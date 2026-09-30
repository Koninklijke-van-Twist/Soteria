package nl.kvt.soteria

data class Person(
    val email: String,
    val name: String,
    val roles: List<String> = emptyList(),
    val roleLabels: List<String> = emptyList()
)

data class RoleCounts(
    val ploegleider: Int = 0,
    val bhv: Int = 0,
    val ehbo: Int = 0,
    val ontruimer: Int = 0
)

data class Place(
    val id: Int,
    val name: String
)

data class LocationPresence(
    val id: Int,
    val name: String,
    val lat: Double,
    val lng: Double,
    val presentCount: Int,
    val byRole: RoleCounts = RoleCounts(),
    val roleSummary: String = "",
    val people: List<Person> = emptyList(),
    val places: List<Place> = emptyList()
)

data class PendingAlert(
    val id: Int,
    val callerName: String,
    val type: String,
    val destName: String,
    val destLat: Double,
    val destLng: Double,
    val subLocationName: String = "",
    val message: String
)

data class ActiveAcknowledgedAlert(
    val id: Int,
    val callerName: String,
    val type: String,
    val destName: String,
    val destLat: Double,
    val destLng: Double,
    val subLocationName: String = ""
)
