import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/vouchers/money_voucher_screen.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ ফোনে অফিসের লোকের ভাউচার (0.4.25) — মালিক, ৭ অক্টোবর ২০২৬; সার্ভার 2bd0f620 (ThePhoneWritesVouchersLikeTheWebTest)।
const _cash = VoucherAccount(id: 11, code: '1101-01', name: 'প্রধান নগদ', kind: 'cash');
const _bank = VoucherAccount(id: 12, code: '1102-01', name: 'DBBL চলতি', kind: 'bank');
const _income = VoucherAccount(id: 41, code: '4101', name: 'অন্যান্য আয়');
const _rent = VoucherAccount(id: 51, code: '5101', name: 'ভাড়া');

class _FakeVouchers implements VoucherApi {
  _FakeVouchers({this.lands, this.refuses, this.offlineTimes = 0, this.page, this.rows});

  /// তালিকার সারি — না দিলে আগের দুটো
  final List<VoucherRow>? rows;
  final String? lands;
  final String? refuses;
  int offlineTimes;
  final VoucherPage? page;
  VoucherDraft? sent;
  final List<String> keys = [];
  final List<bool> awaitingAsked = [];
  final List<Map<String, String?>> asked = [];
  String? postedWith;
  PostAnswer postAnswer = const PostAnswer(posted: true, message: 'JV-1 পাকা হয়েছে — খাতায় উঠেছে।');

  @override
  Future<VoucherSetup> setup(String type) async => VoucherSetup(
        type: type,
        today: DateTime(2026, 10, 7),
        narrationRequired: type == VoucherTypes.payment,
        from: const VoucherSide(label: 'কোথা থেকে', accounts: [_income, _cash, _bank]),
        to: const VoucherSide(label: 'কোথায়', accounts: [_cash, _bank, _rent]),
        accounts: const [_cash, _rent, _income],
        parties: const [VoucherParty(type: 'customer', id: 7, label: 'রহিম স্টোর · গ্রাহক')],
      );

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async {
    awaitingAsked.add(awaiting);
    asked.add({'type': type, 'money': money, 'from': from, 'to': to, 'branch': branch});
    if (rows case final own?) return VoucherListPage(rows: own, count: own.length);
    return const VoucherListPage(rows: [
      VoucherRow(id: 'v1', no: 'RV-0001', type: 'receipt', state: 'awaiting', amount: 500, stateLabel: 'অনুমোদনের অপেক্ষায়', writtenBy: 'আবু কাওসার'),
      VoucherRow(id: 'v2', no: 'JV-0002', type: 'journal', state: 'posted', amount: 100, stateLabel: 'পাকা'),
    ], count: 2, total: 600);
  }

  @override
  Future<VoucherPage> one(String id) async => page!;

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) async {
    postedWith = instrumentNo;
    return postAnswer;
  }

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) async {
    keys.add(changeId);
    if (offlineTimes > 0) {
      offlineTimes--;
      throw const NoNetworkForThis();
    }
    sent = draft;
    return PushOutcome(landedId: lands, refusal: refuses);
  }
}

var _seq = 0;
String _key() => 'vk-${_seq++}';

