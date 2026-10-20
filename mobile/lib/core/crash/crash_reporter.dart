import 'dart:async';
import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter/services.dart';
import 'package:hive/hive.dart';

import '../api_client/api_client.dart';
import '../config/app_config.dart';
import '../storage/hive_encryption.dart';

/// ⭐ অ্যাপ ভাঙলে অফিস জানে — নিজের সার্ভারের ভুলের খাতায় (`POST /app/crash`; সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬:
/// "মাঠের ফোনে অ্যাপ বন্ধ হয়ে গেলে অফিস জানতেই পারে না")। বাইরে কিছু নয় (Crashlytics নয়)।
///
/// <p>⭐ ১৯ অক্টোবর ২০২৬ (ec, অ্যাপ এন্টারপ্রাইজ মান): আগে খবর সাথে সাথে একবার পাঠানো হত, আর নেট না থাকলে হারাত —
/// মাঠে ঠিক যেখানে অ্যাপ বেশি ভাঙে। এখন:
///   · ধরা পড়লে আগে ফোনের সারিতে (এনক্রিপ্ট করা Hive বাক্স), তারপর নেট থাকলে একসাথে কয়েকটা ([[flush]]);
///     পাঠানো সফল হলে সারি থেকে বাদ, ব্যর্থ হলে সারি থাকে — পরের বার আবার।
///   · একই ভুল (একই [fingerprint]) এক চালুতে একবারই সারিতে; আবার হলে কেবল গোনা (`times`) বাড়ে।
///   · ⛔ গোপন কিছু যায় না: লেখা আর stack থেকে উদ্ধৃত মান, চার বা বেশি অঙ্কের সংখ্যা (ফোন, টাকা, আইডি), ইমেইল,
///     টোকেন আর ঠিকানার প্রশ্নাংশ মোছা হয় ([[scrub]]) — সার্ভারের ErrorJournal-এর একই নিয়ম: মান নয়, কেবল ধরন।
///   · যায়: অ্যাপের সংস্করণ, Android সংস্করণ, ফোনের মডেল, পর্দা (আইডি বাদ), ভুলের ধরন, ছাঁটা লেখা, stack আর ছাপ।
///   · ⓘ ANR: Flutter আসল ANR ধরতে পারে না (Android নিজে অ্যাপ বন্ধ করে, Dart তখন চলে না)। যা ধরা হয়: এক সেকেন্ডের
///     বেশি আটকে থাকা ফ্রেম ([[watchStalls]], `FrameStall`) — UI থ্রেডে লম্বা কাজের সবচেয়ে কাছের চিহ্ন।
class CrashReporter {
  CrashReporter._();

  static final CrashReporter instance = CrashReporter._();

  /// এক চালুতে আলাদা আলাদা ভুল সর্বোচ্চ — ভাঙা পর্দা বারবার আঁকা হলে সারি ভাসবে না
  static const int maxPerRun = 10;

  /// ফোনে জমা সর্বোচ্চ — পুরনোগুলো আগে বাদ
  static const int maxQueued = 30;

  /// এক ডাকে সর্বোচ্চ (সার্ভারের সীমার সমান — AppCrashController)
  static const int batchSize = 20;

  /// দুই পাঠানোর মাঝে অন্তত এটুকু — সার্ভারের দরজায় মিনিটে ১০টার সীমা
  static const Duration minGap = Duration(seconds: 30);

  /// যত লম্বা ফ্রেমকে "আটকে থাকা" ধরা হয়
  static const Duration stallAfter = Duration(seconds: 1);

  /// পরীক্ষার পথ — আসলটা সার্ভারে পাঠায়
  @visibleForTesting
  Future<void> Function(List<Map<String, dynamic>> reports) send = _post;

  /// পরীক্ষার পথ — আসলটা এনক্রিপ্ট করা Hive বাক্স, প্রথম ভুলে বা প্রথম পাঠানোয় খোলে (প্রথম ফ্রেমের আগে নয়)
  @visibleForTesting
  CrashStore store = HiveCrashStore();

