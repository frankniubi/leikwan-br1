package com.leikwan.bridge.api

import com.google.gson.JsonParser

object PanelErrorParser {
    fun messageFromHttp(code: Int, reason: String?, body: String?): String {
        return messageFromBody(body)
            ?: buildString {
                append("HTTP ")
                append(code)
                if (!reason.isNullOrBlank()) {
                    append(' ')
                    append(reason)
                }
            }
    }

    fun messageFromBody(body: String?): String? {
        if (body.isNullOrBlank()) return null
        return runCatching {
            val obj = JsonParser.parseString(body).asJsonObject
            obj.get("error")?.asString
                ?: obj.get("message")?.asString
        }.getOrNull()?.takeIf { it.isNotBlank() }
    }
}
