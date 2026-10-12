import Foundation

public struct Country: Equatable {
    public let code: String
    public let nameEn: String
    public let nameZh: String

    public init(code: String, nameEn: String, nameZh: String) {
        self.code = code
        self.nameEn = nameEn
        self.nameZh = nameZh
    }
}

/// Port of `routing/CountryCatalog.kt` (ISO codes with English / Simplified Chinese names).
public enum CountryCatalog {
    public static let all: [Country] = [
        Country(code: "AD", nameEn: "Andorra", nameZh: "安道尔"),
        Country(code: "AE", nameEn: "United Arab Emirates", nameZh: "阿拉伯联合酋长国"),
        Country(code: "AF", nameEn: "Afghanistan", nameZh: "阿富汗"),
        Country(code: "AG", nameEn: "Antigua & Barbuda", nameZh: "安提瓜和巴布达"),
        Country(code: "AI", nameEn: "Anguilla", nameZh: "安圭拉"),
        Country(code: "AL", nameEn: "Albania", nameZh: "阿尔巴尼亚"),
        Country(code: "AM", nameEn: "Armenia", nameZh: "亚美尼亚"),
        Country(code: "AO", nameEn: "Angola", nameZh: "安哥拉"),
        Country(code: "AQ", nameEn: "Antarctica", nameZh: "南极洲"),
        Country(code: "AR", nameEn: "Argentina", nameZh: "阿根廷"),
        Country(code: "AS", nameEn: "American Samoa", nameZh: "美属萨摩亚"),
        Country(code: "AT", nameEn: "Austria", nameZh: "奥地利"),
        Country(code: "AU", nameEn: "Australia", nameZh: "澳大利亚"),
        Country(code: "AW", nameEn: "Aruba", nameZh: "阿鲁巴"),
        Country(code: "AX", nameEn: "Åland Islands", nameZh: "奥兰群岛"),
        Country(code: "AZ", nameEn: "Azerbaijan", nameZh: "阿塞拜疆"),
        Country(code: "BA", nameEn: "Bosnia & Herzegovina", nameZh: "波斯尼亚和黑塞哥维那"),
        Country(code: "BB", nameEn: "Barbados", nameZh: "巴巴多斯"),
        Country(code: "BD", nameEn: "Bangladesh", nameZh: "孟加拉国"),
        Country(code: "BE", nameEn: "Belgium", nameZh: "比利时"),
        Country(code: "BF", nameEn: "Burkina Faso", nameZh: "布基纳法索"),
        Country(code: "BG", nameEn: "Bulgaria", nameZh: "保加利亚"),
        Country(code: "BH", nameEn: "Bahrain", nameZh: "巴林"),
        Country(code: "BI", nameEn: "Burundi", nameZh: "布隆迪"),
        Country(code: "BJ", nameEn: "Benin", nameZh: "贝宁"),
        Country(code: "BL", nameEn: "St. Barthélemy", nameZh: "圣巴泰勒米"),
        Country(code: "BM", nameEn: "Bermuda", nameZh: "百慕大"),
        Country(code: "BN", nameEn: "Brunei", nameZh: "文莱"),
        Country(code: "BO", nameEn: "Bolivia", nameZh: "玻利维亚"),
        Country(code: "BQ", nameEn: "Caribbean Netherlands", nameZh: "荷属加勒比区"),
        Country(code: "BR", nameEn: "Brazil", nameZh: "巴西"),
        Country(code: "BS", nameEn: "Bahamas", nameZh: "巴哈马"),
        Country(code: "BT", nameEn: "Bhutan", nameZh: "不丹"),
        Country(code: "BV", nameEn: "Bouvet Island", nameZh: "布韦岛"),
        Country(code: "BW", nameEn: "Botswana", nameZh: "博茨瓦纳"),
        Country(code: "BY", nameEn: "Belarus", nameZh: "白俄罗斯"),
        Country(code: "BZ", nameEn: "Belize", nameZh: "伯利兹"),
        Country(code: "CA", nameEn: "Canada", nameZh: "加拿大"),
        Country(code: "CC", nameEn: "Cocos (Keeling) Islands", nameZh: "科科斯（基林）群岛"),
        Country(code: "CD", nameEn: "Congo - Kinshasa", nameZh: "刚果（金）"),
        Country(code: "CF", nameEn: "Central African Republic", nameZh: "中非共和国"),
        Country(code: "CG", nameEn: "Congo - Brazzaville", nameZh: "刚果（布）"),
        Country(code: "CH", nameEn: "Switzerland", nameZh: "瑞士"),
        Country(code: "CI", nameEn: "Côte d’Ivoire", nameZh: "科特迪瓦"),
        Country(code: "CK", nameEn: "Cook Islands", nameZh: "库克群岛"),
        Country(code: "CL", nameEn: "Chile", nameZh: "智利"),
        Country(code: "CM", nameEn: "Cameroon", nameZh: "喀麦隆"),
        Country(code: "CN", nameEn: "China", nameZh: "中国"),
        Country(code: "CO", nameEn: "Colombia", nameZh: "哥伦比亚"),
        Country(code: "CR", nameEn: "Costa Rica", nameZh: "哥斯达黎加"),
        Country(code: "CU", nameEn: "Cuba", nameZh: "古巴"),
        Country(code: "CV", nameEn: "Cape Verde", nameZh: "佛得角"),
        Country(code: "CW", nameEn: "Curaçao", nameZh: "库拉索"),
        Country(code: "CX", nameEn: "Christmas Island", nameZh: "圣诞岛"),
        Country(code: "CY", nameEn: "Cyprus", nameZh: "塞浦路斯"),
        Country(code: "CZ", nameEn: "Czechia", nameZh: "捷克"),
        Country(code: "DE", nameEn: "Germany", nameZh: "德国"),
        Country(code: "DJ", nameEn: "Djibouti", nameZh: "吉布提"),
        Country(code: "DK", nameEn: "Denmark", nameZh: "丹麦"),
        Country(code: "DM", nameEn: "Dominica", nameZh: "多米尼克"),
        Country(code: "DO", nameEn: "Dominican Republic", nameZh: "多米尼加共和国"),
        Country(code: "DZ", nameEn: "Algeria", nameZh: "阿尔及利亚"),
        Country(code: "EC", nameEn: "Ecuador", nameZh: "厄瓜多尔"),
        Country(code: "EE", nameEn: "Estonia", nameZh: "爱沙尼亚"),
        Country(code: "EG", nameEn: "Egypt", nameZh: "埃及"),
        Country(code: "EH", nameEn: "Western Sahara", nameZh: "西撒哈拉"),
        Country(code: "ER", nameEn: "Eritrea", nameZh: "厄立特里亚"),
        Country(code: "ES", nameEn: "Spain", nameZh: "西班牙"),
        Country(code: "ET", nameEn: "Ethiopia", nameZh: "埃塞俄比亚"),
        Country(code: "FI", nameEn: "Finland", nameZh: "芬兰"),
        Country(code: "FJ", nameEn: "Fiji", nameZh: "斐济"),
        Country(code: "FK", nameEn: "Falkland Islands", nameZh: "福克兰群岛"),
        Country(code: "FM", nameEn: "Micronesia", nameZh: "密克罗尼西亚"),
        Country(code: "FO", nameEn: "Faroe Islands", nameZh: "法罗群岛"),
        Country(code: "FR", nameEn: "France", nameZh: "法国"),
        Country(code: "GA", nameEn: "Gabon", nameZh: "加蓬"),
        Country(code: "GB", nameEn: "United Kingdom", nameZh: "英国"),
        Country(code: "GD", nameEn: "Grenada", nameZh: "格林纳达"),
        Country(code: "GE", nameEn: "Georgia", nameZh: "格鲁吉亚"),
        Country(code: "GF", nameEn: "French Guiana", nameZh: "法属圭亚那"),
        Country(code: "GG", nameEn: "Guernsey", nameZh: "根西岛"),
        Country(code: "GH", nameEn: "Ghana", nameZh: "加纳"),
        Country(code: "GI", nameEn: "Gibraltar", nameZh: "直布罗陀"),
        Country(code: "GL", nameEn: "Greenland", nameZh: "格陵兰"),
        Country(code: "GM", nameEn: "Gambia", nameZh: "冈比亚"),
        Country(code: "GN", nameEn: "Guinea", nameZh: "几内亚"),
        Country(code: "GP", nameEn: "Guadeloupe", nameZh: "瓜德罗普"),
        Country(code: "GQ", nameEn: "Equatorial Guinea", nameZh: "赤道几内亚"),
        Country(code: "GR", nameEn: "Greece", nameZh: "希腊"),
        Country(code: "GS", nameEn: "South Georgia & South Sandwich Islands", nameZh: "南乔治亚和南桑威奇群岛"),
        Country(code: "GT", nameEn: "Guatemala", nameZh: "危地马拉"),
        Country(code: "GU", nameEn: "Guam", nameZh: "关岛"),
        Country(code: "GW", nameEn: "Guinea-Bissau", nameZh: "几内亚比绍"),
        Country(code: "GY", nameEn: "Guyana", nameZh: "圭亚那"),
        Country(code: "HK", nameEn: "Hong Kong SAR China", nameZh: "中国香港特别行政区"),
        Country(code: "HM", nameEn: "Heard & McDonald Islands", nameZh: "赫德岛和麦克唐纳群岛"),
        Country(code: "HN", nameEn: "Honduras", nameZh: "洪都拉斯"),
        Country(code: "HR", nameEn: "Croatia", nameZh: "克罗地亚"),
        Country(code: "HT", nameEn: "Haiti", nameZh: "海地"),
        Country(code: "HU", nameEn: "Hungary", nameZh: "匈牙利"),
        Country(code: "ID", nameEn: "Indonesia", nameZh: "印度尼西亚"),
        Country(code: "IE", nameEn: "Ireland", nameZh: "爱尔兰"),
        Country(code: "IL", nameEn: "Israel", nameZh: "以色列"),
        Country(code: "IM", nameEn: "Isle of Man", nameZh: "马恩岛"),
        Country(code: "IN", nameEn: "India", nameZh: "印度"),
        Country(code: "IO", nameEn: "British Indian Ocean Territory", nameZh: "英属印度洋领地"),
        Country(code: "IQ", nameEn: "Iraq", nameZh: "伊拉克"),
        Country(code: "IR", nameEn: "Iran", nameZh: "伊朗"),
        Country(code: "IS", nameEn: "Iceland", nameZh: "冰岛"),
        Country(code: "IT", nameEn: "Italy", nameZh: "意大利"),
        Country(code: "JE", nameEn: "Jersey", nameZh: "泽西岛"),
        Country(code: "JM", nameEn: "Jamaica", nameZh: "牙买加"),
        Country(code: "JO", nameEn: "Jordan", nameZh: "约旦"),
        Country(code: "JP", nameEn: "Japan", nameZh: "日本"),
        Country(code: "KE", nameEn: "Kenya", nameZh: "肯尼亚"),
        Country(code: "KG", nameEn: "Kyrgyzstan", nameZh: "吉尔吉斯斯坦"),
        Country(code: "KH", nameEn: "Cambodia", nameZh: "柬埔寨"),
        Country(code: "KI", nameEn: "Kiribati", nameZh: "基里巴斯"),
        Country(code: "KM", nameEn: "Comoros", nameZh: "科摩罗"),
        Country(code: "KN", nameEn: "St. Kitts & Nevis", nameZh: "圣基茨和尼维斯"),
        Country(code: "KP", nameEn: "North Korea", nameZh: "朝鲜"),
        Country(code: "KR", nameEn: "South Korea", nameZh: "韩国"),
        Country(code: "KW", nameEn: "Kuwait", nameZh: "科威特"),
        Country(code: "KY", nameEn: "Cayman Islands", nameZh: "开曼群岛"),
        Country(code: "KZ", nameEn: "Kazakhstan", nameZh: "哈萨克斯坦"),
        Country(code: "LA", nameEn: "Laos", nameZh: "老挝"),
        Country(code: "LB", nameEn: "Lebanon", nameZh: "黎巴嫩"),
        Country(code: "LC", nameEn: "St. Lucia", nameZh: "圣卢西亚"),
        Country(code: "LI", nameEn: "Liechtenstein", nameZh: "列支敦士登"),
        Country(code: "LK", nameEn: "Sri Lanka", nameZh: "斯里兰卡"),
        Country(code: "LR", nameEn: "Liberia", nameZh: "利比里亚"),
        Country(code: "LS", nameEn: "Lesotho", nameZh: "莱索托"),
        Country(code: "LT", nameEn: "Lithuania", nameZh: "立陶宛"),
        Country(code: "LU", nameEn: "Luxembourg", nameZh: "卢森堡"),
        Country(code: "LV", nameEn: "Latvia", nameZh: "拉脱维亚"),
        Country(code: "LY", nameEn: "Libya", nameZh: "利比亚"),
        Country(code: "MA", nameEn: "Morocco", nameZh: "摩洛哥"),
        Country(code: "MC", nameEn: "Monaco", nameZh: "摩纳哥"),
        Country(code: "MD", nameEn: "Moldova", nameZh: "摩尔多瓦"),
        Country(code: "ME", nameEn: "Montenegro", nameZh: "黑山"),
        Country(code: "MF", nameEn: "St. Martin", nameZh: "法属圣马丁"),
        Country(code: "MG", nameEn: "Madagascar", nameZh: "马达加斯加"),
        Country(code: "MH", nameEn: "Marshall Islands", nameZh: "马绍尔群岛"),
        Country(code: "MK", nameEn: "North Macedonia", nameZh: "北马其顿"),
        Country(code: "ML", nameEn: "Mali", nameZh: "马里"),
        Country(code: "MM", nameEn: "Myanmar (Burma)", nameZh: "缅甸"),
        Country(code: "MN", nameEn: "Mongolia", nameZh: "蒙古"),
        Country(code: "MO", nameEn: "Macao SAR China", nameZh: "中国澳门特别行政区"),
        Country(code: "MP", nameEn: "Northern Mariana Islands", nameZh: "北马里亚纳群岛"),
        Country(code: "MQ", nameEn: "Martinique", nameZh: "马提尼克"),
        Country(code: "MR", nameEn: "Mauritania", nameZh: "毛里塔尼亚"),
        Country(code: "MS", nameEn: "Montserrat", nameZh: "蒙特塞拉特"),
        Country(code: "MT", nameEn: "Malta", nameZh: "马耳他"),
        Country(code: "MU", nameEn: "Mauritius", nameZh: "毛里求斯"),
        Country(code: "MV", nameEn: "Maldives", nameZh: "马尔代夫"),
        Country(code: "MW", nameEn: "Malawi", nameZh: "马拉维"),
        Country(code: "MX", nameEn: "Mexico", nameZh: "墨西哥"),
        Country(code: "MY", nameEn: "Malaysia", nameZh: "马来西亚"),
        Country(code: "MZ", nameEn: "Mozambique", nameZh: "莫桑比克"),
        Country(code: "NA", nameEn: "Namibia", nameZh: "纳米比亚"),
        Country(code: "NC", nameEn: "New Caledonia", nameZh: "新喀里多尼亚"),
        Country(code: "NE", nameEn: "Niger", nameZh: "尼日尔"),
        Country(code: "NF", nameEn: "Norfolk Island", nameZh: "诺福克岛"),
        Country(code: "NG", nameEn: "Nigeria", nameZh: "尼日利亚"),
        Country(code: "NI", nameEn: "Nicaragua", nameZh: "尼加拉瓜"),
        Country(code: "NL", nameEn: "Netherlands", nameZh: "荷兰"),
        Country(code: "NO", nameEn: "Norway", nameZh: "挪威"),
        Country(code: "NP", nameEn: "Nepal", nameZh: "尼泊尔"),
        Country(code: "NR", nameEn: "Nauru", nameZh: "瑙鲁"),
        Country(code: "NU", nameEn: "Niue", nameZh: "纽埃"),
        Country(code: "NZ", nameEn: "New Zealand", nameZh: "新西兰"),
        Country(code: "OM", nameEn: "Oman", nameZh: "阿曼"),
        Country(code: "PA", nameEn: "Panama", nameZh: "巴拿马"),
        Country(code: "PE", nameEn: "Peru", nameZh: "秘鲁"),
        Country(code: "PF", nameEn: "French Polynesia", nameZh: "法属波利尼西亚"),
        Country(code: "PG", nameEn: "Papua New Guinea", nameZh: "巴布亚新几内亚"),
        Country(code: "PH", nameEn: "Philippines", nameZh: "菲律宾"),
        Country(code: "PK", nameEn: "Pakistan", nameZh: "巴基斯坦"),
        Country(code: "PL", nameEn: "Poland", nameZh: "波兰"),
        Country(code: "PM", nameEn: "St. Pierre & Miquelon", nameZh: "圣皮埃尔和密克隆群岛"),
        Country(code: "PN", nameEn: "Pitcairn Islands", nameZh: "皮特凯恩群岛"),
        Country(code: "PR", nameEn: "Puerto Rico", nameZh: "波多黎各"),
        Country(code: "PS", nameEn: "Palestinian Territories", nameZh: "巴勒斯坦领土"),
        Country(code: "PT", nameEn: "Portugal", nameZh: "葡萄牙"),
        Country(code: "PW", nameEn: "Palau", nameZh: "帕劳"),
        Country(code: "PY", nameEn: "Paraguay", nameZh: "巴拉圭"),
        Country(code: "QA", nameEn: "Qatar", nameZh: "卡塔尔"),
        Country(code: "RE", nameEn: "Réunion", nameZh: "留尼汪"),
        Country(code: "RO", nameEn: "Romania", nameZh: "罗马尼亚"),
        Country(code: "RS", nameEn: "Serbia", nameZh: "塞尔维亚"),
        Country(code: "RU", nameEn: "Russia", nameZh: "俄罗斯"),
        Country(code: "RW", nameEn: "Rwanda", nameZh: "卢旺达"),
        Country(code: "SA", nameEn: "Saudi Arabia", nameZh: "沙特阿拉伯"),
        Country(code: "SB", nameEn: "Solomon Islands", nameZh: "所罗门群岛"),
        Country(code: "SC", nameEn: "Seychelles", nameZh: "塞舌尔"),
        Country(code: "SD", nameEn: "Sudan", nameZh: "苏丹"),
        Country(code: "SE", nameEn: "Sweden", nameZh: "瑞典"),
        Country(code: "SG", nameEn: "Singapore", nameZh: "新加坡"),
        Country(code: "SH", nameEn: "St. Helena", nameZh: "圣赫勒拿"),
        Country(code: "SI", nameEn: "Slovenia", nameZh: "斯洛文尼亚"),
        Country(code: "SJ", nameEn: "Svalbard & Jan Mayen", nameZh: "斯瓦尔巴和扬马延"),
        Country(code: "SK", nameEn: "Slovakia", nameZh: "斯洛伐克"),
        Country(code: "SL", nameEn: "Sierra Leone", nameZh: "塞拉利昂"),
        Country(code: "SM", nameEn: "San Marino", nameZh: "圣马力诺"),
        Country(code: "SN", nameEn: "Senegal", nameZh: "塞内加尔"),
        Country(code: "SO", nameEn: "Somalia", nameZh: "索马里"),
        Country(code: "SR", nameEn: "Suriname", nameZh: "苏里南"),
        Country(code: "SS", nameEn: "South Sudan", nameZh: "南苏丹"),
        Country(code: "ST", nameEn: "São Tomé & Príncipe", nameZh: "圣多美和普林西比"),
        Country(code: "SV", nameEn: "El Salvador", nameZh: "萨尔瓦多"),
        Country(code: "SX", nameEn: "Sint Maarten", nameZh: "荷属圣马丁"),
        Country(code: "SY", nameEn: "Syria", nameZh: "叙利亚"),
        Country(code: "SZ", nameEn: "Eswatini", nameZh: "斯威士兰"),
        Country(code: "TC", nameEn: "Turks & Caicos Islands", nameZh: "特克斯和凯科斯群岛"),
        Country(code: "TD", nameEn: "Chad", nameZh: "乍得"),
        Country(code: "TF", nameEn: "French Southern Territories", nameZh: "法属南部领地"),
        Country(code: "TG", nameEn: "Togo", nameZh: "多哥"),
        Country(code: "TH", nameEn: "Thailand", nameZh: "泰国"),
        Country(code: "TJ", nameEn: "Tajikistan", nameZh: "塔吉克斯坦"),
        Country(code: "TK", nameEn: "Tokelau", nameZh: "托克劳"),
        Country(code: "TL", nameEn: "Timor-Leste", nameZh: "东帝汶"),
        Country(code: "TM", nameEn: "Turkmenistan", nameZh: "土库曼斯坦"),
        Country(code: "TN", nameEn: "Tunisia", nameZh: "突尼斯"),
        Country(code: "TO", nameEn: "Tonga", nameZh: "汤加"),
        Country(code: "TR", nameEn: "Turkey", nameZh: "土耳其"),
        Country(code: "TT", nameEn: "Trinidad & Tobago", nameZh: "特立尼达和多巴哥"),
        Country(code: "TV", nameEn: "Tuvalu", nameZh: "图瓦卢"),
        Country(code: "TW", nameEn: "Taiwan", nameZh: "台湾"),
        Country(code: "TZ", nameEn: "Tanzania", nameZh: "坦桑尼亚"),
        Country(code: "UA", nameEn: "Ukraine", nameZh: "乌克兰"),
        Country(code: "UG", nameEn: "Uganda", nameZh: "乌干达"),
        Country(code: "UM", nameEn: "U.S. Outlying Islands", nameZh: "美国本土外小岛屿"),
        Country(code: "US", nameEn: "United States", nameZh: "美国"),
        Country(code: "UY", nameEn: "Uruguay", nameZh: "乌拉圭"),
        Country(code: "UZ", nameEn: "Uzbekistan", nameZh: "乌兹别克斯坦"),
        Country(code: "VA", nameEn: "Vatican City", nameZh: "梵蒂冈"),
        Country(code: "VC", nameEn: "St. Vincent & Grenadines", nameZh: "圣文森特和格林纳丁斯"),
        Country(code: "VE", nameEn: "Venezuela", nameZh: "委内瑞拉"),
        Country(code: "VG", nameEn: "British Virgin Islands", nameZh: "英属维尔京群岛"),
        Country(code: "VI", nameEn: "U.S. Virgin Islands", nameZh: "美属维尔京群岛"),
        Country(code: "VN", nameEn: "Vietnam", nameZh: "越南"),
        Country(code: "VU", nameEn: "Vanuatu", nameZh: "瓦努阿图"),
        Country(code: "WF", nameEn: "Wallis & Futuna", nameZh: "瓦利斯和富图纳"),
        Country(code: "WS", nameEn: "Samoa", nameZh: "萨摩亚"),
        Country(code: "YE", nameEn: "Yemen", nameZh: "也门"),
        Country(code: "YT", nameEn: "Mayotte", nameZh: "马约特"),
        Country(code: "ZA", nameEn: "South Africa", nameZh: "南非"),
        Country(code: "ZM", nameEn: "Zambia", nameZh: "赞比亚"),
        Country(code: "ZW", nameEn: "Zimbabwe", nameZh: "津巴布韦"),
    ]

