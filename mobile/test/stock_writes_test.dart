import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/stock_writes/stock_write_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬ (fe): মজুদের পাঁচ লেখা অ্যাপের ফর্মে — মাল বসানো, গণনা-সমন্বয়, ইস্যু, খোলা মজুদ, লট।
/// নিয়ম সার্ভারের; ফোন কেবল ঘর ভরে পাঠায় আর উত্তর বলে।
class _NothingWaiting extends _FakeStock {
  int asked = 0;

  @override
  Future<List<WaitingPaper>> placement() async {
    asked++;
    return const [];
  }

  @override
  Future<List<WaitingCount>> counts() async {
    asked++;
    return const [];
  }
}

class _FakeStock implements StockWriteApi {
  _FakeStock({this.can = const {'place': true, 'adjust': true, 'approve_count': true, 'opening': true, 'lot': true}});

  final Map<String, bool> can;
  final List<String> sent = [];
  Map<String, Object?>? lastBody;
  List<Map<String, Object?>>? lastLines;

  StockResult _ok(String what, Map<String, Object?> body, {String status = 'done'}) {
    sent.add(what);
    lastBody = body;
    return StockResult(status, status == 'done' ? '$what বসেছে।' : 'সইয়ের অপেক্ষায়।', no: 'SC-0001');
  }

  @override
  Future<StockSetup> setup() async => StockSetup(
        warehouses: const [
          StockWarehouse('wh-1', 'ময়মনসিংহ গুদাম', [StockChoice('pl-1', 'তাক ক')]),
        ],
        adjustReasons: const [StockChoice('rs-a', 'গোনায় ঘাটতি')],
        issueReasons: const [StockChoice('rs-i', 'আপ্যায়ন')],
        can: can,
      );

  @override
  Future<List<WaitingPaper>> placement() async => const [
        WaitingPaper('PBL-0042', [
          WaitingLine(ref: 'ref-1', product: 'P-1 - কসমস বিস্কুট', warehouse: 'ময়মনসিংহ গুদাম', warehouseId: 'wh-1', waiting: '10', waitingFree: '2'),
        ]),
      ];

  @override
  Future<StockResult> place(List<Map<String, Object?>> lines) async {
    lastLines = lines;
    return _ok('place', {'lines': lines});
  }

  @override
  Future<StockResult> adjust(Map<String, Object?> body) async => _ok('adjust', body, status: 'waiting');

  @override
  Future<List<WaitingCount>> counts() async => const [
        WaitingCount(id: 'sc-1', no: 'SC-0007', warehouse: 'ময়মনসিংহ গুদাম', lines: ['কসমস বিস্কুট: খাতায় 20 → গোনা 17 (পার্থক্য -3)']),
      ];

  @override
  Future<StockResult> approveCount(String id, String reason) async => _ok('approve', {'id': id, 'reason': reason});

  @override
  Future<StockResult> issue(Map<String, Object?> body) async => _ok('issue', body);

  @override
  Future<StockResult> opening(Map<String, Object?> body) async => _ok('opening', body);

  @override
  Future<StockResult> lot(Map<String, Object?> body) async => _ok('lot', body);
}

const _biscuit = ProductRecord({'id': 'pr-1', 'code': 'P-1', 'nameBn': 'কসমস বিস্কুট', 'isActive': true});

Future<void> _pickBiscuit(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('stock-product')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('stock-product-pr-1')));
  await tester.pumpAndSettle();
}