  /// পরীক্ষার পথ — আসলটা Android-কে জিজ্ঞেস করে
  @visibleForTesting
  Future<Map<String, String>> Function() device = _askDevice;

  /// পরীক্ষার পথ — আসলটা Android-এর নিজের খাতা (ApplicationExitInfo, Android 11+) থেকে আগের চালুগুলোর বন্ধ হওয়া
  @visibleForTesting
  Future<List<Map<String, Object?>>> Function() pastExits = _askExits;

  /// পরীক্ষার পথ — ঘড়ি
  @visibleForTesting
  DateTime Function() clock = DateTime.now;

  String _screen = '';
  final Map<String, int> _seen = {};
  Map<String, String>? _device;
  DateTime? _lastSend;
  Future<void> _chain = Future<void>.value();

  static final RegExp _id = RegExp(
      r'^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|\d+)$',
      caseSensitive: false);

  /// যে পর্দায় আছে — রাউটার বদলালে ([[AbosApp]])। আইডি বাদ: `/home/customers/<uuid>` → `/home/customers/:id`
  set screen(String path) => _screen = path
      .split('/')
      .map((part) => _id.hasMatch(part) ? ':id' : part)
      .join('/');

  /// অ্যাপ ধরতে পারেনি এমন সব ভুল — ফ্রেমওয়ার্কের আর অ্যাসিঙ্কের।
  ///
  /// <p>ⓘ zone-পাহারা নেই, ইচ্ছা করে: main() কোনো `runZonedGuarded` চালায় না, আর মূল zone-এর ধরা-না-পড়া অ্যাসিঙ্ক ভুল
  /// [PlatformDispatcher.onError]-এই আসে। ⓘ কিছুই অপেক্ষা করে না — বাক্স খোলে প্রথম ভুলে।
  void install() {
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      previous?.call(details);
      unawaited(report(details.exception, details.stack));
    };
    final previousPlatform = PlatformDispatcher.instance.onError;
    PlatformDispatcher.instance.onError = (error, stack) {
      unawaited(report(error, stack));
      // ⓘ আগের হাতলের উত্তর, নাহলে false — আচরণ আগের মতোই থাকে (লগে যায়); আমরা কেবল খবরটা রাখি
      return previousPlatform?.call(error, stack) ?? false;
    };
  }

  /// ⓘ আটকে থাকা ফ্রেম — প্রথম ফ্রেমের পরে ডাকা হয় ([[AbosApp]])। ছাপ পর্দা ধরে, তাই প্রতি পর্দায় এক চালুতে একবার।
  void watchStalls() {
    SchedulerBinding.instance.addTimingsCallback(noteTimings);
  }

  @visibleForTesting
  void noteTimings(List<FrameTiming> timings) {
    for (final t in timings) {
      if (t.totalSpan >= stallAfter) {
        unawaited(reportStall(t.buildDuration, t.rasterDuration));
        return;
      }
    }
  }

  /// ⭐ আসল ANR — আগের চালুতে Android অ্যাপটা "সাড়া দিচ্ছে না" বলে বন্ধ করেছিল, বা নেটিভ কোডে ভেঙেছিল (ec, ১৯ অক্টোবর ২০২৬)।
  ///
  /// <p>ⓘ ANR-এর মুহূর্তে Dart চলে না, তাই তখন ধরা যায় না; কিন্তু Android 11 (API 30) থেকে প্রতিটা অ্যাপের বন্ধ হওয়ার কারণ
  /// নিজের খাতায় রাখে (`ActivityManager.getHistoricalProcessExitReasons`)। পরের চালুতে, প্রথম ফ্রেমের পরে, সেখান থেকে
  /// ANR আর নেটিভ ক্র্যাশগুলো সারিতে তোলা হয় — `ANR` / `NativeCrash`, Android-এর বিবরণ ছেঁকে ([scrub]), stack নয়। শেষ পাঠানোর
  /// সময় মনে রাখা হয়, তাই একটা ঘটনা একবারই। ⓘ Android 10 বা পুরনোয় খাতা নেই — কিছুই আসে না। ⛔ কখনো ছোড়ে না।
  Future<void> reportPastExits() async {
    try {
      final mark = await store.readExitMark();
      final exits = await pastExits();
      var newest = mark;
      for (final e in exits) {
        final time = (e['time'] as num?)?.toInt() ?? 0;
        if (time <= mark) continue;
        if (time > newest) newest = time;
        final type = e['reason'] == 'anr' ? 'ANR' : 'NativeCrash';
        final what = _cut(scrub('${e['description'] ?? ''}'), 2000);
        await _enqueue({
          'version': AppConfig.appVersion,
          'screen': '',
          'error': type,
          'message': what,
          'stack': '',
          'fingerprint': _hash('$type|$what'),
        });
      }
      if (newest > mark) await store.writeExitMark(newest);
    } catch (_) {
      // ⓘ পুরনো Android, বা খাতা পড়া গেল না — চুপ
    }
  }

  /// একটা আটকে থাকা ফ্রেম — সেকেন্ডে, এক দশমিকে
  @visibleForTesting
  Future<void> reportStall(Duration build, Duration raster) async {
    final b = (build.inMilliseconds / 1000).toStringAsFixed(1);
    final r = (raster.inMilliseconds / 1000).toStringAsFixed(1);
    await _enqueue({
      ..._base(),
      'error': 'FrameStall',
      'message': 'frame over ${stallAfter.inSeconds}s: build ${b}s, raster ${r}s',
      'stack': '',
      'fingerprint': _hash('FrameStall|$_screen'),
    });
    unawaited(flush());
  }

  /// একটা ভুল সারিতে, তারপর পাঠানোর চেষ্টা। ⛔ কখনো ছোড়ে না — ক্র্যাশের খবর রাখতে গিয়ে আরেক ক্র্যাশ নয়।
  Future<void> report(Object error, StackTrace? stack) async {
    try {
      await _enqueue(describe(error, stack));
    } catch (_) {
      // ⓘ সারিই লেখা গেল না — চুপ
    }
    unawaited(flush());
  }

  Future<void> _enqueue(Map<String, dynamic> body) => _serial(() async {
        final fp = body['fingerprint'] as String;
        final known = _seen[fp];
        if (known == null && _seen.length >= maxPerRun) return;
        _seen[fp] = (known ?? 0) + 1;

        final queue = await _read();
        final at = queue.indexWhere((r) => r['fingerprint'] == fp);
        if (at >= 0) {
          // ⭐ একই ভুল আবার (এই চালুতে বা আগের চালুর, এখনো না গিয়ে) — নতুন সারি নয়, কেবল গোনা
          queue[at]['times'] = ((queue[at]['times'] as num?)?.toInt() ?? 1) + 1;
        } else if (known != null) {
          // ⓘ এই চালুতে আগেই পাঠানো — আবার নয়
          return;
        } else {
          queue.add({...body, ...await _deviceOnce(), 'times': 1});
          while (queue.length > maxQueued) {
            queue.removeAt(0);
          }
        }
        await store.write(queue);
      });

  /// সারির খবরগুলো সার্ভারে, [batchSize]টা করে। সফল হলে পাঠানোগুলো সারি থেকে বাদ; ব্যর্থ হলে সব থাকে।
  /// [minGap]-এর ভেতরে আবার ডাকলে কিছু করে না (দরজার সীমা)। ⛔ কখনো ছোড়ে না।
  Future<void> flush() => _serial(() async {
        final last = _lastSend;
        if (last != null && clock().difference(last) < minGap) return;
        final queue = await _read();
        if (queue.isEmpty) return;
        _lastSend = clock();
        final batch = queue.take(batchSize).toList();
        try {
          await send(batch);
        } catch (_) {
          // ⓘ নেট নেই বা সার্ভার ফেরাল — সারি যেমন ছিল থাকে; পরের বার
          return;
        }
        final sent = batch.map((r) => r['fingerprint']).toSet();
        await store.write(queue.where((r) => !sent.contains(r['fingerprint'])).toList());
      });

  /// একটার পর একটা — দুইটা কাজ একসাথে সারি পড়ে একে অন্যেরটা মুছবে না। ⛔ ভুল এখানেই থামে।
  Future<void> _serial(Future<void> Function() job) {
    final next = _chain.then((_) => job()).catchError((Object _) {});
    _chain = next;
    return next;
  }

  Future<List<Map<String, dynamic>>> _read() async =>
      (await store.read()).map((r) => Map<String, dynamic>.from(r)).toList();

  Future<Map<String, String>> _deviceOnce() async {
    final known = _device;
    if (known != null) return known;
    try {
      return _device = await device();
    } catch (_) {
      return _device = const {'android': '', 'device': ''};
    }
  }

  Map<String, dynamic> _base() => {
        'version': AppConfig.appVersion,
        'screen': _cut(_screen, 191),
      };

  @visibleForTesting
  Map<String, dynamic> describe(Object error, StackTrace? stack) {
    final type = _cut(error.runtimeType.toString(), 191);
    final trace = _cut(scrubStack(stack?.toString() ?? ''), 8000);
    return {
      ..._base(),
      'error': type,
      'message': _cut(scrub(error.toString()), 2000),
      'stack': trace,
      'fingerprint': fingerprint(type, trace),
    };
  }

  /// ⭐ ছাপ — ধরন আর stack-এর প্রথম পাঁচটা ফ্রেম; লেখা নয় (লেখায় মান বদলায়, ভুলটা একই থাকে)
  static String fingerprint(String type, String stack) {
    final frames = stack
        .split('\n')
        .map((l) => l.trim())
        .where((l) => l.isNotEmpty && l != '<asynchronous suspension>')
        .take(5)
        .join('\n');
    return _hash('$type|$frames');
  }

  static String _hash(String s) => sha1.convert(utf8.encode(s)).toString();

  static final RegExp _quoted = RegExp(r'''(["'`])(?:(?!\1)[^\n]){1,500}?\1''');
  // ⓘ দৈর্ঘ্যের সীমা ইচ্ছা করে — `+` হলে লম্বা এক শব্দের stack-এ (৯০০০ অক্ষর) খোঁজা বর্গাকারে ধীর হত (মাপা: ~১০ সেকেন্ড)
  static final RegExp _email = RegExp(r'[\w.+-]{1,64}@[\w-]{1,63}(\.[\w-]{1,63}){1,4}');
  static final RegExp _secretWord = RegExp(
      r'\b(Bearer|token|password|passwd|secret|pin|otp)(\s*[=:]\s*|\s+)(?=\S*[A-Za-z0-9])(\S+)',
      caseSensitive: false);
  static final RegExp _query = RegExp(r'\?[^\s)]+');
  static final RegExp _longToken = RegExp(r'\b(?=[A-Za-z0-9_\-]*\d)[A-Za-z0-9_\-]{24,}\b');
  static final RegExp _number = RegExp(r'[0-9০-৯](?:[0-9০-৯,.]*[0-9০-৯])?');
  static final RegExp _digit = RegExp(r'[0-9০-৯]');
  static final RegExp _stackDigits = RegExp(r'\d{6,}');

  /// চার বা বেশি অঙ্কের একটা সংখ্যা (কমা-দশমিক সহ: ১,২৩,৪৫৬ বা 55000.50) — ছোটগুলো থাকে (`index 5`, `HTTP 500`)
  static String _numbers(String s) =>
      s.replaceAllMapped(_number, (m) => _digit.allMatches(m[0]!).length >= 4 ? '[n]' : m[0]!);

  /// ⛔ গোপন তথ্য বাদ — পাঠানোর আগে (OWASP MASVS-STORAGE-2 / PRIVACY; অ্যাপের নিরাপত্তা, ১৯ অক্টোবর ২০২৬)।
  ///
  /// <p>⚠️ ভুলের লেখা (`error.toString()`) ছাঁকনি ছাড়া যেত: নেটের ভুলে ঠিকানা আর উত্তরের অংশ, যাচাইয়ের ভুলে গ্রাহকের ফোন বা
  /// ইমেইল, আর কখনো `Bearer …` টোকেন। ভুলের খাতা পড়েন নিরাপত্তা-পাহারার মানুষ — তাঁর কাছেও এগুলো যাওয়ার কথা নয়।
  /// ⭐ যা বাদ যায়: Bearer/টোকেন, পাসওয়ার্ড-টোকেন-গোপন নামের ঘরের মান, ২৪+ অক্ষরের টোকেনের মতো লেখা, ইমেইল, বাংলাদেশি
  /// মোবাইল নম্বর ([_secrets]); তার ওপর (ec, ১৯ অক্টোবর ২০২৬) উদ্ধৃত যেকোনো মান, ঠিকানার প্রশ্নাংশ আর চার বা বেশি অঙ্কের
  /// সংখ্যা — টাকা, আইডি (সার্ভারের ErrorJournal-এর একই নিয়ম)। ⓘ ভুলের ধরন আর ছোট সংখ্যা থাকে — সারানোর জন্য ওটুকুই লাগে।
  @visibleForTesting
  static String scrub(String s) => _numbers(_secrets(s)
      .replaceAll(_quoted, '[value]')
      .replaceAll(_email, '[email]')
      .replaceAllMapped(_secretWord, (m) => '${m[1]}${m[2]}[secret]')
      .replaceAll(_query, '?[query]')
      .replaceAll(_longToken, '[secret]'));

  /// নিরাপত্তার ছাঁকনি — টোকেন, গোপন ঘর, ইমেইল, ফোন, লম্বা টোকেন (লেখা আর stack দুইটাতেই)
  static String _secrets(String text) => text
      .replaceAll(_bearer, 'Bearer ***')
      .replaceAllMapped(_secretField, (m) => '${m[1]}***')
      .replaceAll(_email, '***@***')
      .replaceAll(_phone, '***phone***')
      .replaceAll(_securityToken, '***');

  static final RegExp _bearer = RegExp(r'Bearer\s+[A-Za-z0-9\-._~+/|=]+', caseSensitive: false);

  static final RegExp _secretField = RegExp(
      r'''((?:password|passwd|pin|token|access_?token|refresh_?token|secret|authorization|api_?key|otp)["']?\s*[:=]\s*["']?)[^"',&\s}\]]+''',
      caseSensitive: false);

  /// বাংলাদেশি মোবাইল — +880/880/0 সহ ১০ অঙ্ক, ১৩–১৯-এ শুরু
  static final RegExp _phone = RegExp(r'(?<!\d)(?:\+?88)?0?1[3-9]\d{8}(?!\d)');

  /// টোকেনের মতো লম্বা অক্ষরমালা — ২৪+ অক্ষর, অক্ষর আর অঙ্ক মেশানো (ফাইলের পথ বা শ্রেণির নাম নয়: ওগুলোয় `/` বা `.` থাকে)
  static final RegExp _securityToken =
      RegExp(r'(?<![A-Za-z0-9])(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{24,}(?![A-Za-z0-9])');

  /// stack-এ লাইন নম্বর থাকে (`file.dart:120:7`) — ওগুলো দরকারি, তাই নিরাপত্তার ছাঁকনি, উদ্ধৃত মান, প্রশ্নাংশ আর ছয় বা বেশি অঙ্ক
  static String scrubStack(String s) => _secrets(s)
      .replaceAll(_quoted, '[value]')
      .replaceAll(_email, '[email]')
      .replaceAll(_query, '?[query]')
      .replaceAll(_stackDigits, '[n]');

  /// পরীক্ষার জন্য — এক চালুর গোনা মোছা
  @visibleForTesting
  void forget() {
    _seen.clear();
    _screen = '';
    _device = null;
    _lastSend = null;
    _chain = Future<void>.value();
  }

  static String _cut(String s, int max) => s.length <= max ? s : s.substring(0, max);

  static Future<void> _post(List<Map<String, dynamic>> reports) async {
    // ⓘ টোকেন থাকলে সাথে যায় (কে আর কোন কোম্পানি); মেয়াদ ফুরালে নবায়ন নয় — ক্র্যাশের খবর কাউকে লগআউট করাবে না
    await ApiClient.dio.post<void>(
      '/app/crash',
      data: {'reports': reports},
      options: Options(extra: ApiClient.skipAuthRefresh),
    );
  }

  static const MethodChannel _deviceChannel = MethodChannel('com.abos.abos_mobile/device');

  static Future<List<Map<String, Object?>>> _askExits() async {
    final answer = await _deviceChannel.invokeListMethod<Object?>('exits');
    return [
      for (final e in answer ?? const <Object?>[])
        if (e is Map) e.map((k, v) => MapEntry('$k', v)),
    ];
  }

  static Future<Map<String, String>> _askDevice() async {
    final answer = await _deviceChannel.invokeMapMethod<String, Object?>('describe');
    return {
      'android': _cut('${answer?['android'] ?? ''}', 32),
      'device': _cut('${answer?['device'] ?? ''}', 64),
    };
  }
}

