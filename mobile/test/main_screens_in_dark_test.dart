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

  // ⭐ লগইন অন্ধকার মোডেও মুক্তা সাদা — মালিকের পছন্দ (১ অক্টোবর ২০২৬: অ্যাপের "প্রিমিয়াম ৫", ওয়েবের "মুক্তা সাদা"-র মতো; অন্ধকার বা
  // কাচের লগইন মালিক চাননি — memory owner-login-design-pearl)। ⛔ অন্ধকারের রং মালিকের অনুমতি ছাড়া নয়: পাতা, কার্ড আর ঘর মুক্তার রঙে,
  // শুভেচ্ছা গাঢ় কালিতে; লেখা পটে WCAG AA (নিচের textContrastGuideline-এর 'login' ঘর) — goldens/screens/login_dark.png এই ছবিই
  testWidgets('login in dark stays pearl (the owner choice, 1 Oct 2026): page, card and fields light, the words dark ink',
      (tester) async {
    setScreenSize(tester, const Size(390, 844));
    await tester.pumpWidget(loginScreen());
    await tester.pumpAndSettle();
    expect(Theme.of(tester.element(find.byKey(const ValueKey('login-card')))).brightness, Brightness.dark,
        reason: 'পরীক্ষাটা সত্যিই অন্ধকার মোডে');
    final page = tester.widget<Scaffold>(find.byType(Scaffold).first).backgroundColor!;
    final card = (tester.widget<Container>(find.byKey(const ValueKey('login-card'))).decoration! as BoxDecoration).color!;
    final field = tester
        .widget<InputDecorator>(find.descendant(of: find.byKey(const ValueKey('login-identifier')), matching: find.byType(InputDecorator)))
        .decoration
        .fillColor!;
    final greeting = tester.widget<Text>(find.byKey(const ValueKey('login-greeting'))).style!.color!;
    // ⓘ মুক্তার রং: পাতা #EEF5F7, কার্ড সাদা ৮৫%, ঘর সাদা; কালি #0B2A31
    expect(page, const Color(0xFFEEF5F7), reason: '⛔ অন্ধকারে লগইনের পাতা মুক্তা নয়: $page');
    expect(card.computeLuminance(), closeTo(1, 0.001), reason: '⛔ অন্ধকারে লগইনের কার্ড মুক্তা-সাদা নয়: $card');
    expect(card.a, closeTo(0.85, 0.005), reason: '⛔ কার্ড মুক্তার ৮৫% সাদা নয়: $card');
    expect(field.computeLuminance(), closeTo(1, 0.001), reason: '⛔ অন্ধকারে লগইনের ঘর সাদা নয়: $field');
    expect(greeting, const Color(0xFF0B2A31), reason: '⛔ শুভেচ্ছা মুক্তার কালিতে নয়: $greeting');
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
