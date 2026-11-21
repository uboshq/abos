import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart';

/// ⭐ TalkBack আর আঙুলের মাপ — মূল পর্দাগুলো Android-এর নিজের নির্দেশিকায় (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)।
///
/// <p>প্রতিটা চাপার জায়গা অন্তত ৪৮×৪৮ dp ([androidTapTargetGuideline]), প্রতিটা চাপার জায়গার একটা নাম আছে যা পর্দা-পাঠক
/// পড়ে ([labeledTapTargetGuideline]), আর লেখা পটভূমির ওপর পড়া যায় ([textContrastGuideline], WCAG AA)।
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  tearDownAll(() async {
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

  final guidelines = <String, AccessibilityGuideline>{
    'tap targets are 48 dp': androidTapTargetGuideline,
    'every tap target has a label': labeledTapTargetGuideline,
    'text contrast': textContrastGuideline,
  };

  // ⓘ থিমের রঙের অপেক্ষায় থাকা পরীক্ষা — এখন কিছু নেই: লগইনের "মুক্তা সাদা"-র তিন লেখার রং ৪.৫:১-এ গাঢ় হয়েছে
  // (পট হুবহু; a4, ২০ অক্টোবর ২০২৬)।
  const themeHolds = <String>{};

  for (final screen in screens.entries) {
    testWidgets('${screen.key}: every text field has a label TalkBack reads', (tester) async {
      setScreenSize(tester, const Size(390, 844));
      final handle = tester.ensureSemantics();
      await tester.runAsync(seedMainData);
      await tester.pumpWidget(screen.value());
      await tester.pumpAndSettle();
      final unnamed = <String>[];
      for (final element in find.byType(EditableText).evaluate()) {
        final data = tester.getSemantics(find.byWidget(element.widget)).getSemanticsData();
        if (data.label.trim().isEmpty) unnamed.add(data.toString());
      }
      expect(unnamed, isEmpty, reason: '⛔ নামহীন লেখার ঘর — TalkBack কেবল "সম্পাদনা বাক্স" বলে');
      handle.dispose();
    });

    for (final g in guidelines.entries) {
      final name = '${screen.key}: ${g.key}';
      testWidgets(name, skip: themeHolds.contains(name), (tester) async {
        setScreenSize(tester, const Size(390, 844));
        final handle = tester.ensureSemantics();
        await tester.runAsync(seedMainData);
        await tester.pumpWidget(screen.value());
        await tester.pumpAndSettle();
        await expectLater(tester, meetsGuideline(g.value));
        handle.dispose();
      });
    }
  }

  // ⓘ নামওয়ালা বোতাম চাপাও যায় — TalkBack-এ দুইবার ছুঁলে কমে-বাড়ে (নাম দিতে গিয়ে চাপটা মুছে ফেলা যায় না)
  testWidgets('new order: − and + and the quantity are named buttons that can be pressed', (tester) async {
    setScreenSize(tester, const Size(390, 844));
    final handle = tester.ensureSemantics();
    await tester.runAsync(seedMainData);
    await tester.pumpWidget(newOrder());
    await tester.pumpAndSettle();
    expect(tester.getSemantics(find.byKey(const ValueKey('order-minus-$soapId'))),
        matchesSemantics(label: 'কমান', isButton: true, hasTapAction: true, hasFocusAction: true, isFocusable: true));
    expect(tester.getSemantics(find.byKey(const ValueKey('order-plus-$soapId'))),
        matchesSemantics(label: 'বাড়ান', isButton: true, hasTapAction: true, hasFocusAction: true, isFocusable: true));
    expect(
        tester.getSemantics(find.byKey(const ValueKey('order-qty-$soapId'))),
        matchesSemantics(
            label: 'পরিমাণ',
            value: '0', // ⓘ ইংরেজি অঙ্ক বাংলাতেও (মালিকের নিয়ম; a4, ২১ অক্টোবর ২০২৬)
            onTapHint: 'সংখ্যা লিখুন',
            isButton: true,
            hasTapAction: true,
            hasFocusAction: true,
            isFocusable: true));
    handle.dispose();
  });

  // "আমি" — নিজের প্রোফাইল (আইটেম ১০, ২১ নভেম্বর ২০২৬: মলাট, ছবি, "প্রোফাইল ঠিক করুন", নিরাপত্তা, চেহারা)
  for (final g in guidelines.entries) {
    testWidgets('settings (আমি): ${g.key}', (tester) async {
      setScreenSize(tester, const Size(390, 844));
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(homeShell());
      await tester.pumpAndSettle();
      // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): "আমি" — নিজের ছবি — নিচের বারের শেষ ঘর; চাপলে "আমি" পাতা
      await tester.tap(find.byKey(const ValueKey('nav-me')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('me-page-10')), findsOneWidget, reason: '⛔ "আমি" পাতা খোলেনি — অন্য পাতা মাপা হতো');
      await expectLater(tester, meetsGuideline(g.value));
      handle.dispose();
    });
  }

  // ≡ মেনু — অ্যাপের সেটিংসের পাতা এখন (সিঙ্ক, প্রিন্টার, হালনাগাদ, বেরোনো; আইটেম ১০, ২১ নভেম্বর ২০২৬); সেটিংস আর অ্যাপ সম্পর্কে খোলা
  for (final g in guidelines.entries) {
    testWidgets('settings (≡ মেনু): ${g.key}', (tester) async {
      setScreenSize(tester, const Size(390, 844));
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(homeShell());
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('top-leading')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('menu-page')), findsOneWidget, reason: '⛔ ≡ মেনুর পাতা খোলেনি — অন্য পাতা মাপা হতো');
      final menu = find.descendant(of: find.byKey(const ValueKey('menu-page')), matching: find.byType(Scrollable)).first;
      // ⓘ সাহায্যের ভাঁজ খোলস এখনো দেয় না (ডাক নেই, ভাঁজ নেই)
      for (final fold in ['settings', 'about']) {
        final head = find.byKey(ValueKey('menu-fold-$fold'));
        await tester.scrollUntilVisible(head, 150, scrollable: menu);
        await tester.pumpAndSettle();
        if (find.byKey(ValueKey('menu-fold-$fold-body')).evaluate().isEmpty) {
          await tester.tap(head);
          await tester.pumpAndSettle();
        }
      }
      // ⓘ এক পর্দায় যা দেখা যায় তা-ই মাপা — দুই জায়গায়: সেটিংসের ভাঁজ আর পাতার শেষ (বের হওয়া)
      await tester.scrollUntilVisible(find.byKey(const ValueKey('menu-sub-sync-status')), 150, scrollable: menu);
      await tester.pumpAndSettle();
      await expectLater(tester, meetsGuideline(g.value));
      await tester.scrollUntilVisible(find.byKey(const ValueKey('menu-version')), 150, scrollable: menu);
      await tester.pumpAndSettle();
      await expectLater(tester, meetsGuideline(g.value));
      handle.dispose();
    });
  }
}
