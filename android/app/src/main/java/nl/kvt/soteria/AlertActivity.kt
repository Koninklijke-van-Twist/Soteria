package nl.kvt.soteria

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.WindowManager
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import nl.kvt.soteria.databinding.ActivityAlertBinding
import java.util.concurrent.Executors

class AlertActivity : AppCompatActivity() {
    private lateinit var binding: ActivityAlertBinding
    private val io = Executors.newSingleThreadExecutor()
    private var alertId = 0
    private var destLat = 0.0
    private var destLng = 0.0
    private var cancellationReceiverRegistered = false
    private val cancellationReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.getIntExtra(Ringer.EXTRA_ALERT_ID, 0) == alertId) finish()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
            )
        }

        binding = ActivityAlertBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val alert = Ringer.activeAlert
        alertId = intent.getIntExtra("alert_id", alert?.id ?: 0)
        destLat = intent.getDoubleExtra("dest_lat", alert?.destLat ?: 0.0)
        destLng = intent.getDoubleExtra("dest_lng", alert?.destLng ?: 0.0)

        binding.message.text = intent.getStringExtra("message") ?: alert?.message ?: "BHV-oproep"
        binding.answerButton.setOnClickListener { answer() }
        ContextCompat.registerReceiver(
            this,
            cancellationReceiver,
            IntentFilter(Ringer.ACTION_ALERT_CANCELLED),
            ContextCompat.RECEIVER_NOT_EXPORTED
        )
        cancellationReceiverRegistered = true
    }

    private fun openGoogleMaps() {
        val nav = Intent(
            Intent.ACTION_VIEW,
            Uri.parse("google.navigation:q=$destLat,$destLng")
        ).setPackage("com.google.android.apps.maps")
        val web = Intent(
            Intent.ACTION_VIEW,
            Uri.parse("https://www.google.com/maps/dir/?api=1&destination=$destLat,$destLng")
        )
        val opened = runCatching { startActivity(nav) }.isSuccess ||
            runCatching { startActivity(web) }.isSuccess
        if (!opened) {
            Toast.makeText(this, R.string.no_maps_app, Toast.LENGTH_LONG).show()
        }
    }

    private fun answer() {
        binding.answerButton.isEnabled = false
        Ringer.stop(this)
        Ringer.stopVibration(this)
        if (alertId > 0) {
            Prefs.acknowledgedAlertId = alertId
            io.execute {
                runCatching { ApiClient.post("ack_alert", mapOf("alert_id" to alertId)) }
            }
        }
        openGoogleMaps()
        finish()
    }

    override fun onDestroy() {
        if (cancellationReceiverRegistered) unregisterReceiver(cancellationReceiver)
        io.shutdown()
        super.onDestroy()
    }
}
