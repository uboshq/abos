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

  Future<void> clear() async {
    await _storage.delete(key: _userKey);
  }
}
