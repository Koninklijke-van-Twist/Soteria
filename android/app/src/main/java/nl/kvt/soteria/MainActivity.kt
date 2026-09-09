package nl.kvt.soteria

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import nl.kvt.soteria.databinding.ActivityMainBinding
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private val io = Executors.newSingleThreadExecutor()
    private var locations: List<LocationPresence> = emptyList()

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) {
        requestBackgroundLocationIfNeeded()
        startService()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!Prefs.isLoggedIn) {
            startActivity(Intent(this, LoginActivity::class.java))
            finish()
            return
        }

        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)
        binding.userName.text = Prefs.displayName.ifBlank { Prefs.email }
        binding.choicePanel.visibility = View.GONE

        binding.callButton.setOnClickListener {
            binding.choicePanel.visibility = View.VISIBLE
        }
        binding.callToAssembly.setOnClickListener { createAlert("assembly") }
        binding.callToMe.setOnClickListener { createAlert("caller") }
        binding.refreshButton.setOnClickListener { refresh() }
        binding.locationList.setOnItemClickListener { _, _, position, _ ->
            val location = locations.getOrNull(position) ?: return@setOnItemClickListener
            val names = location.people.joinToString("\n") { it.name.ifBlank { it.email } }
                .ifBlank { "Niemand aanwezig" }
            AlertDialog.Builder(this)
                .setTitle("${location.name} (${location.presentCount})")
                .setMessage(names)
                .setPositiveButton("Sluiten", null)
                .show()
        }

        requestPermissions()
        ignoreBatteryOptimizations()
    }

    override fun onResume() {
        super.onResume()
        if (Prefs.isLoggedIn) {
            startService()
            refresh()
            checkForUpdates()
        }
    }

    private fun requestPermissions() {
        val needed = mutableListOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION
        )
        if (Build.VERSION.SDK_INT >= 33) {
            needed.add(Manifest.permission.POST_NOTIFICATIONS)
        }
        val missing = needed.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (missing.isEmpty()) {
            requestBackgroundLocationIfNeeded()
            startService()
        } else {
            permissionLauncher.launch(needed.toTypedArray())
        }
    }

    private fun requestBackgroundLocationIfNeeded() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            != PackageManager.PERMISSION_GRANTED
        ) {
            permissionLauncher.launch(arrayOf(Manifest.permission.ACCESS_BACKGROUND_LOCATION))
        }
    }

    private fun ignoreBatteryOptimizations() {
        if (Prefs.askedBattery) return
        val pm = getSystemService(PowerManager::class.java)
        if (!pm.isIgnoringBatteryOptimizations(packageName)) {
            Prefs.askedBattery = true
            runCatching {
                startActivity(
                    Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                        data = Uri.parse("package:$packageName")
                    }
                )
            }
        }
    }

    private fun startService() {
        ContextCompat.startForegroundService(this, Intent(this, SoteriaService::class.java))
    }

    private fun refresh() {
        io.execute {
            val json = runCatching { ApiClient.post("locations") }.getOrNull()
            if (json == null) {
                runOnUiThread { Toast.makeText(this, "Kon locaties niet laden", Toast.LENGTH_SHORT).show() }
                return@execute
            }
            if (json.optInt("_http") == 401) {
                runOnUiThread {
                    startActivity(Intent(this, LoginActivity::class.java))
                    finish()
                }
                return@execute
            }
            locations = ApiClient.parseLocations(json)
            runOnUiThread {
                val labels = locations.map { "${it.name}: ${it.presentCount} BHV'er(s)" }
                binding.locationList.adapter = ArrayAdapter(
                    this,
                    android.R.layout.simple_list_item_1,
                    labels
                )
            }
        }
    }

    private fun checkForUpdates() {
        io.execute {
            val release = runCatching { UpdateChecker.check() }.getOrNull() ?: return@execute
            if (release.versionCode <= Prefs.lastPromptedRemoteVersion) return@execute
            Prefs.lastPromptedRemoteVersion = release.versionCode
            runOnUiThread { showUpdateDialog(release) }
        }
    }

    private fun showUpdateDialog(release: AppRelease) {
        AlertDialog.Builder(this)
            .setTitle(R.string.update_available_title)
            .setMessage(getString(R.string.update_available_message, release.versionCode))
            .setPositiveButton(R.string.update_open) { _, _ -> UpdateChecker.openRelease(this, release) }
            .setNegativeButton(R.string.update_later, null)
            .show()
    }

    private fun createAlert(type: String) {
        io.execute {
            val json = runCatching { ApiClient.post("create_alert", mapOf("type" to type)) }.getOrNull()
            runOnUiThread {
                if (json == null || json.optBoolean("ok") != true) {
                    Toast.makeText(this, json?.optString("error") ?: "Oproep mislukt", Toast.LENGTH_LONG).show()
                } else {
                    val count = json.optInt("recipient_count")
                    Toast.makeText(this, "Oproep verstuurd naar $count BHV'er(s)", Toast.LENGTH_LONG).show()
                    binding.choicePanel.visibility = View.GONE
                }
            }
        }
    }
}
