import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../locale/app_locale.dart';
import '../locale/l10n.dart';
import '../menu/menu_item.dart';
import '../theme/app_colors.dart';

/// ⭐ মডিউল → মেনু → পর্দা — অ্যাপের নতুন পথ (মালিক, ১২ অক্টোবর ২০২৬: "app icon e tap korle age module
/// asbe, module e dhukle tar menu, menu r vitore kichu thakle vitore dekhabe")।
///
/// <p><b>Which doors a person sees is not decided here.</b> The tree is built
/// from the very list the old grid drew — `/me`'s menu through
/// [RouteRegistry], the phone's own permission-keyed tiles
/// ([MenuRepository]), and the company's phone switches ([ModuleGate]) — so a
/// tile or a row exists only when that list already let the person open the
/// screen. This file only says *where* each door hangs: which module, under
/// which heading, with which words a search should find it.
///
/// <p>Three levels and never more: all modules → a module's menu → the screen;
/// a row with children ([NavRow.children]) opens one short sub-list first.

/// One module tile — the web's own module codes and rail colours.
class NavModuleDef {
  const NavModuleDef(this.code, this.label, this.icon, [this.aliases = const []]);

  final String code;
  final String label;
  final IconData icon;
  final List<String> aliases;

  Color get color => AppColors.moduleColor(code);
}

/// Where one `/home/<path>` screen hangs, and the words that find it.
class NavScreenDef {
  const NavScreenDef({
    required this.path,
    required this.module,
    required this.group,
    required this.label,
    required this.icon,
    this.aliases = const [],
    this.parent,
  });

  final String path;
  final String module;
  final String group;

  /// Used when the menu item carries no label of its own.
  final String label;
  final IconData icon;
  final List<String> aliases;

  /// The [NavParentDef.id] this screen sits under, when it is one of several
  /// close relatives gathered behind one row.
  final String? parent;
}

/// A menu row that opens a short sub-list rather than a screen.
class NavParentDef {
  const NavParentDef({
    required this.id,
    required this.module,
    required this.group,
    required this.label,
    required this.icon,
  });

  final String id;
  final String module;
  final String group;
  final String label;
  final IconData icon;
}

/// One row of a module's menu — a screen ([path] set), a parent with
/// [children], or a "coming soon" row ([planned]).
class NavRow {
  const NavRow({
    required this.id,
    required this.label,
    required this.icon,
    required this.module,
    this.path,
    this.children = const [],
    this.planned = false,
    this.aliases = const [],
    this.web = false,
  });

  /// The path for a screen, `group:<id>` for a parent, the server route for a
  /// planned row — unique within the tree.
  final String id;
  final String label;
  final IconData icon;
  final String module;

  /// The `/home/<path>` this row opens; null for a parent or a planned row.
  final String? path;
  final List<NavRow> children;
  final bool planned;
  final List<String> aliases;

  /// ⭐ The web's own screen, opened inside the app ([WebPaths]) — the row says so under its name.
  final bool web;

  bool get isParent => children.isNotEmpty;
}

class NavGroup {
  const NavGroup(this.title, this.rows);

  final String title;
  final List<NavRow> rows;
}

class NavModule {
  const NavModule({required this.def, required this.groups});

  final NavModuleDef def;
  final List<NavGroup> groups;

  String get code => def.code;
  String get label => def.label;
  Color get color => def.color;

  /// False when every row is "শীঘ্রই আসছে" — the tile is drawn greyed.
  bool get live => screens.isNotEmpty;

  /// Every screen this module opens, children included — never a planned row.
  Iterable<NavRow> get screens sync* {
    for (final group in groups) {
      for (final row in group.rows) {
        if (row.path != null) yield row;
        yield* row.children.where((c) => c.path != null);
      }
    }
  }
}

class NavCatalog {
  const NavCatalog._();

  /// The words of the person's language now ([AppLocale.current]).
  static AppL10n get _l => AppLocale.l10n;

  /// ⓘ The lists below are words, so they are built in the person's language — once per language, not per call
  /// ([NavTree.from] sorts by [screens] many times over).
  static final Map<String, Object> _built = {};
  static String? _builtFor;

  static T _once<T extends Object>(String name, T Function() build) {
    if (_builtFor != AppLocale.code) {
      _built.clear();
      _builtFor = AppLocale.code;
    }
    return _built.putIfAbsent(name, build) as T;
  }

  static String get plannedGroup => _l.navGroupComingSoon;

