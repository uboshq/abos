import '../orders/tracking_api.dart';
import '../widgets/list_controls.dart';
import 'customer_record.dart';
import 'product_record.dart';
import 'sales_order_record.dart';
import 'stock_record.dart';

// প্রতিটা তালিকার ফিল্টার আর সাজানো — খাঁটি function, widget ছাড়া।
//
// <p>⭐ মালিক, ২ অক্টোবর ২০২৬: "Filter r Sort by bosaw"। [ListControls]
// শুধু বোতাম আর শিট; কোন সারি থাকবে আর কোন ক্রমে, সেটা এখানে — যাতে
// `test/list_controls_test.dart` স্ক্রিন না বানিয়েই প্রতিটা নিয়ম পরীক্ষা করতে
// পারে।
//
// <p>⛔ <b>শুধু সেই ঘর যা record-এ সত্যিই আছে।</b> পণ্যে ক্যাটাগরি/ব্র্যান্ড,
// মজুদে গুদাম/লট, গ্রাহকে এলাকা — সার্ভার এগুলো পাঠায় না (দেখুন প্রতিটা
// record-এর field তালিকা), তাই এখানে এগুলোর ফিল্টারও নেই। বানানো ফিল্টার
// মানে খালি তালিকা, আর খালি তালিকা দেখে মানুষ ভাবে মাল নেই।
//
// <p>সাজানো <b>স্থির</b> ([sortStable]): সমান দুটো সারি আগের ক্রমেই থাকে, তাই
// একই বাছাইয়ে বারবার খুললে তালিকা লাফায় না।

/// [compare] সমান বললে আগের ক্রম রাখে — Dart-এর `List.sort` সেটা কথা দেয় না।
List<T> sortStable<T>(Iterable<T> rows, int Function(T a, T b) compare) {
  final indexed = rows.toList().asMap().entries.toList()
    ..sort((a, b) {
      final c = compare(a.value, b.value);
      return c != 0 ? c : a.key.compareTo(b.key);
    });
  return [for (final entry in indexed) entry.value];
}

/// নাম তুলনা — ইংরেজি নামে বড়/ছোট হাতের অক্ষর এক; বাংলা Unicode-এর ক্রম
/// বর্ণমালার ক্রমই (অ…ঔ, ক…হ)।
int compareText(String a, String b) =>
    a.toLowerCase().compareTo(b.toLowerCase());

/// না-থাকা সংখ্যা সবসময় শেষে — উঠতি বা নামতি যেটাই হোক। "দাম নেই" মানে শূন্য
/// নয়, তাই সবচেয়ে সস্তা হিসেবে উপরে উঠে আসা ভুল।
int compareNullableLast(num? a, num? b, {bool descending = false}) {
  if (a == null && b == null) return 0;
  if (a == null) return 1;
  if (b == null) return -1;
  return descending ? b.compareTo(a) : a.compareTo(b);
}

/// তালিকায় অন্তত দুটো আলাদা মান থাকলে তবেই সেই ফিল্টার — একটা মানের ফিল্টার
/// কিছু বাছে না।
List<String> _distinct(Iterable<String?> values) {
  final seen = <String>{};
  for (final value in values) {
    if (value != null && value.isNotEmpty) seen.add(value);
  }
  return seen.toList()..sort(compareText);
}

const _nameAsc = ListSortOption('name', 'নাম: ক থেকে হ');
const _nameDesc = ListSortOption('nameDesc', 'নাম: হ থেকে ক');
const _code = ListSortOption('code', 'কোড অনুযায়ী');

const _activeGroup = ListFilterGroup('active', 'অবস্থা', [
  ListFilterChoice('yes', 'সক্রিয়'),
  ListFilterChoice('no', 'নিষ্ক্রিয়'),
]);

// ───────────────────────────── গ্রাহক ─────────────────────────────

class CustomerListQuery {
  const CustomerListQuery._();

