package nl.kvt.soteria

import android.content.Context
import android.content.Intent
import android.net.Uri
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

data class AppRelease(
    val versionCode: Int,
    val tag: String,
    val htmlUrl: String,
    val apkUrl: String?
)

object UpdateChecker {
    const val RELEASES_API =
        "https://api.github.com/repos/Koninklijke-van-Twist/Soteria/releases/latest"
    const val RELEASES_PAGE =
        "https://github.com/Koninklijke-van-Twist/Soteria/releases/latest"
    private const val CHECK_INTERVAL_MS = 3 * 60 * 60 * 1000L

    fun cachedUpdate(): AppRelease? {
        val remote = Prefs.lastKnownRemoteVersion
        if (remote <= BuildConfig.VERSION_CODE) return null
        val url = Prefs.lastKnownReleaseUrl.ifBlank { RELEASES_PAGE }
        return AppRelease(
            versionCode = remote,
            tag = "apk-$remote",
            htmlUrl = url,
            apkUrl = Prefs.lastKnownApkUrl.ifBlank { null }
        )
    }

    fun check(force: Boolean = false): AppRelease? {
        val now = System.currentTimeMillis()
        if (!force && now - Prefs.lastUpdateCheckAt < CHECK_INTERVAL_MS) {
            return cachedUpdate()
        }
        Prefs.lastUpdateCheckAt = now
        val release = fetchLatest() ?: return cachedUpdate()
        Prefs.lastKnownRemoteVersion = release.versionCode
        Prefs.lastKnownReleaseUrl = release.htmlUrl
        Prefs.lastKnownApkUrl = release.apkUrl.orEmpty()
        return if (release.versionCode > BuildConfig.VERSION_CODE) release else null
    }

    fun notifyIfNeeded(context: Context, release: AppRelease) {
        if (release.versionCode <= Prefs.lastNotifiedRemoteVersion) return
        Prefs.lastNotifiedRemoteVersion = release.versionCode
        AlertNotifications.showUpdate(context, release)
    }

    fun openRelease(context: Context, release: AppRelease) {
        val target = release.apkUrl ?: release.htmlUrl
        context.startActivity(
            Intent(Intent.ACTION_VIEW, Uri.parse(target)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        )
    }

    private fun fetchLatest(): AppRelease? {
        val connection = (URL(RELEASES_API).openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = 15000
            readTimeout = 15000
            setRequestProperty("Accept", "application/vnd.github+json")
            setRequestProperty("User-Agent", "Soteria/${BuildConfig.VERSION_CODE}")
        }
        val code = connection.responseCode
        val stream = if (code in 200..299) connection.inputStream else connection.errorStream
        val body = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
        if (code !in 200..299) return null
        return parseRelease(JSONObject(body))
    }

    private fun parseRelease(json: JSONObject): AppRelease? {
        if (json.optBoolean("draft") || json.optBoolean("prerelease")) return null
        val tag = json.optString("tag_name")
        val name = json.optString("name")
        val body = json.optString("body")
        val version = parseVersionCode(tag)
            ?: parseVersionCode(name)
            ?: parseVersionCode(body)
            ?: return null
        var apkUrl: String? = null
        val assets = json.optJSONArray("assets")
        if (assets != null) {
            for (i in 0 until assets.length()) {
                val asset = assets.getJSONObject(i)
                val assetName = asset.optString("name").lowercase()
                if (assetName.endsWith(".apk")) {
                    apkUrl = asset.optString("browser_download_url").ifBlank { null }
                    break
                }
            }
        }
        return AppRelease(
            versionCode = version,
            tag = tag,
            htmlUrl = json.optString("html_url").ifBlank { RELEASES_PAGE },
            apkUrl = apkUrl
        )
    }

    private fun parseVersionCode(value: String): Int? {
        Regex("""(?:apk-|versionCode\s*[=:]?\s*)(\d+)""", RegexOption.IGNORE_CASE)
            .find(value)
            ?.groupValues
            ?.get(1)
            ?.toIntOrNull()
            ?.let { return it }
        return value.trim().toIntOrNull()
    }
}
