import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/features/today/today_screen.dart';

/// The day on one page — docs/Contract §৮.
///
/// <p>Written against the shape agreed before the endpoint exists, which is
/// the order this app now works in. The payload literal below is the one in
/// that contract.
void main() {
  const full = TodayRecord({
    'date': '2026-09-16',
    'company': 'ADI',
    'branch': 'প্রধান শাখা',
    'asOf': '2026-09-16T18:04:11+06:00',
    'sales': {'count': 12, 'amount': '45200.0000'},
    'collections': {'count': 7, 'amount': '31000.0000'},
    'cashInHand': {'amount': '18500.0000'},
    'dues': {'amount': '812500.0000', 'shops': 41},
    'approvals': {'pending': 3},
  });

  Widget screen({
    Future<TodayRecord> Function()? fetch,
    TodayRecord? Function()? lastKnown,
  }) =>
      MaterialApp(
        home: TodayScreen(
          fetch: fetch ?? () async => full,
          lastKnown: lastKnown ?? () => null,
        ),
      );

  testWidgets('the three numbers the owner asked for, on one page',
      (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    expect(find.text('৳45,200'), findsOneWidget); // বিক্রি
    expect(find.text('৳31,000'), findsOneWidget); // আদায়
    expect(find.text('৳18,500'), findsOneWidget); // নগদ
    expect(find.text('৳812,500'), findsOneWidget);
    expect(find.textContaining('12 টি বিক্রয়'), findsOneWidget);
    expect(find.textContaining('41 টি দোকান'), findsOneWidget);
    expect(find.textContaining('3 টি নথি'), findsOneWidget);
  });

  testWidgets('whose figures these are, and when they were true',
      (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    // Somebody can belong to more than one company and this app cannot switch
    // between them yet. Unlabelled numbers from the wrong company are numbers
    // acted on.
    expect(find.textContaining('ADI'), findsOneWidget);
    expect(find.textContaining('প্রধান শাখা'), findsOneWidget);
    // A stale figure looks exactly like a fresh one.
    expect(find.textContaining('হিসাব'), findsWidgets);
    expect(find.textContaining('06:04 PM'), findsOneWidget);
  });

  testWidgets('a figure the person may not see draws no card at all',
      (tester) async {
    // ⛔ Absent, not zero — the same rule as purchasePrice. "No cash today"
    // and "you may not see the cash" are different sentences, and drawing the
    // first for the second is a lie the screen tells confidently.
    const withoutCash = TodayRecord({
      'date': '2026-09-16',
      'company': 'ADI',
      'sales': {'count': 4, 'amount': '900.0000'},
    });

    await tester.pumpWidget(screen(fetch: () async => withoutCash));
    await tester.pumpAndSettle();

    expect(find.text('আজকের বিক্রি'), findsOneWidget);
    expect(find.text('হাতে নগদ'), findsNothing);
    expect(find.text('৳0'), findsNothing);
  });

  // ⭐ The owner's home, 6 Oct 2026, drawn on a screenshot: sale | money in;
  // Recoverable | Payable; "হাতে ও ব্যাংকে মোট" with its four parts; and the
  // principal commission under it.
  const owners = TodayRecord({
    'date': '2026-10-06',
    'company': 'UNIVER BANGLADESH',
    'asOf': '2026-10-06T01:14:00+06:00',
    'sales': {'count': 2, 'amount': '5200.0000'},
    'collections': {'count': 1, 'amount': '1500.0000'},
    'inflow': {'amount': '9400.0000'},
    'dues': {'amount': '609011.2300', 'shops': 32},
    'payable': {'amount': '125000.0000', 'books': '120000.0000', 'handLoans': '5000.0000'},
    'cashInHand': {'amount': '3000.0000'},
    'money': {
      'amount': '48000.0000',
      'cash': '3000.0000',
      'mfs': '5000.0000',
      'bank': '40000.0000',
      'transit': '700.0000',
    },
    'principals': [
      {
        'name': 'S-001 — Star Line',
        'period': '02/09/2026 – 01/10/2026',
        'basisRate': 'Margin 3.85%',
        'inflow': '100000.0000',
        'commission': '3850.0000',
        'share': '96150.0000',
        'paid': '90000.0000',
        'balance': '6150.0000',
      },
    ],
  });

  testWidgets("the owner's rows: sale beside money in, recoverable beside payable",
      (tester) async {
    await tester.pumpWidget(screen(fetch: () async => owners));
    await tester.pumpAndSettle();

    expect(find.text('আজকের বিক্রি'), findsOneWidget);
    expect(find.text('আজকের ইনফ্লো/আদায়'), findsOneWidget);
    expect(find.text('৳9,400'), findsOneWidget);
    expect(find.textContaining('আদায় ৳1,500'), findsOneWidget);

    expect(find.text('পাওনা (Recoverable)'), findsOneWidget);
    expect(find.text('৳609,011.23'), findsOneWidget);
    expect(find.textContaining('32 টি দোকান'), findsOneWidget);
    expect(find.text('দেনা (Payable)'), findsOneWidget);
    expect(find.text('৳125,000'), findsOneWidget);
    expect(find.textContaining('হাতধার ৳5,000 সহ'), findsOneWidget);

    // ⓘ the old cards are not drawn as well — one figure, one place
    expect(find.text('আদায় ও বকেয়া'), findsNothing);
    expect(find.text('হাতে নগদ'), findsNothing);
  });

  testWidgets("the web's money box: the total and cash · MFS · bank · on the road",
      (tester) async {
    await tester.pumpWidget(screen(fetch: () async => owners));
    await tester.pumpAndSettle();

    expect(find.text('হাতে ও ব্যাংকে মোট'), findsOneWidget);
    expect(find.text('৳48,000'), findsOneWidget);
    for (final label in ['নগদ', 'MFS', 'ব্যাংক', 'পথে']) {
      expect(find.text(label), findsOneWidget, reason: label);
    }
    expect(find.text('৳40,000'), findsOneWidget);
    expect(find.text('৳700'), findsOneWidget);
  });

  testWidgets('the principal commission: commission, paid and the balance in words',
      (tester) async {
    await tester.pumpWidget(screen(fetch: () async => owners));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('প্রিন্সিপালের কমিশন'), 200);

    expect(find.text('S-001 — Star Line'), findsOneWidget);
    expect(find.textContaining('কমিশন ৳3,850'), findsOneWidget);
    expect(find.textContaining('দেওয়া ৳90,000'), findsOneWidget);
    expect(find.text('দিতে হবে ৳6,150'), findsOneWidget);
  });

  testWidgets('an older server without the new blocks still gets the old cards',
      (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    expect(find.text('হাতে নগদ'), findsOneWidget);
    expect(find.text('হাতে ও ব্যাংকে মোট'), findsNothing);
    expect(find.text('দেনা (Payable)'), findsNothing);
  });

  testWidgets('with no signal it shows what it last knew, and says so',
      (tester) async {
    await tester.pumpWidget(screen(
      lastKnown: () => full,
      fetch: () async => throw DioException(
        requestOptions: RequestOptions(path: '/dashboard/today'),
        type: DioExceptionType.connectionError,
      ),
    ));
    await tester.pumpAndSettle();

    // Better than an empty screen — provided it admits what it is. An owner
    // reading the morning's cash at nine at night is reading a number that
    // stopped being true hours ago.
    expect(find.text('৳18,500'), findsOneWidget);
    expect(find.textContaining('এই ফোনে রাখা ছিল'), findsOneWidget);
    expect(find.textContaining('সংযোগ নেই'), findsOneWidget);
  });

  testWidgets('with no signal and nothing remembered, it says that instead',
      (tester) async {
    await tester.pumpWidget(screen(
      fetch: () async => throw DioException(
        requestOptions: RequestOptions(path: '/dashboard/today'),
        type: DioExceptionType.connectionError,
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.textContaining('সংযোগ নেই'), findsOneWidget);
    // No figures invented to fill the space.
    expect(find.textContaining('৳'), findsNothing);
  });

  group('the payload itself', () {
    test('money is read from strings, counts from numbers', () {
      expect(full.sales!.amount, 45200);
      expect(full.sales!.count, 12);
      expect(full.dues!.shops, 41);
      expect(full.pendingApprovals, 3);
    });

    test('an absent block is null, never a zero figure', () {
      const empty = TodayRecord({'date': '2026-09-16'});
      expect(empty.cashInHand, isNull);
      expect(empty.sales, isNull);
      expect(empty.pendingApprovals, isNull);
    });

    test('the day is the server\'s, not the phone\'s', () {
      // A phone's clock can be changed and a business day need not end at
      // midnight — docs/Contract §৮ rule গ.
      expect(full.date, '2026-09-16');
    });
  });
}
