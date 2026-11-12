package com.leikwan.bridge.runtime

import com.google.gson.Gson
import com.google.gson.JsonParser
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File
import java.time.Instant

/** The heartbeat's `ultra_status` is shaped, bounded and redacted like the OpenWrt router. */
class UltraStatusCollectorTest {
    @get:Rule val temp = TemporaryFolder()

    private lateinit var filesDir: File
    private lateinit var statusDir: File
    private val now = Instant.parse("2030-01-01T00:10:00Z")
    private val hashA = "a".repeat(64)
    private val hashB = "b".repeat(64)
    private val selectionRef = "sel_ABCDEFGHIJKLMNOP"

    @Before
    fun setUp() {
        filesDir = temp.newFolder("files")
        statusDir = File(filesDir, "ultra").apply { mkdirs() }
    }

    private fun collect() = UltraStatusCollector.forFilesDir(filesDir).collect(now)

    private fun write(name: String, json: String) = File(statusDir, name).writeText(json)

    private fun encoded(reports: List<UltraStatusReport>) = JsonParser.parseString(Gson().toJson(reports)).asJsonArray

    @Test
    fun `no runtime directory or no documents is an empty list`() {
        assertEquals(emptyList<UltraStatusReport>(), UltraStatusCollector(File(filesDir, "missing")).collect(now))
        assertEquals(emptyList<UltraStatusReport>(), collect())
    }

    @Test
    fun `a full document keeps only the fields the panel normalizes`() {
        write(
            "forward-170-cccccccccccc.status.json",
            """
            {"forward_id":170,"state":"Degraded","updated_at":"2030-01-01T00:09:30.123456789Z",
             "error_code":"relay_handshake_timeout","policy_generation":"${"c".repeat(64)}",
             "detected_ingress_zone":"CN-GD","udp_transport":"external_uot","location_source":"probe",
             "latest_tcp":{"selection_ref":"$selectionRef","selection_tier":"primary","candidate_key_hash":"${hashA.uppercase()}",
                           "lease_hash":"$hashB","rtt_ms":42,"p95_rtt_ms":80,"loss_percent":0,"probe_sent":10,
                           "probe_received":9,"selected_at":"2030-01-01T00:05:00Z","endpoint":"203.0.113.9:443"},
             "active_udp":{"selection_ref":"short","rtt_ms":1},
             "udp_runtime_session":{"selection_ref":"$selectionRef","rtt_ms":1},
             "ha_failures":3,"udp_queue_drops":-5,"udp_setup_failures":2,"bytes_in":4096,"bytes_out":1024,
             "candidate_feedback":[{"candidate_key_hash":"$hashA","outcome":"failure"}],
             "runtime_attestation":{"sha256":"$hashA"},
             "credentials":{"password":"secret"},
             "ha_events":[{"protocol":"TCP","reason":"Relay_Handshake_Timeout","generation":"g1","at":"2030-01-01T00:09:00Z",
                           "candidate_key_hash":"$hashA","lease_hash":"$hashB","upstream_bytes":-1,"remote_read_bytes":77,
                           "remote_read_reason":"eof","remote_stopped_first":true,"local_path_avoided":false,
                           "endpoint":"203.0.113.9:443"}]}
            """.trimIndent(),
        )

        val reports = collect()
        assertEquals(1, reports.size)
        val entry = encoded(reports)[0].asJsonObject
        assertEquals(
            setOf(
                "forward_id", "state", "udp_transport", "detected_ingress_zone", "latest_tcp", "ha_failures",
                "udp_queue_drops", "udp_setup_failures", "bytes_in", "bytes_out", "ha_events", "updated_at", "error_code",
            ),
            entry.keySet(),
        )
        assertEquals(170L, entry.get("forward_id").asLong)
        assertEquals("degraded", entry.get("state").asString)
        assertEquals("external_uot", entry.get("udp_transport").asString)
        assertEquals("cn-gd", entry.get("detected_ingress_zone").asString)
        assertEquals(0L, entry.get("udp_queue_drops").asLong)
        assertEquals(3L, entry.get("ha_failures").asLong)
        assertEquals("relay_handshake_timeout", entry.get("error_code").asString)
        assertEquals("2030-01-01T00:09:30.123456789Z", entry.get("updated_at").asString)

        val tcp = entry.getAsJsonObject("latest_tcp")
        assertEquals(
            setOf("selection_ref", "rtt_ms", "selected_at", "candidate_key_hash", "p95_rtt_ms", "probe_sent", "probe_received"),
            tcp.keySet(),
        )
        assertEquals(selectionRef, tcp.get("selection_ref").asString)
        assertEquals(hashA, tcp.get("candidate_key_hash").asString)

        val event = entry.getAsJsonArray("ha_events")[0].asJsonObject
        assertEquals("tcp", event.get("protocol").asString)
        assertEquals("relay_handshake_timeout", event.get("reason").asString)
        assertEquals(hashB, event.get("lease_hash").asString)
        assertEquals(0L, event.get("upstream_bytes").asLong)
        assertEquals(77L, event.get("remote_read_bytes").asLong)
        assertEquals("eof", event.get("remote_read_reason").asString)
        assertTrue(event.get("remote_stopped_first").asBoolean)
        assertFalse(event.get("local_path_avoided").asBoolean)
        assertFalse(event.has("endpoint"))

        val wire = Gson().toJson(reports)
        listOf("secret", "203.0.113.9", "runtime_attestation", "candidate_feedback", "selection_tier", "policy_generation")
            .forEach { assertFalse("leaked $it", wire.contains(it)) }
    }

