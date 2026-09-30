import Foundation

enum SoteriaRole: String, CaseIterable, Identifiable {
    case ploegleider
    case bhv
    case ehbo
    case ontruimer

    var id: String { rawValue }

    static let callOrder: [SoteriaRole] = [.bhv, .ehbo, .ploegleider, .ontruimer]

    var label: String {
        switch self {
        case .ploegleider: return "Ploegleider"
        case .bhv: return "BHV"
        case .ehbo: return "EHBO"
        case .ontruimer: return "Ontruimer"
        }
    }

    var callTitle: String {
        switch self {
        case .ploegleider: return "Oproepen ploegleiders"
        case .bhv: return "Oproepen BHV"
        case .ehbo: return "Oproepen EHBO"
        case .ontruimer: return "Oproepen ontruimers"
        }
    }

    var confirmMessage: String {
        switch self {
        case .bhv:
            return "Aanwezige BHV, EHBO, ontruimers en ploegleiders worden opgeroepen naar het dichtstbijzijnde verzamelpunt. EHBO telt ook als BHV."
        case .ehbo:
            return "Aanwezige EHBO en ploegleiders worden opgeroepen naar het dichtstbijzijnde verzamelpunt."
        case .ploegleider:
            return "Alleen aanwezige ploegleiders worden opgeroepen naar het dichtstbijzijnde verzamelpunt."
        case .ontruimer:
            return "Aanwezige ontruimers en ploegleiders worden opgeroepen naar het dichtstbijzijnde verzamelpunt."
        }
    }
}

struct SoteriaPlace: Identifiable, Equatable {
    var id: Int
    var name: String
}

struct SoteriaPerson: Identifiable {
    var id: String { email }
    var email: String
    var name: String
    var roleLabels: [String]
}

struct SoteriaLocation: Identifiable {
    var id: Int
    var name: String
    var presentCount: Int
    var roleSummary: String
    var people: [SoteriaPerson]
}

struct SoteriaCallerAlert {
    var id: Int
    var destName: String
    var subLocationId: Int?
    var subLocationName: String
    var places: [SoteriaPlace]
    var recipients: [SoteriaPerson]
}
