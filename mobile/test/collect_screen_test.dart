import 'dart:io';

import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/books/collection_plan.dart';
import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/features/books/collect_screen.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/collect_fake.dart';

/// ⭐ আদায়ের এক পাতা (০.৪.৪৫) — মালিক, ২১ অক্টোবর ২০২৬: "ok ekoni kaj kore live e daw"; চূড়ান্ত নকশা ১০০ (পাতা ১, বিষয়ের
/// তালিকা পাতা ৫)। প্রতিটা দাবির নামে বিষয়ের নম্বর। সব নকল সার্ভারে (a5-এর চূড়ান্ত চুক্তির আকার), নেট নয়।
var _seq = 0;
String _key() => 'key-${_seq++}';

final List<CollectionSaved> _opened = [];
Future<void> _receipt(BuildContext context, CollectionSaved saved) async => _opened.add(saved);

Future<String?> Function(BuildContext, {required bool camera}) _picker(String? path) =>
    (context, {required camera}) async => path;

CollectScreen _screen(FakeCollect api, {int slipBytes = 1000}) => CollectScreen(
      api: api,
      books: FakeBooks(),
      newChangeId: _key,
      today: DateTime(2026, 10, 21),
      previewDelay: Duration.zero,
      openReceipt: _receipt,
      pickSlip: _picker('C:/slips/slip-21.jpg'),
      slipSize: (_) async => slipBytes,
    );

Future<void> _pump(WidgetTester tester, Widget screen, {Size size = const Size(412, 4000), double textScale = 1}) async {
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
    home: MediaQuery.withClampedTextScaling(
      minScaleFactor: textScale,
      maxScaleFactor: textScale,
      child: screen,
    ),
  ));
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, String key) async {
  final f = find.byKey(ValueKey(key));
  await tester.ensureVisible(f);
  await tester.pumpAndSettle();
  await tester.tap(f);
  await tester.pumpAndSettle();
}

Future<void> _type(WidgetTester tester, String key, String text) async {
  final f = find.byKey(ValueKey(key));
  await tester.ensureVisible(f);
  await tester.enterText(f, text);
  await tester.pumpAndSettle();
}

Future<void> _pickShop(WidgetTester tester) async {
  await _tap(tester, 'collect-customer');
  await tester.tap(find.byKey(const ValueKey('collect-pick-cus-1')));
  await tester.pumpAndSettle();
}

String _figure(WidgetTester tester, String key) => tester
    .widgetList<Text>(find.descendant(of: find.byKey(ValueKey(key)), matching: find.byType(Text)))
    .map((t) => t.data)
    .join(' | ');

String _field(WidgetTester tester, String key) => tester.widget<TextField>(find.byKey(ValueKey(key))).controller!.text;

/// পোস্ট → সারাংশ → এক চাপ
Future<void> _post(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('collect-post')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('collect-check-post')));
  await tester.pumpAndSettle();
}

