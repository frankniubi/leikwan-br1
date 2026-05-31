package com.leikwan.bridge.net

/**
 * A DNS-over-HTTPS endpoint together with the literal IP addresses of its own host.
 *
 * The bootstrap IPs let OkHttp connect to the DoH server without first performing a
 * plaintext DNS lookup of the DoH hostname — which both avoids the chicken-and-egg
 * problem and sidesteps GFW UDP/53 poisoning.
 */
data class DohEndpoint(
    val name: String,
    val url: String,
    val bootstrapIps: List<String>,
)

/**
 * DoH providers ordered for mainland-China reachability.
 *
 * The GFW poisons plaintext DNS (UDP/53) but not these domestic providers' own HTTPS
 * resolvers, so resolving a normal (non-blocked) panel domain through them returns the
 * correct address. Cloudflare / Google DoH are intentionally excluded from the in-China
 * path (effectively blocked since ~2023); the platform system resolver remains as a
 * last-resort fallback (see [AppDns]).
 */
object DohEndpoints {
    val CHINA_FIRST: List<DohEndpoint> = listOf(
        // Alibaba AliDNS — domestic anycast, most reliable inside mainland China.
        // Cert on dns.alidns.com also covers the IP SANs, so the bootstrap IPs are safe.
        DohEndpoint(
            name = "AliDNS",
            url = "https://dns.alidns.com/dns-query",
            bootstrapIps = listOf("223.5.5.5", "223.6.6.6"),
        ),
        // Tencent DNSPod — domestic anycast. Use the doh.pub domain for TLS SNI; the IPs
        // are only used to bootstrap the connection (still resolve as of 2024).
        DohEndpoint(
            name = "DNSPod",
            url = "https://doh.pub/dns-query",
            bootstrapIps = listOf("1.12.12.12", "120.53.53.53"),
        ),
        // Qihoo 360 Secure DNS — domestic fallback.
        DohEndpoint(
            name = "360",
            url = "https://doh.360.cn/dns-query",
            bootstrapIps = listOf("101.226.4.6", "218.30.118.6"),
        ),
    )
}