  /// The order the tiles stand in — the owner's mockup (skin 1, picture 2).
  static List<NavModuleDef> get modules => _once('modules', () => [
    // ⓘ ওয়েবের মডিউলের একই নাম ("বিক্রয়", Sales lang/bn/route.php) আর মালিকের অনুমোদিত নমুনার — বারের "› বিক্রয় › …"
    // আর মডিউলের ঘর এক নামে (a5, ২০ অক্টোবর ২০২৬); "বিক্রি" খোঁজায় থাকে
    NavModuleDef('sales', _l.navModuleSales, Icons.shopping_cart_outlined,
        ['বিক্রি', 'sales', 'sale', 'বিক্রয়']),  // l10n-ok: search aliases in both languages
    NavModuleDef('purchase', _l.navModulePurchase, Icons.archive_outlined,
        ['কেনা', 'purchase', 'ক্রয়']),  // l10n-ok: search aliases in both languages
    NavModuleDef('inventory', _l.navModuleInventory, Icons.view_in_ar_outlined,
        ['স্টক', 'inventory', 'stock', 'মজুদ']),  // l10n-ok: search aliases in both languages
    NavModuleDef('accounts', _l.navModuleAccounts, Icons.description_outlined,
        ['accounts', 'হিসাব']),  // l10n-ok: search aliases in both languages
    NavModuleDef('finance', _l.navModuleFinance, Icons.account_balance_outlined,
        ['finance', 'ব্যাংক', 'অর্থ']),  // l10n-ok: search aliases in both languages
    NavModuleDef('customer', _l.navModuleCustomer, Icons.person_outline,
        ['customer', 'দোকান', 'customers', 'গ্রাহক']),  // l10n-ok: search aliases in both languages
    NavModuleDef('supplier', _l.navModuleSupplier, Icons.local_shipping_outlined,
        ['supplier', 'প্রিন্সিপাল', 'suppliers', 'সরবরাহকারী']),  // l10n-ok: search aliases in both languages
    NavModuleDef('approval', _l.navModuleApproval, Icons.check_rounded,
        ['approval', 'সই', 'approvals', 'অনুমোদন']),  // l10n-ok: search aliases in both languages
    NavModuleDef('hr', _l.navModuleHr, Icons.people_outline,
        ['hr', 'staff', 'কর্মী']),  // l10n-ok: search aliases in both languages
    NavModuleDef('reports', _l.navModuleReports, Icons.bar_chart_rounded,
        ['report', 'reports', 'রিপোর্ট']),  // l10n-ok: search aliases in both languages
    NavModuleDef('documents', _l.navModuleDocuments, Icons.folder_outlined,
        ['documents', 'ডকুমেন্ট', 'নথি']),  // l10n-ok: search aliases in both languages
    NavModuleDef('executive', _l.navModuleExecutive, Icons.speed_outlined,
        ['executive', 'command', 'owner\'s centre', 'মালিকের কেন্দ্র']),  // l10n-ok: search aliases in both languages
    // ── ⭐ বাকি মডিউল, দ্বিতীয় ভাগ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬) ──
    NavModuleDef('promotion', _l.navModulePromotion, Icons.local_offer_outlined,
        ['promotion', 'offer', 'অফার', 'প্রমোশন']),  // l10n-ok: search aliases in both languages
    // ── ⭐ ফোনের নতুন মডিউল, ১৩ অক্টোবর ২০২৬ (মালিক: "app e baki module & menu") ──
    NavModuleDef('credit_control', _l.navModuleCreditControl, Icons.credit_score_outlined,
        ['credit', 'বাকি', 'সীমা', 'limit', 'credit control', 'বাকি নিয়ন্ত্রণ']),  // l10n-ok: search aliases in both languages
    NavModuleDef('notification', _l.navModuleNotification, Icons.campaign_outlined,
        ['notice', 'নোটিশ', 'বিজ্ঞপ্তি', 'notices']),  // l10n-ok: search aliases in both languages
      ]);

  /// The order the headings stand in, inside any module.
  static List<String> get groupOrder => _once('groupOrder', () => [
    _l.navGroupTransactions,
    _l.navGroupFieldWork,
    _l.navGroupLists,
    _l.navGroupMyWork,
    _l.navGroupReports,
    _l.navGroupDashboards,
    _l.navGroupOther,
    // ── ⭐ ওয়েবের দলগুলো, অ্যাপের ভিতরে (১৩ অক্টোবর ২০২৬) — [webGroupTitle] ──
    _l.navGroupApprovals,
    _l.navGroupSettings,
    plannedGroup,
      ]);

  static List<NavParentDef> get parents => _once('parents', () => [
    NavParentDef(
      id: 'delivery',
      module: 'sales',
      group: _l.navGroupTransactions,
      label: _l.navParentDelivery,
      icon: Icons.local_shipping_outlined,
    ),
      ]);

