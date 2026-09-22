package nl.kvt.soteria

import android.Manifest
import android.app.Service
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.ActivityCompat
import androidx.core.app.ServiceCompat
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit

class SoteriaService : Service() {
    private val executor = Executors.newSingleThreadScheduledExecutor()
    private var pollTask: ScheduledFuture<*>? = null
    private var lastLat = 0.0
    private var lastLng = 0.0
    private var ringingAlertId: Int? = null

    companion object {
        @Volatile
        var running: Boolean = false
            private set
    }

    private val locationCallback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            val location = result.lastLocation ?: return
            lastLat = location.latitude
            lastLng = location.longitude
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        running = false
        val fine = ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        val coarse = ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        if (!fine && !coarse) {
            Prefs.serviceBlockReason = Prefs.BLOCK_LOCATION
            Watchdog.schedule(this)
            stopSelf()
            return
        }
        AlertNotifications.ensureChannels(this)
        val foregroundStarted = promoteToForeground()
        if (!foregroundStarted) {
            Prefs.serviceBlockReason = Prefs.BLOCK_FOREGROUND
            Watchdog.schedule(this)
            stopSelf()
            return
        }
        if (!AlertNotifications.canPostNotifications(this)) {
            Prefs.serviceBlockReason = Prefs.BLOCK_NOTIFICATIONS
            Watchdog.schedule(this)
            stopSelf()
            return
        }
        Prefs.serviceBlockReason = Prefs.BLOCK_NONE
        running = true
        AlertNotifications.cancelStatus(this)
        Watchdog.schedule(this)
        startLocationUpdates()
        pollTask = executor.scheduleWithFixedDelay({ tick() }, 0, 5, TimeUnit.SECONDS)
    }

    private fun promoteToForeground(): Boolean {
        val notification = AlertNotifications.serviceNotification(this)
        val fgsType = when {
            Build.VERSION.SDK_INT >= 34 ->
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION or ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q ->
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
            else -> 0
        }
        return runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                ServiceCompat.startForeground(
                    this,
                    AlertNotifications.SERVICE_ID,
                    notification,
                    fgsType
                )
            } else {
                startForeground(AlertNotifications.SERVICE_ID, notification)
            }
        }.isSuccess
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        return START_STICKY
    }

    override fun onDestroy() {
        running = false
        pollTask?.cancel(true)
        LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(locationCallback)
        executor.shutdownNow()
        Ringer.stop(this)
        Ringer.stopVibration(this)
        super.onDestroy()
    }

    private fun startLocationUpdates() {
        val fine = ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        val coarse = ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        if (!fine && !coarse) {
            return
        }
        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 15_000L)
            .setMinUpdateIntervalMillis(10_000L)
            .build()
        LocationServices.getFusedLocationProviderClient(this)
            .requestLocationUpdates(request, locationCallback, Looper.getMainLooper())
    }

    private fun tick() {
        if (!Prefs.isLoggedIn) {
            Watchdog.cancel(this)
            stopSelf()
            return
        }
        if (!AlertNotifications.canPostNotifications(this)) {
            Prefs.serviceBlockReason = Prefs.BLOCK_NOTIFICATIONS
            stopSelf()
            return
        }
        runCatching {
            val release = UpdateChecker.check()
            if (release != null) {
                UpdateChecker.notifyIfNeeded(this, release)
            }
        }
        runCatching {
            val acknowledgedAlertId = Prefs.acknowledgedAlertId
            if (acknowledgedAlertId > 0) {
                ApiClient.post("ack_alert", mapOf("alert_id" to acknowledgedAlertId))
            }
            val payload = mutableMapOf<String, Any?>(
                "lat" to lastLat,
                "lng" to lastLng,
                "current_alert_id" to Prefs.currentRespondingAlertId
            )
            val deliveredAlertId = Prefs.deliveredAlertId
            if (deliveredAlertId > 0) {
                payload["delivered_alert_id"] = deliveredAlertId
            }
            val json = ApiClient.post("heartbeat", payload)
            val ownEmail = Prefs.email.trim().lowercase()
            val presentAt = ApiClient.parseLocations(json)
                .filter { location ->
                    location.people.any { it.email.trim().lowercase() == ownEmail }
                }
                .map { it.name }
            AlertNotifications.updatePresence(this, presentAt)
            val cancelled = json.optJSONObject("cancelled_alert")
            if (cancelled != null) {
                val cancelledId = cancelled.optInt("id")
                Ringer.cancelAlert(this, cancelledId)
                AlertNotifications.showCancelled(
                    this,
                    cancelled.optString("caller_name").ifBlank { getString(R.string.unknown_caller) }
                )
                if (ringingAlertId == cancelledId) ringingAlertId = null
            }
            val expired = json.optJSONObject("expired_alert")
            if (expired != null) {
                val expiredId = expired.optInt("id")
                val alreadyAcknowledged = Prefs.acknowledgedAlertId == expiredId
                Ringer.cancelAlert(this, expiredId)
                if (!alreadyAcknowledged) AlertNotifications.showExpired(this)
                if (ringingAlertId == expiredId) ringingAlertId = null
            }
            val alert = ApiClient.parseAlert(json)
            if (alert != null) {
                val firstDelivery = Prefs.deliveredAlertId != alert.id
                // Afgeleverd = toestel heeft pending_alert gezien. Los van Bevestigen.
                Prefs.deliveredAlertId = alert.id
                if (ringingAlertId != alert.id) {
                    ringingAlertId = alert.id
                    Ringer.start(this, alert)
                }
                if (firstDelivery) {
                    ApiClient.post(
                        "heartbeat",
                        mapOf(
                            "lat" to lastLat,
                            "lng" to lastLng,
                            "current_alert_id" to Prefs.currentRespondingAlertId,
                            "delivered_alert_id" to alert.id
                        )
                    )
                }
            }
        }
    }
}
