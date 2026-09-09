package nl.kvt.soteria

import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.media.RingtoneManager
import android.os.Build
import android.os.PowerManager
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import androidx.core.content.ContextCompat

object Ringer {
    @Volatile
    var activeAlert: PendingAlert? = null

    private var player: MediaPlayer? = null
    private var wakeLock: PowerManager.WakeLock? = null

    fun start(context: Context, alert: PendingAlert) {
        activeAlert = alert
        val app = context.applicationContext
        acquireWakeLock(app)
        startSound(app)
        vibrate(app)
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
        app.startActivity(intent)
    }

    fun stop(context: Context) {
        player?.run {
            runCatching { stop() }
            reset()
            release()
        }
        player = null
        wakeLock?.let { lock ->
            if (lock.isHeld) lock.release()
        }
        wakeLock = null
        AlertNotifications.cancelIncoming(context.applicationContext)
    }

    private fun startSound(context: Context) {
        if (player != null) return
        val uri = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM)
            ?: RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE)
        val mediaPlayer = MediaPlayer()
        mediaPlayer.setAudioAttributes(
            AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_ALARM)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build()
        )
        mediaPlayer.isLooping = true
        mediaPlayer.setDataSource(context, uri)
        mediaPlayer.prepare()
        val audio = context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
        runCatching {
            audio.setStreamVolume(
                AudioManager.STREAM_ALARM,
                audio.getStreamMaxVolume(AudioManager.STREAM_ALARM),
                0
            )
        }
        mediaPlayer.start()
        player = mediaPlayer
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
            val manager = context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager
            manager.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            context.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
        }
        val pattern = longArrayOf(0, 800, 400, 800, 400)
        vibrator.vibrate(VibrationEffect.createWaveform(pattern, 0))
    }

    fun stopVibration(context: Context) {
        val vibrator = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val manager = context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager
            manager.defaultVibrator
        } else {
            @Suppress("DEPRECATION")
            context.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
        }
        vibrator.cancel()
    }

    fun isRinging(): Boolean = player?.isPlaying == true
}
