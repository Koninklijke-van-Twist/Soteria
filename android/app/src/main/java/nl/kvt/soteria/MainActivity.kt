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
import androidx.lifecycle.Lifecycle
import nl.kvt.soteria.databinding.ActivityMainBinding
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity() {
    private lateinit var binding: ActivityMainBinding
    private val io = Executors.newSingleThreadExecutor()
    private var locations: List<LocationPresence> = emptyList()
    private var activeAcknowledgedAlerts: List<ActiveAcknowledgedAlert> = emptyList()
    private var overviewRows: List<OverviewRow> = emptyList()
    private var suppressNotificationPrompt = false
    private var skipNextNotificationPrompt = false
    private var serviceStartSettled = false
    private var createdAtMs = 0L

    private sealed interface OverviewRow {
        data class Location(val value: LocationPresence) : OverviewRow
        data class Alert(val value: ActiveAcknowledgedAlert) : OverviewRow
    }

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

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { granted ->
        suppressNotificationPrompt = false
        if (granted) {
            skipNextNotificationPrompt = false
            continueAfterForegroundPermissions()
        } else {
            skipNextNotificationPrompt = true
            updateReliabilityUi()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!Prefs.isLoggedIn) {
            startActivity(Intent(this, LoginActivity::class.java))
            finish()
            return
        }

        createdAtMs = System.currentTimeMillis()
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)
        binding.userName.text = Prefs.displayName.ifBlank { Prefs.email }
        binding.choicePanel.visibility = View.GONE

        binding.callButton.setOnClickListener {
            if (!hasNotificationPermission()) {
                updateReliabilityUi()
                promptForNotifications(allowSettings = true)
                return@setOnClickListener
            }
            binding.choicePanel.visibility = View.VISIBLE
        }
        binding.callToAssembly.setOnClickListener { createAlert("assembly") }
        binding.callToMe.setOnClickListener { createAlert("caller") }
        binding.refreshButton.setOnClickListener { refresh() }
        binding.locationList.setOnItemClickListener { _, _, position, _ ->
            when (val row = overviewRows.getOrNull(position)) {
                is OverviewRow.Location -> showLocationPeople(row.value)
                is OverviewRow.Alert -> openAlertDestination(row.value)
                null -> Unit
            }
        }

        requestPermissions()
    }

    override fun onResume() {
        super.onResume()
        if (!Prefs.isLoggedIn || !::binding.isInitialized) return
        if (hasLocationPermission()) {
            if (!hasNotificationPermission()) {
                if (skipNextNotificationPrompt) {
                    skipNextNotificationPrompt = false
                } else if (!suppressNotificationPrompt) {
                    promptForNotifications(allowSettings = false)
                }
            } else {
                startServiceSafely()
                if (System.currentTimeMillis() - createdAtMs > 1500L) {
                    askForBatteryOptimizationException()
                }
            }
        }
        updateReliabilityUi()
        refresh()
        resumeOutgoingAlert()
        checkForUpdates()
    }

    private fun requestPermissions() {
        val needed = mutableListOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION
        )
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
            updateReliabilityUi()
            return
        }
        if (!hasNotificationPermission()) {
            updateReliabilityUi()
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
        requestBatteryExemption(force = false)
    }

    private fun requestBatteryExemption(force: Boolean) {
        if (isIgnoringBatteryOptimizations()) return
        val now = System.currentTimeMillis()
        if (!force && now - Prefs.lastBatteryPromptAt < BATTERY_PROMPT_INTERVAL_MS) return
        Prefs.lastBatteryPromptAt = now
        Prefs.askedBattery = true
        runCatching {
            startActivity(
                Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
                    data = Uri.parse("package:$packageName")
                }
            )
        }
    }

    private fun isIgnoringBatteryOptimizations(): Boolean {
        return getSystemService(PowerManager::class.java).isIgnoringBatteryOptimizations(packageName)
    }

    private fun backgroundLocationPromptPending(): Boolean {
        if (Build.VERSION.SDK_INT <= Build.VERSION_CODES.Q) return false
        val granted = ContextCompat.checkSelfPermission(
            this,
            Manifest.permission.ACCESS_BACKGROUND_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
        return !granted && !Prefs.askedBackgroundLocation
    }

    private fun hasNotificationPermission(): Boolean {
        return AlertNotifications.canPostNotifications(this)
    }

    private fun promptForNotifications(allowSettings: Boolean) {
        if (Build.VERSION.SDK_INT < 33 || hasNotificationPermission()) return
        val canAsk = !Prefs.askedNotifications ||
            shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS)
        if (!canAsk) {
            if (allowSettings) openNotificationSettings()
            return
        }
        suppressNotificationPrompt = true
        Prefs.askedNotifications = true
        notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    private fun openNotificationSettings() {
        val intent = Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).apply {
            putExtra(Settings.EXTRA_APP_PACKAGE, packageName)
        }
        runCatching { startActivity(intent) }
    }

    private fun updateReliabilityUi() {
        if (!::binding.isInitialized || isFinishing || isDestroyed) return
        val callsAllowed = hasNotificationPermission()
        binding.callButton.isEnabled = callsAllowed
        binding.callToAssembly.isEnabled = callsAllowed
        binding.callToMe.isEnabled = callsAllowed
        if (SoteriaService.running) {
            Prefs.serviceBlockReason = Prefs.BLOCK_NONE
            AlertNotifications.cancelStatus(this)
            if (Prefs.bootPresenceInactive &&
                lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED)
            ) {
                Prefs.bootPresenceInactive = false
                AlertDialog.Builder(this)
                    .setTitle(R.string.presence_inactive_title)
                    .setMessage(R.string.presence_resumed_message)
                    .setPositiveButton(R.string.close, null)
                    .show()
            }
        }

        when {
            !callsAllowed -> showReliabilityBanner(
                message = getString(R.string.notifications_required),
                action = getString(R.string.grant_notifications),
                severe = true
            ) { promptForNotifications(allowSettings = true) }
            !hasLocationPermission() -> hideReliabilityBanner()
            shouldWarnServiceDown() -> {
                val message = if (Prefs.bootPresenceInactive && Build.VERSION.SDK_INT >= 35) {
                    getString(R.string.presence_inactive_boot)
                } else {
                    getString(R.string.service_not_running)
                }
                showReliabilityBanner(
                    message = message,
                    action = getString(R.string.restart_service),
                    severe = true
                ) { startServiceSafely() }
            }
            !isIgnoringBatteryOptimizations() -> showReliabilityBanner(
                message = getString(R.string.battery_exemption_missing),
                action = getString(R.string.allow_battery),
                severe = false
            ) { requestBatteryExemption(force = true) }
            else -> hideReliabilityBanner()
        }
    }

    private fun shouldWarnServiceDown(): Boolean {
        if (SoteriaService.running) return false
        return serviceStartSettled ||
            Prefs.bootPresenceInactive ||
            Prefs.serviceBlockReason.isNotBlank()
    }

    private fun showReliabilityBanner(
        message: String,
        action: String,
        severe: Boolean,
        onAction: () -> Unit
    ) {
        binding.reliabilityBanner.visibility = View.VISIBLE
        binding.reliabilityBanner.text = message
        binding.reliabilityBanner.setBackgroundColor(
            ContextCompat.getColor(this, if (severe) R.color.danger else R.color.accent)
        )
        binding.reliabilityAction.visibility = View.VISIBLE
        binding.reliabilityAction.text = action
        binding.reliabilityAction.setOnClickListener { onAction() }
    }

    private fun hideReliabilityBanner() {
        binding.reliabilityBanner.visibility = View.GONE
        binding.reliabilityAction.visibility = View.GONE
    }

    private fun hasLocationPermission(): Boolean {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
    }

    private fun startServiceSafely() {
        if (!hasLocationPermission() || !hasNotificationPermission()) {
            updateReliabilityUi()
            return
        }
        serviceStartSettled = false
        val started = runCatching {
            ContextCompat.startForegroundService(this, Intent(this, SoteriaService::class.java))
        }.onFailure {
            Prefs.serviceBlockReason = Prefs.BLOCK_FOREGROUND
        }.isSuccess
        if (!started) updateReliabilityUi()
        binding.root.postDelayed({
            serviceStartSettled = true
            if (!isFinishing && !isDestroyed) updateReliabilityUi()
        }, 800)
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
            activeAcknowledgedAlerts = ApiClient.parseActiveAcknowledgedAlerts(json)
            runOnUiThread {
                if (isFinishing || isDestroyed) return@runOnUiThread
                overviewRows = buildOverviewRows()
                val labels = overviewRows.map { row ->
                    when (row) {
                        is OverviewRow.Location ->
                            "${row.value.name}: ${row.value.presentCount} BHV'er(s)"
                        is OverviewRow.Alert -> alertLabel(row.value)
                    }
                }
                binding.locationList.adapter = ArrayAdapter(
                    this,
                    android.R.layout.simple_list_item_1,
                    labels
                )
            }
        }
    }

    private fun buildOverviewRows(): List<OverviewRow> = buildList {
        locations.forEach { location ->
            add(OverviewRow.Location(location))
            val userIsPresent = location.people.any {
                it.email.equals(Prefs.email, ignoreCase = true)
            }
            if (userIsPresent) {
                activeAcknowledgedAlerts.forEach { add(OverviewRow.Alert(it)) }
            }
        }
    }

    private fun alertLabel(alert: ActiveAcknowledgedAlert): String {
        return if (alert.type == "assembly") {
            getString(R.string.active_call_to_assembly, alert.destName)
        } else {
            getString(R.string.active_call_to_person, alert.callerName)
        }
    }

    private fun showLocationPeople(location: LocationPresence) {
        val names = location.people.joinToString("\n") { it.name.ifBlank { it.email } }
            .ifBlank { getString(R.string.nobody_present) }
        AlertDialog.Builder(this)
            .setTitle("${location.name} (${location.presentCount})")
            .setMessage(names)
            .setPositiveButton(R.string.close, null)
            .show()
    }

    private fun openAlertDestination(alert: ActiveAcknowledgedAlert) {
        val navigation = Intent(
            Intent.ACTION_VIEW,
            Uri.parse("google.navigation:q=${alert.destLat},${alert.destLng}")
        ).setPackage("com.google.android.apps.maps")
        val web = Intent(
            Intent.ACTION_VIEW,
            Uri.parse(
                "https://www.google.com/maps/dir/?api=1&destination=${alert.destLat},${alert.destLng}"
            )
        )
        val opened = runCatching { startActivity(navigation) }.isSuccess ||
            runCatching { startActivity(web) }.isSuccess
        if (!opened) Toast.makeText(this, R.string.no_maps_app, Toast.LENGTH_LONG).show()
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
        if (!hasNotificationPermission()) {
            updateReliabilityUi()
            promptForNotifications(allowSettings = true)
            return
        }
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

    companion object {
        private const val BATTERY_PROMPT_INTERVAL_MS = 12 * 60 * 60 * 1000L
    }
}
