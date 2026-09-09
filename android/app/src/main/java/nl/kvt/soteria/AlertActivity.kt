package nl.kvt.soteria

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.View
import android.view.WindowManager
import androidx.appcompat.app.AppCompatActivity
import nl.kvt.soteria.databinding.ActivityAlertBinding
import org.osmdroid.tileprovider.tilesource.TileSourceFactory
import org.osmdroid.util.BoundingBox
import org.osmdroid.util.GeoPoint
import org.osmdroid.views.overlay.Marker
import org.osmdroid.views.overlay.mylocation.GpsMyLocationProvider
import org.osmdroid.views.overlay.mylocation.MyLocationNewOverlay
import java.util.concurrent.Executors

class AlertActivity : AppCompatActivity() {
    private lateinit var binding: ActivityAlertBinding
    private val io = Executors.newSingleThreadExecutor()
    private var revealed = false
    private var destLat = 0.0
    private var destLng = 0.0
    private var destName = ""
    private var locationOverlay: MyLocationNewOverlay? = null

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
        destName = intent.getStringExtra("dest_name") ?: alert?.destName ?: ""
        destLat = intent.getDoubleExtra("dest_lat", alert?.destLat ?: 0.0)
        destLng = intent.getDoubleExtra("dest_lng", alert?.destLng ?: 0.0)

        binding.incomingTitle.text = "BHV-OPROEP"
        binding.message.text = intent.getStringExtra("message") ?: alert?.message ?: "BHV-oproep"
        binding.detailPanel.visibility = View.GONE
        binding.answerButton.setOnClickListener { reveal() }
        binding.openMapsButton.setOnClickListener { openGoogleMaps() }

        setupMap()
    }

    override fun onResume() {
        super.onResume()
        binding.map.onResume()
        locationOverlay?.enableMyLocation()
    }

    override fun onPause() {
        locationOverlay?.disableMyLocation()
        binding.map.onPause()
        super.onPause()
    }

    private fun setupMap() {
        binding.map.setTileSource(TileSourceFactory.MAPNIK)
        binding.map.setMultiTouchControls(true)
        binding.map.controller.setZoom(16.0)
        val dest = GeoPoint(destLat, destLng)
        binding.map.controller.setCenter(dest)

        val marker = Marker(binding.map).apply {
            position = dest
            title = destName.ifBlank { "Bestemming" }
            setAnchor(Marker.ANCHOR_CENTER, Marker.ANCHOR_BOTTOM)
        }
        binding.map.overlays.add(marker)

        val overlay = MyLocationNewOverlay(GpsMyLocationProvider(this), binding.map)
        overlay.enableMyLocation()
        overlay.runOnFirstFix {
            val mine = overlay.myLocation ?: return@runOnFirstFix
            runOnUiThread {
                val box = BoundingBox.fromGeoPoints(listOf(dest, mine))
                binding.map.zoomToBoundingBox(box, true, 96)
            }
        }
        binding.map.overlays.add(overlay)
        locationOverlay = overlay
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
        runCatching { startActivity(nav) }.onFailure {
            startActivity(web)
        }
    }

    private fun reveal() {
        if (revealed) return
        revealed = true
        Ringer.stop(this)
        Ringer.stopVibration(this)
        binding.incomingPanel.visibility = View.GONE
        binding.detailPanel.visibility = View.VISIBLE
        binding.map.onResume()
        binding.map.invalidate()
        val alertId = intent.getIntExtra("alert_id", Ringer.activeAlert?.id ?: 0)
        if (alertId > 0) {
            io.execute {
                runCatching { ApiClient.post("ack_alert", mapOf("alert_id" to alertId)) }
            }
        }
    }
}