/// ফোনে জমা ক্র্যাশের সারি
abstract class CrashStore {
  Future<List<Map>> read();
  Future<void> write(List<Map<String, dynamic>> queue);

  /// শেষ যে বন্ধ-হওয়ার খবর পাঠানো হয়েছে তার সময় (মিলিসেকেন্ড) — [[CrashReporter.reportPastExits]]
  Future<int> readExitMark();
  Future<void> writeExitMark(int millis);
}

/// এনক্রিপ্ট করা বাক্স — বাকি সব বাক্সের মতো ([[HiveEncryptionKey]])। ⓘ প্রথম দরকারে খোলে।
class HiveCrashStore implements CrashStore {
  static const String _boxName = 'abos_crash_queue';
  Box<Map>? _box;

  Future<Box<Map>> _open() async => _box ??= await HiveEncryptionKey.openBox<Map>(_boxName);

  @override
  Future<List<Map>> read() async {
    final row = (await _open()).get('q');
    final list = row?['v'];
    return list is List ? list.whereType<Map>().toList() : <Map>[];
  }

  @override
  Future<void> write(List<Map<String, dynamic>> queue) async {
    await (await _open()).put('q', <String, dynamic>{'v': queue});
  }

  @override
  Future<int> readExitMark() async => ((await _open()).get('exitMark')?['v'] as num?)?.toInt() ?? 0;

  @override
  Future<void> writeExitMark(int millis) async {
    await (await _open()).put('exitMark', <String, dynamic>{'v': millis});
  }
}

/// পরীক্ষার জন্য
class MemoryCrashStore implements CrashStore {
  List<Map<String, dynamic>> data = [];

  @override
  Future<List<Map>> read() async => data.map((r) => Map<String, dynamic>.from(r)).toList();

  @override
  Future<void> write(List<Map<String, dynamic>> queue) async {
    data = queue.map((r) => Map<String, dynamic>.from(r)).toList();
  }

  int exitMark = 0;

  @override
  Future<int> readExitMark() async => exitMark;

  @override
  Future<void> writeExitMark(int millis) async => exitMark = millis;
}
