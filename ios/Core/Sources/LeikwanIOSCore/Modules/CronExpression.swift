import Foundation

/// A cron schedule as Surge modules write it: five fields (minute hour day-of-month month
/// day-of-week), or six with a leading seconds field, which is accepted and ignored (a script
/// runs at most once a minute). Fields take `*`, `*/n`, `a`, `a-b`, `a-b/n`, `a/n` and comma
/// lists; months and weekdays also take three-letter names; weekday 7 is Sunday.
public struct CronExpression: Equatable {
    let minutes: Set<Int>
    let hours: Set<Int>
    let days: Set<Int>
    let months: Set<Int>
    /// 0 = Sunday … 6 = Saturday.
    let weekdays: Set<Int>
    let dayRestricted: Bool
    let weekdayRestricted: Bool

    public init?(_ text: String) {
        var fields = text.split(whereSeparator: { $0 == " " || $0 == "\t" }).map(String.init)
        if fields.count == 6 { fields.removeFirst() }
        guard fields.count == 5,
              let minutes = CronExpression.parseField(fields[0], range: 0...59, names: [:]),
              let hours = CronExpression.parseField(fields[1], range: 0...23, names: [:]),
              let days = CronExpression.parseField(fields[2], range: 1...31, names: [:]),
              let months = CronExpression.parseField(fields[3], range: 1...12, names: CronExpression.monthNames),
              let weekdays = CronExpression.parseField(fields[4], range: 0...7, names: CronExpression.weekdayNames)
        else { return nil }
        self.minutes = minutes
        self.hours = hours
        self.days = days
        self.months = months
        self.weekdays = Set(weekdays.map { $0 == 7 ? 0 : $0 })
        self.dayRestricted = !fields[2].hasPrefix("*")
        self.weekdayRestricted = !fields[4].hasPrefix("*")
    }

    /// The first matching minute strictly after `date`, or nil when none exists within four
    /// years (a date such as February 30).
    public func nextDate(after date: Date, calendar: Calendar = .current) -> Date? {
        let start = (date.timeIntervalSinceReferenceDate / 60).rounded(.down) * 60 + 60
        var candidate = Date(timeIntervalSinceReferenceDate: start)
        let limit = candidate.addingTimeInterval(4 * 366 * 86_400)
        while candidate < limit {
            let parts = calendar.dateComponents([.year, .month, .day, .hour, .minute, .weekday], from: candidate)
            guard let year = parts.year, let month = parts.month, let day = parts.day,
                  let hour = parts.hour, let minute = parts.minute, let weekday = parts.weekday
            else { return nil }
            if !months.contains(month) {
                guard let next = calendar.date(from: DateComponents(year: year, month: month + 1, day: 1)) else { return nil }
                candidate = next
                continue
            }
            if !matchesDay(day: day, weekday: weekday - 1) {
                guard let midnight = calendar.date(from: DateComponents(year: year, month: month, day: day)),
                      let next = calendar.date(byAdding: .day, value: 1, to: midnight)
                else { return nil }
                candidate = next
                continue
            }
            if !hours.contains(hour) {
                candidate = candidate.addingTimeInterval(Double(60 - minute) * 60)
                continue
            }
            if !minutes.contains(minute) {
                candidate = candidate.addingTimeInterval(60)
                continue
            }
            return candidate
        }
        return nil
    }

    /// Standard cron: when both day fields are restricted, either one matching is enough.
    private func matchesDay(day: Int, weekday: Int) -> Bool {
        let dayOK = days.contains(day)
        let weekdayOK = weekdays.contains(weekday)
        if dayRestricted && weekdayRestricted { return dayOK || weekdayOK }
        if dayRestricted { return dayOK }
        if weekdayRestricted { return weekdayOK }
        return true
    }

    private static let monthNames: [String: Int] = [
        "JAN": 1, "FEB": 2, "MAR": 3, "APR": 4, "MAY": 5, "JUN": 6,
        "JUL": 7, "AUG": 8, "SEP": 9, "OCT": 10, "NOV": 11, "DEC": 12,
    ]
    private static let weekdayNames: [String: Int] = [
        "SUN": 0, "MON": 1, "TUE": 2, "WED": 3, "THU": 4, "FRI": 5, "SAT": 6,
    ]

    private static func parseField(_ field: String, range: ClosedRange<Int>, names: [String: Int]) -> Set<Int>? {
        var result = Set<Int>()
        for item in field.split(separator: ",", omittingEmptySubsequences: false) {
            let pieces = item.split(separator: "/", omittingEmptySubsequences: false).map(String.init)
            guard pieces.count == 1 || pieces.count == 2, !pieces[0].isEmpty else { return nil }
            var step = 1
            if pieces.count == 2 {
                guard let parsed = Int(pieces[1]), parsed > 0 else { return nil }
                step = parsed
            }
            func number(_ text: String) -> Int? {
                if let value = Int(text) { return value }
                return names[text.uppercased()]
            }
            let lower: Int
            let upper: Int
            if pieces[0] == "*" {
                lower = range.lowerBound
                upper = range.upperBound
            } else {
                let bounds = pieces[0].split(separator: "-", omittingEmptySubsequences: false).map(String.init)
                if bounds.count == 1, let value = number(bounds[0]) {
                    lower = value
                    // `5/15` means "from 5, every 15".
                    upper = pieces.count == 2 ? range.upperBound : value
                } else if bounds.count == 2, let first = number(bounds[0]), let second = number(bounds[1]), first <= second {
                    lower = first
                    upper = second
                } else {
                    return nil
                }
            }
            guard range.contains(lower), range.contains(upper) else { return nil }
            var value = lower
            while value <= upper {
                result.insert(value)
                value += step
            }
        }
        return result.isEmpty ? nil : result
    }
}