  /// ⚠️ Every `/home/<path>` the router has a screen for must stand here —
  /// `test/nav_catalog_test.dart` reads the router's source and holds the two
  /// together, so a new screen cannot be added and forgotten by the menu.
  static List<NavScreenDef> get screens => _once('screens', () => [
    // ── বিক্রি ──
    NavScreenDef(
        path: 'new-order',
        module: 'sales',
        group: _l.navGroupTransactions,
        label: _l.navScreenNewOrder,
        icon: Icons.add_shopping_cart_outlined,
        aliases: ['অর্ডার', 'order', 'new order', 'so', 'নতুন অর্ডার']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'orders',
        module: 'sales',
        group: _l.navGroupTransactions,
        label: _l.navScreenOrders,
        icon: Icons.receipt_long_outlined,
        aliases: ['অর্ডার', 'order', 'orders', 'so', 'বিক্রয় আদেশ', 'order status', 'অর্ডারের অবস্থা']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'delivery-orders',
        module: 'sales',
        group: _l.navGroupTransactions,
        label: _l.navScreenDeliveryOrders,
        icon: Icons.assignment_outlined,
        aliases: ['do', 'ডিও', 'delivery order', 'অর্ডার', 'আদেশ', 'delivery orders', 'ডেলিভারি অর্ডার']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'counter',
        module: 'sales',
        group: _l.navGroupTransactions,
        label: _l.navScreenCounter,
        icon: Icons.point_of_sale_outlined,
        aliases: ['কাউন্টার', 'counter', 'direct sale', 'বিক্রি', 'চালান', 'বিল', 'invoice', 'সরাসরি বিক্রয়']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'quotations',
        module: 'sales',
        group: _l.navGroupTransactions,
        label: _l.navScreenQuotations,
        icon: Icons.request_quote_outlined,
        aliases: ['quotation', 'কোটেশন', 'দাম', 'quotations', 'উদ্ধৃতি']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'collections',
        module: 'sales',
        group: _l.navGroupTransactions,
        label: _l.navScreenCollections,
        icon: Icons.payments_outlined,
        aliases: ['আদায়', 'collection', 'টাকা', 'জমা', 'money', 'receipt', 'collections', 'টাকা আদায়']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'deliveries',
        module: 'sales',
        group: _l.navGroupTransactions,
        parent: 'delivery',
        label: _l.navScreenDeliveries,
        icon: Icons.local_shipping_outlined,
        aliases: ['চালান', 'challan', 'delivery', 'ডেলিভারি', 'today\'s deliveries', 'আজকের ডেলিভারি']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'loading',
        module: 'sales',
        group: _l.navGroupTransactions,
        parent: 'delivery',
        label: _l.navScreenLoading,
        icon: Icons.inventory_2_outlined,
        aliases: ['loading', 'লোড', 'trip', 'ট্রিপ', 'loading sheet', 'লোডিং শিট']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'tracking',
        module: 'sales',
        group: _l.navGroupTransactions,
        parent: 'delivery',
        label: _l.navScreenTracking,
        icon: Icons.near_me_outlined,
        aliases: ['tracking', 'ট্র্যাক', 'চালান', 'ডেলিভারি', 'delivery tracking', 'ডেলিভারি ট্র্যাকিং']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'scan',
        module: 'sales',
        group: _l.navGroupTransactions,
        parent: 'delivery',
        label: _l.navScreenScan,
        icon: Icons.qr_code_scanner_outlined,
        aliases: ['scan', 'qr', 'স্ক্যান', 'চালান', 'কাগজ', 'scan a paper', 'কাগজ স্ক্যান']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'my-route',
        module: 'sales',
        group: _l.navGroupFieldWork,
        label: _l.navScreenMyRoute,
        icon: Icons.route_outlined,
        aliases: ['route', 'রুট', 'দোকান', 'today\'s route', 'আজকের রুট']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'leads',
        module: 'sales',
        group: _l.navGroupFieldWork,
        label: _l.navScreenLeads,
        icon: Icons.person_search_outlined,
        aliases: ['lead', 'নতুন দোকান', 'leads', 'লিড']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'today',
        module: 'sales',
        group: _l.navGroupReports,
        label: _l.navScreenToday,
        icon: Icons.insights_outlined,
        aliases: ['today', 'আজ', 'বিক্রি', 'হিসাব', 'today\'s figures', 'আজকের হিসাব']),  // l10n-ok: search aliases in both languages
    // ── ক্রয় ──
    NavScreenDef(
        path: 'purchases',
        module: 'purchase',
        group: _l.navGroupTransactions,
        label: _l.navScreenPurchases,
        icon: Icons.shopping_cart_outlined,
        aliases: ['purchase', 'কেনা', 'বিল', 'grn', 'মাল গ্রহণ', 'purchases', 'ক্রয়']),  // l10n-ok: search aliases in both languages
    // ⭐ প্রিন্সিপাল ক্রয়ের ভিতরে, আলাদা টাইল নয় (মালিক, ১২ অক্টোবর ২০২৬); দরজা ক্রয়ের সুইচেই ([[ModuleGate]])
    NavScreenDef(
        path: 'principals',
        module: 'purchase',
        group: _l.navGroupLists,
        label: _l.navScreenPrincipals,
        icon: Icons.business_outlined,
        aliases: ['principal', 'supplier', 'সরবরাহকারী', 'কোম্পানি', 'principals', 'প্রিন্সিপাল']),  // l10n-ok: search aliases in both languages
    // ── ⭐ সরবরাহকারী — প্রিন্সিপালের কমিশন (মালিকের আদেশ "app e dekhate hobe", fe-র মাধ্যমে; a4-এর নকশার নিয়ম ২;
    // ২০ অক্টোবর ২০২৬)। চাবি সার্ভারের একই `supplier.report` ([MenuRepository]); দরজা ক্রয়ের ফোন-সুইচে, সার্ভারের মতো ([ModuleGate]) ──
    NavScreenDef(
        path: 'principal-commission',
        module: 'supplier',
        group: _l.navGroupReports,
        label: _l.navScreenPrincipalCommission,
        icon: Symbols.percent,
        aliases: ['principal commission', 'commission', 'কমিশন', 'প্রিন্সিপাল', 'দেনা', 'পাওনা', 'প্রিন্সিপালের কমিশন']),  // l10n-ok: search aliases in both languages
    // ── মজুদ ──
    NavScreenDef(
        path: 'products',
        module: 'inventory',
        group: _l.navGroupLists,
        label: _l.navScreenProducts,
        icon: Icons.inventory_2_outlined,
        aliases: ['product', 'পণ্য', 'মাল', 'দাম', 'price', 'products', 'পণ্যের তালিকা']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'stock',
        module: 'inventory',
        group: _l.navGroupLists,
        label: _l.navScreenStock,
        icon: Icons.warehouse_outlined,
        aliases: ['stock', 'মজুদ', 'স্টক', 'stock on hand', 'হাতে থাকা মজুদ']),  // l10n-ok: search aliases in both languages
    // ── হিসাব ──
    NavScreenDef(
        path: 'vouchers',
        module: 'accounts',
        group: _l.navGroupTransactions,
        label: _l.navScreenVouchers,
        icon: Icons.receipt_outlined,
        aliases: ['voucher', 'খরচ', 'হিসাব', 'vouchers', 'ভাউচার']),  // l10n-ok: search aliases in both languages
    // ── ⭐ প্রতিটা ভাউচার-ধরন আলাদা (মালিক, ১৭ অক্টোবর ২০২৬: "cash, mfs, bank, payment, contra, journal, expense egulo
    // alada alada asena") — একই হিসাব মডিউল, একই দলে; `/home/vouchers/<ধরন>` ([VoucherView.kinds]) ──
    NavScreenDef(
        path: 'vouchers/cash-receipt',
        module: 'accounts',
        group: _l.navGroupTransactions,
        label: _l.navScreenCashReceipt,
        icon: Icons.payments_outlined,
        aliases: ['cash', 'receipt', 'রসিদ', 'আদায়', 'নগদ', 'cash receipt', 'নগদ রসিদ']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'vouchers/mfs-receipt',
        module: 'accounts',
        group: _l.navGroupTransactions,
        label: _l.navScreenMfsReceipt,
        icon: Icons.phone_android_outlined,
        aliases: ['mfs', 'bkash', 'nagad', 'বিকাশ', 'রসিদ', 'আদায়', 'mfs receipt', 'MFS রসিদ']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'vouchers/bank-receipt',
        module: 'accounts',
        group: _l.navGroupTransactions,
        label: _l.navScreenBankReceipt,
        icon: Icons.account_balance_outlined,
        aliases: ['bank', 'receipt', 'ব্যাংক', 'রসিদ', 'আদায়', 'bank receipt', 'ব্যাংক রসিদ']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'vouchers/payment',
        module: 'accounts',
        group: _l.navGroupTransactions,
        label: _l.navScreenPayment,
        icon: Icons.outbox_outlined,
        aliases: ['payment', 'পরিশোধ', 'পেমেন্ট']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'vouchers/contra',
        module: 'accounts',
        group: _l.navGroupTransactions,
        label: _l.navScreenContra,
        icon: Icons.swap_horiz_outlined,
        aliases: ['contra', 'কন্ট্রা', 'স্থানান্তর']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'vouchers/journal',
        module: 'accounts',
        group: _l.navGroupTransactions,
        label: _l.navScreenJournal,
        icon: Icons.menu_book_outlined,
        aliases: ['journal', 'জাবেদা', 'জার্নাল']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'vouchers/expense',
        module: 'accounts',
        group: _l.navGroupTransactions,
        label: _l.navScreenExpense,
        icon: Icons.shopping_bag_outlined,
        aliases: ['expense', 'খরচ', 'ব্যয়']),  // l10n-ok: search aliases in both languages
    // ── গ্রাহক ──
    NavScreenDef(
        path: 'customers',
        module: 'customer',
        group: _l.navGroupLists,
        label: _l.navScreenCustomers,
        icon: Icons.people_alt_outlined,
        aliases: ['customer', 'দোকান', 'shop', 'dealer', 'ডিলার', 'customers', 'গ্রাহক']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'dues',
        module: 'customer',
        group: _l.navGroupReports,
        label: _l.navScreenDues,
        icon: Icons.account_balance_wallet_outlined,
        aliases: ['due', 'dues', 'বাকি', 'বকেয়া', 'পাওনা', 'dues list', 'বকেয়া তালিকা']),  // l10n-ok: search aliases in both languages
    // ── অনুমোদন ──
    NavScreenDef(
        path: 'approvals',
        module: 'approval',
        group: _l.navGroupTransactions,
        label: _l.navScreenApprovals,
        icon: Icons.fact_check_outlined,
        aliases: ['approval', 'সই', 'অপেক্ষা', 'approvals', 'অনুমোদন']),  // l10n-ok: search aliases in both languages
    // ── কর্মী ──
    NavScreenDef(
        path: 'attendance',
        module: 'hr',
        group: _l.navGroupMyWork,
        label: _l.navScreenAttendance,
        icon: Icons.how_to_reg_outlined,
        aliases: ['attendance', 'উপস্থিতি', 'হাজিরা']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'claims',
        module: 'hr',
        group: _l.navGroupMyWork,
        label: _l.navScreenClaims,
        icon: Icons.request_quote_outlined,
        aliases: ['claim', 'অগ্রিম', 'advance', 'খরচ', 'expense claims', 'খরচের দাবি']),  // l10n-ok: search aliases in both languages
    // ── রিপোর্ট ──
    NavScreenDef(
        path: 'reports',
        module: 'reports',
        group: _l.navGroupReports,
        label: _l.navScreenReports,
        icon: Icons.bar_chart_outlined,
        aliases: ['report', 'রিপোর্ট', 'reports']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'dashboards',
        module: 'reports',
        group: _l.navGroupDashboards,
        label: _l.navScreenDashboards,
        icon: Icons.insights_outlined,
        aliases: ['dashboard', 'ড্যাশবোর্ড', 'business dashboards', 'ব্যবসার ড্যাশবোর্ড']),  // l10n-ok: search aliases in both languages
    // ── ⭐ বাকি মডিউল, দ্বিতীয় ভাগ — অর্থ, ডকুমেন্টস, প্রমোশন, নিজের ছুটি ও স্লিপ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬) ──
    NavScreenDef(
        path: 'deposits',
        module: 'finance',
        group: _l.navGroupLists,
        label: _l.navScreenDeposits,
        icon: Icons.savings_outlined,
        aliases: ['deposit', 'fdr', 'dps', 'আমানত', 'সঞ্চয়পত্র', 'মেয়াদ', 'deposits and savings', 'জমা ও সঞ্চয়']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'bank-loans',
        module: 'finance',
        group: _l.navGroupLists,
        label: _l.navScreenBankLoans,
        icon: Icons.account_balance_outlined,
        aliases: ['loan', 'ঋণ', 'কিস্তি', 'cc', 'bank', 'instalment', 'bank loans and instalments', 'ব্যাংক ঋণ ও কিস্তি']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'rents',
        module: 'finance',
        group: _l.navGroupLists,
        label: _l.navScreenRents,
        icon: Icons.home_work_outlined,
        aliases: ['rent', 'ভাড়া', 'গুদাম', 'tenant', 'ভাড়াটে']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'hand-loans',
        module: 'finance',
        group: _l.navGroupLists,
        label: _l.navScreenHandLoans,
        icon: Icons.handshake_outlined,
        aliases: ['hand loan', 'ধার', 'হাওলাত', 'loan', 'hand loans', 'হাতধার']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'documents',
        module: 'documents',
        group: _l.navGroupLists,
        label: _l.navScreenDocuments,
        icon: Icons.folder_open_outlined,
        aliases: ['document', 'নথি', 'কাগজ', 'সই', 'signature', 'file', 'documents', 'ডকুমেন্টস']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'offers',
        module: 'promotion',
        group: _l.navGroupLists,
        label: _l.navScreenOffers,
        icon: Icons.local_offer_outlined,
        aliases: ['offer', 'অফার', 'ছাড়', 'ফ্রি', 'discount', 'free', 'প্রমোশন', 'current offers', 'চলতি অফার']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'leave',
        module: 'hr',
        group: _l.navGroupMyWork,
        label: _l.navScreenLeave,
        icon: Icons.event_available_outlined,
        aliases: ['leave', 'ছুটি', 'অবকাশ', 'my leave', 'আমার ছুটি']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'payslips',
        module: 'hr',
        group: _l.navGroupMyWork,
        label: _l.navScreenPayslips,
        icon: Icons.receipt_long_outlined,
        aliases: ['payslip', 'salary', 'বেতন', 'স্লিপ', 'payslips', 'বেতনের স্লিপ']),  // l10n-ok: search aliases in both languages
    // ── ⭐ বিক্রয় বিল আর চালান (মালিক, ১৩ অক্টোবর ২০২৬: "kono kichuri list show korche na") ──
    NavScreenDef(
        path: 'invoices',
        module: 'sales',
        group: _l.navGroupTransactions,
        label: _l.navScreenInvoices,
        icon: Icons.receipt_long_outlined,
        aliases: ['invoice', 'বিল', 'ইনভয়েস', 'বিক্রি', 'inv', 'sales invoices', 'বিক্রয় বিল']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'challans',
        module: 'sales',
        group: _l.navGroupTransactions,
        label: _l.navScreenChallans,
        icon: Icons.local_shipping_outlined,
        aliases: ['challan', 'চালান', 'gate pass', 'cha', 'delivery challans']),  // l10n-ok: search aliases in both languages
    // ── ⭐ ফোনের নতুন মডিউল, ১৩ অক্টোবর ২০২৬ (মালিক: "app e baki module & menu") ──
    // ⭐ কোপাইলট (১১ নভেম্বর ২০২৬)
    NavScreenDef(
        path: 'copilot',
        module: 'assistant',
        group: _l.navGroupDashboards,
        label: _l.navScreenCopilot,
        icon: Icons.psychology_outlined,
        aliases: ['copilot', 'কোপাইলট', 'মগজ', 'প্রশ্ন', 'নজর', 'সারাংশ', 'ask', 'assistant', 'brief', 'watch']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'owner-centre',
        module: 'executive',
        group: _l.navGroupDashboards,
        label: _l.navScreenOwnerCentre,
        icon: Icons.speed_outlined,
        aliases: ['owner', 'মালিক', 'আজ', 'গ্রুপ', 'কোম্পানি', 'executive', 'today — the whole group', 'আজ — গোটা গ্রুপ']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'credit',
        module: 'credit_control',
        group: _l.navGroupDashboards,
        label: _l.navScreenCredit,
        icon: Icons.credit_score_outlined,
        aliases: ['credit', 'বাকি', 'সীমা', 'ঝুঁকি', 'মেয়াদ', 'overdue', 'risk', 'credit overview', 'বাকির হিসাব']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'notices',
        module: 'notification',
        group: _l.navGroupLists,
        label: _l.navScreenNotices,
        icon: Icons.campaign_outlined,
        aliases: ['notice', 'নোটিশ', 'সই', 'বিজ্ঞপ্তি', 'notice board', 'নোটিশ বোর্ড']),  // l10n-ok: search aliases in both languages
      ]);

