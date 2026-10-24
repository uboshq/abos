import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/vouchers/money_voucher_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/real_fonts.dart';

/// ⭐ পরিশোধ আর খরচের ভাউচার — ওয়েবের টাকা দেওয়ার পর্দার একই ঘর, নাম আর ক্রম (মালিক, ২১ ও ২৪ অক্টোবর ২০২৬; ওয়েব cb 2bddefbb0:
/// simple-form + party-fields + x-money.method, expense-fields; সার্ভারের নাম [[VoucherRequest::rules()]] আর [[VoucherSync]])।

const _cash = VoucherAccount(id: 11, code: '1101-01', name: 'প্রধান নগদ', kind: 'cash');
const _bkash = VoucherAccount(id: 13, code: '1105-01', name: 'বিকাশ এজেন্ট', kind: 'mfs');
const _bank = VoucherAccount(id: 12, code: '1102', name: 'ডাচ-বাংলা ব্যাংক চলতি', kind: 'bank');
const _transportOwed = VoucherAccount(id: 21, code: '2116', name: 'পরিবহন দেনা');
const _staffAdvance = VoucherAccount(id: 61, code: '1131', name: 'কর্মীর অগ্রিম');
const _rent = VoucherAccount(id: 51, code: '5101', name: 'ভাড়া');

const _signed = VoucherParty(
  type: 'supplier',
  id: 3,
  label: 'জাবেদ অ্যাগ্রো ফুড প্রসেসিং লিমিটেড · SUP-0003 · সরবরাহকারী',
  name: 'জাবেদ অ্যাগ্রো ফুড প্রসেসিং লিমিটেড · SUP-0003',
  typeLabel: 'সরবরাহকারী',
  due: 12000000,
  bank: PayeeBank(state: 'signed', line: 'সোনালী ব্যাংক · মতিঝিল · Jabed Agro Food Processing Ltd · হিসাব ••••4521'),
);
const _unsigned = VoucherParty(
  type: 'supplier',
  id: 4,
  label: 'নতুন সাপ্লাই · সরবরাহকারী',
  name: 'নতুন সাপ্লাই',
  typeLabel: 'সরবরাহকারী',
  bank: PayeeBank(state: 'pending'),
);
const _staff = VoucherParty(type: 'staff', id: 8, label: 'রহিম উদ্দিন · কর্মী', name: 'রহিম উদ্দিন', typeLabel: 'কর্মী');

/// ⭐ 2bddefbb0-এর [[VoucherRequest::rules()]] (সহজ ফর্ম) — সার্ভার যে ঘরগুলো পড়ে, হুবহু; আর [[VoucherSync]]-এর `save_as_draft`
const _serverFields = {
  'type', 'trx_date', 'narration', 'branch_id', 'party_new', 'party_mobile', 'party_type', 'party_id', 'instrument',
  'instrument_no', 'instrument_date', 'carried_by', 'moved_at', 'note_counts', 'wallet', 'wallet_medium',
  'counterparty_phone', 'charge_borne_by', 'transfer_mode_id', 'from_branch', 'from_account_name', 'deposit_slip_no',
  'lands_on', 'value_date', 'reverse_on', 'is_adjusting', 'cost_centre_id', 'expense_account_id', 'bill_no',
  'payee_type_id', 'payee_name', 'gross_amount', 'ait_amount', 'vds_amount', 'bill_shares', 'alloc_basis',
  'from_account_id', 'to_account_id', 'amount', 'party', 'ref_date', 'money_category_id', 'money_subcategory_id',
  'from_bank', 'from_account_no', 'charge_amount', 'against_type', 'against_id', 'save_as_draft',
};

/// ⛔ মালিকের নকশায় বাদ (২১ অক্টোবর ২০২৬): দাতার ব্যাংক/শাখা/হিসাবধারী/হিসাব নম্বর, "চার্জটা কে দিল", পুরনো টাকা-চলাচলের ঘর
const _removedFields = {
  'from_account_no', 'from_account_name', 'from_branch', 'charge_borne_by', 'wallet', 'wallet_medium', 'counterparty_phone',
  'lands_on', 'moved_at', 'carried_by',
};

