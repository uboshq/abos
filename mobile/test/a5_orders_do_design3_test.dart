import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/orders/order_api.dart';
import 'package:abos_mobile/core/orders/tracking_api.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/customer_line.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';
import 'package:abos_mobile/core/widgets/totals_bar.dart';
import 'package:abos_mobile/features/delivery_orders/delivery_order_screens.dart';
import 'package:abos_mobile/features/orders/delivery_tracking_screen.dart';
import 'package:abos_mobile/features/orders/order_list_screen.dart';
import 'package:abos_mobile/features/orders/order_sheets.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ অর্ডার, ডিও আর ট্র্যাকিংয়ের পর্দা নকশা ৩-এ (a4-এর নিয়ম ০.৪.৩৮; মালিক, ১৯ অক্টোবর ২০২৬: "100% international standard
/// and enterprise grade")।
///
/// <p>দাবি, আলো আর অন্ধকার দুই থিমে, ৩৬০dp ফোনে:
/// - সারি: প্রথম লাইনে গ্রাহক "নাম · পয়েন্ট · শাখা" ([CustomerLine]) — ⛔ খালি নাম নয় (মালিকের স্থায়ী নিয়ম); দ্বিতীয় লাইনে
///   কাগজের নম্বর; সারি অন্তত ৭২dp; নাম onSurface, নম্বর onSurfaceVariant।
/// - [TotalsBar]: "এই পাতা" = হাতে আসা সারির গোনা আর যোগ (ফেরত/বাতিল বাদ), "সর্বমোট" = সার্ভারের পুরো তালিকার গোনা আর টাকা।
/// - তিন অবস্থা: আনার সময় [LoadingSkeleton], খালিতে কাজের বোতামসহ [EmptyState], ভুলে "আবার চেষ্টা করুন" যা নকল API আবার ডাকে।
/// - একটা কাগজের পাতা আর সময়রেখা: মাথায় [CustomerLine], তার নিচে নম্বর।

final _themes = <String, ThemeData Function()>{'আলো': AppTheme.light, 'অন্ধকার': AppTheme.dark};

const _place = 'রহিম স্টোর · ফুলপুর · ময়মনসিংহ';

Map<String, dynamic> _doJson(String id, String no, String status, String total) => {
      'id': id,
      'no': no,
      'date': '2026-10-1${id.length}',
      // ⓘ সার্ভারের আসল আকার — নাম, পয়েন্ট আর শাখা আলাদা; ফোনের পার্সার জোড়ে
      'customer': {'id': 'c-1', 'name': 'রহিম স্টোর', 'point': 'ফুলপুর', 'branch': 'ময়মনসিংহ'},
      'status': status,
      'status_label': status == 'rejected' ? 'ফেরত' : 'সুপারভাইজারের অপেক্ষায়',
      'total': total,
    };

/// নকল ডিওর দরজা — [gate] না খোলা পর্যন্ত উত্তর আটকে রাখে; [failFirst] হলে প্রথম ডাকে ভুল
class _DoApi implements DeliveryOrderApi {
  _DoApi({this.rows = const [], this.total, this.failFirst = false, this.gate});

  final List<DeliveryOrder> rows;
  final PaperTotal? total;
  final bool failFirst;
  final Completer<void>? gate;
  int calls = 0;

  @override
  bool get forOrders => false;

  @override
  Future<(List<DeliveryOrder>, PaperTotal?)> list({bool awaitingMe = false}) async {
    calls++;
    if (gate != null) await gate!.future;
    if (failFirst && calls == 1) {
      throw DioException(requestOptions: RequestOptions(path: '/sales/delivery-orders'), type: DioExceptionType.connectionError);
    }
    return (rows, total);
  }