    /// Same ranking as Android: exact code, English prefix, Chinese prefix, then by English name.
    public static func search(_ query: String, limit: Int = 12) -> [Country] {
        let raw = query.trimmingCharacters(in: .whitespacesAndNewlines)
        let normalized = normalize(query)
        if raw.isEmpty { return [] }
        let matches = all.filter { country in
            (!normalized.isEmpty && normalize(country.code).contains(normalized))
                || (!normalized.isEmpty && normalize(country.nameEn).contains(normalized))
                || country.nameZh.contains(raw)
        }
        func rank(_ country: Country) -> Int {
            if normalize(country.code) == normalized { return 0 }
            if normalize(country.nameEn).hasPrefix(normalized) { return 1 }
            if country.nameZh.hasPrefix(raw) { return 2 }
            return 3
        }
        let sorted = matches.sorted { lhs, rhs in
            let l = rank(lhs)
            let r = rank(rhs)
            if l != r { return l < r }
            return lhs.nameEn < rhs.nameEn
        }
        return Array(sorted.prefix(limit))
    }

    public static func name(for code: String, zh: Bool) -> String {
        guard let country = all.first(where: { $0.code.lowercased() == code.lowercased() }) else {
            return code.uppercased()
        }
        return zh ? country.nameZh : country.nameEn
    }

    /// Strips diacritics, collapses non-alphanumerics to single spaces, lowercases.
    static func normalize(_ value: String) -> String {
        let folded = value.folding(options: [.diacriticInsensitive], locale: nil)
        var result = ""
        var pendingSpace = false
        for scalar in folded.unicodeScalars {
            let v = scalar.value
            let isAlnum = (v >= 48 && v <= 57) || (v >= 65 && v <= 90) || (v >= 97 && v <= 122)
            if isAlnum {
                if pendingSpace && !result.isEmpty { result.append(" ") }
                pendingSpace = false
                result.unicodeScalars.append(scalar)
            } else {
                pendingSpace = true
            }
        }
        return result.lowercased()
    }
}