/// প্রতিটা পদ্ধতির নিজের ঘর — ওয়েবের x-money.method হুবহু (নগদ জমা পরিশোধ আর খরচে নেই)
const _methodFieldKeys = ['pay-transfer-mode', 'pay-reference', 'pay-value-date', 'pay-charge', 'pay-cheque-date', 'pay-cheque-bank'];
const _fieldsOf = {
  'cash': <String>{},
  'mfs': {'pay-reference', 'pay-charge'},
  'transfer': {'pay-transfer-mode', 'pay-reference', 'pay-value-date', 'pay-charge'},
  'cheque': {'pay-reference', 'pay-cheque-date', 'pay-cheque-bank'},
};
const _payloadOf = {
  'cash': <String>{},
  'mfs': {'instrument_no', 'charge_amount'},
  'transfer': {'transfer_mode_id', 'instrument_no', 'value_date', 'charge_amount'},
  'cheque': {'instrument_no', 'instrument_date', 'from_bank'},
};

class _Fake implements VoucherApi {
  _Fake({this.modes = true, this.lands, this.refuses});

  final bool modes;
  final String? lands;
  final String? refuses;
  final List<VoucherDraft> sent = [];
  final List<String> keys = [];
  int offline = 0;

  @override
  Future<VoucherSetup> setup(String type) async => VoucherSetup(
        type: type,
        today: DateTime(2026, 10, 24),
        from: VoucherSide(
            label: 'যে খাত থেকে', accounts: type == VoucherTypes.expense ? const [_cash, _bkash, _bank, _transportOwed] : const [_cash, _bkash, _bank]),
        to: VoucherSide(label: 'কাকে', accounts: type == VoucherTypes.expense ? const [_rent] : const [_staffAdvance]),
        parties: const [_signed, _unsigned, _staff],
        partyTypes: const [('supplier', 'সরবরাহকারী'), ('staff', 'কর্মী')],
        transferModes: modes ? const [VoucherTransferMode(id: 1, name: 'BEFTN'), VoucherTransferMode(id: 2, name: 'NPSB')] : null,
      );

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) async {
    keys.add(changeId);
    if (offline > 0) {
      offline--;
      throw const NoNetworkForThis();
    }
    sent.add(draft);
    return PushOutcome(landedId: refuses == null ? lands : null, refusal: refuses);
  }

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: []);

  @override
  Future<VoucherPage> one(String id) => throw UnimplementedError();

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) => throw UnimplementedError();
}

var _seq = 0;
String _key() => 'mv-${_seq++}';

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

Widget _screen(String type, _Fake api) => MoneyVoucherScreen(type: type, api: api, newChangeId: _key, today: DateTime(2026, 10, 24));

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

/// বাছাইয়ের শিট খুলে [option]-তম সারি
Future<void> _pick(WidgetTester tester, String key, int option) async {
  await _tap(tester, key);
  await tester.tap(find.byKey(ValueKey('$key-option-$option')));
  await tester.pumpAndSettle();
}