  /// Screens that hang on no module: the bottom bar and the "আমি" page reach
  /// them; the search still finds them.
  static List<NavScreenDef> get outside => _once('outside', () => [
    NavScreenDef(
        path: 'sync-status',
        module: '',
        group: '',
        label: _l.navScreenSyncStatus,
        icon: Icons.sync_outlined,
        aliases: ['sync', 'সিঙ্ক', 'পাঠানো', 'sync status', 'সিঙ্কের অবস্থা']),  // l10n-ok: search aliases in both languages
    NavScreenDef(
        path: 'notifications',
        module: '',
        group: '',
        label: _l.navScreenNotifications,
        icon: Icons.notifications_outlined,
        aliases: ['notification', 'নোটিফিকেশন', 'বার্তা', 'notifications', 'বিজ্ঞপ্তি']),  // l10n-ok: search aliases in both languages
      ]);

  static NavModuleDef? moduleDef(String code) {
    for (final m in modules) {
      if (m.code == code) return m;
    }
    return null;
  }

  static NavScreenDef? screenDef(String path) {
    for (final s in screens) {
      if (s.path == path) return s;
    }
    for (final s in outside) {
      if (s.path == path) return s;
    }
    return null;
  }

  /// ⭐ The heading a web menu group stands under — the web's six fixed groups in the phone's words
  /// (`ModuleDefinition::MENU_GROUPS`).
  static String webGroupTitle(String? group) => switch (group) {
        'dashboard' => _l.navGroupDashboards,
        'master' => _l.navGroupLists,
        'transactions' => _l.navGroupTransactions,
        'approval' => _l.navGroupApprovals,
        'reports' => _l.navGroupReports,
        'settings' => _l.navGroupSettings,
        _ => _l.navGroupOther,
      };

