import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// "মনে রাখুন" on the login screen — the login ID only, never the password.
///
/// <p>The session itself already survives a restart (the refresh token in
/// [TokenStorage]); what a person retypes after signing out is their ID.
/// Kept under its own key, outside [SessionRepository], because signing out
/// clears that one — and remembering the ID across a sign-out is the whole
/// point of the box.
class RememberedLogin {
  RememberedLogin._();

  static final RememberedLogin instance = RememberedLogin._();

  static const _key = 'abos_remembered_login_id';

  final FlutterSecureStorage _storage = const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  /// The remembered ID, or null. A keystore that cannot be read is treated
  /// as "nothing remembered" — never a reason to fail the login screen.
  Future<String?> read() async {
    try {
      final value = await _storage.read(key: _key);
      return (value == null || value.isEmpty) ? null : value;
    } catch (_) {
      return null;
    }
  }

  /// Saves [identifier], or forgets it when null.
  Future<void> write(String? identifier) async {
    try {
      if (identifier == null || identifier.isEmpty) {
        await _storage.delete(key: _key);
      } else {
        await _storage.write(key: _key, value: identifier);
      }
    } catch (_) {
      // The worst case is retyping an ID next time.
    }
  }
}
