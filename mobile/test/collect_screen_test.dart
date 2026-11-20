import 'dart:io';

import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/books/collection_plan.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/money_input.dart';
import 'package:abos_mobile/core/navigation/role_tabs.dart';
import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/books/collect_screen.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/customers/deposit_request_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/collect_fake.dart';
import 'support/real_fonts.dart';

/// ⭐ টাকা নেওয়া — এক পথ, দুই পর্দা (০.৪.৪৫) — মালিক, ২১ অক্টোবর ২০২৬: "ok ekoni kaj kore live e daw", "100% quality";
/// চূড়ান্ত নকশা ১০০ (পাতা ৫ বিষয়ের তালিকা, পাতা ৬ অ্যাপের ছবি: পর্দা ১ আর ২)। প্রতিটা দাবির নামে বিষয়ের নম্বর। সব নকল
/// সার্ভারে (a5-এর চূড়ান্ত চুক্তির আকার), নেট নয়।
var _seq = 0;
String _key() => 'key-${_seq++}';

final List<(CollectionSaved, ReceiptAction)> _opened = [];
Future<void> _receipt(BuildContext context, CollectionSaved saved, ReceiptAction action) async => _opened.add((saved, action));

final List<String> _parties = [];
Future<void> _party(BuildContext context, String kind) async => _parties.add(kind);

Future<String?> Function(BuildContext, {required bool camera}) _picker(String? path) =>
    (context, {required camera}) async => path;

CollectScreen _screen(FakeCollect api, {int slipBytes = 1000}) => CollectScreen(
      api: api,
      books: FakeBooks(),
      newChangeId: _key,
      today: DateTime(2026, 10, 21),
      openReceipt: _receipt,
      otherParty: _party,
      pickSlip: _picker('C:/slips/slip-21.jpg'),
      slipSize: (_) async => slipBytes,
    );

Future<void> _pump(WidgetTester tester, Widget screen, {Size size = const Size(412, 4000), double textScale = 1, ThemeData? theme}) async {
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
    theme: theme,
    home: MediaQuery.withClampedTextScaling(minScaleFactor: textScale, maxScaleFactor: textScale, child: screen),
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

String _texts(WidgetTester tester, String key) {
  final f = find.byKey(ValueKey(key));
  if (tester.widget(f) case final Text t) return t.data ?? '';
  return tester.widgetList<Text>(find.descendant(of: f, matching: find.byType(Text))).map((t) => t.data).join(' | ');
}

String _field(WidgetTester tester, String key) => tester.widget<TextField>(find.byKey(ValueKey(key))).controller!.text;

bool _shown(String key) => find.byKey(ValueKey(key)).evaluate().isNotEmpty;

/// পর্দা ১-এর "দেখে পোস্ট ›" → পর্দা ২
Future<void> _review(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('collect-post')));
  await tester.pumpAndSettle();
}

/// পোস্ট — পর্দা ১ থেকে পর্দা ২ খুলে, অথবা পর্দা ২-এ থাকলে সেখানেই, এক চাপ
Future<void> _post(WidgetTester tester) async {
  if (!_shown('collect-check-post')) await _review(tester);
  await tester.tap(find.byKey(const ValueKey('collect-check-post')));
  await tester.pumpAndSettle();
}

