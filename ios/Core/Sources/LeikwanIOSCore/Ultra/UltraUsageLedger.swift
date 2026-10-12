import Foundation

/// Turns the Ultra runtime's cumulative byte counters (`bytes_in` / `bytes_out` in each
/// `*.status.json`) into acknowledged per-forward usage deltas for the heartbeat
/// (port of Android `runtime/UltraUsageLedger.kt`):
///
/// * a row is a delta since the last reported counter of that runtime instance (status file);
///   a counter that went backwards means the runtime restarted, so it counts from zero;
/// * every row gets the next `sequence` of its forward; sequences are persisted, so a restart
///   never reuses one (the panel deduplicates on client, device, forward and sequence);
/// * at most `maxRows` rows per heartbeat; observations left out keep their cursor;
/// * a batch stays pending, and is resent unchanged, until the panel acknowledges it;
/// * the final counters of a runtime are captured (`captureFinal`) before its status file goes.
///
/// State is one JSON file at a caller-supplied path. It holds counters only, never lease material.
public final class UltraUsageLedger {
    /// The panel reads at most this many rows per heartbeat.
    public static let maxRows = 32
    /// UltraMode::MAX_QUOTA_LEASE_BYTES: a larger row would be silently dropped.
    public static let maxRowBytes: Int64 = 107_374_182_400
    public static let statusSuffix = ".status.json"
    static let maxStatusBytes = 64 * 1024

    /// One lock for every instance, like Android's companion lock (several owners may share a file).
    private static let lock = NSLock()

    public let statusDirectory: URL
    public let stateFile: URL

    public init(statusDirectory: URL, stateFile: URL) {
        self.statusDirectory = statusDirectory
        self.stateFile = stateFile
    }

    // MARK: Public API

    /// Rows to send with the next heartbeat; the pending batch when one is unacknowledged.
    /// Returns an empty list when the state cannot be persisted (nothing is sent unrecorded).
    public func collect() -> [UltraUsageRow] {
        UltraUsageLedger.lock.lock()
        defer { UltraUsageLedger.lock.unlock() }
        var state = load()
        if !state.pending.isEmpty { return state.pending.map { $0.row } }

        var observations: [Observation] = []
        let files = statusFiles()
        for file in files {
            // A file caught mid-write is skipped this round; its cursor is kept below.
            guard let snapshot = readSnapshot(file) else { continue }
            let name = file.lastPathComponent
            let cursor = state.cursors[name]
            var previousIn = cursor?.bytesIn ?? 0
            var previousOut = cursor?.bytesOut ?? 0
            if snapshot.bytesIn < previousIn { previousIn = 0 }
            if snapshot.bytesOut < previousOut { previousOut = 0 }
            observations.append(Observation(
                key: name,
                forwardId: snapshot.forwardId,
                generation: snapshot.generation,
                currentIn: snapshot.bytesIn,
                currentOut: snapshot.bytesOut,
                previousIn: previousIn,
                previousOut: previousOut
            ))
        }
        // Cursors of runtimes that vanished without a final capture can never be used again.
        let liveNames = Set(files.map { $0.lastPathComponent })
        for name in Array(state.cursors.keys) where !liveNames.contains(name) {
            state.cursors.removeValue(forKey: name)
        }
        for carry in state.carry {
            observations.append(Observation(
                key: nil,
                forwardId: carry.forwardId,
                generation: carry.policyGeneration,
                currentIn: carry.bytesIn,
                currentOut: carry.bytesOut,
                previousIn: 0,
                previousOut: 0
            ))
        }

        for observation in observations where observation.deltaIn == 0 && observation.deltaOut == 0 {
            if let key = observation.key {
                state.cursors[key] = Cursor(bytesIn: observation.currentIn, bytesOut: observation.currentOut)
            }
        }

        // Group by (forward, generation), keeping first-seen member order, sorted by key.
        var groupKeys: [GroupKey] = []
        var groupMembers: [GroupKey: [Observation]] = [:]
        for observation in observations where observation.deltaIn > 0 || observation.deltaOut > 0 {
            let key = GroupKey(forwardId: observation.forwardId, generation: observation.generation)
            if groupMembers[key] == nil {
                groupKeys.append(key)
                groupMembers[key] = []
            }
            groupMembers[key]?.append(observation)
        }
        groupKeys.sort { left, right in
            if left.forwardId != right.forwardId { return left.forwardId < right.forwardId }
            return left.generation < right.generation
        }
        if groupKeys.count > UltraUsageLedger.maxRows {
            groupKeys = Array(groupKeys.prefix(UltraUsageLedger.maxRows))
        }

        var rows: [UltraUsageRow] = []
        var remainingCarry = state.carry
        for key in groupKeys {
            var bytesIn: Int64 = 0
            var bytesOut: Int64 = 0
            for observation in groupMembers[key] ?? [] {
                let consumedIn = min(observation.deltaIn, UltraUsageLedger.maxRowBytes - bytesIn)
                let consumedOut = min(observation.deltaOut, UltraUsageLedger.maxRowBytes - bytesOut)
                bytesIn += consumedIn
                bytesOut += consumedOut
                if let name = observation.key {
                    state.cursors[name] = Cursor(
                        bytesIn: observation.previousIn + consumedIn,
                        bytesOut: observation.previousOut + consumedOut
                    )
                } else {
                    let left = CarryRow(
                        forwardId: key.forwardId,
                        policyGeneration: key.generation,
                        bytesIn: observation.currentIn - consumedIn,
                        bytesOut: observation.currentOut - consumedOut
                    )
                    let index = remainingCarry.firstIndex {
                        $0.forwardId == key.forwardId && $0.policyGeneration == key.generation
                    }
                    if left.bytesIn > 0 || left.bytesOut > 0 {
                        if let index = index { remainingCarry[index] = left } else { remainingCarry.append(left) }
                    } else if let index = index {
                        remainingCarry.remove(at: index)
                    }
                }
            }
            let sequenceKey = String(key.forwardId)
            let sequence = (state.sequences[sequenceKey] ?? 0) + 1
            state.sequences[sequenceKey] = sequence
            rows.append(UltraUsageRow(
                forwardId: key.forwardId,
                policyGeneration: key.generation,
                sequence: sequence,
                bytesIn: bytesIn,
                bytesOut: bytesOut
            ))
        }
        state.carry = remainingCarry
        state.pending = rows.map { PendingRow($0) }
        // Persist before sending: a crash after the send resends the same sequences.
        do {
            try save(state)
        } catch {
            return []
        }
        return rows
    }

