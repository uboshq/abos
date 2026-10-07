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
}