  /// ⭐ A tile for a server module this catalogue has no place for (system admin, master data, governance, …) — the
  /// server's own name, a plain icon; it stands after the catalogue's own tiles, in the server's order.
  static NavModuleDef serverModule(String code, String label) => NavModuleDef(
      code,
      label.trim().isEmpty ? code : label.trim(),
      switch (code) {
        'system_admin' => Icons.settings_outlined,
        'master_data' => Icons.dataset_outlined,
        'governance' => Icons.policy_outlined,
        'backup' => Icons.backup_outlined,
        'restaurant' => Icons.restaurant_outlined,
        'payroll' => Icons.payments_outlined,
        'pos' => Icons.point_of_sale_outlined,
        'asset' || 'fixed_asset' || 'fixed_assets' => Icons.domain_outlined,
        _ => Icons.apps_outlined,
      },
      [code.replaceAll('_', ' ')]);

  /// The module a server route name belongs to — its first segment, with the
  /// web's few plural/alias spellings folded in.
  static String? moduleOfRoute(String route) {
    final head = route.split('.').first;
    const aliases = {'approvals': 'approval', 'report': 'reports', 'customers': 'customer'};
    final code = aliases[head] ?? head;
    return moduleDef(code) == null ? null : code;
  }
}

/// The tree one person sees — built from the menu items the home screen
/// already holds (permission-filtered by `/me` and [MenuRepository], switch-
/// filtered by [ModuleGate]).
class NavTree {
  NavTree._(this.modules, this._extra);

