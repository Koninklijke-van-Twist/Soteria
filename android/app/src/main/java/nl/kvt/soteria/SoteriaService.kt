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
        AlertNotifications.ensureChannels(this)
        val notification = AlertNotifications.serviceNotification(this)
        val fgsType = when {
            Build.VERSION.SDK_INT >= 34 ->
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION or ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q ->
                ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
            else -> 0
        }
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
        startLocationUpdates()
        pollTask = executor.scheduleWithFixedDelay({ tick() }, 0, 5, TimeUnit.SECONDS)
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        return START_STICKY
    }

    override fun onDestroy() {
        pollTask?.cancel(true)
        LocationServices.getFusedLocationProviderClient(this).removeLocationUpdates(locationCallback)
        executor.shutdownNow()
        Ringer.stop(this)
        Ringer.stopVibration(this)
        super.onDestroy()
    }

    private fun startLocationUpdates() {
        if (ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED
        ) {
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
            stopSelf()
            return
        }
        runCatching {
            val release = UpdateChecker.check()
            if (release != null) {
                UpdateChecker.notifyIfNeeded(this, release)
            }
        }
        if (lastLat == 0.0 && lastLng == 0.0) {
            return
        }
        runCatching {
            val json = ApiClient.post(
                "heartbeat",
                mapOf("lat" to lastLat, "lng" to lastLng)
            )
            val alert = ApiClient.parseAlert(json)
            if (alert != null && ringingAlertId != alert.id) {
                ringingAlertId = alert.id
                Ringer.start(this, alert)
            }
        }
    }
}
