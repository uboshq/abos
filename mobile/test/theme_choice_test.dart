import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/theme_choice.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart';

/// ⭐ আলো বা অন্ধকার — মানুষের নিজের বাছাই (মালিকের আদেশ, ২০ অক্টোবর ২০২৬): "ফোনের মতো" (শুরুতে), "আলো", "অন্ধকার";
/// চাপ দিলেই বদলায়, অ্যাপ আবার খুললেও থাকে, লগআউটে মোছে না।
void main() {
  late HiveTestHarness harness;
  String? stored;

  ThemeChoices store() => ThemeChoices(read: () async => stored, write: (v) async => stored = v);

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

  setUp(() {
    stored = null;
    ThemeChoices.instance = store();
  });

  test('it starts as the phone does, and an unknown stored word is the phone too', () async {
    final s = store();
    await s.load();
    expect(s.current.value, ThemeChoice.phone);
    expect(ThemeChoice.phone.mode, ThemeMode.system);
    stored = 'purple';
    await s.load();
    expect(s.current.value, ThemeChoice.phone);
  });

  test('a choice survives the app being closed and opened again', () async {
    await store().choose(ThemeChoice.dark);
    expect(stored, 'dark');

    final reopened = store();
    await reopened.load();
    expect(reopened.current.value, ThemeChoice.dark, reason: '⛔ আবার খুললে বাছাই হারাল');
  });

  Widget app(ThemeChoices choices) => ValueListenableBuilder<ThemeChoice>(
        valueListenable: choices.current,
        builder: (context, choice, _) => MaterialApp(
          debugShowCheckedModeBanner: false,
          theme: AppTheme.light(),
          darkTheme: AppTheme.dark(),
          themeMode: choice.mode,
          home: RepaintBoundary(
            key: const ValueKey('shot'),
            child: Scaffold(
              appBar: AppBar(title: const Text('আজ')),
              body: const Padding(padding: EdgeInsets.all(16), child: Card(child: ListTile(title: Text('৳48,500.00')))),
            ),
          ),
        ),
      );

  Color page(WidgetTester tester) => Theme.of(tester.element(find.byType(Scaffold))).scaffoldBackgroundColor;

  for (final (choice, phone, dark) in [
    (ThemeChoice.phone, Brightness.dark, true),
    (ThemeChoice.light, Brightness.dark, false),
    (ThemeChoice.dark, Brightness.light, true),
  ]) {
    testWidgets('${choice.name} on a ${phone.name} phone draws the ${dark ? 'dark' : 'light'} page', (tester) async {
      tester.platformDispatcher.platformBrightnessTestValue = phone;
      addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
      tester.view.physicalSize = const Size(360, 240);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      final s = store();
      await s.choose(choice);
      await tester.pumpWidget(app(s));
      await tester.pumpAndSettle();

      expect(page(tester), dark ? AppTheme.dark().scaffoldBackgroundColor : AppTheme.light().scaffoldBackgroundColor,
          reason: '⛔ বাছাই উপেক্ষা করা হলো');
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/theme/${choice.name}_on_${phone.name}_phone.png'));
    });
  }

  testWidgets('a tap changes the whole app at once, no restart', (tester) async {
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.light;
    addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);
    final s = store();
    await tester.pumpWidget(app(s));
    await tester.pumpAndSettle();
    expect(page(tester), AppTheme.light().scaffoldBackgroundColor);

    await s.choose(ThemeChoice.dark);
    await tester.pumpAndSettle();
    expect(page(tester), AppTheme.dark().scaffoldBackgroundColor);
  });

  testWidgets('the আমি page offers the three choices and a tap is the choice', (tester) async {
    setScreenSize(tester, const Size(390, 1600));
    await tester.runAsync(seedMainData);
    await tester.pumpWidget(homeShell());
    await tester.pumpAndSettle();
    // ⭐ ১৪ নভেম্বর ২০২৬: ছবি নিচের বারের "আমি" — রঙ বাছাই সেই পাতায় (আগে ছবির মেনুতে)
    await tester.tap(find.byKey(const ValueKey('nav-me')));
    await tester.pumpAndSettle();
    try {
      await tester.scrollUntilVisible(find.byKey(const ValueKey('me-theme')), 150,
          scrollable: find.descendant(of: find.byKey(const ValueKey('me-page')), matching: find.byType(Scrollable)).first);
    } on StateError {
      fail('⛔ "আমি" পাতার শেষ পর্যন্ত নেমেও রঙ বাছাইয়ের সারি নেই');
    }

    expect(find.byKey(const ValueKey('me-theme')), findsOneWidget);
    for (final c in ThemeChoice.values) {
      expect(find.byKey(ValueKey('theme-${c.name}')), findsOneWidget);
    }
    await tester.tap(find.byKey(const ValueKey('theme-dark')));
    await tester.pumpAndSettle();
    expect(ThemeChoices.instance.current.value, ThemeChoice.dark);
    expect(stored, 'dark');
    expect(tester.getSize(find.byKey(const ValueKey('theme-dark'))).height, greaterThan(0));
  });

  test('the app itself listens to the choice', () {
    final main = File('lib/main.dart').readAsStringSync();
    expect(main, contains('valueListenable: ThemeChoices.instance.current'));
    expect(main, contains('themeMode: choice.mode'));
    expect(main, contains('await ThemeChoices.instance.load();'));
  });
}
