package nl.kvt.soteria

import android.content.Intent
import android.os.Bundle
import android.widget.ArrayAdapter
import android.widget.Toast
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import nl.kvt.soteria.databinding.ActivityCallerAlertBinding
import org.json.JSONObject
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledFuture
import java.util.concurrent.TimeUnit
import kotlin.math.roundToInt

class CallerAlertActivity : AppCompatActivity() {
    companion object {
        const val EXTRA_ALERT_ID = "alert_id"
    }

    private lateinit var binding: ActivityCallerAlertBinding
    private val executor = Executors.newSingleThreadScheduledExecutor()
    private var pollTask: ScheduledFuture<*>? = null
    private var alertId = 0
    private var closing = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        alertId = intent.getIntExtra(EXTRA_ALERT_ID, 0)
        if (alertId <= 0) {
            finish()
            return
        }
        binding = ActivityCallerAlertBinding.inflate(layoutInflater)
        setContentView(binding.root)

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                Toast.makeText(
                    this@CallerAlertActivity,
                    R.string.cancel_call_first,
                    Toast.LENGTH_SHORT
                ).show()
            }
        })
        binding.cancelButton.setOnClickListener { confirmCancellation() }
    }

    override fun onStart() {
        super.onStart()
        if (pollTask == null) {
            pollTask = executor.scheduleWithFixedDelay(
                { loadStatus() },
                0,
                3,
                TimeUnit.SECONDS
            )
        }
    }

    override fun onStop() {
        pollTask?.cancel(false)
        pollTask = null
        super.onStop()
    }

    private fun loadStatus() {
        val json = runCatching {
            ApiClient.post("alert_status", mapOf("alert_id" to alertId))
        }.getOrNull() ?: return
        val alert = json.optJSONObject("alert") ?: return
        if (!alert.optBoolean("active")) {
            val expired = alert.has("expired_at") && !alert.isNull("expired_at")
            runOnUiThread {
                if (closing || isFinishing || isDestroyed) return@runOnUiThread
                Toast.makeText(
                    this,
                    if (expired) R.string.alert_expired else R.string.call_cancelled,
                    Toast.LENGTH_LONG
                ).show()
                closeAfterCancellation()
            }
            return
        }
        runOnUiThread {
            if (!isFinishing && !isDestroyed) render(alert)
        }
    }

    private fun render(alert: JSONObject) {
        val type = alert.optString("type")
        val destination = alert.optString("dest_name")
        binding.destination.text = if (type == "assembly") {
            getString(R.string.calling_to_assembly, destination)
        } else {
            getString(R.string.calling_to_caller_location)
        }

        val recipients = alert.optJSONArray("recipients")
        var acknowledged = 0
        var deliveredOnly = 0
        var notDelivered = 0
        val labels = mutableListOf<String>()
        if (recipients != null) {
            for (index in 0 until recipients.length()) {
                val person = recipients.getJSONObject(index)
                val didAcknowledge = person.optBoolean("responded")
                val didDeliver = person.optBoolean("delivered")
                when {
                    didAcknowledge -> acknowledged++
                    didDeliver -> deliveredOnly++
                    else -> notDelivered++
                }
                val distance = if (person.isNull("distance_meters")) {
                    getString(R.string.distance_unknown)
                } else {
                    formatDistance(person.optDouble("distance_meters"))
                }
                val status = when {
                    didAcknowledge -> getString(R.string.status_acknowledged)
                    didDeliver -> getString(R.string.status_delivered)
                    else -> getString(R.string.status_not_delivered)
                }
                labels += "${person.optString("name")}\n$status · $distance"
            }
        }
        binding.summary.text = getString(
            R.string.response_summary,
            acknowledged,
            deliveredOnly,
            notDelivered
        )
        binding.responderList.adapter = ArrayAdapter(
            this,
            android.R.layout.simple_list_item_1,
            labels
        )
    }

    private fun formatDistance(meters: Double): String {
        return if (meters < 1000) {
            getString(R.string.distance_meters, meters.roundToInt())
        } else {
            getString(R.string.distance_kilometers, meters / 1000.0)
        }
    }

    private fun confirmCancellation() {
        AlertDialog.Builder(this)
            .setTitle(R.string.cancel_call)
            .setMessage(R.string.cancel_call_confirmation)
            .setPositiveButton(R.string.cancel_call_confirm) { _, _ -> cancelAlert() }
            .setNegativeButton(R.string.keep_calling, null)
            .show()
    }

    private fun cancelAlert() {
        closing = true
        binding.cancelButton.isEnabled = false
        executor.execute {
            val json = runCatching {
                ApiClient.post("cancel_alert", mapOf("alert_id" to alertId))
            }.getOrNull()
            runOnUiThread {
                if (isFinishing || isDestroyed) return@runOnUiThread
                if (json?.optBoolean("ok") == true) {
                    Toast.makeText(this, R.string.call_cancelled, Toast.LENGTH_LONG).show()
                    closeAfterCancellation()
                } else {
                    closing = false
                    binding.cancelButton.isEnabled = true
                    Toast.makeText(
                        this,
                        json?.optString("error").orEmpty().ifBlank {
                            getString(R.string.cancel_call_failed)
                        },
                        Toast.LENGTH_LONG
                    ).show()
                }
            }
        }
    }

    private fun closeAfterCancellation() {
        if (isFinishing) return
        closing = true
        startActivity(
            Intent(this, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        )
        finish()
    }

    override fun onDestroy() {
        pollTask?.cancel(true)
        executor.shutdownNow()
        super.onDestroy()
    }
}
