import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/dashboard/dashboard_api.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/orders/delivery_order_api.dart';
import 'package:abos_mobile/core/orders/direct_sale_api.dart';
import 'package:abos_mobile/core/orders/order_api.dart';
import 'package:abos_mobile/core/orders/tracking_api.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_palette.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/app_status_pill.dart';
import 'package:abos_mobile/core/widgets/customer_line.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/core/widgets/list_controls.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';
import 'package:abos_mobile/features/dashboards/dashboards_screen.dart';
import 'package:abos_mobile/features/delivery_orders/delivery_order_screens.dart';
import 'package:abos_mobile/features/direct_sale/counter_screen.dart';
import 'package:abos_mobile/features/direct_sale/return_screen.dart';
import 'package:abos_mobile/features/home/home_dashboard.dart';
import 'package:abos_mobile/features/home/module_pages.dart';
import 'package:abos_mobile/features/home/nav_search.dart';
import 'package:abos_mobile/features/orders/delivery_tracking_screen.dart';
import 'package:abos_mobile/features/orders/new_order_screen.dart';
import 'package:abos_mobile/features/orders/order_sheets.dart';

import 'return_screen_test.dart' show FakeReturnApi;
import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⭐ a5-এর ১৪টা পর্দা নকশার নিয়ম মানে (০.৪.৩৮, a4, ১৯ অক্টোবর ২০২৬; `E:\ABOS\app-0438-নকশার-নিয়ম.md`) — মালিকের আদেশ
/// "100% international standard and enterprise grade" (১৯ অক্টোবর ২০২৬, fe-র মারফত)।
///
/// <p>তিনটা দাবি, প্রতিটা পর্দায়:
/// - §১ রং: আলো আর অন্ধকার দুই থিমে কোনো লেখা, আইকন বা পট বাঁধা সাদা/কালো/AppColors.surface/onSurface নয় — যেখানে
///   থিমের ভূমিকা অন্য রং বলে। ⓘ নিজের রঙিন পটের উপর বাঁধা লেখা (মডিউলের টাইলে সাদা আইকন, সবুজ চিপে গাঢ় সবুজ) জোড়া রং —
///   দুই মোডে একই পড়া যায়, তাই ছাড়।
/// - §২ মাপ: চাপা যায় এমন সব কিছু (InkWell, IconButton, বোতাম, চিপ, ListTile) অন্তত ৪৮×৪৮; তালিকার পর্দায় সারি অন্তত ৭২।
/// - §২ বড় অক্ষর: ২০০% অক্ষরে কোনো RenderFlex ছাপিয়ে যায় না।
///
/// <p>আর উৎসের পাহারা: এই ফাইলগুলোয় `Colors.white`/`black`/`grey`, `AppColors.surface`, `AppColors.onSurface` আর ফিরে আসবে না।

// ── থিম ──────────────────────────────────────────────────────────────────

/// ⓘ a4-এর দুই থিম (c5e7fbbd6): অন্ধকারে context.palette আর colorScheme দুটোই অন্ধকারের রং দেয়
final _themes = <String, ThemeData Function()>{'আলো': AppTheme.light, 'অন্ধকার': AppTheme.dark};

Set<Color> _roles(ColorScheme cs) => {
      cs.primary, cs.onPrimary, cs.primaryContainer, cs.onPrimaryContainer,
      cs.secondary, cs.onSecondary, cs.secondaryContainer, cs.onSecondaryContainer,
      cs.tertiary, cs.onTertiary, cs.tertiaryContainer, cs.onTertiaryContainer,
      cs.error, cs.onError, cs.errorContainer, cs.onErrorContainer,
      cs.surface, cs.onSurface, cs.onSurfaceVariant, cs.surfaceDim, cs.surfaceBright,
      cs.surfaceContainerLowest, cs.surfaceContainerLow, cs.surfaceContainer,
      cs.surfaceContainerHigh, cs.surfaceContainerHighest,
      cs.outline, cs.outlineVariant, cs.inverseSurface, cs.onInverseSurface, cs.inversePrimary,
      cs.shadow, cs.scrim, cs.surfaceTint,
    };

