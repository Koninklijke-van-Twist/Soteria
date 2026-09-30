import SwiftUI

struct ContentView: View {
    @AppStorage("soteria.baseURL") private var baseURL = "https://sleutels.kvt.nl/soteria"
    @AppStorage("soteria.token") private var token = ""
    @State private var draftURL = ""
    @State private var draftToken = ""
    @State private var locations: [SoteriaLocation] = []
    @State private var errorMessage = ""
    @State private var pendingType: SoteriaRole?
    @State private var activeAlertId = 0
    @State private var callerAlert: SoteriaCallerAlert?
    @State private var isWorking = false

    private var client: ApiClient {
        ApiClient(baseURL: baseURL, token: token)
    }

    var body: some View {
        NavigationStack {
            Group {
                if token.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty {
                    loginForm
                } else {
                    callScreen
                }
            }
            .navigationTitle("Soteria")
            .toolbar {
                if !token.isEmpty {
                    Button("Uitloggen") {
                        token = ""
                        draftToken = ""
                    }
                }
            }
        }
        .onAppear {
            draftURL = baseURL
            draftToken = token
        }
        .alert("Oproep naar verzamelpunt", isPresented: Binding(
            get: { pendingType != nil },
            set: { if !$0 { pendingType = nil } }
        )) {
            Button("Oproep versturen") {
                if let type = pendingType {
                    pendingType = nil
                    Task { await send(type) }
                }
            }
            Button("Later", role: .cancel) { pendingType = nil }
        } message: {
            Text(pendingType?.confirmMessage ?? "")
        }
        .sheet(isPresented: Binding(
            get: { activeAlertId > 0 },
            set: { if !$0 { activeAlertId = 0 } }
        )) {
            CallerSheet(
                client: client,
                alertId: activeAlertId,
                alert: callerAlert,
                onChange: { callerAlert = $0 },
                onClose: { activeAlertId = 0 }
            )
        }
    }

    private var loginForm: some View {
        Form {
            Section("Server") {
                TextField("Server-URL", text: $draftURL)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                TextField("Toegangstoken", text: $draftToken)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
            }
            Section {
                Text("Entra-login volgt nog. Plak voor een test het token uit de Android-app of de API.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            }
            Button("Bewaren") {
                baseURL = draftURL.trimmingCharacters(in: .whitespacesAndNewlines)
                token = draftToken.trimmingCharacters(in: .whitespacesAndNewlines)
            }
        }
    }

    private var callScreen: some View {
        List {
            if !errorMessage.isEmpty {
                Text(errorMessage).foregroundStyle(.red)
            }
            Section("Oproep naar verzamelpunt") {
                ForEach(SoteriaRole.callOrder) { role in
                    Button(role.callTitle) { pendingType = role }
                        .disabled(isWorking)
                }
            }
            Section("Aanwezigheid per rol") {
                if locations.isEmpty {
                    Text("Nog geen verzamelpunten geladen.")
                }
                ForEach(locations) { location in
                    VStack(alignment: .leading, spacing: 4) {
                        Text("\(location.name): \(location.presentCount) aanwezig")
                            .font(.headline)
                        Text(location.roleSummary)
                            .font(.subheadline)
                            .foregroundStyle(.secondary)
                        ForEach(location.people) { person in
                            let roles = person.roleLabels.joined(separator: ", ")
                            Text(roles.isEmpty ? person.name : "\(person.name) · \(roles)")
                                .font(.footnote)
                        }
                    }
                    .padding(.vertical, 4)
                }
                Button("Vernieuwen") { Task { await refresh() } }
            }
        }
        .task { await refresh() }
    }

    private func refresh() async {
        do {
            locations = try await client.locations()
            errorMessage = ""
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func send(_ type: SoteriaRole) async {
        isWorking = true
        defer { isWorking = false }
        do {
            let id = try await client.createAlert(type: type)
            callerAlert = try? await client.alertStatus(id: id)
            activeAlertId = id
            errorMessage = ""
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}

private struct CallerSheet: View {
    var client: ApiClient
    var alertId: Int
    var alert: SoteriaCallerAlert?
    var onChange: (SoteriaCallerAlert) -> Void
    var onClose: () -> Void
    @State private var errorMessage = ""

    var body: some View {
        NavigationStack {
            List {
                if let alert {
                    Section {
                        if alert.subLocationName.isEmpty {
                            Text("Oproep naar verzamelpunt \(alert.destName)")
                        } else {
                            Text("Oproep naar verzamelpunt \(alert.destName), locatie \(alert.subLocationName)")
                        }
                    }
                    Section("Locatie binnen het verzamelpunt") {
                        if alert.places.isEmpty {
                            Text("Er zijn nog geen locaties ingesteld voor dit verzamelpunt.")
                        } else {
                            Picker("Locatie", selection: placeSelection) {
                                Text("Kies een locatie").tag(0)
                                ForEach(alert.places) { place in
                                    Text(place.name).tag(place.id)
                                }
                            }
                        }
                    }
                    Section("Opgeroepen") {
                        ForEach(alert.recipients) { person in
                            let roles = person.roleLabels.joined(separator: ", ")
                            Text(roles.isEmpty ? person.name : "\(person.name) · \(roles)")
                        }
                    }
                }
                if !errorMessage.isEmpty {
                    Text(errorMessage).foregroundStyle(.red)
                }
            }
            .navigationTitle("Actieve oproep")
            .toolbar {
                Button("Oproep annuleren", role: .destructive) {
                    Task { await cancel() }
                }
            }
            .task { await reload() }
        }
    }

    private var placeSelection: Binding<Int> {
        Binding(
            get: { alert?.subLocationId ?? 0 },
            set: { newValue in
                guard newValue > 0, newValue != (alert?.subLocationId ?? 0) else { return }
                Task { await update(placeId: newValue) }
            }
        )
    }

    private func reload() async {
        do {
            onChange(try await client.alertStatus(id: alertId))
            errorMessage = ""
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func update(placeId: Int) async {
        do {
            onChange(try await client.updateLocation(alertId: alertId, placeId: placeId))
            errorMessage = ""
        } catch {
            errorMessage = error.localizedDescription
        }
    }

    private func cancel() async {
        do {
            try await client.cancelAlert(id: alertId)
            onClose()
        } catch {
            errorMessage = error.localizedDescription
        }
    }
}
