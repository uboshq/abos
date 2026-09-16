import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/records/report_record.dart';
import 'package:abos_mobile/features/reports/reports_screen.dart';

/// Reports — docs/Contract §৯.
///
/// <p>One screen for thirty-three reports, because the server's `ReportEngine`
/// already knows every one of them, their columns, their types and who may
/// see which column. The phone's whole job is to draw what it is handed.
void main() {
  const page = ReportPage({
    'key': 'sales.daily',
    'title': 'দৈনিক বিক্রয়',
    'columns': [
      {'key': 'trx_date', 'label': 'তারিখ', 'type': 'date', 'total': false},
      {'key': 'document_no', 'label': 'নম্বর', 'type': 'document', 'total': false},
      {'key': 'amount', 'label': 'টাকা', 'type': 'money', 'total': true},
    ],
    'rows': [
      {'trx_date': '2026-09-16', 'document_no': 'SI-2609-0031', 'amount': '1275.0000'},
      {'trx_date': '2026-09-16', 'document_no': 'SI-2609-0032', 'amount': '860.5000'},
    ],
    'totals': {'amount': '45200.0000'},
    'page': 1,
    'perPage': 100,
    'totalRows': 412,
    'lastPage': 5,
  });

  group('columns come from the server', () {
    test('each type is read the way a person reads it', () {
      final cols = page.columns;
      expect(cols[0].format('2026-09-16'), '16/09/2026');
      expect(cols[1].format('SI-2609-0031'), 'SI-2609-0031');
      expect(cols[2].format('1275.0000'), '৳1,275');
    });

    test('an unfamiliar type is printed raw, not hidden', () {
      // A build that meets a type added after it shipped should look plain,
      // not broken. An empty cell reads as "there is nothing here".
      const odd = ReportColumn({'key': 'x', 'label': 'X', 'type': 'sparkline'});
      expect(odd.format('12.5'), '12.5');
    });

    test('a column this person may not see is simply absent', () {
      // ⭐ The purchasePrice rule generalised: columnsFor($user) drops it on
      // the server, so there is nothing here to hide and nothing to hardcode.
      const restricted = ReportPage({
        'columns': [
          {'key': 'qty', 'label': 'পরিমাণ', 'type': 'quantity'},
        ],
        'rows': [
          {'qty': '12.0000', 'cost': '900.0000'},
        ],
      });

      expect(restricted.columns.map((c) => c.key), ['qty']);
    });
  });

  group('totals', () {
    test('come from the server and are not the page', () {
      // ⛔ 1275 + 860.5 = 2135.5 — the two rows on screen. The report's total
      // is 45,200. Summing what is visible would put the sum of one page
      // under a report of four hundred and twelve rows and call it the total:
      // a number that looks credible and is false.
      expect(page.totals['amount'], '45200.0000');
      expect(page.rows.length, 2);
      expect(page.totalRows, 412);
    });
  });

  group('paging', () {
    test('says which slice is on screen', () {
      expect(page.rangeSentence, '412 টির মধ্যে 1–2');
      expect(page.hasMore, isTrue);
    });

    test('the last page has no more', () {
      const last = ReportPage({
        'rows': [
          {'a': 1},
        ],
        'page': 5,
        'perPage': 100,
        'totalRows': 401,
        'lastPage': 5,
      });
      expect(last.hasMore, isFalse);
    });
  });

  group('the screen', () {
    testWidgets('lists only what the server offered', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: ReportsScreen(
          loadList: () async => const [
            ReportSummary({
              'key': 'sales.daily',
              'title': 'দৈনিক বিক্রয়',
              'module': 'sales'
            }),
            ReportSummary({
              'key': 'accounts.trial',
              'title': 'রেওয়ামিল',
              'module': 'accounts'
            }),
          ],
        ),
      ));
      await tester.pumpAndSettle();

      expect(find.text('দৈনিক বিক্রয়'), findsOneWidget);
      expect(find.text('রেওয়ামিল'), findsOneWidget);
    });

    testWidgets('a role with no reports is told so, not shown an error',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: ReportsScreen(loadList: () async => const []),
      ));
      await tester.pumpAndSettle();

      expect(find.text('আপনার জন্য কোনো রিপোর্ট নেই'), findsOneWidget);
      expect(find.text('তালিকা আনা গেল না'), findsNothing);
    });

    testWidgets('opening one draws its rows, its totals and its range',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: ReportsScreen(
          loadList: () async => const [
            ReportSummary({'key': 'sales.daily', 'title': 'দৈনিক বিক্রয়'}),
          ],
          open: (key, p) async => page,
        ),
      ));
      await tester.pumpAndSettle();

      await tester.tap(find.text('দৈনিক বিক্রয়'));
      await tester.pumpAndSettle();

      expect(find.text('SI-2609-0031'), findsOneWidget);
      expect(find.text('16/09/2026'), findsWidgets);
      expect(find.text('৳1,275'), findsOneWidget);
      // The server's total, and the sentence that stops one page being read
      // as the whole report.
      expect(find.text('৳45,200'), findsOneWidget);
      expect(find.text('412 টির মধ্যে 1–2'), findsOneWidget);
      expect(find.text('পাতা 1 / 5'), findsOneWidget);
    });
  });
}
