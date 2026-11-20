import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/orders/quotation_api.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/quotations/quotation_compare_screens.dart';
import 'package:abos_mobile/features/stock_writes/quality_serial_screens.dart';
import 'package:abos_mobile/features/stock_writes/stock_write_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬ (fe): উদ্ধৃতির তুলনা ও সংস্করণ, মান যাচাই আর সিরিয়াল ফোনের নিজের পর্দায়; উদ্ধৃতি, আদেশ আর
/// ডেলিভারির ওয়েব-সারি ফোনের আগের পর্দায়।
class _FakeCompare implements QuotationCompareApi {
  _FakeCompare({this.empty = false});

  final bool empty;
  List<String>? askedIds;
  String? askedFamily;
  int revisionsAsked = 0;

  @override
  Future<Comparison> compare({List<String> ids = const [], String? family}) async {
    askedIds = ids;
    askedFamily = family;
    return Comparison.fromJson({
      'quotations': [
        {'id': 'q-1', 'no': 'QT-0001', 'customer': 'রহিম স্টোর', 'status_label': 'পাঠানো', 'total': '300.00'},
        {'id': 'q-2', 'no': 'QT-0001', 'revision_no': 1, 'customer': 'রহিম স্টোর', 'status_label': 'খসড়া', 'total': '250.00'},
      ],
      'comparison': {
        'head': [
          {'label': 'মোট', 'values': ['300.00', '250.00'], 'changed': [false, true]},
        ],
        'lines': [
          {
            'label': 'P-1 - কসমস বিস্কুট',
            'cells': [
              {'fields': {'qty': '3', 'rate': '100.00', 'discount': '0.00', 'tax': '0.00', 'amount': '300.00'}, 'unit': 'পিস', 'changed': {}, 'added': false},
              null,
            ],
            'missing': [false, true],
          },
        ],
      },
    });
  }

  @override
  Future<(List<RevisionFamily>, int?)> revisions({String q = '', int page = 1}) async {
    revisionsAsked++;
    if (empty) return (const <RevisionFamily>[], null);
    return (
      [
        RevisionFamily(
          CompareHead.fromJson({'id': 'q-1', 'no': 'QT-0001', 'customer': 'রহিম স্টোর'}),
          CompareHead.fromJson({'id': 'q-2', 'no': 'QT-0001/R1', 'revision_no': 1, 'status_label': 'খসড়া', 'total': '250.00'}),
          1,
        ),
      ],
      null,
    );
  }
}

