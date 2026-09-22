package nl.kvt.soteria

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import android.content.pm.PackageManager

object AlertNotifications {
    const val SERVICE_CHANNEL = "soteria_service"
    // Nieuwe ID: kanaalinstellingen zijn na creatie onveranderlijk op Android.
    const val ALERT_CHANNEL = "soteria_alert_v2"
    const val SERVICE_ID = 1001
    const val ALERT_ID = 1002
    const val UPDATE_ID = 1003
    const val CANCELLED_ID = 1004
    const val UPDATE_CHANNEL = "soteria_update"
    const val STATUS_CHANNEL = "soteria_status"
    const val STATUS_ID = 1005
    const val EXPIRED_ID = 1006

    fun ensureChannels(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(
            NotificationChannel(
                SERVICE_CHANNEL,
                "Soteria aanwezigheid",
                NotificationManager.IMPORTANCE_LOW
            )
        )
        manager.createNotificationChannel(
            NotificationChannel(
                UPDATE_CHANNEL,
                "App-updates",
                NotificationManager.IMPORTANCE_DEFAULT
            ).apply {
                description = "Nieuwe Soteria-versies van GitHub Releases"
            }
        )
        val alertChannel = NotificationChannel(
            ALERT_CHANNEL,
            "BHV-oproepen",
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = "Noodoproepen die blijven rinkelen tot je reageert"
            setBypassDnd(true)
            // De Ringer beheert de herhalende vibratie zelf, zodat die direct stopbaar is.
            enableVibration(false)
            lockscreenVisibility = Notification.VISIBILITY_PUBLIC
            setSound(null, null)
        }
        manager.createNotificationChannel(alertChannel)
        manager.createNotificationChannel(
            NotificationChannel(
                STATUS_CHANNEL,
                "Soteria status",
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "Melding als aanwezigheid of oproepen uit staan"
            }
        )
    }

    fun serviceNotification(context: Context, locations: List<String> = emptyList()): Notification {
        val open = PendingIntent.getActivity(
            context,
            0,
            Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        return NotificationCompat.Builder(context, SERVICE_CHANNEL)
            .setSmallIcon(R.drawable.ic_shield)
            .setContentTitle("Soteria")
            .setContentText(presenceText(context, locations))
            .setContentIntent(open)
            .setOngoing(true)
            .setSilent(true)
            .build()
    }

    fun updatePresence(context: Context, locations: List<String>) {
        if (!canPostNotifications(context)) return
        context.getSystemService(NotificationManager::class.java)
            .notify(SERVICE_ID, serviceNotification(context, locations))
    }

    private fun presenceText(context: Context, locations: List<String>): String {
        if (locations.isEmpty()) return context.getString(R.string.presence_service_active)
        val names = when (locations.size) {
            1 -> locations.first()
            2 -> locations.joinToString(" en ")
            else -> locations.dropLast(1).joinToString(", ") + " en " + locations.last()
        }
        return context.getString(R.string.present_in_locations, names)
    }

    fun showIncoming(context: Context, alert: PendingAlert) {
        if (!canPostNotifications(context)) return
        val intent = Intent(context, AlertActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("alert_id", alert.id)
            putExtra("message", alert.message)
            putExtra("dest_name", alert.destName)
            putExtra("dest_lat", alert.destLat)
            putExtra("dest_lng", alert.destLng)
            putExtra("ringing", true)
        }
        val fullScreen = PendingIntent.getActivity(
            context,
            alert.id,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val builder = NotificationCompat.Builder(context, ALERT_CHANNEL)
            .setSmallIcon(R.drawable.ic_shield)
            .setContentTitle("BHV-oproep")
            .setContentText("Tik om de oproep te openen")
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setCategory(NotificationCompat.CATEGORY_ALARM)
            .setContentIntent(fullScreen)
            .setOngoing(true)
            .setAutoCancel(false)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
        val canUseFullScreen = Build.VERSION.SDK_INT < 34 ||
            context.getSystemService(NotificationManager::class.java).canUseFullScreenIntent()
        if (canUseFullScreen) {
            builder.setFullScreenIntent(fullScreen, true)
        }
        val notification = builder.build()
        context.getSystemService(NotificationManager::class.java).notify(ALERT_ID, notification)
    }

    fun showUpdate(context: Context, release: AppRelease) {
        if (!canPostNotifications(context)) return
        ensureChannels(context)
        val open = PendingIntent.getActivity(
            context,
            UPDATE_ID,
            Intent(Intent.ACTION_VIEW, Uri.parse(release.htmlUrl)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notification = NotificationCompat.Builder(context, UPDATE_CHANNEL)
            .setSmallIcon(R.drawable.ic_shield)
            .setContentTitle(context.getString(R.string.update_available_title))
            .setContentText(context.getString(R.string.update_available_text, release.versionCode))
            .setContentIntent(open)
            .setAutoCancel(true)
            .build()
        context.getSystemService(NotificationManager::class.java).notify(UPDATE_ID, notification)
    }

    fun cancelIncoming(context: Context) {
        context.getSystemService(NotificationManager::class.java).cancel(ALERT_ID)
    }

    fun showStatusCue(context: Context, text: String) {
        if (!canPostNotifications(context)) return
        ensureChannels(context)
        val open = PendingIntent.getActivity(
            context,
            STATUS_ID,
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val notification = NotificationCompat.Builder(context, STATUS_CHANNEL)
            .setSmallIcon(R.drawable.ic_shield)
            .setContentTitle(context.getString(R.string.presence_inactive_title))
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setContentIntent(open)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()
        context.getSystemService(NotificationManager::class.java).notify(STATUS_ID, notification)
    }

    fun cancelStatus(context: Context) {
        context.getSystemService(NotificationManager::class.java).cancel(STATUS_ID)
    }

    fun showExpired(context: Context) {
        if (!canPostNotifications(context)) return
        val open = PendingIntent.getActivity(
            context,
            EXPIRED_ID,
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val text = context.getString(R.string.alert_expired)
        val notification = NotificationCompat.Builder(context, ALERT_CHANNEL)
            .setSmallIcon(R.drawable.ic_shield)
            .setContentTitle(context.getString(R.string.alert_expired_title))
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setContentIntent(open)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()
        context.getSystemService(NotificationManager::class.java).notify(EXPIRED_ID, notification)
    }

    fun showCancelled(context: Context, callerName: String) {
        if (!canPostNotifications(context)) return
        val open = PendingIntent.getActivity(
            context,
            CANCELLED_ID,
            Intent(context, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        val text = context.getString(R.string.call_cancelled_by, callerName)
        val notification = NotificationCompat.Builder(context, ALERT_CHANNEL)
            .setSmallIcon(R.drawable.ic_shield)
            .setContentTitle(context.getString(R.string.call_cancelled_title))
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setContentIntent(open)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()
        context.getSystemService(NotificationManager::class.java).notify(CANCELLED_ID, notification)
    }

    fun canPostNotifications(context: Context): Boolean {
        return Build.VERSION.SDK_INT < 33 ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED
    }
}
