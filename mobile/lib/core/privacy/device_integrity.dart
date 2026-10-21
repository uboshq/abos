import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

import '../locale/app_locale.dart';

/// ফোনের অবস্থা — root করা কি না, আর আসল ফোন নাকি emulator
class IntegrityReport {
  const IntegrityReport({required this.rooted, required this.emulator});

  final bool rooted;
  final bool emulator;

  bool get clean => !rooted && !emulator;
}

/// ⭐ root করা বা ছদ্ম-ফোন চেনা — সতর্ক করে, আটকায় না (অ্যাপের নিরাপত্তা, OWASP MASVS-RESILIENCE-1, ১৯ অক্টোবর ২০২৬)।
///
/// <p>⚠️ root করা ফোনে অন্য অ্যাপ এই অ্যাপের ভাণ্ডার পড়তে বা পর্দার আড়াল ভাঙতে পারে; emulator-এ কেউ অ্যাপটা খুলে দেখছেন।
/// ⭐ মালিকের নিয়ম (fe-র মাধ্যমে): আটকানো নয় — মাঠের কারো পুরনো ফোন ভুলে root-ধরা পড়লে কাজ থামবে না। মানুষকে একবার
/// বলা হয়, প্রতি চালুতে একবার। ⓘ পরীক্ষা Android-এর ভেতরে (`MainActivity.kt`, `integrity` চ্যানেল); না পাওয়া গেলে
/// (iOS, পুরনো বিল্ড, পরীক্ষা) চুপ — সতর্কতা দেখাতে গিয়ে অ্যাপ ভাঙবে না।
class DeviceIntegrity {
  const DeviceIntegrity._();

  static const _channel = MethodChannel('com.abos.abos_mobile/integrity');

  /// পরীক্ষার পথ — আসলটা Android-এর পরীক্ষা
  @visibleForTesting
  static Future<IntegrityReport> Function() checker = _check;

  static bool _warned = false;

  /// এই চালুতে একবার — সমস্যা থাকলে বার্তা, নইলে (বা আগেই বলা হয়ে থাকলে) null
  static Future<String?> warnOnce() async {
    if (_warned) return null;
    try {
      final report = await checker();
      _warned = true;
      return messageFor(report);
    } catch (error) {
      debugPrint('ABOS device integrity not checked: $error');
      return null;
    }
  }

  /// মানুষের ভাষায় — কী পাওয়া গেল আর কী করবেন
  static String? messageFor(IntegrityReport report) {
    if (report.clean) return null;
    final l = AppLocale.l10n;
    final what = report.rooted && report.emulator
        ? l.deviceIntegrityRootedEmulator
        : report.rooted
            ? l.deviceIntegrityRooted
            : l.deviceIntegrityEmulator;
    return l.deviceIntegrityWarning(what);
  }

  @visibleForTesting
  static void reset() => _warned = false;

  static Future<IntegrityReport> _check() async {
    final map = await _channel.invokeMapMethod<String, dynamic>('check') ?? const {};
    return IntegrityReport(rooted: map['rooted'] == true, emulator: map['emulator'] == true);
  }
}
