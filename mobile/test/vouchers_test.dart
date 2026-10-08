import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
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
  _FakeVouchers({this.lands, this.refuses, this.offlineTimes = 0, this.page});

  final String? lands;
  final String? refuses;
  int offlineTimes;
  final VoucherPage? page;
  VoucherDraft? sent;
  final List<String> keys = [];
  final List<bool> awaitingAsked = [];
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
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1}) async {
    awaitingAsked.add(awaiting);
    return const VoucherListPage(rows: [
      VoucherRow(id: 'v1', no: 'RV-0001', type: 'receipt', state: 'awaiting', amount: 500, stateLabel: 'সইয়ের অপেক্ষায়'),
      VoucherRow(id: 'v2', no: 'JV-0002', type: 'journal', state: 'posted', amount: 100, stateLabel: 'পাকা'),
    ], count: 2);
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
      expect(find.text('ডেবিট ৳500'), findsOneWidget);
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

  testWidgets('the list shows each state, and "সইয়ের অপেক্ষায়" asks the server for those only', (tester) async {
    final api = _FakeVouchers();
    await _pump(tester, VoucherListScreen(api: api, newChangeId: _key));
    expect(find.text('RV-0001 · আদায় ভাউচার'), findsOneWidget);
    expect(find.text('সইয়ের অপেক্ষায়'), findsWidgets);
    expect(find.text('পাকা'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('voucher-awaiting')));
    await tester.pumpAndSettle();
    expect(api.awaitingAsked.last, isTrue);
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
