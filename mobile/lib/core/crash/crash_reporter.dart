import 'package:flutter/foundation.dart';

import '../api_client/api_client.dart';
import '../config/app_config.dart';

/// ⭐ অ্যাপ ভাঙলে অফিস জানে — নিজের সার্ভারের ভুলের খাতায় (`POST /app/crash`; সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬:
/// "মাঠের ফোনে অ্যাপ বন্ধ হয়ে গেলে অফিস জানতেই পারে না")।
///
/// <p>⛔ কেবল চারটা জিনিস যায়: সংস্করণ, পর্দা (পথের আইডিগুলো `:id` হয়ে), ভুলের ধরন আর লেখা, আর stack। টোকেন বা
/// ব্যক্তিগত তথ্য নয়; বাইরে কিছু নয় (Crashlytics নয়)। এক চালুতে একই ভুল একবার, আর সব মিলে [maxPerRun]টা —
/// ভাঙা পর্দা বারবার আঁকা হলে সার্ভার ভাসবে না। পাঠানো ব্যর্থ হলে চুপ — ক্র্যাশের খবর পাঠাতে গিয়ে আরেক ক্র্যাশ নয়।
class CrashReporter {
  CrashReporter._();

  static final CrashReporter instance = CrashReporter._();

  static const int maxPerRun = 5;

  /// পরীক্ষার পথ — আসলটা সার্ভারে পাঠায়
  @visibleForTesting
  Future<void> Function(Map<String, dynamic> report) send = _post;

  String _screen = '';
  final Set<String> _sent = {};

  static final RegExp _id = RegExp(
      r'^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\d+)$',
      caseSensitive: false);

  /// যে পর্দায় আছে — রাউটার বদলালে ([[AbosApp]])। আইডি বাদ: `/home/customers/<uuid>` → `/home/customers/:id`
  set screen(String path) => _screen = path
      .split('/')
      .map((part) => _id.hasMatch(part) ? ':id' : part)
      .join('/');

  /// অ্যাপ ধরতে পারেনি এমন সব ভুল — ফ্রেমওয়ার্কের আর অ্যাসিঙ্কের
  void install() {
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      previous?.call(details);
      report(details.exception, details.stack);
    };
    PlatformDispatcher.instance.onError = (error, stack) {
      report(error, stack);
      // ⓘ false — আচরণ আগের মতোই থাকে (লগে যায়); আমরা কেবল খবরটা রাখি
      return false;
    };
  }

  Future<void> report(Object error, StackTrace? stack) async {
    final body = describe(error, stack);
    final firstLine = (body['stack'] as String).split('\n').first;
    if (_sent.length >= maxPerRun || !_sent.add('${body['error']}|$firstLine')) return;
    try {
      await send(body);
    } catch (_) {
      // ⓘ নেট নেই বা সার্ভার ফেরাল — চুপ; পরের চালুতে একই ভুল হলে আবার যাবে
    }
  }

  @visibleForTesting
  Map<String, dynamic> describe(Object error, StackTrace? stack) => {
        'version': AppConfig.appVersion,
        'screen': _cut(_screen, 191),
        'error': _cut(error.runtimeType.toString(), 191),
        'message': _cut(error.toString(), 2000),
        'stack': _cut(stack?.toString() ?? '', 8000),
      };

  /// পরীক্ষার জন্য — এক চালুর গোনা মোছা
  @visibleForTesting
  void forget() {
    _sent.clear();
    _screen = '';
  }

  static String _cut(String s, int max) => s.length <= max ? s : s.substring(0, max);

  static Future<void> _post(Map<String, dynamic> report) async {
    await ApiClient.dio.post<void>('/app/crash', data: report);
  }
}
