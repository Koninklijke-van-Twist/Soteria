package nl.kvt.soteria

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.AdapterView
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
    private var places: List<Place> = emptyList()
    private var selectedPlaceId = 0
    private var ignoreSpinner = true
    private var placesKey = ""

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
        binding.locationPicker.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
            override fun onItemSelected(parent: AdapterView<*>?, view: View?, position: Int, id: Long) {
                if (ignoreSpinner) return
                val place = places.getOrNull(position - 1) ?: return
                if (place.id == selectedPlaceId) return
                updateLocation(place.id)
            }

            override fun onNothingSelected(parent: AdapterView<*>?) = Unit
        }
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
        val subLocation = alert.optString("sub_location_name")
        binding.destination.text = when (type) {
            "caller" -> getString(R.string.calling_to_caller_location)
            else -> if (subLocation.isBlank()) {
                getString(R.string.calling_to_assembly, destination)
            } else {
                getString(R.string.calling_to_assembly_place, destination, subLocation)
            }
        }
        renderPlaces(alert)

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
                val roleLabels = person.optJSONArray("role_labels")
                val roleText = if (roleLabels == null || roleLabels.length() == 0) {
                    ""
                } else {
                    buildString {
                        for (roleIndex in 0 until roleLabels.length()) {
                            val label = roleLabels.optString(roleIndex)
                            if (label.isBlank()) continue
                            if (isNotEmpty()) append(", ")
                            append(label)
                        }
                    }
                }
                val nameLine = if (roleText.isBlank()) {
                    person.optString("name")
                } else {
                    "${person.optString("name")} · $roleText"
                }
                labels += "$nameLine\n$status · $distance"
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

    private fun renderPlaces(alert: JSONObject) {
        val parsed = mutableListOf<Place>()
        val array = alert.optJSONArray("places")
        if (array != null) {
            for (index in 0 until array.length()) {
                val place = array.getJSONObject(index)
                val id = place.optInt("id")
                if (id > 0) parsed += Place(id = id, name = place.optString("name"))
            }
        }
        val placeId = if (alert.isNull("sub_location_id")) 0 else alert.optInt("sub_location_id")
        val key = parsed.joinToString(",") { "${it.id}:${it.name}" }
        places = parsed
        if (parsed.isEmpty()) {
            binding.locationPicker.visibility = View.GONE
            binding.locationHint.visibility = View.VISIBLE
            placesKey = ""
            selectedPlaceId = 0
            return
        }
        binding.locationHint.visibility = View.GONE
        binding.locationPicker.visibility = View.VISIBLE
        if (key != placesKey) {
            ignoreSpinner = true
            placesKey = key
            val labels = listOf(getString(R.string.choose_place)) + parsed.map { it.name }
            binding.locationPicker.adapter = ArrayAdapter(
                this,
                android.R.layout.simple_spinner_item,
                labels
            ).also { adapter ->
                adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item)
            }
        }
        val index = parsed.indexOfFirst { it.id == placeId }.let { found -> if (found >= 0) found + 1 else 0 }
        if (binding.locationPicker.selectedItemPosition != index) {
            ignoreSpinner = true
            binding.locationPicker.setSelection(index)
        }
        selectedPlaceId = if (placeId > 0) placeId else 0
        binding.locationPicker.post { ignoreSpinner = false }
    }

    private fun updateLocation(placeId: Int) {
        ignoreSpinner = true
        selectedPlaceId = placeId
        executor.execute {
            val json = runCatching {
                ApiClient.post(
                    "update_alert_location",
                    mapOf("alert_id" to alertId, "sub_location_id" to placeId)
                )
            }.getOrNull()
            runOnUiThread {
                if (isFinishing || isDestroyed) return@runOnUiThread
                ignoreSpinner = false
                if (json?.optBoolean("ok") == true) {
                    Toast.makeText(this, R.string.location_updated, Toast.LENGTH_SHORT).show()
                    json.optJSONObject("alert")?.let { render(it) }
                } else {
                    selectedPlaceId = 0
                    placesKey = ""
                    Toast.makeText(
                        this,
                        json?.optString("error").orEmpty().ifBlank {
                            getString(R.string.location_update_failed)
                        },
                        Toast.LENGTH_LONG
                    ).show()
                }
            }
        }
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