/// নিয়মের নিষেধের তালিকা (§১)
const _fixed = <Color>[
  Colors.white,
  Colors.black,
  AppColors.surface,
  AppColors.onSurface,
  AppColors.surfaceMuted,
  AppColors.onSurfaceMuted,
  AppColors.border,
  AppColors.primarySoft,
];

/// ⓘ a4-এর সাধারণ widget (lib/core/widgets) — সেগুলোর রং a4 নিজে বদলাচ্ছে (a4/app-0438), এই পরীক্ষা কেবল a5-এর পর্দার কোড মাপে
bool _sharedWidget(Widget w) => w is ListControls || w is EmptyState || w is AppStatusPill || w is CustomerLine;

// ── নকল API (আগের পরীক্ষার নকলের ধাঁচে) ─────────────────────────────────────

class _Tracking implements TrackingApi {
  static const sale = TrackedSale(
    kind: 'challan', id: 'c1', no: 'S-0007', documentNo: 'CH-2610-0007', date: '2026-10-02',
    customer: 'মেসার্স রহমান ট্রেডার্স · ফুলপুর · ময়মনসিংহ', total: 125000, step: 'gate_out', billed: true,
    backOrder: true,
  );

  @override
  Future<TrackingList> list({String? query, String? step}) async =>
      const TrackingList([sale], {'all': 1, 'gate_out': 1});

  @override
  Future<(TrackedSale, List<TrackingEvent>, List<Milestone>)> story(TrackedSale s) async => (
        sale,
        [TrackingEvent(at: DateTime(2026, 10, 2, 11), step: 'gate_out', by: 'গেটম্যান', text: 'গেট পাস GP-3')],
        const [
          Milestone(key: 'order_created', label: 'অর্ডার তৈরি', category: 'approved', state: 'done'),
          Milestone(key: 'delivered', label: 'পৌঁছেছে', category: 'delivered', state: 'current'),
          Milestone(key: 'billed', label: 'বিল', category: 'billed', state: 'todo'),
        ],
      );
}

class _Orders implements DeliveryOrderApi {
  @override
  bool get forOrders => false;

  static const current = DeliveryOrder(
    id: 'do-1', no: 'DO-2610-0001', date: '2026-10-03', customer: 'মেসার্স রহমান ট্রেডার্স · ফুলপুর',
    status: 'x', statusLabel: 'সুপারভাইজারের অপেক্ষায়', total: 125000, editable: false, awaitingMe: true,
    approvalId: 'ap-1',
    lines: [
      DeliveryOrderLine(id: 7, product: 'কসমস বিস্কুট', qty: 10, approvedQty: null, finalQty: 10, lineTotal: 400),
    ],
  );

  @override
  Future<(List<DeliveryOrder>, PaperTotal?)> list({bool awaitingMe = false}) async =>
      ([current], const PaperTotal(sum: 125000, count: 1));