  @override
  Future<DeliveryOrder> show(String id) async => rows.first;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _TrackApi implements TrackingApi {
  _TrackApi({this.rows = const [], this.failFirst = false, this.gate});

  final List<TrackedSale> rows;
  final bool failFirst;
  final Completer<void>? gate;
  int calls = 0;

  static const sale = TrackedSale(
      kind: 'challan', id: 't1', no: 'S-0007', date: '2026-10-02', customer: _place, total: 125000,
      step: 'gate_out', billed: false);

  @override
  Future<TrackingList> list({String? query, String? step}) async {
    calls++;
    if (gate != null) await gate!.future;
    if (failFirst && calls == 1) {
      throw DioException(requestOptions: RequestOptions(path: '/sales/tracking'), type: DioExceptionType.connectionError);
    }
    return TrackingList(rows, {'all': rows.length});
  }

  @override
  Future<(TrackedSale, List<TrackingEvent>, List<Milestone>)> story(TrackedSale s) async => (sale, const <TrackingEvent>[], const <Milestone>[]);
}

Future<void> _pump(WidgetTester tester, Widget home, ThemeData theme, {bool settle = true}) async {
  tester.view.physicalSize = const Size(360, 1600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const SizedBox());
  await tester.pumpWidget(MaterialApp(theme: theme, home: home));
  if (settle) await tester.pumpAndSettle();
}

/// সারির দাবি — প্রথম লাইনে "নাম · পয়েন্ট · শাখা", দ্বিতীয় লাইনে নম্বর, ৭২dp, থিমের রং
void _expectRow(WidgetTester tester, Finder row, String number, ThemeData theme) {
  final party = find.descendant(of: row, matching: find.byType(CustomerLine));
  expect(party, findsOneWidget);
  expect(tester.widget<CustomerLine>(party).text, _place, reason: '⛔ গ্রাহকের খালি নাম — পয়েন্ট আর শাখা চাই');
  final numberText = find.descendant(of: row, matching: find.text(number));
  expect(numberText, findsOneWidget);
  expect(tester.getTopLeft(numberText).dy, greaterThanOrEqualTo(tester.getBottomLeft(party).dy - 0.5),
      reason: 'নম্বর নামের নিচের লাইনে');
  expect(tester.getSize(row).height, greaterThanOrEqualTo(72));
  final cs = theme.colorScheme;
  final partyText = tester.widget<Text>(find.descendant(of: party, matching: find.byType(Text)));
  expect(partyText.style?.color, cs.onSurface);
  expect(tester.widget<Text>(numberText).style?.color, cs.onSurfaceVariant);
}

void _expectTotals(WidgetTester tester, {required String page, required String pageSum, required String all, required String allSum}) {
  final p = find.byKey(const ValueKey('totals-page'));
  final a = find.byKey(const ValueKey('totals-all'));
  expect(find.descendant(of: p, matching: find.text(page)), findsOneWidget);
  expect(find.descendant(of: p, matching: find.text(pageSum)), findsOneWidget);
  expect(find.descendant(of: a, matching: find.text(all)), findsOneWidget, reason: '⛔ সর্বমোট সার্ভারের নয়');
  expect(find.descendant(of: a, matching: find.text(allSum)), findsOneWidget, reason: '⛔ সর্বমোট সার্ভারের নয়');
}

void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  setUp(() async {
    await ReferenceCache.instance.clearAll();
    ReferenceSync.forgetLastFailure();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  for (final t in _themes.entries) {
    final name = t.key;

    // ── ডিওর তালিকা ──
    testWidgets('ডিওর তালিকা · $name: খসড়া, তারপর নকশা ৩-এর সারি আর সার্ভারের সর্বমোট', (tester) async {
      final theme = t.value();
      final gate = Completer<void>();
      final api = _DoApi(
        gate: gate,
        rows: [
          DeliveryOrder.fromJson(_doJson('a', 'DO-0001', 'supervisor_pending', '1500.00')),
          // ⓘ ফেরত — সারিতে থাকে, এই পাতার যোগে নয়
          DeliveryOrder.fromJson(_doJson('bb', 'DO-0002', 'rejected', '700.00')),
        ],
        total: const PaperTotal(sum: 987654.5, count: 57),
      );
      await _pump(tester, DeliveryOrderListScreen(api: api), theme, settle: false);
      await tester.pump();
      expect(find.byType(LoadingSkeleton), findsOneWidget, reason: 'আনার সময় খসড়া সারি');

      gate.complete();
      await tester.pumpAndSettle();
      expect(find.byType(LoadingSkeleton), findsNothing);
      _expectRow(tester, find.byKey(const ValueKey('do-row-a')), 'DO-0001', theme);
      _expectRow(tester, find.byKey(const ValueKey('do-row-bb')), 'DO-0002', theme);
      _expectTotals(tester, page: 'এই পাতা · 2টি', pageSum: '৳1,500.00', all: 'সর্বমোট · 57টি', allSum: '৳9,87,654.50');
    });

    testWidgets('ডিওর তালিকা · $name: খালিতে "নতুন DO", ভুলে "আবার চেষ্টা করুন" দরজা আবার ডাকে', (tester) async {
      final theme = t.value();
      final empty = _DoApi(total: const PaperTotal(sum: 0, count: 0));
      await _pump(tester, DeliveryOrderListScreen(api: empty), theme);
      expect(find.byType(EmptyState), findsOneWidget);
      final action = find.byKey(const ValueKey('do-empty-new'));
      expect(find.descendant(of: action, matching: find.text('নতুন DO')), findsOneWidget);
      expect(tester.getSize(action).height, greaterThanOrEqualTo(48));
      expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget,
          reason: 'খালি পর্দাও টানলে নতুন');
      expect(find.byType(TotalsBar), findsNothing, reason: 'খালি তালিকায় পট্টি নেই');

      final failing = _DoApi(failFirst: true, rows: [DeliveryOrder.fromJson(_doJson('a', 'DO-0001', 'draft', '10'))]);
      await _pump(tester, DeliveryOrderListScreen(api: failing), theme);
      expect(find.byType(ErrorState), findsOneWidget);
      expect(failing.calls, 1);
      await tester.tap(find.byKey(const ValueKey('error-retry')));
      await tester.pumpAndSettle();
      expect(failing.calls, 2, reason: '"আবার চেষ্টা করুন" দরজা আবার ডাকে');
      expect(find.byType(ErrorState), findsNothing);
      expect(find.byKey(const ValueKey('do-row-a')), findsOneWidget);
      // ⓘ পুরনো সার্ভার মোট পাঠায় না — পাতার যোগকে সর্বমোট বলা নয়, পট্টিই নেই
      expect(find.byType(TotalsBar), findsNothing);
    });

    testWidgets('একটা ডিও · $name: মাথায় "নাম · পয়েন্ট · শাখা", নিচে নম্বর', (tester) async {
      final theme = t.value();
      final api = _DoApi(rows: [DeliveryOrder.fromJson(_doJson('a', 'DO-0001', 'draft', '10'))]);
      await _pump(tester, DeliveryOrderScreen(id: 'a', api: api), theme);
      final head = find.byKey(const ValueKey('do-customer-line'));
      expect(tester.widget<CustomerLine>(head).text, _place);
      final number = find.byKey(const ValueKey('do-number-line'));
      expect(tester.widget<Text>(number).data, startsWith('DO-0001'));
      expect(tester.getTopLeft(number).dy, greaterThanOrEqualTo(tester.getBottomLeft(head).dy - 0.5));
    });

    // ── অর্ডারের অবস্থা (ফোনে সিঙ্ক হওয়া) ──
    testWidgets('অর্ডারের অবস্থা · $name: সারি নকশা ৩-এ, পট্টিতে দেখানো আর পুরো তালিকা', (tester) async {
      final theme = t.value();
      final now = DateTime(2026, 10, 1);
      // ⓘ Hive-এর আসল ফাইল-লেখা — নকল সময়ের ভেতরে চিরকাল ঝুলে থাকে, তাই runAsync-এ
      await tester.runAsync(() async {
      await ReferenceCache.instance.put(entityType: 'Customer', entityId: 'c-1', updatedAt: now, payload: const {
        'id': 'c-1',
        'nameBn': 'রহিম স্টোর',
        'pointName': 'ফুলপুর',
        'branchName': 'ময়মনসিংহ',
      });
      for (final (id, no, status, total) in [
        ('o1', 'SO-0001', 'confirmed', '1200.0000'),
        ('o2', 'SO-0002', 'confirmed', '800.0000'),
        ('o3', 'SO-0003', 'cancelled', '5000.0000'),
      ]) {
        await ReferenceCache.instance.put(entityType: 'SalesOrder', entityId: id, updatedAt: now, payload: {
          'id': id,
          'documentNo': no,
          'customerId': 'c-1',
          'trxDate': '2026-10-0${id.substring(1)}',
          'status': status,
          'total': total,
        });
      }
      });
      await _pump(tester, const OrderListScreen(), theme);
      _expectRow(tester, find.byKey(const ValueKey('order-row-o1')), 'SO-0001', theme);
      _expectRow(tester, find.byKey(const ValueKey('order-row-o3')), 'SO-0003', theme);
      // ⓘ বাতিল সারিতে থাকে, মোটে নয়
      _expectTotals(tester, page: 'এই পাতা · 3টি', pageSum: '৳2,000.00', all: 'সর্বমোট · 3টি', allSum: '৳2,000.00');
    });

    testWidgets('অর্ডারের অবস্থা · $name: খালিতে "নতুন অর্ডার", টান ব্যর্থ হলে কারণ আর "আবার চেষ্টা করুন"', (tester) async {
      final theme = t.value();
      var opened = 0;
      await _pump(tester, OrderListScreen(onNewOrder: () => opened++), theme);
      final action = find.byKey(const ValueKey('orders-empty-new'));
      expect(find.descendant(of: action, matching: find.text('নতুন অর্ডার')), findsOneWidget);
      await tester.tap(action);
      expect(opened, 1);

      ReferenceSync.rememberFailureForTest(SyncAttemptFailure.from(
          DioException(
              requestOptions: RequestOptions(path: '/sync/sales/pull'),
              response: Response(requestOptions: RequestOptions(path: '/sync/sales/pull'), statusCode: 500),
              type: DioExceptionType.badResponse),
          direction: SyncDirection.pull));
      await _pump(tester, const OrderListScreen(), theme);
      expect(find.byType(ErrorState), findsOneWidget);
      expect(find.text('অর্ডারের তালিকা আনা যায়নি'), findsOneWidget);
      expect(find.byKey(const ValueKey('error-retry')), findsOneWidget);
      expect(find.text(ReferenceSync.troubleSentence!), findsOneWidget, reason: 'কেন — বাংলায়');
    });

    // ── ডেলিভারি ট্র্যাকিং ──
    testWidgets('ট্র্যাকিং · $name: খসড়া, সারি নকশা ৩-এ, সময়রেখার মাথায় নাম আর নম্বর', (tester) async {
      final theme = t.value();
      final gate = Completer<void>();
      final api = _TrackApi(gate: gate, rows: const [_TrackApi.sale]);
      await _pump(tester, DeliveryTrackingScreen(api: api), theme, settle: false);
      await tester.pump();
      expect(find.byType(LoadingSkeleton), findsOneWidget);
      gate.complete();
      await tester.pumpAndSettle();
      _expectRow(tester, find.byKey(const ValueKey('tracked-row-t1')), 'S-0007', theme);

      await tester.tap(find.byKey(const ValueKey('tracked-row-t1')));
      await tester.pumpAndSettle();
      final head = find.byKey(const ValueKey('story-customer'));
      expect(tester.widget<CustomerLine>(head).text, _place);
      final number = find.byKey(const ValueKey('story-number'));
      expect(tester.widget<Text>(number).data, startsWith('S-0007'));
      expect(tester.getTopLeft(number).dy, greaterThanOrEqualTo(tester.getBottomLeft(head).dy - 0.5));
    });

    testWidgets('ট্র্যাকিং · $name: ভুলে আবার চেষ্টা দরজা ডাকে, খালিতে কাজের বোতাম', (tester) async {
      final theme = t.value();
      final failing = _TrackApi(failFirst: true);
      await _pump(tester, DeliveryTrackingScreen(api: failing), theme);
      expect(find.byType(ErrorState), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('error-retry')));
      await tester.pumpAndSettle();
      expect(failing.calls, 2);
      // ⓘ দ্বিতীয় উত্তর খালি — খালি অবস্থা, তার বোতাম আবার আনে
      expect(find.byType(EmptyState), findsOneWidget);
      expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
      final action = find.byKey(const ValueKey('tracking-empty-action'));
      expect(tester.getSize(action).height, greaterThanOrEqualTo(48));
      await tester.tap(action);
      await tester.pumpAndSettle();
      expect(failing.calls, 3);
    });

    // ── পাঠানোর আগের শিট ──
    testWidgets('পাঠানোর আগে · $name: কার অর্ডার — "নাম · পয়েন্ট · শাখা"', (tester) async {
      final theme = t.value();
      await _pump(
          tester,
          Scaffold(
              body: OrderConfirmSheet(
                  customer: _place,
                  lines: const [ConfirmLine(name: 'সাবান ১০০ গ্রাম', qty: 24, free: 2, taka: 840)],
                  standing: Future.value(const CustomerStanding(
                      due: 0, advance: 0, pendingClaims: 0, pendingClaimCount: 0, limit: 0, orderTotal: 840,
                      toPay: 0, overLimit: false)))),
          theme);
      final line = find.byKey(const ValueKey('confirm-customer'));
      expect(tester.widget<CustomerLine>(line).text, _place);
      expect(tester.getTopLeft(line).dy, greaterThan(tester.getBottomLeft(find.text('পাঠানোর আগে দেখে নিন')).dy - 0.5));
    });
  }
}
