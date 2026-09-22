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
        // Android 14+ vereist bij een achtergrondstart van een locatie-FGS ook achtergrondlocatie.
        // Het type location valt niet onder de BOOT_COMPLETED-blokkade van Android 15.
        if (Build.VERSION.SDK_INT >= 34 && !hasBackgroundLocation(context)) return false
        return true
    }

    private fun markPresenceInactive(context: Context) {
        Prefs.bootPresenceInactive = true
        Prefs.lastServiceNudgeAt = System.currentTimeMillis()
        AlertNotifications.showStatusCue(
            context,
            context.getString(R.string.presence_inactive_notification)
        )
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
