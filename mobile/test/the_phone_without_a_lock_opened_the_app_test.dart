import 'package:abos_mobile/core/privacy/app_lock.dart';
import 'package:abos_mobile/core/privacy/app_pin.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ ফোনে তালা না থাকলে অ্যাপের নিজের PIN (পূর্ণ নিরীক্ষা §৯ ⚠️১২, ১৮ অক্টোবর ২০২৬; OWASP MASVS-AUTH)।
///
/// <p>⛔ আগে ফোনে PIN/আঙুল না থাকলে অ্যাপ-তালা খুলে দিত — খোলা ফোন হাতে পেলে যে কেউ অনুমোদন-বাক্সে সই দিতেন।
///
/// <p>⭐ দাবি: পাসওয়ার্ডে ঢোকার পরপরই PIN বসাতে হয়; ফিরে এলে PIN ছাড়া খোলে না; ভুল PIN খোলে না, পাঁচবারের পরে অপেক্ষা (বের করে দেওয়া নয়, ২০ নভেম্বর ২০২৬);
/// PIN না থাকা পুরনো সেশন পাসওয়ার্ড চায়; PIN নিজে কোথাও রাখা হয় না।
void main() {
  late Map<String, String> store;
  late DateTime now;
  // ⭐ একঘেয়ে সময় — অপেক্ষা কেবল এটা দিয়ে কমে; ফোনের ঘড়ি ([now]) নয়
  late Duration mono;

  AppPin pin() => AppPin(
      read: (k) async => store[k], write: (k, v) async => v == null ? store.remove(k) : store[k] = v, elapsed: () => mono);

  /// সময় সত্যিই পেরোনো — ঘড়ি আর একঘেয়ে সময় দুটোই এগোয়
  void tick(Duration d) {
    now = now.add(d);
    mono += d;
  }

  AppLock lock({bool startLocked = false}) => AppLock(
        startLocked: startLocked,
        authenticate: () async => fail('⛔ তালাহীন ফোনে ফোনের যাচাই চাওয়া হলো'),
        deviceSupported: () async => false,
        clock: () => now,
        pin: pin(),
      );

  void away(AppLock l) {
    l.didChangeAppLifecycleState(AppLifecycleState.paused);
    tick(AppLock.idleLimit);
    l.didChangeAppLifecycleState(AppLifecycleState.resumed);
  }

  setUp(() {
    store = {};
    now = DateTime(2026, 10, 18, 10);
    mono = const Duration(hours: 3);
  });

  test('the PIN is kept only as a salted slow hash, Bangla digits work, and 3 or 7 digits are refused', () async {
    final p = pin();
    expect(await p.set('123'), isFalse);
    expect(await p.set('1234567'), isFalse);
    expect(await p.set('১২৩৪'), isTrue);
    expect(store.values.join(), isNot(contains('1234')), reason: '⛔ PIN নিজেই ভাণ্ডারে');
    expect(store.values.first, startsWith('v1:'));
    expect(await p.check('1234'), isTrue);
    expect(await p.check('4321'), isFalse);
  });

  test('right after a password sign-in a phone with no lock must set a PIN first', () async {
    final l = lock();
    await l.prepare();
    expect(l.needsNewPin, isTrue, reason: '⛔ PIN ছাড়াই কাজ শুরু');
    expect(await l.setPin('1234', '1243'), isNotNull, reason: 'দুইবার আলাদা PIN মেনে নিল');
    expect(await l.setPin('12', '12'), isNotNull);
    expect(await l.setPin('2580', '২৫৮০'), isNull);
    expect(l.needsNewPin, isFalse);
  });

  test('coming back after the idle limit asks the app PIN; a wrong one keeps it shut, the right one opens', () async {
    final l = lock();
    await l.prepare();
    await l.setPin('2580', '2580');

    away(l);
    await l.unlock();
    expect(l.locked, isTrue, reason: '⛔ তালাহীন ফোনে অ্যাপ সোজা খুলল');
    expect(l.asksPin, isTrue);

    expect(await l.submitPin('0000'), contains('আর ৪বার'));
    expect(l.locked, isTrue, reason: '⛔ ভুল PIN-এ খুলল');
    expect(await l.submitPin('2580'), isNull);
    expect(l.locked, isFalse);
  });

  // ⭐ মালিক, ২০ নভেম্বর ২০২৬: পাঁচবার ভুলে আর বের করে দেওয়া নয় — অপেক্ষা, প্রতি ভুলে দ্বিগুণ; অপেক্ষার মধ্যে ঠিক PIN-ও খোলে না
  test('five wrong PINs make the person wait, doubling, and never sign them out', () async {
    final l = lock();
    await l.prepare();
    await l.setPin('2580', '2580');
    away(l);
    await l.unlock();
    for (var i = 0; i < AppPin.maxTries; i++) {
      await l.submitPin('1111');
    }
    expect(l.tooManyTries, isTrue, reason: '⛔ অসীম চেষ্টা — চার অঙ্ক অনুমানে খোলা যেত');
    expect(l.locked, isTrue);
    expect(l.signedIn, isTrue, reason: '⛔ পাঁচবার ভুলে বের করে দিল');
    expect(await l.pinWaitLeft(), const Duration(seconds: 30));

    expect(await l.submitPin('2580'), isNotNull, reason: '⛔ অপেক্ষার মধ্যে PIN যাচাই হলো');
    expect(l.locked, isTrue);

    tick(const Duration(seconds: 31));
    expect(await l.submitPin('1111'), isNotNull);
    expect(await l.pinWaitLeft(), const Duration(minutes: 1), reason: '⛔ অপেক্ষা দ্বিগুণ হলো না');

    tick(const Duration(minutes: 2));
    expect(await l.submitPin('2580'), isNull);
    expect(l.locked, isFalse);
    expect(l.tooManyTries, isFalse);
  });

  // ⛔ রিভিউ A1, ২১ নভেম্বর ২০২৬: ফোনের তারিখ সামনে নিলে অপেক্ষা উধাও হতো, পেছনে নিলে মালিক আটকা পড়তেন
  test('moving the phone clock forward or back neither skips nor stretches the wait', () async {
    final l = lock();
    await l.prepare();
    await l.setPin('2580', '2580');
    away(l);
    await l.unlock();
    for (var i = 0; i < AppPin.maxTries; i++) {
      await l.submitPin('1111');
    }
    expect(await l.pinWaitLeft(), const Duration(seconds: 30));

    now = now.add(const Duration(days: 3));
    expect(await l.pinWaitLeft(), const Duration(seconds: 30), reason: '⛔ ফোনের তারিখ সামনে নিয়ে অপেক্ষা ফাঁকি দেওয়া গেল');
    expect(await l.submitPin('2580'), isNotNull, reason: '⛔ ঘড়ি ঘুরিয়ে অপেক্ষার মধ্যে খোলা গেল');
    expect(l.locked, isTrue);

    now = now.subtract(const Duration(days: 30));
    mono += const Duration(seconds: 10);
    expect(await l.pinWaitLeft(), const Duration(seconds: 20), reason: '⛔ ঘড়ি পেছনে নিলে অপেক্ষা বেড়ে গেল');

    mono += const Duration(seconds: 20);
    expect(await l.pinWaitLeft(), Duration.zero);
    expect(await l.submitPin('2580'), isNull);
    expect(l.locked, isFalse);
  });

  test('closing and opening the app again continues the wait — never back to zero, never more than an hour', () async {
    final first = pin();
    await first.set('2580');
    for (var i = 0; i < AppPin.maxTries; i++) {
      await first.check('1111');
    }
    mono += const Duration(seconds: 10);
    expect(await first.waitLeft(), const Duration(seconds: 20));

    // ⓘ নতুন চালু — Stopwatch আবার শূন্য থেকে
    mono = Duration.zero;
    final again = pin();
    expect(await again.waitLeft(), const Duration(seconds: 20), reason: '⛔ অ্যাপ বন্ধ করে খুললে অপেক্ষা শূন্য হলো');
    expect(await again.check('2580'), isFalse, reason: '⛔ নতুন চালুতে অপেক্ষার মধ্যে খুলল');
    expect(await again.tries(), AppPin.maxTries, reason: '⛔ অপেক্ষার মধ্যের চেষ্টা ভুল হিসেবে গোনা হলো');
    mono += const Duration(seconds: 20);
    expect(await again.check('2580'), isTrue);

    store['abos_app_pin_wait_left_ms'] = '${const Duration(days: 9).inMilliseconds}';
    expect(await pin().waitLeft(), AppPin.maxWait, reason: '⛔ ভাণ্ডারের অপেক্ষা এক ঘণ্টার বেশি মানা হলো');
  });

  test('the wait doubles and stops at one hour', () {
    expect(AppPin.waitAfter(4), Duration.zero);
    expect(AppPin.waitAfter(5), const Duration(seconds: 30));
    expect(AppPin.waitAfter(6), const Duration(minutes: 1));
    expect(AppPin.waitAfter(12), const Duration(hours: 1));
    expect(AppPin.waitAfter(40), const Duration(hours: 1));
  });

  test('a saved session with no PIN on a phone with no lock asks for the password, never a new PIN', () async {
    final l = lock(startLocked: true);
    await l.prepare();
    await l.unlock();
    expect(l.mustSignInAgain, isTrue);
    expect(l.needsNewPin, isFalse, reason: '⛔ খোলা ফোন হাতে পাওয়া কেউ নিজের PIN বসিয়ে ঢুকতেন');
    expect(l.locked, isTrue);
  });

  testWidgets('the gate: set-up cover after sign-in, PIN cover after the idle limit, five misses only wait', (tester) async {
    var signedOut = 0;
    final l = lock();
    await tester.pumpWidget(MaterialApp(home: AppLockGate(lock: l, onSignOut: () => signedOut++, child: const Text('APP'))));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('app-pin-new')), findsOneWidget, reason: '⛔ PIN বসানোর পর্দা এলো না');

    await tester.enterText(find.byKey(const ValueKey('app-pin-new-field')), '2580');
    await tester.enterText(find.byKey(const ValueKey('app-pin-new-again')), '2580');
    await tester.tap(find.byKey(const ValueKey('app-pin-save')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('app-pin-new')), findsNothing);

    away(l);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('app-pin-enter')), findsOneWidget, reason: '⛔ ফিরে এলে PIN চাইল না');

    for (var i = 0; i < AppPin.maxTries; i++) {
      await tester.enterText(find.byKey(const ValueKey('app-pin-field')), '1111');
      await tester.tap(find.byKey(const ValueKey('app-pin-open')));
      await tester.pumpAndSettle();
    }
    expect(signedOut, 0, reason: '⛔ পাঁচবার ভুলে বের করে দিল — তালা মানে তালা (মালিক, ২০ নভেম্বর ২০২৬)');
    expect(find.byKey(const ValueKey('app-pin-enter')), findsOneWidget);
    expect(find.byKey(const ValueKey('app-pin-error')), findsOneWidget, reason: '⛔ অপেক্ষার কথা দেখাল না');
  });
}
