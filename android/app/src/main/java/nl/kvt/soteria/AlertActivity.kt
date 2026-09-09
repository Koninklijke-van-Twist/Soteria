package nl.kvt.soteria

import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.WindowManager
import androidx.appcompat.app.AppCompatActivity
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.SupportMapFragment
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.MarkerOptions
import nl.kvt.soteria.databinding.ActivityAlertBinding
import java.util.concurrent.Executors

class AlertActivity : AppCompatActivity() {
    private lateinit var binding: ActivityAlertBinding
    private val io = Executors.newSingleThreadExecutor()
    private var revealed = false
    private var dest: LatLng? = null
    private var map: GoogleMap? = null

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
        val message = intent.getStringExtra("message") ?: alert?.message ?: "BHV-oproep"
        val destName = intent.getStringExtra("dest_name") ?: alert?.destName ?: ""
        val destLat = intent.getDoubleExtra("dest_lat", alert?.destLat ?: 0.0)
        val destLng = intent.getDoubleExtra("dest_lng", alert?.destLng ?: 0.0)
        dest = LatLng(destLat, destLng)

        binding.incomingTitle.text = "BHV-OPROEP"
        binding.message.text = message
        binding.detailPanel.visibility = View.GONE

        binding.answerButton.setOnClickListener { reveal() }

        val mapFragment = supportFragmentManager.findFragmentById(R.id.map) as? SupportMapFragment
        mapFragment?.getMapAsync { googleMap ->
            map = googleMap
            googleMap.uiSettings.isMyLocationButtonEnabled = true
            runCatching { googleMap.isMyLocationEnabled = true }
            dest?.let { point ->
                googleMap.addMarker(MarkerOptions().position(point).title(destName.ifBlank { "Bestemming" }))
                googleMap.moveCamera(CameraUpdateFactory.newLatLngZoom(point, 16f))
            }
        }
    }

    private fun reveal() {
        if (revealed) return
        revealed = true
        Ringer.stop(this)
        Ringer.stopVibration(this)
        binding.incomingPanel.visibility = View.GONE
        binding.detailPanel.visibility = View.VISIBLE
        val alertId = intent.getIntExtra("alert_id", Ringer.activeAlert?.id ?: 0)
        if (alertId > 0) {
            io.execute {
                runCatching { ApiClient.post("ack_alert", mapOf("alert_id" to alertId)) }
            }
        }
    }
}