Future<void> _choose(WidgetTester tester, String key, String text) async {
  await tester.tap(find.byKey(ValueKey(key)));
  await tester.pumpAndSettle();
  await tester.tap(find.text(text).last);
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('the stock work screen shows only what this person may write', (tester) async {
    await tester.pumpWidget(MaterialApp(home: StockWorkScreen(api: _FakeStock(can: const {'adjust': true}))));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('stock-work-adjust')), findsOneWidget);
    expect(find.byKey(const ValueKey('stock-work-issue')), findsOneWidget, reason: 'ইস্যুর চাবি সমন্বয়েরই');
    expect(find.byKey(const ValueKey('stock-work-place')), findsNothing);
    expect(find.byKey(const ValueKey('stock-work-opening')), findsNothing);
    expect(find.byKey(const ValueKey('stock-work-lot')), findsNothing);
    expect(find.byKey(const ValueKey('stock-work-counts')), findsNothing);
  });

  testWidgets('an issue sends the product, warehouse, reason and quantity by their public ids', (tester) async {
    final api = _FakeStock();
    final setup = await api.setup();
    await tester.pumpWidget(MaterialApp(
        home: StockFormScreen(form: StockForm.issue, api: api, setup: setup, products: const [_biscuit])));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('stock-send')));
    await tester.pumpAndSettle();
    expect(find.textContaining('লাগবেই'), findsOneWidget, reason: 'খালি ফর্ম পাঠানোই গেল');
    expect(api.sent, isEmpty);

    await _pickBiscuit(tester);
    await _choose(tester, 'stock-reason', 'আপ্যায়ন');
    await tester.enterText(find.byKey(const ValueKey('stock-qty')), '5');
    await tester.tap(find.byKey(const ValueKey('stock-send')));
    await tester.pumpAndSettle();

    expect(api.sent, ['issue']);
    expect(api.lastBody, {'product': 'pr-1', 'warehouse': 'wh-1', 'reason': 'rs-i', 'qty': '5'});
    expect(find.text('issue বসেছে।'), findsOneWidget);
  });

  testWidgets('a count sends what was counted, the free pool, and says when it waits', (tester) async {
    final api = _FakeStock();
    await tester.pumpWidget(MaterialApp(
        home: StockFormScreen(form: StockForm.adjust, api: api, setup: await api.setup(), products: const [_biscuit])));
    await tester.pumpAndSettle();

    await _pickBiscuit(tester);
    await _choose(tester, 'stock-reason', 'গোনায় ঘাটতি');
    await tester.enterText(find.byKey(const ValueKey('stock-qty')), '17');
    await tester.tap(find.byKey(const ValueKey('stock-free-pool')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('stock-cost')), findsNothing, reason: 'ফ্রি মালের দাম নেই');
    await tester.tap(find.byKey(const ValueKey('stock-send')));
    await tester.pumpAndSettle();

    expect(api.lastBody?['counted'], '17');
    expect(api.lastBody?['pool'], 'free');
    expect(api.lastBody?.containsKey('qty'), isFalse);
    expect(find.text('সইয়ের অপেক্ষায়।'), findsOneWidget);
  });

  testWidgets('opening stock and a lot send their own fields', (tester) async {
    final api = _FakeStock();
    final setup = await api.setup();
    await tester.pumpWidget(MaterialApp(
        home: StockFormScreen(form: StockForm.opening, api: api, setup: setup, products: const [_biscuit])));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('stock-reason')), findsNothing, reason: 'খোলা মজুদে কারণ নেই');
    await _pickBiscuit(tester);
    await tester.enterText(find.byKey(const ValueKey('stock-qty')), '12');
    await tester.enterText(find.byKey(const ValueKey('stock-cost')), '10');
    await tester.tap(find.byKey(const ValueKey('stock-send')));
    await tester.pumpAndSettle();
    expect(api.lastBody, {'product': 'pr-1', 'warehouse': 'wh-1', 'qty': '12', 'unit_cost': '10'});

    await tester.pumpWidget(MaterialApp(
        home: StockFormScreen(form: StockForm.lot, api: api, setup: setup, products: const [_biscuit])));
    await tester.pumpAndSettle();
    await _pickBiscuit(tester);
    await tester.enterText(find.byKey(const ValueKey('stock-qty')), '5');
    await tester.enterText(find.byKey(const ValueKey('stock-free')), '1');
    await tester.enterText(find.byKey(const ValueKey('stock-lot')), 'L-1');
    await tester.tap(find.byKey(const ValueKey('stock-send')));
    await tester.pumpAndSettle();
    expect(api.lastBody, {'product': 'pr-1', 'warehouse': 'wh-1', 'qty': '5', 'free_qty': '1', 'batch_no': 'L-1'});
  });

  testWidgets('put-away sends each waiting line by its ref, with the place chosen', (tester) async {
    final api = _FakeStock();
    await tester.pumpWidget(MaterialApp(home: PlacementScreen(api: api, setup: await api.setup())));
    await tester.pumpAndSettle();

    expect(find.text('PBL-0042'), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('placement-qty-ref-1')), '6');
    await _choose(tester, 'placement-place-ref-1', 'তাক ক');
    await tester.tap(find.byKey(const ValueKey('placement-send-PBL-0042')));
    await tester.pumpAndSettle();

    expect(api.lastLines, [
      {'ref': 'ref-1', 'qty': '6', 'free_qty': '2', 'place': 'pl-1'},
    ]);
  });

  testWidgets('a waiting count is settled with a reason, never without one', (tester) async {
    final api = _FakeStock();
    await tester.pumpWidget(MaterialApp(home: WaitingCountsScreen(api: api, setup: await api.setup())));
    await tester.pumpAndSettle();

    expect(find.textContaining('পার্থক্য -3'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('count-approve-sc-1')));
    await tester.pumpAndSettle();
    expect(api.sent, isEmpty, reason: 'কারণ ছাড়া মেনে নেওয়া গেল');

    await _choose(tester, 'count-reason-sc-1', 'গোনায় ঘাটতি');
    await tester.tap(find.byKey(const ValueKey('count-approve-sc-1')));
    await tester.pumpAndSettle();
    expect(api.lastBody, {'id': 'sc-1', 'reason': 'rs-a'});
  });

  test('the web paths of the five stock writes open the phone forms behind the stock switch', () {
    for (final entry in NativeForWeb.stockDoors.entries) {
      final screen = NativeForWeb.screenFor(entry.key) as ModuleGateView;
      expect(screen.path, 'stock', reason: entry.key);
      expect((screen.child as StockDoor).open, entry.value, reason: entry.key);
    }
    expect(NativeForWeb.stockDoors.values.toSet(), {'place', 'adjust', 'issue', 'opening', 'lot', 'counts'});
  });

  testWidgets('nothing waiting is an empty state that a pull refreshes', (tester) async {
    for (final screen in [
      (StockWriteApi api, StockSetup setup) => PlacementScreen(api: api, setup: setup),
      (StockWriteApi api, StockSetup setup) => WaitingCountsScreen(api: api, setup: setup),
    ]) {
      final api = _NothingWaiting();
      await tester.pumpWidget(MaterialApp(home: screen(api, await api.setup())));
      await tester.pumpAndSettle();

      expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
      final before = api.asked;
      await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
      await tester.pumpAndSettle();
      expect(api.asked, greaterThan(before), reason: 'টেনে নামালে আবার আনা হয়নি');
    }
  });
}