    /// Settles `rows` after a heartbeat answered; an unacknowledged batch stays pending.
    public func acknowledge(_ rows: [UltraUsageRow], acknowledged: Bool) {
        if !acknowledged || rows.isEmpty { return }
        UltraUsageLedger.lock.lock()
        defer { UltraUsageLedger.lock.unlock() }
        var state = load()
        if state.pending.map({ $0.row }) == rows {
            state.pending = []
            try? save(state)
        }
    }

    /// Records the last counters of a runtime instance whose status file is about to be
    /// deleted, so the bytes it moved since the last heartbeat are still reported.
    public func captureFinal(statusFile: URL) {
        UltraUsageLedger.lock.lock()
        defer { UltraUsageLedger.lock.unlock() }
        var state = load()
        let name = statusFile.lastPathComponent
        let snapshot = readSnapshot(statusFile)
        let cursor = state.cursors.removeValue(forKey: name)
        if let snapshot = snapshot {
            var previousIn = cursor?.bytesIn ?? 0
            var previousOut = cursor?.bytesOut ?? 0
            if snapshot.bytesIn < previousIn { previousIn = 0 }
            if snapshot.bytesOut < previousOut { previousOut = 0 }
            let deltaIn = snapshot.bytesIn - previousIn
            let deltaOut = snapshot.bytesOut - previousOut
            if deltaIn > 0 || deltaOut > 0 {
                if let index = state.carry.firstIndex(where: {
                    $0.forwardId == snapshot.forwardId && $0.policyGeneration == snapshot.generation
                }) {
                    state.carry[index].bytesIn += deltaIn
                    state.carry[index].bytesOut += deltaOut
                } else {
                    state.carry.append(CarryRow(
                        forwardId: snapshot.forwardId,
                        policyGeneration: snapshot.generation,
                        bytesIn: deltaIn,
                        bytesOut: deltaOut
                    ))
                }
            }
        }
        if snapshot != nil || cursor != nil {
            try? save(state)
        }
    }

    // MARK: Status files

    private func statusFiles() -> [URL] {
        let manager = FileManager.default
        guard let names = try? manager.contentsOfDirectory(atPath: statusDirectory.path) else { return [] }
        var files: [URL] = []
        for name in names.sorted() where name.hasSuffix(UltraUsageLedger.statusSuffix) {
            let url = statusDirectory.appendingPathComponent(name)
            var isDirectory: ObjCBool = false
            if manager.fileExists(atPath: url.path, isDirectory: &isDirectory), !isDirectory.boolValue {
                files.append(url)
            }
        }
        return files
    }

