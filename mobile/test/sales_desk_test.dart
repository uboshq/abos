import 'package:abos_mobile/core/orders/sales_desk_api.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/sales_desk/coming_soon_screen.dart';
import 'package:abos_mobile/features/sales_desk/sales_desk_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeApi implements SalesDeskApi {
  _FakeApi({this.empty = false});

  final bool empty;
  final asked = <String>[];
  final settled = <String>[];

  @override
  Future<DeskRow> depotCheck({String q = ''}) async {
    asked.add('depot:$q');
    if (empty) return const DeskRow({'orders': [], 'held': [], 'orders_count': 0, 'orders_total': '0.00'});
    return const DeskRow({
      'orders': [
        {'id': 'do1', 'document_no': 'DO-0009', 'customer': 'রহিম স্টোর', 'date': '17-10-2026', 'lines': 3, 'total': '1,500.00',
          'status': 'হিসাবের অনুমোদিত', 'warnings': ['সীমার কাছে']},
      ],
      'orders_count': 1,
      'orders_total': '1,500.00',
      'held': [],
    });
  }

  @override
  Future<DeskRow> printQueue({String q = '', int page = 1}) async {
    if (empty) return const DeskRow({'jobs': [], 'count': 0});
    return DeskRow({
      'jobs': [
        if (!settled.contains('pj1'))
          {'id': 'pj1', 'document_no': 'INV-0042', 'paper': 'a4', 'status_label': 'ব্যর্থ', 'failed': true, 'failure': 'কাগজ ফুরিয়েছে'},
      ],
      'count': settled.contains('pj1') ? 0 : 1,
    });
  }

  @override
  Future<void> settlePrint(String id) async => settled.add(id);

  @override
  Future<DeskRow> deliveryPerformance({required String from, required String to, int page = 1}) async {
    asked.add('perf:$from..$to');
    return const DeskRow({
      'cards': [
        {'label': 'সময়মতো ও পুরো', 'value': '85.0%', 'hint': '১৭টার ২০টা'},
      ],
      'late': [
        {'id': 'c1', 'document_no': 'DC-0011', 'customer': 'করিম স্টোর', 'promised_on': '10-10-2026', 'arrived_on': 'এখনো নয়',
          'days_late': 7, 'order_no': 'SO-0003', 'stage': 'পথে'},
      ],
      'late_count': 1,
    });
  }

  @override
  Future<DeskRow> pipeline() async => const DeskRow({
        'open_weighted': '10,000.00',
        'stages': [
          {'name': 'প্রস্তাব', 'count': 1, 'total': '20,000.00', 'weighted': '10,000.00', 'rows': [
            {'id': 'o1', 'title': 'পাইকারি চুক্তি', 'party': 'রহিম স্টোর', 'value': '20,000.00', 'probability': 50},
          ]},
        ],
      });

  @override
  Future<DeskRow> templates({int page = 1}) async => empty
      ? const DeskRow({'rows': [], 'count': 0})
      : const DeskRow({
          'rows': [
            {'id': 't1', 'code': 'TPL-B', 'title': 'ব্যাংকের চিঠি', 'type': 'চিঠি', 'folder': 'কোম্পানি', 'active': true},
          ],
          'count': 1,
        });

  @override
  Future<DeskRow> template(String id) async =>
      const DeskRow({'code': 'TPL-B', 'title': 'ব্যাংকের চিঠি', 'type': 'চিঠি', 'folder': 'কোম্পানি', 'body': 'Dear {{bank_name}},\nRegards'});
}

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"* — বিক্রয়ের ডেস্ক-পাতা আর দলিলের ছাঁচ ফোনের পর্দায়।
void main() {
  test('the nine web rows fe listed open phone screens, the editor stays on the web', () {
    expect(NativeForWeb.screenFor('/sales/direct').toString(), contains('ModuleGateView'));
    expect(NativeForWeb.screenFor('/sales/direct/drafts').toString(), contains('ModuleGateView'));
    expect(NativeForWeb.screenFor('/sales/direct/depot-check'), isA<DepotCheckScreen>());
    expect(NativeForWeb.screenFor('/sales/print-queue'), isA<PrintQueueScreen>());
    expect(NativeForWeb.screenFor('/sales/delivery-performance'), isA<DeliveryPerformanceScreen>());
    expect(NativeForWeb.screenFor('/sales/opportunities/pipeline'), isA<PipelineScreen>());
    expect(NativeForWeb.screenFor('/documents/templates'), isA<TemplateListScreen>());
    expect(NativeForWeb.screenFor('/sales/planned/pricing_dynamic', title: 'Dynamic Pricing'), isA<ComingSoonScreen>());
    expect(NativeForWeb.screenFor('/documents/templates/new'), isNull, reason: 'ছাঁচ লেখা ডেস্কের কাজ — ওয়েবে');
  });

  testWidgets('the depot check shows the whole total, each order with its warning, and says opening is on the web', (tester) async {
    await tester.pumpWidget(MaterialApp(home: DepotCheckScreen(api: _FakeApi())));
    await tester.pumpAndSettle();

    final total = find.byKey(const ValueKey('books-total'));
    expect(find.descendant(of: total, matching: find.text('৳1,500.00')), findsOneWidget);
    expect(find.descendant(of: total, matching: find.text('· 1 টি')), findsOneWidget);
    expect(find.text('DO-0009 · রহিম স্টোর'), findsOneWidget);
    expect(find.text('⚠ সীমার কাছে'), findsOneWidget);
    expect(find.byKey(const ValueKey('depot-web-note')), findsOneWidget);
  });

  testWidgets('the print queue names the failure and a hand print takes the paper off', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: PrintQueueScreen(api: api)));
    await tester.pumpAndSettle();

    expect(find.text('কারণ: কাগজ ফুরিয়েছে'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('print-settle-pj1')));
    await tester.pumpAndSettle();
    expect(api.settled, ['pj1']);
    expect(find.text('সব কাগজ ছাপা হয়েছে'), findsOneWidget);
  });

  testWidgets('delivery performance asks for the chosen range and shows its cards and late challans', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(home: DeliveryPerformanceScreen(api: api, today: DateTime(2026, 10, 17))));
    await tester.pumpAndSettle();

    expect(api.asked.last, 'perf:2026-10-01..2026-10-17', reason: 'শুরু এই মাস, ওয়েবের মতো');
    expect(find.text('85.0%'), findsOneWidget);
    expect(find.text('দেরির চালান · 1 টি'), findsOneWidget);
    expect(find.text('7 দিন দেরি'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('range-today')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'perf:2026-10-17..2026-10-17');
  });

  testWidgets('the pipeline shows each stage with its totals and deals, and a template opens its text', (tester) async {
    await tester.pumpWidget(MaterialApp(home: PipelineScreen(api: _FakeApi())));
    await tester.pumpAndSettle();
    expect(find.text('প্রস্তাব · 1 টি'), findsOneWidget);
    expect(find.text('মোট ৳20,000.00 · ওজন-করা ৳10,000.00'), findsOneWidget);
    expect(find.text('পাইকারি চুক্তি'), findsOneWidget);

    await tester.pumpWidget(MaterialApp(home: TemplateListScreen(api: _FakeApi())));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('template-t1')));
    await tester.pumpAndSettle();
    expect(find.text('Dear {{bank_name}},\nRegards'), findsOneWidget);
  });

  testWidgets('empty desk screens can be pulled to refresh', (tester) async {
    for (final screen in [
      DepotCheckScreen(api: _FakeApi(empty: true)),
      PrintQueueScreen(api: _FakeApi(empty: true)),
      TemplateListScreen(api: _FakeApi(empty: true)),
    ]) {
      await tester.pumpWidget(MaterialApp(home: screen));
      await tester.pumpAndSettle();
      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
    }
  });
}
