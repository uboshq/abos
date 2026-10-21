import 'dart:io';

import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/books/collect_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/bar_fonts.dart';
import 'support/collect_fake.dart';

/// ⭐ টাকা নেওয়ার দুই পর্দার ছবি — চূড়ান্ত নকশা ১০০, পাতা ৬ (পর্দা ১ "টাকা নেওয়া", পর্দা ২ "পোস্টের আগে দেখুন"); 412dp,
/// আলো আর অন্ধকার, বাংলায়, আর প্রতিটা ইংরেজিতে। আসল ফন্টে (Roboto + Shonar Bangla + Material Symbols), এক PC-তে বানানো।
/// বদলালে ছবি খুলে নকশার পাশে রেখে দেখে `--update-goldens`।
final _bengali = RegExp('[ঀ-৲৴-৿]');

/// সার্ভার থেকে আসা নাম — ইংরেজিতেও বাংলা থাকে
const _serverWords = ['করিম স্টোর', 'ফুলপুর পয়েন্ট', 'ময়মনসিংহ', 'নগদ', 'ডাচ-বাংলা', 'bKash', 'পাওনা', 'পঁয়তাল্লিশ', 'প্রধান'];

/// ⓘ অবস্থার চিপের আইকন — material_symbols_icons-এর Rounded ফন্ট (a5-এর screens_0441 আর a6-এর paper_state ছবির একই পথ);
/// না থাকলে চিপের আইকন ফাঁকা বাক্স
Future<void> _loadRounded() async {
  final config = File('.dart_tool/package_config.json').readAsStringSync();
  final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
  final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
  final loader = FontLoader('packages/material_symbols_icons/MaterialSymbolsRounded')
    ..addFont(Future.value(ByteData.view(Uint8List.fromList(File('$root/lib/fonts/MaterialSymbolsRounded.ttf').readAsBytesSync()).buffer)));
  await loader.load();
}

/// [review]: পর্দা ২ খোলা; [posted]: তার পরে পোস্টও হয়ে গেছে
Future<void> _open(WidgetTester tester,
    {bool dark = false,
    Locale locale = AppLocale.locale,
    bool review = false,
    bool posted = false,
    double textScale = 1,
    String? rounding}) async {
  tester.view.physicalSize = Size(412, (review ? 1400 : 1900) * (textScale > 1 ? 1.45 : 1));
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
      builder: (context, child) => MediaQuery.withClampedTextScaling(minScaleFactor: textScale, maxScaleFactor: textScale, child: child!),
      home: CollectScreen(
        // ⓘ নকশার মতো NPSB আগে (সার্ভার কোড ধরে সাজায়; এখানে নকশার নমুনা)
        api: FakeCollect(
          preview: previewJson(needsSignature: rounding != null),
          transferModes: const [
            {'id': 'tm-npsb', 'code': 'NPSB', 'name': 'NPSB'},
            {'id': 'tm-beftn', 'code': 'BEFTN', 'name': 'BEFTN'},
          ],
        ),
        books: FakeBooks(),
        newChangeId: () => 'k',
        today: DateTime(2026, 10, 21),
        openReceipt: (_, __, ___) async {},
        otherParty: (_, __) async {},
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
  // ⓘ নকশার নমুনা: ডাচ্-বাংলা ব্যাংক, "ব্যাংক" পদ্ধতি, TrxID লেখা
  await tester.tap(find.byKey(const ValueKey('collect-method-transfer')));
  await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const ValueKey('collect-reference')), 'NP2610210457812');
  await tester.pumpAndSettle();
  FocusManager.instance.primaryFocus?.unfocus();
  await tester.pumpAndSettle();
  if (rounding != null) {
    await tester.ensureVisible(find.byKey(const ValueKey('collect-adjust')));
    await tester.tap(find.byKey(const ValueKey('collect-adjust')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('collect-difference-rounding')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('collect-difference')), rounding);
    await tester.pumpAndSettle();
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
  }
  if (review) {
    await tester.tap(find.byKey(const ValueKey('collect-post')));
    await tester.pumpAndSettle();
  }
  if (posted) {
    await tester.tap(find.byKey(const ValueKey('collect-check-post')));
    await tester.pumpAndSettle();
  }
}

