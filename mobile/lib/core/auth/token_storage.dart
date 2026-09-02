import 'dart:convert';
import 'dart:math';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Tokens and the device identity, in the platform keystore.
///
/// Deliberately NOT Hive/SharedPreferences: those are plain files inside the
/// app sandbox, readable on a rooted device and often included in device
/// backups. flutter_secure_storage maps to Android EncryptedSharedPreferences
/// (hardware-backed keystore) and the iOS Keychain.
///
/// The access token is cached in memory as well, so the hot path (an
/// Authorization header on every request) does not cross a platform channel
/// for every call — see [accessToken] / [cachedAccessToken].
class TokenStorage {
  TokenStorage._();

  /// Single instance: ApiClient's interceptor and the auth layer must read and
  /// write the same in-memory cache, or a refreshed token would be invisible
  /// to the other.
  static final TokenStorage instance = TokenStorage._();

  static const _accessTokenKey = 'abos_access_token';
  static const _refreshTokenKey = 'abos_refresh_token';
  static const _deviceIdKey = 'abos_device_id';

  final FlutterSecureStorage _storage = const FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  String? _accessTokenCache;
  String? _deviceIdCache;

  /// Non-async peek used by the request interceptor; null until [accessToken]
  /// or [saveTokens] has populated it.
  String? get cachedAccessToken => _accessTokenCache;

  Future<String?> accessToken() async {
    return _accessTokenCache ??= await _storage.read(key: _accessTokenKey);
  }

  Future<String?> refreshToken() => _storage.read(key: _refreshTokenKey);

  Future<void> saveTokens({
    required String accessToken,
    required String refreshToken,
  }) async {
    _accessTokenCache = accessToken;
    await _storage.write(key: _accessTokenKey, value: accessToken);
    await _storage.write(key: _refreshTokenKey, value: refreshToken);
  }

  Future<void> clear() async {
    _accessTokenCache = null;
    await _storage.delete(key: _accessTokenKey);
    await _storage.delete(key: _refreshTokenKey);
    // The device id deliberately survives sign-out: it identifies the handset
    // to the sync bookkeeping (the server's per-device watermark rows), not
    // the person. Clearing it would make every sign-out look like a brand new
    // device and re-pull the whole catalogue.
  }

  /// Stable per-install identifier required by every /api/v1/sync/** call
  /// (the `deviceId` parameter). Generated once on first use.
  ///
  /// Random.secure() rather than a hardware id: this needs to be unguessable
  /// and unique, not tied to the handset. A hardware id would follow the
  /// device across users and factory resets, which the sync bookkeeping does
  /// not want -- and on modern Android it is not reliably readable anyway.
  Future<String> deviceId() async {
    final cached = _deviceIdCache;
    if (cached != null) return cached;

    final existing = await _storage.read(key: _deviceIdKey);
    if (existing != null && existing.isNotEmpty) {
      return _deviceIdCache = existing;
    }

    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    final generated = base64Url.encode(bytes).replaceAll('=', '');
    await _storage.write(key: _deviceIdKey, value: generated);
    return _deviceIdCache = generated;
  }
}