  final List<NavModule> modules;
  final List<NavRow> _extra;

  static NavTree empty() => NavTree._(const [], const []);

  /// [items] is the person's menu; [always] are paths every signed-in person
  /// may open whatever the menu says — the dashboards list (the server filters
  /// it itself, and the old home always offered it), the sync status and the
  /// bell, none of which sit behind a permission or a module switch.
  factory NavTree.from(List<MenuItem> items,
      {List<String> always = const ['dashboards', 'sync-status', 'notifications']}) {
    final rowsByModule = <String, Map<String, List<NavRow>>>{};
    final childrenByParent = <String, List<NavRow>>{};
    final extra = <NavRow>[];
    final seen = <String>{};

    void place(String module, String group, NavRow row) {
      rowsByModule
          .putIfAbsent(module, () => {})
          .putIfAbsent(group, () => [])
          .add(row);
    }

    final live = <({String path, String? label})>[
      for (final item in items)
        if (!item.planned && item.routeName.isNotEmpty && !item.isWeb)
          (path: item.routeName, label: item.label),
      for (final path in always) (path: path, label: null),
    ];

    // Catalogue order inside a group, not arrival order — the same screen
    // stands in the same place for everybody.
    int rank(String path) {
      final i = NavCatalog.screens.indexWhere((s) => s.path == path);
      return i < 0 ? 1 << 20 : i;
    }

    live.sort((a, b) => rank(a.path).compareTo(rank(b.path)));

    for (final entry in live) {
      if (!seen.add(entry.path)) continue;
      final def = NavCatalog.screenDef(entry.path);
      final label = (entry.label?.trim().isNotEmpty ?? false)
          ? entry.label!.trim()
          : def?.label ?? entry.path;
      if (def == null || def.module.isEmpty) {
        // ⓘ The sync status and the bell live outside the modules; an unknown
        // path (a build that added a screen and not its place) still has a
        // door — under "অন্যান্য" in reports — rather than none.
        final row = NavRow(
          id: entry.path,
          label: label,
          icon: def?.icon ?? Icons.circle_outlined,
          module: def == null ? 'reports' : '',
          path: entry.path,
          aliases: def?.aliases ?? const [],
        );
        if (def == null) {
          place('reports', NavCatalog._l.navGroupOther, row);
        } else {
          extra.add(row);
        }
        continue;
      }
      final row = NavRow(
        id: def.path,
        label: label,
        icon: def.icon,
        module: def.module,
        path: def.path,
        aliases: [def.label, ...def.aliases],
      );
      if (def.parent != null) {
        childrenByParent.putIfAbsent(def.parent!, () => []).add(row);
      } else {
        place(def.module, def.group, row);
      }
    }

    // A parent with one child is no parent — the child stands in its place.
    for (final parent in NavCatalog.parents) {
      final children = childrenByParent[parent.id] ?? const [];
      if (children.isEmpty) continue;
      place(
          parent.module,
          parent.group,
          children.length == 1
              ? children.single
              : NavRow(
                  id: 'group:${parent.id}',
                  label: parent.label,
                  icon: parent.icon,
                  module: parent.module,
                  children: children,
                ));
    }

    // ⭐ ওয়েবের পর্দা, অ্যাপের ভিতরে — নিজের মডিউলে, ওয়েবের দলের নামে ([MenuRepository.webTiles], ১৩ অক্টোবর ২০২৬)।
    // A module the catalogue does not know gets its own tile, named by the server.
    final serverModules = <String, NavModuleDef>{};
    for (final item in items.where((i) => i.isWeb && !i.planned)) {
      if (!seen.add(item.routeName)) continue;
      final code = (item.module ?? '').trim();
      if (code.isEmpty) continue;
      if (NavCatalog.moduleDef(code) == null) {
        serverModules.putIfAbsent(code, () => NavCatalog.serverModule(code, item.moduleLabel ?? code));
      }
      place(
          code,
          NavCatalog.webGroupTitle(item.group),
          NavRow(
            id: item.routeName,
            label: item.label,
            icon: item.icon,
            module: code,
            path: item.routeName,
            aliases: [
              if (item.moduleLabel != null) item.moduleLabel!,
              NavCatalog.webGroupTitle(item.group),
            ],
            web: true,
          ));
    }

    // "শীঘ্রই আসছে" — dimmed rows at the bottom of their module. ⭐ A module
    // with nothing live still gets its tile, greyed, whose menu is only these
    // rows (owner, 12 Oct 2026): hiding it would let a person conclude the
    // module is never coming.
    for (final item in items.where((i) => i.planned)) {
      final module = NavCatalog.moduleOfRoute(item.key) ??
          (serverModules.containsKey(item.key.split('.').first) ? item.key.split('.').first : null);
      if (module == null) continue;
      place(
          module,
          NavCatalog.plannedGroup,
          NavRow(
            id: item.key,
            label: item.label,
            icon: Icons.schedule_outlined,
            module: module,
            planned: true,
          ));
    }

    final modules = <NavModule>[];
    final comingSoon = <NavModule>[];
    for (final def in [...NavCatalog.modules, ...serverModules.values]) {
      final groups = rowsByModule[def.code];
      if (groups == null) continue;
      final ordered = groups.keys.toList()
        ..sort((a, b) {
          int at(String g) {
            final i = NavCatalog.groupOrder.indexOf(g);
            return i < 0 ? NavCatalog.groupOrder.length : i;
          }

          return at(a).compareTo(at(b));
        });
      final module = NavModule(def: def, groups: [
        for (final g in ordered) NavGroup(g, groups[g]!),
      ]);
      // ⓘ কাজের টাইল আগে, ধূসর টাইল পরে — যা চলে না তার পিছনে যা চলে তা খুঁজতে হয় না
      (module.live ? modules : comingSoon).add(module);
    }
    return NavTree._([...modules, ...comingSoon], extra);
  }

