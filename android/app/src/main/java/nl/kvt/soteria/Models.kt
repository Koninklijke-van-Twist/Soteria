package nl.kvt.soteria

data class Person(
    val email: String,
    val name: String
)

data class LocationPresence(
    val id: Int,
    val name: String,
    val lat: Double,
    val lng: Double,
    val presentCount: Int,
    val people: List<Person>
)

data class PendingAlert(
    val id: Int,
    val callerName: String,
    val type: String,
    val destName: String,
    val destLat: Double,
    val destLng: Double,
    val message: String
)
