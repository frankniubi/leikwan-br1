package com.leikwan.bridge.api

import com.google.gson.JsonElement
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.leikwan.bridge.session.DeviceLimitReachedException

object PanelErrorParser {
    private const val HTTP_TOO_MANY_REQUESTS = 429

    /**
     * Maps a panel `429 client_online_limit_reached` refusal to [DeviceLimitReachedException].
     * Counts are read from the flat fields first and from `details` second; any other status or
     * error code returns null so ordinary rate limiting keeps its existing handling.
     */
    fun deviceLimitFromHttp(code: Int, body: String?): DeviceLimitReachedException? {
        if (code != HTTP_TOO_MANY_REQUESTS || body.isNullOrBlank()) return null
        val obj = runCatching { JsonParser.parseString(body).asJsonObject }.getOrNull() ?: return null
        val error = obj.get("error")
        val errorCode = if (error?.isJsonObject == true) {
            stringValue(error.asJsonObject.get("code"))
        } else {
            stringValue(error)
        }
        if (errorCode != DeviceLimitReachedException.CODE) return null
        val details = obj.get("details")?.takeIf { it.isJsonObject }?.asJsonObject
        return DeviceLimitReachedException(
            limit = countValue(obj, details, "online_device_limit"),
            active = countValue(obj, details, "active_online_devices"),
        )
    }

    private fun countValue(obj: JsonObject, details: JsonObject?, key: String): Int? =
        intValue(obj.get(key)) ?: intValue(details?.get(key))

    private fun intValue(element: JsonElement?): Int? = runCatching {
        element?.takeIf { it.isJsonPrimitive }?.asString?.trim()?.toInt()
    }.getOrNull()?.takeIf { it >= 0 }

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
