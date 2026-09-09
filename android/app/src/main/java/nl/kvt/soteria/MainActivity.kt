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
        continueAfterForegroundPermissions()
    }

    private val backgroundLocationLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) {
        startServiceSafely()
        askForBatteryOptimizationException()
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
    }

    override fun onResume() {
        super.onResume()
        if (Prefs.isLoggedIn) {
            startServiceSafely()
            refresh()
            resumeOutgoingAlert()
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
            continueAfterForegroundPermissions()
        } else {
            permissionLauncher.launch(missing.toTypedArray())
        }
    }

    private fun continueAfterForegroundPermissions() {
        if (!hasLocationPermission()) {
            return
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_BACKGROUND_LOCATION)
            != PackageManager.PERMISSION_GRANTED
        ) {
            if (Build.VERSION.SDK_INT == Build.VERSION_CODES.Q) {
                backgroundLocationLauncher.launch(Manifest.permission.ACCESS_BACKGROUND_LOCATION)
                return
            }
            startServiceSafely()
            if (!explainBackgroundLocationOnce()) {
                askForBatteryOptimizationException()
            }
        } else {
            startServiceSafely()
            askForBatteryOptimizationException()
        }
    }

    private fun explainBackgroundLocationOnce(): Boolean {
        if (Prefs.askedBackgroundLocation) return false
        Prefs.askedBackgroundLocation = true
        AlertDialog.Builder(this)
            .setTitle(R.string.background_location_title)
            .setMessage(R.string.background_location_explanation)
            .setPositiveButton(R.string.open_app_settings) { _, _ ->
                runCatching {
                    startActivity(
                        Intent(
                            Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                            Uri.parse("package:$packageName")
                        )
                    )
                }
            }
            .setNegativeButton(R.string.later) { _, _ -> askForBatteryOptimizationException() }
            .show()
        return true
    }

    private fun askForBatteryOptimizationException() {
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

    private fun hasLocationPermission(): Boolean {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
    }

    private fun startServiceSafely() {
        if (!hasLocationPermission()) return
        runCatching {
            ContextCompat.startForegroundService(this, Intent(this, SoteriaService::class.java))
        }
    }

    private fun refresh() {
        io.execute {
            val json = runCatching { ApiClient.post("locations") }.getOrNull()
            if (json == null) {
                runOnUiThread {
                    if (!isFinishing && !isDestroyed) {
                        Toast.makeText(this, "Kon locaties niet laden", Toast.LENGTH_SHORT).show()
                    }
                }
                return@execute
            }
            if (json.optInt("_http") == 401) {
                runOnUiThread {
                    if (isFinishing || isDestroyed) return@runOnUiThread
                    startActivity(Intent(this, LoginActivity::class.java))
                    finish()
                }
                return@execute
            }
            locations = ApiClient.parseLocations(json)
            runOnUiThread {
                if (isFinishing || isDestroyed) return@runOnUiThread
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
            runOnUiThread {
                if (!isFinishing && !isDestroyed) showUpdateDialog(release)
            }
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
                if (isFinishing || isDestroyed) return@runOnUiThread
                if (json == null || json.optBoolean("ok") != true) {
                    Toast.makeText(this, json?.optString("error") ?: "Oproep mislukt", Toast.LENGTH_LONG).show()
                } else {
                    val count = json.optInt("recipient_count")
                    if (!json.optBoolean("existing")) {
                        Toast.makeText(this, "Oproep verstuurd naar $count BHV'er(s)", Toast.LENGTH_LONG).show()
                    }
                    binding.choicePanel.visibility = View.GONE
                    openCallerAlert(json.optInt("alert_id"))
                }
            }
        }
    }

    private fun resumeOutgoingAlert() {
        io.execute {
            val json = runCatching { ApiClient.post("active_outgoing_alert") }.getOrNull()
                ?: return@execute
            val alertId = json.optJSONObject("alert")?.optInt("id", 0) ?: 0
            if (alertId > 0) {
                runOnUiThread {
                    if (!isFinishing && !isDestroyed) openCallerAlert(alertId)
                }
            }
        }
    }

    private fun openCallerAlert(alertId: Int) {
        if (alertId <= 0) return
        startActivity(
            Intent(this, CallerAlertActivity::class.java)
                .putExtra(CallerAlertActivity.EXTRA_ALERT_ID, alertId)
        )
    }

    override fun onDestroy() {
        io.shutdownNow()
        super.onDestroy()
    }
}
