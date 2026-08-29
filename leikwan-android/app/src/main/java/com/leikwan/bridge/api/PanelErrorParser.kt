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
            messageFromElement(obj.get("error"))
                ?: stringValue(obj.get("message"))
        }.getOrNull()?.takeIf { it.isNotBlank() }
    }

    private fun messageFromElement(element: com.google.gson.JsonElement?): String? {
        if (element == null || element.isJsonNull) return null
        if (element.isJsonPrimitive) return stringValue(element)
        if (!element.isJsonObject) return null

        val obj = element.asJsonObject
        val code = stringValue(obj.get("code"))
        val details = obj.get("details")
            ?.takeIf { it.isJsonObject }
            ?.asJsonObject
        val reason = stringValue(details?.get("reason"))
        val message = stringValue(obj.get("message"))
        return listOfNotNull(code, reason ?: message)
            .distinct()
            .joinToString(": ")
            .takeIf { it.isNotBlank() }
    }

    private fun stringValue(element: com.google.gson.JsonElement?): String? = runCatching {
        element?.takeIf { it.isJsonPrimitive }?.asString
    }.getOrNull()?.trim()?.takeIf { it.isNotBlank() }
}
