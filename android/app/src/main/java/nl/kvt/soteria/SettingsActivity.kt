package nl.kvt.soteria

import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import nl.kvt.soteria.databinding.ActivitySettingsBinding

class SettingsActivity : AppCompatActivity() {
    private lateinit var binding: ActivitySettingsBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.serverUrl.setText(Prefs.apiBaseUrl)
        binding.defaultHint.text = getString(R.string.default_server_hint, Prefs.defaultApiBaseUrl)

        binding.saveButton.setOnClickListener {
            val url = normalizeUrl(binding.serverUrl.text?.toString().orEmpty())
            if (url == null) {
                Toast.makeText(this, R.string.invalid_server_url, Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            Prefs.apiBaseUrl = url
            Toast.makeText(this, R.string.server_saved, Toast.LENGTH_SHORT).show()
            finish()
        }

        binding.resetButton.setOnClickListener {
            Prefs.apiBaseUrl = ""
            binding.serverUrl.setText(Prefs.defaultApiBaseUrl)
            Toast.makeText(this, R.string.server_reset, Toast.LENGTH_SHORT).show()
        }
    }

    private fun normalizeUrl(raw: String): String? {
        var value = raw.trim().trimEnd('/')
        if (value.isBlank()) {
            return Prefs.defaultApiBaseUrl
        }
        if (!value.contains("://")) {
            value = "https://$value"
        }
        if (!value.startsWith("https://", ignoreCase = true) &&
            !value.startsWith("http://", ignoreCase = true)
        ) {
            return null
        }
        return value.trimEnd('/')
    }
}