  @override
  Future<DeliveryOrder> show(String id) async => current;

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Books implements BooksApi {
  @override
  Future<MoneyInPage> moneyIn(
          {required String from, required String to, String? q, String? method, int page = 1}) async =>
      const MoneyInPage(
        rows: [
          MoneyInRow(
              id: 'c1', no: 'RCV-2610-0002', date: '2026-10-06', customer: 'মেসার্স রহমান ট্রেডার্স',
              account: 'প্রধান নগদ', method: 'cash', amount: 125000),
        ],
        total: 125000,
        count: 1,
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _Counter implements DirectSaleApi {
  @override
  Future<CounterSetup> setup({String? warehouseId}) async => const CounterSetup(
        warehouseId: 'wh-1',
        warehouses: [CounterChoice('wh-1', 'প্রধান গুদাম')],
        paymentTerms: [CounterChoice('cash', 'নগদ')],
        moneyAccounts: [CounterChoice('ac-1', '1101 · নগদ', kind: 'cash')],
        depositMethods: [],
        carriers: [],
        farePayers: [],
        voidReasons: [],
        lots: {},
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

class _OrderApi implements OrderApi {
  @override
  Future<List<OfferLine>> offers(String customerId, List<OfferAsk> lines) async => [
        for (final l in lines) OfferLine(productId: l.productId, freeQty: 0, offer: null),
      ];

  @override
  Future<CustomerStanding> standing(String customerId, double orderTotal) async => const CustomerStanding(
      due: 48200, advance: 0, pendingClaims: 1500, pendingClaimCount: 2, limit: 50000, orderTotal: 4820,
      toPay: 3020, overLimit: true);
}

class _Memory extends OrderMemory {
  @override
  Map<String, int> lastFor(String customerId) => const {};

  @override
  Future<void> remember(String customerId, Map<String, int> lines) async {}
}

const _inventory = ModuleDashboard({
  'title': 'মজুদ',
  'subtitle': 'গুদামের অবস্থা',
  'stats': [
    {'label': 'মজুদের মূল্য', 'value': null, 'hidden': true, 'hint': 'কেনা দরে', 'tone': 'neutral'},
    {'label': 'মেয়াদ পেরোনো', 'value': '12,40,500.00', 'hidden': false, 'hint': '', 'tone': 'bad'},
  ],
  'panels': [
    {
      'kind': 'series',
      'label': 'মাসে আসা-যাওয়া',
      'firstLabel': 'এসেছে',
      'secondLabel': 'গেছে',
      'points': [
        {'label': 'Sep', 'first': '12,000.00', 'second': '8,000.00'},
      ],
    },
  ],
  'listings': [
    {
      'label': 'কম মজুদ',
      'empty': 'সব ঠিক আছে',
      'columns': [
        {'key': 'name', 'label': 'পণ্য'},
        {'key': 'available', 'label': 'বিক্রয়যোগ্য'}
      ],
      'rows': [
        ['সাবান', '2']
      ],
    },
  ],
});

final _sales = NavModule(def: NavCatalog.moduleDef('sales')!, groups: const [
  NavGroup('লেনদেন', [
    NavRow(id: 'new-order', label: 'নতুন অর্ডার', icon: Icons.add, module: 'sales', path: 'new-order'),
    NavRow(id: 'later', label: 'রুট পরিকল্পনা', icon: Icons.map, module: 'sales', planned: true),
  ]),
]);
final _coming = NavModule(def: NavCatalog.moduleDef('purchase')!, groups: const [
  NavGroup('শীঘ্রই আসছে', [NavRow(id: 'po', label: 'ক্রয় আদেশ', icon: Icons.add, module: 'purchase', planned: true)]),
]);

// ── পর্দাগুলো ─────────────────────────────────────────────────────────────

class _Case {
  const _Case(this.name, this.build, {this.rows72 = false, this.settle = true});

  final String name;
  final Widget Function() build;

  /// §২: তালিকার পর্দা — চাপা যায় এমন প্রতিটা ListTile অন্তত ৭২
  final bool rows72;

  /// নতুন অর্ডারের পর্দা অফারের টাইমার চালায় — pumpAndSettle নয়, কয়েকটা pump
  final bool settle;
}

const _shop = 'c-0001';
const _soap = 'p-soap';

final _cases = <_Case>[
  _Case('হোম', () => Scaffold(
        body: HomeDashboard(
          user: const AuthUser(id: 'u1', name: 'করিম', email: 'k@x', roles: [], permissions: []),
          items: const [],
          fetchToday: () async => const TodayRecord({}),
          search: NavSearchBox(onTap: () {}),
          favourites: const [
            NavRow(id: 'new-order', label: 'নতুন অর্ডার', icon: Icons.add, module: 'sales', path: 'new-order'),
            NavRow(id: 'dues', label: 'বকেয়া তালিকা', icon: Icons.money, module: 'customer', path: 'dues'),
          ],
          recents: const [RecentEntry(path: 'orders', label: 'অর্ডারের অবস্থা')],
          onOpen: (_, __) {},
        ),
      )),
  _Case('সব মডিউল', () => Scaffold(
        body: AllModulesPage(
          modules: [_sales, _coming],
          counts: const NavCounts(byModule: {'sales': 3}),
          onOpen: (_) {},
          onRefresh: () async {},
          header: NavSearchBox(onTap: () {}),
        ),
      )),
  _Case('মডিউলের মেনু', () => Scaffold(
        body: ModuleMenuPage(
          crumb: 'সব মডিউল',
          onCrumb: () {},
          groups: _sales.groups,
          counts: const NavCounts(byPath: {'new-order': 2}, quietByPath: {'new-order': 'পাঠানোর অপেক্ষায় ২টি'}),
          favourites: const {'new-order'},
          onRow: (_) {},
          onFavourite: (_) {},
          note: 'এই মডিউলের পর্দা শীঘ্রই আসছে',
        ),
      )),
  _Case('ড্যাশবোর্ডের তালিকা', () => DashboardsScreen(
        loadList: () async => const [
          DashboardEntry({
            'module': 'inventory',
            'name': 'মজুদ',
            'stat': {'label': 'মজুদের মূল্য', 'value': '81,25,000.00', 'hidden': false}
          }),
        ],
        openModule: (_) async => _inventory,
      ), rows72: true),
  _Case('মডিউলের ড্যাশবোর্ড', () => ModuleDashboardScreen(module: 'inventory', name: 'মজুদ', open: (_) async => _inventory)),
  _Case('বিক্রয়ের হালচাল', () => SalesOverviewScreen(open: (_, __) async => _inventory, today: DateTime(2026, 10, 19))),
  _Case('ডেলিভারি ট্র্যাকিং', () => DeliveryTrackingScreen(api: _Tracking())),
  _Case('ডিও তালিকা', () => DeliveryOrderListScreen(api: _Orders()), rows72: true),
  _Case('একটা ডিও', () => DeliveryOrderScreen(id: 'do-1', api: _Orders())),
  _Case('নতুন ডিও', () => NewDeliveryOrderScreen(
      api: _Orders(),
      customers: const [CustomerRecord({'id': 'cus-1', 'nameBn': 'রহিম স্টোর'})],
      products: const [])),
  _Case('কাউন্টার', () => CounterScreen(
      api: _Counter(),
      returnApi: FakeReturnApi(),
      customers: const [CustomerRecord({'id': 'cus-1', 'nameBn': 'রহিম স্টোর'})],
      products: const [ProductRecord({'id': 'prd-1', 'nameBn': 'কসমস বিস্কুট', 'salePrice': '40'})])),
  _Case('বিক্রি ফেরত', () => ReturnScreen(customerId: 'cus-1', api: FakeReturnApi()), rows72: true),
  _Case('টাকা আদায়', () => MoneyInListScreen(api: _Books(), today: DateTime(2026, 10, 6)), rows72: true),
  _Case('বকেয়া তালিকা', () => const DueListScreen(), rows72: true),
  _Case('নতুন অর্ডার', () => NewOrderScreen(
      api: _OrderApi(), memory: _Memory(), offerDelay: const Duration(milliseconds: 10), enqueue: (_) async {}),
      settle: false),
  _Case('ঘটির চাবি', () => const Scaffold(
      body: QtyKeypadSheet(
          productName: 'সাবান ১০০ গ্রাম', current: 50, rate: 35, offer: '১২টা কিনলে ১টা ফ্রি',
          cartonName: 'কার্টন', cartonFactor: 48, lastQty: 96))),
  _Case('অর্ডারের ফিল্টার', () => Scaffold(
      body: OrderFilterSheet(current: const OrderFilter(offerOnly: true), countFor: (_) => 12))),
  _Case('পাঠানোর আগে', () => Scaffold(
      body: OrderConfirmSheet(
          lines: const [ConfirmLine(name: 'সাবান ১০০ গ্রাম', qty: 24, free: 2, taka: 840)],
          standing: _OrderApi().standing(_shop, 840)))),
];

// ── মাপার যন্ত্র ───────────────────────────────────────────────────────────

Future<void> _pump(WidgetTester tester, _Case c, ThemeData theme, {double scale = 1}) async {
  // ⓘ সাধারণ অ্যান্ড্রয়েড ফোনের চওড়া (৩৬০dp); লম্বা — যাতে তালিকার প্রথম সারিগুলো আঁকা হয়
  tester.view.physicalSize = const Size(360, 2000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const SizedBox());
  await tester.pumpWidget(MaterialApp(
    theme: theme,
    builder: (context, child) => MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale)),
      child: child!,
    ),
    home: c.build(),
  ));
  if (c.settle) {
    await tester.pumpAndSettle();
  } else {
    for (var i = 0; i < 5; i++) {
      await tester.pump(const Duration(milliseconds: 20));
    }
  }
}

bool _isPad(Widget w) => w is Container || w is Material || w is Card || w is DecoratedBox || w is ColoredBox;

Color? _padColour(Widget w) => switch (w) {
      Container(:final color?) => color,
      Container(:final BoxDecoration decoration) => decoration.color,
      Material(:final color) => color,
      Card(:final color) => color,
      DecoratedBox(:final BoxDecoration decoration) => decoration.color,
      ColoredBox(:final color) => color,
      _ => null,
    };

Iterable<Color> _ownColours(Widget w) sync* {
  if (w is Text) {
    if (w.style?.color != null) yield w.style!.color!;
    final span = w.textSpan;
    if (span != null) {
      final colours = <Color>[];
      span.visitChildren((s) {
        final c = s.style?.color;
        if (c != null) colours.add(c);
        return true;
      });
      yield* colours;
    }
  } else if (w is Icon) {
    if (w.color != null) yield w.color!;
  } else if (_isPad(w)) {
    final c = _padColour(w);
    if (c != null) yield c;
  }
}

/// §১ — বাঁধা রং যা থিমের পটে বসে আছে, তার তালিকা
List<String> _fixedColours(WidgetTester tester, ThemeData theme) {
  // ⓘ থিমের নিজের ঠিক করা রং (পাতার জমি, কার্ড, মাথা) — সেটা থিম থেকেই আসে, পর্দার বাঁধা রং নয়
  final palette = theme.extension<AppPalette>() ?? AppPalette.light;
  final roles = {
    // ⓘ a4-এর context.palette — থিম থেকেই আসে (আলোতে আগের AppColors-এর হুবহু, অন্ধকারে অন্ধকারের রং)
    palette.surface, palette.surfaceMuted, palette.onSurface, palette.onSurfaceMuted, palette.border,
    palette.primarySoft,
    ..._roles(theme.colorScheme),
    theme.scaffoldBackgroundColor,
    theme.canvasColor,
    theme.cardColor,
    if (theme.cardTheme.color != null) theme.cardTheme.color!,
    if (theme.appBarTheme.backgroundColor != null) theme.appBarTheme.backgroundColor!,
    if (theme.appBarTheme.foregroundColor != null) theme.appBarTheme.foregroundColor!,
    if (theme.inputDecorationTheme.fillColor != null) theme.inputDecorationTheme.fillColor!,
  };
  final banned = _fixed.where((c) => !roles.contains(c)).toSet();
  final found = <String>[];
  for (final e in find.byType(Scaffold).first.evaluate().expand((root) {
    final all = <Element>[root];
    void walk(Element el) => el.visitChildren((child) {
          all.add(child);
          walk(child);
        });
    walk(root);
    return all;
  })) {
    var shared = false;
    e.visitAncestorElements((a) {
      if (_sharedWidget(a.widget)) shared = true;
      return !shared;
    });
    if (shared || _sharedWidget(e.widget)) continue;
    for (final colour in _ownColours(e.widget)) {
      if (!banned.contains(colour)) continue;
      // ⓘ নিজের রঙিন পটের উপর জোড়া রং হলে ছাড় — পট বাঁধা, থিমের নয়, নিষেধের তালিকারও নয়
      Color? under;
      e.visitAncestorElements((a) {
        if (!_isPad(a.widget)) return true;
        final c = _padColour(a.widget);
        if (c == null || c.a == 0) return true;
        under = c;
        return false;
      });
      final pairedOnTint = under != null && !roles.contains(under) && !_fixed.contains(under);
      if (!pairedOnTint) {
        found.add('${e.widget.runtimeType} ${colour.toARGB32().toRadixString(16)} '
            '(${e.widget is Text ? (e.widget as Text).data ?? (e.widget as Text).textSpan?.toPlainText() : ''})');
      }
    }
  }
  return found;
}

bool _insideControl(Element e) {
  var inside = false;
  e.visitAncestorElements((a) {
    final w = a.widget;
    if (w is ButtonStyleButton || w is IconButton || w is ListTile || w is RawChip || w is ChoiceChip ||
        w is InputChip || w is DropdownButton || w is PopupMenuButton || w is TextField) {
      inside = true;
      return false;
    }
    return true;
  });
  return inside;
}

/// §২ — ৪৮-এর ছোট চাপার জায়গা (আর তালিকায় ৭২-এর ছোট সারি)
List<String> _smallTargets(WidgetTester tester, {required bool rows72}) {
  final small = <String>[];
  final targets = find.byWidgetPredicate((w) =>
      (w is InkWell && (w.onTap != null || w.onLongPress != null)) ||
      (w is IconButton && w.onPressed != null) ||
      (w is ButtonStyleButton && w.onPressed != null) ||
      (w is ListTile && w.onTap != null && w.enabled) ||
      (w is ChoiceChip && w.onSelected != null) ||
      (w is InputChip && (w.onPressed != null || w.onDeleted != null)));
  for (final e in targets.evaluate()) {
    if (e.widget is InkWell && _insideControl(e)) continue;
    final box = e.renderObject;
    if (box is! RenderBox || !box.hasSize) continue;
    final size = box.size;
    final label = '${e.widget.runtimeType}${e.widget.key == null ? '' : ' ${e.widget.key}'} '
        '${size.width.toStringAsFixed(0)}×${size.height.toStringAsFixed(0)}';
    if (size.width < 48 || size.height < 48) small.add(label);
    if (rows72 && e.widget is ListTile && size.height < 72) small.add('সারি < ৭২: $label');
  }
  return small;
}

// ── উৎসের পাহারা ──────────────────────────────────────────────────────────

const _files = [
  'lib/features/home/home_dashboard.dart',
  'lib/features/home/home_shell.dart',
  'lib/features/home/module_pages.dart',
  'lib/features/home/nav_search.dart',
  'lib/features/dashboards/dashboards_screen.dart',
  'lib/features/orders/new_order_screen.dart',
  'lib/features/orders/order_sheets.dart',
  'lib/features/orders/delivery_tracking_screen.dart',
  'lib/features/orders/order_list_screen.dart',
  'lib/features/delivery_orders/delivery_order_screens.dart',
  'lib/features/direct_sale/counter_screen.dart',
  'lib/features/direct_sale/return_screen.dart',
  'lib/features/books/money_in_screens.dart',
  'lib/features/customers/due_list_screen.dart',
];

/// ⓘ a4-এর তালিকা (482b2798d, ১৯ অক্টোবর ২০২৬): Colors.white/black/grey আর AppColors.surface/onSurface/surfaceMuted/
/// onSurfaceMuted/border/primarySoft — সবই বাঁধা রং
final _banned = RegExp(
    r'Colors\.(white|black|grey)|AppColors\.(surface|onSurface|surfaceMuted|onSurfaceMuted|border|primarySoft)\b');

/// মন্তব্য বাদ দিয়ে কোড — মন্তব্যে নিয়মের কথা লেখা থাকতে পারে। ⓘ `// dark-ok` লেখা লাইন পুরো বাদ: দুই মোডেই ঠিক
/// এমন বাঁধা রং (মডিউলের গাঢ় পটে সাদা আইকন) — a4-এর নিয়ম
String _code(String source) => source
    .split('\n')
    .map((line) {
      if (line.contains('// dark-ok')) return '';
      final i = line.indexOf('//');
      return i < 0 ? line : line.substring(0, i);
    })
    .join('\n');

void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
    await ReferenceCache.instance.clearAll();
    final now = DateTime(2026, 10, 1);
    Future<void> put(String type, String id, Map<String, dynamic> payload) =>
        ReferenceCache.instance.put(entityType: type, entityId: id, updatedAt: now, payload: payload);
    await put('Customer', _shop, const {
      'id': _shop,
      'nameBn': 'মেসার্স রহমান ট্রেডার্স অ্যান্ড সন্স',
      'pointName': 'ফুলপুর',
      'branchName': 'ময়মনসিংহ',
      'addressBn': 'নেত্রকোনা',
    });
    await put('CustomerDue', _shop, const {
      'customerId': _shop,
      'outstanding': '1240500.0000',
      'creditLimit': '50000.0000',
      'creditDays': 15,
    });
    await put('Product', _soap, const {
      'id': _soap,
      'code': 'SOAP100',
      'nameBn': 'সাবান ১০০ গ্রাম',
      'salePrice': '35.0000',
      'packs': [
        {'unitNameBn': 'কার্টন', 'factor': '48'},
      ],
      'isActive': true,
    });
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  for (final c in _cases) {
    for (final t in _themes.entries) {
      testWidgets('${c.name} · ${t.key}: রং থিম থেকে, চাপার জায়গা ৪৮', (tester) async {
        final theme = t.value();
        // ⓘ কিছু ছাপিয়ে গেলে flutter_test নিজেই পরীক্ষা ফেল করায়, কোন widget কোন লাইনে — পুরো বিবরণসহ
        await _pump(tester, c, theme);

        expect(_fixedColours(tester, theme), isEmpty,
            reason: '⛔ থিমের পটে বাঁধা রং — অন্ধকার/আলো মোডে ভাঙে (নকশার নিয়ম ০.৪.৩৮ §১)');
        expect(_smallTargets(tester, rows72: c.rows72), isEmpty,
            reason: '⛔ চাপার জায়গা ৪৮×৪৮-এর ছোট, বা তালিকার সারি ৭২-এর ছোট (নকশার নিয়ম ০.৪.৩৮ §২)');
      });
    }

    testWidgets('${c.name} · ২০০% অক্ষর: কিছু ছাপিয়ে যায় না', (tester) async {
      // ⛔ বড় অক্ষরে লেখা ভাঙলে (RenderFlex overflow) flutter_test নিজেই ফেল করায়, কোন widget কোন লাইনে বলে দিয়ে
      // (নকশার নিয়ম ০.৪.৩৮ §২: বাঁধা উচ্চতায় লেখা নয়)
      await _pump(tester, c, AppTheme.light(), scale: 2);
      expect(find.byType(Scaffold), findsWidgets);
    });
  }

  test('the source guard: none of my 14 files names a fixed colour, except a line marked dark-ok', () {
    final hits = <String>[];
    for (final path in _files) {
      final lines = _code(File(path).readAsStringSync()).split('\n');
      for (var i = 0; i < lines.length; i++) {
        if (_banned.hasMatch(lines[i])) hits.add('$path:${i + 1}: ${lines[i].trim()}');
      }
    }
    expect(hits, isEmpty, reason: '⛔ বাঁধা রং ফিরে এসেছে — Theme.of(context).colorScheme নিন (নকশার নিয়ম ০.৪.৩৮ §১)');
  });

  test('the source guard itself catches a fixed colour put back', () {
    // ⓘ পাহারা নিজে অন্ধ নয় — ঠিক এই লেখাগুলো ধরে, মডিউলের রং ছাড়ে
    for (final bad in [
      "color: Colors.white,",
      "color: Colors.black54,",
      "color: Colors.grey,",
      "backgroundColor: AppColors.surface,",
      "color: AppColors.onSurface)",
      "color: AppColors.onSurfaceMuted,",
      "fillColor: AppColors.surfaceMuted,",
      "side: BorderSide(color: AppColors.border),",
      "indicatorColor: AppColors.primarySoft,",
    ]) {
      expect(_banned.hasMatch(_code(bad)), isTrue, reason: bad);
    }
    for (final ok in [
      "color: AppColors.moduleSolid('sales'),",
      "color: AppColors.onPrimary, // dark-ok",
      "color: cs.onSurface,",
      "// ⛔ আগে Colors.white",
    ]) {
      expect(_banned.hasMatch(_code(ok)), isFalse, reason: ok);
    }
  });
}
