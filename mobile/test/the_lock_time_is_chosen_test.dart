import 'package:abos_mobile/core/privacy/app_lock.dart';
import 'package:abos_mobile/core/privacy/lock_delay.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ অ্যাপ-তালার সময় বাছা যায় — অ্যাপের নিরাপত্তা, OWASP MASVS-AUTH-3, ১৯ অক্টোবর ২০২৬ ([[LockDelay]], [[AppLock]])।
///
/// <p>⛔ আগে ৫ মিনিটে বাঁধা। দাবি:
///  - পছন্দ চারটা (সাথে সাথে, ১, ৫, ১৫ মিনিট); তালিকার বাইরে কিছু নেওয়া হয় না, পড়া না গেলে ৫;
///  - তালা ফেরার মুহূর্তে বাছাইটা মানে — ১ মিনিটে কড়া, ১৫-তে ঢিলে, "সাথে সাথে"-তে প্রতিবার;
///  - যাচাই চলার সময় ফোনের PIN-পর্দায় যাওয়া "বাইরে যাওয়া" নয় — "সাথে সাথে"-তে তালা চিরকাল ঘোরে না।
void main() {
  group('the choice', () {
    test('an unknown or unreadable stored value falls back to five minutes, never to looser', () async {
      for (final stored in [null, '', 'abc', '7', '-1', '999']) {
        final d = LockDelay(read: () async => stored, write: (_) async {});
        await d.load();
        expect(d.current.value, const Duration(minutes: 5), reason: '⛔ "$stored" থেকে ৫ মিনিট ছাড়া অন্য কিছু');
      }

      final broken = LockDelay(read: () async => throw StateError('keystore'), write: (_) async {});
      await broken.load();
      expect(broken.current.value, const Duration(minutes: 5));
    });

    test('a chosen time is kept and read back; one outside the list is refused', () async {
      String? saved;
      final d = LockDelay(read: () async => saved, write: (v) async => saved = v);

      await d.choose(1);
      expect(saved, '1');
      expect(d.current.value, const Duration(minutes: 1));

      await d.choose(60);
      expect(saved, '1', reason: '⛔ তালিকার বাইরের সময় বসে গেল');
      expect(d.current.value, const Duration(minutes: 1));

      final again = LockDelay(read: () async => saved, write: (_) async {});
      await again.load();
      expect(again.current.value, const Duration(minutes: 1), reason: '⛔ বাছাই পরের চালুতে হারাল');
    });

    test('the choices are the four, with no "never", and read in Bangla', () {
      expect(LockDelay.choices, [0, 1, 5, 15]);
      expect(LockDelay.label(0), 'সাথে সাথে');
      expect(LockDelay.label(15), '১৫ মিনিট');
    });
  });

  group('the lock follows the choice', () {
    late DateTime now;
    late Duration limit;

    AppLock lock({Future<bool> Function()? authenticate}) => AppLock(
          startLocked: false,
          authenticate: authenticate ?? () async => true,
          deviceSupported: () async => true,
          clock: () => now,
          idleLimitOf: () => limit,
        )..signedIn = true;

    void away(AppLock l, Duration d) {
      l.didChangeAppLifecycleState(AppLifecycleState.paused);
      now = now.add(d);
      l.didChangeAppLifecycleState(AppLifecycleState.resumed);
    }

    setUp(() => now = DateTime(2026, 10, 19, 23));

    test('one minute locks after two minutes away; fifteen does not after ten', () {
      limit = const Duration(minutes: 1);
      final strict = lock();
      away(strict, const Duration(minutes: 2));
      expect(strict.locked, isTrue, reason: '⛔ ১ মিনিট বাছার পরেও ২ মিনিট বাইরে থেকে খোলা');

      limit = const Duration(minutes: 15);
      final loose = lock();
      away(loose, const Duration(minutes: 10));
      expect(loose.locked, isFalse, reason: '১৫ মিনিট বাছার পরেও ১০ মিনিটে তালা');
    });

    test('"at once" locks on every return', () {
      limit = Duration.zero;
      final l = lock();
      away(l, const Duration(seconds: 1));
      expect(l.locked, isTrue, reason: '⛔ "সাথে সাথে" বাছার পরেও ফিরে খোলা');
    });

    test('the phone\'s own PIN screen during an unlock is not "away" — "at once" does not lock again', () async {
      limit = Duration.zero;
      late AppLock l;
      l = lock(authenticate: () async {
        // ⓘ ফোনের PIN-পর্দা অ্যাপকে পেছনে পাঠায়; মানুষ PIN দেন — যাচাই শেষ হয়, তারপর অ্যাপ ফেরে (নিচে)
        l.didChangeAppLifecycleState(AppLifecycleState.paused);
        now = now.add(const Duration(seconds: 5));
        return true;
      });
      away(l, const Duration(seconds: 1));
      expect(l.locked, isTrue);

      await l.unlock();
      expect(l.locked, isFalse);
      l.didChangeAppLifecycleState(AppLifecycleState.resumed);
      expect(l.locked, isFalse, reason: '⛔ যাচাই শেষে ফিরেই আবার তালা — "সাথে সাথে"-তে অ্যাপ আর খোলেই না');
    });
  });
}
