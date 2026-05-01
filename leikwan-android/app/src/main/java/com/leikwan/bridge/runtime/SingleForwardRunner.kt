package com.leikwan.bridge.runtime

object SingleForwardRunner {
    fun buildPlan(
        forward: ForwardContract,
        binaries: RuntimeBinaryPaths,
    ): RuntimeContext {
        val endpoint = forward.endpoints.firstOrNull()
            ?: throw IllegalArgumentException("single mode requires at least one usable endpoint")
        val wstunnel = binaries.wstunnelBinary?.takeIf { it.isNotBlank() }
            ?: throw IllegalArgumentException("single mode requires packaged wstunnel")

        val command = mutableListOf(
            wstunnel,
            "client",
        )
        if (forward.transport == "tcp" || forward.transport == "tcp+udp") {
            command += listOf(
                "-L",
                "tcp://127.0.0.1:${forward.listenPort}:${forward.target}",
            )
        }
        if (forward.transport == "udp" || forward.transport == "tcp+udp") {
            command += listOf(
                "-L",
                "udp://127.0.0.1:${forward.listenPort}:${forward.target}?timeout_sec=0",
            )
        }
        if (forward.sni.isNotBlank()) {
            command += listOf("--tls-sni-override", forward.sni)
            command += listOf("--http-headers", "Host: ${forward.sni}")
        }
        if (forward.pathPrefix.isNotBlank()) {
            command += listOf("--http-upgrade-path-prefix", forward.pathPrefix)
        }
        command += "wss://${endpoint.host}:${endpoint.port}"

        val bypassHosts = linkedSetOf(endpoint.host)
        endpoint.cdnHost?.let { bypassHosts += it }

        return RuntimeContext(
            forwardId = forward.forwardId,
            mode = ForwardMode.SINGLE,
            localListenPort = forward.listenPort,
            localLegPorts = emptyList(),
            processPlans = listOf(
                RuntimeProcessPlan(
                    role = RuntimeProcessRole.SINGLE,
                    command = command,
                )
            ),
            bypassHosts = bypassHosts,
        )
    }
}
