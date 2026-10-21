import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/books/collect_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/bar_fonts.dart';
import 'support/collect_fake.dart';

/// ⭐ আদায়ের পাতার ছবি — 412dp, আলো আর অন্ধকার, পোস্টের আগের সারাংশ, আর ইংরেজিতে একটা (চূড়ান্ত নকশা ১০০, বিষয় ১৯–২০)।
/// আসল ফন্টে (Roboto + Shonar Bangla + Material Symbols), এক PC-তে বানানো। বদলালে ছবি খুলে দেখে `--update-goldens`।
final _bengali = RegExp('[ঀ-৲৴-৿]');

/// সার্ভার থেকে আসা নাম — ইংরেজিতেও বাংলা থাকে
const _serverWords = ['করিম স্টোর', 'ফুলপুর পয়েন্ট', 'ময়মনসিংহ', 'নগদ', 'ডাচ-বাংলা', 'bKash', 'পাওনা', 'পঁয়তাল্লিশ', 'প্রধান'];

Future<void> _open(WidgetTester tester, {bool dark = false, Locale locale = AppLocale.locale, double height = 2600}) async {
  tester.view.physicalSize = Size(412, height);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  AppLocale.current = locale;
  addTearDown(() => AppLocale.current = AppLocale.locale);
  await tester.pumpWidget(RepaintBoundary(
    key: const ValueKey('shot'),
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: withBarFonts(AppTheme.light()),
      darkTheme: withBarFonts(AppTheme.dark()),
      themeMode: dark ? ThemeMode.dark : ThemeMode.light,
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: CollectScreen(
        api: FakeCollect(preview: previewJson(needsSignature: false)),
        books: FakeBooks(),
        newChangeId: () => 'k',
        today: DateTime(2026, 10, 21),
        previewDelay: Duration.zero,
        openReceipt: (_, __) async {},
        pickSlip: (_, {required camera}) async => null,
      ),
    ),
  ));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('collect-customer')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('collect-pick-cus-1')));
  await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const ValueKey('collect-amount')), '45000');
  await tester.pumpAndSettle();
  final change = find.byKey(const ValueKey('collect-bills-change'));
  await tester.ensureVisible(change);
  await tester.pumpAndSettle();
  await tester.tap(change);
  await tester.pumpAndSettle();
  await tester.drag(find.byKey(const ValueKey('collect-list')), const Offset(0, 5000));
  await tester.pumpAndSettle();
  FocusManager.instance.primaryFocus?.unfocus();
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(loadBarFonts);

  for (final dark in [false, true]) {
    testWidgets('20 · the screen at 412dp, ${dark ? 'dark' : 'light'}, in Bengali', (tester) async {
      await _open(tester, dark: dark);
      expect(tester.takeException(), isNull);
      await expectLater(
          find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/collect/collect_412_${dark ? 'dark' : 'light'}_bn.png'));
    });
  }

  testWidgets('10 · the summary before posting, in Bengali', (tester) async {
    await _open(tester, height: 892);
    await tester.tap(find.byKey(const ValueKey('collect-post')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('collect-summary')), findsOneWidget);
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/collect/collect_summary_bn.png'));
  });

  testWidgets('19 · in English: no Bengali of the screen\'s own, only the server\'s names', (tester) async {
    await _open(tester, locale: AppLocale.english);
    expect(find.text('Collection'), findsWidgets);
    final texts = [
      for (final t in tester.widgetList<Text>(find.byType(Text))) t.data ?? t.textSpan?.toPlainText() ?? '',
      for (final d in tester.widgetList<InputDecorator>(find.byType(InputDecorator))) d.decoration.labelText ?? '',
    ];
    final own = texts.where((t) => _bengali.hasMatch(t) && !_serverWords.any(t.contains)).toList();
    expect(own, isEmpty, reason: 'ইংরেজিতে পর্দার নিজের বাংলা: $own');
    expect(texts, contains('Received into *'));
    expect(texts, contains('Open invoices and credits'));
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/collect/collect_412_light_en.png'));
  });

  test('the fake speaks the final contract', () {
    final b = OpenBills.fromJson(openBillsJson());
    expect(b.bills.map((i) => i.no), ['INV-0094', 'INV-0101', 'INV-0112']);
    expect(b.credits.single.kind, 'credit_note');
    expect(b.credits.single.open, 200000, reason: 'ক্রেডিটের ঋণাত্মক টাকা ধনাত্মক পয়সায়, দিক kind-এ');
    expect(b.creditLimit, 10000000);
    expect(b.oldestOverdueDays, 49);
  });
}
