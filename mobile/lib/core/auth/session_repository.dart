import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'auth_user.dart';

/// Persists the signed-in person's profile (name, roles, permissions) across
/// app restarts.
///
/// Kept separate from [TokenStorage]: that file holds only the two tokens and
/// the device id, by design — see its own doc comment. There is no `/me`
/// endpoint yet (docs/Contract, §৪ — "যা এখনো নেই"), so the only moment this
/// app ever learns a person's roles and permissions is the `/auth/login`
/// response itself. Without saving it somewhere, a restart would have a valid
/// refresh token but no way to build a role-based menu until the next login —
/// so the profile is written here, next to the tokens, the moment login
/// succeeds.
class SessionRepository {
  SessionRepository._();

  static final SessionRepository instance = SessionRepository._();

  static const _userKey = 'abos_session_user';
  static const _orgKey = 'abos_session_org';

  final FlutterSecureStorage _storage = const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  Future<void> saveUser(AuthUser user) async {
    await _storage.write(key: _userKey, value: jsonEncode(user.toJson()));
  }

  Future<AuthUser?> readUser() async {
    try {
      final raw = await _storage.read(key: _userKey);
      if (raw == null || raw.isEmpty) return null;
      return AuthUser.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      // Corrupt or foreign value under this key — treated as "no saved
      // profile", same as a fresh install, rather than crashing startup.
      return null;
    }
  }

  /// Which company and branch this session is inside, as `GET /me` last said
  /// — kept so the home header can still name them on a phone with no
  /// signal. docs/Contract §৮ rule খ: figures nobody can tell the company of
  /// are figures somebody acts on, and the header is where that is read.
  Future<void> saveOrg(OrgSnapshot org) async {
    try {
      await _storage.write(key: _orgKey, value: jsonEncode(org.toJson()));
    } catch (_) {
      // A header that falls back to the person's name is the worst case
      // here; it is not worth failing a sign-in over.
    }
  }

  Future<OrgSnapshot?> readOrg() async {
    try {
      final raw = await _storage.read(key: _orgKey);
      if (raw == null || raw.isEmpty) return null;
      return OrgSnapshot.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  Future<void> clear() async {
    await _storage.delete(key: _userKey);
    try {
      await _storage.delete(key: _orgKey);
    } catch (_) {
      // Nothing to do — the next sign-in overwrites it anyway.
    }
  }
}

/// The two names the home header shows. Names only: never an id, and never
/// used as a cache key — that stays `public_id`-based, see [AuthUser].
class OrgSnapshot {
  const OrgSnapshot({required this.company, required this.branch, this.phoneModules});

  final String company;
  final String branch;

  /// The modules `/me` last said are on for the phone — kept so a widget
  /// tap on a phone with no signal still meets the same switch
  /// ([ModuleGate]). Null when never heard.
  final Set<String>? phoneModules;

  bool get isEmpty => company.isEmpty && branch.isEmpty;

  factory OrgSnapshot.fromJson(Map<String, dynamic> json) {
    final modules = json['phoneModules'];
    return OrgSnapshot(
      company: json['company']?.toString() ?? '',
      branch: json['branch']?.toString() ?? '',
      phoneModules:
          modules is List ? modules.map((e) => e.toString()).toSet() : null,
    );
  }

  Map<String, dynamic> toJson() => {
        'company': company,
        'branch': branch,
        if (phoneModules != null) 'phoneModules': phoneModules!.toList()..sort(),
      };
}