  static const sortOptions = [_nameAsc, _nameDesc, _code];
  static const defaultSort = 'name';

  /// ধরন (`customerType`) সার্ভারের মুক্ত লেখা/মাস্টারের নাম — যেমন এসেছে
  /// তেমনই দেখানো হয়, অনুবাদ করে বানানো হয় না।
  static List<ListFilterGroup> filterGroups(Iterable<CustomerRecord> all) {
    final types = _distinct(all.map((c) => c.customerType));
    return [
      _activeGroup,
      const ListFilterGroup('phone', 'মোবাইল নম্বর', [
        ListFilterChoice('yes', 'আছে'),
        ListFilterChoice('no', 'নেই'),
      ]),
      if (types.length >= 2)
        ListFilterGroup(
            'type', 'ধরন', [for (final t in types) ListFilterChoice(t, t)]),
    ];
  }

  static List<CustomerRecord> apply(
    Iterable<CustomerRecord> rows, {
    String query = '',
    String sort = defaultSort,
    ListFilters filters = const {},
  }) {
    final kept = rows.where((c) {
      if (!c.matches(query)) return false;
      switch (filters['active']) {
        case 'yes' when !c.isActive:
        case 'no' when c.isActive:
          return false;
      }
      switch (filters['phone']) {
        case 'yes' when c.phone == null:
        case 'no' when c.phone != null:
          return false;
      }
      final type = filters['type'];
      if (type != null && c.customerType != type) return false;
      return true;
    });
    return sortStable(
        kept,
        switch (sort) {
          'nameDesc' => (a, b) => compareText(b.name, a.name),
          // কোড না থাকলে শেষে, তারপর নাম দিয়ে।
          'code' => (a, b) => a.code == null || b.code == null
              ? (a.code == null ? 1 : 0) - (b.code == null ? 1 : 0)
              : compareText(a.code!, b.code!),
          _ => (a, b) => compareText(a.name, b.name),
        });
  }
}

// ───────────────────────────── বকেয়া ─────────────────────────────

/// বকেয়া তালিকার এক সারি — দোকান আর তার বকেয়া, একসাথে।
class DueRow {
  const DueRow({required this.customer, required this.due});

  final CustomerRecord customer;
  final CustomerDueRecord due;

  /// সীমার কত ভাগ খরচ — সীমা না থাকলে null (শূন্য সীমা মানে "বাকি নয়", ১০০%
  /// নয়; দেখুন [CustomerDueRecord.hasCreditLimit])।
  double? get limitUsed =>
      due.hasCreditLimit ? due.outstanding / due.creditLimit : null;
}

class DueListQuery {
  const DueListQuery._();

  static const sortOptions = [
    ListSortOption('dueDesc', 'বকেয়া: বেশি আগে'),
    ListSortOption('dueAsc', 'বকেয়া: কম আগে'),
    _nameAsc,
    ListSortOption('limitUsed', 'সীমার ব্যবহার: বেশি আগে'),
  ];

  /// বড় বকেয়া উপরে — এই স্ক্রিনের আগের ক্রমই, এখনো প্রথম বাছাই।
  static const defaultSort = 'dueDesc';

  /// ⓘ "অগ্রিম" ফিল্টার নেই ইচ্ছে করে: এই পাতা অগ্রিমের দোকান আগেই বাদ দেয়
  /// (দেখুন `DueListScreen._owing`), তাই সেই ফিল্টার সবসময় খালি দেখাত।
  static List<ListFilterGroup> filterGroups(Iterable<DueRow> all) {
    final days = all.map((r) => r.due.creditDays).toSet().toList()..sort();
    return [
      const ListFilterGroup('limit', 'ঋণসীমা', [
        ListFilterChoice('over', 'সীমা ছাড়িয়েছে'),
        ListFilterChoice('within', 'সীমার মধ্যে'),
        ListFilterChoice('none', 'সীমা নেই'),
      ]),
      if (days.length >= 2)
        ListFilterGroup('days', 'বাকির শর্ত', [
          for (final d in days)
            ListFilterChoice('$d', d == 0 ? 'শর্ত নেই' : '$d দিন'),
        ]),
    ];
  }

