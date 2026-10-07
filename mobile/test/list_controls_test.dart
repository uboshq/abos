import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/orders/tracking_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/list_queries.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/core/records/sales_order_record.dart';
import 'package:abos_mobile/core/records/stock_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/widgets/list_controls.dart';
import 'package:abos_mobile/features/orders/delivery_tracking_screen.dart';
import 'package:abos_mobile/features/stock/stock_list_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ফিল্টার আর সাজানো — মালিক, ২ অক্টোবর ২০২৬: "Filter r Sort by bosaw"।
///
/// <p>প্রতিটা স্ক্রিনের নিয়ম `list_queries.dart`-এর খাঁটি function-এ, তাই
/// বেশিরভাগ পরীক্ষা স্ক্রিন ছাড়াই; শেষে দুটো widget পরীক্ষা দেখে যে বোতাম চাপলে
/// সত্যিই তালিকার ক্রম আর সারি বদলায়।
void main() {
  List<String> names<T>(List<T> rows, String Function(T) of) =>
      rows.map(of).toList();

  group('গ্রাহক', () {
    final rows = [
      const CustomerRecord({
        'id': 'a',
        'nameBn': 'রহিম স্টোর',
        'code': 'C-2',
        'phone': '017',
        'customerType': 'ডিলার',
      }),
      const CustomerRecord({
        'id': 'b',
        'nameBn': 'করিম ট্রেডার্স',
        'code': 'C-1',
        'isActive': false,
        'customerType': 'খুচরা',
      }),
      const CustomerRecord({'id': 'c', 'nameEn': 'Abul Shop', 'code': 'C-3'}),
    ];

    test('নাম ক থেকে হ, উল্টো, আর কোড', () {
      expect(
          names(CustomerListQuery.apply(rows), (c) => c.id), ['c', 'b', 'a']);
      expect(
          names(CustomerListQuery.apply(rows, sort: 'nameDesc'), (c) => c.id),
          ['a', 'b', 'c']);
      expect(names(CustomerListQuery.apply(rows, sort: 'code'), (c) => c.id),
          ['b', 'a', 'c']);
    });

    test('অবস্থা, মোবাইল, ধরন', () {
      expect(
          names(CustomerListQuery.apply(rows, filters: {'active': 'no'}),
              (c) => c.id),
          ['b']);
      expect(
          names(CustomerListQuery.apply(rows, filters: {'phone': 'yes'}),
              (c) => c.id),
          ['a']);
      expect(
          names(CustomerListQuery.apply(rows, filters: {'type': 'খুচরা'}),
              (c) => c.id),
          ['b']);
      // খোঁজ আর ফিল্টার একসাথে
      expect(
          CustomerListQuery.apply(rows,
              query: 'রহিম', filters: {'active': 'no'}),
          isEmpty);
    });

    test('ধরনের ফিল্টার শুধু দুটো আলাদা ধরন থাকলে', () {
      final keys = CustomerListQuery.filterGroups(rows).map((g) => g.key);
      expect(keys, containsAll(['active', 'phone', 'type']));
      final one = CustomerListQuery.filterGroups(rows.take(1));
      expect(one.map((g) => g.key), isNot(contains('type')));
    });
  });

  group('বকেয়া', () {
    DueRow row(String id, String name, String outstanding,
            {String limit = '0', int days = 0}) =>
        DueRow(
          customer: CustomerRecord({'id': id, 'nameBn': name}),
          due: CustomerDueRecord({
            'customerId': id,
            'outstanding': outstanding,
            'creditLimit': limit,
            'creditDays': days,
          }),
        );

    final rows = [
      row('a', 'খ দোকান', '1200', limit: '1000', days: 15), // 120%
      row('b', 'ক দোকান', '8000', limit: '50000', days: 30), // 16%
      row('c', 'গ দোকান', '3000'), // সীমা নেই
    ];

    test('শুরুতে বড় বকেয়া উপরে', () {
      expect(names(DueListQuery.apply(rows), (r) => r.customer.id),
          ['b', 'c', 'a']);
    });

    test('কম আগে, নাম, আর সীমার ব্যবহার (সীমা নেই শেষে)', () {
      expect(
          names(DueListQuery.apply(rows, sort: 'dueAsc'), (r) => r.customer.id),
          ['a', 'c', 'b']);
      expect(
          names(DueListQuery.apply(rows, sort: 'name'), (r) => r.customer.id),
          ['b', 'a', 'c']);
      expect(
          names(DueListQuery.apply(rows, sort: 'limitUsed'),
              (r) => r.customer.id),
          ['a', 'b', 'c']);
    });

    test('সীমা ছাড়িয়েছে, সীমার মধ্যে, সীমা নেই, শর্তের দিন', () {
      List<String> only(Map<String, String> f) =>
          names(DueListQuery.apply(rows, filters: f), (r) => r.customer.id);
      expect(only({'limit': 'over'}), ['a']);
      expect(only({'limit': 'within'}), ['b']);
      expect(only({'limit': 'none'}), ['c']);
      expect(only({'days': '15'}), ['a']);
      expect(only({'days': '0'}), ['c']);
    });

    test('শর্তের দিনের অংশে প্রতিটা আলাদা দিন একবার', () {
      final days =
          DueListQuery.filterGroups(rows).firstWhere((g) => g.key == 'days');
      expect(
          days.choices.map((c) => c.label), ['শর্ত নেই', '15 দিন', '30 দিন']);
    });
  });

  group('পণ্য', () {
    final rows = [
      const ProductRecord({
        'id': 'p1',
        'nameBn': 'সাবান',
        'code': 'P-2',
        'salePrice': '40.0000'
      }),
      const ProductRecord(
          {'id': 'p2', 'nameBn': 'চাল', 'code': 'P-1', 'salePrice': '75.5000'}),
      const ProductRecord({'id': 'p3', 'nameBn': 'ডাল', 'isActive': false}),
    ];
    final stock = {'p1': 10.0, 'p2': 0.0};
    double? availableOf(ProductRecord p) => stock[p.id];

    test('নাম, কোড, দাম (দাম নেই সবসময় শেষে)', () {
      List<String> by(String sort) => names(
          ProductListQuery.apply(rows, sort: sort, availableOf: availableOf),
          (p) => p.id);
      expect(by('name'), ['p2', 'p3', 'p1']);
      expect(by('code'), ['p2', 'p1', 'p3']);
      expect(by('priceDesc'), ['p2', 'p1', 'p3']);
      expect(by('priceAsc'), ['p1', 'p2', 'p3']);
    });

    test('অবস্থা, দাম, মজুদ — অজানা মজুদ কোনো দিকেই নয়', () {
      List<String> only(Map<String, String> f) => names(
          ProductListQuery.apply(rows, filters: f, availableOf: availableOf),
          (p) => p.id);
      expect(only({'active': 'no'}), ['p3']);
      expect(only({'price': 'no'}), ['p3']);
      expect(only({'stock': 'yes'}), ['p1']);
      expect(only({'stock': 'zero'}), ['p2']);
    });

    test('মজুদ না এলে (SR-এর ফোন) মজুদের ফিল্টারই নেই', () {
      expect(
          ProductListQuery.filterGroups(rows, availableOf: (_) => null)
              .map((g) => g.key),
          isNot(contains('stock')));
      expect(
          ProductListQuery.filterGroups(rows, availableOf: availableOf)
              .map((g) => g.key),
          contains('stock'));
    });
  });

  group('মজুদ', () {
    final products = {
      's1': const ProductRecord({'id': 's1', 'nameBn': 'সাবান'}),
      's2': const ProductRecord({'id': 's2', 'nameBn': 'চাল'}),
      's3': const ProductRecord({'id': 's3', 'nameBn': 'ডাল'}),
    };
    final rows = [
      const StockRecord({
        'productId': 's1',
        'available': '5',
        'reserved': '2',
        'freeAvailable': '1'
      }),
      const StockRecord({'productId': 's2', 'available': '50'}),
      const StockRecord({'productId': 's3', 'available': '0'}),
    ];
    List<String> run(
            {String sort = 'name',
            Map<String, String> filters = const {},
            String query = ''}) =>
        names(
            StockListQuery.apply(rows,
                sort: sort,
                filters: filters,
                query: query,
                productOf: (r) => products[r.productId]),
            (r) => r.productId);

    test('শুরুর ফিল্টারে শূন্য মজুদ লুকানো', () {
      expect(run(filters: StockListQuery.defaultFilters), ['s2', 's1']);
      expect(run(), ['s2', 's3', 's1']);
      expect(run(filters: {'zero': 'only'}), ['s3']);
    });

    test('পরিমাণ বেশি/কম আগে', () {
      expect(run(sort: 'qtyDesc'), ['s2', 's1', 's3']);
      expect(run(sort: 'qtyAsc'), ['s3', 's1', 's2']);
    });

    test('অর্ডারে/আটকানো আর ফ্রি মাল', () {
      expect(run(filters: {'commit': 'yes'}), ['s1']);
      expect(run(filters: {'commit': 'no'}), ['s2', 's3']);
      expect(run(filters: {'free': 'yes'}), ['s1']);
    });

    test('পণ্য না এলে খোঁজ খালি থাকলে তবু দেখায়', () {
      final orphan = [
        const StockRecord({'productId': 'x', 'available': '3'})
      ];
      expect(
          StockListQuery.apply(orphan, productOf: (_) => null), hasLength(1));
      expect(StockListQuery.apply(orphan, query: 'চাল', productOf: (_) => null),
          isEmpty);
    });
  });

  group('অর্ডার', () {
    final rows = [
      const SalesOrderRecord({
        'id': 'o1',
        'trxDate': '2026-09-30',
        'total': '500',
        'status': 'confirmed',
        'customerId': 'b'
      }),
      const SalesOrderRecord({
        'id': 'o2',
        'trxDate': '2026-10-02',
        'total': '100',
        'status': 'cancelled',
        'customerId': 'a'
      }),
      const SalesOrderRecord(
          {'id': 'o3', 'status': 'confirmed', 'customerId': 'c'}),
    ];
    const shops = {'a': 'ক দোকান', 'b': 'খ দোকান', 'c': 'গ দোকান'};
    List<String> run(
            {String sort = 'dateDesc',
            Map<String, String> filters = const {}}) =>
        names(
            OrderListQuery.apply(rows,
                sort: sort,
                filters: filters,
                customerOf: (o) => shops[o.customerId]!),
            (o) => o.id);

    test('তারিখ নতুন/পুরনো আগে, তারিখ নেই শেষে', () {
      expect(run(), ['o2', 'o1', 'o3']);
      expect(run(sort: 'dateAsc'), ['o1', 'o2', 'o3']);
    });

    test('টাকা আর দোকানের নাম', () {
      expect(run(sort: 'amountDesc'), ['o1', 'o2', 'o3']);
      expect(run(sort: 'amountAsc'), ['o2', 'o1', 'o3']);
      expect(run(sort: 'customer'), ['o2', 'o1', 'o3']);
    });

    test('অবস্থার ফিল্টার, নাম বাংলায়', () {
      expect(run(filters: {'status': 'cancelled'}), ['o2']);
      final group = OrderListQuery.filterGroups(rows).single;
      expect(
          group.choices.map((c) => c.label), containsAll(['নিশ্চিত', 'বাতিল']));
      expect(OrderListQuery.filterGroups(rows.take(1)), isEmpty);
    });
  });

  group('ডেলিভারি ট্র্যাকিং', () {
    TrackedSale sale(String no, String? date, double total, String? shop) =>
        TrackedSale(
            kind: 'challan',
            id: no,
            no: no,
            date: date,
            customer: shop,
            total: total,
            step: 'gate_out',
            billed: false);
    final rows = [
      sale('S-1', '2026-09-28', 300, 'খ দোকান'),
      sale('S-2', '2026-10-02', 100, null),
      sale('S-3', '2026-09-30', 900, 'ক দোকান'),
    ];
    List<String> run(String sort) =>
        TrackingListQuery.apply(rows, sort: sort).map((s) => s.no).toList();

    test('পাঁচ রকম সাজানো', () {
      expect(run('dateDesc'), ['S-2', 'S-3', 'S-1']);
      expect(run('dateAsc'), ['S-1', 'S-3', 'S-2']);
      expect(run('amountDesc'), ['S-3', 'S-1', 'S-2']);
      expect(run('amountAsc'), ['S-2', 'S-1', 'S-3']);
      expect(run('customer'), ['S-3', 'S-1', 'S-2']);
    });
  });

  test('সমান সারি আগের ক্রমেই থাকে', () {
    expect(sortStable([3, 1, 2, 1], (a, b) => 0), [3, 1, 2, 1]);
  });

  group('widget', () {
    // লম্বা পর্দা — ধাপের chip আর পুরো ফিল্টার শিট যেন এক পর্দায় আঁটে; সারি
    // অলস ListView-এ, পর্দার বাইরে গেলে খুঁজে পাওয়া যায় না।
    void tallScreen(WidgetTester tester) {
      tester.view.physicalSize = const Size(800, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
    }

    testWidgets('সাজান শিট খুলে "টাকা: বেশি আগে" বাছলে ক্রম বদলায়',
        (tester) async {
      tallScreen(tester);
      await tester.pumpWidget(
          MaterialApp(home: DeliveryTrackingScreen(api: _FakeApi())));
      await tester.pumpAndSettle();

      double top(String no) => tester.getTopLeft(find.text(no)).dy;
      // শুরুতে নতুন তারিখ আগে
      expect(top('S-2'), lessThan(top('S-3')));
      expect(find.text('তারিখ: নতুন আগে'), findsOneWidget);

      await tester.tap(find.byKey(const Key('list-controls-sort')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('sort-option-amountDesc')));
      await tester.pumpAndSettle();

      expect(top('S-3'), lessThan(top('S-1')));
      expect(top('S-1'), lessThan(top('S-2')));
      expect(find.text('টাকা: বেশি আগে'), findsOneWidget);
      // এই স্ক্রিনে ফিল্টারের কাজ ধাপের chip করে — ফিল্টার বোতাম নেই
      expect(find.byKey(const Key('list-controls-filter')), findsNothing);
    });

    testWidgets('ফিল্টার শিট: বাছাই প্রয়োগ, ব্যাজে গোনা, সব মুছুন',
        (tester) async {
      tallScreen(tester);
      ListFilters applied = const {};
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: StatefulBuilder(
            builder: (context, setState) => ListControls(
              sortOptions: StockListQuery.sortOptions,
              sort: 'name',
              onSort: (_) {},
              filterGroups: StockListQuery.filterGroups,
              filters: applied,
              onFilters: (value) => setState(() => applied = value),
            ),
          ),
        ),
      ));

      await tester.tap(find.byKey(const Key('list-controls-filter')));
      await tester.pumpAndSettle();
      expect(find.text('প্রয়োগ করুন'), findsOneWidget);
      expect(find.text('সব মুছুন'), findsOneWidget);
      await tester.tap(find.byKey(const Key('filter-zero-only')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('filter-free-yes')));
      await tester.pump();
      await tester.tap(find.byKey(const Key('filter-apply')));
      await tester.pumpAndSettle();

      expect(applied, {'zero': 'only', 'free': 'yes'});
      expect(find.text('2'), findsOneWidget); // ব্যাজ

      await tester.tap(find.byKey(const Key('list-controls-filter')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('filter-clear')));
      await tester.pumpAndSettle();
      expect(applied, isEmpty);
      expect(find.text('2'), findsNothing);
    });

    group('মজুদের স্ক্রিন', () {
      late HiveTestHarness harness;

      setUpAll(() async {
        FakeSecureStorage.install();
        harness = await HiveTestHarness.setUp();
        await ReferenceCache.instance.init();
      });

      tearDownAll(() async => harness.tearDown());

      setUp(() async => ReferenceCache.instance.clearAll());

      testWidgets('শূন্য মজুদ শুরুতে লুকানো, সব মুছুন চাপলে ফেরে',
          (tester) async {
        await tester.runAsync(() async {
          for (final (id, name, qty) in [
            ('s1', 'সাবান', '12.0000'),
            ('s2', 'ডাল', '0.0000'),
          ]) {
            await ReferenceCache.instance.put(
                entityType: 'Product',
                entityId: id,
                updatedAt: DateTime(2026, 10, 2),
                payload: {'id': id, 'nameBn': name});
            await ReferenceCache.instance.put(
                entityType: 'StockOnHand',
                entityId: id,
                updatedAt: DateTime(2026, 10, 2),
                payload: {'productId': id, 'floor': qty, 'available': qty});
          }
        });

        await tester.pumpWidget(const MaterialApp(home: StockListScreen()));
        await tester.pump();

        expect(find.text('সাবান'), findsOneWidget);
        // ⭐ মজুদ মডিউল — মূল্যসহ তালিকা আর মজুদের রিপোর্ট, পর্দার মাথায় (মালিক, ৪ অক্টোবর ২০২৬)
        expect(find.byKey(const Key('stock-value')), findsOneWidget);
        expect(find.byKey(const Key('stock-reports')), findsOneWidget);
        expect(find.text('ডাল'), findsNothing);
        expect(find.text('1'), findsOneWidget); // ব্যাজ: একটা ফিল্টার চালু

        await tester.tap(find.byKey(const Key('list-controls-filter')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const Key('filter-clear')));
        await tester.pumpAndSettle();

        expect(find.text('ডাল'), findsOneWidget);
      });
    });
  });
}

class _FakeApi implements TrackingApi {
  static TrackedSale _sale(String no, String date, double total) => TrackedSale(
      kind: 'challan',
      id: no,
      no: no,
      date: date,
      customer: 'দোকান $no',
      total: total,
      step: 'gate_out',
      billed: false);

  @override
  Future<TrackingList> list({String? query, String? step}) async =>
      TrackingList([
        _sale('S-1', '2026-09-28', 300),
        _sale('S-2', '2026-10-02', 100),
        _sale('S-3', '2026-09-30', 900),
      ], const {
        'all': 3
      });

  @override
  Future<(TrackedSale, List<TrackingEvent>, List<Milestone>)> story(
          TrackedSale sale) async =>
      (sale, const <TrackingEvent>[], const <Milestone>[]);
}