List<String> _texts(WidgetTester tester) => [
      for (final t in tester.widgetList<Text>(find.byType(Text))) t.data ?? t.textSpan?.toPlainText() ?? '',
      for (final d in tester.widgetList<InputDecorator>(find.byType(InputDecorator))) d.decoration.labelText ?? '',
    ];

void main() {
  setUpAll(() async {
    await loadBarFonts();
    await _loadRounded();
  });

  // ⓘ তিন ছবি: পর্দা ১; পর্দা ২ পোস্টের আগে (সারাংশ, খাতা, নিয়ন্ত্রণ, নিচে "ফিরে যান" আর "পোস্ট" — নকশার মতো); পর্দা ২ পোস্টের পরে
  for (final (name, what, review, posted) in [
    ('collect_screen', 'screen 1 (টাকা নেওয়া)', false, false),
    ('collect_review', 'screen 2 before posting (পোস্টের আগে দেখুন)', true, false),
    ('collect_posted', 'screen 2 after posting (পোস্টের পরে)', true, true),
  ]) {
    for (final dark in [false, true]) {
      testWidgets('20 · $what at 412dp, ${dark ? 'dark' : 'light'}, in Bengali', (tester) async {
        await _open(tester, dark: dark, review: review, posted: posted);
        expect(tester.takeException(), isNull);
        await expectLater(
            find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/collect/${name}_412_${dark ? 'dark' : 'light'}_bn.png'));
      });
    }

    testWidgets('19 · $what in English: no Bengali of the screen\'s own, only the server\'s names', (tester) async {
      await _open(tester, locale: AppLocale.english, review: review, posted: posted);
      final texts = _texts(tester);
      final own = texts.where((t) => _bengali.hasMatch(t) && !_serverWords.any(t.contains)).toList();
      expect(own, isEmpty, reason: 'ইংরেজিতে পর্দার নিজের বাংলা: $own');
      expect(texts, contains(review ? 'Check before posting' : 'Receive money'));
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/collect/${name}_412_light_en.png'));
    });
  }

  // ⓘ ১৩০% লেখায় দুই পর্দা — কিছু কাটা পড়ে না (২০০%-এর উপচে-না-পড়া দাবি collect_screen_test-এ)
  for (final (name, what, review) in [('collect_screen', 'screen 1', false), ('collect_review', 'screen 2', true)]) {
    testWidgets('20 · $what at 130% text, light, in Bengali — nothing cut', (tester) async {
      await _open(tester, review: review, textScale: 1.3);
      expect(tester.takeException(), isNull, reason: '⛔ ১৩০% লেখায় উপচে পড়ল');
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/collect/${name}_412_light_bn_130.png'));
    });
  }

  // ⓘ পর্দা ২, মালিকের সই লাগবে (রাউন্ডিং ৳0.51): নিয়ন্ত্রণের নোট আর "সইয়ে পাঠান"
  for (final (locale, tag) in [(AppLocale.locale, 'bn'), (AppLocale.english, 'en')]) {
    testWidgets('4 · screen 2 when the owner must sign (rounding ৳0.51), light, $tag', (tester) async {
      await _open(tester, locale: locale, review: true, rounding: '0.51');
      expect(find.text(tag == 'bn' ? 'সইয়ে পাঠান' : 'Send for signature'), findsOneWidget);
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/collect/collect_review_sign_412_light_$tag.png'));
    });
  }

  test('the sample shows the design\'s figures (page 6): due 51,200, this 45,000, left 6,200', () {
    final b = OpenBills.fromJson(openBillsJson());
    expect(b.due, 5120000);
    expect(b.items.map((i) => (i.no, i.isCredit ? -i.open : i.open)),
        [('INV-0094', 3000000), ('INV-0101', 1520000), ('CN-0007', -200000), ('INV-0112', 800000)]);
    expect(b.items.first.overdueDays, 8);
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