  static List<DueRow> apply(
    Iterable<DueRow> rows, {
    String query = '',
    String sort = defaultSort,
    ListFilters filters = const {},
  }) {
    final kept = rows.where((r) {
      if (!r.customer.matches(query)) return false;
      // শুধু হিসাব — বকেয়া সীমার চেয়ে বেশি কি না। বিক্রি আটকাবে কি না, সেটা
      // কোম্পানির সুইচ, ফোন জানে না; এই ফিল্টার শুধু খুঁজে দেয়, রায় দেয় না।
      final over =
          r.due.hasCreditLimit && r.due.outstanding > r.due.creditLimit;
      switch (filters['limit']) {
        case 'over' when !over:
        case 'within' when over || !r.due.hasCreditLimit:
        case 'none' when r.due.hasCreditLimit:
          return false;
      }
      final days = filters['days'];
      if (days != null && '${r.due.creditDays}' != days) return false;
      return true;
    });
    return sortStable(
        kept,
        switch (sort) {
          'dueAsc' => (a, b) =>
              a.due.outstandingInView.compareTo(b.due.outstandingInView),
          'name' => (a, b) => compareText(a.customer.name, b.customer.name),
          'limitUsed' => (a, b) =>
              compareNullableLast(a.limitUsed, b.limitUsed, descending: true),
          _ => (a, b) =>
              b.due.outstandingInView.compareTo(a.due.outstandingInView),
        });
  }
}

// ───────────────────────────── পণ্য ─────────────────────────────

double? _cachedAvailable(ProductRecord p) =>
    StockRecord.forProduct(p.id)?.available;

class ProductListQuery {
  const ProductListQuery._();

  static const sortOptions = [
    _nameAsc,
    _nameDesc,
    _code,
    ListSortOption('priceDesc', 'দাম: বেশি আগে'),
    ListSortOption('priceAsc', 'দাম: কম আগে'),
  ];
  static const defaultSort = 'name';

  /// মজুদের ফিল্টার শুধু তখন, যখন এই ফোনে মজুদ আসে। SR-এর ফোনে মজুদের রেকর্ডই
  /// আসে না (মালিক, ১ অক্টোবর: SR phone hides stock) — সেখানে "মজুদ শূন্য"
  /// বাছলে সব পণ্য হারাত।
  static List<ListFilterGroup> filterGroups(
    Iterable<ProductRecord> all, {
    double? Function(ProductRecord) availableOf = _cachedAvailable,
  }) {
    final stockKnown = all.any((p) => availableOf(p) != null);
    return [
      _activeGroup,
      const ListFilterGroup('price', 'বিক্রয় দাম', [
        ListFilterChoice('yes', 'দাম আছে'),
        ListFilterChoice('no', 'দাম নেই'),
      ]),
      if (stockKnown)
        const ListFilterGroup('stock', 'মজুদ', [
          ListFilterChoice('yes', 'বিক্রয়যোগ্য আছে'),
          ListFilterChoice('zero', 'মজুদ শূন্য'),
        ]),
    ];
  }

