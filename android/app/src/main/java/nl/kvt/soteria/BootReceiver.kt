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
        val hasLocation = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        // Android 15 staat niet toe dat een locatie-FGS vanuit BOOT_COMPLETED start.
        if (!hasLocation || Build.VERSION.SDK_INT >= 35) return
        runCatching {
            ContextCompat.startForegroundService(context, Intent(context, SoteriaService::class.java))
        }
    }
}