Future<void> _pump(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = const Size(900, 3000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

Future<void> _pickIn(WidgetTester tester, String tileKey, int option) async {
  await tester.tap(find.byKey(ValueKey(tileKey)));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(ValueKey('$tileKey-option-$option')));
  await tester.pumpAndSettle();
}

void main() {
  group('the payload is the web form\'s own fields', () {
    test('a receipt: two accounts, the amount, the party as type:id, the way and the TrxID', () {
      final p = VoucherDraft(
              type: 'receipt', date: DateTime(2026, 10, 7), narration: ' জমা ', fromAccountId: 41, toAccountId: 12,
              amount: '500', party: 'customer:7', instrument: 'transfer', instrumentNo: ' TRX-9 ')
          .toPayload();
      expect(p, {
        'type': 'receipt', 'trx_date': '2026-10-07', 'narration': 'জমা', 'from_account_id': 41, 'to_account_id': 12,
        'amount': '500', 'party': 'customer:7', 'instrument': 'transfer', 'instrument_no': 'TRX-9',
      });
    });

    test('a journal sends lines and no two-account fields; keep-as-draft rides along', () {
      final p = VoucherDraft(type: 'journal', date: DateTime(2026, 10, 7), keepAsDraft: true, lines: const [
        {'account_id': 51, 'debit': '100', 'credit': '0'},
        {'account_id': 41, 'debit': '0', 'credit': '100'},
      ]).toPayload();
      expect(p.containsKey('from_account_id'), isFalse);
      expect(p['lines'], hasLength(2));
      expect(p['save_as_draft'], isTrue);
    });

    test('the way follows the money account: cash, mfs, bank → transfer', () {
      expect(_cash.instrument, 'cash');
      expect(const VoucherAccount(id: 1, name: 'bKash', kind: 'mfs').instrument, 'mfs');
      expect(_bank.instrument, 'transfer');
      expect(_rent.instrument, isNull);
    });
  });

  group('the real path: online now, never the queue', () {
    late HiveTestHarness harness;
    late HttpClientAdapter real;
    late _Server server;

    setUpAll(() async {
      FakeSecureStorage.install();
      harness = await HiveTestHarness.setUp();
      await SyncEngine.instance.init();
      real = ApiClient.dio.httpClientAdapter;
    });

    tearDownAll(() async {
      ApiClient.dio.httpClientAdapter = real;
      await SyncEngine.instance.dispose();
      await harness.tearDown();
    });

    setUp(() async {
      server = _Server();
      ApiClient.dio.httpClientAdapter = server;
      if (Hive.isBoxOpen('abos_sync_queue')) await Hive.box<Map>('abos_sync_queue').clear();
    });

    test('it goes to the accounts push door with the phone\'s key and lands', () async {
      final out = await const ServerVoucherApi()
          .send(VoucherDraft(type: 'journal', date: DateTime(2026, 10, 7), lines: const []), 'vk-real-1');
      expect(out.landedId, 'v-9');
      expect(server.paths, ['/sync/accounts/push']);
      expect(server.changes.single['entityType'], 'Voucher');
      expect(server.changes.single['changeId'], 'vk-real-1');
      expect(SyncEngine.instance.pendingCount, 0);
    });

    test('no network: NoNetworkForThis, nothing queued', () async {
      server.offline = true;
      await expectLater(
          const ServerVoucherApi().send(VoucherDraft(type: 'journal', date: DateTime(2026, 10, 7)), 'vk-real-2'),
          throwsA(isA<NoNetworkForThis>()));
      expect(SyncEngine.instance.pendingCount, 0, reason: '⛔ ভাউচার সারিতে উঠল — নেট না থাকলে শুধু অর্ডার');
    });
  });

  group('the form', () {
    testWidgets('nothing goes without both sides, with one account twice, or a malformed amount', (tester) async {
      final api = _FakeVouchers(lands: 'v-1');
      await _pump(tester, VoucherFormScreen(type: 'receipt', api: api, newChangeId: _key));

      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      expect(find.text('দুই দিকের খাত বাছুন।'), findsOneWidget);

      await _pickIn(tester, 'voucher-from', 1); // নগদ
      await _pickIn(tester, 'voucher-to', 0); // নগদ — একই
      await tester.enterText(find.byKey(const ValueKey('voucher-amount')), '500');
      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      expect(find.text('দুই দিকে একই খাত হয় না।'), findsOneWidget);

      await _pickIn(tester, 'voucher-from', 0); // আয়
      await tester.enterText(find.byKey(const ValueKey('voucher-amount')), '1e5');
      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      expect(find.textContaining('অঙ্কটা ঠিক নয়'), findsOneWidget);
      expect(api.sent, isNull, reason: '⛔ ভুল ফর্মেও পাঠাল');
    });

    // ⭐ টাকা লেখা থাকলে "পিছনে" জিজ্ঞেস করে; খালি ভাউচারে নয় (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
    testWidgets('a voucher with an amount typed asks before going back, an empty one does not', (tester) async {
      await _pump(tester, VoucherFormScreen(type: 'receipt', api: _FakeVouchers(lands: 'v-1'), newChangeId: _key));
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('লেখা ফেলে চলে যাবেন?'), findsNothing, reason: 'খালি ভাউচারে প্রশ্ন নয়');

      await tester.enterText(find.byKey(const ValueKey('voucher-amount')), '500');
      await tester.pumpAndSettle();
      await tester.binding.handlePopRoute();
      await tester.pumpAndSettle();
      expect(find.text('লেখা ফেলে চলে যাবেন?'), findsOneWidget, reason: '⛔ টাকা লেখা, তবু "পিছনে" কিছু না বলে ভাউচার ফেলে দিল');
    });

    testWidgets('a bank receipt asks the TrxID and carries the party; it lands and the form closes with the id',
        (tester) async {
      final api = _FakeVouchers(lands: 'v-1');
      String? landed;
      await _pump(
          tester,
          Builder(
              builder: (context) => TextButton(
                  onPressed: () async => landed = await Navigator.of(context).push<String>(MaterialPageRoute(
                      builder: (_) => VoucherFormScreen(type: 'receipt', api: api, newChangeId: _key))),
                  child: const Text('open'))));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      await _pickIn(tester, 'voucher-from', 0);
      await _pickIn(tester, 'voucher-to', 1); // ব্যাংক
      expect(find.byKey(const ValueKey('voucher-form-trx')), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('voucher-amount')), '500');
      await tester.enterText(find.byKey(const ValueKey('voucher-form-trx')), 'TRX-77');
      await _pickIn(tester, 'voucher-party', 0);
      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();

      final p = api.sent!.toPayload();
      expect(p['to_account_id'], 12);
      expect(p['instrument'], 'transfer');
      expect(p['instrument_no'], 'TRX-77');
      expect(p['party'], 'customer:7');
      expect(landed, 'v-1');
    });

    testWidgets('no network says so and keeps the key; a refusal shows its reason and the next try gets a new key',
        (tester) async {
      final api = _FakeVouchers(refuses: 'বাকিতে খরচ লিখতে হলে কার কাছে দেনা হচ্ছে সেটা বলতে হবে', offlineTimes: 1);
      await _pump(tester, VoucherFormScreen(type: 'expense', api: api, newChangeId: _key));
      await _pickIn(tester, 'voucher-from', 1);
      await _pickIn(tester, 'voucher-to', 2);
      await tester.enterText(find.byKey(const ValueKey('voucher-amount')), '300');

      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      expect(find.textContaining('ভাউচার লিখতে নেট লাগবে'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      expect(api.keys[0], api.keys[1], reason: '⛔ নেট ফেরার পরে নতুন চাবি');
      expect(find.textContaining('কার কাছে দেনা'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      expect(api.keys[2], isNot(api.keys[1]), reason: '⛔ ফেরানো চাবিতেই আবার');
    });

    testWidgets('the narration switch is asked on the phone too', (tester) async {
      final api = _FakeVouchers(lands: 'v-1');
      await _pump(tester, VoucherFormScreen(type: 'payment', api: api, newChangeId: _key));
      await _pickIn(tester, 'voucher-from', 1);
      await _pickIn(tester, 'voucher-to', 2);
      await tester.enterText(find.byKey(const ValueKey('voucher-amount')), '300');
      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      expect(find.textContaining('বিবরণ লিখুন'), findsOneWidget);
      expect(api.sent, isNull);
    });

    testWidgets('a journal goes only when debit equals credit, with each line', (tester) async {
      final api = _FakeVouchers(lands: 'v-1');
      await _pump(tester, VoucherFormScreen(type: 'journal', api: api, newChangeId: _key));
      await _pickIn(tester, 'voucher-line-0-account', 1);
      await tester.enterText(find.byKey(const ValueKey('voucher-line-0-debit')), '100');
      await _pickIn(tester, 'voucher-line-1-account', 2);
      await tester.enterText(find.byKey(const ValueKey('voucher-line-1-credit')), '90');
      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      expect(find.text('ডেবিট আর ক্রেডিট মেলেনি।'), findsOneWidget);
      expect(api.sent, isNull);

      await tester.enterText(find.byKey(const ValueKey('voucher-line-1-credit')), '100');
      await tester.tap(find.byKey(const ValueKey('voucher-send')));
      await tester.pumpAndSettle();
      final lines = api.sent!.toPayload()['lines'] as List;
      expect(lines, [
        {'account_id': 51, 'debit': '100', 'credit': '0'},
        {'account_id': 41, 'debit': '0', 'credit': '100'},
      ]);
    });
  });

  group('the voucher\'s page', () {
    VoucherPage page({bool canPost = false, bool another = false, String? instrument, String? no}) => VoucherPage(
          row: const VoucherRow(id: 'v1', no: 'RV-0001', type: 'receipt', state: 'draft', amount: 500, stateLabel: 'খসড়া'),
          lines: const [
            VoucherLineView(account: '1102-01 DBBL চলতি', debit: 500, credit: 0),
            VoucherLineView(account: '4101 অন্যান্য আয়', debit: 0, credit: 500),
          ],
          instrument: instrument,
          instrumentNo: no,
          canPost: canPost,
          awaitsAnotherHand: another,
        );

    testWidgets('the writer sees why they cannot post; no post button', (tester) async {
      await _pump(tester, VoucherScreen(id: 'v1', api: _FakeVouchers(page: page(another: true))));
      expect(find.byKey(const ValueKey('voucher-another-hand')), findsOneWidget);
      expect(find.byKey(const ValueKey('voucher-post')), findsNothing);
      expect(find.text('ডেবিট ৳500.00'), findsOneWidget);
    });

    testWidgets('another hand posts, giving the TrxID a bank voucher lacks; a held signature reads as a warning',
        (tester) async {
      final api = _FakeVouchers(page: page(canPost: true, instrument: 'transfer'));
      await _pump(tester, VoucherScreen(id: 'v1', api: api));
      await tester.enterText(find.byKey(const ValueKey('voucher-trx')), 'TRX-1');
      await tester.tap(find.byKey(const ValueKey('voucher-post')));
      await tester.pumpAndSettle();
      expect(api.postedWith, 'TRX-1');
      expect(find.byKey(const ValueKey('voucher-notice')), findsOneWidget);

      api.postAnswer = const PostAnswer(posted: false, message: 'RV-0001 খসড়া হিসেবে আছে, অনুমোদনের অপেক্ষায়');
      await tester.tap(find.byKey(const ValueKey('voucher-post')));
      await tester.pumpAndSettle();
      expect(find.textContaining('অনুমোদনের অপেক্ষায়'), findsOneWidget);
    });
  });

  test('who the money comes from or goes to reads by type; the server\'s own word first (owner, 7 Oct 2026)', () {
    const receipt = VoucherRow(id: 'a', no: 'RV-1', type: 'receipt', state: 'draft', amount: 1, party: 'রহিম স্টোর');
    const payment = VoucherRow(id: 'b', no: 'PV-1', type: 'payment', state: 'draft', amount: 1);
    const journal = VoucherRow(id: 'c', no: 'JV-1', type: 'journal', state: 'draft', amount: 1);
    expect([receipt.partyWords, payment.partyWords, journal.partyWords], ['কার কাছ থেকে', 'কাকে', 'পক্ষ']);
    expect(VoucherRow.fromJson({'type': 'expense', 'party_label': 'কাকে দেওয়া'}).partyWords, 'কাকে দেওয়া');
    final page = VoucherPage.fromJson({'type': 'receipt', 'party': 'রহিম স্টোর', 'lines': [
      {'account': '1101 নগদ', 'debit': '500', 'credit': '0'},
      {'account': '1110 প্রাপ্য', 'debit': '0', 'credit': '500', 'party': 'রহিম স্টোর'},
    ]});
    expect([page.row.party, page.lines[0].party, page.lines[1].party], ['রহিম স্টোর', null, 'রহিম স্টোর']);
  });

  testWidgets('the voucher page says who the money came from, and the line carries its party', (tester) async {
    await _pump(tester, VoucherScreen(id: 'v1', api: _FakeVouchers(page: const VoucherPage(
      row: VoucherRow(id: 'v1', no: 'RV-0001', type: 'receipt', state: 'draft', amount: 500, party: 'রহিম স্টোর', partyLabel: 'কার কাছ থেকে'),
      moneyAccount: '1101 নগদ টিল', moneyLabel: 'কোথায় জমা হলো',
      lines: [
        VoucherLineView(account: '1101 নগদ', debit: 500, credit: 0),
        VoucherLineView(account: '1110 প্রাপ্য', debit: 0, credit: 500, party: 'রহিম স্টোর', narration: 'বিল INV-1'),
      ],
    ))));
    expect(find.text('কার কাছ থেকে'), findsOneWidget);
    expect(find.text('কোথায় জমা হলো'), findsOneWidget);
    expect(find.text('1101 নগদ টিল'), findsOneWidget);
    expect(find.text('রহিম স্টোর · বিল INV-1'), findsOneWidget);
  });

  testWidgets('the list shows each state, and "অনুমোদনের অপেক্ষায়" asks the server for those only', (tester) async {
    final api = _FakeVouchers();
    await _pump(tester, VoucherListScreen(api: api, newChangeId: _key, branches: () async => const []));
    expect(find.text('RV-0001 · আদায় ভাউচার'), findsOneWidget);
    // ⭐ অবস্থা অ্যাপের এক চিপে (নকশা ৩, অংশ ৫)
    expect(find.byWidgetPredicate((w) => w is AppStatusChip && w.state == PaperState.waiting), findsOneWidget);
    expect(find.byWidgetPredicate((w) => w is AppStatusChip && w.state == PaperState.posted), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('voucher-awaiting')));
    await tester.pumpAndSettle();
    expect(api.awaitingAsked.last, isTrue);
  });

  /// ⭐ নগদ রসিদ আলাদা — মালিক, ১৫ অক্টোবর ২০২৬ (০.৪.৩৪; সার্ভার: ThePhoneWritesVouchersLikeTheWebTest::…cash_and_bank_receipts…)।
  testWidgets('the four buttons ask for cash or bank receipts or payments, the total and the writer show, a branch goes by public id',
      (tester) async {
    final api = _FakeVouchers();
    await _pump(
        tester,
        VoucherListScreen(
          api: api,
          newChangeId: _key,
          today: DateTime(2026, 10, 15),
          branches: () async => const [
            OrgRef(publicId: 'br-mym', code: 'MYM', name: 'ময়মনসিংহ'),
            OrgRef(publicId: 'br-ntk', code: 'NTK', name: 'নেত্রকোনা'),
          ],
        ));

    // ⓘ প্রথম ডাক — সব, এ মাস
    expect(api.asked.first, {'type': null, 'money': null, 'from': '2026-10-01', 'to': '2026-10-15', 'branch': null});
    for (final label in ['সব', 'নগদ রসিদ', 'ব্যাংক রসিদ', 'পেমেন্ট']) {
      expect(find.widgetWithText(ChoiceChip, label), findsOneWidget, reason: 'বোতাম "$label" নেই');
    }

    await tester.tap(find.byKey(const ValueKey('voucher-view-cash-receipt')));
    await tester.pumpAndSettle();
    expect([api.asked.last['type'], api.asked.last['money']], ['receipt', 'cash'], reason: '⛔ নগদ রসিদ সার্ভারকে নগদ বলেনি');

    await tester.tap(find.byKey(const ValueKey('voucher-view-bank-receipt')));
    await tester.pumpAndSettle();
    expect([api.asked.last['type'], api.asked.last['money']], ['receipt', 'bank']);

    await tester.tap(find.byKey(const ValueKey('voucher-view-payment')));
    await tester.pumpAndSettle();
    expect([api.asked.last['type'], api.asked.last['money']], ['payment', null]);

    // ⭐ মোট সার্ভারের, আর কে তুলেছেন
    expect(find.byKey(const ValueKey('books-total')), findsOneWidget);
    expect(find.text('তুলেছেন: আবু কাওসার'), findsOneWidget);

    // ⓘ শাখা — public_id যায়
    await tester.tap(find.byKey(const ValueKey('voucher-branch-br-ntk')));
    await tester.pumpAndSettle();
    expect(api.asked.last['branch'], 'br-ntk');
    await tester.tap(find.byKey(const ValueKey('voucher-branch-all')));
    await tester.pumpAndSettle();
    expect(api.asked.last['branch'], isNull);
  });

  // ⭐ ওয়েবের মতো "অ্যাকশন ▾" — মালিক, ৮ নভেম্বর ২০২৬ ("অ্যাপস এর যে লিস্টগুলো আছে তাতেও অ্যাকশন বাটন দিতে হবে")
  testWidgets('every voucher row carries "Actions ▾": view, print & send, and confirm greyed with its reason', (tester) async {
    await _pump(tester, VoucherListScreen(api: _FakeVouchers(), newChangeId: _key, branches: () async => const []));
    expect(find.byKey(const ValueKey('row-actions-RV-0001')), findsOneWidget, reason: '⛔ সারিতে "অ্যাকশন ▾" নেই');
    expect(find.byKey(const ValueKey('row-actions-JV-0002')), findsOneWidget, reason: '⛔ সারিতে "অ্যাকশন ▾" নেই');
    // ⓘ ওয়েবের মতো সারির ডানে — বাঁয়ে বসলে নম্বর আর অঙ্ক ঠেলে দেয়
    expect(tester.getCenter(find.byKey(const ValueKey('row-actions-RV-0001'))).dx,
        greaterThan(tester.getCenter(find.byKey(const ValueKey('voucher-v1'))).dx), reason: '⛔ "অ্যাকশন ▾" সারির ডানে নয়');

    await tester.tap(find.byKey(const ValueKey('row-actions-RV-0001')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('row-actions-sheet')), findsOneWidget, reason: '⛔ অ্যাকশনের শিট খোলে না');
    for (final key in ['view', 'print', 'confirm']) {
      expect(find.byKey(ValueKey('row-action-$key')), findsOneWidget, reason: '⛔ শিটে "$key" নেই');
    }
    expect(tester.widget<ListTile>(find.byKey(const ValueKey('row-action-confirm'))).enabled, isFalse, reason: '⛔ অপেক্ষার কাগজে আবার নিশ্চিত করা যায়');
    expect(find.text('অনুমোদনের অপেক্ষায় — অনুমোদন হলে নিজে খাতায় বসবে'), findsOneWidget, reason: '⛔ কেন করা যায় না, বলে না');
    await tester.tapAt(const Offset(10, 10));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('row-actions-JV-0002')));
    await tester.pumpAndSettle();
    expect(find.text('নিশ্চিত হওয়া কাগজে আর কিছু নিশ্চিত করার নেই'), findsOneWidget, reason: '⛔ পাকা কাগজের কারণ ভুল');
  });

  // ⭐ "নতুন ভাউচার" কেবল লেখার চাবিওয়ালার — চাবি না থাকলে বোতামই নেই (সার্ভারের দরজাও `can:accounts.voucher.create`)
  testWidgets('"নতুন ভাউচার" shows only to one who may write a voucher', (tester) async {
    await _pump(tester, VoucherListScreen(api: _FakeVouchers(), newChangeId: _key, branches: () async => const []));
    expect(find.byKey(const ValueKey('voucher-new')), findsNothing, reason: '⛔ চাবি ছাড়াই নতুন ভাউচারের বোতাম');

    await _pump(tester, VoucherListScreen(api: _FakeVouchers(), newChangeId: _key, branches: () async => const [], canCreate: true));
    expect(find.byKey(const ValueKey('voucher-new')), findsOneWidget, reason: 'চাবি থাকলেও বোতাম নেই');
  });

  test('the tile comes with the web\'s write key and the phone\'s accounts switch closes it', () async {
    const repository = MenuRepository();
    const clerk = AuthUser(id: '1', name: 'C', email: 'c@abos.test', roles: ['accountant'], permissions: ['accounts.voucher.create']);
    const sr = AuthUser(id: '2', name: 'S', email: 's@abos.test', roles: ['salesman'], permissions: ['sales.order.create']);
    expect((await repository.menuFor(clerk)).map((i) => i.key), contains('accounts.voucher.create'));
    expect((await repository.menuFor(sr)).map((i) => i.key), isNot(contains('accounts.voucher.create')));
    expect(ModuleGate.allows({'sales'}, 'vouchers'), isFalse);
    expect(ModuleGate.allows({'accounts'}, 'vouchers'), isTrue);
  });

  // ── ⭐ প্রতিটা ধরন আলাদা — মালিক, ১৭ অক্টোবর ২০২৬: "receipt app e cash, mfs, bank, payment, contra, journal, expense
  // egulo alada alada asena eta tik korobe" ──
  group('each kind on its own (owner, 17 Oct 2026)', () {
    testWidgets('the buttons read সব · নগদ · MFS · ব্যাংক রসিদ · পেমেন্ট · কন্ট্রা · জার্নাল · খরচ; MFS asks receipt+mfs',
        (tester) async {
      final api = _FakeVouchers();
      await _pump(tester, VoucherListScreen(api: api, newChangeId: _key, branches: () async => const []));

      const labels = ['সব', 'নগদ রসিদ', 'MFS রসিদ', 'ব্যাংক রসিদ', 'পেমেন্ট', 'কন্ট্রা', 'জার্নাল', 'খরচ'];
      for (final label in labels) {
        expect(find.widgetWithText(ChoiceChip, label), findsOneWidget, reason: 'বোতাম "$label" নেই');
      }
      final xs = [for (final l in labels) tester.getTopLeft(find.widgetWithText(ChoiceChip, l))];
      for (var i = 1; i < xs.length; i++) {
        final before = xs[i - 1], here = xs[i];
        expect(here.dy > before.dy || (here.dy == before.dy && here.dx > before.dx), isTrue,
            reason: '"${labels[i]}" দাঁড়ায়নি "${labels[i - 1]}"-এর পরে');
      }
      final kindChips = find.byWidgetPredicate(
          (w) => w is ChoiceChip && w.key is ValueKey<String> && (w.key! as ValueKey<String>).value.startsWith('voucher-view-'));
      expect(kindChips, findsNWidgets(labels.length), reason: 'একই ধরন দুবার');

      await tester.tap(find.byKey(const ValueKey('voucher-view-mfs-receipt')));
      await tester.pumpAndSettle();
      expect([api.asked.last['type'], api.asked.last['money']], ['receipt', 'mfs'], reason: '⛔ MFS রসিদ সার্ভারকে MFS বলেনি');

      for (final (key, type) in [('contra', 'contra'), ('journal', 'journal'), ('expense', 'expense')]) {
        await tester.tap(find.byKey(ValueKey('voucher-view-$key')));
        await tester.pumpAndSettle();
        expect([api.asked.last['type'], api.asked.last['money']], [type, null]);
      }
    });

    for (final (view, title, type, money) in [
      ('cash-receipt', 'নগদ রসিদ', 'receipt', 'cash'),
      ('mfs-receipt', 'MFS রসিদ', 'receipt', 'mfs'),
      ('bank-receipt', 'ব্যাংক রসিদ', 'receipt', 'bank'),
      ('payment', 'পেমেন্ট', 'payment', null),
      ('contra', 'কন্ট্রা', 'contra', null),
      ('journal', 'জার্নাল', 'journal', null),
      ('expense', 'খরচ', 'expense', null),
    ]) {
      testWidgets('opened as "$title": asks $type/$money first, says so on top, and "নতুন ভাউচার" goes straight to its form',
          (tester) async {
        final api = _FakeVouchers();
        await _pump(
            tester,
            VoucherListScreen(
                api: api,
                newChangeId: _key,
                branches: () async => const [],
                view: view,
                canCreate: true,
                mayCollect: true));

        expect(api.asked, hasLength(1), reason: 'আগে "সব" চেয়ে তারপর ধরন — দুবার ডাকা হয়েছে');
        expect([api.asked.single['type'], api.asked.single['money']], [type, money]);
        expect(find.descendant(of: find.byType(AppBar), matching: find.text(title)), findsOneWidget);
        final chip = tester.widget<ChoiceChip>(find.byKey(ValueKey('voucher-view-$view')));
        expect(chip.selected, isTrue);

        await tester.tap(find.byKey(const ValueKey('voucher-new')));
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('voucher-type-receipt')), findsNothing, reason: 'ধরন বাছাই আর দেখানোর কথা নয়');
        if (type == 'receipt') {
          // ⭐ মালিক, ২০ নভেম্বর ২০২৬ (টাকা নেওয়ার এক কাগজ): রসিদের ধরন থেকে "নতুন" খোলে রসিদ ভাউচারের ফর্ম (RCV), আদায়ের পাতা নয়
          expect(tester.widget<VoucherFormScreen>(find.byType(VoucherFormScreen)).type, 'receipt',
              reason: '⛔ রসিদ থেকে নতুন — রসিদের ফর্ম নয়');
        } else if (type == 'payment' || type == 'expense') {
          // ⭐ পরিশোধ আর খরচ — ওয়েবের টাকা দেওয়ার পর্দার গড়নে (মালিক, ২১ ও ২৪ অক্টোবর ২০২৬)
          expect(find.byType(VoucherFormScreen), findsNothing, reason: '⛔ পরিশোধ/খরচ এখনো পুরনো ফর্মে');
          expect(tester.widget<MoneyVoucherScreen>(find.byType(MoneyVoucherScreen)).type, type);
        } else {
          expect(find.byType(VoucherFormScreen), findsOneWidget);
          expect(tester.widget<VoucherFormScreen>(find.byType(VoucherFormScreen)).type, type);
        }
      });
    }

    // ⭐ মালিক, ২০ নভেম্বর ২০২৬: গ্রাহকের টাকা হিসাবের রসিদ ভাউচারে (RCV) — ফোনে আদায় (COL) লেখা নেই
    testWidgets('one money-in paper: the customer receipt opens the receipt voucher form; "অন্য আয়" stays a separate entry',
        (tester) async {
      await _pump(
          tester,
          VoucherListScreen(
              api: _FakeVouchers(),
              newChangeId: _key,
              branches: () async => const [],
              canCreate: true,
              mayCollect: true));
      await tester.tap(find.byKey(const ValueKey('voucher-new')));
      await tester.pumpAndSettle();
      expect(find.text('অন্য আয়'), findsOneWidget, reason: 'গ্রাহক ছাড়া আয়ের আলাদা ঘর নেই');
      expect(find.text('গ্রাহকের টাকা — রসিদ ভাউচারে (RCV)'), findsOneWidget, reason: 'রসিদের নিচে কোথায় টাকা নেওয়া হয় বলা নেই');
      await tester.tap(find.byKey(const ValueKey('voucher-type-receipt')));
      await tester.pumpAndSettle();
      expect(tester.widget<VoucherFormScreen>(find.byType(VoucherFormScreen)).type, 'receipt',
          reason: '⛔ গ্রাহকের রসিদ রসিদ ভাউচারের ফর্ম খোলেনি');

      await tester.pageBack();
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('voucher-new')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('voucher-type-other-income')));
      await tester.pumpAndSettle();
      expect(tester.widget<VoucherFormScreen>(find.byType(VoucherFormScreen)).type, 'receipt');
    });

    // ⭐ এক উৎস (মালিকের নিয়ম, ৭ অক্টোবর ২০২৬): "আদায় ভাউচার" `/me`-র mayCollect মানে — হোমের "নতুন আদায়" আর আদায়ের তালিকার বোতামের মতো
    testWidgets('18 · a walled SR with voucher-create and the collection keys sees no "আদায় ভাউচার" (mayCollect false from /me)',
        (tester) async {
      await _pump(
          tester,
          VoucherListScreen(
              api: _FakeVouchers(),
              newChangeId: _key,
              branches: () async => const [],
              canCreate: true,
              mayCollect: false));
      await tester.tap(find.byKey(const ValueKey('voucher-new')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('voucher-type-receipt')), findsNothing, reason: '⛔ দেয়ালের SR "আদায় ভাউচার" দেখল');
      expect(find.byKey(const ValueKey('voucher-type-payment')), findsOneWidget, reason: 'বাকি ভাউচার যেমন ছিল');
      await tester.tapAt(const Offset(10, 10));
      await tester.pumpAndSettle();

      // ⓘ রসিদের ধরন থেকে "নতুন" — আদায়ের পাতা নয়, গ্রাহক ছাড়া আয়ের ফর্ম
      await _pump(
          tester,
          VoucherListScreen(
              api: _FakeVouchers(),
              newChangeId: _key,
              branches: () async => const [],
              view: 'cash-receipt',
              canCreate: true,
              mayCollect: false));
      await tester.tap(find.byKey(const ValueKey('voucher-new')));
      await tester.pumpAndSettle();
      expect(tester.widget<VoucherFormScreen>(find.byType(VoucherFormScreen)).type, 'receipt');
    });

    testWidgets('opened plain (old favourites, recents) or with an unknown kind: "ভাউচার", all, and the type picker',
        (tester) async {
      for (final view in [null, 'no-such-kind']) {
        final api = _FakeVouchers();
        await _pump(tester,
            VoucherListScreen(api: api, newChangeId: _key, branches: () async => const [], view: view, canCreate: true, mayCollect: true));

        expect([api.asked.single['type'], api.asked.single['money']], [null, null]);
        expect(find.descendant(of: find.byType(AppBar), matching: find.text('ভাউচার')), findsOneWidget);

        await tester.tap(find.byKey(const ValueKey('voucher-new')));
        await tester.pumpAndSettle();
        expect(find.byKey(const ValueKey('voucher-type-receipt')), findsOneWidget);
        expect(find.byKey(const ValueKey('voucher-type-expense')), findsOneWidget);
        expect(find.byType(VoucherFormScreen), findsNothing);
      }
    });

    test('the menu has one tile per kind beside "সব ভাউচার", for the same key, behind the same accounts switch', () async {
      const repository = MenuRepository();
      const clerk = AuthUser(id: '1', name: 'C', email: 'c@abos.test', roles: ['accountant'], permissions: ['accounts.voucher.create']);
      const sr = AuthUser(id: '2', name: 'S', email: 's@abos.test', roles: ['salesman'], permissions: ['sales.order.create']);

      final tiles = {for (final i in await repository.menuFor(clerk)) i.routeName: i.label};
      expect(tiles, containsPair('vouchers', 'সব ভাউচার'));
      const kinds = {
        'vouchers/cash-receipt': 'নগদ রসিদ',
        'vouchers/mfs-receipt': 'MFS রসিদ',
        'vouchers/bank-receipt': 'ব্যাংক রসিদ',
        'vouchers/payment': 'পেমেন্ট',
        'vouchers/contra': 'কন্ট্রা',
        'vouchers/journal': 'জার্নাল',
        'vouchers/expense': 'খরচ',
      };
      kinds.forEach((path, label) {
        expect(tiles, containsPair(path, label));
        // ⓘ ঘরের চাবি পর্দার বোতামের চাবিই — অচেনা হলে পর্দা "সব"-এ খুলত
        expect(VoucherView.byKey(path.split('/').last)?.label, label);
        // ⓘ মডিউলের মেনুতে হিসাবের নিচে, লেনদেনে
        expect(NavCatalog.screenDef(path)?.module, 'accounts');
        expect(NavCatalog.screenDef(path)?.group, 'লেনদেন');
        expect(ModuleGate.allows({'sales'}, path), isFalse, reason: '$path হিসাব-সুইচ বন্ধেও খোলে');
        expect(ModuleGate.allows({'accounts'}, path), isTrue);
      });

      final srPaths = (await repository.menuFor(sr)).map((i) => i.routeName);
      expect(srPaths.where((p) => p.startsWith('vouchers')), isEmpty);

      // ⓘ পথটা রাউটারে আছে, হিসাব-সুইচের পিছনে
      final router = File('lib/core/router/app_router.dart').readAsStringSync();
      expect(RegExp(r"path: 'vouchers/:view',\s*builder: \(context, state\) => ModuleGateView\(\s*path: 'vouchers'").hasMatch(router),
          isTrue);
    });
  });

  // ── ⭐ টাকা কোন খাতে — মালিক, ১৭ অক্টোবর ২০২৬: "Mobile app Voucher e kon account e recived hoto ta dekhayna, recived into,
  // pay From" (সার্ভার: ThePhoneWritesVouchersLikeTheWebTest::test_the_list_and_the_page_say_which_account_the_money_went_into_or_came_from) ──
  group('which account the money went into or came from (owner, 17 Oct 2026)', () {
    Map<String, dynamic> json(String id, String no, String type, Map<String, dynamic> money) =>
        {'id': id, 'no': no, 'type': type, 'state': 'posted', 'state_label': 'পাকা', 'amount': '500.00', ...money};

    final receipt = json('r', 'RV-0009', 'receipt',
        {'money_role': 'into', 'money_account': '1102-01 — DBBL চলতি', 'money_label': 'কোথায় জমা হলো'});
    final payment = json('p', 'PV-0009', 'payment',
        {'money_role': 'out_of', 'money_account': '1101-01 — প্রধান নগদ', 'money_label': 'কোথা থেকে গেল'});
    final expense = json('e', 'EV-0009', 'expense', {'money_role': 'out_of', 'money_account': '1101-01 — প্রধান নগদ'});
    final contra = json('c', 'CV-0009', 'contra', {
      'money_role': 'contra', 'money_account': null, 'money_label': null,
      'from_account': '1101-01 — প্রধান নগদ', 'from_label': 'কোথা থেকে',
      'to_account': '1102-01 — DBBL চলতি', 'to_label': 'কোথায় গেল',
    });
    final journal = json('j', 'JV-0009', 'journal', {'money_role': null, 'money_account': null, 'from_account': null});
    // ⓘ পুরনো সার্ভার — তালিকায় টাকার কোনো চাবিই নেই
    final old = json('o', 'RV-0008', 'receipt', {});

    test('the list line: জমা for a receipt, থেকে for a payment or expense, from → to for a contra, nothing otherwise', () {
      String? line(Map<String, dynamic> j) => VoucherRow.fromJson(j).moneyLine;
      expect(line(receipt), 'জমা: 1102-01 — DBBL চলতি');
      expect(line(payment), 'থেকে: 1101-01 — প্রধান নগদ');
      expect(line(expense), 'থেকে: 1101-01 — প্রধান নগদ');
      expect(line(contra), '1101-01 — প্রধান নগদ → 1102-01 — DBBL চলতি');
      expect(line(journal), isNull);
      expect(line(old), isNull, reason: '⛔ পুরনো সার্ভার কিছু পাঠায়নি, তবু ফোন খাত বানাল');
      // ⓘ কনট্রার এক দিক কেবল — যা এসেছে তাই, আন্দাজ নয়
      expect(line({...contra, 'to_account': null}), 'থেকে: 1101-01 — প্রধান নগদ');
      expect(line({...contra, 'from_account': ' '}), 'জমা: 1102-01 — DBBL চলতি');
      // ⓘ `money_role` না এলেও ধরন দেখে
      expect(line({...receipt}..remove('money_role')), 'জমা: 1102-01 — DBBL চলতি');
    });

    testWidgets("each card shows its line; the journal and the old server's card show none", (tester) async {
      final api = _FakeVouchers(rows: [for (final j in [receipt, payment, contra, journal, old]) VoucherRow.fromJson(j)]);
      await _pump(tester, VoucherListScreen(api: api, newChangeId: _key, branches: () async => const []));

      expect(find.text('জমা: 1102-01 — DBBL চলতি'), findsOneWidget);
      expect(find.text('থেকে: 1101-01 — প্রধান নগদ'), findsOneWidget);
      expect(find.text('1101-01 — প্রধান নগদ → 1102-01 — DBBL চলতি'), findsOneWidget);
      for (final id in ['r', 'p', 'c']) {
        expect(find.descendant(of: find.byKey(ValueKey('voucher-$id')), matching: find.byKey(ValueKey('voucher-money-$id'))),
            findsOneWidget, reason: 'কার্ড $id-তে টাকার খাত নেই');
      }
      expect(find.byKey(const ValueKey('voucher-money-j')), findsNothing);
      expect(find.byKey(const ValueKey('voucher-money-o')), findsNothing);
    });

    Future<void> open(WidgetTester tester, Map<String, dynamic> j) =>
        _pump(tester, VoucherScreen(id: j['id'] as String, api: _FakeVouchers(page: VoucherPage.fromJson({...j, 'lines': const []}))));

    testWidgets("the page: the server's words first — a receipt's bank, a payment's cash", (tester) async {
      await open(tester, receipt);
      expect(find.text('কোথায় জমা হলো'), findsOneWidget);
      expect(find.text('1102-01 — DBBL চলতি'), findsOneWidget);

      await open(tester, payment);
      expect(find.text('কোথা থেকে গেল'), findsOneWidget);
      expect(find.text('1101-01 — প্রধান নগদ'), findsOneWidget);
    });

    testWidgets("the page without the server's word: জমা হয়েছে for a receipt, যেখান থেকে দেওয়া for a payment or expense",
        (tester) async {
      await open(tester, {...receipt, 'money_label': null});
      expect(find.text('জমা হয়েছে'), findsOneWidget);
      expect(find.text('1102-01 — DBBL চলতি'), findsOneWidget);

      await open(tester, {...payment, 'money_label': ''});
      expect(find.text('যেখান থেকে দেওয়া'), findsOneWidget);

      await open(tester, expense);
      expect(find.text('যেখান থেকে দেওয়া'), findsOneWidget);
      expect(find.text('1101-01 — প্রধান নগদ'), findsOneWidget);
    });

    testWidgets("a contra's page shows both sides, in order; without labels its own words", (tester) async {
      await open(tester, contra);
      expect(find.text('কোথা থেকে'), findsOneWidget);
      expect(find.text('কোথায় গেল'), findsOneWidget);
      final from = tester.getTopLeft(find.text('1101-01 — প্রধান নগদ'));
      final to = tester.getTopLeft(find.text('1102-01 — DBBL চলতি'));
      expect(from.dy < to.dy, isTrue, reason: '"কোথা থেকে" আগে, "কোথায় গেল" পরে');

      await open(tester, {...contra, 'from_label': null, 'to_label': null});
      expect(find.text('কোথা থেকে'), findsOneWidget);
      expect(find.text('কোথায় গেল'), findsOneWidget);
    });

    testWidgets("a journal's page and an old server's contra show no money row", (tester) async {
      await open(tester, journal);
      expect(find.text('টাকার খাত'), findsNothing);
      expect(find.text('কোথা থেকে'), findsNothing);

      await open(tester, json('c2', 'CV-0001', 'contra', {}));
      expect(find.text('কোথা থেকে'), findsNothing);
      expect(find.text('কোথায় গেল'), findsNothing);
    });
  });
}

class _Server implements HttpClientAdapter {
  final List<String> paths = [];
  final List<Map<String, dynamic>> changes = [];
  bool offline = false;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    if (offline) throw DioException(requestOptions: options, type: DioExceptionType.connectionError);
    paths.add(options.path);
    final rows = [
      for (final r in (options.data is String ? jsonDecode(options.data as String) : options.data) as List)
        Map<String, dynamic>.from(r as Map)
    ];
    changes.addAll(rows);
    return ResponseBody.fromString(
      jsonEncode({
        'outcomes': [for (final r in rows) {'changeId': r['changeId'], 'status': 'APPLIED', 'entityId': 'v-9'}]
      }),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

/// আদায়ের পাতার জায়গায় — পথটা ঠিক কি না দেখতে, নেট ছাড়া
