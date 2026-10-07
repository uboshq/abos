import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';

/// The approvals inbox, driven the way a person drives it.
///
/// <p>Written at the same time as the screen and before the endpoint exists,
/// against the shape agreed in writing in docs/Contract §৫. That ordering is
/// the point: the six payload bugs of 12 September all came from screens
/// built against a shape nobody had agreed, which then passed every test for
/// a month while drawing nothing.
///
/// <p>The payload literal below is the one in that contract. When the server
/// side lands, `payload_contract_test.dart` gains a pin that reads the real
/// controller — until then there is no PHP file to read, and a pin matching
/// nothing would pass while proving nothing.
void main() {
  const row = <String, dynamic>{
    'id': '01a0c3f0-0000-7000-8000-0000000000a1',
    'documentType': 'PurchaseBill',
    'documentNo': 'PB-2609-0007',
    'action': 'confirm',
    'amount': '125000.0000',
    'currentLevel': 2,
    'requestedAt': '2026-09-13T09:40:00+06:00',
    'requesterName': 'করিম',
    'summary': 'মেসার্স রহমান ট্রেডার্স',
  };

  Widget screen({
    Future<ApprovalPage> Function()? load,
    Future<void> Function(String, String?)? approve,
    Future<void> Function(String, String)? reject,
  }) =>
      MaterialApp(
        home: ApprovalInboxScreen(
          loadPending: load ??
              () async => const ApprovalPage(rows: [ApprovalRecord(row)]),
          approve: approve ?? (_, __) async {},
          reject: reject ?? (_, __) async {},
        ),
      );

  Finder inDialog(String text) => find.descendant(
        of: find.byType(AlertDialog),
        matching: find.text(text),
      );

  testWidgets('a waiting document shows what it is and what it is worth',
      (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    expect(find.text('ক্রয় বিল'), findsOneWidget,
        reason: 'the model name is English on the wire and Bangla on screen');
    expect(find.text('PB-2609-0007'), findsOneWidget);
    // decimal:4 on the wire, readable here — the salePrice lesson.
    expect(find.text('৳125,000'), findsOneWidget);
    expect(find.textContaining('করিম'), findsOneWidget);
    expect(find.textContaining('ধাপ 2'), findsOneWidget);
    expect(find.text('মেসার্স রহমান ট্রেডার্স'), findsOneWidget);
  });

  // ⭐ সইয়ের আগে আসল কাগজ — মালিক, ৪ অক্টোবর ২০২৬। The paper opens from the card when the server named it
  // (`documentId`, its public_id); a request whose paper is gone offers nothing to open.
  testWidgets('a card with its paper offers the PDF, one without does not',
      (tester) async {
    await tester.pumpWidget(screen(
      load: () async => ApprovalPage(rows: [
        ApprovalRecord(
            {...row, 'documentId': '01a0c3f0-0000-7000-8000-0000000000b7'}),
        ApprovalRecord(
            {...row, 'id': 'no-paper', 'documentNo': 'PB-2609-0008'}),
      ]),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(Key('approval-paper-${row['id']}')), findsOneWidget);
    expect(find.byKey(const Key('approval-paper-no-paper')), findsNothing);
    expect(find.text('কাগজ দেখুন'), findsOneWidget);
  });

  testWidgets('an empty inbox is good news, not an error', (tester) async {
    await tester
        .pumpWidget(screen(load: () async => const ApprovalPage(rows: [])));
    await tester.pumpAndSettle();

    expect(find.text('কিছু অপেক্ষা করছে না'), findsOneWidget);
    expect(find.text('তালিকা আনা গেল না'), findsNothing);
  });

  testWidgets('an unreachable server does not read as an empty inbox',
      (tester) async {
    // The two are opposite facts. A screen that drew "nothing is waiting" for
    // both would tell someone their work was done when it was only invisible.
    await tester.pumpWidget(screen(load: () async {
      throw DioException(
        requestOptions: RequestOptions(path: '/approvals/pending'),
        type: DioExceptionType.connectionError,
      );
    }));
    await tester.pumpAndSettle();

    expect(find.text('তালিকা আনা গেল না'), findsOneWidget);
    expect(find.text('কিছু অপেক্ষা করছে না'), findsNothing);
    expect(find.textContaining('সংযোগ নেই'), findsOneWidget);
  });

  testWidgets('approving takes the row off the list', (tester) async {
    var approvedId = '';
    await tester.pumpWidget(screen(approve: (id, _) async => approvedId = id));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'অনুমোদন'));
    await tester.pumpAndSettle();

    // The dialog's own confirm button, not the card's.
    await tester.tap(inDialog('অনুমোদন'));
    await tester.pumpAndSettle();

    expect(approvedId, '01a0c3f0-0000-7000-8000-0000000000a1');
    expect(find.text('PB-2609-0007'), findsNothing);
    expect(find.text('কিছু অপেক্ষা করছে না'), findsOneWidget);
  });

  testWidgets('a rejection cannot be sent without a reason', (tester) async {
    var sentReason = '';
    await tester.pumpWidget(screen(reject: (_, r) async => sentReason = r));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(TextButton, 'প্রত্যাখ্যান'));
    await tester.pumpAndSettle();

    // Dead until a reason is typed. ApprovalEngine::reject takes a non-null
    // string and ApprovalsApi.reject checks it too — three places, because a
    // refusal with no reason reaches the requester as something they cannot
    // act on.
    final confirm = tester.widget<FilledButton>(find.descendant(
      of: find.byType(AlertDialog),
      matching: find.widgetWithText(FilledButton, 'প্রত্যাখ্যান'),
    ));
    expect(confirm.onPressed, isNull);

    await tester.enterText(
        find.descendant(
            of: find.byType(AlertDialog), matching: find.byType(TextField)),
        'দর বেশি');
    await tester.pumpAndSettle();

    await tester.tap(inDialog('প্রত্যাখ্যান'));
    await tester.pumpAndSettle();

    expect(sentReason, 'দর বেশি');
    expect(find.text('PB-2609-0007'), findsNothing);
  });

  testWidgets('a colleague deciding first is not an error', (tester) async {
    // docs/Contract §৫ rule খ: two people can be looking at the same inbox.
    // The second to tap is not seeing a failure — they are seeing that it is
    // no longer theirs to decide, and the row leaves quietly.
    await tester.pumpWidget(screen(approve: (_, __) async {
      throw DioException(
        requestOptions: RequestOptions(path: '/approvals/x/approve'),
        response: Response(
          requestOptions: RequestOptions(path: '/approvals/x/approve'),
          statusCode: 409,
        ),
      );
    }));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'অনুমোদন'));
    await tester.pumpAndSettle();
    await tester.tap(inDialog('অনুমোদন'));
    await tester.pumpAndSettle();

    expect(find.text('PB-2609-0007'), findsNothing);
    expect(find.textContaining('ইতিমধ্যে নিষ্পত্তি'), findsOneWidget);
  });

  group('salary never reaches a phone', () {
    test('a payroll row is recognised so it can be dropped', () {
      expect(
          const ApprovalRecord({'documentType': 'Payroll'}).isPayroll, isTrue);
      expect(const ApprovalRecord(row).isPayroll, isFalse);
    });

    // ⚠️ The real gate is the server's query — docs/Contract §৫ and the
    // owner's decision of 13 September. ApprovalsApi drops these a second
    // time, the same two-lock shape as SyncEngine's offline-write guard,
    // because the leak would be quiet: the endpoint is called "approvals",
    // not "payroll", so a salary amount arriving through it would look
    // ordinary to every screen and every reviewer.
    //
    // The server's own test asserts both halves — a payroll approval that
    // does not appear AND a non-payroll one that does — because a
    // filter-everything query would pass "the list is empty" on its own.
  });

  testWidgets('a voucher waiting for a signature shows its lines, its facts and its totals before signing (owner, 7 Oct 2026)', (tester) async {
    const voucher = <String, dynamic>{
      'id': '01a0c3f0-0000-7000-8000-0000000000v1', 'documentType': 'Voucher', 'documentNo': 'RCV-0007',
      'documentId': '01a115da-bfcf-7284-8468-c419d8e00420', 'action': 'receipt', 'amount': '5000.0000', 'currentLevel': 1,
    };
    String? askedFor;
    tester.view.physicalSize = const Size(900, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(
      home: ApprovalInboxScreen(
        loadPending: () async => const ApprovalPage(rows: [ApprovalRecord(voucher)]),
        approve: (_, __) async {},
        reject: (_, __) async {},
        loadSheet: (id) async {
          askedFor = id;
          return ApprovalSheet.fromJson({
            'facts': [
              {'label': 'তারিখ', 'value': '07 Oct 2026'},
              {'label': 'বর্ণনা', 'value': 'রহিম স্টোরের আদায়'},
            ],
            'columns': [
              {'key': 'account', 'label': 'খাত'},
              {'key': 'party', 'label': 'পক্ষ'},
              {'key': 'debit', 'label': 'ডেবিট', 'numeric': true},
              {'key': 'credit', 'label': 'ক্রেডিট', 'numeric': true},
            ],
            'rows': [
              {'account': 'নগদ', 'party': null, 'debit': '5,000.00', 'credit': null},
              {'account': 'প্রাপ্য', 'party': 'রহিম স্টোর', 'debit': null, 'credit': '5,000.00'},
            ],
            'totals': {'debit': '5,000.00', 'credit': '5,000.00'},
          });
        },
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.text('ভাউচার'), findsOneWidget, reason: 'ধরন বাংলায় — আগে "Voucher"');
    await tester.tap(find.byKey(const Key('approval-details-01a0c3f0-0000-7000-8000-0000000000v1')));
    await tester.pumpAndSettle();

    expect(askedFor, '01a0c3f0-0000-7000-8000-0000000000v1');
    expect(find.text('বর্ণনা: রহিম স্টোরের আদায়'), findsOneWidget);
    expect(find.text('নগদ — ডেবিট 5,000.00'), findsOneWidget);
    expect(find.text('প্রাপ্য · রহিম স্টোর — ক্রেডিট 5,000.00'), findsOneWidget);
    expect(find.text('মোট — ডেবিট 5,000.00 · ক্রেডিট 5,000.00'), findsOneWidget);
  });
}
