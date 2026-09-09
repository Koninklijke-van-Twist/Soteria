package nl.kvt.soteria

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.browser.customtabs.CustomTabsIntent
import nl.kvt.soteria.databinding.ActivityLoginBinding
import org.json.JSONObject
import java.util.concurrent.Executors

class LoginActivity : AppCompatActivity() {
    private lateinit var binding: ActivityLoginBinding
    private val io = Executors.newSingleThreadExecutor()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)
        binding.loginButton.setOnClickListener { startLogin() }
        binding.settingsButton.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }
        handleAuthIntent(intent)
        checkForUpdates()
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleAuthIntent(intent)
    }

    private fun startLogin() {
        val url = Prefs.apiBaseUrl
        CustomTabsIntent.Builder().build().launchUrl(this, Uri.parse("$url/app-auth.php"))
    }

    private fun checkForUpdates() {
        io.execute {
            val release = runCatching { UpdateChecker.check() }.getOrNull() ?: return@execute
            if (release.versionCode <= Prefs.lastPromptedRemoteVersion) return@execute
            Prefs.lastPromptedRemoteVersion = release.versionCode
            runOnUiThread {
                if (isFinishing || isDestroyed) return@runOnUiThread
                AlertDialog.Builder(this)
                    .setTitle(R.string.update_available_title)
                    .setMessage(getString(R.string.update_available_message, release.versionCode))
                    .setPositiveButton(R.string.update_open) { _, _ -> UpdateChecker.openRelease(this, release) }
                    .setNegativeButton(R.string.update_later, null)
                    .show()
            }
        }
    }

    private fun handleAuthIntent(intent: Intent?) {
        val uri = intent?.data ?: return
        if (uri.scheme != "nl.kvt.soteria" || uri.host != "auth") return
        val token = uri.getQueryParameter("token").orEmpty()
        if (token.isBlank()) return
        Prefs.token = token
        io.execute {
            val attempt = runCatching { ApiClient.post("me") }
            val me = attempt.getOrNull()
            if (me?.optBoolean("ok") == true) {
                Prefs.displayName = me.optString("name")
                Prefs.email = me.optString("email")
                runOnUiThread {
                    if (isFinishing || isDestroyed) return@runOnUiThread
                    startActivity(Intent(this, MainActivity::class.java))
                    finish()
                }
            } else {
                Prefs.clearSession()
                val reason = failureReason(me, attempt.exceptionOrNull())
                runOnUiThread { showLoginError(reason) }
            }
        }
    }

    private fun failureReason(response: JSONObject?, error: Throwable?): String {
        val serverError = response?.optString("error").orEmpty().trim()
        if (serverError.isNotBlank()) {
            val status = response?.optInt("_http", 0) ?: 0
            return if (status > 0) "$serverError (HTTP $status)" else serverError
        }
        if (error != null) {
            val message = error.message.orEmpty().trim()
            val type = error.javaClass.simpleName
            return if (message.isBlank()) type else "$type: $message"
        }
        return getString(R.string.login_failed_unknown)
    }

    private fun showLoginError(reason: String) {
        if (isFinishing || isDestroyed) return
        AlertDialog.Builder(this)
            .setTitle(R.string.login_failed)
            .setMessage(getString(R.string.login_failed_detail, reason))
            .setPositiveButton(R.string.close, null)
            .show()
    }

    override fun onDestroy() {
        io.shutdownNow()
        super.onDestroy()
    }
}
