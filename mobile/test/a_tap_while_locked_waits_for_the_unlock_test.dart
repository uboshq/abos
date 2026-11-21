import 'package:abos_mobile/core/privacy/app_lock.dart';
import 'package:abos_mobile/core/privacy/app_pin.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⛔ রিভিউ A3, ২১ নভেম্বর ২০২৬: তালা থাকা অবস্থায় বার্তায় চাপলে সাথে সাথে `go(path)` — তালার পর্দার নিচে পাতা খুলত,
/// তথ্য আনত, "পড়া হলো" দাগাত, অনুমোদন আনত।
///
/// <p>⭐ দাবি: তালা থাকলে (ঠান্ডা শুরু) বা ফিরলেই পড়বে (গরম ফেরা, `resumed`-এর আগে চাপ) পথ ধরে রাখা, খোলার পরপরই একবার;
/// খোলা অবস্থায় সাথে সাথে; বেরোলে ধরা পথ মোছে।
void main() {
  late DateTime now;
  late bool answer;
  late List<String> opened;

  AppLock lock({bool startLocked = false}) {
    final store = <String, String>{};
    return AppLock(
      startLocked: startLocked,
      authenticate: () async => answer,
      deviceSupported: () async => true,
      clock: () => now,
      pin: AppPin(read: (k) async => store[k], write: (k, v) async => v == null ? store.remove(k) : store[k] = v),
    );
  }

  setUp(() {
    now = DateTime(2026, 11, 21, 9);
    answer = true;
    opened = [];
  });

  test('cold start: a tap that opened a locked app waits for the unlock, then opens once', () async {
    final l = lock(startLocked: true);
    l.openWhenUnlocked('/home/approvals/7', opened.add);
    expect(opened, isEmpty, reason: '⛔ তালার নিচে পাতা খুলল');
    expect(l.holdsATap, isTrue);

    answer = false;
    await l.unlock();
    expect(opened, isEmpty, reason: '⛔ যাচাই ব্যর্থ, তবু পাতা খুলল');

    answer = true;
    await l.unlock();
    expect(l.locked, isFalse);
    expect(opened, ['/home/approvals/7'], reason: '⛔ খোলার পরে ধরা পাতা খুলল না');
    await l.unlock();
    expect(opened, hasLength(1), reason: '⛔ একই চাপ দুবার খুলল');
  });

  test('warm resume: a tap that arrives before "resumed" after a long time away is held too', () async {
    final l = lock();
    l.didChangeAppLifecycleState(AppLifecycleState.paused);
    now = now.add(AppLock.idleLimit + const Duration(minutes: 1));

    l.openWhenUnlocked('/home/notifications', opened.add);
    expect(opened, isEmpty, reason: '⛔ তালা পড়ার ঠিক আগে পাতা খুলে গেল');

    l.didChangeAppLifecycleState(AppLifecycleState.resumed);
    expect(l.locked, isTrue);
    expect(opened, isEmpty);

    await l.unlock();
    expect(opened, ['/home/notifications']);
  });

  test('a short trip away does not hold the tap', () {
    final l = lock();
    l.didChangeAppLifecycleState(AppLifecycleState.paused);
    now = now.add(const Duration(seconds: 5));
    l.openWhenUnlocked('/home/notifications', opened.add);
    expect(opened, ['/home/notifications']);
  });

  test('an open app opens the tap at once', () {
    final l = lock();
    l.openWhenUnlocked('/home/sales', opened.add);
    expect(opened, ['/home/sales']);
  });

  test('signing out from the lock screen drops the held tap — never opened for the next person', () async {
    final l = lock(startLocked: true);
    l.openWhenUnlocked('/home/approvals/7', opened.add);
    l.signedIn = false;
    expect(l.holdsATap, isFalse);
    l.signedIn = true;
    await l.prepare();
    expect(opened, isEmpty, reason: '⛔ আগের জনের চাপা পাতা পরের জনের জন্য খুলল');
  });
}