  static List<ProductRecord> apply(
    Iterable<ProductRecord> rows, {
    String query = '',
    String sort = defaultSort,
    ListFilters filters = const {},
    double? Function(ProductRecord) availableOf = _cachedAvailable,
  }) {
    final kept = rows.where((p) {
      if (!p.matches(query)) return false;
      switch (filters['active']) {
        case 'yes' when !p.isActive:
        case 'no' when p.isActive:
          return false;
      }
      switch (filters['price']) {
        case 'yes' when p.salePrice == null:
        case 'no' when p.salePrice != null:
          return false;
      }
      final stock = filters['stock'];
      if (stock != null) {
        // মজুদ অজানা (সারি এখনো আসেনি) কোনো দিকেই গোনা হয় না — অজানা শূন্য নয়।
        final available = availableOf(p);
        if (available == null) return false;
        if (stock == 'yes' && available <= 0) return false;
        if (stock == 'zero' && available > 0) return false;
      }
      return true;
    });
    return sortStable(
        kept,
        switch (sort) {
          'nameDesc' => (a, b) => compareText(b.name, a.name),
          'code' => (a, b) => a.code == null || b.code == null
              ? (a.code == null ? 1 : 0) - (b.code == null ? 1 : 0)
              : compareText(a.code!, b.code!),
          'priceDesc' => (a, b) =>
              compareNullableLast(a.salePrice, b.salePrice, descending: true),
          'priceAsc' => (a, b) => compareNullableLast(a.salePrice, b.salePrice),
          _ => (a, b) => compareText(a.name, b.name),
        });
  }
}

// ───────────────────────────── মজুদ ─────────────────────────────

ProductRecord? _cachedProduct(StockRecord row) => row.product;

class StockListQuery {
  const StockListQuery._();

  static const sortOptions = [
    _nameAsc,
    ListSortOption('qtyDesc', 'পরিমাণ: বেশি আগে'),
    ListSortOption('qtyAsc', 'পরিমাণ: কম আগে'),
  ];
  static const defaultSort = 'name';

  /// ⭐ শূন্য মজুদ শুরুতেই লুকানো — মালিক আগেই চেয়েছিলেন। তাই ফিল্টার বোতামের
  /// ব্যাজে শুরু থেকেই "1" — লুকানো আছে সেটা চোখে পড়ে, আর "সব মুছুন" চাপলে
  /// শূন্যগুলোও ফেরে।
  static const ListFilters defaultFilters = {'zero': 'hide'};

  static const filterGroups = [
    ListFilterGroup('zero', 'শূন্য মজুদ', [
      ListFilterChoice('hide', 'লুকান'),
      ListFilterChoice('only', 'শুধু শূন্য'),
    ]),
    ListFilterGroup('commit', 'অর্ডারে বা আটকানো', [
      ListFilterChoice('yes', 'আছে'),
      ListFilterChoice('no', 'নেই'),
    ]),
    ListFilterGroup('free', 'ফ্রি মাল', [
      ListFilterChoice('yes', 'আছে'),
    ]),
  ];

  static List<StockRecord> apply(
    Iterable<StockRecord> rows, {
    String query = '',
    String sort = defaultSort,
    ListFilters filters = const {},
    ProductRecord? Function(StockRecord) productOf = _cachedProduct,
  }) {
    final kept = rows.where((row) {
      // পণ্য এখনো না এলে নাম নেই খোঁজার — খোঁজ খালি থাকলে তবু দেখায়।
      final product = productOf(row);
      if (!(product?.matches(query) ?? query.trim().isEmpty)) return false;
      final zero = row.available <= 0;
      switch (filters['zero']) {
        case 'hide' when zero:
        case 'only' when !zero:
          return false;
      }
      switch (filters['commit']) {
        case 'yes' when !row.hasCommitments:
        case 'no' when row.hasCommitments:
          return false;
      }
      if (filters['free'] == 'yes' && row.freeAvailable <= 0) return false;
      return true;
    });
    String nameOf(StockRecord row) => productOf(row)?.name ?? '';
    return sortStable(
        kept,
        switch (sort) {
          'qtyDesc' => (a, b) => b.available.compareTo(a.available),
          'qtyAsc' => (a, b) => a.available.compareTo(b.available),
          _ => (a, b) => compareText(nameOf(a), nameOf(b)),
        });
  }
}

// ───────────────────────────── অর্ডার ─────────────────────────────

String _cachedCustomerName(SalesOrderRecord order) => order.customerName;