/// পর্দা ২ থেকে ফিরে পর্দা ১-এ
Future<void> _back(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('collect-check-back')));
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(loadRealFonts);

  setUp(() {
    _opened.clear();
    _parties.clear();
  });

  testWidgets('1 · 13 · 14 · the three figures, the party "name · point · branch" with limit and oldest days, the month and the branch',
      (tester) async {
    // ⓘ আসল ফন্টে — "এক সারিতে" মাপা অর্থপূর্ণ কেবল সেখানে: পরীক্ষার ফন্টে "৳51,200.00" ঘরের চেয়ে চওড়া, আর তিন অঙ্ক তখন ঠিকই
    // একটার নিচে একটা নামে (অঙ্ক ভাঙার বদলে; MoneyFigures, a4, ২৪ অক্টোবর ২০২৬)
    await _pump(tester, _screen(FakeCollect()), theme: withRealFonts(ThemeData()));
    expect(find.text('টাকা নেওয়া'), findsOneWidget, reason: 'ওয়েবের পাতার নাম');
    expect(find.byKey(const ValueKey('collect-state-draft')), findsOneWidget, reason: 'বসার আগে বারে "খসড়া"');
    await _pickShop(tester);

    expect(find.text('করিম স্টোর · ফুলপুর পয়েন্ট · ময়মনসিংহ'), findsOneWidget, reason: '⛔ গ্রাহকের নাম · পয়েন্ট · শাখা নয়');
    expect(find.text('সীমা ৳1,00,000.00 · পুরনো বাকি 49 দিন'), findsOneWidget);
    expect(find.text('শাখা: গ্রাহকের শাখা (ময়মনসিংহ)'), findsOneWidget, reason: 'বিষয় ১৪: শাখা গ্রাহকের');
    expect(find.text('মাস খোলা'), findsOneWidget, reason: 'বিষয় ১৩');

    await _type(tester, 'collect-amount', '45000');
    expect(_texts(tester, 'collect-figure-due'), 'বকেয়া | ৳51,200.00');
    expect(_texts(tester, 'collect-figure-this'), 'এই আদায় | ৳45,000.00');
    expect(_texts(tester, 'collect-figure-left'), 'বাকি থাকবে | ৳6,200.00', reason: '⛔ বকেয়া − এই আদায় ভুল');
    expect(find.byKey(const ValueKey('collect-over-due')), findsNothing);
    final ys = [for (final k in ['collect-figure-due', 'collect-figure-this', 'collect-figure-left']) tester.getTopLeft(find.byKey(ValueKey(k))).dy];
    expect(ys.toSet(), hasLength(1), reason: 'তিন অঙ্ক এক সারিতে');
  });

  testWidgets('"পুরো বকেয়া" fills the due in one tap', (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _tap(tester, 'collect-full-due');
    expect(_field(tester, 'collect-amount'), '51,200.00');
    expect(_texts(tester, 'collect-figure-left'), 'বাকি থাকবে | ৳0.00');
  });

  testWidgets('"নির্বাচিত বিল" fills the amount with the ticked invoices (less the ticked credit) and applies exactly them', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    // ⓘ টাকা লেখার আগে টিক — দুটো নতুন বিল আর ক্রেডিট নোট, পুরনোটা নয়
    for (final no in ['INV-0101', 'INV-0112', 'CN-0007']) {
      await _tap(tester, 'collect-tick-$no');
    }
    await _tap(tester, 'collect-selected-bills');
    expect(_field(tester, 'collect-amount'), '21,200.00', reason: '⛔ নির্বাচিত বিলের যোগফল নয় (15,200 + 8,000 − 2,000)');
    expect(_texts(tester, 'collect-row-amount-INV-0094'), '0.00', reason: '⛔ না-বাছা পুরনো বিলে বসল');
    expect(_texts(tester, 'collect-row-amount-INV-0101'), '15,200.00');
    expect(_texts(tester, 'collect-row-amount-INV-0112'), '8,000.00');
    await _post(tester);
    expect(api.sent.single.toPayload()['lines'], [
      {'kind': 'bill', 'id': 'inv-101', 'amount': '15200.00'},
      {'kind': 'bill', 'id': 'inv-112', 'amount': '8000.00'},
      {'kind': 'credit_note', 'id': 'cn-7', 'amount': '-2000.00'},
    ]);
  });

  testWidgets('4 · more than the due warns, and the extra shows as the customer\'s credit (Cr) and as unapplied', (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '60000');

    expect(find.text('বকেয়ার চেয়ে ৳8,800.00 বেশি — বাড়তি টাকা গ্রাহকের খাতায় জমা (Cr) থাকবে।'), findsOneWidget,
        reason: '⛔ বকেয়ার বেশি টাকায় সতর্কতা নেই');
    expect(_texts(tester, 'collect-figure-left'), 'গ্রাহকের খাতায় জমা থাকবে | ৳8,800.00');
    // ⓘ 60,000 + ক্রেডিট নোট 2,000 = 62,000 হাতে; বিল 53,200 — বাকি 8,800 অপ্রয়োগকৃত, গ্রাহকের খাতায়
    await _review(tester);
    expect(_texts(tester, 'collect-unapplied'), contains('৳8,800.00'));
    expect(find.byKey(const ValueKey('collect-balanced')), findsOneWidget);
  });

  testWidgets('18 · an SR\'s picker shows only the shops the server gave, each as "name · point · branch"', (tester) async {
    await _pump(tester, _screen(FakeCollect(shops: const [shop])));
    await _tap(tester, 'collect-customer');
    expect(find.byType(ListTile), findsOneWidget, reason: '⛔ সার্ভারের দেওয়া দোকানের বাইরে কেউ দেখা গেল');
    expect(find.byKey(const ValueKey('collect-pick-cus-1')), findsOneWidget);
    expect(find.text('করিম স্টোর · ফুলপুর পয়েন্ট · ময়মনসিংহ'), findsOneWidget);
  });

  testWidgets('the party type: picking সরবরাহকারী shows the note and opens the old form only on its button; posting is off meanwhile',
      (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    expect(
        tester.widget<ChoiceChip>(find.descendant(of: find.byKey(const ValueKey('collect-party-customer')), matching: find.byType(ChoiceChip))).selected,
        isTrue);
    expect(find.byKey(const ValueKey('collect-party-note')), findsNothing);

    await _tap(tester, 'collect-party-supplier');
    expect(find.text('এই ধরনের আদায় এখন পুরনো ফর্মে'), findsOneWidget, reason: '⛔ নোট নেই');
    expect(_parties, isEmpty, reason: '⛔ চুপচাপ পুরনো ফর্ম খুলল');
    expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed, isNull,
        reason: '⛔ সরবরাহকারী বেছেও গ্রাহকের আদায় পাঠানো যায়');
    await _tap(tester, 'collect-party-open-old');
    expect(_parties, ['supplier']);

    for (final k in ['staff', 'other']) {
      await _tap(tester, 'collect-party-$k');
      expect(find.byKey(const ValueKey('collect-party-note')), findsOneWidget);
    }
    await _tap(tester, 'collect-party-customer');
    expect(find.byKey(const ValueKey('collect-party-note')), findsNothing);
    expect(_parties, ['supplier']);
  });

  testWidgets('the amount reads as money (45,000.00) once the field is left, stays as typed while typing, and the exact decimal is sent',
      (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await tester.tap(find.byKey(const ValueKey('collect-amount')));
    await tester.enterText(find.byKey(const ValueKey('collect-amount')), '45000');
    await tester.pumpAndSettle();
    expect(_field(tester, 'collect-amount'), '45000', reason: '⛔ লেখার সময়েই সাজানো — লেখা ভাঙে');
    await tester.testTextInput.receiveAction(TextInputAction.next);
    await tester.pumpAndSettle();
    expect(_field(tester, 'collect-amount'), '45,000.00', reason: '⛔ ঘর ছাড়ার পরে টাকার রূপে নয়');
    expect(tester.widget<TextField>(find.byKey(const ValueKey('collect-amount'))).textAlign, TextAlign.end);
    expect(_texts(tester, 'collect-figure-this'), 'এই আদায় | ৳45,000.00');

    await _type(tester, 'collect-amount', '1234567.5');
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    expect(_field(tester, 'collect-amount'), '12,34,567.50', reason: 'লাখের ভাগে, ইংরেজি অঙ্ক');
    await _post(tester);
    expect(api.sent.single.toPayload()['amount'], '1234567.50', reason: '⛔ সাজানো লেখা (কমা) সার্ভারে গেল');
  });

  testWidgets('formatting the amount keeps hand-edited allocations', (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-item-INV-0094');
    await _type(tester, 'collect-apply-INV-0094', '20000');
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    expect(_field(tester, 'collect-amount'), '45,000.00');
    expect(_texts(tester, 'collect-row-amount-INV-0094'), '20,000.00', reason: '⛔ সাজানোয় হাতে বদলানো বণ্টন মুছে গেল');
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

  testWidgets('own cash comes from mine: true; without it the first cash account is chosen', (tester) async {
    final api = FakeCollect(accountList: const [bkash, cash, dbbl]);
    await _pump(tester, _screen(api));
    expect(find.text('1101 · প্রধান নগদ'), findsOneWidget, reason: '⛔ mine না থাকলে প্রথম নগদ নয়');
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _post(tester);
    expect(api.sent.single.accountId, 'acc-cash');
  });

  testWidgets('9 · the reference/TrxID shows only for MFS and bank; bank shows one compact line, and "বদলান" opens mode, value date and charge',
      (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    expect(find.byKey(const ValueKey('collect-reference')), findsNothing, reason: '⛔ নগদে TrxID চাওয়া হলো');
    expect(find.byKey(const ValueKey('collect-bank-line')), findsNothing);

    await _tap(tester, 'collect-method-mfs');
    expect(find.byKey(const ValueKey('collect-reference')), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-bank-line')), findsNothing);
    expect(find.text('1105 · bKash মার্চেন্ট'), findsOneWidget, reason: 'MFS-এ MFS-এর খাত');

    await _tap(tester, 'collect-method-transfer');
    expect(find.byKey(const ValueKey('collect-reference')), findsOneWidget);
    expect(find.text('মোড BEFTN · মূল্য-তারিখ আজ · চার্জ 0'), findsOneWidget, reason: '⛔ ব্যাংকের এক লাইন নেই (সার্ভারের প্রথম মোড)');
    expect(find.byKey(const ValueKey('collect-bank-charge')), findsNothing, reason: 'ঘরগুলো আগে থেকে খোলা নয়');
    expect(find.byKey(const ValueKey('collect-transfer-mode')), findsNothing);

    await _tap(tester, 'collect-bank-change');
    expect(find.byKey(const ValueKey('collect-transfer-mode')), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-value-date')), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('collect-bank-charge')), '25');
    await tester.tap(find.byKey(const ValueKey('collect-transfer-mode')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('NPSB').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('collect-bank-done')));
    await tester.pumpAndSettle();
    expect(find.text('মোড NPSB · মূল্য-তারিখ আজ · চার্জ 25'), findsOneWidget, reason: '⛔ বদলানো লাইনে উঠল না');

    await _tap(tester, 'collect-method-cash');
    expect(find.byKey(const ValueKey('collect-reference')), findsNothing);
  });

  testWidgets('cash posts without any reference; MFS and bank refuse to post without one', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-mfs');
    await _review(tester);
    expect(find.text('রেফারেন্স / TrxID দিন।'), findsOneWidget);
    expect(find.byType(CollectReviewScreen), findsNothing);
    expect(api.sent, isEmpty);

    await _tap(tester, 'collect-method-cash');
    await _post(tester);
    expect(api.sent, hasLength(1), reason: '⛔ নগদেও TrxID চাওয়া হলো — নগদ আদায় আটকে গেল');
    expect(api.sent.single.toPayload().containsKey('instrument_no'), isFalse);
    expect(api.sent.single.toPayload().containsKey('reference'), isFalse);
  });

  testWidgets('a bank transfer sends instrument "transfer" and the chosen transfer_mode_id from setup, with the TrxID in instrument_no',
      (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-transfer');
    await _tap(tester, 'collect-bank-change');
    await tester.tap(find.byKey(const ValueKey('collect-transfer-mode')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('RTGS').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('collect-bank-done')));
    await tester.pumpAndSettle();
    await _type(tester, 'collect-reference', 'BF26102100981');
    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p['instrument'], 'transfer');
    expect(p['transfer_mode_id'], 'tm-rtgs', reason: '⛔ বাছা ট্রান্সফার মোড গেল না');
    expect(p['instrument_no'], 'BF26102100981');
    expect(p['accountId'], 'acc-dbbl');
    expect(p.containsKey('deposit_slip_no'), isFalse);
  });

  testWidgets('new server: the bank charge and the value date are on screen and are sent (bank_charge, value_date)', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-cheque');
    expect(find.byKey(const ValueKey('collect-bank-line')), findsOneWidget);
    await _tap(tester, 'collect-bank-change');
    expect(find.byKey(const ValueKey('collect-bank-charge')), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-value-date')), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('collect-bank-charge')), '25');
    await tester.tap(find.byKey(const ValueKey('collect-value-date')));
    await tester.pumpAndSettle();
    final ok = MaterialLocalizations.of(tester.element(find.byType(DatePickerDialog))).okButtonLabel;
    await tester.tap(find.text(ok));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('collect-bank-done')));
    await tester.pumpAndSettle();
    await _type(tester, 'collect-reference', 'CHQ-77');
    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p['instrument'], 'cheque');
    expect(p.containsKey('bank_charge'), isTrue, reason: '⛔ নতুন সার্ভারে ব্যাংক চার্জ গেল না');
    expect(p.containsKey('value_date'), isTrue, reason: '⛔ নতুন সার্ভারে মূল্য-তারিখ গেল না');
  });

  testWidgets('a cash deposit sends instrument "deposit" and deposit_slip_no, and refuses to post without the slip number', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-deposit');
    expect(find.byKey(const ValueKey('collect-reference')), findsNothing, reason: 'নগদ জমায় TrxID নয়, স্লিপ নম্বর');
    await _review(tester);
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

  testWidgets('3 · open bills, credits and notes in one compact table, oldest first, overdue days in red; the fold shows one line',
      (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');

    expect(find.text('4টা · পুরনো আগে'), findsOneWidget);
    expect(_texts(tester, 'collect-row-amount-INV-0094'), '30,000.00', reason: '⛔ পুরনো বিল আগে নয়');
    expect(_texts(tester, 'collect-row-amount-INV-0101'), '15,200.00');
    expect(_texts(tester, 'collect-row-amount-CN-0007'), '-2,000.00', reason: '⛔ খোলা ক্রেডিট নোট কাজে লাগেনি');
    expect(_texts(tester, 'collect-row-amount-INV-0112'), '1,800.00', reason: '⛔ সবচেয়ে নতুন বিলে আগে বসেছে');
    expect(find.text('ফেরত'), findsOneWidget, reason: 'ক্রেডিট নোটের পাশে "ফেরত"');
    expect(find.text('8 দিন দেরি'), findsOneWidget, reason: 'দেরির দিন নেই');
    expect(find.byKey(const ValueKey('collect-apply-INV-0094')), findsNothing, reason: 'সারিতে আগে থেকে ঘর — তালিকা পরিষ্কার থাকার কথা');
    expect(_texts(tester, 'collect-applied-total'), 'মোট প্রয়োগ | ৳45,000.00');

    final ys = [
      for (final no in ['INV-0094', 'INV-0101', 'CN-0007', 'INV-0112']) tester.getTopLeft(find.byKey(ValueKey('collect-item-$no'))).dy,
    ];
    expect([...ys]..sort(), ys, reason: 'টেবিলের ক্রম সার্ভারের (পুরনো আগে)');

    await _tap(tester, 'collect-bills-change');
    expect(find.text('নিজে থেকে: পুরনো বিল আগে — INV-0094 ৳30,000.00 · INV-0101 ৳15,200.00 · CN-0007 ৳-2,000.00 · INV-0112 ৳1,800.00'),
        findsOneWidget);
    expect(find.byKey(const ValueKey('collect-item-INV-0094')), findsNothing);
  });

  testWidgets('3 · tapping a row opens its amount; editing keeps the total and refuses over-allocation', (tester) async {
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-item-INV-0112');
    await _type(tester, 'collect-apply-INV-0112', '5000');
    expect(_field(tester, 'collect-apply-INV-0112'), '1800.00', reason: '⛔ হাতের টাকার বেশি বসানো গেল');
    expect(find.text('এর বেশি বসানো যায় না — সর্বোচ্চ ৳1,800.00।'), findsOneWidget);
    expect(_texts(tester, 'collect-applied-total'), 'মোট প্রয়োগ | ৳45,000.00', reason: '⛔ মোট বদলে গেল');

    await _tap(tester, 'collect-item-INV-0094');
    await _type(tester, 'collect-apply-INV-0094', '20000');
    expect(_texts(tester, 'collect-applied-total'), 'মোট প্রয়োগ | ৳35,000.00');

    await _tap(tester, 'collect-tick-INV-0112');
    expect(_texts(tester, 'collect-row-amount-INV-0112'), '0.00', reason: 'টিক তুললে শূন্য');
    await _tap(tester, 'collect-oldest-first');
    expect(_texts(tester, 'collect-row-amount-INV-0094'), '30,000.00', reason: '"পুরনো বিল আগে" আবার বসায়');

    await _tap(tester, 'collect-item-INV-0094');
    await _type(tester, 'collect-apply-INV-0094', '20000');
    await _review(tester);
    expect(_texts(tester, 'collect-unapplied'), contains('৳10,000.00'), reason: 'না-বসানো টাকা গ্রাহকের খাতায়');
    expect(find.byKey(const ValueKey('collect-balanced')), findsOneWidget);
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

  testWidgets('4 · discount and rounding (folded card) go for the owner\'s signature; VDS and AIT count as settled; the summary lines and banner',
      (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '40000');
    expect(find.byKey(const ValueKey('collect-difference')), findsNothing, reason: 'কাটা ও ছাড় গুটানো থাকার কথা');
    await _tap(tester, 'collect-adjust');
    await _type(tester, 'collect-difference', '1000');
    expect(find.byKey(const ValueKey('collect-difference-sign')), findsOneWidget, reason: '⛔ ছাড়ে মালিকের অনুমোদনের কথা নেই');
    await _type(tester, 'collect-vds', '500');
    await _type(tester, 'collect-ait', '200');
    expect(_texts(tester, 'collect-figure-left'), 'বাকি থাকবে | ৳9,500.00', reason: 'বকেয়া − আদায় − ছাড় − কাটা');

    await _review(tester);
    expect(_texts(tester, 'collect-sum-received'), 'পাওয়া টাকা | ৳40,000.00');
    expect(_texts(tester, 'collect-sum-withheld'), 'গ্রাহক কেটেছে (VDS/AIT) | ৳700.00');
    expect(_texts(tester, 'collect-sum-discount'), 'আদায়ে ছাড় (অনুমোদন লাগবে) | ৳1,000.00');
    expect(_texts(tester, 'collect-sum-rounding'), 'রাউন্ডিং (৳0.50-এর বেশি হলে মালিকের অনুমোদন) | ৳0.00');
    // ⓘ 40,000 + 1,000 + 700 + ক্রেডিট 2,000 = 43,700 বিলে; ক্রেডিট বাদে প্রয়োগ 41,700
    expect(_texts(tester, 'collect-net-applied'), 'বিলে প্রয়োগ | ৳41,700.00');
    expect(_texts(tester, 'collect-unapplied'), 'অপ্রয়োগকৃত | ৳0.00');
    expect(find.text('পাওয়া + কাটা + ছাড় = বিলে প্রয়োগ + অপ্রয়োগকৃত'), findsOneWidget);
    expect(find.text('এই আদায়ে মালিকের অনুমোদন লাগবে — অনুমোদনের পর খাতায় উঠবে'), findsOneWidget, reason: 'নিয়ন্ত্রণে অনুমোদনের কথা');
    expect(find.text('অনুমোদনে পাঠান'), findsOneWidget, reason: '⛔ ছাড়ে অনুমোদন লাগে, তবু বোতাম "পোস্ট"');

    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p['difference'], {'kind': 'discount', 'amount': '1000.00'});
    expect(p['vds'], '500.00');
    expect(p['ait'], '200.00');
  });

  testWidgets('4 · rounding is refused above Tk 10 — an early warning on the phone that blocks "দেখে পোস্ট ›"', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-adjust');
    await _tap(tester, 'collect-difference-rounding');
    await _type(tester, 'collect-difference', '11');
    expect(find.text('রাউন্ডিং ৳10.00-এর বেশি হতে পারে না।'), findsOneWidget);
    expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed, isNull,
        reason: '⛔ ৳10-এর বেশি রাউন্ডিংয়েও পোস্ট খোলা');
    await _review(tester);
    expect(find.byType(CollectReviewScreen), findsNothing);
    expect(api.sent, isEmpty);

    await _type(tester, 'collect-difference', '10');
    expect(find.byKey(const ValueKey('collect-rounding-warning')), findsNothing);
    expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed, isNotNull);
  });

  for (final (typed, sign, blocked) in [('0.50', false, false), ('0.51', true, false), ('10.00', true, false), ('10.01', false, true)]) {
    testWidgets('4 · rounding ৳$typed: ${blocked ? 'blocked on the phone' : sign ? 'allowed, posts after the owner\'s signature' : 'allowed, no signature'}',
        (tester) async {
      final api = FakeCollect();
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _tap(tester, 'collect-adjust');
      await _tap(tester, 'collect-difference-rounding');
      await _type(tester, 'collect-difference', typed);
      final post = tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed;
      expect(post == null, blocked, reason: blocked ? '⛔ ৳10-এর বেশি রাউন্ডিংয়েও পোস্ট খোলা' : '⛔ সীমার ভিতরের রাউন্ডিং আটকাল');
      if (blocked) {
        expect(find.byKey(const ValueKey('collect-rounding-warning')), findsOneWidget);
        return;
      }
      await _review(tester);
      expect(find.byType(CollectReviewScreen), findsOneWidget);
      expect(_texts(tester, 'collect-sum-rounding'), 'রাউন্ডিং (৳0.50-এর বেশি হলে মালিকের অনুমোদন) | ৳$typed');
      expect(find.text('এই আদায়ে মালিকের অনুমোদন লাগবে — অনুমোদনের পর খাতায় উঠবে'), sign ? findsOneWidget : findsNothing,
          reason: sign ? '⛔ ৳0.50-এর বেশি রাউন্ডিংয়ে অনুমোদনের কথা নেই' : '⛔ ৳0.50 পর্যন্ত রাউন্ডিংয়েও অনুমোদন চাওয়া হলো');
      expect(_texts(tester, 'collect-check-post'), sign ? 'অনুমোদনে পাঠান' : 'পোস্ট',
          reason: sign ? '⛔ অনুমোদন লাগে, তবু বোতাম "পোস্ট"' : '⛔ অনুমোদন লাগে না, তবু বোতাম "অনুমোদনে পাঠান"');
      await _post(tester);
      expect(api.sent.single.toPayload()['difference'], {'kind': 'rounding', 'amount': typed});
    });
  }

  testWidgets('4 · the server is the real gate: its 422 on rounding shows the server\'s own text', (tester) async {
    final api = FakeCollect(refusal: 'রাউন্ডিং এই আদায়ে চলবে না — সার্ভারের সীমা ৳10, আর বিলে বাকি ৳0.40।');
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-adjust');
    await _tap(tester, 'collect-difference-rounding');
    await _type(tester, 'collect-difference', '5');
    await _post(tester);
    expect(find.text('রাউন্ডিং এই আদায়ে চলবে না — সার্ভারের সীমা ৳10, আর বিলে বাকি ৳0.40।'), findsOneWidget,
        reason: '⛔ সার্ভারের কারণ দেখা গেল না');
    expect(api.sent, isEmpty);
  });

  testWidgets('10 · 17 · 19 · "দেখে পোস্ট ›" opens screen 2, a full screen: summary, words, the journal, controls — nothing goes before the one tap',
      (tester) async {
    final api = FakeCollect(preview: previewJson(needsSignature: true));
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');

    await _review(tester);
    expect(find.byType(CollectReviewScreen), findsOneWidget, reason: '⛔ পর্দা ২ খোলেনি');
    expect(find.byType(BottomSheet), findsNothing, reason: 'পর্দা ২ আলাদা পর্দা, নিচের পাতা নয়');
    expect(find.text('পোস্টের আগে দেখুন'), findsOneWidget);
    expect(api.sent, isEmpty, reason: '⛔ এক চাপের আগেই পাঠানো হলো');
    expect(find.textContaining('করিম স্টোর · ফুলপুর পয়েন্ট · ময়মনসিংহ · নগদ · 1101-07 · আল-আমিনের নগদ'), findsOneWidget);
    expect(_texts(tester, 'collect-sum-received'), 'পাওয়া টাকা | ৳45,000.00');
    expect(find.text('মিলেছে · পঁয়তাল্লিশ হাজার টাকা মাত্র'), findsOneWidget, reason: '⛔ কথায় টাকা সার্ভারের (রসিদের হুবহু) নয়');
    expect(find.textContaining('INV-0094 30,000.00'), findsOneWidget);
    expect(find.text('খাতায় যা যাবে'), findsOneWidget);
    // ⓘ সার্ভারের খাতা (a6 d5cc9657f): টাকার খাত Dr, গ্রাহকের পাওনা ১১১০ প্রাপ্য হিসাব Cr
    expect(find.text('Dr 1101-07 আল-আমিনের নগদ · ময়মনসিংহ'), findsOneWidget);
    expect(find.text('Cr 1110 প্রাপ্য হিসাব · করিম স্টোর · ময়মনসিংহ'), findsOneWidget);
    expect(find.text('মোট মিলেছে'), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-needs-signature')), findsOneWidget, reason: 'বিষয় ৪/১১: অনুমোদনে যাবে বলা নেই');
    expect(find.text('নেট না থাকলে জমা হবে না; আবার চাপলে একটাই রসিদ হবে।'), findsOneWidget);

    await _back(tester);
    expect(find.byType(CollectReviewScreen), findsNothing);
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
    await _tap(tester, 'collect-adjust');
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
    expect(p['amount'], '44000.00', reason: 'টাকার রূপে সাজানো হলেও পাঠানো দশমিক, কমা ছাড়া');
  });

  testWidgets('everything unticked sends lines: [] — nothing applied, the whole amount stays as the customer\'s credit', (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    for (final no in ['INV-0094', 'INV-0101', 'INV-0112', 'CN-0007']) {
      await _tap(tester, 'collect-tick-$no');
    }
    await _review(tester);
    expect(_texts(tester, 'collect-unapplied'), contains('৳45,000.00'));
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

  testWidgets('17 · after posting, on screen 2: the number, the state and the four receipt chips; the bar of screen 1 shows them too',
      (tester) async {
    final api = FakeCollect(saved: const {
      'no': 'RCV-2610-0012',
      // ⓘ সার্ভারের আসল চাবি (a6 d5cc9657f): সইয়ের অপেক্ষা = `waiting`
      'state': 'waiting',
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

    expect(find.byKey(const ValueKey('collect-after')), findsOneWidget, reason: '⛔ "পোস্টের পরে" কার্ড নেই');
    expect(find.text('RCV-2610-0012'), findsOneWidget);
    expect(tester.widget<AppStatusChip>(find.byKey(const ValueKey('collect-review-state'))).state, PaperState.waiting,
        reason: '⛔ সার্ভারের অবস্থা চিপে নেই');
    expect(find.text('কথায়: পঁয়তাল্লিশ হাজার টাকা মাত্র'), findsOneWidget);
    expect(_opened.map((o) => o.$2), [ReceiptAction.pdf], reason: '⛔ পোস্টের পরে রসিদ খোলেনি');
    expect(_opened.single.$1.receipt, '/documents/Collection/col-1/receipt.pdf');

    for (final a in ['pdf', 'whatsapp', 'sms', 'print']) {
      await _tap(tester, 'collect-receipt-$a');
    }
    expect(_opened.skip(1).map((o) => o.$2), ReceiptAction.values, reason: '⛔ রসিদের চার চিপের কোনোটা কাজ করে না');
    expect(find.text('WhatsApp'), findsOneWidget);
    expect(find.text('SMS'), findsOneWidget);

    await _tap(tester, 'collect-check-close');
    expect(find.byType(CollectReviewScreen), findsNothing);
    expect(find.text('নম্বর: RCV-2610-0012'), findsOneWidget, reason: 'পর্দা ১-এ নম্বর');
    expect(tester.widget<AppStatusChip>(find.byKey(const ValueKey('collect-state'))).state, PaperState.waiting);
  });

  testWidgets('"খসড়া" saves without screen 2 and without confirming; the old "এখনই নিশ্চিত" switch is gone', (tester) async {
    final api = FakeCollect(saved: const {'no': 'RCV-2610-0013', 'state': 'draft'});
    await _pump(tester, _screen(api));
    expect(find.text('এখনই নিশ্চিত'), findsNothing);
    expect(find.byType(SwitchListTile), findsNothing);
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await tester.tap(find.byKey(const ValueKey('collect-draft')));
    await tester.pumpAndSettle();
    expect(find.byType(CollectReviewScreen), findsNothing);
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
    expect(find.byKey(const ValueKey('collect-after')), findsNothing);

    // ⓘ ফিরে গিয়ে ঠিক করে আবার — নতুন চাবি (ফেরানো চাবি আবার পাঠালে আবার ফেরে)
    api.refusal = null;
    await _back(tester);
    expect(find.text('এই TrxID এই খাতে আগে RCV-2610-0003-এ নেওয়া হয়েছে।'), findsOneWidget, reason: 'পর্দা ১-এও কারণ');
    await _type(tester, 'collect-reference', 'BK7782');
    await _post(tester);
    expect(api.keys[1], isNot(api.keys[0]));
  });

  testWidgets('13 · the server\'s stops block posting on screen 2 and are shown; a closed month says so in the header', (tester) async {
    final api = FakeCollect(
      bills: openBillsJson(monthOpen: false),
      preview: previewJson(stops: ['অক্টোবর 2026 মাস বন্ধ — এই তারিখে পোস্ট হবে না।']),
    );
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    expect(find.text('মাস বন্ধ — পোস্ট হবে না'), findsOneWidget);
    await _review(tester);
    expect(find.text('অক্টোবর 2026 মাস বন্ধ — এই তারিখে পোস্ট হবে না।'), findsOneWidget);
    expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-check-post'))).onPressed, isNull,
        reason: '⛔ বাধা থাকতেও পোস্ট খোলা');
  });

  testWidgets('15 · a required slip must be chosen before posting, and it is sent after saving', (tester) async {
    final api = FakeCollect(bills: openBillsJson(slipRequired: true));
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    expect(find.text('স্লিপের ছবি *'), findsOneWidget);
    await _type(tester, 'collect-amount', '45000');
    await _review(tester);
    expect(find.text('স্লিপের ছবি দিন — এই কোম্পানিতে বাধ্যতামূলক।'), findsOneWidget);
    expect(api.sent, isEmpty);

    await _tap(tester, 'collect-slip-gallery');
    expect(find.text('ছবি বাছা হয়েছে: slip-21.jpg'), findsOneWidget);
    await _post(tester);
    expect(api.slips, [('col-1', 'C:/slips/slip-21.jpg')]);
  });

  testWidgets('15 · a slip turns the server\'s draft into posted: the chip shows the attachment answer\'s state', (tester) async {
    // ⓘ a6 d5cc9657f: স্লিপ-বাধ্যতামূলক কোম্পানিতে "পোস্ট" চাওয়া আদায় প্রথমে খসড়া; স্লিপ পৌঁছালে সার্ভার পোস্ট করে, উত্তরে `state`
    final api = FakeCollect(
      bills: openBillsJson(slipRequired: true),
      saved: const {'no': 'RCV-2610-0014', 'state': 'draft', 'receipt_pdf': 'https://erp.test/sales/print/receipt/42'},
      slipState: 'posted',
    );
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-slip-camera');
    await _post(tester);
    expect(tester.widget<AppStatusChip>(find.byKey(const ValueKey('collect-review-state'))).state, PaperState.posted,
        reason: '⛔ স্লিপের পরে পোস্ট হলো, অথচ চিপ বলছে খসড়া');
  });

  testWidgets('14 · the header\'s branch is the server\'s (open-bills customer.branch) when the phone\'s record has none', (tester) async {
    const plain = CustomerRecord({'id': 'cus-1', 'nameBn': 'করিম স্টোর', 'pointName': 'ফুলপুর পয়েন্ট'});
    await _pump(tester, _screen(FakeCollect(shops: const [plain])));
    await _pickShop(tester);
    expect(find.text('শাখা: গ্রাহকের শাখা (ময়মনসিংহ)'), findsOneWidget, reason: '⛔ সার্ভারের বলা শাখা মাথায় নেই');
  });

  testWidgets('15 · a slip over 5 MB is refused before it is kept', (tester) async {
    await _pump(tester, _screen(FakeCollect(), slipBytes: 6 * 1024 * 1024));
    await _pickShop(tester);
    await _tap(tester, 'collect-slip-camera');
    expect(find.text('ছবিটা 5 MB-এর বেশি — ছোট ছবি দিন।'), findsOneWidget);
    expect(find.byKey(const ValueKey('collect-slip-chosen')), findsNothing);
  });

  group('⛔ old server (fe\'s rule)', () {
    testWidgets('open-bills 404/405: the screen still works, the bills read "নিজে থেকে: পুরনো বিল আগে" without a list, and no lines go',
        (tester) async {
      final api = FakeCollect(oldServer: true);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      expect(find.text('নিজে থেকে: পুরনো বিল আগে'), findsOneWidget);
      expect(find.byKey(const ValueKey('collect-bills-change')), findsNothing);
      expect(find.byKey(const ValueKey('collect-adjust')), findsNothing, reason: 'পুরনো সার্ভার ছাড় পড়ে না — নীরবে বাদ যেত');
      expect(find.byKey(const ValueKey('collect-selected-bills')), findsNothing);
      await _type(tester, 'collect-amount', '45000');
      await _post(tester);
      final p = api.sent.single.toPayload();
      expect(p.containsKey('lines'), isFalse, reason: '⛔ পুরনো সার্ভারে লাইন পাঠানো হলো');
      expect(p.containsKey('difference'), isFalse);
    });

    testWidgets('no preview: screen 2 shows without "খাতায় যা যাবে", and the words come from the app', (tester) async {
      final api = FakeCollect(noPreview: true);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45200');
      await _review(tester);
      expect(find.byType(CollectReviewScreen), findsOneWidget);
      expect(find.byKey(const ValueKey('collect-journal')), findsNothing);
      expect(find.text('মিলেছে · 45 হাজার 200 টাকা'), findsOneWidget, reason: 'অ্যাপের কথায় টাকা, ইংরেজি অঙ্কে');
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
      expect(find.byType(CollectReviewScreen), findsNothing);
      expect(find.text('COL-0042'), findsWidgets);
      expect(_opened, isEmpty);
    });

    testWidgets('old server without transfer_modes: no mode, no bank line, no transfer_mode_id, the transfer still posts with its number',
        (tester) async {
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
      expect(p['instrument_no'], 'BF1', reason: 'পুরনো সার্ভারও নম্বর পড়ে');
      expect(p.containsKey('transfer_mode_id'), isFalse);
    });

    // ⛔ আজকের লাইভ সার্ভারের CollectionSync ব্যাংক চার্জ, মূল্য-তারিখ আর জমার স্লিপ নম্বর নীরবে ফেলে দেয় — লেখা চার্জ খাতায় উঠত না
    for (final method in ['transfer', 'cheque', 'deposit']) {
      testWidgets('old server, $method: no bank charge, value date or deposit slip on screen, and none of the three is sent',
          (tester) async {
        final api = FakeCollect(oldServer: true);
        await _pump(tester, _screen(api));
        await _pickShop(tester);
        await _type(tester, 'collect-amount', '45000');
        await _tap(tester, 'collect-method-$method');
        expect(find.byKey(const ValueKey('collect-bank-line')), findsNothing, reason: '⛔ পুরনো সার্ভারে ব্যাংক চার্জের লাইন');
        expect(find.byKey(const ValueKey('collect-bank-change')), findsNothing);
        expect(find.byKey(const ValueKey('collect-bank-charge')), findsNothing, reason: '⛔ পুরনো সার্ভারে ব্যাংক চার্জের ঘর');
        expect(find.byKey(const ValueKey('collect-value-date')), findsNothing);
        expect(find.byKey(const ValueKey('collect-deposit-slip')), findsNothing, reason: '⛔ পুরনো সার্ভারে জমার স্লিপের ঘর');
        if (method != 'deposit') await _type(tester, 'collect-reference', 'CHQ-77');
        await _post(tester);
        final p = api.sent.single.toPayload();
        expect(p['instrument'], method);
        for (final key in ['bank_charge', 'value_date', 'deposit_slip_no']) {
          expect(p.containsKey(key), isFalse, reason: '⛔ পুরনো সার্ভারে $key পাঠানো হলো — সার্ভার নীরবে ফেলে দেয়');
        }
        if (method != 'deposit') expect(p['instrument_no'], 'CHQ-77');
      });
    }
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
      await _review(tester);
      expect(find.text('গ্রাহক বাছুন।'), findsOneWidget);

      await _pickShop(tester);
      // ⓘ "1e5", "12.345", "abc" ঘরেই ঢোকে না (MoneyInputFormatter) — খালি ঘরে লিখলে ঘর খালিই থাকে, আর খালি বা শূন্য পাঠানো যায় না
      for (final bad in ['1e5', '0', '12.345', 'abc']) {
        // ⓘ প্রতিবার খালি ঘর থেকে — ফেরানো লেখা আগের লেখা রাখে, তাই আগের "0" থেকে গেলে দাবিটা অন্য কিছু মাপত
        await _type(tester, 'collect-amount', '');
        await _type(tester, 'collect-amount', bad);
        if (bad != '0') expect(_field(tester, 'collect-amount'), '', reason: '⛔ "$bad" টাকার ঘরে ঢুকল');
        await _review(tester);
        expect(find.byKey(const ValueKey('collect-error')), findsOneWidget, reason: '"$bad"');
        expect(find.byType(CollectReviewScreen), findsNothing);
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
      expect(find.byKey(const ValueKey('collect-after')), findsOneWidget, reason: 'বসে গেলে রসিদ');
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

    // ⛔ মালিক, ২০ নভেম্বর ২০২৬: টাকা নেওয়ার এক কাগজ — ফোনে আর কেউ "নতুন আদায়" দেখেন না; জমার খবরের চাবিতে "টাকা জমার খবর"
    testWidgets('no one sees "নতুন আদায়" on the list any more; the deposit-report key gets "টাকা জমার খবর"', (tester) async {
      await _pump(tester, MoneyInListScreen(api: FakeBooks(), dues: () => const [], unsent: () => const []));
      expect(find.byKey(const ValueKey('money-in-deposit-report')), findsNothing, reason: 'চাবি নেই, তবু বোতাম');
      await _pump(tester, MoneyInListScreen(api: FakeBooks(), canReportDeposit: true, dues: () => const [], unsent: () => const []));
      expect(find.byKey(const ValueKey('money-in-deposit-report')), findsOneWidget);
      expect(find.text('নতুন আদায়'), findsNothing, reason: '⛔ ফোনে আবার নতুন আদায়');
      expect(find.text('টাকা জমার খবর'), findsOneWidget);
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

  group('readable and distinct (fe, 22 Oct 2026)', () {
    /// প্রতিটা লেখার আসল মাপ — RichText-এর মেলানো স্টাইল থেকে; আইকন (ফন্ট-চিহ্ন) বাদ
    List<(double, String, Element)> sizes(WidgetTester tester) => [
          for (final e in find.byType(RichText).evaluate())
            if ((e.widget as RichText).text.style case final st?)
              if (!(st.fontFamily ?? '').contains('Material') && (e.widget as RichText).text.toPlainText().trim().isNotEmpty)
                (st.fontSize ?? 14, (e.widget as RichText).text.toPlainText(), e),
        ];

    void floor(WidgetTester tester, {required String screen}) {
      final all = sizes(tester);
      expect(all, isNotEmpty);
      final small = [for (final (size, text, _) in all) if (size < 12) '$text ($size)'];
      expect(small, isEmpty, reason: '⛔ $screen: ১২-এর নিচে লেখা: $small');
      bool under(Element e, String key) {
        var hit = false;
        e.visitAncestorElements((a) {
          final k = a.widget.key;
          if (k is ValueKey<String> && k.value.startsWith(key)) {
            hit = true;
            return false;
          }
          return true;
        });
        return hit;
      }

      // ⓘ টাকার অঙ্ক আর লেখার মূল অংশ ১৪-র কম নয়; মাথার তিন অঙ্ক ১৬-র কম নয়
      for (final (size, text, e) in all) {
        if (text.contains('৳') && under(e, 'collect-figure-')) {
          expect(size, greaterThanOrEqualTo(16), reason: '⛔ $screen: মাথার অঙ্ক "$text" $size');
        }
        for (final key in ['collect-row-amount-', 'collect-sum-', 'collect-net-applied', 'collect-unapplied', 'collect-applied-total',
          'collect-journal-', 'collect-controls', 'collect-after']) {
          // ⓘ অবস্থার চিপের লেখা লেবেল (১২-র নিয়ম), মূল লেখা নয়
          if (under(e, key) && find.ancestor(of: find.byElementPredicate((x) => x == e), matching: find.byType(AppStatusChip)).evaluate().isEmpty) {
            expect(size, greaterThanOrEqualTo(14), reason: '⛔ $screen: "$text" $size (চাবি $key)');
          }
        }
      }
    }

    /// ⭐ নিচের বোতামের লেখা Material 3-এর labelLarge: অন্তত 14, w600
    void buttons(WidgetTester tester, {required String screen, required List<String> labels}) {
      final seen = <String>[];
      for (final type in [FilledButton, OutlinedButton]) {
        for (final b in find.byWidgetPredicate((w) => w.runtimeType.toString().startsWith(type.toString())).evaluate()) {
          for (final r in find.descendant(of: find.byWidget(b.widget), matching: find.byType(RichText)).evaluate()) {
            final rich = r.widget as RichText;
            final st = rich.text.style;
            if (st == null || (st.fontFamily ?? '').contains('Material') || rich.text.toPlainText().trim().isEmpty) continue;
            final text = rich.text.toPlainText();
            seen.add(text);
            expect(st.fontSize ?? 0, greaterThanOrEqualTo(14), reason: '⛔ $screen: বোতাম "$text" ${st.fontSize}');
            expect(st.fontWeight, FontWeight.w600, reason: '⛔ $screen: বোতাম "$text" ${st.fontWeight}');
          }
        }
      }
      expect(seen, containsAll(labels), reason: '$screen-এর বোতাম মাপা হয়নি: $seen');
    }

    testWidgets('every text on screen 1 and screen 2 is at least 12; amounts and body 14; the three figures 16; buttons 14 w600',
        (tester) async {
      await _pump(tester, _screen(FakeCollect(preview: previewJson(needsSignature: true))));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _tap(tester, 'collect-item-INV-0094');
      await _tap(tester, 'collect-adjust');
      floor(tester, screen: 'পর্দা ১');
      buttons(tester, screen: 'পর্দা ১', labels: ['খসড়া', 'দেখে পোস্ট ›']);
      await _review(tester);
      floor(tester, screen: 'পর্দা ২');
      buttons(tester, screen: 'পর্দা ২', labels: ['ফিরে যান', 'অনুমোদনে পাঠান']);
      await tester.tap(find.byKey(const ValueKey('collect-check-post')));
      await tester.pumpAndSettle();
      floor(tester, screen: 'পর্দা ২, পোস্টের পরে');
      buttons(tester, screen: 'পর্দা ২, পোস্টের পরে', labels: ['শেষ']);
    });

    testWidgets('screen 2 says "পোস্ট" when nobody needs to sign', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _review(tester);
      buttons(tester, screen: 'পর্দা ২', labels: ['ফিরে যান', 'পোস্ট']);
    });

    /// WCAG-এর আপেক্ষিক উজ্জ্বলতা আর অনুপাত
    double ratio(Color a, Color b) {
      final la = a.computeLuminance();
      final lb = b.computeLuminance();
      final hi = la > lb ? la : lb;
      final lo = la > lb ? lb : la;
      return (hi + 0.05) / (lo + 0.05);
    }

    for (final (mode, theme) in [('light', AppTheme.light()), ('dark', AppTheme.dark())]) {
      test('a selected chip stands apart in $mode: fill or outline 3:1 on the card, its label 4.5:1 on the fill', () {
        final c = CollectChipColors.of(theme);
        final edge = [ratio(c.selectedFill, c.unselectedSurface), ratio(c.selectedBorder, c.unselectedSurface)].reduce((a, b) => a > b ? a : b);
        expect(edge, greaterThanOrEqualTo(3), reason: '⛔ বাছা চিপ না-বাছা থেকে আলাদা দেখায় না ($mode, ${edge.toStringAsFixed(2)}:1)');
        final label = ratio(c.selectedLabel, c.selectedFill);
        expect(label, greaterThanOrEqualTo(4.5), reason: '⛔ বাছা চিপের লেখা পড়া যায় না ($mode, ${label.toStringAsFixed(2)}:1)');
      });
    }

    testWidgets('the chips on screen use those colours: the selected one filled and outlined, the others not', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      final c = CollectChipColors.of(Theme.of(tester.element(find.byType(CollectScreen))));
      ChoiceChip chip(String key) => tester.widget<ChoiceChip>(find.descendant(of: find.byKey(ValueKey(key)), matching: find.byType(ChoiceChip)));
      expect(chip('collect-party-customer').selectedColor, c.selectedFill);
      expect(chip('collect-party-customer').side?.color, c.selectedBorder);
      expect(chip('collect-party-customer').side?.width, 2);
      expect(chip('collect-party-supplier').side, isNull);
      expect(chip('collect-method-cash').selected, isTrue);
      expect(chip('collect-method-cash').labelStyle?.color, c.selectedLabel);
    });
  });

  group('the journal balances (fe, 22 Oct 2026; a6 d5cc9657f ledgerLines)', () {
    /// পর্দার টাকা → পয়সা ("৳45,000.51" → 4500051)
    int paisa(String text) => paisaOf(text.split('|').last.replaceAll('৳', '').replaceAll(',', '').trim());

    Future<void> check(WidgetTester tester, FakeCollect api, String what) async {
      final p = api.lastPreview!;
      final dr = p.lines.fold(0, (s, l) => s + l.dr);
      final cr = p.lines.fold(0, (s, l) => s + l.cr);
      final applied = paisa(_texts(tester, 'collect-net-applied'));
      final unapplied = paisa(_texts(tester, 'collect-unapplied'));
      expect([dr, cr, p.totalDr, p.totalCr], everyElement(applied + unapplied),
          reason: '⛔ $what: Dr $dr · Cr $cr · মোট ${p.totalDr}/${p.totalCr} ≠ বিলে প্রয়োগ + অপ্রয়োগকৃত ${applied + unapplied}');
      expect(find.text('মোট মিলেছে'), findsOneWidget, reason: '⛔ $what: মেলা খাতায় "মোট মিলেছে" নেই');
    }

    for (final (what, amount, method, adjust) in [
      ('cash, oldest first', '45000', 'cash', <String, String>{}),
      ('bank with a charge', '45000', 'transfer', {'charge': '25'}),
      ('rounding ৳0.51 (the sign state)', '45000', 'transfer', {'rounding': '0.51'}),
      ('discount, VDS and AIT', '40000', 'cash', {'discount': '1000', 'vds': '500', 'ait': '200'}),
      ('more than the due', '60000', 'cash', <String, String>{}),
      ('MFS', '1500', 'mfs', <String, String>{}),
    ]) {
      testWidgets('10 · Dr = Cr = applied + unapplied, in paisa: $what', (tester) async {
        final api = FakeCollect();
        await _pump(tester, _screen(api));
        await _pickShop(tester);
        await _type(tester, 'collect-amount', amount);
        if (method != 'cash') {
          await _tap(tester, 'collect-method-$method');
          await _type(tester, 'collect-reference', 'NP1');
        }
        if (adjust['charge'] case final c?) {
          await _tap(tester, 'collect-bank-change');
          await tester.enterText(find.byKey(const ValueKey('collect-bank-charge')), c);
          await tester.tap(find.byKey(const ValueKey('collect-bank-done')));
          await tester.pumpAndSettle();
        }
        if (adjust.keys.any((k) => k != 'charge')) {
          await _tap(tester, 'collect-adjust');
          if (adjust['rounding'] case final r?) {
            await _tap(tester, 'collect-difference-rounding');
            await _type(tester, 'collect-difference', r);
          }
          if (adjust['discount'] case final d?) await _type(tester, 'collect-difference', d);
          if (adjust['vds'] case final v?) await _type(tester, 'collect-vds', v);
          if (adjust['ait'] case final a?) await _type(tester, 'collect-ait', a);
        }
        await _review(tester);
        await check(tester, api, what);
      });
    }

    testWidgets('10 · the sign state reads Dr 1102 45,000.00 + Dr 5305 0.51 = Cr 1110 45,000.51', (tester) async {
      final api = FakeCollect();
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _tap(tester, 'collect-method-transfer');
      await _type(tester, 'collect-reference', 'NP1');
      await _tap(tester, 'collect-adjust');
      await _tap(tester, 'collect-difference-rounding');
      await _type(tester, 'collect-difference', '0.51');
      await _review(tester);
      expect(_texts(tester, 'collect-journal-0'), 'Dr 1102 ডাচ-বাংলা ব্যাংক চলতি · ময়মনসিংহ | 45,000.00');
      expect(_texts(tester, 'collect-journal-1'), 'Dr 5305 রাউন্ডিং পার্থক্য · ময়মনসিংহ | 0.51', reason: '⛔ রাউন্ডিংয়ের ৫৩০৫ লাইন নেই');
      expect(_texts(tester, 'collect-journal-2'), 'Cr 1110 প্রাপ্য হিসাব · করিম স্টোর · ময়মনসিংহ | 45,000.51');
      expect(_texts(tester, 'collect-net-applied'), 'বিলে প্রয়োগ | ৳45,000.51');
      expect(find.text('মোট মিলেছে'), findsOneWidget);
    });

    testWidgets('10 · an unbalanced preview never says "মোট মিলেছে"', (tester) async {
      final api = FakeCollect(unbalancedPreview: true);
      await _pump(tester, _screen(api));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _review(tester);
      expect(find.text('মোট মিলেছে'), findsNothing, reason: '⛔ মেলেনি, তবু "মোট মিলেছে"');
      expect(find.text('মোট মেলেনি — পোস্ট করবেন না, অফিসে জানান।'), findsOneWidget);
    });

    test('10 · "balanced" needs Dr = Cr, lines that add up, the server not saying otherwise, and Cr = applied + unapplied', () {
      const ok = [JournalLine(account: 'Dr', dr: 4500000, cr: 0), JournalLine(account: 'Cr', dr: 0, cr: 4500000)];
      expect(JournalLines.isBalanced(ok, 4500000, 4500000, serverBalanced: true, expected: 4500000), isTrue);
      expect(JournalLines.isBalanced(ok, 4500000, 4500001), isFalse, reason: 'মোট Dr ≠ Cr');
      expect(JournalLines.isBalanced(ok, 4500051, 4500051), isFalse, reason: 'লাইনের যোগ মোটের সমান নয়');
      expect(JournalLines.isBalanced(ok, 4500000, 4500000, serverBalanced: false), isFalse, reason: 'সার্ভার বলল মেলেনি');
      expect(JournalLines.isBalanced(ok, 4500000, 4500000, expected: 4500051), isFalse, reason: 'পর্দার প্রয়োগ + অপ্রয়োগকৃতের সাথে মেলে না');
    });
  });

  testWidgets('18 · a customer outside this person\'s reach (the server\'s deliberate 404) says so, is not an old server, and nothing goes',
      (tester) async {
    final api = FakeCollect(outOfReach: true);
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    expect(find.text('এই গ্রাহক আপনার শাখায় নেই'), findsWidgets, reason: '⛔ নাগালের বাইরের গ্রাহকের কথা নেই');
    expect(find.text('নিজে থেকে: পুরনো বিল আগে'), findsNothing, reason: '⛔ ৪০৪ পুরনো সার্ভার বলে ধরা হলো');
    await _type(tester, 'collect-amount', '45000');
    expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed, isNull);
    expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-draft'))).onPressed, isNull);
    expect(api.sent, isEmpty);
  });

  group('a6\'s final names (8006bd78e)', () {
    testWidgets('403 on the collection doors (a walled SR): "আদায় নেওয়ার অনুমতি নেই", and nothing can be sent', (tester) async {
      final api = FakeCollect(notAllowed: true);
      await _pump(tester, _screen(api));
      expect(find.text('আদায় নেওয়ার অনুমতি নেই'), findsOneWidget, reason: '⛔ ৪০৩-এর কথা নেই');
      expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed, isNull);
      expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-draft'))).onPressed, isNull);
      expect(api.sent, isEmpty);
    });

    // ⛔ মালিক, ২০ নভেম্বর ২০২৬: হোমের "＋ নতুন"-এ আদায় কারো নেই — জমার খবরের চাবিতে "টাকা জমার খবর"
    test('18 · the home\'s "＋ নতুন" offers no collection to anyone; the deposit-report key offers the deposit report', () {
      const sr = ['sales.order.create', 'sales.collection.view', 'sales.collection.create'];
      expect(RoleTabs.createChoices(sr).map((c) => c.route), isNot(contains('/home/collections')),
          reason: '⛔ SR-এর "＋ নতুন"-এ আদায়');
      expect(RoleTabs.createChoices(sr).map((c) => c.route), contains('/home/new-order'));
      const office = ['sales.collection.create', 'accounts.voucher.create'];
      expect(RoleTabs.createChoices(office).map((c) => c.route), isNot(contains('/home/collections')),
          reason: '⛔ অফিসের "＋ নতুন"-এ আদায়');
      const field = ['sales.order.create', 'sales.claim.submit'];
      expect(RoleTabs.createChoices(field).map((c) => c.route), contains('/home/deposit-report'));
    });

    for (final (typed, blocked) in [('5.00', false), ('6.00', true)]) {
      testWidgets('4 · the server\'s rounding limit wins: limit "5.0000", rounding ৳$typed ${blocked ? 'blocked' : 'allowed'}', (tester) async {
        await _pump(tester, _screen(FakeCollect(roundingLimit: '5.0000')));
        await _pickShop(tester);
        await _type(tester, 'collect-amount', '45000');
        await _tap(tester, 'collect-adjust');
        await _tap(tester, 'collect-difference-rounding');
        await _type(tester, 'collect-difference', typed);
        expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed == null, blocked);
        expect(find.text('রাউন্ডিং ৳5.00-এর বেশি হতে পারে না।'), blocked ? findsOneWidget : findsNothing);
      });
    }

    testWidgets('4 · the server\'s signature threshold wins: above "1.0000", ৳0.51 needs no signature and the line says ৳1.00',
        (tester) async {
      await _pump(tester, _screen(FakeCollect(roundingSignAbove: '1.0000')));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _tap(tester, 'collect-adjust');
      await _tap(tester, 'collect-difference-rounding');
      await _type(tester, 'collect-difference', '0.51');
      await _review(tester);
      expect(_texts(tester, 'collect-sum-rounding'), 'রাউন্ডিং (৳1.00-এর বেশি হলে মালিকের অনুমোদন) | ৳0.51');
      expect(_texts(tester, 'collect-check-post'), 'পোস্ট', reason: '⛔ সার্ভারের সীমার নিচে, তবু অনুমোদন চাওয়া হলো');
    });

    for (final (typed, sign, blocked) in [('0.51', true, false), ('10.00', true, false), ('10.01', false, true)]) {
      testWidgets('4 · without the setup fields (old server) the owner\'s ৳0.50 / ৳10 apply: ৳$typed', (tester) async {
        await _pump(tester, _screen(FakeCollect(roundingLimit: null, roundingSignAbove: null)));
        await _pickShop(tester);
        await _type(tester, 'collect-amount', '45000');
        await _tap(tester, 'collect-adjust');
        await _tap(tester, 'collect-difference-rounding');
        await _type(tester, 'collect-difference', typed);
        expect(tester.widget<ButtonStyleButton>(find.byKey(const ValueKey('collect-post'))).onPressed == null, blocked);
        if (blocked) {
          expect(find.text('রাউন্ডিং ৳10.00-এর বেশি হতে পারে না।'), findsOneWidget);
          return;
        }
        await _review(tester);
        expect(_texts(tester, 'collect-check-post'), sign ? 'অনুমোদনে পাঠান' : 'পোস্ট');
      });
    }

    test('17 · the receipt opens through the phone\'s documents door: the path from receipt_pdf, else the paper\'s own id', () {
      const fromServer = CollectionSaved(id: 'col-9', receipt: 'https://erp.test/api/v1/documents/Collection/col-9/pdf');
      expect(fromServer.receiptDoor, (type: 'Collection', id: 'col-9'));
      const pathOnly = CollectionSaved(id: 'col-9', receipt: '/documents/Collection/9f2e-uuid/pdf');
      expect(pathOnly.receiptDoor, (type: 'Collection', id: '9f2e-uuid'));
      const none = CollectionSaved(id: 'col-7');
      expect(none.receiptDoor, (type: 'Collection', id: 'col-7'), reason: 'পথ না এলে আদায়ের নিজের আইডি');
    });
  });

  /// ⛔ পরীক্ষার ভুল-ঢোকানো পথ (debugAfterAllocate) অ্যাপের আসল কোনো পথে বসে না (fe, ২৪ অক্টোবর ২০২৬): ওয়েবের ঠিকানা থেকে
  /// খোলা পাতা (NativeForWeb, `/sales/collections/create`), আদায় তালিকার "＋ নতুন আদায়" আর ভাউচার তালিকার "আদায় ভাউচার" —
  /// তিনটার তৈরি করা উইজেট নিজে দেখা, grep নয়। release-এ বসানো constructor-এর assert-এ থামে।
  // ⛔ মালিক, ২০ নভেম্বর ২০২৬ (টাকা নেওয়ার এক কাগজ): আগের পথ — ওয়েবের পুরনো লিংক আর আদায়ের তালিকা — এখন টাকা জমার খবর
  // খোলে; ভাউচারের রসিদ খোলে রসিদের ফর্ম (vouchers_test)। কোনো আসল পথ আর আদায়ের পাতা খোলে না।
  test('no real path opens the collection screen: the old web link and the collections list open the deposit report', () {
    final routed = NativeForWeb.screenFor('/sales/collections/create');
    expect(routed, isA<ModuleGateView>(), reason: 'ওয়েবের ঠিকানাটা চেনা নেই');
    final fromWeb = (routed! as ModuleGateView).child;
    final fromList = MoneyInListScreen.defaultReportDeposit();
    for (final (path, screen) in [('web path', fromWeb), ('collections list', fromList)]) {
      expect(screen, isA<DepositRequestScreen>(), reason: '$path টাকা জমার খবর খোলে না');
      expect(screen, isNot(isA<CollectScreen>()), reason: '⛔ $path এখনো আদায়ের পাতা খোলে');
    }
  });

  testWidgets('the collections list\'s "টাকা জমার খবর" opens the deposit report', (tester) async {
    await _pump(
        tester,
        MoneyInListScreen(
            api: FakeBooks(),
            canReportDeposit: true,
            dues: () => const [],
            unsent: () => const [],
            reportDeposit: () => const Scaffold(body: Text('DEPOSIT REPORT'))));
    await tester.tap(find.byKey(const ValueKey('money-in-deposit-report')));
    await tester.pumpAndSettle();
    expect(find.text('DEPOSIT REPORT'), findsOneWidget);
    expect(find.byType(CollectScreen), findsNothing);
  });

  testWidgets('19 · the same fields, names and order as the web and the design, two screens, one column, buttons fixed at the bottom',
      (tester) async {
    await _pump(tester, _screen(FakeCollect()), size: const Size(412, 892));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '45000');
    await _tap(tester, 'collect-method-transfer');

    // ⓘ ওয়েবের লেখা (lang ফাইল) — অ্যাপের লেবেল হুবহু; লেবেলের "*" কেবল বাধ্যতামূলক বোঝায়
    const web = {
      'sales::field.customer': 'গ্রাহক',
      'sales::field.date': 'তারিখ',
      'sales::field.amount': 'টাকা',
      'accounts::field.paid_into': 'যে খাতে জমা',
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
    List<String> labels() => [
          for (final d in tester.widgetList<InputDecorator>(find.byType(InputDecorator))) d.decoration.labelText ?? '',
          for (final t in tester.widgetList<Text>(find.byType(Text))) t.data ?? '',
        ];
    bool shown(List<String> all, String w) => all.any((l) => l == w || l == '$w *' || l.startsWith('$w ('));
    final screen1 = labels();
    for (final w in ['গ্রাহক', 'তারিখ', 'টাকা', 'যে খাতে জমা', 'বিবরণ']) {
      expect(shown(screen1, w), isTrue, reason: '⛔ ওয়েবের ঘর "$w" পাতায় নেই বা অন্য নামে');
    }
    for (final w in ['টাকা নেওয়া', 'বকেয়া', 'এই আদায়', 'বাকি থাকবে', 'সাধারণ তথ্য', 'পক্ষের ধরন *', 'পদ্ধতি', 'ব্যাংক',
      'খোলা বিল ও ক্রেডিট', 'মোট প্রয়োগ', 'সংযুক্তি ও বিবরণ', 'খসড়া', 'দেখে পোস্ট ›', 'নির্বাচিত বিল', 'পুরো বকেয়া']) {
      expect(find.text(w), findsWidgets, reason: '⛔ নকশার "$w" নেই');
    }
    for (final old in ['অঙ্ক (টাকা)', 'কোন খাতে', 'মন্তব্য (ঐচ্ছিক)', 'এখনই নিশ্চিত', 'ব্যাংক ট্রান্সফার']) {
      expect(screen1.contains(old), isFalse, reason: '⛔ পুরনো নাম "$old" রয়ে গেল');
    }
    await _tap(tester, 'collect-bank-change');
    final editor = labels();
    for (final w in ['ব্যাংক চার্জ', 'ট্রান্সফার মোড', 'মূল্য-তারিখ']) {
      expect(shown(editor, w), isTrue, reason: '⛔ ব্যাংকের ঘর "$w" নেই');
    }
    await tester.tap(find.byKey(const ValueKey('collect-bank-done')));
    await tester.pumpAndSettle();

    // ⓘ নকশার ক্রম: পক্ষের ধরন → গ্রাহক → টাকা → পদ্ধতি → যে খাতে জমা → রেফারেন্স → ব্যাংকের লাইন → বিল → কাটা ও ছাড় → সংযুক্তি
    // (পদ্ধতি আগে — ওয়েবের ২৪ অক্টোবরের ক্রম, cb 2bddefbb0)
    double y(String key) => tester.getTopLeft(find.byKey(ValueKey(key))).dy;
    final order = [
      'collect-party-customer', 'collect-customer', 'collect-amount', 'collect-method-cash', 'collect-account', 'collect-reference',
      'collect-bank-line', 'collect-bills', 'collect-adjust', 'collect-narration',
    ];
    final ys = [for (final k in order) y(k)];
    expect([...ys]..sort(), ys, reason: '⛔ ঘরের ক্রম নকশার মতো নয়');

    // ⓘ এক কলাম — প্রতিটা অংশ একই বাঁ ধারে, একই চওড়ায়
    final cardOf = [
      find.descendant(of: find.byKey(const ValueKey('collect-bills')), matching: find.byType(Card)).first,
      find.ancestor(of: find.byKey(const ValueKey('collect-adjust')), matching: find.byType(Card)).first,
    ];
    expect({for (final c in cardOf) tester.getTopLeft(c).dx}, hasLength(1));
    expect({for (final c in cardOf) tester.getSize(c).width}, hasLength(1));

    // ⓘ নিচের বোতাম স্থির — পাতা গড়ালেও একই জায়গায়
    final before = tester.getRect(find.byKey(const ValueKey('collect-post')));
    await tester.drag(find.byKey(const ValueKey('collect-list')), const Offset(0, -600));
    await tester.pumpAndSettle();
    expect(tester.getRect(find.byKey(const ValueKey('collect-post'))), before, reason: '⛔ বোতাম পাতার সাথে সরে গেল');

    // ⓘ দুই পর্দা: পর্দা ২ আলাদা, তার বোতামও নিচে স্থির
    await _type(tester, 'collect-reference', 'NP1');
    await _review(tester);
    expect(find.byType(CollectReviewScreen), findsOneWidget);
    for (final w in ['হিসাবের সারাংশ', 'খাতায় যা যাবে', 'নিয়ন্ত্রণ', 'ফিরে যান', 'পোস্ট']) {
      expect(find.text(w), findsWidgets, reason: '⛔ পর্দা ২-এর "$w" নেই');
    }
    final post = tester.getRect(find.byKey(const ValueKey('collect-check-post')));
    await tester.drag(find.byKey(const ValueKey('collect-review-list')), const Offset(0, -400));
    await tester.pumpAndSettle();
    expect(tester.getRect(find.byKey(const ValueKey('collect-check-post'))), post);

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
    testWidgets('20 · the keyboard "next" walks the fields in order: amount → reference → narration', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      await _pickShop(tester);
      await _tap(tester, 'collect-method-transfer');
      await tester.tap(find.byKey(const ValueKey('collect-amount')));
      await tester.pumpAndSettle();
      const fields = ['collect-amount', 'collect-reference', 'collect-narration'];
      String focused() {
        final node = FocusManager.instance.primaryFocus;
        for (final k in fields) {
          if (tester.widget<TextField>(find.byKey(ValueKey(k))).focusNode == node) return k;
        }
        return '?';
      }

      final walked = [focused()];
      for (var i = 0; i < 2; i++) {
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

    testWidgets('20 · every control has a screen-reader label and a 48dp target, on both screens', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _screen(FakeCollect()), size: const Size(412, 892));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _tap(tester, 'collect-item-INV-0094');
      await tester.drag(find.byKey(const ValueKey('collect-list')), const Offset(0, 3000));
      await tester.pumpAndSettle();
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await _review(tester);
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      handle.dispose();
    });

    for (final width in [360.0, 412.0]) {
      testWidgets('20 · ${width.toInt()}dp at 200% text: nothing overflows on either screen, all reachable, the fixed buttons never cover the last field',
          (tester) async {
        await _pump(tester, _screen(FakeCollect(preview: previewJson(needsSignature: true))), size: Size(width, 760), textScale: 2);
        await _pickShop(tester);
        await _type(tester, 'collect-amount', '45000');
        await _tap(tester, 'collect-method-transfer');
        await _tap(tester, 'collect-item-INV-0094');
        await _tap(tester, 'collect-adjust');
        expect(tester.takeException(), isNull);
        for (final k in ['collect-bills', 'collect-adjust', 'collect-narration']) {
          await tester.ensureVisible(find.byKey(ValueKey(k)));
          await tester.pumpAndSettle();
        }
        await tester.drag(find.byKey(const ValueKey('collect-list')), const Offset(0, -20000));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: '⛔ 200% লেখায় পর্দা ১ উপচে পড়ল');
        final last = tester.getRect(find.byKey(const ValueKey('collect-narration')));
        final bar = tester.getRect(find.byKey(const ValueKey('collect-post')));
        expect(last.bottom <= bar.top, isTrue, reason: '⛔ স্থির বোতাম শেষ ঘর ঢেকে দিল');

        await _type(tester, 'collect-reference', 'NP1');
        await _review(tester);
        await tester.drag(find.byKey(const ValueKey('collect-review-list')), const Offset(0, -20000));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull, reason: '⛔ 200% লেখায় পর্দা ২ উপচে পড়ল');
        final lastCard = tester.getRect(find.byKey(const ValueKey('collect-controls')));
        expect(lastCard.bottom <= tester.getRect(find.byKey(const ValueKey('collect-check-post'))).top, isTrue);
      });
    }
  });
  /// ⭐ পদ্ধতি আগে, খাত পরে — ওয়েবের ক্রম (cb 2bddefbb0, লাইভ 95a60587b) আর পরিশোধ-খরচের পর্দার একই ক্রম (মালিকের নিয়ম: ওয়েব আর
  /// অ্যাপে একই ঘর, একই ক্রম; fe, ২৪ অক্টোবর ২০২৬)
  group('method first, then only its accounts (web 2bddefbb0)', () {
    testWidgets('the order: party, date, amount, method, then account — as on payment and expense', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      await _pickShop(tester);
      double y(String key) => tester.getTopLeft(find.byKey(ValueKey(key))).dy;
      final order = ['collect-customer', 'collect-date', 'collect-amount', 'collect-method-cash', 'collect-account'];
      for (var i = 1; i < order.length; i++) {
        expect(y(order[i - 1]) < y(order[i]), isTrue, reason: '⛔ ${order[i - 1]} আর ${order[i]} ওয়েবের ক্রমে নয় — পদ্ধতি আগে, খাত পরে');
      }
    });

    testWidgets('own cash first; each method lists only its own kind of account', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      List<String> listed() => tester
          .widget<DropdownButton<CollectionAccount>>(find.byWidgetPredicate((w) => w is DropdownButton<CollectionAccount>))
          .items!
          .map((i) => i.value!.id)
          .toList();
      String? chosen() =>
          tester.widget<DropdownButton<CollectionAccount>>(find.byWidgetPredicate((w) => w is DropdownButton<CollectionAccount>)).value?.id;
      expect(chosen(), ownCash.id, reason: '⛔ নিজের নগদ আগে থেকে বাছা নয়');
      for (final (m, want) in [('mfs', [bkash]), ('transfer', [dbbl]), ('deposit', [dbbl]), ('cheque', [dbbl]), ('cash', [ownCash, cash])]) {
        await _tap(tester, 'collect-method-$m');
        expect(listed(), [for (final a in want) a.id], reason: '⛔ $m-এর তালিকায় অন্য ধরনের খাত');
        expect(chosen(), want.first.id, reason: '⛔ $m বাছার পরেও না-মেলা খাত রয়ে গেল');
      }
    });

    // ⓘ আগে খাত বাছা, তারপর অন্য পদ্ধতি — পুরনো পথে ঠিক এখানে "নগদ"-এ বিকাশের খাত যেতে পারত
    const kindOf = {'cash': 'cash', 'mfs': 'mfs', 'transfer': 'bank', 'deposit': 'bank', 'cheque': 'bank'};
    for (final (pick, then) in [
      ('প্রধান নগদ', 'transfer'),
      ('প্রধান নগদ', 'mfs'),
      ('প্রধান নগদ', 'deposit'),
      (null, 'cheque'),
      (null, 'mfs'),
    ]) {
      testWidgets('no tap sequence sends a mismatch: ${pick ?? 'own cash'} then $then, then back and forth', (tester) async {
        final api = FakeCollect();
        await _pump(tester, _screen(api));
        await _pickShop(tester);
        await _type(tester, 'collect-amount', '45000');
        if (pick != null) {
          await tester.tap(find.byKey(const ValueKey('collect-account')));
          await tester.pumpAndSettle();
          await tester.tap(find.text('1101 · $pick').last);
          await tester.pumpAndSettle();
        }
        await _tap(tester, 'collect-method-$then');
        await _tap(tester, 'collect-method-cash');
        await _tap(tester, 'collect-method-$then');
        if (then != 'cash') {
          await _type(tester, then == 'deposit' ? 'collect-deposit-slip' : 'collect-reference', 'REF-1234');
        }
        await tester.tap(find.byKey(const ValueKey('collect-draft')));
        await tester.pumpAndSettle();
        expect(api.sent, hasLength(1), reason: '⛔ ${pick ?? 'own cash'} → $then: কিছুই গেল না — পদ্ধতি বদলের পরে খাত মেলেনি, তাই পাঠানো আটকাল');
        final d = api.sent.single;
        final account = [cash, ownCash, bkash, dbbl].firstWhere((a) => a.id == d.accountId);
        expect(account.kind, kindOf[d.instrument],
            reason: '⛔ ${pick ?? 'own cash'} → $then: "${d.instrument}" পাঠাল "${account.label}" খাত দিয়ে — সার্ভার ফেরাত');
      });
    }
  });

  /// ⛔ টাকার ঘরে বিয়োগ চিহ্ন ঢোকে না (a4, ২৩ অক্টোবর ২০২৬): কিবোর্ডে নেই, তবু পেস্ট করা "-0.51" আগে ঢুকত — ছাড়/রাউন্ডিং নীরবে
  /// বাদ পড়ত, অথচ বিলের বণ্টন সেই ঋণাত্মক অঙ্কে কষা; আর সার্ভার (a6 5d6c6bac0) ঋণাত্মক পার্থক্য পুশে ফেরায়। এখন পেস্টের
  /// চিহ্ন ঝরে যায় ("-0.51" → "0.51"), আর পাঠানো আদায়ে কোনো টাকার ঘর ঋণাত্মক নয় (ক্রেডিটের সারি ছাড়া — সেটার চিহ্ন চুক্তির)।
  testWidgets('a pasted "-0.51" leaves no minus sign in the amount, the difference, VDS, AIT or a bill row, and nothing negative is sent',
      (tester) async {
    final api = FakeCollect();
    await _pump(tester, _screen(api));
    await _pickShop(tester);
    await _type(tester, 'collect-amount', '-0.51');
    expect(_field(tester, 'collect-amount'), '0.51', reason: '⛔ টাকার ঘরে বিয়োগ চিহ্ন ঢুকল');
    // ⭐ fe-র আরও দুই পেস্ট: টাকার চিহ্নসহ আর বাংলা অঙ্কে
    await _type(tester, 'collect-amount', '৳-5');
    expect(_field(tester, 'collect-amount'), '5', reason: '⛔ "৳-5" টাকার ঘরে বিয়োগ চিহ্নসহ ঢুকল');
    await _type(tester, 'collect-amount', '-৫০');
    expect(_field(tester, 'collect-amount'), '৫০', reason: '⛔ বাংলা অঙ্কের "-৫০" বিয়োগ চিহ্নসহ ঢুকল');
    await _type(tester, 'collect-amount', '-45000');
    expect(_field(tester, 'collect-amount'), '45000', reason: '⛔ টাকার ঘরে বিয়োগ চিহ্ন ঢুকল');
    await _tap(tester, 'collect-adjust');
    await _tap(tester, 'collect-difference-rounding');
    await _type(tester, 'collect-difference', '-0.51');
    expect(_field(tester, 'collect-difference'), '0.51', reason: '⛔ ছাড়/রাউন্ডিংয়ের ঘরে বিয়োগ চিহ্ন ঢুকল');
    for (final key in ['collect-vds', 'collect-ait']) {
      await _type(tester, key, '−5'); // ⓘ ইউনিকোডের বিয়োগ চিহ্নও
      expect(_field(tester, key), '5', reason: '⛔ $key-এ বিয়োগ চিহ্ন ঢুকল');
    }
    await _tap(tester, 'collect-item-INV-0094');
    await _type(tester, 'collect-apply-INV-0094', '-100');
    expect(_field(tester, 'collect-apply-INV-0094'), isNot(contains('-')), reason: '⛔ বিলের সারির ঘরে বিয়োগ চিহ্ন ঢুকল');

    await _post(tester);
    final p = api.sent.single.toPayload();
    expect(p['amount'], '45000.00');
    expect(p['difference'], {'kind': 'rounding', 'amount': '0.51'}, reason: '⛔ রাউন্ডিং ৳0.51 পাঠানো হয়নি (ঋণাত্মক হয়ে বাদ পড়ল?)');
    expect([p['vds'], p['ait']], ['5.00', '5.00']);
    for (final (key, value) in [('amount', p['amount']), ('vds', p['vds']), ('ait', p['ait']), ('bank_charge', p['bank_charge']),
      ('difference', (p['difference'] as Map?)?['amount'])]) {
      expect(value?.toString().startsWith('-') ?? false, isFalse, reason: '⛔ $key ঋণাত্মক গেল: $value');
    }
    for (final line in (p['lines'] as List).cast<Map>()) {
      final credit = line['kind'] == 'credit' || line['kind'] == 'credit_note';
      expect(line['amount'].toString().startsWith('-'), credit, reason: '⛔ সারির চিহ্ন তার ধরনের নয়: $line');
    }
  });

  test('the money formatter drops signs and spaces, keeps Bengali digits and the formatted commas, and refuses anything else whole', () {
    const f = MoneyInputFormatter();
    TextEditingValue type(String old, String next) => f.formatEditUpdate(
        TextEditingValue(text: old, selection: TextSelection.collapsed(offset: old.length)),
        TextEditingValue(text: next, selection: TextSelection.collapsed(offset: next.length)));
    expect(type('', '-0.51').text, '0.51');
    expect(type('', '+৪৫০০০.৫').text, '৪৫০০০.৫');
    // ⭐ fe-র তিন পেস্ট (২৩ অক্টোবর ২০২৬): কোনোটাতেই বিয়োগ চিহ্ন থাকে না
    expect(type('', '৳-5').text, '5', reason: '⛔ "৳-5" থেকে চিহ্ন ঝরেনি');
    expect(type('', '-৫০').text, '৫০', reason: '⛔ বাংলা অঙ্কের "-৫০" থেকে চিহ্ন ঝরেনি');
    expect(type('', '৳ 1,250.50').text, '1,250.50');
    expect(type('', '- 1 250').text, '1250');
    expect(type('1.5', '1.5.').text, '1.5', reason: 'দ্বিতীয় বিন্দু নেওয়া হয় না');
    expect(type('1.52', '1.529').text, '1.52', reason: 'তৃতীয় দশমিক নেওয়া হয় না');
    expect(type('7', '12.345').text, '7', reason: '⛔ পেস্টের বাড়তি দশমিক চুপচাপ কাটা নয় — পুরো পেস্ট বাদ');
    expect(type('7', '1e5').text, '7', reason: '⛔ "1e5" থেকে চুপচাপ "15" নয়');
    expect(type('45,000.00', '45,000.0').text, '45,000.0', reason: 'সাজানো অঙ্কের সম্পাদনা চলে');
    final mid = f.formatEditUpdate(const TextEditingValue(text: '100'),
        const TextEditingValue(text: '-100', selection: TextSelection.collapsed(offset: 1)));
    expect(mid.selection.extentOffset, 0, reason: 'ঝরানো চিহ্নের পরে কার্সর সরে আসে');
  });

  test('the fake speaks the server: a negative difference on the push is refused with the server\'s own regex text', () async {
    // ⓘ সার্ভারের নিয়ম নিজেই — কাঁচা পুশে ঋণাত্মক পার্থক্য ফেরত, সাধারণ দশমিক চলে
    expect(serverRefusal({'amount': '1000.00', 'difference': {'kind': 'rounding', 'amount': '-0.51'}}), 'difference.amount-এর গড়নটা ঠিক নয়।');
    expect(serverRefusal({'amount': '1000.00', 'difference': {'kind': 'rounding', 'amount': '0.51'}}), isNull);
    // ⓘ অ্যাপ ঋণাত্মক পার্থক্য পাঠায়ই না (toPayload কেবল ধনাত্মক রাখে; আর MoneyInputFormatter ঘরেই চিহ্ন ঢুকতে দেয় না)
    final draft = CollectionDraft(customerId: 'cus-1', amount: '1000', date: DateTime(2026, 10, 21), difference: (kind: 'rounding', amount: '-0.51'));
    expect(draft.toPayload().containsKey('difference'), isFalse, reason: '⛔ ঋণাত্মক পার্থক্য পুশে গেল');
    final api = FakeCollect();
    final preview = await api.preview(
        CollectionDraft(customerId: 'cus-1', amount: '1000', date: DateTime(2026, 10, 21), accountId: 'acc-dbbl', difference: (kind: 'rounding', amount: '-0.51')));
    expect(preview!.needsSignature, isTrue, reason: '⛔ সার্ভারের প্রিভিউ −0.51-কে 0.51 পড়ে আর অনুমোদন চায়');
  });

  /// ⭐ চিহ্ন ঝরলে চুপচাপ নয় (fe, ২৩ অক্টোবর ২০২৬): ঘরের নিচে "চিহ্ন বাদ দেওয়া হয়েছে — টাকা সবসময় ধনাত্মক", পর্দা-পাঠকে
  /// ঘোষণা, আর পরের চিহ্নহীন বদলে লেখাটা চলে যায়। টাকার ঘর, ছাড়/রাউন্ডিং, VDS, AIT আর বিলের সারি।
  testWidgets('a dropped sign is told under the field and announced, and the note goes with the next clean edit', (tester) async {
    const note = 'চিহ্ন বাদ দেওয়া হয়েছে — টাকা সবসময় ধনাত্মক';
    await _pump(tester, _screen(FakeCollect()));
    await _pickShop(tester);
    tester.takeAnnouncements();

    Finder under(String key) => find.descendant(of: find.byKey(ValueKey(key)), matching: find.text(note));

    await _type(tester, 'collect-amount', '-45000');
    expect(under('collect-amount'), findsOneWidget, reason: '⛔ চিহ্ন ঝরল, অথচ ঘরের নিচে কিছু বলা নেই');
    expect(tester.takeAnnouncements().map((a) => a.message), contains(note), reason: '⛔ পর্দা-পাঠককে বলা হয়নি');
    // ⓘ ঝরার পরে ঘরে "45000" — একই লেখা আবার বদল নয়, তাই অন্য অঙ্ক
    await _type(tester, 'collect-amount', '46000');
    expect(under('collect-amount'), findsNothing, reason: '⛔ চিহ্নহীন বদলের পরেও পুরনো লেখা রয়ে গেল');
    expect(tester.takeAnnouncements(), isEmpty, reason: '⛔ চিহ্নহীন বদলেও ঘোষণা');

    await _type(tester, 'collect-amount', '৳ 45000');
    expect(under('collect-amount'), findsOneWidget, reason: '⛔ ৳ ঝরল, অথচ বলা নেই');
    await _type(tester, 'collect-amount', ' 45000 ');
    expect(under('collect-amount'), findsNothing, reason: 'কেবল ফাঁকা ঝরলে কিছু বলা হয় না');

    await _tap(tester, 'collect-adjust');
    await _tap(tester, 'collect-difference-rounding');
    for (final key in ['collect-difference', 'collect-vds', 'collect-ait']) {
      await _type(tester, key, '-5');
      expect(under(key), findsOneWidget, reason: '⛔ $key-এ চিহ্ন ঝরল, অথচ বলা নেই');
    }
    await _tap(tester, 'collect-item-INV-0094');
    await _type(tester, 'collect-apply-INV-0094', '-100');
    expect(under('collect-apply-INV-0094'), findsOneWidget, reason: '⛔ বিলের সারিতে চিহ্ন ঝরল, অথচ বলা নেই');
  });

  test('the formatter reports every accepted edit and whether a sign dropped; a refused paste reports nothing', () {
    final seen = <bool>[];
    final f = MoneyInputFormatter(onEdit: seen.add);
    TextEditingValue type(String old, String next) => f.formatEditUpdate(
        TextEditingValue(text: old, selection: TextSelection.collapsed(offset: old.length)),
        TextEditingValue(text: next, selection: TextSelection.collapsed(offset: next.length)));
    type('', '-0.51');
    type('0.51', '0.512'); // ফেরানো
    type('', '৳5');
    type('', '12');
    type('12', '12'); // লেখা একই — কেবল কার্সর
    expect(seen, [true, true, false], reason: '⛔ চিহ্ন ঝরার খবর ভুল');
  });

  /// ⭐ মোট প্রয়োগের নিচে প্রয়োগ না হওয়া টাকা (fe, ২৩ অক্টোবর ২০২৬): "এই আদায়" ৳45,000, "মোট প্রয়োগ" ৳15,100 — ৳29,900 পর্দা ১-এ
  /// কোথাও ছিল না। সার্ভার (a6 5d6c6bac0): unapplied = gross − applied, gross = পাওয়া + VDS + AIT + ছাড়/রাউন্ডিং, গ্রাহকের
  /// নিজের খাতায় Cr; applied > gross হলে ফেরায় (allocation_over_amount)।
  group('what is not applied shows under the total', () {
    const left = 'প্রয়োগ হয়নি — গ্রাহকের খাতায় জমা (Cr) থাকবে: ৳29,900.00';

    testWidgets('৳45,000 with ৳15,100 applied: the line says ৳29,900.00, the same figure screen 2 calls অপ্রয়োগকৃত', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      expect(_shown('collect-unapplied-line'), isFalse, reason: 'পুরো প্রয়োগে লাইন নেই');
      await _tap(tester, 'collect-item-INV-0094');
      await _type(tester, 'collect-apply-INV-0094', '100');
      expect(_texts(tester, 'collect-applied-total'), contains('৳15,100.00'));
      expect(_shown('collect-unapplied-line'), isTrue, reason: '⛔ ৳29,900 প্রয়োগ হয়নি, অথচ মোটের নিচে বলা নেই');
      expect(_texts(tester, 'collect-unapplied-line'), left);
      expect(_texts(tester, 'collect-unapplied-line'), isNot(contains('অগ্রিম')));
      expect(_shown('collect-over-applied'), isFalse);
      await _review(tester);
      expect(_texts(tester, 'collect-unapplied'), contains('৳29,900.00'), reason: '⛔ পর্দা ১ আর ২ আলাদা অঙ্ক বলে');
    });

    testWidgets('fully applied: no line', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      expect(_texts(tester, 'collect-applied-total'), contains('৳45,000.00'));
      expect(_shown('collect-unapplied-line'), isFalse, reason: '⛔ সব প্রয়োগ হলো, তবু "প্রয়োগ হয়নি"');
      expect(_shown('collect-over-applied'), isFalse);
    });

    testWidgets('the figure counts VDS, AIT and the discount as the server\'s gross does', (tester) async {
      await _pump(tester, _screen(FakeCollect()));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      await _tap(tester, 'collect-adjust');
      await _type(tester, 'collect-vds', '500');
      // ⓘ ৳45,000 + VDS ৳500, নিজে বসানো ৳45,500 — কিছু বাকি নেই, কিছু বেশিও নয়
      expect(_texts(tester, 'collect-applied-total'), contains('৳45,500.00'));
      expect(_shown('collect-unapplied-line') || _shown('collect-over-applied'), isFalse,
          reason: '⛔ ৳45,000 + VDS ৳500 = ৳45,500 প্রয়োগ — অঙ্কে VDS ধরা হয়নি');
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('collect-post'))).onPressed, isNotNull);
      await _type(tester, 'collect-ait', '200');
      await _type(tester, 'collect-difference', '300');
      expect(_texts(tester, 'collect-applied-total'), contains('৳46,000.00'));
      expect(_shown('collect-unapplied-line') || _shown('collect-over-applied'), isFalse,
          reason: '⛔ ৳45,000 + VDS ৳500 + AIT ৳200 + ছাড় ৳300 = ৳46,000 প্রয়োগ — অঙ্কে কাটা বা ছাড় ধরা হয়নি');
      await _tap(tester, 'collect-item-INV-0094');
      await _type(tester, 'collect-apply-INV-0094', '100');
      expect(_texts(tester, 'collect-applied-total'), contains('৳16,100.00'));
      expect(_texts(tester, 'collect-unapplied-line'), left, reason: '⛔ ৳46,000 − ৳16,100 = ৳29,900 নয়');
    });

    testWidgets('over-applied: a red line, posting and the draft off, nothing previewed or sent', (tester) async {
      final api = FakeCollect();
      await _pump(
          tester,
          CollectScreen(
            api: api,
            books: FakeBooks(),
            newChangeId: _key,
            today: DateTime(2026, 10, 21),
            openReceipt: _receipt,
            otherParty: _party,
            pickSlip: _picker(null),
            slipSize: (_) async => 1000,
            // ⛔ ভুল ঢোকানো: নিজে-বসানোর পরে প্রথম বিলে ৳500 বেশি — আজ পর্দার কোনো পথে এটা হয় না
            debugAfterAllocate: (plan) => plan.bills.first.applied += 50000,
          ));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      expect(_shown('collect-over-applied'), isTrue, reason: '⛔ প্রয়োগ আদায়ের বেশি, অথচ লাল লাইন নেই');
      expect(_texts(tester, 'collect-over-applied'), 'প্রয়োগ আদায়ের চেয়ে ৳500.00 বেশি — কমান');
      expect(_shown('collect-unapplied-line'), isFalse);
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('collect-post'))).onPressed, isNull,
          reason: '⛔ বেশি প্রয়োগে "দেখে পোস্ট" চালু');
      expect(tester.widget<OutlinedButton>(find.byKey(const ValueKey('collect-draft'))).onPressed, isNull,
          reason: '⛔ বেশি প্রয়োগে খসড়া চালু — সার্ভার ফেরাবে');
      await tester.tap(find.byKey(const ValueKey('collect-post')), warnIfMissed: false);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('collect-draft')), warnIfMissed: false);
      await tester.pumpAndSettle();
      expect(api.sent, isEmpty, reason: '⛔ বেশি প্রয়োগ পাঠানো হলো');
      expect(api.previewed, isEmpty);
    });

    testWidgets('old server (no bills list): neither line', (tester) async {
      await _pump(tester, _screen(FakeCollect(oldServer: true)));
      await _pickShop(tester);
      await _type(tester, 'collect-amount', '45000');
      expect(_shown('collect-bills-auto'), isTrue);
      expect(_shown('collect-unapplied-line'), isFalse);
      expect(_shown('collect-over-applied'), isFalse);
    });
  });
}
