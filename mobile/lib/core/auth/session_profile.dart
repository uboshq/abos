/// A company or branch, as `GET /me` names one — just enough to show which
/// tenant this session is inside, never used as a cache key (that stays
/// `public_id`-based the way [AuthUser] already is).
class OrgRef {
  const OrgRef({required this.publicId, required this.code, required this.name});

  final String publicId;
  final String code;
  final String name;

  factory OrgRef.fromJson(Map<String, dynamic>? json) => OrgRef(
        publicId: json?['public_id']?.toString() ?? '',
        code: json?['code'] as String? ?? '',
        name: json?['name'] as String? ?? '',
      );

  /// A `companies[]` / `branches[]` list from `/me`. A row without a
  /// public_id is dropped: it could never be sent back to switch into.
  static List<OrgRef> listFrom(Object? raw) => ((raw as List?) ?? const [])
      .whereType<Map>()
      .map((e) => OrgRef.fromJson(e.cast<String, dynamic>()))
      .where((ref) => ref.publicId.isNotEmpty)
      .toList(growable: false);
}

/// The parts of `GET /me` that are not the menu — see [MeApi].
class SessionProfile {
  const SessionProfile({
    required this.company,
    required this.branch,
    required this.locale,
    this.companies = const [],
    this.branches = const [],
    this.viewAllBranches = false,
    this.phoneModules,
    this.ordersReplaceDo = false,
    this.mayCollect = false,
  });

  final OrgRef company;
  final OrgRef branch;
  final String locale;

  /// Every company this person may switch into — the server's list, built
  /// from their own memberships. Empty from a server older than 0.4.2.
  final List<OrgRef> companies;

  /// The branches of the **current** company inside this person's reach —
  /// the picker never offers one the server would refuse.
  final List<OrgRef> branches;

  /// "সব শাখা" — the web header's own switch: figures for every branch in
  /// reach rather than only the one [branch] this person works in.
  final bool viewAllBranches;

  /// Which modules this company has switched on for the phone (a Control
  /// Panel switch per module, per company). Null from a server older than
  /// the switch — then nothing is hidden on this side, and the server's own
  /// walls still hold.
  final Set<String>? phoneModules;

  /// ⭐ The company writes sales orders where it wrote DOs (`sales.orders_replace_do`; SO+DO merge, step 10,
  /// 5 Oct 2026) — the DO screens then write through `/sales/orders`. False from an older server.
  final bool ordersReplaceDo;

  /// ⭐ This person may take money on the phone — office people only (owner,
  /// 7 Oct 2026; field money goes through the deposit notice). False from an
  /// older server.
  final bool mayCollect;

  /// Whether there is anything to switch between at all — a picker with one
  /// company and one branch is a button that does nothing.
  bool get canSwitch => companies.length > 1 || branches.length > 1;

  factory SessionProfile.fromJson(Map<String, dynamic> json) {
    final modules = json['phoneModules'];
    return SessionProfile(
      company: OrgRef.fromJson(json['company'] as Map<String, dynamic>?),
      branch: OrgRef.fromJson(json['branch'] as Map<String, dynamic>?),
      locale: (json['user'] as Map?)?['locale'] as String? ?? 'bn',
      companies: OrgRef.listFrom(json['companies']),
      branches: OrgRef.listFrom(json['branches']),
      viewAllBranches: json['viewAllBranches'] == true,
      phoneModules:
          modules is List ? modules.map((e) => e.toString()).toSet() : null,
      ordersReplaceDo: json['ordersReplaceDo'] == true,
      mayCollect: json['mayCollect'] == true,
    );
  }
}
