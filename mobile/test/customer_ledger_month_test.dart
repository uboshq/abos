import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/customers/customer_ledger_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'navigation/bar_harness.dart' show loadRealFonts, realFontTheme;

/// ⭐ খাতার মাসের মাথা — মাসের নাম ভাষায়, অঙ্ক দুই ভাষাতেই ইংরেজি (মালিকের স্থায়ী নিয়ম; a4-এর সিদ্ধান্ত, ২১ অক্টোবর ২০২৬):
/// বাংলায় "অক্টোবর 2026", ইংরেজিতে "October 2026"। ⛔ আগে বাংলায় বছর বাংলা অঙ্কে ("অক্টোবর ২০২৬")।
///
/// <p>ছবি দুটো আসল ফন্টে (Roboto + Shonar Bangla) — চৌকো-ফন্টে বাংলা আর ইংরেজি অঙ্ক একই দেখায়, তাই ওখানে ধরা পড়ে না।
class _OneMonth implements CustomerLedgerApi {
  @override
  Future<CustomerLedger> ledger(String customerId, {int page = 1}) async => const CustomerLedger(
        name: 'রহিম স্টোর',
        code: 'C-0007',
        due: 1500,
        rows: [
          CustomerLedgerRow(date: '2026-10-06', no: 'RCV-0002', debit: 0, credit: 500, balance: 1500),
          CustomerLedgerRow(date: '2026-09-28', no: 'S-0041', debit: 2000, credit: 0, balance: 2000),
        ],
      );
}

final _bengaliDigit = RegExp('[০-৯]');

Widget _app(Locale locale) => MaterialApp(
      debugShowCheckedModeBanner: false,
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      theme: realFontTheme(AppTheme.light()),
      builder: (context, child) => RepaintBoundary(key: const ValueKey('shot'), child: child!),
      home: CustomerLedgerScreen(customerId: 'x', api: _OneMonth()),
    );

void main() {
  setUpAll(loadRealFonts);
  tearDown(() => AppLocale.current = AppLocale.locale);

  test('the heading has no Bengali digit in bn, and reads "October 2026" in en', () {
    AppLocale.current = AppLocale.locale;
    expect(monthOf('2026-10-12'), 'অক্টোবর 2026');
    expect(monthOf('2026-10-12'), isNot(contains(_bengaliDigit)));
    expect(monthOf('2026-09-01'), 'সেপ্টেম্বর 2026');
    AppLocale.current = AppLocale.english;
    expect(monthOf('2026-10-12'), 'October 2026');
    expect(monthOf('2026-10-12'), isNot(contains(_bengaliDigit)));
  });

  for (final (code, locale, october, september) in [
    ('bn', AppLocale.locale, 'অক্টোবর 2026', 'সেপ্টেম্বর 2026'),
    ('en', AppLocale.english, 'October 2026', 'September 2026'),
  ]) {
    testWidgets('$code: the ledger screen draws "$october" and "$september", no Bengali digit in either', (tester) async {
      AppLocale.current = locale;
      await tester.binding.setSurfaceSize(const Size(412, 600));
      addTearDown(() => tester.binding.setSurfaceSize(null));
      await tester.pumpWidget(_app(locale));
      await tester.pumpAndSettle();
      expect(find.text(october), findsOneWidget);
      expect(find.text(september), findsOneWidget);
      for (final heading in [october, september]) {
        expect(heading, isNot(contains(_bengaliDigit)));
      }
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/ledger_month_$code.png'));
    });
  }
}
