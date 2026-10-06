import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/features/deliveries/deliveries_screen.dart';
import 'package:abos_mobile/features/loading/loading_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ লোডিং শিট, ফোনে (0.4.19) — মালিকের বিক্রয় পরিকল্পনা (সংস্করণ ২) ধাপ ৪, ৬ অক্টোবর ২০২৬: "পণ্য ধরে কত তুলতে হবে,
/// চালান ধরে কার জন্য"; আর আজকের ডেলিভারির "আরও দেখুন"।
const _trip = LoadingTrip(
    id: 'trip-1',
    documentNo: 'TRP-0001',
    date: '2026-10-06',
    vehicle: 'DM-T 11-0001',
    driver: 'করিম',
    driverPhone: '01811-222333',
    challans: 2);

class _FakeLoading implements LoadingApi {
  bool packedDone = false;
  int packCalls = 0;
  final pagesAsked = <int>[];

  @override
  Future<LoadingTripPage> trips({int page = 1}) async {
    pagesAsked.add(page);
    return page == 1
        ? const LoadingTripPage([_trip], nextPage: 2)
        : const LoadingTripPage(
            [LoadingTrip(id: 'trip-2', documentNo: 'TRP-0002', challans: 1)]);
  }

  @override
  Future<LoadingSheet> sheet(String id) async => LoadingSheet(
        trip: _trip,
        products: const [
          LoadingProduct(
            product: 'কসমস বিস্কুট',
            unit: 'পিস',
            qty: 5,
            lots: [LoadingShare('L-01', 5)],
            forWhom: [
              LoadingShare('রহিম স্টোর (CH-0001)', 2),
              LoadingShare('করিম স্টোর (CH-0002)', 3),
            ],
          ),
        ],
        challans: [
          LoadingChallan(
              documentNo: 'CH-0001',
              customer: 'রহিম স্টোর',
              packed: packedDone,
              lines: const [LoadingChallanLine('কসমস বিস্কুট', 2, 0)]),
          LoadingChallan(
              documentNo: 'CH-0002',
              customer: 'করিম স্টোর',
              packed: packedDone,
              lines: const [LoadingChallanLine('কসমস বিস্কুট', 3, 0)]),
        ],
      );

  @override
  Future<(int, String)> packed(String id) async {
    packCalls++;
    packedDone = true;
    return (2, 'TRP-0001 — 2টা চালান "প্যাক হয়েছে" ধাপে গেল।');
  }
}

class _FakeRun implements DeliveryRunApi {
  final pagesAsked = <int>[];

  @override
  Future<DeliveryRunPage> today({int page = 1}) async {
    pagesAsked.add(page);
    return DeliveryRunPage([
      DeliveryRunRow(
          token: 'tok-$page',
          documentNo: 'CH-000$page',
          customer: 'দোকান $page'),
    ], nextPage: page == 1 ? 2 : null);
  }
}

Future<void> _pump(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = const Size(800, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('the open trips, with the crew, and the next page below',
      (tester) async {
    final api = _FakeLoading();
    await _pump(tester, LoadingListScreen(api: api));

    expect(find.byKey(const ValueKey('trip-TRP-0001')), findsOneWidget);
    expect(find.textContaining('গাড়ি DM-T 11-0001 · চালক করিম · 01811-222333'),
        findsOneWidget);
    expect(find.textContaining('2টা চালান'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('loading-more')));
    await tester.pumpAndSettle();
    expect(api.pagesAsked, [1, 2]);
    expect(find.byKey(const ValueKey('trip-TRP-0001')), findsOneWidget,
        reason: '⛔ পরের পাতা আগের সারি মুছে দিয়েছে');
    expect(find.byKey(const ValueKey('trip-TRP-0002')), findsOneWidget);
    expect(find.byKey(const ValueKey('loading-more')), findsNothing);
  });

  testWidgets(
      'the sheet says how much by product and for whom, and packed asks first then marks every challan',
      (tester) async {
    final api = _FakeLoading();
    await _pump(tester, LoadingSheetScreen(trip: _trip, api: api));

    expect(find.byKey(const ValueKey('loading-product-কসমস বিস্কুট')),
        findsOneWidget);
    expect(find.text('5 পিস'), findsOneWidget);
    expect(find.text('লট: L-01 5'), findsOneWidget);
    expect(find.text('• রহিম স্টোর (CH-0001) — 2'), findsOneWidget);
    expect(find.text('• করিম স্টোর (CH-0002) — 3'), findsOneWidget);
    expect(find.text('প্যাক হয়েছে'), findsOneWidget,
        reason: 'কেবল বোতামটা — কোনো চালানে এখনো চিহ্ন নেই');

    await tester.tap(find.byKey(const ValueKey('loading-packed')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('না'));
    await tester.pumpAndSettle();
    expect(api.packCalls, 0, reason: '⛔ "না" বলার পরেও প্যাক পাঠানো হয়েছে');

    await tester.tap(find.byKey(const ValueKey('loading-packed')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('loading-packed-sure')));
    await tester.pumpAndSettle();

    expect(api.packCalls, 1);
    expect(find.byKey(const ValueKey('loading-notice')), findsOneWidget);
    expect(find.text('প্যাক হয়েছে'), findsNWidgets(2),
        reason: '⛔ শিট নতুন করে আনা হয়নি — চালানে চিহ্ন নেই');
    expect(find.byKey(const ValueKey('loading-packed')), findsNothing);
    expect(find.byKey(const ValueKey('loading-all-packed')), findsOneWidget);
  });

  testWidgets("today's deliveries show more below, the first page kept",
      (tester) async {
    final run = _FakeRun();
    await _pump(tester, DeliveriesScreen(api: run));

    expect(find.text('দোকান 1'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('deliveries-more')));
    await tester.pumpAndSettle();
    expect(run.pagesAsked, [1, 2]);
    expect(find.text('দোকান 1'), findsOneWidget);
    expect(find.text('দোকান 2'), findsOneWidget);
    expect(find.byKey(const ValueKey('deliveries-more')), findsNothing);
  });

  test('the tile follows the trip view key and the sales switch', () {
    const repository = MenuRepository();
    Set<String> keysOf(List<String> p) => repository
        .ordered(
            const [],
            AuthUser(
                id: '1',
                name: 'X',
                email: 'x@abos.test',
                roles: const [],
                permissions: p))
        .map((i) => i.key)
        .toSet();
    expect(keysOf(['sales.shipment.view']), contains('sales.loading'));
    expect(keysOf(['sales.delivery.view']), isNot(contains('sales.loading')));
    expect(ModuleGate.moduleOfPath['loading'], 'sales');
  });
}