class _FakeQuotations implements QuotationApi {
  @override
  Future<(List<Quotation>, int?, PaperTotal?)> list({String? status, int page = 1}) async => (
        [
          Quotation.fromJson({'id': 'q-1', 'no': 'QT-0001', 'status': 'sent', 'status_label': 'পাঠানো', 'total': 300, 'lines': []}),
          Quotation.fromJson({'id': 'q-2', 'no': 'QT-0002', 'status': 'draft', 'status_label': 'খসড়া', 'total': 250, 'lines': []}),
        ],
        null,
        null,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _FakeQs implements QualitySerialApi {
  _FakeQs({this.empty = false});

  final bool empty;
  final List<String> sent = [];
  final List<Map<String, Object?>> written = [];
  Map<String, Object?>? lastBody;
  int asked = 0;

  @override
  Future<(List<InspectionRow>, List<StockChoice>, int?, int?)> inspections({String? status, int page = 1}) async {
    asked++;
    return (
      empty ? const <InspectionRow>[] : [InspectionRow.fromJson({'id': 'qc-1', 'no': 'QC-0003', 'product': 'P-1 - কসমস বিস্কুট', 'qty': '10 পিস', 'status': 'pending', 'status_label': 'রায় বাকি'})],
      const [StockChoice('pending', 'রায় বাকি'), StockChoice('approved', 'গৃহীত')],
      null,
      null,
    );
  }

  @override
  Future<Inspection> inspection(String id) async => Inspection.fromJson({
        'id': id,
        'no': 'QC-0003',
        'product': 'P-1 - কসমস বিস্কুট',
        'qty': '10 পিস',
        'status': 'pending',
        'status_label': 'রায় বাকি',
        'can_decide': true,
        'can_dispose': false,
        'results': [
          {'code': 'approved', 'label': 'গৃহীত'},
          {'code': 'rejected', 'label': 'বাতিল'},
        ],
        'write_off_reasons': [],
      });

  Future<String> _ok(String what, Map<String, Object?> body) async {
    sent.add(what);
    written.add(body);
    lastBody = body;
    return '$what বসেছে।';
  }

  @override
  Future<String> openInspection(Map<String, Object?> body) => _ok('open', body);

  @override
  Future<String> decide(String id, Map<String, Object?> body) => _ok('decide', {'id': id, ...body});

  @override
  Future<String> dispose(String id, Map<String, Object?> body) => _ok('dispose', body);

  @override
  Future<(List<SerialRow>, int?, int?)> serials({String serial = '', int page = 1}) async {
    asked++;
    lastBody = {'serial': serial};
    return (
      empty ? const <SerialRow>[] : const [SerialRow(serialNo: 'SN-1', product: 'P-9 - ফ্যান', status: 'sold', statusLabel: 'গ্রাহকের কাছে', soldTo: 'রহিম')],
      null,
      null,
    );
  }

  @override
  Future<String> receiveSerials(Map<String, Object?> body) => _ok('receive', body);

  @override
  Future<String> issueSerials(Map<String, Object?> body) => _ok('issue', body);
}

const _fan = ProductRecord({'id': 'pr-9', 'code': 'P-9', 'nameBn': 'ফ্যান', 'isActive': true});

void main() {
  testWidgets('two picked quotations compare side by side, a missing line reads as a dash', (tester) async {
    final api = _FakeCompare();
    await tester.pumpWidget(MaterialApp(home: QuotationCompareScreen(api: api, quotations: _FakeQuotations())));
    await tester.pumpAndSettle();

    expect(tester.widget<FilledButton>(find.byKey(const ValueKey('compare-go'))).onPressed, isNull, reason: 'একটা দিয়েই তুলনা চাপা গেল');
    await tester.tap(find.byKey(const ValueKey('compare-pick-q-1')));
    await tester.tap(find.byKey(const ValueKey('compare-pick-q-2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('compare-go')));
    await tester.pumpAndSettle();

    expect(api.askedIds, ['q-1', 'q-2']);
    expect(find.byKey(const ValueKey('compare-table')), findsOneWidget);
    expect(find.text('3 পিস × 100.00 = 300.00'), findsOneWidget);
    expect(find.text('—'), findsOneWidget);
  });

  testWidgets('a revision family opens its own comparison, and an empty list pulls again', (tester) async {
    final api = _FakeCompare();
    await tester.pumpWidget(MaterialApp(home: QuotationRevisionsScreen(api: api)));
    await tester.pumpAndSettle();
    expect(find.text('QT-0001 → QT-0001/R1'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('revision-q-1')));
    await tester.pumpAndSettle();
    expect(api.askedFamily, 'q-1');

    final empty = _FakeCompare(empty: true);
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(MaterialApp(home: QuotationRevisionsScreen(api: empty)));
    await tester.pumpAndSettle();
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
    final before = empty.revisionsAsked;
    await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(empty.revisionsAsked, greaterThan(before));
  });

  testWidgets('an inspection shows the verdict form only when the server says so and sends it once', (tester) async {
    final api = _FakeQs();
    await tester.pumpWidget(MaterialApp(home: InspectionScreen(id: 'qc-1', api: api)));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('qc-decide-card')), findsOneWidget);
    expect(find.byKey(const ValueKey('qc-dispose-card')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('qc-result')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('গৃহীত').last);
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const ValueKey('qc-decide')));
    await tester.tap(find.byKey(const ValueKey('qc-decide')));
    await tester.pumpAndSettle();

    expect(api.sent, ['decide']);
    expect(api.lastBody, {'id': 'qc-1', 'result': 'approved', 'accepted_qty': '10', 'rejected_qty': '0'});
  });

  testWidgets('the quality list offers a new inspection only with the create key, and its empty state pulls', (tester) async {
    await tester.pumpWidget(MaterialApp(home: QualityListScreen(api: _FakeQs())));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('qc-new')), findsNothing);
    expect(find.text('QC-0003'), findsOneWidget);

    final empty = _FakeQs(empty: true);
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(MaterialApp(home: QualityListScreen(api: empty, canCreate: true)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('qc-new')), findsOneWidget);
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
    final before = empty.asked;
    await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(empty.asked, greaterThan(before));
  });

  testWidgets('serials: search, and the receive form sends the product and one number per line', (tester) async {
    final api = _FakeQs();
    await tester.pumpWidget(MaterialApp(home: SerialScreen(api: api, canManage: true, products: const [_fan])));
    await tester.pumpAndSettle();
    expect(find.text('SN-1'), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('serial-search')), 'SN-1');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.lastBody, {'serial': 'SN-1'});

    await tester.tap(find.byKey(const ValueKey('serial-receive')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('serial-product')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('stock-product-pr-9')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('serial-numbers')), 'SN-7\nSN-8');
    await tester.tap(find.byKey(const ValueKey('serial-send')));
    await tester.pumpAndSettle();
    expect(api.sent, ['receive']);
    expect(api.written.last['product'], 'pr-9');
    expect(api.written.last['serials'], 'SN-7\nSN-8');

    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(MaterialApp(home: SerialScreen(api: _FakeQs())));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('serial-receive')), findsNothing, reason: 'চাবি ছাড়া পিস ঢোকানো');
  });

  test('the ten web paths open the phone screens behind their own module switch', () {
    const gates = {
      '/sales/quotations': 'quotations',
      '/sales/quotations/create': 'quotations',
      '/sales/quotations/compare': 'quotations',
      '/sales/quotations/revisions': 'quotations',
      '/sales/orders': 'orders',
      '/sales/orders/create': 'new-order',
      '/sales/deliveries': 'deliveries',
      '/inventory/quality': 'stock',
      '/inventory/serials': 'stock',
      // ⛔ ওয়েবের পুরনো "নতুন আদায়" → টাকা জমার খবর (মালিক, ২০ নভেম্বর ২০২৬: ফোনে আদায় লেখা নেই)
      '/sales/collections/create': 'deposit-report',
    };
    expect(NativeForWeb.desks, gates.keys.toSet());
    for (final entry in gates.entries) {
      final screen = NativeForWeb.screenFor(entry.key);
      expect(screen, isA<ModuleGateView>(), reason: entry.key);
      expect((screen! as ModuleGateView).path, entry.value, reason: entry.key);
      expect(NativeForWeb.iconFor(entry.key), isNotNull, reason: entry.key);
    }
  });
}
