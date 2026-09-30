import Foundation

enum ApiClientError: LocalizedError {
    case invalidURL
    case message(String)

    var errorDescription: String? {
        switch self {
        case .invalidURL:
            return "Ongeldige server-URL."
        case .message(let text):
            return text
        }
    }
}

struct ApiClient {
    var baseURL: String
    var token: String

    func post(action: String, extra: [String: Any] = [:]) async throws -> [String: Any] {
        let trimmed = baseURL.trimmingCharacters(in: .whitespacesAndNewlines).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let url = URL(string: trimmed + "/api.php") else {
            throw ApiClientError.invalidURL
        }
        var payload: [String: Any] = ["action": action, "token": token]
        extra.forEach { payload[$0.key] = $0.value }
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.httpBody = try JSONSerialization.data(withJSONObject: payload)
        let (data, response) = try await URLSession.shared.data(for: request)
        let code = (response as? HTTPURLResponse)?.statusCode ?? 0
        let json = (try? JSONSerialization.jsonObject(with: data) as? [String: Any]) ?? [:]
        if !(200...299).contains(code) {
            let error = (json["error"] as? String)?.trimmingCharacters(in: .whitespacesAndNewlines)
            throw ApiClientError.message(error?.isEmpty == false ? error! : "Serverantwoord \(code).")
        }
        if json["ok"] as? Bool != true {
            let error = (json["error"] as? String)?.trimmingCharacters(in: .whitespacesAndNewlines)
            throw ApiClientError.message(error?.isEmpty == false ? error! : "Serverantwoord \(code).")
        }
        return json
    }

    func locations() async throws -> [SoteriaLocation] {
        let json = try await post(action: "locations")
        let rows = json["locations"] as? [[String: Any]] ?? []
        return rows.map(Self.location)
    }

    private static func intValue(_ value: Any?) -> Int {
        if let number = value as? NSNumber {
            return number.intValue
        }
        return 0
    }

    func createAlert(type: SoteriaRole) async throws -> Int {
        let json = try await post(action: "create_alert", extra: ["type": type.rawValue])
        return intValue(json["alert_id"])
    }

    func alertStatus(id: Int) async throws -> SoteriaCallerAlert {
        let json = try await post(action: "alert_status", extra: ["alert_id": id])
        let alert = json["alert"] as? [String: Any] ?? [:]
        return Self.callerAlert(alert)
    }

    func updateLocation(alertId: Int, placeId: Int) async throws -> SoteriaCallerAlert {
        let json = try await post(
            action: "update_alert_location",
            extra: ["alert_id": alertId, "sub_location_id": placeId]
        )
        let alert = json["alert"] as? [String: Any] ?? [:]
        return Self.callerAlert(alert)
    }

    func cancelAlert(id: Int) async throws {
        _ = try await post(action: "cancel_alert", extra: ["alert_id": id])
    }

    private static func location(_ row: [String: Any]) -> SoteriaLocation {
        let people = (row["people"] as? [[String: Any]] ?? []).map(person)
        return SoteriaLocation(
            id: intValue(row["id"]),
            name: row["name"] as? String ?? "",
            presentCount: intValue(row["present_count"]),
            roleSummary: row["role_summary"] as? String ?? "",
            people: people
        )
    }

    private static func callerAlert(_ row: [String: Any]) -> SoteriaCallerAlert {
        let places = (row["places"] as? [[String: Any]] ?? []).compactMap { place -> SoteriaPlace? in
            let id = intValue(place["id"])
            guard id > 0 else { return nil }
            return SoteriaPlace(id: id, name: place["name"] as? String ?? "")
        }
        let recipients = (row["recipients"] as? [[String: Any]] ?? []).map(person)
        return SoteriaCallerAlert(
            id: intValue(row["id"]),
            destName: row["dest_name"] as? String ?? "",
            subLocationId: {
                let raw = row["sub_location_id"]
                if raw == nil || raw is NSNull { return nil }
                let id = intValue(raw)
                return id > 0 ? id : nil
            }(),
            subLocationName: row["sub_location_name"] as? String ?? "",
            places: places,
            recipients: recipients
        )
    }

    private static func person(_ row: [String: Any]) -> SoteriaPerson {
        SoteriaPerson(
            email: row["email"] as? String ?? "",
            name: row["name"] as? String ?? "",
            roleLabels: row["role_labels"] as? [String] ?? []
        )
    }
}
