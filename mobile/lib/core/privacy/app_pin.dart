import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// ⭐ অ্যাপের নিজের PIN — ফোনে তালা না থাকলে (পূর্ণ নিরীক্ষা §৯ ⚠️১২, ১৮ অক্টোবর ২০২৬; OWASP MASVS-AUTH)।
///
/// <p>⛔ আগে ফোনে PIN/আঙুল না থাকলে অ্যাপ-তালা কাজই করত না: অ্যাপ সোজা খুলত, কেবল একটা সতর্কবার্তা। খোলা ফোন হাতে পেলে যে কেউ
/// মালিকের অনুমোদন-বাক্সে সই দিতে পারতেন।
///
/// <p>⭐ ৪–৬ অঙ্ক। রাখা হয় কেবল লবণ-মেশানো PBKDF2-HMAC-SHA256 (২০,০০০ পাক), নিরাপদ ভাণ্ডারে — PIN নিজে কোথাও নয়।
/// ⓘ বেরোলে মোছে ([[clear]]) — পরের জন নিজের PIN বসান।
class AppPin {
  AppPin({
    Future<String?> Function(String key)? read,
    Future<void> Function(String key, String? value)? write,
    DateTime Function()? clock,
  })  : _read = read ?? _secureRead,
        _write = write ?? _secureWrite,
        _clock = clock ?? DateTime.now;

  final DateTime Function() _clock;

  static const String _key = 'abos_app_pin_v1';
  static const String _triesKey = 'abos_app_pin_tries';
  static const int iterations = 20000;

  static const String _lastWrongKey = 'abos_app_pin_last_wrong';

  /// ভুলের সীমা — এর পরে অপেক্ষা ([waitAfter]); ⛔ আর বের করে দেওয়া নয় (মালিক, ২০ নভেম্বর ২০২৬: "লগআউট কেবল নিজে বের হলে, ফোন
  /// বাদ দিলে, পাসওয়ার্ড বদলালে, প্রশাসক বন্ধ করলে")
  static const int maxTries = 5;

  /// ⭐ [maxTries]-এর পরে প্রতিটা ভুলে অপেক্ষা দ্বিগুণ — ৩০ সেকেন্ড, ১, ২, ৪ … মিনিট, সর্বোচ্চ এক ঘণ্টা (iOS/Android তালার নিয়মের মতো;
  /// ৪ অঙ্কের PIN আন্দাজে খুলতে তখন মাসের পর মাস)। [maxTries]-এর আগে শূন্য।
  static Duration waitAfter(int tries) {
    if (tries < maxTries) return Duration.zero;
    final seconds = 30 * (1 << (tries - maxTries).clamp(0, 7));
    return Duration(seconds: seconds > 3600 ? 3600 : seconds);
  }

  /// শেষ ভুলের সময় — অপেক্ষা গোনার জন্য
  Future<DateTime?> lastWrongAt() async {
    final ms = int.tryParse(await _read(_lastWrongKey) ?? '');
    return ms == null ? null : DateTime.fromMillisecondsSinceEpoch(ms);
  }

  final Future<String?> Function(String key) _read;
  final Future<void> Function(String key, String? value) _write;

  static const FlutterSecureStorage _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  static Future<String?> _secureRead(String key) => _storage.read(key: key);

  static Future<void> _secureWrite(String key, String? value) =>
      value == null ? _storage.delete(key: key) : _storage.write(key: key, value: value);

  /// ৪–৬টা অঙ্ক — বাংলা অঙ্কও চলে
  static String? normalise(String typed) {
    const bangla = '০১২৩৪৫৬৭৮৯'; // l10n-ok: reads Bengali digits typed into the PIN, not text
    final digits = typed.trim().split('').map((c) => bangla.contains(c) ? '${bangla.indexOf(c)}' : c).join();
    return RegExp(r'^\d{4,6}$').hasMatch(digits) ? digits : null;
  }

  Future<bool> isSet() async => (await _read(_key)) != null;

  /// নতুন PIN — ভুল আকারে `false`
  Future<bool> set(String typed) async {
    final pin = normalise(typed);
    if (pin == null) return false;
    final salt = Uint8List.fromList(List<int>.generate(16, (_) => Random.secure().nextInt(256)));
    await _write(_key, 'v1:${base64Encode(salt)}:$iterations:${base64Encode(_derive(pin, salt, iterations))}');
    await _write(_triesKey, null);
    await _write(_lastWrongKey, null);
    return true;
  }

  /// মিলল কি না; না মিললে ভুলের গোনা বাড়ে
  Future<bool> check(String typed) async {
    final stored = await _read(_key);
    final pin = normalise(typed);
    if (stored == null) return false;
    if (pin == null) return _wrong();
    final parts = stored.split(':');
    if (parts.length != 4 || parts[0] != 'v1') return _wrong();
    final derived = _derive(pin, base64Decode(parts[1]), int.parse(parts[2]));
    if (_same(derived, base64Decode(parts[3]))) {
      await _write(_triesKey, null);
      await _write(_lastWrongKey, null);
      return true;
    }
    return _wrong();
  }

  /// এ পর্যন্ত ভুল কয়বার
  Future<int> tries() async => int.tryParse(await _read(_triesKey) ?? '') ?? 0;

  Future<void> clear() async {
    await _write(_key, null);
    await _write(_triesKey, null);
    await _write(_lastWrongKey, null);
  }

  Future<bool> _wrong() async {
    await _write(_triesKey, '${await tries() + 1}');
    await _write(_lastWrongKey, '${_clock().millisecondsSinceEpoch}');
    return false;
  }

  static Uint8List _derive(String pin, List<int> salt, int rounds) {
    // PBKDF2-HMAC-SHA256, এক ব্লক (৩২ বাইট)
    final hmac = Hmac(sha256, utf8.encode(pin));
    var u = hmac.convert([...salt, 0, 0, 0, 1]).bytes;
    final out = Uint8List.fromList(u);
    for (var i = 1; i < rounds; i++) {
      u = hmac.convert(u).bytes;
      for (var j = 0; j < out.length; j++) {
        out[j] ^= u[j];
      }
    }
    return out;
  }

  static bool _same(List<int> a, List<int> b) {
    if (a.length != b.length) return false;
    var diff = 0;
    for (var i = 0; i < a.length; i++) {
      diff |= a[i] ^ b[i];
    }
    return diff == 0;
  }
}
