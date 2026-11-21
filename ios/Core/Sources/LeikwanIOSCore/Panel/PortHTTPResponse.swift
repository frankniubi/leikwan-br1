import Foundation

/// Small HTTP/1.1 response decoder for the interface-scoped NWConnection transport.
/// No redirects, cookies, proxy negotiation or automatic content decoding.
public enum PortHTTPResponse {
    public static let maximumBytes = 4 * 1024 * 1024

    /// Determine message completion across arbitrary TCP fragments. Close-delimited bodies
    /// only finish on EOF; Content-Length/chunked need not wait for the server to close.
    public static func isComplete(_ data: Data) -> Bool {
        guard let boundary = data.range(of: Data("\r\n\r\n".utf8)),
              let head = String(data: data[..<boundary.lowerBound], encoding: .utf8) else { return false }
        let lines = head.lowercased().components(separatedBy: "\r\n")
        let body = Data(data[boundary.upperBound...])
        if lines.first?.contains(" 204 ") == true || lines.first?.contains(" 304 ") == true { return true }
        if lines.contains(where: { $0 == "transfer-encoding: chunked" }) { return (try? decodeChunks(body)) != nil }
        for line in lines where line.hasPrefix("content-length:") {
            if let size = Int(line.dropFirst("content-length:".count).trimmingCharacters(in: .whitespaces)), size >= 0 {
                return body.count >= size
            }
        }
        return lines.first?.contains(" 204 ") == true || lines.first?.contains(" 304 ") == true
    }

    public static func decode(_ data: Data, url: URL) throws -> (Data, HTTPURLResponse) {
        guard data.count <= maximumBytes,
              let boundary = data.range(of: Data("\r\n\r\n".utf8)),
              let head = String(data: data[..<boundary.lowerBound], encoding: .utf8) else {
            throw URLError(.badServerResponse)
        }
        let lines = head.components(separatedBy: "\r\n")
        let statusLine = (lines.first ?? "").split(separator: " ")
        guard statusLine.count >= 2, statusLine[0].hasPrefix("HTTP/1."),
              let status = Int(statusLine[1]), status >= 200 else { throw URLError(.badServerResponse) }
        var headers: [String: String] = [:]
        for line in lines.dropFirst() {
            guard let colon = line.firstIndex(of: ":") else { throw URLError(.badServerResponse) }
            let name = String(line[..<colon]).lowercased()
            let value = line[line.index(after: colon)...].trimmingCharacters(in: .whitespaces)
            if name == "set-cookie" { continue } // This transport has no cookie store.
            if let previous = headers[name] {
                guard name != "content-length", name != "transfer-encoding" else { throw URLError(.badServerResponse) }
                headers[name] = previous + ", " + value
            } else { headers[name] = value }
        }
        var body = Data(data[boundary.upperBound...])
        if status == 204 || status == 304 {
            guard body.isEmpty else { throw URLError(.badServerResponse) }
        } else if let transfer = headers["transfer-encoding"] {
            guard transfer.lowercased() == "chunked", headers["content-length"] == nil else {
                throw URLError(.badServerResponse)
            }
            body = try decodeChunks(body)
        } else if let lengthText = headers["content-length"] {
            guard let length = Int(lengthText), length >= 0, length == body.count else {
                throw URLError(.badServerResponse)
            }
        }
        guard let response = HTTPURLResponse(url: url, statusCode: status, httpVersion: "HTTP/1.1", headerFields: headers) else {
            throw URLError(.badServerResponse)
        }
        return (body, response)
    }

    private static func decodeChunks(_ source: Data) throws -> Data {
        var remaining = source
        var result = Data()
        let newline = Data("\r\n".utf8)
        while let range = remaining.range(of: newline),
              let line = String(data: remaining[..<range.lowerBound], encoding: .utf8),
              let token = line.split(separator: ";", maxSplits: 1).first,
              let size = Int(token, radix: 16), size >= 0 {
            remaining = Data(remaining[range.upperBound...])
            if size == 0 {
                // The zero chunk is followed by trailers and a final empty line.
                guard remaining == newline || remaining.range(of: Data("\r\n\r\n".utf8)) != nil else { throw URLError(.badServerResponse) }
                return result
            }
            guard size <= maximumBytes - result.count, remaining.count >= size + 2,
                  remaining.subdata(in: size..<(size + 2)) == newline else { throw URLError(.badServerResponse) }
            result.append(remaining.prefix(size))
            remaining = Data(remaining.dropFirst(size + 2))
        }
        throw URLError(.badServerResponse)
    }
}