  /// The name a module tile carries — the catalogue's, or the server's for a module only the web knew.
  String? moduleLabel(String code) => module(code)?.label ?? NavCatalog.moduleDef(code)?.label;

  NavModule? module(String code) {
    for (final m in modules) {
      if (m.code == code) return m;
    }
    return null;
  }

  /// A row with children, by its id.
  NavRow? parent(String id) {
    for (final m in modules) {
      for (final g in m.groups) {
        for (final r in g.rows) {
          if (r.id == id) return r;
        }
      }
    }
    return null;
  }

  /// Every screen this person may open — module screens first, then the
  /// ones outside the modules.
  List<NavRow> get screens => [
        for (final m in modules) ...m.screens,
        ..._extra,
      ];

  /// The screen at [path], or null when this person has no door to it.
  NavRow? screen(String path) {
    for (final row in screens) {
      if (row.path == path) return row;
    }
    return null;
  }

  /// Screens whose name — Bengali or English, the module's too — holds every
  /// word of [query]. Ranked: a name that starts with the query first.
  List<NavRow> search(String query) {
    final words = _norm(query).split(' ').where((w) => w.isNotEmpty).toList();
    if (words.isEmpty) return const [];
    final hits = <(int, int, NavRow)>[];
    var order = 0;
    for (final row in screens) {
      final moduleDef = NavCatalog.moduleDef(row.module) ?? module(row.module)?.def;
      final hay = _norm([
        row.label,
        ...row.aliases,
        if (moduleDef != null) ...[moduleDef.label, ...moduleDef.aliases],
      ].join(' | '));
      if (!words.every(hay.contains)) {
        order++;
        continue;
      }
      final label = _norm(row.label);
      final score = label.startsWith(words.first)
          ? 0
          : label.contains(words.first)
              ? 1
              : 2;
      hits.add((score, order++, row));
    }
    hits.sort((a, b) =>
        a.$1 != b.$1 ? a.$1.compareTo(b.$1) : a.$2.compareTo(b.$2));
    return [for (final h in hits) h.$3];
  }

  static String _norm(String s) =>
      s.toLowerCase().replaceAll(RegExp(r'\s+'), ' ').trim();
}
