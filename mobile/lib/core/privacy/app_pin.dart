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
    Duration Function()? elapsed,
  })  : _read = read ?? _secureRead,
        _write = write ?? _secureWrite,
        _elapsed = elapsed ?? _monotonic;

  /// ⭐ একঘেয়ে-বাড়া সময় (Stopwatch) — ফোনের ঘড়ি নয়। ⛔ আগে অপেক্ষা গোনা হতো "শেষ ভুলের দেয়াল-ঘড়ির সময় + অপেক্ষা" দিয়ে:
  /// ফোনের তারিখ সামনে নিলে অপেক্ষা উধাও, পেছনে নিলে মালিক ঘণ্টার পর ঘণ্টা আটকা (রিভিউ A1, ২১ নভেম্বর ২০২৬)।
  final Duration Function() _elapsed;

  static final Stopwatch _stopwatch = Stopwatch()..start();
  static Duration _monotonic() => _stopwatch.elapsed;

  static const String _key = 'abos_app_pin_v1';
  static const String _triesKey = 'abos_app_pin_tries';
  static const int iterations = 20000;

  /// ⭐ বাকি অপেক্ষা (মিলিসেকেন্ড) — অ্যাপ চলার সময় কেবল [_elapsed] দিয়ে কমে; বন্ধ করে খুললে যেখানে ছিল সেখান থেকে চলে
  static const String _waitLeftKey = 'abos_app_pin_wait_left_ms';

  /// ⓘ পুরনো দেয়াল-ঘড়ির চাবি — কেবল মোছার জন্য
  static const String _lastWrongKey = 'abos_app_pin_last_wrong';

  /// বাকি অপেক্ষার সর্বোচ্চ — ভাণ্ডারে যা-ই থাক
  static const Duration maxWait = Duration(hours: 1);

  /// শেষবার পড়া/লেখা বাকি অপেক্ষা, আর তখনকার [_elapsed]
  Duration? _left;
  Duration _leftAt = Duration.zero;

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

  /// ⭐ এখনো কতক্ষণ PIN দেওয়া যাবে না — শেষ পড়া/লেখার পর থেকে একঘেয়ে সময় বাদ দিয়ে; নতুন বাকিটা লিখে রাখে, তাই অ্যাপ
  /// আবার খুললেও শূন্য হয় না। সর্বোচ্চ [maxWait]।
  Future<Duration> waitLeft() async {
    var left = _left;
    if (left == null) {
      final ms = int.tryParse(await _read(_waitLeftKey) ?? '') ?? 0;
      left = Duration(milliseconds: ms < 0 ? 0 : ms);
      _leftAt = _elapsed();
      // ⓘ পুরনো দেয়াল-ঘড়ির চাবি থাকলে মুছে ফেলা
      if (await _read(_lastWrongKey) != null) await _write(_lastWrongKey, null);
    }
    final now = _elapsed();
    final ran = now - _leftAt;
    left = left - (ran.isNegative ? Duration.zero : ran);
    if (left.isNegative) left = Duration.zero;
    if (left > maxWait) left = maxWait;
    await _keepLeft(left, now);
    return left;
  }

  Future<void> _keepLeft(Duration left, Duration at) async {
    final changed = _left != left;
    _left = left;
    _leftAt = at;
    if (changed) await _write(_waitLeftKey, left == Duration.zero ? null : '${left.inMilliseconds}');
  }

  Future<void> _forgetWait() async {
    _left = Duration.zero;
    _leftAt = _elapsed();
    await _write(_waitLeftKey, null);
    await _write(_lastWrongKey, null);
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
    await _forgetWait();
    return true;
  }

  /// মিলল কি না; না মিললে ভুলের গোনা বাড়ে
  Future<bool> check(String typed) async {
    final stored = await _read(_key);
    final pin = normalise(typed);
    if (stored == null) return false;
    // ⛔ অপেক্ষার মধ্যে যাচাই নয়, ভুলও গোনা নয়
    if (await waitLeft() > Duration.zero) return false;
    if (pin == null) return _wrong();
    final parts = stored.split(':');
    if (parts.length != 4 || parts[0] != 'v1') return _wrong();
    final derived = _derive(pin, base64Decode(parts[1]), int.parse(parts[2]));
    if (_same(derived, base64Decode(parts[3]))) {
      await _write(_triesKey, null);
      await _forgetWait();
      return true;
    }
    return _wrong();
  }

  /// এ পর্যন্ত ভুল কয়বার
  Future<int> tries() async => int.tryParse(await _read(_triesKey) ?? '') ?? 0;

  Future<void> clear() async {
    await _write(_key, null);
    await _write(_triesKey, null);
    await _forgetWait();
  }

  Future<bool> _wrong() async {
    final tries = await this.tries() + 1;
    await _write(_triesKey, '$tries');
    // ⭐ সীমার পরে প্রতিটা ভুলে বাকি অপেক্ষা = নতুন অপেক্ষা, গোনা শুরু এখনকার একঘেয়ে সময় থেকে
    if (tries >= maxTries) await _keepLeft(waitAfter(tries), _elapsed());
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
