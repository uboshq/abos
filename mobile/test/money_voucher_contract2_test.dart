import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/vouchers/money_voucher_screen.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/real_fonts.dart';

/// ⭐ পরিশোধে মোট টাকা — cb-র চুক্তি, খসড়া ১ (E:\ABOS\পরিশোধে-মোট-টাকা-চুক্তি-২৫-অক্টোবর.md; cb/money-display, cb-ma-4): নতুন সার্ভারে
/// (`voucher_contract: 2`) `gross_amount` + AIT/VDS/চার্জ, `amount` কখনো নয়; বিল বাছলে মোটে বকেয়ার যোগফল, প্রতিটা ≤ বকেয়া, যোগফল ≤
/// মোট, বাকিটা পক্ষের নিজের খাতায় (Dr, কখনো "অগ্রিম" নয়); ৪২২ ঘরে ঘরে; নিট কেবল সার্ভারের। পুরনো সার্ভারে সব আগের মতো।

const _cash = VoucherAccount(id: 11, code: '1101-01', name: 'প্রধান নগদ', kind: 'cash');
const _bkash = VoucherAccount(id: 13, code: '1105-01', name: 'বিকাশ এজেন্ট', kind: 'mfs');
const _bank = VoucherAccount(id: 12, code: '1102', name: 'ডাচ-বাংলা ব্যাংক চলতি', kind: 'bank');

// ⓘ সার্ভারের ক্রমে নতুন আগে — পর্দা নিজে পুরনো আগে সাজায়
// ⓘ খোলা বিলের দরজার উত্তর (চুক্তির §৭.১) — `total` ইচ্ছা করে বড়: বিল বাছায় চলে `outstanding`, বিলের মোট নয়
const _openBills = [
  {'id': 'bill-61', 'no': 'PB-0061', 'date': '2026-10-20', 'due_date': '2026-11-19', 'days_late': 0, 'total': '45000.0000', 'outstanding': '40000.0000'},
  {'id': 'bill-57', 'no': 'PB-0057', 'date': '2026-10-15', 'due_date': '2026-10-20', 'days_late': 5, 'total': '95000.0000', 'outstanding': '80000.0000'},
];

const _supplier = VoucherParty(
  type: 'supplier',
  id: 3,
  label: 'জাবেদ অ্যাগ্রো · সরবরাহকারী',
  name: 'জাবেদ অ্যাগ্রো ফুড প্রসেসিং লিমিটেড · SUP-0003',
  due: 12000000,
  bank: PayeeBank(state: 'signed', line: 'সোনালী ব্যাংক · হিসাব ••••4521'),
);

const _staff = VoucherParty(type: 'staff', id: 8, label: 'রহিম উদ্দিন · কর্মী', name: 'রহিম উদ্দিন');

const _longUnknown = 'জাবেদ অ্যাগ্রো ফুড প্রসেসিং লিমিটেড-এর ব্যাংক তথ্যে এখনো দ্বিতীয় সই হয়নি — ব্যাংকে (ট্রান্সফার বা জমা) দেওয়া যাবে না। '
    'করণীয়: সরবরাহকারীর পাতায় ব্যাংক তথ্য দিন। পাতা: https://adi.abos.app/suppliers/3';

class _Fake implements VoucherApi, VoucherPreviewer, VoucherBillsSource {
  _Fake({this.contract = 2, this.previewAnswer, this.previewAbsent = false, this.preview422, this.rejectDetails, this.billsDoor = true});

  /// খোলা বিলের দরজা আছে কি না — না থাকলে ৪০৪ (পুরনো সার্ভার)
  final bool billsDoor;
  final List<(String, int)> billsAsked = [];

  @override
  Future<List<VoucherBill>?> openBills(String type, int id) async {
    billsAsked.add((type, id));
    if (!billsDoor) return null;
    return [for (final b in _openBills) VoucherBill.fromJson(b)!];
  }

