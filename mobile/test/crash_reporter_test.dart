import 'package:abos_mobile/core/config/app_config.dart';
import 'package:abos_mobile/core/crash/crash_reporter.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ অ্যাপ ভাঙলে অফিস জানে — সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬; সার্ভার: ThePhoneCrashedAndNobodyKnewTest।
void main() {
  final sent = <Map<String, dynamic>>[];

  setUp(() {
    sent.clear();
    CrashReporter.instance.forget();
    CrashReporter.instance.send = (r) async => sent.add(r);
  });

  test('only the version, the screen without ids, the error and the stack go', () async {
    CrashReporter.instance.screen = '/home/customers/0199a8f2-1c3d-7e4f-8a9b-0c1d2e3f4a5b/orders/42';
    await CrashReporter.instance.report(StateError('No element'), StackTrace.fromString('#0 a\n#1 b'));

    expect(sent, hasLength(1));
    final r = sent.single;
    expect(r.keys.toSet(), {'version', 'screen', 'error', 'message', 'stack'});
    expect(r['version'], AppConfig.appVersion);
    expect(r['screen'], '/home/customers/:id/orders/:id', reason: '⛔ পথের আইডি খবরে গেল');
    expect(r['error'], 'StateError');
    expect(r['message'], contains('No element'));
    expect(r['stack'], startsWith('#0 a'));
  });

  test('the same crash once a run, and at most five in all', () async {
    final trace = StackTrace.fromString('#0 same');
    await CrashReporter.instance.report(StateError('x'), trace);
    await CrashReporter.instance.report(StateError('x'), trace);
    expect(sent, hasLength(1), reason: '⛔ একই ক্র্যাশ বারবার পাঠাল');

    for (var i = 0; i < 10; i++) {
      await CrashReporter.instance.report(StateError('x'), StackTrace.fromString('#0 frame $i'));
    }
    expect(sent, hasLength(CrashReporter.maxPerRun));
  });

  test('a failed send is quiet — reporting a crash never makes another', () async {
    CrashReporter.instance.send = (_) async => throw Exception('offline');
    await expectLater(CrashReporter.instance.report(StateError('x'), null), completes);
  });

  test('a huge stack is cut to the server\'s limit', () async {
    await CrashReporter.instance.report(StateError('x'), StackTrace.fromString('#0 ${'y' * 9000}'));
    expect((sent.single['stack'] as String).length, 8000);
  });

  /// ⛔ গোপন তথ্য খবরে নয় — অ্যাপের নিরাপত্তা, ১৯ অক্টোবর ২০২৬ (MASVS-STORAGE-2 / PRIVACY; [[CrashReporter.scrub]])
  test('a token, a password, an email and a phone number never leave in the report', () async {
    const token = 'eyJhbGciOiJIUzI1NiJ9.abc123DEF456ghi789JKL012';
    final error = StateError('DioException POST /api/v1/auth/login failed: {"password": "hunter22", "phone": "01712345678", '
        '"email": "rahim.store@example.com"} Authorization: Bearer $token refresh_token=r3fr3shT0k3nV4lu3XYZ0987654321');
    await CrashReporter.instance.report(error, StackTrace.fromString('#0 LoginScreen._submit (package:abos_mobile/x.dart:42:7)'));

    final r = sent.single;
    final message = r['message'] as String;
    for (final secret in ['hunter22', '01712345678', 'rahim.store@example.com', token, 'r3fr3shT0k3nV4lu3XYZ0987654321']) {
      expect(message, isNot(contains(secret)), reason: '⛔ "$secret" ক্র্যাশের খবরে গেল');
    }
    // ⓘ সারানোর জন্য যা লাগে তা থাকে — ধরন, পথ, আর stack-এর ফাইল-লাইন
    expect(message, contains('/api/v1/auth/login'));
    expect(r['stack'], contains('package:abos_mobile/x.dart:42:7'));
  });

  test('the scrubber keeps an ordinary message and a status code as they are', () {
    expect(CrashReporter.scrub('RangeError (index): Invalid value: Not in inclusive range 0..3: 7'),
        'RangeError (index): Invalid value: Not in inclusive range 0..3: 7');
    expect(CrashReporter.scrub('DioException [bad response]: status code: 422'), 'DioException [bad response]: status code: 422');
    expect(CrashReporter.scrub('+8801912345678 and 8801812345678'), '***phone*** and ***phone***');
  });
}
