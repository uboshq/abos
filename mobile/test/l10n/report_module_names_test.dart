import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/records/report_record.dart';
import 'package:abos_mobile/features/reports/report_module_name.dart';
import 'package:abos_mobile/features/reports/reports_screen.dart';
import 'package:abos_mobile/l10n/app_localizations.dart';

/// ⭐ The reports list names each report's module, not the server's code (a4, 22 Oct 2026): "sales" reads বিক্রয় / Sales —
/// the same word as the module tile — and an unknown code shows itself.
void main() {
  tearDown(() => AppLocale.current = AppLocale.locale);

  Future<void> open(WidgetTester tester, Locale locale) async {
    AppLocale.current = locale;
    await tester.pumpWidget(MaterialApp(
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: ReportsScreen(
        loadList: () async => const [
          ReportSummary({'key': 'sales.daily', 'title': 'Daily sales', 'module': 'sales'}),
          ReportSummary({'key': 'governance.changes', 'title': 'Changes', 'module': 'governance'}),
          ReportSummary({'key': 'x.y', 'title': 'Something new', 'module': 'space_station'}),
        ],
      ),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('in Bangla: "sales" reads বিক্রয়, a web-only module its web name, an unknown code itself', (tester) async {
    await open(tester, AppLocale.locale);
    expect(find.text('বিক্রয়'), findsOneWidget);
    expect(find.text('নিয়ন্ত্রণ ও নিরীক্ষা'), findsOneWidget);
    expect(find.text('space_station'), findsOneWidget);
    expect(find.text('sales'), findsNothing, reason: '⛔ সার্ভারের কাঁচা কোড');
  });

  testWidgets('in English: "sales" reads Sales', (tester) async {
    await open(tester, AppLocale.english);
    expect(find.text('Sales'), findsOneWidget);
    expect(find.text('Governance'), findsOneWidget);
    expect(find.text('space_station'), findsOneWidget);
    expect(find.text('sales'), findsNothing);
  });

  test('every module tile the phone has reads the same name in the reports list, in both languages', () {
    for (final locale in AppLocale.supported) {
      AppLocale.current = locale;
      final l = lookupAppL10n(locale);
      for (final m in NavCatalog.modules) {
        if (m.code == 'reports') continue; // ⓘ reports are not a report's module
        expect(reportModuleName(m.code, l), m.label, reason: '${m.code} ($locale)');
      }
    }
  });
}
