package nl.kvt.soteria

import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.os.Build
import android.os.PowerManager
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

object Ringer {
    const val ACTION_ALERT_CANCELLED = "nl.kvt.soteria.ALERT_CANCELLED"
    const val EXTRA_ALERT_ID = "alert_id"

    @Volatile
    var activeAlert: PendingAlert? = null

    private var player: MediaPlayer? = null
    private var activeVibrator: Vibrator? = null
    private var originalAlarmVolume: Int? = null
    private var wakeLock: PowerManager.WakeLock? = null

    @Synchronized
    fun start(context: Context, alert: PendingAlert) {
        activeAlert = alert
        Prefs.currentRespondingAlertId = alert.id
        val app = context.applicationContext
        acquireWakeLock(app)
        runCatching { startSound(app) }
        runCatching { vibrate(app) }
        AlertNotifications.showIncoming(app, alert)
        val intent = Intent(app, AlertActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            putExtra("alert_id", alert.id)
            putExtra("message", alert.message)
            putExtra("dest_name", alert.destName)
            putExtra("dest_lat", alert.destLat)
            putExtra("dest_lng", alert.destLng)
            putExtra("ringing", true)
        }
        runCatching { app.startActivity(intent) }
    }

    @Synchronized
    fun stop(context: Context) {
        player?.let { mediaPlayer ->
            runCatching { mediaPlayer.stop() }
            runCatching { mediaPlayer.reset() }
            runCatching { mediaPlayer.release() }
        }
        player = null
        restoreAlarmVolume(context.applicationContext)
        stopVibrationLocked(context.applicationContext)
        wakeLock?.let { lock ->
            if (lock.isHeld) lock.release()
        }
        wakeLock = null
        AlertNotifications.cancelIncoming(context.applicationContext)
    }

    @Synchronized
    fun cancelAlert(context: Context, alertId: Int) {
        stop(context)
        stopVibration(context)
        activeAlert = null
        if (Prefs.currentRespondingAlertId == alertId) {
            Prefs.currentRespondingAlertId = 0
        }
        if (Prefs.acknowledgedAlertId == alertId) {
            Prefs.acknowledgedAlertId = 0
        }
        context.applicationContext.sendBroadcast(
            Intent(ACTION_ALERT_CANCELLED)
                .setPackage(context.packageName)
                .putExtra(EXTRA_ALERT_ID, alertId)
        )
    }

    private fun startSound(context: Context) {
        if (player != null) return
        val attributes = AudioAttributes.Builder()
            .setUsage(AudioAttributes.USAGE_ALARM)
            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
            .build()
        val mediaPlayer = MediaPlayer.create(
            context,
            R.raw.soteria_alarm,
            attributes,
            AudioManager.AUDIO_SESSION_ID_GENERATE
        ) ?: return
        try {
            mediaPlayer.isLooping = true
            mediaPlayer.setVolume(1.0f, 1.0f)
            val audio = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
            runCatching {
                if (originalAlarmVolume == null) {
                    originalAlarmVolume = audio.getStreamVolume(AudioManager.STREAM_ALARM)
                }
                audio.setStreamVolume(
                    AudioManager.STREAM_ALARM,
                    audio.getStreamMaxVolume(AudioManager.STREAM_ALARM),
                    0
                )
            }
            mediaPlayer.start()
            player = mediaPlayer
        } catch (error: Exception) {
            runCatching { mediaPlayer.release() }
            throw error
        }
    }

    private fun restoreAlarmVolume(context: Context) {
        val volume = originalAlarmVolume ?: return
        originalAlarmVolume = null
        runCatching {
            val audio = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
            audio.setStreamVolume(AudioManager.STREAM_ALARM, volume, 0)
        }
    }

    private fun acquireWakeLock(context: Context) {
        if (wakeLock?.isHeld == true) return
        val pm = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        wakeLock = pm.newWakeLock(
            PowerManager.PARTIAL_WAKE_LOCK,
            "soteria:alert"
        ).apply {
            setReferenceCounted(false)
            acquire(30 * 60 * 1000L)
        }
    }

    private fun vibrate(context: Context) {
        val vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val manager = context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager
            manager?.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        }
        val pattern = longArrayOf(0, 800, 400, 800, 400)
        activeVibrator = vibrator
        activeVibrator?.vibrate(VibrationEffect.createWaveform(pattern, 0))
    }

    @Synchronized
    fun stopVibration(context: Context) {
        stopVibrationLocked(context.applicationContext)
    }

    private fun stopVibrationLocked(context: Context) {
        activeVibrator?.cancel()
        activeVibrator = null
        // Annuleer ook via een nieuw verkregen handle voor OEMs die handles per context bijhouden.
        val systemVibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val manager = context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as? VibratorManager
            manager?.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            context.getSystemService(Context.VIBRATOR_SERVICE) as? Vibrator
        }
        systemVibrator?.cancel()
    }

    @Synchronized
    fun isRinging(): Boolean = runCatching { player?.isPlaying == true }.getOrDefault(false)
}