/// এখনকার পদ্ধতির খাতের তালিকা থেকে [label]
Future<void> _account(WidgetTester tester, String label) async {
  await _tap(tester, 'pay-account');
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

/// খাতের তালিকায় যা আছে — শিট খুলে পড়ে আবার বন্ধ
Future<List<String>> _listed(WidgetTester tester) async {
  await _tap(tester, 'pay-account');
  final shown = [
    for (var i = 0; find.byKey(ValueKey('pay-account-option-$i')).evaluate().isNotEmpty; i++)
      tester.widget<Text>(find.descendant(of: find.byKey(ValueKey('pay-account-option-$i')), matching: find.byType(Text))).data!,
  ];
  Navigator.of(tester.element(find.byKey(const ValueKey('pay-account-option-0')))).pop();
  await tester.pumpAndSettle();
  return shown;
}

String? _chosen(WidgetTester tester) {
  final f = find.byKey(const ValueKey('pay-account-chosen'));
  return f.evaluate().isEmpty ? null : tester.widget<Text>(f).data;
}

bool _shown(String key) => find.byKey(ValueKey(key)).evaluate().isNotEmpty;

Future<void> _send(WidgetTester tester, {bool draft = false}) => _tap(tester, draft ? 'pay-draft' : 'pay-post');

/// সরবরাহকারী (সই-পাওয়া ব্যাংক) আর টাকা
Future<void> _supplierAndAmount(WidgetTester tester, {int party = 0, String amount = '120000'}) async {
  await _pick(tester, 'pay-party', party);
  await _type(tester, 'pay-amount', amount);
}

void main() {
  setUpAll(loadRealFonts);

  group('the fields per method — and no removed field', () {
    for (final type in [VoucherTypes.payment, VoucherTypes.expense]) {
      testWidgets('$type: each method shows only its own fields; nothing of the old money box is on screen', (tester) async {
        await _pump(tester, _screen(type, _Fake()));
        for (final m in MoneyVoucherScreen.methods) {
          await _tap(tester, 'pay-method-$m');
          final shown = {for (final k in _methodFieldKeys) if (_shown(k)) k};
          expect(shown, _fieldsOf[m], reason: '⛔ $m-এ অন্য পদ্ধতির ঘর, বা নিজের ঘর নেই');
        }
        expect(_shown('pay-method-deposit'), isFalse, reason: 'ওয়েবের পরিশোধ আর খরচে নগদ জমা নেই');
        // ⛔ বাদ পড়া ঘর — লেবেল ধরেও নেই
        for (final gone in ['পক্ষ থেকে', 'চার্জটা কে', 'কে দিয়েছে', 'হিসাবধারী', 'হিসাব নম্বর', 'যিনি দিলেন', 'ব্রাঞ্চ', 'কার মাধ্যমে']) {
          expect(find.textContaining(gone), findsNothing, reason: '⛔ বাদ পড়া ঘর "$gone" পর্দায়');
        }
      });

      testWidgets('$type: each method sends only its own fields, even after another method was filled', (tester) async {
        final api = _Fake();
        await _pump(tester, _screen(type, api));
        if (type == VoucherTypes.payment) {
          await _supplierAndAmount(tester);
        } else {
          await _pick(tester, 'pay-head', 0);
          await _type(tester, 'pay-amount', '5000');
        }
        // সব পদ্ধতির ঘর ভরে রাখা
        await _tap(tester, 'pay-method-transfer');
        await _tap(tester, 'pay-transfer-mode');
        await tester.tap(find.text('NPSB').last);
        await tester.pumpAndSettle();
        await _type(tester, 'pay-reference', 'BF26102100981');
        await _type(tester, 'pay-charge', '25');
        await _tap(tester, 'pay-method-cheque');
        await _type(tester, 'pay-cheque-bank', 'সোনালী ব্যাংক');
        for (final m in MoneyVoucherScreen.methods) {
          await _tap(tester, 'pay-method-$m');
          if (m != 'cash') await _type(tester, 'pay-reference', 'REF-$m-1');
          await _send(tester, draft: true);
          final p = api.sent.last.toPayload();
          final methodKeys = p.keys.toSet().intersection({..._payloadOf.values.expand((s) => s)});
          expect(methodKeys, _payloadOf[m]!.difference(m == 'cheque' ? {'instrument_date'} : {}),
              reason: '⛔ $m-এ অন্য পদ্ধতির ঘর পাঠাল');
          expect(p['instrument'], m);
          expect(p.keys.toSet().intersection(_removedFields), isEmpty, reason: '⛔ বাদ পড়া ঘর পাঠাল');
        }
      });
    }
  });

  group('the payload names are the server\'s (2bddefbb0)', () {
    testWidgets('a supplier paid by bank transfer: the exact payload, every name one the server reads', (tester) async {
      final api = _Fake();
      await _pump(tester, _screen(VoucherTypes.payment, api));
      await _supplierAndAmount(tester);
      await _tap(tester, 'pay-method-transfer');
      await _tap(tester, 'pay-transfer-mode');
      await tester.tap(find.text('BEFTN').last);
      await tester.pumpAndSettle();
      await _type(tester, 'pay-reference', 'BF26102100981');
      await _type(tester, 'pay-charge', '25');
      await _type(tester, 'pay-narration', 'বিল PB-0057 আর PB-0061');
      await _send(tester);

      final p = api.sent.single.toPayload();
      expect(p, {
        'type': 'payment',
        'trx_date': '2026-10-24',
        'narration': 'বিল PB-0057 আর PB-0061',
        'from_account_id': 12,
        'to_account_id': null,
        'amount': '120000.00',
        'party_type': 'supplier',
        'party_id': 3,
        'instrument': 'transfer',
        'instrument_no': 'BF26102100981',
        'transfer_mode_id': 1,
        'value_date': '2026-10-24',
        'charge_amount': '25.00',
      });
      expect(_serverFields.containsAll(p.keys), isTrue, reason: '⛔ সার্ভার যে নাম পড়ে না: ${p.keys.toSet().difference(_serverFields)}');
    });

    testWidgets('an expense on cheque: the exact payload, the head in both of the web\'s names', (tester) async {
      final api = _Fake();
      await _pump(tester, _screen(VoucherTypes.expense, api));
      await _pick(tester, 'pay-head', 0);
      await _type(tester, 'pay-payee', 'বাড়িওয়ালা');
      await _type(tester, 'pay-bill-no', 'B-77');
      await _tap(tester, 'pay-deduction');
      await _type(tester, 'pay-gross', '10000');
      await _type(tester, 'pay-ait', '500');
      await _type(tester, 'pay-vds', '0');
      await _type(tester, 'pay-amount', '9500');
      await _tap(tester, 'pay-method-cheque');
      await _type(tester, 'pay-reference', 'CHQ-445566');
      await _type(tester, 'pay-cheque-bank', 'ডাচ-বাংলা');
      await _send(tester);

      final p = api.sent.single.toPayload();
      expect(p, {
        'type': 'expense',
        'trx_date': '2026-10-24',
        'from_account_id': 12,
        'to_account_id': 51,
        'expense_account_id': 51,
        'amount': '9500.00',
        'instrument': 'cheque',
        'instrument_no': 'CHQ-445566',
        'from_bank': 'ডাচ-বাংলা',
        'payee_name': 'বাড়িওয়ালা',
        'bill_no': 'B-77',
        'gross_amount': '10000.00',
        'ait_amount': '500.00',
      });
      expect(_serverFields.containsAll(p.keys), isTrue, reason: '⛔ সার্ভার যে নাম পড়ে না: ${p.keys.toSet().difference(_serverFields)}');
    });

    testWidgets('"খসড়া রাখুন" sends save_as_draft, "পোস্ট" does not; a landed voucher closes the screen with its id', (tester) async {
      final api = _Fake(lands: 'v-9');
      String? landed;
      await _pump(
          tester,
          Builder(
              builder: (context) => TextButton(
                  onPressed: () async => landed = await Navigator.of(context)
                      .push<String>(MaterialPageRoute(builder: (_) => _screen(VoucherTypes.payment, api))),
                  child: const Text('open'))));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      await _supplierAndAmount(tester);
      await _send(tester, draft: true);
      expect(api.sent.single.toPayload()['save_as_draft'], isTrue);
      expect(landed, 'v-9');
    });
  });

  group('the method and the account never disagree', () {
    testWidgets('own cash is chosen first; each method lists only its own kind; changing the method changes the account', (tester) async {
      await _pump(tester, _screen(VoucherTypes.payment, _Fake()));
      expect(_chosen(tester), _cash.label, reason: '⛔ নিজের নগদ আগে থেকে বাছা নয়');
      for (final (m, want) in [('mfs', _bkash), ('transfer', _bank), ('cheque', _bank), ('cash', _cash)]) {
        await _tap(tester, 'pay-method-$m');
        expect(await _listed(tester), [want.label], reason: '⛔ $m-এর তালিকায় অন্য ধরনের খাত');
        expect(_chosen(tester), want.label, reason: '⛔ $m বাছার পরেও আগের ধরনের খাত রয়ে গেল');
      }
    });

    for (final type in [VoucherTypes.payment, VoucherTypes.expense]) {
      testWidgets('$type: whatever is tapped, what goes out has a method and an account of the same kind', (tester) async {
        final api = _Fake();
        await _pump(tester, _screen(type, api));
        if (type == VoucherTypes.payment) {
          await _supplierAndAmount(tester);
        } else {
          await _pick(tester, 'pay-head', 0);
          await _type(tester, 'pay-amount', '5000');
        }
        final byId = {for (final a in [_cash, _bkash, _bank, _transportOwed]) a.id: a};
        // ⓘ আগে খাত, তারপর অন্য পদ্ধতি — পুরনো ফর্মে ঠিক এই পথে "নগদ"-এ বিকাশের খাত যেত
        for (final (first, then) in [('cash', 'transfer'), ('transfer', 'mfs'), ('mfs', 'cheque'), ('cheque', 'cash'), ('transfer', 'cash')]) {
          await _tap(tester, 'pay-method-$first');
          await _tap(tester, 'pay-method-$then');
          if (then != 'cash') await _type(tester, 'pay-reference', 'REF-$first-$then');
          await _send(tester, draft: true);
          final p = api.sent.last.toPayload();
          final account = byId[p['from_account_id']]!;
          expect(MoneyVoucherScreen.kindOf[p['instrument']], account.kind,
              reason: '⛔ $first → $then: "${p['instrument']}" পাঠাল "${account.label}" খাত দিয়ে — সার্ভার ফেরাত');
        }
      });
    }

    test('the kind rule is the server\'s MoneyMethod::KIND_OF', () {
      expect(MoneyVoucherScreen.kindOf, {'cash': 'cash', 'mfs': 'mfs', 'transfer': 'bank', 'deposit': 'bank', 'cheque': 'bank'});
      expect(MoneyVoucherScreen.fits('cash', 'mfs'), isFalse);
      expect(MoneyVoucherScreen.fits('transfer', 'mfs'), isFalse, reason: 'MFS ব্যাংক নয়');
      expect(MoneyVoucherScreen.fits('cheque', 'bank'), isTrue);
      expect(MoneyVoucherScreen.fits('cash', null), isTrue, reason: 'বাকির খাতে পদ্ধতির প্রশ্ন নেই');
    });

    testWidgets('an expense on credit: the payable is listed apart, no money method is sent, and the party is required', (tester) async {
      final api = _Fake();
      await _pump(tester, _screen(VoucherTypes.expense, api));
      await _pick(tester, 'pay-head', 0);
      await _type(tester, 'pay-amount', '700');
      await _account(tester, 'বাকিতে — 2116 · পরিবহন দেনা (দেনা তৈরি হবে)');
      expect(_shown('pay-credit-note'), isTrue);
      expect({for (final k in _methodFieldKeys) if (_shown(k)) k}, isEmpty, reason: 'বাকিতে টাকা নড়ে না — পদ্ধতির ঘর নয়');
      await _send(tester);
      expect(api.sent, isEmpty, reason: '⛔ পক্ষ ছাড়া বাকিতে খরচ পাঠাল');
      expect(find.text('বাকিতে খরচ লিখতে হলে কার কাছে দেনা হচ্ছে সেটা বলতে হবে।'), findsOneWidget);
      await _pick(tester, 'pay-credit-party', 2);
      await _send(tester);
      final p = api.sent.single.toPayload();
      expect([p['from_account_id'], p.containsKey('instrument'), p['party_type'], p['party_id']], [21, false, 'staff', 8]);
    });
  });

  group('the supplier\'s bank', () {
    testWidgets('signed: shown read-only with its line and the two-signature mark; a transfer posts', (tester) async {
      final api = _Fake();
      await _pump(tester, _screen(VoucherTypes.payment, api));
      await _supplierAndAmount(tester);
      await _tap(tester, 'pay-method-transfer');
      expect(find.descendant(of: find.byKey(const ValueKey('pay-payee-bank')), matching: find.byType(EditableText)), findsNothing,
          reason: '⛔ প্রাপকের ব্যাংক বদলানো যায়');
      expect(find.textContaining('••••4521'), findsOneWidget);
      expect(find.text('দুজনের সইয়ে যাচাই-করা'), findsOneWidget);
      expect(_shown('pay-payee-bank-blocks'), isFalse);
      await _type(tester, 'pay-reference', 'BF26102100981');
      await _send(tester);
      expect(api.sent.single.toPayload()['instrument'], 'transfer');
    });

    testWidgets('unsigned: a bank transfer cannot be posted — the reason shows and the post button is off; cash still goes',
        (tester) async {
      final api = _Fake();
      await _pump(tester, _screen(VoucherTypes.payment, api));
      await _supplierAndAmount(tester, party: 1);
      await _tap(tester, 'pay-method-transfer');
      await _type(tester, 'pay-reference', 'BF26102100999');
      expect(_shown('pay-payee-bank-blocks'), isTrue, reason: '⛔ সই-না-পাওয়া ব্যাংক, তবু কারণ নেই');
      expect(tester.widget<FilledButton>(find.byKey(const ValueKey('pay-post'))).onPressed, isNull,
          reason: '⛔ সই-না-পাওয়া ব্যাংকে ট্রান্সফারের পোস্ট খোলা');
      await _send(tester);
      expect(api.sent, isEmpty, reason: '⛔ সই-না-পাওয়া ব্যাংকে ট্রান্সফার পাঠাল');

      await _tap(tester, 'pay-method-cash');
      expect(_shown('pay-payee-bank-blocks'), isFalse);
      await _send(tester);
      expect(api.sent.single.toPayload()['instrument'], 'cash', reason: 'নগদে আটকানোর কথা নয়');
    });

    testWidgets('an old server without bank details draws no bank box and blocks nothing (the server\'s rule stands)', (tester) async {
      final api = _Fake();
      await _pump(tester, _screen(VoucherTypes.payment, api));
      await _tap(tester, 'pay-party-type-staff');
      await _pick(tester, 'pay-party', 0);
      expect(_shown('pay-payee-bank'), isFalse);
    });
  });

  group('the web\'s order and the rest of the form', () {
    double y(WidgetTester tester, String key) => tester.getTopLeft(find.byKey(ValueKey(key))).dy;

    testWidgets('payment: party type · party · date · amount · method · account · reference · charge · narration', (tester) async {
      await _pump(tester, _screen(VoucherTypes.payment, _Fake()));
      await _supplierAndAmount(tester);
      await _tap(tester, 'pay-method-transfer');
      final order = ['pay-party-type-supplier', 'pay-party', 'pay-date', 'pay-amount', 'pay-method-transfer', 'pay-account',
        'pay-payee-bank', 'pay-transfer-mode', 'pay-reference', 'pay-value-date', 'pay-charge', 'pay-narration'];
      for (var i = 1; i < order.length; i++) {
        expect(y(tester, order[i - 1]) < y(tester, order[i]), isTrue, reason: '⛔ ${order[i - 1]} আর ${order[i]} ওয়েবের ক্রমে নয়');
      }
      expect(_shown('pay-to-account'), isFalse, reason: '⛔ সরবরাহকারীর পরিশোধে "কাকে দেওয়া হল" ফিরে এল');
      expect(find.textContaining('পাওনা ৳1,20,000.00'), findsOneWidget, reason: 'পাওনা নামের পাশে');
    });

    testWidgets('expense: date · head · paid to · bill no · deduction · method · account · amount · narration', (tester) async {
      await _pump(tester, _screen(VoucherTypes.expense, _Fake()));
      final order = ['pay-date', 'pay-head', 'pay-payee', 'pay-bill-no', 'pay-deduction', 'pay-method-cash', 'pay-account',
        'pay-amount', 'pay-narration'];
      for (var i = 1; i < order.length; i++) {
        expect(y(tester, order[i - 1]) < y(tester, order[i]), isTrue, reason: '⛔ ${order[i - 1]} আর ${order[i]} ওয়েবের ক্রমে নয়');
      }
    });

    testWidgets('the three figures: owed, this payment, left — and "পুরো বকেয়া" fills the owed amount', (tester) async {
      await _pump(tester, _screen(VoucherTypes.payment, _Fake()));
      await _pick(tester, 'pay-party', 0);
      await _tap(tester, 'pay-full-due');
      final field = tester.widget<TextField>(find.byKey(const ValueKey('pay-amount'))).controller!.text;
      expect(field, '1,20,000.00');
      String fig(String k) => tester.widgetList<Text>(find.descendant(of: find.byKey(ValueKey(k)), matching: find.byType(Text))).last.data!;
      expect([fig('pay-figure-due'), fig('pay-figure-this'), fig('pay-figure-left')], ['৳1,20,000.00', '৳1,20,000.00', '৳0.00']);
    });

    testWidgets('a non-supplier payment asks the account it goes to; a supplier payment does not', (tester) async {
      final api = _Fake();
      await _pump(tester, _screen(VoucherTypes.payment, api));
      await _tap(tester, 'pay-party-type-staff');
      await _pick(tester, 'pay-party', 0);
      await _type(tester, 'pay-amount', '2000');
      await _send(tester);
      expect(api.sent, isEmpty);
      expect(find.text('কোন খাতে যাবে বাছুন।'), findsOneWidget);
      await _pick(tester, 'pay-to-account', 0);
      await _send(tester);
      expect([api.sent.single.toPayload()['to_account_id'], api.sent.single.toPayload()['party_type']], [61, 'staff']);
    });

    testWidgets('posting needs the reference (a draft does not); no transfer modes from an old server: no mode field', (tester) async {
      final api = _Fake(modes: false);
      await _pump(tester, _screen(VoucherTypes.payment, api));
      await _supplierAndAmount(tester);
      await _tap(tester, 'pay-method-mfs');
      expect(find.text('TrxID *'), findsOneWidget);
      await _send(tester);
      expect(api.sent, isEmpty, reason: '⛔ TrxID ছাড়া পোস্টে পাঠাল');
      await _send(tester, draft: true);
      expect(api.sent, hasLength(1), reason: 'খসড়ায় রেফারেন্স লাগে না');
      await _tap(tester, 'pay-method-transfer');
      expect(_shown('pay-transfer-mode'), isFalse);
      expect(_shown('pay-value-date'), isTrue);
    });

    testWidgets('no network keeps the key; a refusal shows the server\'s words and the next try gets a new key', (tester) async {
      final api = _Fake(refuses: '"ডাচ-বাংলা" নগদ-এর খাত নয়')..offline = 1;
      await _pump(tester, _screen(VoucherTypes.payment, api));
      await _supplierAndAmount(tester);
      await _send(tester);
      expect(find.textContaining('ভাউচার লিখতে নেট লাগবে'), findsOneWidget);
      await _send(tester);
      expect(api.keys[0], api.keys[1], reason: '⛔ নেট ফেরার পরে নতুন চাবি');
      expect(find.textContaining('নগদ-এর খাত নয়'), findsOneWidget);
      await _send(tester);
      expect(api.keys[2], isNot(api.keys[1]), reason: '⛔ ফেরানো চাবিতেই আবার');
    });

    testWidgets('a pasted "-25" leaves no minus in the amount or the charge', (tester) async {
      final api = _Fake();
      await _pump(tester, _screen(VoucherTypes.payment, api));
      await _pick(tester, 'pay-party', 0);
      await _type(tester, 'pay-amount', '-500');
      await _tap(tester, 'pay-method-mfs');
      await _type(tester, 'pay-charge', '-25');
      await _type(tester, 'pay-reference', 'TRX12345');
      await _send(tester);
      final p = api.sent.single.toPayload();
      expect([p['amount'], p['charge_amount']], ['500.00', '25.00']);
    });
  });

  group('layout: nothing cut, nothing covered (0.4.48 rules)', () {
    for (final type in [VoucherTypes.payment, VoucherTypes.expense]) {
      for (final width in [320.0, 360.0, 412.0]) {
        for (final scale in [1.0, 2.0]) {
          testWidgets('$type at ${width.toInt()}dp × $scale: no overflow, amounts on one line, the bar never covers the last field',
              (tester) async {
            // ⓘ আসল ফন্টে (অ্যাপের থিম) — পরীক্ষার ফন্টে অক্ষর ১em চওড়া, "এক লাইনে ধরে" মাপা অর্থহীন
            await _pump(tester, _screen(type, _Fake()), size: Size(width, 760), textScale: scale, theme: withRealFonts(AppTheme.light()));
            if (type == VoucherTypes.payment) {
              await _supplierAndAmount(tester);
            } else {
              await _pick(tester, 'pay-head', 0);
              await _type(tester, 'pay-amount', '120000');
              await _tap(tester, 'pay-deduction');
              await _type(tester, 'pay-gross', '120000');
            }
            await _tap(tester, 'pay-method-transfer');
            await _type(tester, 'pay-charge', '25');
            expect(tester.takeException(), isNull);
            await tester.drag(find.byKey(const ValueKey('pay-list')), const Offset(0, -20000));
            await tester.pumpAndSettle();
            expect(tester.takeException(), isNull, reason: '⛔ ${width.toInt()}dp × $scale-এ পর্দা উপচে পড়ল');
            final last = tester.getRect(find.byKey(const ValueKey('pay-narration')));
            for (final b in ['pay-cancel', 'pay-draft', 'pay-post']) {
              expect(last.bottom <= tester.getRect(find.byKey(ValueKey(b))).top, isTrue, reason: '⛔ নিচের বোতাম শেষ ঘর ঢেকে দিল');
            }
            // ⭐ টাকার অঙ্ক কখনো ভাঙা বা কাটা নয় — প্রতিটা "৳…" লেখা এক লাইনে, পুরোটা
            await tester.drag(find.byKey(const ValueKey('pay-list')), const Offset(0, 20000));
            await tester.pumpAndSettle();
            for (final e in find.textContaining('৳').evaluate()) {
              final p = e.renderObject;
              if (p is! RenderParagraph || e.widget is! Text) continue;
              final text = (e.widget as Text).data ?? '';
              if (!RegExp(r'^৳[\d,]+\.\d\d$').hasMatch(text)) continue;
              expect(p.didExceedMaxLines, isFalse, reason: '⛔ "$text" কাটা');
              // ⓘ ৳ আর অঙ্ক দুই ফন্টে — একই লাইনের বাক্সের উপর-কিনারা আলাদা হয়, তবু উপর-নিচে মেশে; নতুন লাইন মানে আগের সব
              // বাক্সের নিচে শুরু
              final boxes = p.getBoxesForSelection(TextSelection(baseOffset: 0, extentOffset: text.length))..sort((a, b) => a.top.compareTo(b.top));
              var lines = 0;
              var bottom = double.negativeInfinity;
              for (final b in boxes) {
                if (b.top >= bottom - 1) lines++;
                if (b.bottom > bottom) bottom = b.bottom;
              }
              expect(lines, 1, reason: '⛔ "$text" দুই লাইনে ভাঙল');
            }
            // ⭐ বোতামের লেখা পুরো — কাটা নয় (মোড়ানো চলে)
            for (final b in ['pay-cancel', 'pay-draft', 'pay-post']) {
              final t = find.descendant(of: find.byKey(ValueKey(b)), matching: find.byType(Text));
              expect((tester.renderObject(t) as RenderParagraph).didExceedMaxLines, isFalse);
            }
          });
        }
      }
    }
  });
}
