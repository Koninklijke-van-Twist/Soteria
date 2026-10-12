package nl.kvt.soteria

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import androidx.core.content.ContextCompat
import com.google.android.gms.location.CurrentLocationRequest
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import com.google.android.gms.tasks.Tasks
import java.util.concurrent.TimeUnit

/**
 * Losse locatiemetingen in plaats van continue tracking.
 * Elke aanroep vraagt één fix; de locatieprovider stopt direct daarna.
 * Blokkeert: alleen aanroepen vanaf een achtergrondthread.
 */
object LocationFix {
    /** Interval voor de aanwezigheidsmeting. Aanwezigheid hoeft niet vaker dan eens per kwartier. */
    const val PRESENCE_INTERVAL_MS: Long = 15 * 60 * 1000L

    /** Na een mislukte meting (geen fix) opnieuw proberen met backoff vanaf dit interval. */
    const val RETRY_INTERVAL_MS: Long = 30 * 1000L

    /** Een fix die nog niet ouder is dan dit mag opnieuw worden gebruikt. */
    private const val PRESENCE_MAX_AGE_MS: Long = 5 * 60 * 1000L
    private const val PRESENCE_TIMEOUT_MS: Long = 30_000L
    private const val PRECISE_MAX_AGE_MS: Long = 30_000L
    private const val PRECISE_TIMEOUT_MS: Long = 10_000L

    /** Energiezuinige meting (wifi/zendmasten, GPS alleen als nodig) voor aanwezigheid. */
    fun presence(context: Context): Location? =
        fix(context, Priority.PRIORITY_BALANCED_POWER_ACCURACY, PRESENCE_MAX_AGE_MS, PRESENCE_TIMEOUT_MS)

    /** Precieze meting op verzoek, bijvoorbeeld bij het starten van een noodoproep. */
    fun precise(context: Context): Location? =
        fix(context, Priority.PRIORITY_HIGH_ACCURACY, PRECISE_MAX_AGE_MS, PRECISE_TIMEOUT_MS)

    /** Laatst bekende locatie van het systeem (geen nieuwe meting), als fallback. */
    fun lastKnown(context: Context): Location? {
        val fine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        val coarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        if (!fine && !coarse) return null
        return try {
            Tasks.await(LocationServices.getFusedLocationProviderClient(context).lastLocation, 5, TimeUnit.SECONDS)
        } catch (e: Exception) {
            null
        }
    }

    private fun fix(context: Context, priority: Int, maxAgeMs: Long, timeoutMs: Long): Location? {
        val fine = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        val coarse = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        if (!fine && !coarse) return null
        val request = CurrentLocationRequest.Builder()
            .setPriority(priority)
            .setMaxUpdateAgeMillis(maxAgeMs)
            .setDurationMillis(timeoutMs)
            .build()
        val cancel = CancellationTokenSource()
        return try {
            val task = LocationServices.getFusedLocationProviderClient(context)
                .getCurrentLocation(request, cancel.token)
            Tasks.await(task, timeoutMs + 2_000L, TimeUnit.MILLISECONDS)
        } catch (e: SecurityException) {
            null
        } catch (e: Exception) {
            cancel.cancel()
            null
        }
    }
}
