import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../locale/app_locale.dart';
import '../navigation/top_bar/abos_top_bar.dart' show bnDigits;

/// ⭐ অ্যাপ-তালার সময় — কতক্ষণ বাইরে থাকলে ফিরতে আঙুলের ছাপ বা PIN লাগে (অ্যাপের নিরাপত্তা, OWASP MASVS-AUTH-3,
/// ১৯ অক্টোবর ২০২৬; [[AppLock]])।
///
/// <p>⛔ আগে ৫ মিনিটে বাঁধা ছিল — কাউন্টারে বসা মানুষ চাইলেও কড়া করতে পারতেন না। ⭐ এখন চারটা পছন্দ: সাথে সাথে, ১, ৫ (আগের
/// মতো, ডিফল্ট) আর ১৫ মিনিট। ⛔ "কখনো না" নেই — খোলা ফোন হারালে অনুমোদন-বাক্স আর টাকার অঙ্ক খোলা থাকত।
/// ⓘ পছন্দ ফোনের নিরাপদ ভাণ্ডারে; পড়া না গেলে বা অচেনা মান হলে ৫ মিনিট — ভুলে কখনো ঢিলে নয়।
class LockDelay {
  LockDelay({Future<String?> Function()? read, Future<void> Function(String value)? write})
      : _read = read ?? _readStore,
        _write = write ?? _writeStore;

  static final LockDelay instance = LockDelay();

  /// বাছাইয়ের পছন্দ, মিনিটে — ক্রমে, পর্দায় এভাবেই
  static const List<int> choices = [0, 1, 5, 15];

  static const int defaultMinutes = 5;

  static const String _key = 'abos_lock_delay_minutes';

  static const FlutterSecureStorage _store = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
  );

  final Future<String?> Function() _read;
  final Future<void> Function(String value) _write;

  /// এখনকার সময় — তালা প্রতিবার ফেরার মুহূর্তে এটাই পড়ে
  final ValueNotifier<Duration> current = ValueNotifier(const Duration(minutes: defaultMinutes));

  /// শুরুতে একবার — ভাণ্ডার থেকে
  Future<void> load() async {
    try {
      current.value = Duration(minutes: _parse(await _read()));
    } catch (_) {
      current.value = const Duration(minutes: defaultMinutes);
    }
  }

  /// মানুষের বাছাই — তালিকার বাইরের মান নেওয়া হয় না
  Future<void> choose(int minutes) async {
    if (!choices.contains(minutes)) return;
    current.value = Duration(minutes: minutes);
    try {
      await _write('$minutes');
    } catch (_) {
      // ⓘ লেখা গেল না — এই চালুতে বাছাই খাটে, পরের চালুতে ডিফল্ট; ভাঙার কিছু নয়
    }
  }

  static int _parse(String? stored) {
    final minutes = int.tryParse(stored ?? '');
    return minutes != null && choices.contains(minutes) ? minutes : defaultMinutes;
  }

  /// পর্দার লেখা — "সাথে সাথে", "১ মিনিট" …
  static String label(int minutes) =>
      minutes == 0 ? AppLocale.l10n.lockDelayNow : AppLocale.l10n.lockDelayMinutes(minutes, bnDigits(minutes));

  static Future<String?> _readStore() => _store.read(key: _key);

  static Future<void> _writeStore(String value) => _store.write(key: _key, value: value);
}
