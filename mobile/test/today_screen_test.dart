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
