import Foundation

/// libbox calls the platform interface synchronously from Go threads; these
/// bridge to async Network Extension APIs.
func runBlocking<T>(_ block: @escaping () async -> T) -> T {
    let semaphore = DispatchSemaphore(value: 0)
    let box = ResultBox<T>()
    Task.detached {
        box.value = await block()
        semaphore.signal()
    }
    semaphore.wait()
    return box.value!
}

func runBlocking<T>(_ block: @escaping () async throws -> T) throws -> T {
    let semaphore = DispatchSemaphore(value: 0)
    let box = ResultBox<T>()
    Task.detached {
        do {
            box.result = .success(try await block())
        } catch {
            box.result = .failure(error)
        }
        semaphore.signal()
    }
    semaphore.wait()
    return try box.result!.get()
}

private final class ResultBox<T> {
    var value: T?
    var result: Result<T, Error>?
}
