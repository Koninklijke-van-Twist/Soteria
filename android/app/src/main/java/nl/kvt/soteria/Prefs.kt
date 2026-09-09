package nl.kvt.soteria

import android.content.Context
import android.content.SharedPreferences

object Prefs {
    private lateinit var prefs: SharedPreferences

    val defaultApiBaseUrl: String
        get() = BuildConfig.API_BASE_URL.trimEnd('/')

    fun init(context: Context) {
        prefs = context.applicationContext.getSharedPreferences("soteria", Context.MODE_PRIVATE)
    }

    var token: String
        get() = prefs.getString("token", "") ?: ""
        set(value) { prefs.edit().putString("token", value).apply() }

    var displayName: String
        get() = prefs.getString("display_name", "") ?: ""
        set(value) { prefs.edit().putString("display_name", value).apply() }

    var email: String
        get() = prefs.getString("email", "") ?: ""
        set(value) { prefs.edit().putString("email", value).apply() }

    var apiBaseUrl: String
        get() {
            val stored = (prefs.getString("api_base_url", "") ?: "").trimEnd('/')
            return stored.ifBlank { defaultApiBaseUrl }
        }
        set(value) {
            val normalized = value.trim().trimEnd('/')
            if (normalized.isBlank() || normalized.equals(defaultApiBaseUrl, ignoreCase = true)) {
                prefs.edit().remove("api_base_url").apply()
            } else {
                prefs.edit().putString("api_base_url", normalized).apply()
            }
        }

    val usesCustomServer: Boolean
        get() = prefs.getString("api_base_url", "").orEmpty().isNotBlank()

    val isLoggedIn: Boolean get() = token.isNotBlank()

    var askedBattery: Boolean
        get() = prefs.getBoolean("asked_battery", false)
        set(value) { prefs.edit().putBoolean("asked_battery", value).apply() }

    var lastUpdateCheckAt: Long
        get() = prefs.getLong("last_update_check_at", 0L)
        set(value) { prefs.edit().putLong("last_update_check_at", value).apply() }

    var lastKnownRemoteVersion: Int
        get() = prefs.getInt("last_known_remote_version", 0)
        set(value) { prefs.edit().putInt("last_known_remote_version", value).apply() }

    var lastKnownReleaseUrl: String
        get() = prefs.getString("last_known_release_url", "") ?: ""
        set(value) { prefs.edit().putString("last_known_release_url", value).apply() }

    var lastKnownApkUrl: String
        get() = prefs.getString("last_known_apk_url", "") ?: ""
        set(value) { prefs.edit().putString("last_known_apk_url", value).apply() }

    var lastNotifiedRemoteVersion: Int
        get() = prefs.getInt("last_notified_remote_version", 0)
        set(value) { prefs.edit().putInt("last_notified_remote_version", value).apply() }

    var lastPromptedRemoteVersion: Int
        get() = prefs.getInt("last_prompted_remote_version", 0)
        set(value) { prefs.edit().putInt("last_prompted_remote_version", value).apply() }

    fun clearSession() {
        prefs.edit().remove("token").remove("display_name").remove("email").apply()
    }
}
