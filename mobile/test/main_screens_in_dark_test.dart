import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart';

/// ⭐ অন্ধকার মোড চালুর দাবি (০.৪.৩৯, ২০ অক্টোবর ২০২৬): মূল পর্দাগুলো অ্যাপের অন্ধকার থিমে আঁকা হলে প্রতিটা লেখা তার নিচের
/// পটের উপর পড়া যায় (Flutter-এর [textContrastGuideline], WCAG AA) — বাঁধা হালকা পটে থিমের হালকা লেখা মিলিয়ে গেলে লাল।
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
    appFrameInDark = true;
  });

  tearDownAll(() async {
    appFrameInDark = false;
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  final screens = <String, Widget Function()>{
    'login': loginScreen,
    'home': homeShell,
    'customer list': customerList,
    'customer detail': customerDetail,
    'new order': newOrder,
    'collection': collect,
    'ledger': customerLedger,
    'printer settings': printerSettings,
  };

  // ⛔ লগইনের পাতা অন্ধকারেও সাদা ছিল — তার "অন্ধকার" ছবি আসলে আলোর ছবি, অন্ধকার কখনো দেখা হয়নি (ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬)।
  // অন্ধকারে পাতা, কার্ড আর ঘর গাঢ়, লেখা হালকা
  testWidgets('login in dark: the page, the card and the fields are dark, the words light', (tester) async {
    setScreenSize(tester, const Size(390, 844));
    await tester.pumpWidget(loginScreen());
    await tester.pumpAndSettle();
    final page = tester.widget<Scaffold>(find.byType(Scaffold).first).backgroundColor!;
    final card = (tester.widget<Container>(find.byKey(const ValueKey('login-card'))).decoration! as BoxDecoration).color!;
    final field = tester
        .widget<InputDecorator>(find.descendant(of: find.byKey(const ValueKey('login-identifier')), matching: find.byType(InputDecorator)))
        .decoration
        .fillColor!;
    final greeting = tester.widget<Text>(find.byKey(const ValueKey('login-greeting'))).style!.color!;
    for (final (what, colour) in [('page', page), ('card', card), ('field', field)]) {
      expect(colour.computeLuminance(), lessThan(0.05), reason: '⛔ অন্ধকারে লগইনের $what হালকা: $colour');
    }
    expect(greeting.computeLuminance(), greaterThan(0.6), reason: '⛔ অন্ধকারে শুভেচ্ছার লেখা গাঢ়: $greeting');
  });

  for (final screen in screens.entries) {
    testWidgets('${screen.key} in dark: every text reads on what is under it', (tester) async {
      // ⓘ লম্বা পর্দা — নিচের পট্টির আড়ালে অর্ধেক ঢাকা লেখার মাপ ভুল আসে (পট্টির রেখা আর পট মাপে, লেখা নয়)
      setScreenSize(tester, const Size(390, 1800));
      final handle = tester.ensureSemantics();
      await tester.runAsync(seedMainData);
      await tester.pumpWidget(screen.value());
      await tester.pumpAndSettle();
      await expectLater(tester, meetsGuideline(textContrastGuideline));
      handle.dispose();
    });
  }
}