const _dateDesc = ListSortOption('dateDesc', 'তারিখ: নতুন আগে');
const _dateAsc = ListSortOption('dateAsc', 'তারিখ: পুরনো আগে');
const _amountDesc = ListSortOption('amountDesc', 'টাকা: বেশি আগে');
const _amountAsc = ListSortOption('amountAsc', 'টাকা: কম আগে');
const _customerAsc = ListSortOption('customer', 'দোকান: ক থেকে হ');

int? _millis(DateTime? date) => date?.millisecondsSinceEpoch;

class OrderListQuery {
  const OrderListQuery._();

  static const sortOptions = [
    _dateDesc,
    _dateAsc,
    _amountDesc,
    _amountAsc,
    _customerAsc,
  ];
  static const defaultSort = 'dateDesc';

  /// অবস্থার নাম [SalesOrderRecord.statusLabel] থেকে — অচেনা অবস্থা যেমন এসেছে
  /// তেমন দেখায়, ঠিক তালিকার সারির মতো।
  static List<ListFilterGroup> filterGroups(Iterable<SalesOrderRecord> all) {
    final byStatus = <String, String>{};
    for (final order in all) {
      final status = order.status;
      if (status != null) byStatus[status] = order.statusLabel;
    }
    if (byStatus.length < 2) return const [];
    return [
      ListFilterGroup('status', 'অবস্থা', [
        for (final entry in byStatus.entries)
          ListFilterChoice(entry.key, entry.value),
      ]),
    ];
  }

  static List<SalesOrderRecord> apply(
    Iterable<SalesOrderRecord> rows, {
    String sort = defaultSort,
    ListFilters filters = const {},
    String Function(SalesOrderRecord) customerOf = _cachedCustomerName,
  }) {
    final status = filters['status'];
    final kept = rows.where((o) => status == null || o.status == status);
    return sortStable(
        kept,
        switch (sort) {
          'dateAsc' => (a, b) =>
              compareNullableLast(_millis(a.trxDate), _millis(b.trxDate)),
          'amountDesc' => (a, b) =>
              compareNullableLast(a.total, b.total, descending: true),
          'amountAsc' => (a, b) => compareNullableLast(a.total, b.total),
          'customer' => (a, b) => compareText(customerOf(a), customerOf(b)),
          _ => (a, b) => compareNullableLast(
              _millis(a.trxDate), _millis(b.trxDate),
              descending: true),
        });
  }
}

// ─────────────────────────── ডেলিভারি ট্র্যাকিং ───────────────────────────

/// শুধু সাজানো — ধাপের chip আর খোঁজ সার্ভারে যায়, এটা হাতে আসা সারিগুলোর ক্রম
/// বদলায় মাত্র। তারিখ সার্ভার পাঠায় `yyyy-MM-dd` (SaleTracking::toDateString)।
class TrackingListQuery {
  const TrackingListQuery._();

  static const sortOptions = [
    _dateDesc,
    _dateAsc,
    _amountDesc,
    _amountAsc,
    _customerAsc,
  ];
  static const defaultSort = 'dateDesc';

  static int? _when(TrackedSale sale) =>
      _millis(sale.date == null ? null : DateTime.tryParse(sale.date!));

  static List<TrackedSale> apply(Iterable<TrackedSale> rows,
      {String sort = defaultSort}) {
    return sortStable(
        rows,
        switch (sort) {
          'dateAsc' => (a, b) => compareNullableLast(_when(a), _when(b)),
          'amountDesc' => (a, b) => b.total.compareTo(a.total),
          'amountAsc' => (a, b) => a.total.compareTo(b.total),
          // দোকানের নাম না থাকলে শেষে।
          'customer' => (a, b) => a.customer == null || b.customer == null
              ? (a.customer == null ? 1 : 0) - (b.customer == null ? 1 : 0)
              : compareText(a.customer!, b.customer!),
          _ => (a, b) =>
              compareNullableLast(_when(a), _when(b), descending: true),
        });
  }
}