  final int contract;
  final Map<String, dynamic>? previewAnswer;
  final bool previewAbsent;
  final Map<String, dynamic>? preview422;
  final Map<String, dynamic>? rejectDetails;
  final List<VoucherDraft> sent = [];
  final List<VoucherDraft> previewed = [];

  @override
  Future<VoucherSetup> setup(String type) async => VoucherSetup(
        type: type,
        today: DateTime(2026, 10, 25),
        from: const VoucherSide(label: '', accounts: [_cash, _bkash, _bank]),
        to: const VoucherSide(label: '', accounts: []),
        parties: const [_supplier, _staff],
        partyTypes: const [('supplier', 'সরবরাহকারী'), ('staff', 'কর্মী')],
        transferModes: const [VoucherTransferMode(id: 1, name: 'BEFTN')],
        contract: contract,
      );

  @override
  Future<VoucherPreview?> preview(VoucherDraft draft) async {
    previewed.add(draft);
    if (previewAbsent) return null;
    if (preview422 != null) {
      final o = RequestOptions(path: '/accounts/vouchers/preview');
      throw DioException(requestOptions: o, response: Response(requestOptions: o, statusCode: 422, data: preview422));
    }
    return VoucherPreview.fromJson(previewAnswer);
  }

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) async {
    sent.add(draft);
    if (rejectDetails != null) {
      return PushOutcome(refusal: rejectDetails!['message'] as String?, details: rejectDetails!);
    }
    return const PushOutcome();
  }

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: []);

  @override
  Future<VoucherPage> one(String id) => throw UnimplementedError();

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) => throw UnimplementedError();
}

