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
}

/// The parts of `GET /me` that are not the menu — see [MeApi].
class SessionProfile {
  const SessionProfile({
    required this.company,
    required this.branch,
    required this.locale,
  });

  final OrgRef company;
  final OrgRef branch;
  final String locale;

  factory SessionProfile.fromJson(Map<String, dynamic> json) => SessionProfile(
        company: OrgRef.fromJson(json['company'] as Map<String, dynamic>?),
        branch: OrgRef.fromJson(json['branch'] as Map<String, dynamic>?),
        locale: (json['user'] as Map?)?['locale'] as String? ?? 'bn',
      );
}
