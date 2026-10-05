import 'package:abos_mobile/core/orders/sales_return_api.dart';
import 'package:abos_mobile/core/widgets/confirm_overview_sheet.dart';
import 'package:abos_mobile/features/direct_sale/return_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ বিক্রি ফেরত, ফোনে — মালিক, ৪ অক্টোবর ২০২৬। বিল → মাল আর পরিমাণ → খসড়া → সারাংশ → নিশ্চিত; ফোন কেবল বিলের সারি পাঠায়।
class FakeReturnApi implements SalesReturnApi {
  String? askedCustomer;
  Map<String, double>? sentLines;
  String? sentReason;
  int confirms = 0;

  @override
  Future<ReturnSetup> setup({String? customerId}) async {
    askedCustomer = customerId;
    return const ReturnSetup(
      reasons: [ReturnChoice('rsn-1', 'নষ্ট মাল')],
      invoices: [
        ReturnInvoice(
            id: 'inv-1',
            no: 'INV-0007',
            customer: 'রহিম স্টোর',
            total: '500.00',
            date: '2026-10-04')
      ],
    );
  }

  @override
  Future<ReturnBill> bill(String invoiceId) async => const ReturnBill(
        id: 'inv-1',
        no: 'INV-0007',
        customerName: 'রহিম স্টোর',
        lines: [
          ReturnBillLine(
              id: 'line-1',
              name: 'কসমস বিস্কুট',
              qty: 5,
              rate: 100,
              lotNo: 'LOT-A')
        ],
      );

  @override
  Future<String> draft(
      {required String invoiceId,
      required String reasonId,
      required Map<String, double> lines,
      String? note}) async {
    sentLines = lines;
    sentReason = reasonId;
    return 'ret-1';
  }

  @override
  Future<ConfirmOverviewData> overview(String returnId) async =>
      ConfirmOverviewData.fromJson(const {
        'title': 'বিক্রি ফেরত RET-0001 — সারাংশ',
        'head': [],
        'lines': [
          {
            'title': 'কসমস বিস্কুট',
            'details': ['2 × 100.00'],
            'amount': '200.00'
          },
        ],
        'totals': [
          {'label': 'ফেরতের মোট', 'amount': '200.00', 'strong': true},
        ],
        'money': [],
        'notes': [],
        'blocks': false,
      });

  @override
  Future<(String, String)> confirm(String returnId) async {
    confirms++;
    return ('done', 'ফেরত নিশ্চিত হলো।');
  }
}

Future<void> _open(WidgetTester tester, FakeReturnApi api) async {
  tester.view.physicalSize = const Size(800, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
    home: Builder(
      builder: (context) => Scaffold(
        body: TextButton(
          onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
              builder: (_) => ReturnScreen(customerId: 'cus-1', api: api))),
          child: const Text('খুলুন'),
        ),
      ),
    ),
  ));
  await tester.tap(find.text('খুলুন'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets(
      'bill, quantity, overview, confirm — only the bill\'s own line goes, for this customer',
      (tester) async {
    final api = FakeReturnApi();
    await _open(tester, api);
    expect(api.askedCustomer, 'cus-1',
        reason: 'the counter\'s customer narrows the bills');

    await tester.tap(find.byKey(const ValueKey('return-bill-inv-1')));
    await tester.pumpAndSettle();
    expect(find.text('লট: LOT-A'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('return-go')));
    await tester.pumpAndSettle();
    expect(find.text('অন্তত একটা মালের ফেরত পরিমাণ দিন।'), findsOneWidget);
    expect(api.sentLines, isNull, reason: '⛔ পরিমাণ ছাড়াই খসড়া পাঠানো হলো');

    await tester.enterText(
        find.byKey(const ValueKey('return-qty-line-1')), '2');
    await tester.tap(find.byKey(const ValueKey('return-go')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('overview-sheet')), findsOneWidget,
        reason: 'the overview comes before confirming');
    expect(api.confirms, 0);
    await tester.tap(find.byKey(const ValueKey('overview-confirm')));
    await tester.pumpAndSettle();

    expect(api.sentLines, {'line-1': 2});
    expect(api.sentReason, 'rsn-1');
    expect(api.confirms, 1);
    expect(find.text('ফেরত হলো'), findsOneWidget);
  });

  testWidgets(
      'going back from the overview keeps the draft and says where it is',
      (tester) async {
    final api = FakeReturnApi();
    await _open(tester, api);
    await tester.tap(find.byKey(const ValueKey('return-bill-inv-1')));
    await tester.pumpAndSettle();
    await tester.enterText(
        find.byKey(const ValueKey('return-qty-line-1')), '1');
    await tester.tap(find.byKey(const ValueKey('return-go')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('overview-back')));
    await tester.pumpAndSettle();

    expect(api.confirms, 0, reason: '⛔ "ফিরে যান" চাপতেই ফেরত পাকা হলো');
    expect(find.text('খসড়া ফেরত রাখা হলো'), findsOneWidget);
  });
}
