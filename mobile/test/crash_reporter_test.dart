import 'dart:io';

import 'package:abos_mobile/core/config/app_config.dart';
import 'package:abos_mobile/core/crash/crash_reporter.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ অ্যাপ ভাঙলে অফিস জানে — সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬; সার্ভার: ThePhoneCrashedAndNobodyKnewTest।
///
/// <p>⭐ ১৯ অক্টোবর ২০২৬ (ec, অ্যাপ এন্টারপ্রাইজ মান) — দাবি: ধরা ভুল আগে ফোনের সারিতে, গোপন মান মুছে; সারি একসাথে যায় আর
/// সফল হলে খালি, ব্যর্থ হলে থাকে; একই ভুল ঝাঁকে এলে একবারই যায় (গোনাসহ)।
void main() {
  final sent = <List<Map<String, dynamic>>>[];
  late MemoryCrashStore store;
  late DateTime now;
  final r = CrashReporter.instance;

  setUp(() {
    sent.clear();
    r.forget();
    store = MemoryCrashStore();
    now = DateTime(2026, 10, 19, 10);
    r.store = store;
    r.clock = () => now;
    r.device = () async => {'android': '14', 'device': 'samsung SM-A145F'};
    r.send = (batch) async => sent.add(batch);
  });

  test('a thrown error is queued with its secrets scrubbed, and only the allowed fields go', () async {
    r.send = (_) async => throw Exception('offline');
    r.screen = '/home/customers/0199a8f2-1c3d-7e4f-8a9b-0c1d2e3f4a5b/orders/42';
    try {
      throw StateError("Duplicate entry '01711223344' for customer rahim@shop.com, amount ৳1,23,456.50 "
          'and 55000, Bearer 3|aB9xYz0123456789abcdefGHIJ, url /api/v1/x?token=abc&p=2, index 5 of HTTP 500');
    } catch (e, st) {
      await r.report(e, st);
    }

    expect(store.data, hasLength(1), reason: '⛔ ভুলটা সারিতে উঠল না');
    final q = store.data.single;
    expect(q.keys.toSet(),
        {'version', 'android', 'device', 'screen', 'error', 'message', 'stack', 'fingerprint', 'times'});
    expect(q['version'], AppConfig.appVersion);
    expect(q['android'], '14');
    expect(q['device'], 'samsung SM-A145F');
    expect(q['screen'], '/home/customers/:id/orders/:id', reason: '⛔ পথের আইডি খবরে গেল');
    expect(q['error'], 'StateError');
    final msg = q['message'] as String;
    for (final secret in ['01711223344', 'rahim@shop.com', '1,23,456', '55000', 'aB9xYz0123456789', 'token=abc']) {
      expect(msg, isNot(contains(secret)), reason: '⛔ "$secret" খবরে গেল: $msg');
    }
    expect(msg, contains('index 5'), reason: 'ছোট সংখ্যা থাকে — ভুল বোঝার জন্য');
    expect(msg, contains('HTTP 500'));
    expect(q['stack'], contains('crash_reporter_test.dart'), reason: 'stack-এর ফাইল আর লাইন থাকে');
    expect(q['fingerprint'], matches(RegExp(r'^[0-9a-f]{40}$')));
  });

  test('the queue is sent in one batch and cleared on success, kept on failure', () async {
    var fail = true;
    r.send = (batch) async {
      if (fail) throw Exception('offline');
      sent.add(batch);
    };
    await r.report(StateError('a'), StackTrace.fromString('#0 a'));
    await r.report(ArgumentError('b'), StackTrace.fromString('#0 b'));
    await r.flush();
    expect(store.data, hasLength(2), reason: '⛔ পাঠানো ব্যর্থ হলো, তবু সারি মুছল');
    expect(sent, isEmpty);

    fail = false;
    await r.flush();
    expect(sent, isEmpty, reason: '⛔ দরজার সীমার ভেতরে আবার পাঠাল');

    now = now.add(CrashReporter.minGap);
    await r.flush();
    expect(sent, hasLength(1), reason: 'দুইটা খবর এক ডাকে');
    expect(sent.single.map((x) => x['error']), ['StateError', 'ArgumentError']);
    expect(store.data, isEmpty, reason: '⛔ পাঠানো সফল, তবু সারিতে রয়ে গেল');
  });

  /// ⛔ সার্ভার টোকেন ছাড়া এক ডাকে ৫টার বেশি ফেরায় (d1b8958d0) — ২০টা জমা থাকলে চার ডাকে যায়, একটাও হারায় না;
  /// ফেরালে (৪২২) সারি থাকে (a4, fe ২০ অক্টোবর ২০২৬)।
  test('twenty waiting reports go in four calls of five, none lost, and a refused call keeps them', () async {
    store.data = [
      for (var i = 0; i < 20; i++)
        {'version': '0.4.38', 'screen': '/login', 'error': 'E$i', 'message': '', 'stack': '#0 f$i',
          'fingerprint': i.toRadixString(16).padLeft(40, '0'), 'times': 1, 'android': '', 'device': ''},
    ];

    r.send = (_) async => throw Exception('422');
    await r.flush();
    expect(store.data, hasLength(20), reason: '⛔ সার্ভার ফেরাল, তবু খবর হারাল');

    r.send = (batch) async => sent.add(batch);
    for (var call = 0; call < 4; call++) {
      now = now.add(CrashReporter.minGap);
      await r.flush();
    }
    expect(sent, hasLength(4), reason: '⛔ ২০টা খবর চার ডাকে যায়নি');
    expect(sent.every((b) => b.length <= 5), isTrue, reason: '⛔ এক ডাকে ৫টার বেশি — সার্ভার টোকেন ছাড়া ফেরাবে');
    expect(sent.expand((b) => b).map((x) => x['error']).toSet(), {for (var i = 0; i < 20; i++) 'E$i'},
        reason: '⛔ কোনো খবর হারাল বা দুবার গেল');
    expect(store.data, isEmpty);
  });

  test('the same error in a burst is sent once, with its count', () async {
    r.send = (_) async => throw Exception('offline');
    final trace = StackTrace.fromString('#0 same (package:abos_mobile/x.dart:12:3)');
    for (var i = 0; i < 25; i++) {
      // ⓘ লেখায় মান বদলায় (মুছে যায়), ছাপ একই
      await r.report(StateError('row $i failed'), trace);
    }
    expect(store.data, hasLength(1), reason: '⛔ একই ভুল বারবার সারিতে');
    expect(store.data.single['times'], 25);

    r.send = (batch) async => sent.add(batch);
    now = now.add(CrashReporter.minGap);
    await r.flush();
    await r.report(StateError('row 99 failed'), trace);
    now = now.add(CrashReporter.minGap);
    await r.flush();
    expect(sent, hasLength(1), reason: '⛔ একই ভুল এক চালুতে দুবার গেল');
    expect(sent.single, hasLength(1));
  });

  /// ⛔ লাইভে দরজা না থাকা পুরনো সার্ভার (0.4.41-এর এখনকার অবস্থা; a4, ২০ অক্টোবর ২০২৬) — 404: সারি থাকে, কিছু ছোড়ে না,
  /// আর দিনের পর দিন 404 এলেও সারি [CrashReporter.maxQueued]-এর বেশি বাড়ে না।
  test('an old server without the door answers 404: the queue is kept, nothing throws, and it never grows past its cap', () async {
    var calls = 0;
    Never notFound() {
      calls++;
      final request = RequestOptions(path: '/app/crash');
      throw DioException(
        requestOptions: request,
        response: Response<void>(requestOptions: request, statusCode: 404),
        type: DioExceptionType.badResponse,
      );
    }

    r.send = (_) async => notFound();
    await r.report(StateError('a'), StackTrace.fromString('#0 a'));
    await expectLater(r.flush(), completes, reason: '⛔ 404-এ flush ছুড়ল — অ্যাপ ভাঙবে');
    expect(calls, 1, reason: 'প্রস্তুতিটাই ভুল — দরজায় ডাকই যায়নি');
    expect(store.data, hasLength(1), reason: '⛔ 404-এ সারি মুছে গেল — দরজা এলে খবরটা আর যাবে না');

    // ⓘ অনেক দিন, অনেক চালু — প্রতিবার নতুন ভুল, প্রতিবার 404
    for (var run = 0; run < 6; run++) {
      r.forget();
      r.store = store;
      r.clock = () => now;
      r.send = (_) async => notFound();
      for (var i = 0; i < CrashReporter.maxPerRun; i++) {
        await r.report(StateError('run $run'), StackTrace.fromString('#0 run $run frame $i'));
      }
      now = now.add(CrashReporter.minGap);
      await expectLater(r.flush(), completes);
    }
    expect(calls, greaterThanOrEqualTo(7), reason: 'প্রতিটা চালুতে অন্তত একবার চেষ্টা');
    expect(store.data.length, CrashReporter.maxQueued, reason: '⛔ 404-এর সারি সীমা ছাড়িয়ে বাড়ল, বা ফেলে দিল');
  });

  /// ⛔ 404 মানে দরজাই নেই — এই চালুতে [CrashReporter.notFoundGap] (৬ ঘণ্টা) আর ডাক নয়; ৫০৩-এর মতো অন্য ফেরানোয় আগের মতো
  /// [CrashReporter.minGap] পরে (a4, fe ২০ অক্টোবর ২০২৬: পুরনো সার্ভারে প্রতি সিঙ্কে বৃথা ডাক ব্যাটারি খেত)।
  Future<int> afterFirstAnswer(int status, List<Duration> waits) async {
    var calls = 0;
    r.send = (_) async {
      calls++;
      final request = RequestOptions(path: '/app/crash');
      throw DioException(
        requestOptions: request,
        response: Response<void>(requestOptions: request, statusCode: status),
        type: DioExceptionType.badResponse,
      );
    };
    await r.report(StateError('a'), StackTrace.fromString('#0 a'));
    await r.flush();
    expect(calls, 1, reason: 'প্রস্তুতিটাই ভুল — প্রথম ডাক যায়নি');
    final start = now;
    for (final wait in waits) {
      now = start.add(wait);
      await r.flush();
    }
    return calls - 1;
  }

  test('after a 404 nothing goes at 30 seconds, an hour or five hours, and one call goes after six hours', () async {
    expect(await afterFirstAnswer(404, const [Duration(seconds: 31), Duration(hours: 1), Duration(hours: 5)]), 0,
        reason: '⛔ দরজা নেই, তবু ৬ ঘণ্টার আগে আবার ডাকল');
    r.forget();
    r.store = store;
    now = DateTime(2026, 10, 19, 10);
    expect(await afterFirstAnswer(404, const [Duration(hours: 6, seconds: 1)]), 1, reason: '⛔ ৬ ঘণ্টা পরেও আর চেষ্টা করে না');
  });

  test('after a 503 the next call goes after the usual 30 seconds', () async {
    expect(await afterFirstAnswer(503, const [Duration(seconds: 31)]), 1, reason: '⛔ সার্ভার ব্যস্ত (৫০৩), তবু ৬ ঘণ্টা থামল');
  });

  test('the reporter keeps no timer of its own: calls come only from start-up and sync', () {
    final source = File('lib/core/crash/crash_reporter.dart').readAsStringSync();
    expect(source, isNot(contains('Timer(')), reason: '⛔ নিজের টাইমার — ঘুমন্ত ফোন জাগিয়ে ব্যাটারি খাবে');
    expect(source, isNot(contains('Timer.periodic')));
    expect(source, isNot(contains('Stream.periodic')));
    expect(source, isNot(contains('Future.delayed')), reason: '⛔ দেরি করে নিজেই আবার ডাকা');
  });

  test('at most ten different errors a run, and a failed store or send never throws', () async {
    r.send = (_) async => throw Exception('offline');
    for (var i = 0; i < 15; i++) {
      await r.report(StateError('x'), StackTrace.fromString('#0 frame $i'));
    }
    expect(store.data, hasLength(CrashReporter.maxPerRun));

    r.store = _BrokenStore();
    await expectLater(r.report(StateError('y'), null), completes);
    await expectLater(r.flush(), completes);
  });

  test('a stalled frame is recorded once a screen, and FlutterError reaches the queue', () async {
    r.send = (_) async => throw Exception('offline');
    r.screen = '/home/ledger';
    await r.reportStall(const Duration(milliseconds: 1400), const Duration(milliseconds: 90));
    await r.reportStall(const Duration(milliseconds: 1800), const Duration(milliseconds: 90));
    expect(store.data, hasLength(1));
    expect(store.data.single['error'], 'FrameStall');
    expect(store.data.single['times'], 2);
    expect(store.data.single['message'], contains('build 1.4s'));

    final before = FlutterError.onError;
    final beforePlatform = PlatformDispatcher.instance.onError;
    FlutterError.onError = (_) {};
    r.install();
    FlutterError.onError!(FlutterErrorDetails(exception: StateError('in build'), stack: StackTrace.fromString('#0 w')));
    FlutterError.onError = before;
    PlatformDispatcher.instance.onError = beforePlatform;
    await Future<void>.delayed(Duration.zero);
    await r.flush();
    expect(store.data.map((x) => x['error']), contains('StateError'));
  });

  /// ⭐ আসল ANR — Android-এর নিজের খাতা থেকে, পরের চালুতে (ec, ১৯ অক্টোবর ২০২৬; [[CrashReporter.reportPastExits]])
  test('an ANR Android recorded in an earlier run is queued once, scrubbed, and not again on the next start', () async {
    r.send = (_) async => throw Exception('offline');
    r.pastExits = () async => [
          {'reason': 'anr', 'time': 1000, 'description': 'Input dispatching timed out (Waited 5005ms for 01712345678)'},
          {'reason': 'native', 'time': 2000, 'description': 'crash in libflutter.so'},
        ];
    await r.reportPastExits();

    expect(store.data.map((x) => x['error']), ['ANR', 'NativeCrash']);
    final anr = store.data.first['message'] as String;
    expect(anr, contains('Input dispatching timed out'));
    expect(anr, isNot(contains('01712345678')), reason: '⛔ ফোন নম্বর ANR-এর খবরে গেল');
    expect(store.exitMark, 2000);

    // ⓘ পরের চালু — একই খাতা আবার আসে, সাথে একটা নতুন
    r.forget();
    r.pastExits = () async => [
          {'reason': 'anr', 'time': 1000, 'description': 'Input dispatching timed out'},
          {'reason': 'native', 'time': 2000, 'description': 'crash in libflutter.so'},
          {'reason': 'anr', 'time': 3000, 'description': 'Broadcast of Intent timed out'},
        ];
    await r.reportPastExits();
    expect(store.data, hasLength(3), reason: '⛔ আগের চালুতে পাঠানো ANR আবার সারিতে');
    expect(store.data.last['message'], contains('Broadcast'));

    r.pastExits = () async => throw Exception('old Android');
    await expectLater(r.reportPastExits(), completes);
  });

  test('a huge stack is cut to the server\'s limit', () async {
    r.send = (_) async => throw Exception('offline');
    await r.report(StateError('x'), StackTrace.fromString('#0 ${'y' * 9000}'));
    expect((store.data.single['stack'] as String).length, 8000);
  });

  /// ⛔ গোপন তথ্য খবরে নয় — অ্যাপের নিরাপত্তা, ১৯ অক্টোবর ২০২৬ (MASVS-STORAGE-2 / PRIVACY; [[CrashReporter.scrub]])
  test('a token, a password, an email and a phone number never leave in the report', () async {
    const token = 'eyJhbGciOiJIUzI1NiJ9.abc123DEF456ghi789JKL012';
    final error = StateError('DioException POST /api/v1/auth/login failed: {"password": "hunter22", "phone": "01712345678", '
        '"email": "rahim.store@example.com"} Authorization: Bearer $token refresh_token=r3fr3shT0k3nV4lu3XYZ0987654321');
    r.send = (_) async => throw Exception('offline');
    await r.report(error, StackTrace.fromString('#0 LoginScreen._submit (package:abos_mobile/x.dart:42:7)'));

    // ⓘ সারিতে যা বসে, ঠিক তাই যায় ([[CrashReporter.flush]])
    final q = store.data.single;
    final message = q['message'] as String;
    for (final secret in ['hunter22', '01712345678', 'rahim.store@example.com', token, 'r3fr3shT0k3nV4lu3XYZ0987654321']) {
      expect(message, isNot(contains(secret)), reason: '⛔ "$secret" ক্র্যাশের খবরে গেল');
    }
    // ⓘ সারানোর জন্য যা লাগে তা থাকে — ধরন, পথ, আর stack-এর ফাইল-লাইন
    expect(message, contains('/api/v1/auth/login'));
    expect(q['stack'], contains('package:abos_mobile/x.dart:42:7'));
  });

  test('the scrubber keeps an ordinary message and a status code as they are', () {
    expect(CrashReporter.scrub('RangeError (index): Invalid value: Not in inclusive range 0..3: 7'),
        'RangeError (index): Invalid value: Not in inclusive range 0..3: 7');
    expect(CrashReporter.scrub('DioException [bad response]: status code: 422'), 'DioException [bad response]: status code: 422');
    expect(CrashReporter.scrub('+8801912345678 and 8801812345678'), '***phone*** and ***phone***');
  });
}

class _BrokenStore implements CrashStore {
  @override
  Future<List<Map>> read() async => throw StateError('disk');

  @override
  Future<void> write(List<Map<String, dynamic>> queue) async => throw StateError('disk');

  @override
  Future<int> readExitMark() async => throw StateError('disk');

  @override
  Future<void> writeExitMark(int millis) async => throw StateError('disk');
}
