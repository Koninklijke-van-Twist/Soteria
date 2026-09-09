package nl.kvt.soteria

import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

object ApiClient {
    fun post(action: String, extra: Map<String, Any?> = emptyMap()): JSONObject {
        val payload = JSONObject()
        payload.put("action", action)
        payload.put("token", Prefs.token)
        extra.forEach { (key, value) ->
            if (value != null) payload.put(key, value)
        }
        val url = URL(Prefs.apiBaseUrl + "/api.php")
        val connection = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 15000
            readTimeout = 15000
            doOutput = true
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            setRequestProperty("Authorization", "Bearer ${Prefs.token}")
        }
        connection.outputStream.bufferedWriter(Charsets.UTF_8).use { writer ->
            writer.write(payload.toString())
        }
        val code = connection.responseCode
        val stream = if (code in 200..299) connection.inputStream else connection.errorStream
        val body = stream?.bufferedReader()?.use { it.readText() } ?: "{}"
        if (code == 401) {
            Prefs.clearSession()
        }
        val json = if (body.isBlank()) JSONObject() else JSONObject(body)
        json.put("_http", code)
        return json
    }

    fun parseLocations(json: JSONObject): List<LocationPresence> {
        val array = json.optJSONArray("locations") ?: return emptyList()
        val result = mutableListOf<LocationPresence>()
        for (i in 0 until array.length()) {
            val item = array.getJSONObject(i)
            val peopleJson = item.optJSONArray("people")
            val people = mutableListOf<Person>()
            if (peopleJson != null) {
                for (j in 0 until peopleJson.length()) {
                    val person = peopleJson.getJSONObject(j)
                    people.add(
                        Person(
                            email = person.optString("email"),
                            name = person.optString("name")
                        )
                    )
                }
            }
            result.add(
                LocationPresence(
                    id = item.optInt("id"),
                    name = item.optString("name"),
                    lat = item.optDouble("lat"),
                    lng = item.optDouble("lng"),
                    presentCount = item.optInt("present_count"),
                    people = people
                )
            )
        }
        return result
    }

    fun parseAlert(json: JSONObject): PendingAlert? {
        val raw = json.opt("alert") ?: json.opt("pending_alert") ?: return null
        if (raw == JSONObject.NULL) return null
        val alert = raw as? JSONObject ?: return null
        val id = alert.optInt("id", 0)
        if (id <= 0) return null
        return PendingAlert(
            id = id,
            callerName = alert.optString("caller_name"),
            type = alert.optString("type"),
            destName = alert.optString("dest_name"),
            destLat = alert.optDouble("dest_lat"),
            destLng = alert.optDouble("dest_lng"),
            message = alert.optString("message")
        )
    }
}
