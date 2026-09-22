package nl.kvt.soteria

import android.Manifest
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action != Intent.ACTION_BOOT_COMPLETED) return
        Prefs.init(context)
        if (!Prefs.isLoggedIn) return
        Watchdog.schedule(context)
        if (!canStartAfterBoot(context)) {
            markPresenceInactive(context)
            return
        }
        val started = runCatching {
            ContextCompat.startForegroundService(context, Intent(context, SoteriaService::class.java))
        }.isSuccess
        if (!started) markPresenceInactive(context)
    }

    private fun canStartAfterBoot(context: Context): Boolean {
        if (!hasLocation(context)) return false
        if (!AlertNotifications.canPostNotifications(context)) return false
        // Android 15 staat niet toe dat een locatie-FGS vanuit BOOT_COMPLETED start.
        // De gebruiker moet de app zelf openen; zie markPresenceInactive.
        if (Build.VERSION.SDK_INT >= 35) return false
        // Android 14 vereist bij zo'n achtergrondstart bovendien achtergrondlocatie.
        if (Build.VERSION.SDK_INT >= 34 && !hasBackgroundLocation(context)) return false
        return true
    }

    private fun markPresenceInactive(context: Context) {
        Prefs.bootPresenceInactive = true
        Prefs.lastServiceNudgeAt = System.currentTimeMillis()
        val text = if (Build.VERSION.SDK_INT >= 35) {
            context.getString(R.string.presence_inactive_notification)
        } else {
            context.getString(R.string.service_died_notification)
        }
        AlertNotifications.showStatusCue(context, text)
    }

    private fun hasLocation(context: Context): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
    }

    private fun hasBackgroundLocation(context: Context): Boolean {
        return ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_BACKGROUND_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
    }
}
