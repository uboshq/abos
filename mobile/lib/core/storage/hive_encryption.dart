import 'dart:convert';
import 'dart:math';

import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:hive/hive.dart';

/// Every Hive box in this app, encrypted at rest with one AES-256 key kept in
/// the platform keystore -- the same door TokenStorage keeps the auth tokens
/// behind, rather than the plaintext file inside the app sandbox that an
/// un-encrypted Hive box otherwise is. `hive_flutter` already ships
/// `HiveAesCipher`; the only piece missing was somewhere for the key itself to
/// live that survives an app restart but never leaves the device.
///
/// This matters more here than it looks. The offline queue holds a rep's
/// not-yet-pushed sale or delivery -- customer, quantities, price -- sitting on
/// a phone that gets lost, sold, or handed to a repair shop.
///
/// Same generate-once-then-persist shape as TokenStorage.deviceId(): a random
/// key exists the first time anything asks, is written to the keystore, and
/// every later ask reads the same one back.
/// চাবিটা এই মুহূর্তে পড়া গেল না — বাক্স মোছার কারণ নয় ([[HiveEncryptionKey]])
class HiveKeyUnavailable implements Exception {
  const HiveKeyUnavailable(this.cause);

  final Object cause;

  @override
  String toString() => 'HiveKeyUnavailable($cause)';
}

class HiveEncryptionKey {
  const HiveEncryptionKey._();

  static const _storageKey = 'abos_hive_aes_key';

  static const FlutterSecureStorage _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  static HiveAesCipher? _cached;

  static Future<HiveAesCipher> cipher() async {
    final cached = _cached;
    if (cached != null) return cached;
    return _cached = HiveAesCipher(await _keyBytes());
  }

  static Future<List<int>> _keyBytes() async {
    String? existing;
    try {
      existing = await _storage.read(key: _storageKey);
    } on MissingPluginException {
      // No secure-storage platform available at all -- treated the same as
      // "never written", below.
      existing = null;
    } catch (error) {
      // ⛔ পড়া ব্যর্থ (কিস্টোর সাময়িক বন্ধ, পটভূমির isolate) মানে "চাবি নেই" নয় — আগে নতুন চাবি বানিয়ে পুরনোটা মুছে দিত, আর
      // অফলাইন সারির বাক্স আর খোলা যেত না, মুছে যেত: না-পাঠানো সব অর্ডার, কোনো বার্তা ছাড়া (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)।
      throw HiveKeyUnavailable(error);
    }

    if (existing != null && existing.isNotEmpty) {
      try {
        final decoded = base64Decode(existing);
        if (decoded.length == 32) return decoded;
      } catch (_) {
        // Not a key this file ever wrote -- a stale or foreign value sitting
        // under the same storage key. Falls through to generating a real one
        // rather than handing HiveAesCipher bytes it cannot use.
      }
    }

    // HiveAesCipher expects exactly 32 bytes -- AES-256, not the 16-byte
    // (AES-128) key some examples use.
    final random = Random.secure();
    final generated = List<int>.generate(32, (_) => random.nextInt(256));
    try {
      await _storage.write(key: _storageKey, value: base64Encode(generated));
    } catch (_) {
      // Could not persist (no secure-storage platform) -- the key still works
      // for the rest of this run, it just will not survive a restart.
    }
    return generated;
  }

  /// Opens [name] encrypted with this app's shared key, migrating losslessly
  /// from an existing unencrypted box instead of deleting it outright.
  ///
  /// The migration path exists for the day encryption is added to a build that
  /// already shipped without it. The sync queue box in particular can hold a
  /// rep's own not-yet-pushed sale; a wipe-and-restart migration would lose
  /// that for good rather than just resetting a preference.
  ///
  /// Deliberately does NOT detect "already encrypted" by attempting an
  /// encrypted open and catching the failure: Hive 2.2.3's own `_openingBoxes`
  /// completer re-surfaces that failure a second time, as a second unhandled
  /// async error, even after a try/catch here has already handled it --
  /// harmless noise in production, a guaranteed test failure under
  /// flutter_test's zone. A small persisted "already migrated" flag per box
  /// name sidesteps ever calling the encrypted open on a box this file has not
  /// itself already put there.
  static Future<Box<T>> openBox<T>(String name) async {
    final cipher = await HiveEncryptionKey.cipher();

    if (await _isMigrated(name)) {
      return Hive.openBox<T>(name, encryptionCipher: cipher);
    }

    // First time this box is opened since encryption was added -- read
    // whatever is already on disk (a fresh install has nothing; an upgrading
    // install may have real, previously-plaintext data) before switching the
    // file over, then remember not to repeat this.
    Map<dynamic, T> recovered = const {};
    try {
      final plain = await Hive.openBox<T>(name);
      recovered = Map<dynamic, T>.from(plain.toMap());
      await plain.close();
    } catch (_) {
      // Unreadable even as plaintext -- nothing to carry forward, a fresh
      // encrypted box is still the right outcome.
    }

    await Hive.deleteBoxFromDisk(name);
    final box = await Hive.openBox<T>(name, encryptionCipher: cipher);
    if (recovered.isNotEmpty) {
      await box.putAll(recovered);
    }

    await _markMigrated(name);
    return box;
  }

  static Future<bool> _isMigrated(String name) async {
    try {
      return await _storage.read(key: _migratedKey(name)) == 'true';
    } catch (_) {
      return false;
    }
  }

  static Future<void> _markMigrated(String name) async {
    try {
      await _storage.write(key: _migratedKey(name), value: 'true');
    } catch (_) {
      // Could not persist -- next launch just repeats this same, idempotent
      // recovery-then-reopen rather than wrongly assuming the box on disk is
      // already encrypted.
    }
  }

  static String _migratedKey(String name) => 'abos_hive_migrated_$name';
}
