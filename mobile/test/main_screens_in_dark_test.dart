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
