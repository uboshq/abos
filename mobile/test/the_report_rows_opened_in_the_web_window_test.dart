import 'package:abos_mobile/core/records/report_record.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/router/report_paths.dart';
import 'package:abos_mobile/features/reports/report_by_key_screen.dart';
import 'package:abos_mobile/features/reports/reports_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মেনুর রিপোর্ট-সারি ফোনের রিপোর্ট-পর্দায় — মালিক "অ্যাপে ১০০ ভাগ" (fe, ১৯ অক্টোবর ২০২৬; পূর্ণ নিরীক্ষা §৯ ⚠️৪)।
///
/// <p>⛔ আগে ১১৩টা রিপোর্ট-সারি ওয়েব-জানালায় খুলত, অথচ ফোনের রিপোর্ট-দরজা ঐ রিপোর্টগুলো চাবি ধরেই দিত; আর ফোন তারিখ ছাড়া
/// কোনো ছাঁকনি পাঠাত না — খতিয়ান হিসাব ছাড়াই খুলত।
///
/// <p>⭐ দাবি: ওয়েবের পথ থেকে সার্ভারের চাবি (নিয়মের বাইরেরগুলোসহ); ওয়েবের নিজস্ব বিবরণী (ব্যালান্স শিট) ওয়েবেই; চাবিটা তালিকায়
/// না থাকলে সোজা কথা; বাছা ছাঁকনি সার্ভারের ডাকে যায়, "সব" তুলে নেয়, বড় তালিকায় সার্ভারে খোঁজে।
void main() {
  group('the map', () {
    test('a report row maps to the server key, the odd ones included', () {
      expect(ReportPaths.keyFor('/accounts/reports/day-book'), 'accounts.day_book');
      expect(ReportPaths.keyFor('/credit-control/reports/ageing'), 'credit_control.ageing');
      expect(ReportPaths.keyFor('/approvals/reports/why-rejected'), 'approval.why_rejected');
      expect(ReportPaths.keyFor('/owner/reports/profit-by-customer'), 'executive.profit_by_customer');
      expect(ReportPaths.keyFor('/system/reports/notice-register'), 'system_admin.notice_register');
      // ⓘ নিয়মের বাইরে — মডিউল বা নাম আলাদা
      expect(ReportPaths.keyFor('/accounts/reports/party-target'), 'sales.party_target');
      expect(ReportPaths.keyFor('/accounts/reports/branches'), 'accounts.branches_side_by_side');
      expect(ReportPaths.keyFor('/sales/margin/margin'), 'sales.margin');
      expect(ReportPaths.keyFor('/sales/returns/reports/by-reason'), 'sales.return_by_reason');
      expect(ReportPaths.keyFor('/notification/reports/audit'), 'notification.audit_report');
    });

    test('the web\'s own statements stay on the web', () {
      expect(ReportPaths.keyFor('/accounts/reports/balance-sheet'), isNull, reason: '⛔ ইঞ্জিনের পুরনো সমতল টেবিল খুলত, ওয়েবের বিবরণী নয়');
      expect(ReportPaths.keyFor('/accounts/reports/group'), isNull);
      expect(ReportPaths.keyFor('/accounts/books-check'), isNull);
    });

    test('every key is module.report and every path is a report door', () {
      expect(ReportPaths.keys.length, greaterThanOrEqualTo(113), reason: 'নিরীক্ষার ১১৩টা সারি');
      for (final e in ReportPaths.keys.entries) {
        expect(e.value, matches(RegExp(r'^[a-z_]+\.[a-z0-9_]+$')), reason: e.key);
        expect(e.key, matches(RegExp(r'^/[a-z-]+/(reports/|returns/reports/|margin/)[a-z0-9-]+$')), reason: e.key);
      }
    });

    test('the menu door opens the phone report screen for a report row', () {
      expect(NativeForWeb.screenFor('/credit-control/reports/ageing', title: 'বাকির বয়স'), isA<ReportByKeyScreen>(),
          reason: '⛔ রিপোর্ট-সারি এখনো ওয়েব-জানালায়');
      expect(NativeForWeb.screenFor('/documents/reports'), isA<ReportsScreen>());
      expect(NativeForWeb.screenFor('/accounts/reports/balance-sheet'), isNull);
    });
  });

  group('opening by key', () {
    testWidgets('a report on the list opens with its own title and filters', (tester) async {
      ReportSummary? opened;
      await tester.pumpWidget(MaterialApp(
        home: ReportByKeyScreen(
          reportKey: 'accounts.ledger',
          title: 'খতিয়ান',
          loadList: () async => const [
            ReportSummary({'key': 'accounts.ledger', 'title': 'খতিয়ান', 'module': 'accounts', 'filters': ['date_range', 'account']}),
          ],
          view: (s) {
            opened = s;
            return const Text('VIEW');
          },
        ),
      ));
      await tester.pumpAndSettle();
      expect(find.text('VIEW'), findsOneWidget);
      expect(opened!.filters, ['date_range', 'account'], reason: '⛔ তারিখ আর হিসাবের ঘোষণা হারাল');
    });

    testWidgets('a key this person may not run says so plainly', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: ReportByKeyScreen(reportKey: 'accounts.trial_balance', loadList: () async => const []),
      ));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('report-by-key-missing')), findsOneWidget);
    });
  });

  group('the filters', () {
    const ledger = ReportSummary({'key': 'accounts.ledger', 'title': 'খতিয়ান', 'filters': ['date_range', 'account', 'branch']});

    Future<List<Map<String, dynamic>>> openWith(WidgetTester tester, {bool capped = false, List<(String, String)> found = const []}) async {
      final asked = <Map<String, dynamic>>[];
      await tester.pumpWidget(MaterialApp(
        home: ReportViewScreen(
          report: ledger,
          today: () => DateTime(2026, 10, 19),
          open: (k, p, f) async {
            asked.add(Map.of(f));
            return const ReportPage({'title': 'খতিয়ান', 'columns': [], 'rows': []});
          },
          loadChoices: (k) async => [
            const ReportChoice({'key': 'date_range', 'kind': 'date_range', 'label': 'তারিখ'}),
            ReportChoice({
              'key': 'account_id', 'kind': 'list', 'label': 'হিসাব', 'capped': capped,
              'options': [
                {'value': 'a-cash', 'label': 'নগদ'},
                {'value': 'a-bank', 'label': 'ব্যাংক'},
              ],
            }),
            const ReportChoice({'key': 'branch', 'kind': 'branch', 'label': 'শাখা', 'options': [{'value': 'b-1', 'label': 'প্রধান'}]}),
            const ReportChoice({'key': 'odd', 'kind': 'web', 'label': 'odd'}),
          ],
          searchChoices: (k, f, q) async => found,
        ),
      ));
      await tester.pumpAndSettle();
      return asked;
    }

    testWidgets('a picked account goes to the server and "all" takes it off', (tester) async {
      final asked = await openWith(tester);
      expect(find.byKey(const ValueKey('report-choice-account_id')), findsOneWidget, reason: '⛔ হিসাবের ছাঁকনি নেই');
      expect(find.byKey(const ValueKey('report-choice-branch')), findsOneWidget);
      expect(find.byKey(const ValueKey('report-choice-odd')), findsNothing, reason: 'ফোনে আঁকা যায় না এমন ছাঁকনি নয়');
      expect(find.byKey(const ValueKey('report-choice-date_range')), findsNothing, reason: 'তারিখ নিজের ঘরে');

      await tester.tap(find.byKey(const ValueKey('report-choice-account_id')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('report-choice-option-a-bank')));
      await tester.pumpAndSettle();

      expect(asked.last['account_id'], 'a-bank', reason: '⛔ বাছা হিসাব সার্ভারে গেল না');
      expect(asked.last['from'], '2026-10-01', reason: 'তারিখও সাথে');
      expect(find.text('হিসাব: ব্যাংক'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('report-choice-account_id')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('report-choice-all')));
      await tester.pumpAndSettle();
      expect(asked.last.containsKey('account_id'), isFalse, reason: '"সব" বাছলে ছাঁকনি উঠে যাওয়ার কথা');
    });

    testWidgets('a capped list searches the server for what is past its cap', (tester) async {
      final asked = await openWith(tester, capped: true, found: const [('a-far', 'দূরের হিসাব')]);
      await tester.tap(find.byKey(const ValueKey('report-choice-account_id')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('report-choice-search')), 'দূর');
      await tester.pump(const Duration(milliseconds: 600));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('report-choice-option-a-far')));
      await tester.pumpAndSettle();
      expect(asked.last['account_id'], 'a-far', reason: '⛔ ছাদের বাইরের হিসাব বাছা গেল না');
    });
  });
}
