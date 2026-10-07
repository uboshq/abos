import 'package:abos_mobile/core/privacy/app_lock.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ অ্যাপ-তালা — সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬ (খোলা ফোন হারালে অনুমোদন আর টাকার অঙ্ক খোলা)।
void main() {
  late DateTime now;
  late int asked;
  late bool answer;

  AppLock lock({bool startLocked = true, bool hasLock = true}) => AppLock(
        startLocked: startLocked,
        authenticate: () async {
          asked++;
          return answer;
        },
        deviceSupported: () async => hasLock,
        clock: () => now,
      );

  setUp(() {
    now = DateTime(2026, 10, 7, 10);
    asked = 0;
    answer = true;
  });

  test('a saved session opens locked; the phone\'s own check opens it, a failed check keeps it shut', () async {
    final l = lock();
    expect(l.locked, isTrue);

    answer = false;
    await l.unlock();
    expect(l.locked, isTrue, reason: '⛔ ভুল আঙুলেও খুলল');

    answer = true;
    await l.unlock();
    expect(l.locked, isFalse);
  });

  test('away less than the limit stays open; away longer locks again', () {
    final l = lock(startLocked: false);

    l.didChangeAppLifecycleState(AppLifecycleState.paused);
    now = now.add(const Duration(minutes: 4));
    l.didChangeAppLifecycleState(AppLifecycleState.resumed);
    expect(l.locked, isFalse, reason: 'চার মিনিটে তালা — রোজকার কাজ আটকাত');

    l.didChangeAppLifecycleState(AppLifecycleState.paused);
    now = now.add(AppLock.idleLimit);
    l.didChangeAppLifecycleState(AppLifecycleState.resumed);
    expect(l.locked, isTrue, reason: '⛔ অনেকক্ষণ পরে ফিরেও খোলা');
  });

  test('signed out there is nothing to lock', () {
    final l = lock()..signedIn = false;
    expect(l.locked, isFalse);
  });

  test('a phone with no lock of its own is not shut out, and says so', () async {
    final l = lock(hasLock: false);
    await l.unlock();
    expect(l.locked, isFalse);
    expect(l.noDeviceLock, isTrue);
    expect(asked, 0);
  });

  testWidgets('the gate covers the app while locked, asks once by itself, and the button asks again', (tester) async {
    answer = false;
    final l = lock();
    await tester.pumpWidget(MaterialApp(home: AppLockGate(lock: l, child: const Text('APP'))));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('app-lock')), findsOneWidget);
    expect(asked, 1, reason: 'নিজে থেকে একবার চাওয়া হয়নি');

    answer = true;
    await tester.tap(find.byKey(const ValueKey('app-lock-open')));
    await tester.pumpAndSettle();
    expect(asked, 2);
    expect(find.byKey(const ValueKey('app-lock')), findsNothing);
    expect(find.text('APP'), findsOneWidget);
  });
}
