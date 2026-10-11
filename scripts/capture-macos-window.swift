import AppKit
import CoreGraphics
import Foundation

func fail(_ message: String) -> Never {
    FileHandle.standardError.write(Data(("capture-macos-window: \(message)\n").utf8))
    exit(1)
}

guard CommandLine.arguments.count == 5,
      let pid = Int32(CommandLine.arguments[1]),
      let expectedWidth = Int(CommandLine.arguments[2]),
      let expectedHeight = Int(CommandLine.arguments[3]) else {
    fail("usage: <pid> <expected-width> <expected-height> <output.png>")
}

let output = CommandLine.arguments[4]
var windowID: CGWindowID?
// First launch on a fresh runner compiles Skia Metal shaders; give the window time to appear.
let deadline = Date().addingTimeInterval(45)
while Date() < deadline && windowID == nil {
    let options: CGWindowListOption = [.optionOnScreenOnly, .excludeDesktopElements]
    let windows = CGWindowListCopyWindowInfo(options, kCGNullWindowID) as? [[String: Any]] ?? []
    for window in windows {
        guard let ownerPID = window[kCGWindowOwnerPID as String] as? NSNumber,
              ownerPID.int32Value == pid,
              let layer = window[kCGWindowLayer as String] as? NSNumber,
              layer.intValue == 0,
              let number = window[kCGWindowNumber as String] as? NSNumber,
              let bounds = window[kCGWindowBounds as String] as? [String: Any],
              let width = bounds["Width"] as? NSNumber,
              let height = bounds["Height"] as? NSNumber,
              width.doubleValue >= Double(expectedWidth - 20),
              height.doubleValue >= Double(expectedHeight - 20) else {
            continue
        }
        windowID = CGWindowID(number.uint32Value)
        break
    }
    if windowID == nil {
        Thread.sleep(forTimeInterval: 0.1)
    }
}

guard let id = windowID else { fail("application window not found") }
let capture = Process()
capture.executableURL = URL(fileURLWithPath: "/usr/sbin/screencapture")
capture.arguments = ["-x", "-l", String(id), output]
try capture.run()
capture.waitUntilExit()
guard capture.terminationStatus == 0,
      let image = NSImage(contentsOfFile: output),
      let data = image.tiffRepresentation,
      let bitmap = NSBitmapImageRep(data: data) else {
    fail("window capture failed")
}

guard bitmap.pixelsWide >= expectedWidth - 20,
      bitmap.pixelsHigh >= expectedHeight - 20 else {
    fail("captured window dimensions are too small")
}

var buckets = Set<Int>()
var opaque = 0
let stepX = max(1, bitmap.pixelsWide / 80)
let stepY = max(1, bitmap.pixelsHigh / 60)
for y in stride(from: 0, to: bitmap.pixelsHigh, by: stepY) {
    for x in stride(from: 0, to: bitmap.pixelsWide, by: stepX) {
        guard let color = bitmap.colorAt(x: x, y: y)?.usingColorSpace(.deviceRGB) else { continue }
        if color.alphaComponent > 0.9 { opaque += 1 }
        let red = Int(color.redComponent * 15)
        let green = Int(color.greenComponent * 15)
        let blue = Int(color.blueComponent * 15)
        buckets.insert((red << 8) | (green << 4) | blue)
    }
}
guard opaque > 1000, buckets.count >= 12 else {
    fail("captured window is blank or visually degenerate")
}

print("ok")