void main() {
  setUp(_opened.clear);

  testWidgets('1 · 13 · 14 · the three figures, the party "name · point · branch" with due, credit limit and oldest days, the month and the branch',
      (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);

    expect(find.text('করিম স্টোর · ফুলপুর পয়েন্ট · ময়মনসিংহ'), findsOneWidget, reason: '⛔ গ্রাহকের নাম · পয়েন্ট · শাখা নয়');
    expect(find.text('বকেয়া ৳53,200.00 · বাকির সীমা ৳1,00,000.00 · সবচেয়ে পুরনো বাকি 49 দিন'), findsOneWidget);
    expect(find.text('শাখা: গ্রাহকের শাখা (ময়মনসিংহ)'), findsOneWidget, reason: 'বিষয় ১৪: শাখা গ্রাহকের');
    expect(find.text('মাস খোলা'), findsOneWidget, reason: 'বিষয় ১৩');
    expect(find.text('নম্বর: সংরক্ষণ করলে বসবে'), findsOneWidget);

    await _type(tester, 'collect-amount', '45000');
    expect(_figure(tester, 'collect-figure-due'), 'গ্রাহকের বকেয়া | ৳53,200.00');
    expect(_figure(tester, 'collect-figure-this'), 'এই আদায় | ৳45,000.00');
    expect(_figure(tester, 'collect-figure-left'), 'বাকি থাকবে | ৳8,200.00', reason: '⛔ বকেয়া − এই আদায় ভুল');
    expect(find.byKey(const ValueKey('collect-over-due')), findsNothing);
  });

  testWidgets('"পুরো বকেয়া" fills the due in one tap', (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _tap(tester, 'collect-full-due');
    expect(_field(tester, 'collect-amount'), '53200.00');
    expect(_figure(tester, 'collect-figure-left'), 'বাকি থাকবে | ৳0.00');
  });

  testWidgets('4 · more than the due warns, and the extra shows as the customer\'s credit (Cr) and as unapplied', (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '60000');

    expect(find.text('বকেয়ার চেয়ে ৳6,800.00 বেশি — বাড়তি টাকা গ্রাহকের খাতায় জমা (Cr) থাকবে।'), findsOneWidget,
        reason: '⛔ বকেয়ার বেশি টাকায় সতর্কতা নেই');
    expect(_figure(tester, 'collect-figure-left'), 'গ্রাহকের খাতায় জমা থাকবে | ৳6,800.00');
    // ⓘ 60,000 + ক্রেডিট নোট 2,000 = 62,000 হাতে; বিল 53,200 — বাকি 8,800 অপ্রয়োগকৃত, গ্রাহকের খাতায়
    expect(_figure(tester, 'collect-unapplied'), contains('৳8,800.00'));
    expect(find.byKey(const ValueKey('collect-balanced')), findsOneWidget);
  });

  testWidgets('18 · an SR\'s picker shows only the shops the server gave, each as "name · point · branch"', (tester) async {
    await _pump(tester, _screen(FakeCollect(shops: const [shop])));
    await _tap(tester, 'collect-customer');
    expect(find.byType(ListTile), findsOneWidget, reason: '⛔ সার্ভারের দেওয়া দোকানের বাইরে কেউ দেখা গেল');
    expect(find.byKey(const ValueKey('collect-pick-cus-1')), findsOneWidget);
    expect(find.text('করিম স্টোর · ফুলপুর পয়েন্ট · ময়মনসিংহ'), findsOneWidget);
  });

  testWidgets('the account defaults to the person\'s own cash, ahead of the server\'s first cash', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    expect(find.text('1101-07 · আল-আমিনের নগদ · আমার হাতের নগদ'), findsOneWidget, reason: '⛔ নিজের হাতের নগদ আগে থেকে বাছা নয়');
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _post(tester);
    expect(api.sent.single.accountId, 'acc-own');
    expect(api.sent.single.toPayload()['instrument'], 'cash', reason: 'পদ্ধতি = instrument');
  });

  testWidgets('9 · the reference/TrxID shows only for MFS and bank; bank adds mode, value date and one bank-charge field', (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    expect(find.byKey(const ValueKey('collect-reference')), findsNothing, reason: '⛔ নগদে TrxID চাওয়া হলো');
    expect(find.byKey(const ValueKey('collect-bank-charge')), findsNothing);

    await _tap(tester, 'collect-method-mfs');
    expect(find.byKey(const ValueKey('collect-reference')), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-bank-charge')), findsNothing);
    expect(find.text('1105 · bKash মার্চেন্ট'), findsOneWidget, reason: 'MFS-এ MFS-এর খাত');

    await _tap(tester, 'collect-method-transfer');
    expect(find.byKey(const ValueKey('collect-reference')), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-transfer-mode')), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-value-date')), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-bank-charge')), findsOneWidget);

    await _tap(tester, 'collect-method-cash');
    expect(find.byKey(const ValueKey('collect-reference')), findsNothing);
  });

  testWidgets('cash posts without any reference; MFS and bank refuse to post without one', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-mfs');
    await tester.tap(find.byKey(const ValueKey('collect-post')));
    await tester.pumpAndSettle();
    expect(find.text('রেফারেন্স / TrxID দিন।'), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-summary')), findsNothing);
    expect(api.sent, isEmpty);

    await _tap(tester, 'collect-method-cash');
    await _post(tester);
    expect(api.sent, hasLength(1), reason: '⛔ নগদেও TrxID চাওয়া হলো — নগদ আদায় আটকে গেল');
    expect(api.sent.single.toPayload().containsKey('instrument_no'), isFalse);
    expect(api.sent.single.toPayload().containsKey('reference'), isFalse);
  });

  testWidgets('3 · open bills, credits and notes in one table, oldest first, with overdue days; the credit is used first',
      (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');

    // ⓘ গুটানো: এক লাইনের সারাংশ
    expect(find.text('নিজে থেকে: পুরনো বিল আগে — INV-0094 ৳30,000.00 · INV-0101 ৳15,200.00 · CN-0007 ৳-2,000.00 · INV-0112 ৳1,800.00'),
        findsOneWidget);
    expect(find.byKey(const ValueKey('collect-apply-INV-0094')), findsNothing, reason: 'টেবিল আগে থেকেই খোলা — গুটানো থাকার কথা');

    await _tap(tester, 'collect-bills-change');
    expect(_field(tester, 'collect-apply-INV-0094'), '30000.00', reason: '⛔ পুরনো বিল আগে নয়');
    expect(_field(tester, 'collect-apply-INV-0101'), '15200.00');
    expect(_field(tester, 'collect-apply-CN-0007'), '2000.00', reason: '⛔ খোলা ক্রেডিট নোট কাজে লাগেনি');
    expect(_field(tester, 'collect-apply-INV-0112'), '1800.00', reason: '⛔ সবচেয়ে নতুন বিলে আগে বসেছে');
    expect(find.text('ক্রেডিট নোট'), findsOneWidget);
    expect(find.text('8 দিন দেরি'), findsOneWidget, reason: 'দেরির দিন নেই');
    expect(_figure(tester, 'collect-applied-total'), 'মোট প্রয়োগ | ৳45,000.00');

    // ⓘ টেবিলের ক্রম সার্ভারের (পুরনো আগে) — ক্রেডিট নোট তার তারিখের জায়গায়
    final ys = [
      for (final no in ['INV-0094', 'INV-0101', 'CN-0007', 'INV-0112']) tester.getTopLeft(find.byKey(ValueKey('collect-item-$no'))).dy,
    ];
    expect([...ys]..sort(), ys);
  });

  testWidgets('3 · editing an allocation keeps the total and refuses over-allocation', (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-bills-change');

    await _type(tester, 'collect-apply-INV-0112', '5000');
    expect(_field(tester, 'collect-apply-INV-0112'), '1800.00', reason: '⛔ হাতের টাকার বেশি বসানো গেল');
    expect(find.text('এর বেশি বসানো যায় না — সর্বোচ্চ ৳1,800.00।'), findsOneWidget);
    expect(_figure(tester, 'collect-applied-total'), 'মোট প্রয়োগ | ৳45,000.00', reason: '⛔ মোট বদলে গেল');

    await _type(tester, 'collect-apply-INV-0094', '20000');
    expect(_figure(tester, 'collect-applied-total'), 'মোট প্রয়োগ | ৳35,000.00');
    expect(_figure(tester, 'collect-unapplied'), contains('৳10,000.00'), reason: 'না-বসানো টাকা গ্রাহকের খাতায়');
    expect(find.byKey(const ValueKey('collect-balanced')), findsOneWidget);

    await _tap(tester, 'collect-tick-INV-0112');
    expect(_field(tester, 'collect-apply-INV-0112'), '', reason: 'টিক তুললে শূন্য');
    await _tap(tester, 'collect-oldest-first');
    expect(_field(tester, 'collect-apply-INV-0094'), '30000.00', reason: '"পুরনো বিল আগে" আবার বসায়');
  });

  test('3 · the plan never puts more on the invoices than the money in hand, and always balances', () {
    final bills = OpenBills.fromJson(openBillsJson());
    for (final received in [0, 100, 4500000, 5320000, 9000000]) {
      for (final diff in [0, 5000]) {
        final plan = CollectionPlan(bills: bills.bills, credits: bills.credits)
          ..received = received
          ..difference = diff
          ..withheld = 2500;
        plan.autoAllocate();
        expect(plan.billsApplied <= plan.pool, isTrue);
        expect(plan.unapplied >= 0, isTrue);
        expect(plan.balanced, isTrue);
        for (final r in plan.bills) {
          expect(plan.setBill(r, r.item.open + 1), isFalse, reason: '⛔ বিলের বাকির বেশি বসল');
          expect(plan.billsApplied <= plan.pool, isTrue, reason: '⛔ হাতের টাকার বেশি বসল');
        }
      }
    }
  });

  testWidgets('4 · a discount or rounding goes for the owner\'s signature; VDS and AIT count as settled; the summary balances',
      (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '40000');
    await _type(tester, 'collect-difference', '1000');
    expect(find.byKey(const ValueKey('collect-difference-sign')), findsOneWidget, reason: '⛔ ছাড়ে মালিকের সইয়ের কথা নেই');
    await _tap(tester, 'collect-withheld');
    await _type(tester, 'collect-vds', '500');
    await _type(tester, 'collect-ait', '200');

    // ⓘ 40,000 + 1,000 + 700 + ক্রেডিট 2,000 = 43,700 বিলে; ক্রেডিট বাদে প্রয়োগ 41,700
    expect(_figure(tester, 'collect-net-applied'), 'বিলে প্রয়োগ | ৳41,700.00');
    expect(_figure(tester, 'collect-unapplied'), contains('৳0.00'));
    expect(_figure(tester, 'collect-figure-left'), 'বাকি থাকবে | ৳11,500.00', reason: 'বকেয়া − আদায় − ছাড় − কাটা');
    expect(find.byKey(const ValueKey('collect-balanced')), findsOneWidget);

    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p['difference'], {'kind': 'discount', 'amount': '1000.00'});
    expect(p['vds'], '500.00');
    expect(p['ait'], '200.00');
  });

  testWidgets('10 · 17 · "পোস্ট" shows the summary first — customer, amount, words, account, bills, the journal — and nothing goes before the one tap',
      (tester) async {
    final api = FakeCollect(preview: previewJson(needsSignature: true));
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');

    // ⓘ পাতাতেও খাতায় যা যাবে, মোট মেলে
    expect(find.byKey(const ValueKey('collect-journal')), findsOneWidget);
    expect(find.text('মোট মিলেছে'), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-needs-signature')), findsOneWidget, reason: 'বিষয় ৪/১১: সইয়ে যাবে বলা নেই');

    await tester.tap(find.byKey(const ValueKey('collect-post')));
    await tester.pumpAndSettle();
    final sheet = find.byKey(const ValueKey('collect-summary'));
    expect(sheet, findsOneWidget, reason: '⛔ সারাংশ ছাড়াই পোস্ট');
    expect(api.sent, isEmpty, reason: '⛔ এক চাপের আগেই পাঠানো হলো');
    Finder inSheet(String text) => find.descendant(of: sheet, matching: find.text(text));
    expect(inSheet('করিম স্টোর · ফুলপুর পয়েন্ট · ময়মনসিংহ'), findsOneWidget);
    expect(inSheet('৳45,000.00'), findsOneWidget);
    expect(inSheet('পঁয়তাল্লিশ হাজার টাকা মাত্র'), findsOneWidget, reason: '⛔ কথায় টাকা সার্ভারের (রসিদের হুবহু) নয়');
    expect(inSheet('নগদ · 1101-07 · আল-আমিনের নগদ'), findsOneWidget);
    expect(inSheet('INV-0094'), findsOneWidget);
    expect(inSheet('খাতায় যা যাবে'), findsOneWidget);
    expect(inSheet('1102 ডাচ-বাংলা ব্যাংক'), findsOneWidget);
    expect(inSheet('1130 পাওনা · করিম স্টোর'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('collect-check-back')));
    await tester.pumpAndSettle();
    expect(api.sent, isEmpty, reason: '⛔ "ফিরে যান"-এও পাঠানো হলো');

    await _post(tester);
    expect(api.sent, hasLength(1));
    expect(api.sent.single.confirm, isTrue);
  });

  testWidgets('posting sends the lines (credit negative) and the difference; a retry after no network carries the same key — one receipt',
      (tester) async {
    final api = FakeCollect(offlineTimes: 1);
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '44000');
    await _type(tester, 'collect-difference', '1000');
    await _post(tester);
    expect(find.textContaining('নেট লাগবে'), findsOneWidget);
    expect(api.sent, isEmpty);

    await _post(tester);
    expect(api.keys, hasLength(2));
    expect(api.keys[1], api.keys[0], reason: '⛔ আবার চাপায় নতুন চাবি — একই আদায় দুবার বসত');
    expect(api.receipts, hasLength(1), reason: '⛔ দুটো রসিদ');
    final p = api.sent.single.toPayload();
    expect(p['lines'], [
      {'kind': 'bill', 'id': 'inv-94', 'amount': '30000.00'},
      {'kind': 'bill', 'id': 'inv-101', 'amount': '15200.00'},
      {'kind': 'bill', 'id': 'inv-112', 'amount': '1800.00'},
      {'kind': 'credit_note', 'id': 'cn-7', 'amount': '-2000.00'},
    ]);
    expect(p['difference'], {'kind': 'discount', 'amount': '1000.00'});
    expect(p['customerId'], 'cus-1');
    expect(p['amount'], '44000');
  });

  testWidgets('17 · after posting: the number and the state chip in the header, the amount in words, and the receipt opens',
      (tester) async {
    final api = FakeCollect(saved: const {
      'no': 'RCV-2610-0012',
      'state': 'awaiting',
      'amount_words': 'পঁয়তাল্লিশ হাজার টাকা মাত্র',
      'receipt_pdf': '/documents/Collection/col-1/receipt.pdf',
      'applied': [
        {'kind': 'bill', 'no': 'INV-0094', 'amount': '30000.0000'},
      ],
      'unapplied': '0.0000',
    });
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _post(tester);

    expect(find.text('নম্বর: RCV-2610-0012'), findsOneWidget);
    final chip = tester.widget<AppStatusChip>(find.byKey(const ValueKey('collect-state')));
    expect(chip.state, PaperState.waiting, reason: '⛔ সার্ভারের অবস্থা চিপে নেই');
    expect(find.text('কথায়: পঁয়তাল্লিশ হাজার টাকা মাত্র'), findsOneWidget);
    expect(_opened, hasLength(1), reason: '⛔ পোস্টের পরে রসিদ খোলেনি');
    expect(_opened.single.id, 'col-1');
    expect(_opened.single.receipt, '/documents/Collection/col-1/receipt.pdf');

    await tester.tap(find.byKey(const ValueKey('collect-open-receipt')));
    await tester.pumpAndSettle();
    expect(_opened, hasLength(2));
  });

  testWidgets('"খসড়া রাখুন" saves without the summary and without confirming; the old "এখনই নিশ্চিত" switch is gone', (tester) async {
    final api = FakeCollect(saved: const {'no': 'RCV-2610-0013', 'state': 'draft'});
    await _pump(tester, _screen(api));
    expect(find.text('এখনই নিশ্চিত'), findsNothing);
    expect(find.byType(SwitchListTile), findsNothing);
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await tester.tap(find.byKey(const ValueKey('collect-draft')));
    await tester.pumpAndSettle();
    expect(api.sent.single.confirm, isFalse);
    expect(tester.widget<AppStatusChip>(find.byKey(const ValueKey('collect-state'))).state, PaperState.draft);
    expect(_opened, isEmpty, reason: 'খসড়ায় রসিদ নয়');
  });

  testWidgets('7 · the same reference twice is refused, with the earlier paper\'s number from the server', (tester) async {
    final api = FakeCollect(refusal: 'এই TrxID এই খাতে আগে RCV-2610-0003-এ নেওয়া হয়েছে।');
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-mfs');
    await _type(tester, 'collect-reference', 'BK7781');
    await _post(tester);
    expect(find.text('এই TrxID এই খাতে আগে RCV-2610-0003-এ নেওয়া হয়েছে।'), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-done')), findsNothing);

    // ⓘ ঠিক করে আবার — নতুন চাবি (ফেরানো চাবি আবার পাঠালে আবার ফেরে)
    api.refusal = null;
    await _type(tester, 'collect-reference', 'BK7782');
    await _post(tester);
    expect(api.keys[1], isNot(api.keys[0]));
  });

  testWidgets('13 · the server\'s stops block posting and are shown; a closed month says so in the header', (tester) async {
    final api = FakeCollect(
      bills: openBillsJson(monthOpen: false),
      preview: previewJson(stops: ['অক্টোবর 2026 মাস বন্ধ — এই তারিখে পোস্ট হবে না।']),
    );
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    expect(find.text('মাস বন্ধ — পোস্ট হবে না'), findsWidgets);
    expect(find.text('অক্টোবর 2026 মাস বন্ধ — এই তারিখে পোস্ট হবে না।'), findsOneWidget);
    expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed, isNull,
        reason: '⛔ বাধা থাকতেও পোস্ট খোলা');
  });

  testWidgets('15 · a required slip must be chosen before posting, and it is sent after saving', (tester) async {
    final api = FakeCollect(bills: openBillsJson(slipRequired: true));
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    expect(find.text('স্লিপের ছবি *'), findsOneWidget);
    await _type(tester, 'collect-amount', '45000');
    await tester.tap(find.byKey(const ValueKey('collect-post')));
    await tester.pumpAndSettle();
    expect(find.text('স্লিপের ছবি দিন — এই কোম্পানিতে বাধ্যতামূলক।'), findsOneWidget);
    expect(api.sent, isEmpty);

    await _tap(tester, 'collect-slip-gallery');
    expect(find.text('ছবি বাছা হয়েছে: slip-21.jpg'), findsOneWidget);
    await _post(tester);
    expect(api.slips, [('col-1', 'C:/slips/slip-21.jpg')]);
  });

  testWidgets('15 · a slip over 5 MB is refused before it is kept', (tester) async {
    await _pump(tester, _screen(FakeCollect(), slipBytes: 6 * 1024 * 1024));
    await _pickShop(tester);
    await _tap(tester, 'collect-slip-camera');
    expect(find.text('ছবিটা 5 MB-এর বেশি — ছোট ছবি দিন।'), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-slip-chosen')), findsNothing);
  });

  // ── ⭐ টাকার-পদ্ধতির মডেল (a5-এর উত্তর, ২১ অক্টোবর ২০২৬) ──
  testWidgets('a bank transfer sends instrument "transfer" and the chosen transfer_mode_id from setup, with the TrxID in instrument_no',
      (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-transfer');
    expect(find.text('NPSB'), findsOneWidget, reason: 'সেটআপের প্রথম মোড আগে থেকে বাছা');
    await _tap(tester, 'collect-transfer-mode');
    await tester.tap(find.text('BEFTN').last);
    await tester.pumpAndSettle();
    await _type(tester, 'collect-reference', 'BF26102100981');
    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p['instrument'], 'transfer');
    expect(p['transfer_mode_id'], 'tm-beftn', reason: '⛔ ট্রান্সফার মোড গেল না');
    expect(p['instrument_no'], 'BF26102100981');
    expect(p['accountId'], 'acc-dbbl');
    expect(p.containsKey('deposit_slip_no'), isFalse);
  });

  testWidgets('a cash deposit sends instrument "deposit" and deposit_slip_no, and refuses to post without the slip number', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-deposit');
    expect(find.byKey(const ValueKey('collect-reference')), findsNothing, reason: 'নগদ জমায় TrxID নয়, স্লিপ নম্বর');
    expect(find.byKey(const ValueKey('collect-transfer-mode')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('collect-post')));
    await tester.pumpAndSettle();
    expect(find.text('জমার স্লিপ নম্বর দিন।'), findsOneWidget);
    expect(api.sent, isEmpty);

    await _type(tester, 'collect-deposit-slip', 'DS-551');
    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p['instrument'], 'deposit');
    expect(p['deposit_slip_no'], 'DS-551', reason: '⛔ জমার স্লিপ নম্বর গেল না');
    expect(p.containsKey('instrument_no'), isFalse);
    expect(p.containsKey('transfer_mode_id'), isFalse);
  });

  testWidgets('everything unticked sends lines: [] — nothing applied, the whole amount stays as the customer\'s credit', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-bills-change');
    for (final no in ['INV-0094', 'INV-0101', 'INV-0112', 'CN-0007']) {
      await _tap(tester, 'collect-tick-$no');
    }
    expect(_figure(tester, 'collect-unapplied'), contains('৳45,000.00'));
    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p.containsKey('lines'), isTrue, reason: '⛔ লাইন বাদ গেল — সার্ভার তখন পুরনো বিল আগে বসাত');
    expect(p['lines'], isEmpty, reason: '⛔ কিছু না বসাতে চেয়েও লাইন গেল');
  });

  testWidgets('the fallback (open bills failed to load) omits lines, so the server applies oldest first', (tester) async {
    final api = FakeCollect(billsFail: true);
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    expect(find.byKey(const ValueKey('collect-bills-failed')), findsOneWidget);
    await _type(tester, 'collect-amount', '45000');
    await _post(tester);
    expect(api.sent.single.toPayload().containsKey('lines'), isFalse, reason: '⛔ বিল না জেনেও লাইন পাঠানো হলো');
  });

  testWidgets('own cash comes from mine: true; without it the first cash account is chosen', (tester) async {
    final api = FakeCollect(accountList: const [bkash, cash, dbbl]);
    await _pump(tester, _screen(api));
    expect(find.text('1101 · প্রধান নগদ'), findsOneWidget, reason: '⛔ mine না থাকলে প্রথম নগদ নয়');
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _post(tester);
    expect(api.sent.single.accountId, 'acc-cash');
  });

  testWidgets('old server without transfer_modes: no mode picker and no transfer_mode_id, the transfer still posts', (tester) async {
    final api = FakeCollect(oldServer: true);
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-transfer');
    expect(find.byKey(const ValueKey('collect-transfer-mode')), findsNothing);
    await _type(tester, 'collect-reference', 'BF1');
    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p['instrument'], 'transfer');
    expect(p.containsKey('transfer_mode_id'), isFalse);
  });

  group('⛔ old server (fe\'s rule)', () {
    testWidgets('open-bills 404/405: the screen still works, the bills read "নিজে থেকে: পুরনো বিল আগে" without a list, and no lines go',
        (tester) async {
      final api = FakeCollect(oldServer: true);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      expect(find.text('নিজে থেকে: পুরনো বিল আগে'), findsOneWidget);
      expect(find.byKey(const ValueKey('collect-bills-change')), findsNothing);
      expect(find.byKey(const ValueKey('collect-difference')), findsNothing, reason: 'পুরনো সার্ভার ছাড় পড়ে না — নীরবে বাদ যেত');
      await _type(tester, 'collect-amount', '45000');
      await _post(tester);
      final p = api.sent.single.toPayload();
      expect(p.containsKey('lines'), isFalse, reason: '⛔ পুরনো সার্ভারে লাইন পাঠানো হলো');
      expect(p.containsKey('difference'), isFalse);
    });

    testWidgets('no preview: the summary shows without "খাতায় যা যাবে", and the words come from the app', (tester) async {
      final api = FakeCollect(noPreview: true);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45200');
      expect(find.byKey(const ValueKey('collect-journal')), findsNothing);
      await tester.tap(find.byKey(const ValueKey('collect-post')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('collect-summary')), findsOneWidget);
      expect(find.byKey(const ValueKey('collect-summary-journal')), findsNothing);
      expect(find.text('45 হাজার 200 টাকা'), findsOneWidget, reason: 'অ্যাপের কথায় টাকা, ইংরেজি অঙ্কে');
      await tester.tap(find.byKey(const ValueKey('collect-check-post')));
      await tester.pumpAndSettle();
      expect(api.sent, hasLength(1));
    });

    testWidgets('no attachment door: the photo option is hidden', (tester) async {
      await _pump(tester, _screen(FakeCollect(oldServer: true)));
      await _pickShop(tester);
      expect(find.byKey(const ValueKey('collect-slip-camera')), findsNothing);
      expect(find.byKey(const ValueKey('collect-slip-gallery')), findsNothing);
    });

    testWidgets('a save answer without no, state or receipt: today\'s receipt page opens, as before', (tester) async {
      final api = FakeCollect(oldServer: true);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _post(tester);
      expect(find.byType(MoneyInScreen), findsOneWidget, reason: '⛔ পুরনো সার্ভারে বসার পরে কিছুই খুলল না');
      expect(find.text('COL-0042'), findsWidgets);
      expect(_opened, isEmpty);
    });
  });

  // ── ⭐ আগের পর্দার দাবি (০.৪.২১–০.৪.৪৪), নতুন পাতায় একই জোরে ──
  group('carried over from the old collection screen', () {
    testWidgets('a collection with an amount typed asks before going back, an empty one does not', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('লেখা ফেলে চলে যাবেন?'), findsNothing, reason: 'খালি ফর্মে প্রশ্ন নয়');

      await _type(tester, 'collect-amount', '500');
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('লেখা ফেলে চলে যাবেন?'), findsOneWidget, reason: '⛔ অঙ্ক লেখা, তবু "পিছনে" কিছু না বলে আদায় ফেলে দিল');
    });

    testWidgets('nothing is sent without a customer or with a malformed amount', (tester) async {
      final api = FakeCollect();
      await _pump(tester, _screen(api));
      await _type(tester, 'collect-amount', '500');
      await tester.tap(find.byKey(const ValueKey('collect-post')));
      await tester.pumpAndSettle();
      expect(find.text('গ্রাহক বাছুন।'), findsOneWidget);

      await _pickShop(tester);
      for (final bad in ['1e5', '0', '12.345', 'abc']) {
        await _type(tester, 'collect-amount', bad);
        await tester.tap(find.byKey(const ValueKey('collect-post')));
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('collect-error')), findsOneWidget, reason: '"$bad"');
        expect(find.byKey(const ValueKey('collect-summary')), findsNothing);
      }
      await tester.tap(find.byKey(const ValueKey('collect-draft')));
      await tester.pumpAndSettle();
      expect(api.sent, isEmpty, reason: '⛔ ভুল অঙ্কেও পাঠানো হলো');
      expect(api.keys, isEmpty);
    });

    testWidgets(
        'an MFS collection carries the customer, amount, date, account, method and TrxID, confirmed; a refusal shows its reason and the fixed retry gets a new key',
        (tester) async {
      final api = FakeCollect(refusal: 'TrxID আগে ব্যবহার হয়েছে');
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '750.50');
      await _tap(tester, 'collect-method-mfs');
      await _type(tester, 'collect-reference', 'TRX-7781');
      await _post(tester);
      expect(find.text('TrxID আগে ব্যবহার হয়েছে'), findsOneWidget);
      expect(api.sent, isEmpty);

      api.refusal = null;
      await _post(tester);
      expect(api.keys, hasLength(2));
      expect(api.keys[0], isNot(api.keys[1]), reason: '⛔ ফেরানো চাবিতেই আবার পাঠাল');
      final payload = api.sent.single.toPayload();
      expect(payload['customerId'], 'cus-1');
      expect(payload['amount'], '750.50');
      expect(payload['trxDate'], '2026-10-21');
      expect(payload['accountId'], 'acc-bkash');
      expect(payload['instrument'], 'mfs', reason: 'পদ্ধতি = instrument');
      expect(payload['instrument_no'], 'TRX-7781', reason: '⛔ TrxID instrument_no-তে নয়');
      expect(payload['instrumentNo'], 'TRX-7781', reason: 'পুরনো সার্ভার এই ঘর পড়ে');
      expect(payload.containsKey('reference'), isFalse, reason: '⛔ পুরনো নাম reference');
      expect(payload.containsKey('method'), isFalse);
      expect(payload['confirm'], true);
    });

    testWidgets(
        'no network: "নেট লাগবে", nothing is kept on the phone, and the retry carries the same key so it lands once with its receipt',
        (tester) async {
      final api = FakeCollect(offlineTimes: 1);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '750.50');
      await _post(tester);
      expect(find.textContaining('আদায় দিতে নেট লাগবে'), findsOneWidget);
      expect(api.sent, isEmpty);

      await _post(tester);
      expect(api.keys, hasLength(2));
      expect(api.keys[0], api.keys[1], reason: '⛔ নেট ফেরার পরে নতুন চাবি — দুবার চাপলে দুটো আদায় বসতে পারত');
      expect(api.receipts, hasLength(1));
      expect(find.byKey(const ValueKey('collect-done')), findsOneWidget, reason: 'বসে গেলে রসিদ');
      expect(_opened, hasLength(1));
    });

    testWidgets('a timed-out collection does not say nothing was saved, and the retry is the same action (phone line audit, 15 Oct 2026)',
        (tester) async {
      final api = FakeCollect(slowTimes: 1);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '500');
      await _post(tester);
      expect(find.textContaining('ফোনে কিছু জমা থাকেনি'), findsNothing,
          reason: '⛔ সময় পেরোনো আদায়েও "কিছু জমা থাকেনি" — মানুষ নতুন করে লিখত');
      expect(find.textContaining('হয়তো পৌঁছেছে'), findsOneWidget);

      await _post(tester);
      expect(api.keys, hasLength(2));
      expect(api.keys[1], api.keys[0], reason: '⛔ আবার চাপায় নতুন চাবি — দুবার বসত');
      expect(api.receipts, hasLength(1), reason: '⛔ দুটো রসিদ');
    });

    testWidgets('old server: once it lands, the receipt opens with its number and that it is posted — never "পাকা"', (tester) async {
      final api = FakeCollect(oldServer: true);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '750.50');
      await _post(tester);
      expect(find.text('COL-0042'), findsWidgets, reason: '⛔ রসিদে আদায়ের নম্বর নেই');
      expect(find.text('পোস্ট হয়েছে · খাতায় উঠেছে'), findsOneWidget);
      expect(find.textContaining('পাকা'), findsNothing, reason: '⛔ "পাকা" আর নয় — খাতায় ওঠা মানে "পোস্ট হয়েছে"');
    });

    testWidgets('only an office person sees "নতুন আদায়"; the field keeps the deposit request', (tester) async {
      await _pump(tester, MoneyInListScreen(api: FakeBooks(), dues: () => const [], unsent: () => const []));
      expect(find.byKey(const ValueKey('money-in-new')), findsNothing, reason: '⛔ মাঠের লোকও ফোনে আদায় লিখতে পারছে');
      await _pump(tester, MoneyInListScreen(api: FakeBooks(), canCollect: true, dues: () => const [], unsent: () => const []));
      expect(find.byKey(const ValueKey('money-in-new')), findsOneWidget);
    });

    testWidgets('a collection still waiting in the phone\'s queue is listed first with the "পাঠানো বাকি" chip', (tester) async {
      await _pump(
          tester,
          MoneyInListScreen(
              api: FakeBooks(), dues: () => const [], unsent: () => const [UnsentCollection('করিম স্টোর · ফুলপুর পয়েন্ট', 500)]));
      final row = find.byKey(const ValueKey('money-in-unsent-0'));
      expect(row, findsOneWidget);
      final chip = tester.widget<AppStatusChip>(find.descendant(of: row, matching: find.byType(AppStatusChip)));
      expect(chip.state, PaperState.unsent);
    });

    test('a cash collection sends instrument cash and no instrument_no, and the payload stays what the server reads', () {
      // ⓘ টাকার-পদ্ধতির মডেলে (a5, ২১ অক্টোবর ২০২৬) নগদও নিজের পদ্ধতি পাঠায়; আগে খালি `instrument` বাদ পড়ত — সেটাও থাকে
      final payload = CollectionDraft(
              customerId: 'cus-1', amount: '100', date: DateTime(2026, 10, 7), accountId: 'acc-cash', instrument: 'cash')
          .toPayload();
      expect(payload['instrument'], 'cash');
      expect(payload.containsKey('instrument_no'), isFalse);
      expect(payload.containsKey('reference'), isFalse);
      expect(
          CollectionDraft(customerId: 'cus-1', amount: '100', date: DateTime(2026, 10, 7), instrument: '').toPayload().containsKey('instrument'),
          isFalse);
      expect(payload.containsKey('lines'), isFalse, reason: 'লাইন না দিলে সার্ভার পুরনো বিল আগে বসায়');
      expect(payload['confirm'], true);
      expect(payload['trxDate'], '2026-10-07');
    });
  });

  testWidgets('the collections list\'s "＋ নতুন আদায়" opens this screen', (tester) async {
    await _pump(
        tester,
        MoneyInListScreen(
            api: FakeBooks(), canCollect: true, dues: () => const [], unsent: () => const [], collect: () => _screen(FakeCollect())));
    await tester.tap(find.byKey(const ValueKey('money-in-new')));
    await tester.pumpAndSettle();
    expect(find.byType(CollectScreen), findsOneWidget);
  });

  testWidgets('19 · the same fields, names and order as the web, in one column, with the buttons fixed at the bottom', (tester) async {
    await _pump(tester, _screen(FakeCollect()), size: const Size(412, 892));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-transfer');

    // ⓘ ওয়েবের লেখা (lang ফাইল) — অ্যাপের লেবেল হুবহু; লেবেলের "*" কেবল বাধ্যতামূলক বোঝায়
    const web = {
      'sales::field.customer': 'গ্রাহক',
      'sales::field.date': 'তারিখ',
      'sales::field.amount': 'টাকা',
      'sales::field.account': 'যে খাতে জমা হলো',
      'sales::field.narration': 'বিবরণ',
      'sales::field.bank_charge': 'ব্যাংক চার্জ',
      'sales::field.instrument_date': 'চেকের তারিখ',
      'sales::action.oldest_bills_first': 'পুরনো বিল আগে',
      'accounts::field.transfer_mode': 'ট্রান্সফার মোড',
    };
    if (Directory('../app/Modules').existsSync()) {
      for (final e in web.entries) {
        final module = e.key.startsWith('sales') ? 'Sales' : 'Accounts';
        final file = e.key.split('::').last.split('.').first;
        final key = e.key.split('.').last;
        final php = File('../app/Modules/$module/Resources/lang/bn/$file.php').readAsStringSync();
        expect(php, contains("'$key' => '${e.value}'"), reason: 'ওয়েবের লেখা বদলেছে: ${e.key}');
      }
    }
    final labels = [
      for (final d in tester.widgetList<InputDecorator>(find.byType(InputDecorator))) d.decoration.labelText ?? '',
      for (final t in tester.widgetList<Text>(find.byType(Text))) t.data ?? '',
    ];
    bool shown(String w) => labels.any((l) => l == w || l == '$w *' || l.startsWith('$w ('));
    for (final w in ['গ্রাহক', 'তারিখ', 'টাকা', 'যে খাতে জমা হলো', 'ব্যাংক চার্জ', 'ট্রান্সফার মোড', 'বিবরণ']) {
      expect(shown(w), isTrue, reason: '⛔ ওয়েবের ঘর "$w" পাতায় নেই বা অন্য নামে');
    }
    for (final w in ['গ্রাহকের বকেয়া', 'এই আদায়', 'বাকি থাকবে', 'খোলা বিল ও ক্রেডিট', 'হিসাবের সারাংশ', 'খাতায় যা যাবে', 'নিয়ন্ত্রণ', 'খসড়া রাখুন', 'পোস্ট']) {
      expect(find.text(w), findsWidgets, reason: '⛔ নকশার "$w" নেই');
    }
    for (final old in ['অঙ্ক (টাকা)', 'কোন খাতে', 'মন্তব্য (ঐচ্ছিক)', 'এখনই নিশ্চিত']) {
      expect(labels.contains(old), isFalse, reason: '⛔ পুরনো নাম "$old" রয়ে গেল');
    }

    // ⓘ ওয়েবের ক্রম: গ্রাহক → তারিখ → টাকা → পদ্ধতি → খাত → রেফারেন্স → বিল → … → বিবরণ
    double y(String key) => tester.getTopLeft(find.byKey(ValueKey(key))).dy;
    final order = [
      'collect-customer', 'collect-date', 'collect-amount', 'collect-method-cash', 'collect-account-bank', 'collect-reference',
      'collect-bank-charge', 'collect-bills', 'collect-difference', 'collect-summary-card', 'collect-journal', 'collect-controls',
      'collect-narration',
    ];
    final ys = [for (final k in order) y(k)];
    expect([...ys]..sort(), ys, reason: '⛔ ঘরের ক্রম ওয়েবের মতো নয়');

    // ⓘ এক কলাম — প্রতিটা অংশ একই বাঁ ধারে, একই চওড়ায়
    final cards = ['collect-bills', 'collect-summary-card', 'collect-journal', 'collect-controls'];
    expect({for (final k in cards) tester.getTopLeft(find.byKey(ValueKey(k))).dx}, hasLength(1));
    expect({for (final k in cards) tester.getSize(find.byKey(ValueKey(k))).width}, hasLength(1));

    // ⓘ নিচের বোতাম স্থির — পাতা গড়ালেও একই জায়গায়
    final before = tester.getRect(find.byKey(const ValueKey('collect-post')));
    await tester.drag(find.byKey(const ValueKey('collect-list')), const Offset(0, -600));
    await tester.pumpAndSettle();
    expect(tester.getRect(find.byKey(const ValueKey('collect-post'))), before, reason: '⛔ বোতাম পাতার সাথে সরে গেল');

    // ⓘ একই হিসাব: নকশার নমুনা (45,000 → 30,000 · 15,200 · −2,000 · 1,800; অপ্রয়োগকৃত 0)
    final b = OpenBills.fromJson(openBillsJson());
    final plan = CollectionPlan(bills: b.bills, credits: b.credits)..received = 4500000;
    plan.autoAllocate();
    expect([for (final r in plan.bills) r.applied], [3000000, 1520000, 180000]);
    expect([for (final r in plan.credits) r.applied], [200000]);
    expect(plan.netApplied, 4500000);
    expect(plan.unapplied, 0);
  });

  group('20 · easy to use', () {
    testWidgets('20 · the keyboard "next" walks the fields in order: amount → reference → bank charge → discount → narration',
        (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      await _pickShop(tester);
      await _tap(tester, 'collect-method-transfer');
      await tester.tap(find.byKey(const ValueKey('collect-amount')));
      await tester.pumpAndSettle();
      const fields = ['collect-amount', 'collect-reference', 'collect-bank-charge', 'collect-difference', 'collect-narration'];
      String focused() {
        final node = FocusManager.instance.primaryFocus;
        for (final k in fields) {
          if (tester.widget<TextField>(find.byKey(ValueKey(k))).focusNode == node) return k;
        }
        return '?';
      }

      final walked = [focused()];
      for (var i = 0; i < 4; i++) {
        await tester.testTextInput.receiveAction(TextInputAction.next);
        await tester.pumpAndSettle();
        walked.add(focused());
      }
      expect(walked, fields);
    });

    testWidgets('20 · a hardware keyboard\'s Tab moves down the page in reading order', (tester) async {
      await _pump(tester, _screen(FakeCollect()), size: const Size(412, 892));
      await _pickShop(tester);
      await tester.tap(find.byKey(const ValueKey('collect-amount')));
      await tester.pumpAndSettle();
      var last = -1.0;
      var steps = 0;
      for (var i = 0; i < 12; i++) {
        await tester.sendKeyEvent(LogicalKeyboardKey.tab);
        await tester.pumpAndSettle();
        final ctx = FocusManager.instance.primaryFocus?.context;
        if (ctx == null) break;
        final scrollable = Scrollable.maybeOf(ctx);
        if (scrollable == null) break; // ⓘ পাতার বাইরে (নিচের বোতাম) — শেষ
        final box = ctx.findRenderObject() as RenderBox?;
        if (box == null || !box.attached) continue;
        final top = box.localToGlobal(Offset.zero).dy + scrollable.position.pixels;
        expect(top + 1 >= last, isTrue, reason: '⛔ Tab উপরে লাফাল ($last → $top)');
        last = top;
        steps++;
      }
      expect(steps, greaterThan(3));
    });

    testWidgets('20 · every control has a screen-reader label and a 48dp target', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _screen(FakeCollect()), size: const Size(412, 892));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _tap(tester, 'collect-bills-change');
      await tester.drag(find.byKey(const ValueKey('collect-list')), const Offset(0, 3000));
      await tester.pumpAndSettle();
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      handle.dispose();
    });

    for (final width in [360.0, 412.0]) {
      testWidgets('20 · ${width.toInt()}dp at 200% text: nothing overflows, all reachable by scrolling, the fixed buttons never cover the last field',
          (tester) async {
        await _pump(tester, _screen(FakeCollect()), size: Size(width, 760), textScale: 2);
        await _pickShop(tester);
        await _type(tester, 'collect-amount', '45000');
        await _tap(tester, 'collect-method-transfer');
        await _tap(tester, 'collect-bills-change');
        expect(tester.takeException(), isNull);
        for (final k in ['collect-bills', 'collect-summary-card', 'collect-journal', 'collect-controls', 'collect-narration']) {
          await tester.ensureVisible(find.byKey(ValueKey(k)));
          await tester.pumpAndSettle();
        }
        await tester.drag(find.byKey(const ValueKey('collect-list')), const Offset(0, -20000));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: '⛔ 200% লেখায় উপচে পড়ল');
        final last = tester.getRect(find.byKey(const ValueKey('collect-narration')));
        final bar = tester.getRect(find.byKey(const ValueKey('collect-post')));
        expect(last.bottom <= bar.top, isTrue, reason: '⛔ স্থির বোতাম শেষ ঘর ঢেকে দিল');
      });
    }
  });
}
