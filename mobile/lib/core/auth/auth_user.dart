/// The signed-in person, as `/auth/login` describes them.
///
/// `permissions` is for building the menu, not for gating what a screen may
/// do — see docs/Contract — মোবাইল সিঙ্ক প্রোটোকল.md, §০: the server checks
/// every sync door itself; a permission missing from this list only means a
/// menu tile should not be offered, never that the door is actually locked by
/// its absence.
class AuthUser {
  const AuthUser({
    required this.id,
    required this.name,
    required this.email,
    required this.roles,
    required this.permissions,
  });

  final String id;
  final String name;
  final String email;
  final List<String> roles;
  final List<String> permissions;

  bool hasRole(String role) => roles.contains(role);
  bool can(String permission) => permissions.contains(permission);

  /// Reads `public_id` first — the only id `/me` ever sends (see
  /// menu_repository.dart's own doc comment) — falling back to `id` for the
  /// `/auth/login` response, which the contract document still names `id`.
  /// Never the other way around: a screen must not end up displaying an
  /// internal sequential id because one endpoint happened to omit its own
  /// name for the field.
  factory AuthUser.fromJson(Map<String, dynamic> json) => AuthUser(
        id: (json['public_id'] ?? json['id'])?.toString() ?? '',
        name: json['name'] as String? ?? '',
        email: json['email'] as String? ?? '',
        roles: ((json['roles'] as List?) ?? const [])
            .map((e) => e.toString())
            .toList(),
        permissions: ((json['permissions'] as List?) ?? const [])
            .map((e) => e.toString())
            .toList(),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'roles': roles,
        'permissions': permissions,
      };
}