    private func readSnapshot(_ file: URL) -> Snapshot? {
        guard let data = FileManager.default.contents(atPath: file.path) else { return nil }
        if data.isEmpty || data.count > UltraUsageLedger.maxStatusBytes { return nil }
        guard let root = JSONLenient.parseObject(data),
              let forwardId = JSONLenient.strictInt64(root["forward_id"]),
              let rawGeneration = root["policy_generation"] as? String
        else { return nil }
        let generation = rawGeneration.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        if forwardId <= 0 || !Hashing.isLowerHex64(generation) { return nil }
        guard let bytesIn = counter(root["bytes_in"]), let bytesOut = counter(root["bytes_out"]) else { return nil }
        return Snapshot(forwardId: forwardId, generation: generation, bytesIn: max(bytesIn, 0), bytesOut: max(bytesOut, 0))
    }

    /// Absent / null / non-primitive -> 0; a primitive that is not a number -> nil (unreadable).
    private func counter(_ value: Any?) -> Int64? {
        guard let value = value, !(value is NSNull) else { return 0 }
        if value is [Any] || value is [String: Any] { return 0 }
        return JSONLenient.strictInt64(value)
    }

    // MARK: State persistence

    private func load() -> LedgerState {
        guard let data = try? Data(contentsOf: stateFile) else { return LedgerState() }
        return (try? JSONDecoder().decode(LedgerState.self, from: data)) ?? LedgerState()
    }

    private func save(_ state: LedgerState) throws {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]
        let data = try encoder.encode(state)
        try FileManager.default.createDirectory(
            at: stateFile.deletingLastPathComponent(),
            withIntermediateDirectories: true
        )
        try data.write(to: stateFile, options: [.atomic])
    }

    // MARK: Types

    struct Cursor: Codable, Equatable {
        var bytesIn: Int64
        var bytesOut: Int64

        enum CodingKeys: String, CodingKey {
            case bytesIn = "in"
            case bytesOut = "out"
        }
    }

    struct CarryRow: Codable, Equatable {
        var forwardId: Int64
        var policyGeneration: String
        var bytesIn: Int64
        var bytesOut: Int64

        enum CodingKeys: String, CodingKey {
            case forwardId = "forward_id"
            case policyGeneration = "policy_generation"
            case bytesIn = "bytes_in"
            case bytesOut = "bytes_out"
        }
    }

    struct PendingRow: Codable, Equatable {
        var forwardId: Int64
        var policyGeneration: String
        var sequence: Int64
        var bytesIn: Int64
        var bytesOut: Int64

        init(_ row: UltraUsageRow) {
            forwardId = row.forwardId
            policyGeneration = row.policyGeneration
            sequence = row.sequence
            bytesIn = row.bytesIn
            bytesOut = row.bytesOut
        }

        var row: UltraUsageRow {
            return UltraUsageRow(
                forwardId: forwardId,
                policyGeneration: policyGeneration,
                sequence: sequence,
                bytesIn: bytesIn,
                bytesOut: bytesOut
            )
        }

        enum CodingKeys: String, CodingKey {
            case forwardId = "forward_id"
            case policyGeneration = "policy_generation"
            case sequence
            case bytesIn = "bytes_in"
            case bytesOut = "bytes_out"
        }
    }

    /// Every section decodes independently, so one damaged section never resets the sequences.
    struct LedgerState: Codable {
        var v: Int = 1
        var cursors: [String: Cursor] = [:]
        var sequences: [String: Int64] = [:]
        var carry: [CarryRow] = []
        var pending: [PendingRow] = []

        init() {}

        init(from decoder: Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            v = (try? container.decodeIfPresent(Int.self, forKey: .v)) ?? 1
            cursors = (try? container.decodeIfPresent([String: Cursor].self, forKey: .cursors)) ?? [:]
            sequences = (try? container.decodeIfPresent([String: Int64].self, forKey: .sequences)) ?? [:]
            carry = (try? container.decodeIfPresent([CarryRow].self, forKey: .carry)) ?? []
            pending = (try? container.decodeIfPresent([PendingRow].self, forKey: .pending)) ?? []
        }

        enum CodingKeys: String, CodingKey {
            case v
            case cursors
            case sequences
            case carry
            case pending
        }
    }

    private struct GroupKey: Hashable {
        let forwardId: Int64
        let generation: String
    }

    private struct Snapshot {
        let forwardId: Int64
        let generation: String
        let bytesIn: Int64
        let bytesOut: Int64
    }

    private struct Observation {
        let key: String?
        let forwardId: Int64
        let generation: String
        let currentIn: Int64
        let currentOut: Int64
        let previousIn: Int64
        let previousOut: Int64

        var deltaIn: Int64 { return currentIn - previousIn }
        var deltaOut: Int64 { return currentOut - previousOut }
    }
}
