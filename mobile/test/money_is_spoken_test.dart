import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart';

/// ⭐ TalkBack টাকা বলে মুখের ভাষায় — "১২ লাখ ৪৮ হাজার ১২৫ টাকা ৫০ পয়সা", পর্দার "৳12,48,125.50" কমায় কমায় ভেঙে নয়
/// (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)। ⓘ পর্দার লেখা যেমন ছিল তেমনই।
void main() {
  test('an amount is said in crore, lakh, thousand, taka and paisa', () {
    expect(Money.spoken('1248125.5000'), '১২ লাখ ৪৮ হাজার ১২৫ টাকা ৫০ পয়সা');
    expect(Money.spoken('123456789.0'), '১২ কোটি ৩৪ লাখ ৫৬ হাজার ৭৮৯ টাকা');
    expect(Money.spoken(40), '৪০ টাকা');
    expect(Money.spoken('0'), '০ টাকা');
    expect(Money.spoken('0.75'), '০ টাকা ৭৫ পয়সা');
    expect(Money.spoken('-5000'), 'ঋণাত্মক ৫ হাজার টাকা');
    expect(Money.spoken(null), isNull);
  });

  test('every taka figure inside a sentence is said, the rest is kept', () {
    expect(Money.speakable('বকেয়া ৳8,125.50'), 'বকেয়া ৮ হাজার ১২৫ টাকা ৫০ পয়সা');
    expect(Money.speakable('মোট ৳১,২৫০ · ২ টি'), 'মোট ১ হাজার ২৫০ টাকা · ২ টি');
    expect(Money.speakable('কিছু নেই'), 'কিছু নেই');
  });

  group('on the screens', () {
    late HiveTestHarness harness;

    setUpAll(() async {
      FakeSecureStorage.install();
      harness = await HiveTestHarness.setUp();
      await ReferenceCache.instance.init();
    });

    tearDownAll(() async => harness.tearDown());

    testWidgets('the customer list says the due and the total in words', (tester) async {
      setScreenSize(tester, const Size(390, 844));
      final handle = tester.ensureSemantics();
      await tester.runAsync(seedMainData);
      await tester.pumpWidget(customerList());
      await tester.pumpAndSettle();

      expect(find.bySemanticsLabel(RegExp('বকেয়া ১২ লাখ ৪৮ হাজার ১২৫ টাকা ৫০ পয়সা')), findsOneWidget,
          reason: '⛔ বকেয়ার অঙ্ক কমায় ভাঙা সংখ্যা হয়ে পড়া হয়');
      // ⓘ মোটের পট্টি এখন নিট (বকেয়া − অগ্রিম), ওয়েবের মতো (০.৪.৪৮); এখানে অগ্রিম নেই, তাই অঙ্ক একই
      expect(find.bySemanticsLabel(RegExp('নিট বকেয়া.*১২ লাখ ৪৮ হাজার ১২৫ টাকা ৫০ পয়সা', dotAll: true)), findsOneWidget,
          reason: '⛔ মোটের পট্টি অঙ্কটা কমায় ভাঙা সংখ্যা হয়ে বলে');
      handle.dispose();
    });

    testWidgets('the customer page says the due in words', (tester) async {
      setScreenSize(tester, const Size(390, 844));
      final handle = tester.ensureSemantics();
      await tester.runAsync(seedMainData);
      await tester.pumpWidget(customerDetail());
      await tester.pumpAndSettle();

      expect(find.bySemanticsLabel(RegExp('বকেয়া ১২ লাখ ৪৮ হাজার ১২৫ টাকা ৫০ পয়সা')), findsOneWidget);
      expect(find.byKey(const ValueKey('customer-due-card')), findsOneWidget);
      handle.dispose();
    });
  });
}
