import 'package:abos_mobile/core/dashboard/dashboard_api.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/features/accounts_chart/chart_screens.dart';
import 'package:abos_mobile/features/approval_rows/approval_rows_screens.dart';
import 'package:abos_mobile/features/dashboards/dashboards_screen.dart';
import 'package:abos_mobile/features/executive/executive_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay sei vabe koro, direct webpage app e bujajayna"* —
/// ড্যাশবোর্ডের ওয়েব-সারিগুলো ফোনের নিজের পর্দায়।
void main() {
  test('the dashboards, the sales overview and the owner centre open as phone screens; anything else stays a web window', () {
    for (final module in [
      'accounts', 'finance', 'purchase', 'inventory', 'customer', 'credit_control', 'sales',
      'promotion', 'approval', 'system_admin', 'documents', 'hr',
    ]) {
      final screen = NativeForWeb.screenFor('/dashboard/$module', title: 'নাম');
      expect(screen, isA<ModuleDashboardScreen>(), reason: '/dashboard/$module এখনো ওয়েব-জানালায়');
      expect((screen! as ModuleDashboardScreen).module, module);
    }
    expect(NativeForWeb.screenFor('/dashboard/accounts/'), isA<ModuleDashboardScreen>(), reason: 'শেষের / থাকলেও একই পর্দা');
    expect(NativeForWeb.screenFor('/sales/overview?period=month'), isA<SalesOverviewScreen>());
    expect(NativeForWeb.screenFor('/owner').toString(), contains('ModuleGateView'));
    expect(NativeForWeb.screenFor('/accounts/chart-of-accounts'), isA<ChartOfAccountsScreen>());
    expect(NativeForWeb.screenFor('/approvals/delegations'), isA<DelegationScreen>());
    expect((NativeForWeb.screenFor('/documents/approval')! as PaperQueueScreen).kind, PaperQueueKind.documents);
    expect((NativeForWeb.screenFor('/documents/signatures')! as PaperQueueScreen).kind, PaperQueueKind.signatures);

    // ⭐ HR-এর ড্যাশবোর্ডও ফোনের পর্দায় — বেতন সার্ভারেই বাদ (মালিক, ১৭ অক্টোবর ২০২৬)
    expect(NativeForWeb.screenFor('/dashboard/ghost'), isNull, reason: 'অজানা মডিউল আন্দাজে খোলা নয়');
    expect(NativeForWeb.screenFor('/accounts/chart'), isNull);
    expect(NativeForWeb.screenFor(null), isNull);
    expect(ExecutiveScreen, isNotNull);
  });

  testWidgets('the sales overview draws the server\'s cards, chart and list, and asks again for a new date range', (tester) async {
    final asked = <String>[];
    await tester.pumpWidget(MaterialApp(
      home: SalesOverviewScreen(
        today: DateTime(2026, 10, 17),
        open: (from, to) async {
          asked.add('$from..$to');
          return const ModuleDashboard({
            'title': 'বিক্রয়ের হালচাল',
            'subtitle': '2026-10-17 থেকে 2026-10-17 পর্যন্ত',
            'period': {'kind': 'custom', 'refused': null},
            'stats': [
              {'label': 'নিট বিক্রয়', 'value': '12,500.00', 'hidden': false, 'hint': '', 'tone': 'good'},
            ],
            'panels': [
              {'kind': 'breakdown', 'label': 'শেষ 30 দিনের বিক্রয়', 'parts': [
                {'label': '17', 'value': '12,500.00'},
              ]},
            ],
            'listings': [
              {'label': 'সবচেয়ে বড় ক্রেতা', 'empty': 'এই সময়ে কিছু নেই।', 'columns': [
                {'key': 'name', 'label': 'নাম'}, {'key': 'amount', 'label': 'টাকা'},
              ], 'rows': [
                ['রহিম স্টোর', '12,500.00'],
              ]},
            ],
          });
        },
      ),
    ));
    await tester.pumpAndSettle();

    expect(asked, ['2026-10-17..2026-10-17'], reason: 'শুরু আজ থেকে, ওয়েবের পাতার মতো');
    expect(find.text('বিক্রয়ের হালচাল'), findsOneWidget);
    expect(find.text('নিট বিক্রয়'), findsOneWidget);
    expect(find.text('12,500.00'), findsWidgets);
    expect(find.text('শেষ 30 দিনের বিক্রয়'), findsOneWidget);
    expect(find.text('রহিম স্টোর'), findsOneWidget);
    expect(find.text('টাকা: 12,500.00'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('range-month')));
    await tester.pumpAndSettle();
    expect(asked.last, '2026-10-01..2026-10-17');
  });

  testWidgets('a range the server refused says why', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: SalesOverviewScreen(
        today: DateTime(2026, 10, 17),
        open: (from, to) async => const ModuleDashboard({
          'title': 'বিক্রয়ের হালচাল',
          'period': {'kind': 'month', 'refused': 'তারিখের পরিসরটা ঠিক ছিল না — তাই এই মাস দেখানো হচ্ছে।'},
          'stats': [], 'panels': [], 'listings': [],
        }),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('overview-refused')), findsOneWidget);
    expect(find.text('এই পর্দার কোনো সংখ্যা দেখার অনুমতি আপনার নেই।'), findsOneWidget);
  });
}
