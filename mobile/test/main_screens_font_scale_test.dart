import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart';

/// ⭐ বড় লেখায় পর্দা ভাঙে না — ফোনের "ফন্টের মাপ" ১৩০% আর ২০০%-এ মূল পর্দাগুলো (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)।
///
/// <p>কম দেখেন এমন দোকানি বা বিক্রয়কর্মী ফোনের লেখা বড় করে রাখেন। তখন সারি ভাঙা, অঙ্ক কাটা বা হলুদ-কালো ডোরা মানে
/// তাঁর কাছে পর্দাটাই নেই। লেখা মোড়ায়, দরকারে ছোট হয় বা "…" দিয়ে থামে, আর পাতা নিচে গড়ায়।
///
/// <p>ছবিগুলো `test/goldens/`-এ জমা — লেআউট বদলালে লাল। ⓘ পরীক্ষার ফন্টে প্রতিটা অক্ষর একটা চৌকো ঘর, আসল বাংলার
/// চেয়ে চওড়া: এখানে যা ধরে, ফোনে তা আরও আরামে ধরে। ছবিতে বাংলা অক্ষর চৌকো দেখায় — এটা পরীক্ষার ফন্ট, ভুল নয়।
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

  final screens = <String, Widget Function({double textScale})>{
    'login': loginScreen,
    'home': homeShell,
    'customer_list': customerList,
    'customer_detail': customerDetail,
    'new_order': newOrder,
    'collection': collect,
    'ledger': customerLedger,
    'printer_settings': printerSettings,
  };

  /// [build] চালায় আর যত ভাঙন (RenderFlex overflow, ListTile-এর জায়গা ফুরানো) হলো তার তালিকা দেয়
  Future<List<String>> collectBreaks(Future<void> Function() build) async {
    final breaks = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      final text = details.exceptionAsString();
      if (text.contains('overflowed') || text.contains('consumes the entire tile width')) {
        breaks.add('${text.split('\n').first} @ ${_where(details)}');
      } else {
        previous?.call(details);
      }
    };
    try {
      await build();
    } finally {
      FlutterError.onError = previous;
    }
    return breaks;
  }

  Future<List<String>> open(WidgetTester tester, Widget app) => collectBreaks(() async {
        await tester.runAsync(seedMainData);
        await tester.pumpWidget(app);
        await tester.pumpAndSettle();
      });

  Future<List<String>> openSettings(WidgetTester tester, double scale) => collectBreaks(() async {
        await tester.pumpWidget(homeShell(textScale: scale));
        await tester.pumpAndSettle();
        // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): "আমি" — নিজের ছবি — নিচের বারের শেষ ঘর; চাপলে "আমি" পাতা
        await tester.tap(find.byKey(const ValueKey('nav-me')));
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('me-page')), findsOneWidget, reason: '⛔ "আমি" পাতা খোলেনি — অন্য পাতা মাপা হতো');
      });

  // ⛔ বড় লেখায় বোতামের লেখা বোতামের ভেতরে, উপরে-নিচে-পাশে অন্তত ৮dp ফাঁক রেখে — দুই লাইনে ভাঙলে বোতাম বাড়ে, লেখা কিনারা ছোঁয় না
  // (ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬: login_200-এ "সাইন ইন"-এর উপরের লাইন বোতামের উপরের কিনারায় লেগে ছিল)
  testWidgets('login: at 200% text the sign-in label lies inside its button, clear of every edge', (tester) async {
    setScreenSize(tester, const Size(390, 844));
    await open(tester, loginScreen(textScale: 2.0));
    _expectClearInside(tester, find.byKey(const ValueKey('login-submit')), find.byKey(const ValueKey('login-submit-label')));
  });

  // ⛔ টাকা নেওয়ার নিচের দুই বোতাম: ১৩০% আর ২০০%-এ লেখা দুই লাইনে ভাঙলেও বোতামের ভেতরে (ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬:
  // collection_130/200-এ শেষ অক্ষর বোতামের নিচের কিনারা ছুঁত)
  for (final scale in [1.3, 2.0]) {
    testWidgets('collection: at ${(scale * 100).round()}% text the bottom buttons hold their labels clear of every edge', (tester) async {
      setScreenSize(tester, const Size(390, 844));
      await open(tester, collect(textScale: scale));
      for (final key in ['collect-draft', 'collect-post']) {
        final button = find.byKey(ValueKey(key));
        _expectClearInside(tester, button, find.descendant(of: button, matching: find.byType(Text)));
      }
    });
  }

  // ⛔ নতুন অর্ডারের − আর +: ২০০%-এ চিহ্ন তার গোল বোতামের বৃত্তের ভেতরে (ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬: new_order_200-এ লেখার
  // − আর + বৃত্তের চেয়ে বড়, কোনা বাইরে)। চিহ্নের ঘরের চার কোনাই বৃত্তের ভেতরে
  testWidgets('new order: at 200% text the − and + glyphs lie inside their round buttons', (tester) async {
    setScreenSize(tester, const Size(390, 844));
    await open(tester, newOrder(textScale: 2.0));
    for (final key in ['order-minus-$soapId', 'order-plus-$soapId']) {
      final button = find.byKey(ValueKey(key));
      final circle = tester.getRect(button);
      final glyphs = find.descendant(of: button, matching: find.byWidgetPredicate((w) => w is Icon || w is Text));
      expect(glyphs, findsOneWidget, reason: '$key: একটাই চিহ্ন');
      final glyph = tester.getRect(glyphs);
      final radius = circle.shortestSide / 2;
      for (final corner in [glyph.topLeft, glyph.topRight, glyph.bottomLeft, glyph.bottomRight]) {
        expect((corner - circle.center).distance <= radius, isTrue,
            reason: '⛔ $key: চিহ্ন $glyph বোতামের বৃত্তের (কেন্দ্র ${circle.center}, ব্যাসার্ধ $radius) বাইরে');
      }
    }
  });

  for (final screen in screens.entries) {
    testWidgets('${screen.key}: nothing overflows at 200% text', (tester) async {
      setScreenSize(tester, const Size(390, 844));
      final breaks = await open(tester, screen.value(textScale: 2.0));
      expect(breaks, isEmpty, reason: '⛔ ২০০% লেখায় পর্দা ভাঙল: ${breaks.join(' | ')}');
    });

    for (final scale in [1.3, 2.0]) {
      final pct = (scale * 100).round();
      testWidgets('${screen.key}: golden at $pct% text', (tester) async {
        setScreenSize(tester, const Size(390, 844));
        final breaks = await open(tester, screen.value(textScale: scale));
        expect(breaks, isEmpty, reason: '⛔ ছবিতে ডোরা থাকত: ${breaks.join(' | ')}');
        await expectLater(find.byType(MaterialApp), matchesGoldenFile('goldens/${screen.key}_$pct.png'));
      });
    }
  }

  // "আমি" — সেটিংসের পাতা
  testWidgets('settings (আমি): nothing overflows at 200% text', (tester) async {
    setScreenSize(tester, const Size(390, 844));
    final breaks = await openSettings(tester, 2.0);
    expect(breaks, isEmpty, reason: '⛔ ২০০% লেখায় "আমি" ভাঙল: ${breaks.join(' | ')}');
  });

  for (final scale in [1.3, 2.0]) {
    final pct = (scale * 100).round();
    testWidgets('settings (আমি): golden at $pct% text', (tester) async {
      setScreenSize(tester, const Size(390, 844));
      final breaks = await openSettings(tester, scale);
      expect(breaks, isEmpty);
      await expectLater(find.byType(MaterialApp), matchesGoldenFile('goldens/settings_$pct.png'));
    });
  }
}

/// [inner]-এর ঘর [outer]-এর ঘরের ভেতরে, প্রতিটা কিনারা থেকে অন্তত [gap] dp দূরে
void _expectClearInside(WidgetTester tester, Finder outer, Finder inner, {double gap = 8}) {
  final o = tester.getRect(outer);
  final i = tester.getRect(inner);
  final room = (top: i.top - o.top, bottom: o.bottom - i.bottom, left: i.left - o.left, right: o.right - i.right);
  expect(room.top >= gap && room.bottom >= gap && room.left >= gap && room.right >= gap, isTrue,
      reason: '⛔ লেখা $i ঘরের $o কিনারায় লাগে বা বাইরে — ফাঁক $room, চাই অন্তত $gap');
}

/// কোন পর্দার কোন লাইনে ভাঙল — লাল হলে খুঁজতে হয় না
String _where(FlutterErrorDetails details) {
  final at = RegExp(r'lib/features/[^\s)]+').allMatches(details.toString()).map((m) => m[0]!).toSet();
  return at.isEmpty ? '?' : at.join(', ');
}