Future<void> _pump(WidgetTester tester, _Fake api, {Size size = const Size(412, 5000), double textScale = 1, ThemeData? theme, Locale? locale}) async {
  if (locale != null) {
    AppLocale.current = locale;
    addTearDown(() => AppLocale.current = AppLocale.locale);
  }
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
    theme: theme,
    locale: locale ?? AppLocale.locale,
    supportedLocales: AppLocale.supported,
    localizationsDelegates: AppLocale.delegates,
    home: MediaQuery.withClampedTextScaling(
      minScaleFactor: textScale,
      maxScaleFactor: textScale,
      child: MoneyVoucherScreen(type: VoucherTypes.payment, api: api, newChangeId: () => 'k', today: DateTime(2026, 10, 25)),
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

Future<void> _party(WidgetTester tester) async {
  await _tap(tester, 'pay-party');
  await tester.tap(find.byKey(const ValueKey('pay-party-option-0')));
  await tester.pumpAndSettle();
}

String _field(WidgetTester tester, String key) => tester.widget<TextField>(find.byKey(ValueKey(key))).controller!.text;

bool _shown(String key) => find.byKey(ValueKey(key)).evaluate().isNotEmpty;

/// [key]-এর ঘরের ভুলের লেখা (ঘরের নিচে)
String? _errorUnder(WidgetTester tester, String key) => tester.widget<TextField>(find.byKey(ValueKey(key))).decoration?.errorText;

/// সরবরাহকারী, দুই বিলে টিক, ট্রান্সফার, AIT/VDS/চার্জ
Future<void> _fill(WidgetTester tester) async {
  await _party(tester);
  await _tap(tester, 'pay-bill-tick-PB-0057');
  await _tap(tester, 'pay-bill-tick-PB-0061');
  await _type(tester, 'pay-ait', '2000');
  await _type(tester, 'pay-vds', '1000');
  await _tap(tester, 'pay-method-transfer');
  await _type(tester, 'pay-reference', 'BF26102500981');
  await _type(tester, 'pay-charge', '25');
}

void main() {
  setUpAll(loadRealFonts);

  group('the payload', () {
    testWidgets('new server: gross_amount with AIT, VDS, charge and the bills — never amount', (tester) async {
      final api = _Fake();
      await _pump(tester, api);
      await _fill(tester);
      await _tap(tester, 'pay-draft');
      final p = api.sent.single.toPayload();
      expect(p.containsKey('amount'), isFalse, reason: '⛔ নতুন সার্ভারে পুরনো amount গেল — সার্ভার ফেরাত (gross_or_net_not_both)');
      expect(p, {
        'type': 'payment',
        'trx_date': '2026-10-25',
        'from_account_id': 12,
        'to_account_id': null,
        'party_type': 'supplier',
        'party_id': 3,
        'instrument': 'transfer',
        'instrument_no': 'BF26102500981',
        'value_date': '2026-10-25',
        'charge_amount': '25.00',
        'gross_amount': '120000.00',
        'ait_amount': '2000.00',
        'vds_amount': '1000.00',
        'bills': [
          {'id': 'bill-57', 'amount': '80000.00'},
          {'id': 'bill-61', 'amount': '40000.00'},
        ],
        'save_as_draft': true,
      });
    });

    testWidgets('old server (no voucher_contract): amount as before, no AIT/VDS, no bills, no summary', (tester) async {
      final api = _Fake(contract: 1);
      await _pump(tester, api);
      await _party(tester);
      for (final k in ['pay-ait', 'pay-vds', 'pay-bills', 'pay-summary']) {
        expect(_shown(k), isFalse, reason: '⛔ পুরনো সার্ভারে $k');
      }
      await _type(tester, 'pay-amount', '120000');
      await _tap(tester, 'pay-draft');
      final p = api.sent.single.toPayload();
      expect(p['amount'], '120000', reason: '⛔ পুরনো সার্ভারে amount নেই');
      for (final k in ['gross_amount', 'ait_amount', 'vds_amount', 'bills']) {
        expect(p.containsKey(k), isFalse, reason: '⛔ পুরনো সার্ভারে $k গেল');
      }
    });
  });

  group('the bills', () {
    testWidgets('oldest first; ticking fills the total with the dues; a bill takes no more than its due; the rest is an advance; more than the total is stopped',
        (tester) async {
      final api = _Fake();
      await _pump(tester, api);
      await _party(tester);
      final y57 = tester.getTopLeft(find.byKey(const ValueKey('pay-bill-PB-0057'))).dy;
      final y61 = tester.getTopLeft(find.byKey(const ValueKey('pay-bill-PB-0061'))).dy;
      expect(y57 < y61, isTrue, reason: '⛔ বিল পুরনো আগে নয়');

      await _tap(tester, 'pay-bill-tick-PB-0057');
      expect(_field(tester, 'pay-amount'), '80,000.00', reason: '⛔ বিল বাছলে মোটে তার বকেয়া বসল না');
      await _tap(tester, 'pay-bill-tick-PB-0061');
      expect(_field(tester, 'pay-amount'), '1,20,000.00', reason: '⛔ দুই বিলের বকেয়ার যোগফল মোটে বসল না');

      await _type(tester, 'pay-bill-amount-PB-0057', '90000');
      expect(_field(tester, 'pay-bill-amount-PB-0057'), '80,000.00', reason: '⛔ বিলের অঙ্ক বিলের বকেয়ার বেশি রয়ে গেল');
      expect(_errorUnder(tester, 'pay-bill-amount-PB-0057'), 'বিলের বাকি ৳80,000.00-এর বেশি বসে না।');

      await _type(tester, 'pay-amount', '150000');
      expect(find.text('বিলে প্রয়োগ হয়নি — সরবরাহকারীর খাতায় থাকবে (Dr): ৳30,000.00'), findsOneWidget,
          reason: '⛔ বিলের বাইরের টাকা সরবরাহকারীর খাতায় থাকে বলে দেখানো নয়');

      await _type(tester, 'pay-amount', '100000');
      expect(_shown('pay-bills-over'), isTrue, reason: '⛔ বিলের যোগফল মোটের বেশি, তবু বলা নেই');
      await _tap(tester, 'pay-draft');
      expect(api.sent, isEmpty, reason: '⛔ বিলের যোগফল মোটের বেশি — তবু পাঠাল');
    });
  });

  group('the open-bills door and the unapplied line (contract §7)', () {
    testWidgets('picking the party asks its open bills by type and id; the bill uses outstanding, not its total; days late shown',
        (tester) async {
      final api = _Fake();
      await _pump(tester, api);
      await _party(tester);
      expect(api.billsAsked, [('supplier', 3)], reason: '⛔ খোলা বিলের দরজা ভুল পক্ষে, বা ডাকা হয়নি');
      expect(find.text('বাকি ৳80,000.00'), findsOneWidget, reason: '⛔ বিলের বাকি outstanding থেকে নয়');
      expect(find.textContaining('95,000'), findsNothing, reason: '⛔ বিলের মোট টাকা বাকি বলে দেখাল');
      expect(find.byKey(const ValueKey('pay-bill-late-PB-0057')), findsOneWidget);
      expect(find.byKey(const ValueKey('pay-bill-late-PB-0061')), findsNothing);
      await _tap(tester, 'pay-bill-tick-PB-0057');
      expect(_field(tester, 'pay-amount'), '80,000.00', reason: '⛔ বিল বাছায় outstanding বসল না');
    });

    testWidgets('no open-bills door (404): no bills, and the form still sends the total', (tester) async {
      final api = _Fake(billsDoor: false);
      await _pump(tester, api);
      await _party(tester);
      expect(_shown('pay-bills'), isFalse, reason: '⛔ দরজা নেই, তবু বিলের অংশ');
      await _type(tester, 'pay-amount', '5000');
      await _tap(tester, 'pay-draft');
      expect(api.sent.single.toPayload()['gross_amount'], '5000');
      expect(api.sent.single.toPayload().containsKey('bills'), isFalse);
    });

    for (final locale in [AppLocale.locale, AppLocale.english]) {
      testWidgets("overpaying a supplier never says advance (${locale.languageCode}): it stays in the supplier's ledger (Dr)", (tester) async {
        final api = _Fake();
        await _pump(tester, api, locale: locale);
        await _party(tester);
        await _tap(tester, 'pay-bill-tick-PB-0057');
        await _type(tester, 'pay-amount', '130000');
        final line = tester.widget<Text>(find.descendant(of: find.byKey(const ValueKey('pay-bills-unapplied')), matching: find.byType(Text))).data!;
        expect(
            line,
            locale.languageCode == 'bn'
                ? 'বিলে প্রয়োগ হয়নি — সরবরাহকারীর খাতায় থাকবে (Dr): ৳50,000.00'
                : "Not applied to bills — stays in the supplier's ledger (Dr): ৳50,000.00");
        final texts = [for (final t in tester.widgetList<Text>(find.byType(Text))) t.data ?? ''];
        expect(texts.where((t) => t.contains('অগ্রিম') || t.toLowerCase().contains('advance')), isEmpty,
            reason: '⛔ পরিশোধের পর্দায় "অগ্রিম"/"advance" — সরবরাহকারীর আলাদা অগ্রিম খাত নেই');
      });
    }

    testWidgets("a non-supplier party's bills come by its own type and id", (tester) async {
      final api = _Fake();
      await _pump(tester, api);
      await _tap(tester, 'pay-party-type-staff');
      await _party(tester);
      expect(api.billsAsked, [('staff', 8)]);
      await _tap(tester, 'pay-bill-tick-PB-0061');
      await _type(tester, 'pay-amount', '50000');
      expect(find.text('বিলে প্রয়োগ হয়নি — পক্ষের খাতায় থাকবে (Dr): ৳10,000.00'), findsOneWidget,
          reason: '⛔ সরবরাহকারী নয় এমন পক্ষে তাঁর নিজের খাতার কথা নয়');
    });
  });

  group('the server\'s 422, field by field', () {
    const errors = {
      'gross_amount': ['মোট টাকা দিন।'],
      'ait_amount': ['AIT ভুল।'],
      'vds_amount': ['VDS ভুল।'],
      'charge_amount': ['চার্জ ভুল।'],
      'instrument_no': ['TrxID আগে ব্যবহার হয়েছে।'],
      'bills.0.amount': ['PB-0057-এর বাকি ৳80,000-এর বেশি।'],
      'bills': ['বিলের যোগফল মোটের বেশি।'],
      'payee_bank': [_longUnknown],
    };

    void expectPlaced(WidgetTester tester) {
      expect(_errorUnder(tester, 'pay-amount'), 'মোট টাকা দিন।', reason: '⛔ gross_amount মোট টাকার ঘরে নয়');
      expect(_errorUnder(tester, 'pay-ait'), 'AIT ভুল।', reason: '⛔ ait_amount AIT-এর ঘরে নয়');
      expect(_errorUnder(tester, 'pay-vds'), 'VDS ভুল।', reason: '⛔ vds_amount VDS-এর ঘরে নয়');
      expect(_errorUnder(tester, 'pay-charge'), 'চার্জ ভুল।', reason: '⛔ charge_amount চার্জের ঘরে নয়');
      expect(_errorUnder(tester, 'pay-reference'), 'TrxID আগে ব্যবহার হয়েছে।', reason: '⛔ instrument_no রেফারেন্সের ঘরে নয়');
      // ⓘ পাঠানো ক্রমে (পুরনো আগে) bills.0 = PB-0057
      expect(_errorUnder(tester, 'pay-bill-amount-PB-0057'), 'PB-0057-এর বাকি ৳80,000-এর বেশি।', reason: '⛔ bills.0.amount তার বিলের সারিতে নয়');
      expect(_errorUnder(tester, 'pay-bill-amount-PB-0061'), isNull, reason: '⛔ bills.0.amount ভুল বিলে বসল');
      expect(find.descendant(of: find.byKey(const ValueKey('pay-bills-error')), matching: find.text('বিলের যোগফল মোটের বেশি।')), findsOneWidget,
          reason: '⛔ bills বিলের মোটের নিচে নয়');
      expect(tester.widget<Text>(find.byKey(const ValueKey('pay-error'))).data, _longUnknown, reason: '⛔ অচেনা চাবির কথা উপরে পুরোটা নয়');
    }

    testWidgets('from the summary door: each key under its own field, the unknown one on top in full', (tester) async {
      final api = _Fake(preview422: {'message': 'মোট টাকা দিন। (and 7 more errors)', 'errors': errors});
      await _pump(tester, api);
      await _fill(tester);
      await _tap(tester, 'pay-preview');
      expectPlaced(tester);
    });

    testWidgets('from a refused send that carries errors: the same places', (tester) async {
      final api = _Fake(rejectDetails: {'status': 'REJECTED', 'message': 'মোট টাকা দিন।', 'errors': errors});
      await _pump(tester, api);
      await _fill(tester);
      await _tap(tester, 'pay-draft');
      expectPlaced(tester);
    });
  });

  group('the net is the server\'s only', () {
    testWidgets('before the server answers there is no net; its answer shows as given; a change hides it again', (tester) async {
      final api = _Fake(previewAnswer: {
        'net_amount': '116500.0000',
        'ledger': [
          {'account': {'id': 211, 'code': '2111', 'name': 'সরবরাহকারীর দেনা'}, 'debit': '120000.0000', 'credit': '0'},
          {'account': {'id': 12, 'code': '1102', 'name': 'ডাচ-বাংলা ব্যাংক চলতি'}, 'debit': '0', 'credit': '116525.0000'},
        ],
        'total_dr': '120025.0000',
        'total_cr': '120025.0000',
      });
      await _pump(tester, api);
      await _fill(tester);
      // ⓘ নিজে গুনলে 1,20,000 − 2,000 − 1,000 = 1,17,000 — পর্দায় কোথাও থাকার কথা নয়
      expect(_shown('pay-sum-net'), isFalse, reason: '⛔ সার্ভারের উত্তরের আগেই নিট');
      expect(find.textContaining('1,17,000.00'), findsNothing, reason: '⛔ ফোন নিজে নিট গুনল');
      expect(_shown('pay-net-from-server'), isTrue);

      await _tap(tester, 'pay-preview');
      expect(tester.widgetList<Text>(find.descendant(of: find.byKey(const ValueKey('pay-sum-net')), matching: find.byType(Text))).last.data,
          '৳1,16,500.00', reason: '⛔ সার্ভারের নিট নয়');
      expect(_shown('pay-ledger-1'), isTrue);
      // ⓘ খাত বস্তু হয়ে আসে ({id, code, name}, চুক্তির §৭.৩) — পর্দায় "কোড নাম"
      expect(find.text('2111 সরবরাহকারীর দেনা'), findsOneWidget, reason: '⛔ preview-এর খাতের বস্তু পড়া গেল না');
      expect(find.textContaining('1,17,000.00'), findsNothing, reason: '⛔ ফোন নিজে নিট গুনল');

      await _type(tester, 'pay-ait', '2500');
      expect(_shown('pay-sum-net'), isFalse, reason: '⛔ লেখা বদলেছে, তবু পুরনো নিট রয়ে গেল');
    });

    testWidgets('no summary door on the server: the button goes, the net is promised for later, never guessed', (tester) async {
      final api = _Fake(previewAbsent: true);
      await _pump(tester, api);
      await _fill(tester);
      await _tap(tester, 'pay-preview');
      expect(_shown('pay-preview'), isFalse);
      expect(_shown('pay-sum-net'), isFalse);
      expect(find.text('নিট সার্ভার গোনে — খসড়া রাখলে বা পোস্টের পরে ভাউচারে দেখাবে।'), findsOneWidget);
      expect(find.textContaining('1,17,000.00'), findsNothing, reason: '⛔ ফোন নিজে নিট গুনল');
    });
  });

  group('layout: nothing cut, nothing covered', () {
    for (final width in [320.0, 360.0, 412.0]) {
      for (final scale in [1.0, 2.0]) {
        testWidgets('${width.toInt()}dp × $scale with bills, withholding and the server\'s summary', (tester) async {
          final api = _Fake(previewAnswer: {
            'net_amount': '116500.0000',
            'ledger': [
              {'account': {'id': 211, 'code': '2111', 'name': 'সরবরাহকারীর দেনা'}, 'debit': '120000.0000', 'credit': '0'},
              {'account': {'id': 12, 'code': '1102', 'name': 'ডাচ-বাংলা ব্যাংক চলতি'}, 'debit': '0', 'credit': '116525.0000'},
            ],
          });
          await _pump(tester, api, size: Size(width, 760), textScale: scale, theme: withRealFonts(AppTheme.light()));
          await _fill(tester);
          await _type(tester, 'pay-amount', '150000');
          await _tap(tester, 'pay-preview');
          expect(tester.takeException(), isNull);
          await tester.drag(find.byKey(const ValueKey('pay-list')), const Offset(0, -20000));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull, reason: '⛔ ${width.toInt()}dp × $scale-এ উপচে পড়ল');
          final last = tester.getRect(find.byKey(const ValueKey('pay-narration')));
          expect(last.bottom <= tester.getRect(find.byKey(const ValueKey('pay-post'))).top, isTrue, reason: '⛔ নিচের বোতাম শেষ ঘর ঢাকে');
          for (final e in find.textContaining('৳').evaluate()) {
            final p = e.renderObject;
            if (p is! RenderParagraph || e.widget is! Text) continue;
            final text = (e.widget as Text).data ?? '';
            if (!RegExp(r'^৳[\d,]+\.\d\d$').hasMatch(text)) continue;
            expect(p.didExceedMaxLines, isFalse, reason: '⛔ "$text" কাটা');
            final boxes = p.getBoxesForSelection(TextSelection(baseOffset: 0, extentOffset: text.length))..sort((a, b) => a.top.compareTo(b.top));
            var lines = 0;
            var bottom = double.negativeInfinity;
            for (final b in boxes) {
              if (b.top >= bottom - 1) lines++;
              if (b.bottom > bottom) bottom = b.bottom;
            }
            expect(lines, 1, reason: '⛔ "$text" দুই লাইনে ভাঙল');
          }
        });
      }
    }
  });
}
