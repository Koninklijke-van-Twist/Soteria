package nl.kvt.soteria

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.SystemClock

object Watchdog {
    private const val INTERVAL_MS = 3 * 60 * 1000L
    private const val NUDGE_COOLDOWN_MS = 15 * 60 * 1000L
    private const val ACTION = "nl.kvt.soteria.WATCHDOG"

    fun schedule(context: Context) {
        val app = context.applicationContext
        val alarm = app.getSystemService(AlarmManager::class.java) ?: return
        val triggerAt = SystemClock.elapsedRealtime() + INTERVAL_MS
        runCatching {
            alarm.setAndAllowWhileIdle(
                AlarmManager.ELAPSED_REALTIME_WAKEUP,
                triggerAt,
                pendingIntent(app)
            )
        }
    }

    fun cancel(context: Context) {
        val app = context.applicationContext
        val alarm = app.getSystemService(AlarmManager::class.java) ?: return
        alarm.cancel(pendingIntent(app))
    }

    fun nudgeIfNeeded(context: Context) {
        val now = System.currentTimeMillis()
        if (now - Prefs.lastServiceNudgeAt < NUDGE_COOLDOWN_MS) return
        if (!AlertNotifications.canPostNotifications(context)) return
        Prefs.lastServiceNudgeAt = now
        val text = if (Prefs.bootPresenceInactive) {
            context.getString(R.string.presence_inactive_notification)
        } else {
            context.getString(R.string.service_died_notification)
        }
        AlertNotifications.showStatusCue(context, text)
    }

    private fun pendingIntent(context: Context): PendingIntent {
        val intent = Intent(context, WatchdogReceiver::class.java).setAction(ACTION)
        return PendingIntent.getBroadcast(
            context,
            0,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
    }
}

class WatchdogReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        Prefs.init(context)
        if (!Prefs.isLoggedIn) {
            Watchdog.cancel(context)
            return
        }
        if (!SoteriaService.running) {
            Watchdog.nudgeIfNeeded(context)
        }
        Watchdog.schedule(context)
    }
}