    @Test
    fun `newest document per forward wins and stopped or stale runtimes are left out`() {
        write("forward-1-aaaaaaaaaaaa.status.json", """{"forward_id":1,"state":"error","updated_at":"2030-01-01T00:08:00Z"}""")
        write("forward-1-bbbbbbbbbbbb.status.json", """{"forward_id":1,"state":"ready","updated_at":"2030-01-01T00:09:00Z"}""")
        write("forward-2-aaaaaaaaaaaa.status.json", """{"forward_id":2,"state":"stopped","updated_at":"2030-01-01T00:09:00Z"}""")
        write("forward-3-aaaaaaaaaaaa.status.json", """{"forward_id":3,"error_code":"runtime_stopped","updated_at":"2030-01-01T00:09:00Z"}""")
        write("forward-4-aaaaaaaaaaaa.status.json", """{"forward_id":4,"state":"ready","updated_at":"2030-01-01T00:04:59Z"}""")
        write("forward-5-aaaaaaaaaaaa.status.json", """{"forward_id":5,"state":"weird","updated_at":"2030-01-01T00:09:30Z"}""")
        write("forward-6-aaaaaaaaaaaa.status.json", """{"forward_id":0,"state":"ready"}""")
        write("forward-7-aaaaaaaaaaaa.status.json", "{not json")
        write("forward-8-aaaaaaaaaaaa.status.json", "")
        write("notes.json", """{"forward_id":9,"state":"ready","updated_at":"2030-01-01T00:09:00Z"}""")

        val reports = collect()
        // Newest first: forward 5 (00:09:30) before forward 1 (00:09:00).
        assertEquals(listOf(5L, 1L), reports.map { it.forwardId })
        assertEquals("error", reports[0].state)
        assertEquals("ready", reports[1].state)
        assertNull(reports[1].udpTransport)
        assertEquals("unknown", reports[1].detectedIngressZone)
    }

    @Test
    fun `entries events and bytes are bounded`() {
        val events = (1..20).joinToString(",") { """{"protocol":"udp","reason":"r$it","at":"t$it"}""" }
        (1..40).forEach { id ->
            write(
                "forward-$id-aaaaaaaaaaaa.status.json",
                """{"forward_id":$id,"state":"ready","updated_at":"2030-01-01T00:09:00Z","ha_events":[$events]}""",
            )
        }
        val reports = collect()
        assertEquals(UltraStatusCollector.MAX_ENTRIES, reports.size)
        assertEquals((1L..32L).toList(), reports.map { it.forwardId })
        assertEquals((13..20).map { "r$it" }, reports[0].haEvents.map { it.reason })

        // An entry whose events would overflow the byte budget keeps its verdict without them.
        val longEvents = (1..8).joinToString(",") {
            """{"protocol":"tcp","reason":"${"x".repeat(200)}","generation":"${"g".repeat(200)}","at":"${"t".repeat(200)}","candidate_key_hash":"$hashA","lease_hash":"$hashB"}"""
        }
        val documents = (1..32L).map { id ->
            UltraStatusCollector.Document(
                JsonParser.parseString(
                    """{"forward_id":$id,"state":"ready","updated_at":"2030-01-01T00:09:00Z","error_code":"${"e".repeat(500)}","ha_events":[$longEvents]}""",
                ).asJsonObject,
                null,
            )
        }
        val bounded = UltraStatusCollector.buildReports(documents, now)
        // The budget runs out: early entries keep their events, later ones give them up, and
        // what still does not fit is left out rather than overflowing the panel's limit.
        assertTrue(bounded.size in 2 until 32)
        assertEquals((1L..bounded.size.toLong()).toList(), bounded.map { it.forwardId })
        // Entry sizes include their separating comma, so the list adds at most its brackets.
        assertTrue(Gson().toJson(bounded).toByteArray().size <= UltraStatusCollector.MAX_BYTES + 1)
        assertTrue(bounded.first().haEvents.isNotEmpty())
        assertTrue(bounded.last().haEvents.isEmpty())
        assertEquals(64, bounded.first().errorCode.length)
        assertEquals(64, bounded.first().haEvents.first().reason.length)
    }

    @Test
    fun `strings are trimmed to printable ascii and invalid hashes and events are dropped`() {
        write(
            "forward-9-aaaaaaaaaaaa.status.json",
            """
            {"forward_id":9,"updated_at":" 2030-01-01T00:09:00Z ","error_code":"bad\u0000code\nnext 中文",
             "detected_ingress_zone":"hk-1",
             "latest_tcp":{"selection_ref":"$selectionRef","rtt_ms":5,"candidate_key_hash":"xyz"},
             "ha_events":[{"protocol":"quic","reason":"x"},{"protocol":"tcp","reason":"  "},
                          {"protocol":"udp","reason":"ok","candidate_key_hash":"${"a".repeat(63)}","remote_read_reason":"Bad Reason",
                           "remote_stopped_first":"true"}]}
            """.trimIndent(),
        )
        val report = collect().single()
        assertEquals("ready", report.state)
        assertEquals("hk-1", report.detectedIngressZone)
        assertEquals("badcodenext", report.errorCode)
        assertEquals("2030-01-01T00:09:00Z", report.updatedAt)
        assertNull(report.latestTcp?.candidateKeyHash)
        assertEquals(1, report.haEvents.size)
        val event = report.haEvents.single()
        assertEquals("udp", event.protocol)
        assertNull(event.candidateKeyHash)
        assertNull(event.remoteReadReason)
        assertNull(event.remoteStoppedFirst)
        assertEquals("", event.generation)
    }
}
