import 'package:flutter/widgets.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../locale/app_locale.dart';
import '../menu/module_gate.dart';
import 'top_bar/abos_top_bar.dart' as top_bar show bnDigits;
import '../web_inside/web_inside.dart';

/// ⭐ নিচের বারের চার ঘর, ভূমিকা অনুযায়ী — বারের চূড়ান্ত নকশা, সংস্করণ ৩, অংশ ৪ আর ৭ (মালিকের "হ্যাঁ", ২০ অক্টোবর ২০২৬)।
///
/// <p>সব কিছু এখানে খাঁটি ফাংশন: কোন ভূমিকা, কোন ঘর, কোন ঘরে লাল গোনা, কোন পাতায় ＋ নতুন, বার না খাড়া বার। পর্দা
/// ([ScreenFrame]) কেবল এগুলো আঁকে। টেস্ট: `test/navigation/`।
///
/// <p>⛔ ভূমিকা ঠিক হয় চাবি (permission) দেখে, রোলের নাম দেখে নয়: রোলের নাম কোম্পানি নিজে রাখে, চাবি সার্ভারের।
/// যে ঘরের চাবি নেই, বা যার মডিউল কোম্পানি ফোনে বন্ধ রেখেছে ([ModuleGate]), সে ঘর বারে কখনো আসে না।
enum BarRole { owner, sr, depot, accounts, other }

/// বারের সম্ভাব্য সব ঘর। প্রতিটা ভূমিকা এর চারটা পায়।
enum BarTab { home, approvals, reports, orders, collections, challans, stock, vouchers, modules, me }

/// একটা ঘরের সব তথ্য — নাম, আইকন (কেবল Material Symbols), কোন পথে খোলে, কোন চাবিতে দেখা যায়।
@immutable
class BarTabSpec {
  const BarTabSpec({
    required this.tab,
    required this.label,
    required this.icon,
    this.path,
    this.anyOf = const [],
    String? waiting,
  }) : _waiting = waiting;

  final BarTab tab;

  /// বাংলা নাম — সবসময় দেখায়, কখনো লুকায় না (Material 3, Apple HIG)
  final String label;
  final IconData icon;

  /// `/home/<path>` — null হলে ঘরটা হোমের নিজের পাতা (হোম, সব মডিউল)
  final String? path;

  /// এর যেকোনো একটা চাবি থাকলে ঘর দেখা যায়; খালি মানে সবার (হোম, সব মডিউল)
  final List<String> anyOf;

  final String? _waiting;

  /// লাল গোনার পরে TalkBack-এর শব্দ, যেমন "অনুমোদন, ৫টা অপেক্ষায়"
  String get waiting => _waiting ?? AppLocale.l10n.navTabWaiting;

  /// ঘরটা খোলার ঠিকানা
  String get location => path == null ? '/home' : '/home/$path';
}

class RoleTabs {
  const RoleTabs._();

  /// প্রতিটা ঘর একবারই লেখা। চাবিগুলো সেই একই, যা "সব মডিউল"-এর টাইল দেখায় ([MenuRepository]) — ঘর আছে অথচ টাইল
  /// নেই, বা উল্টো, এমন হয় না।
  static Map<BarTab, BarTabSpec> get specs {
    final l = AppLocale.l10n;
    return {
    BarTab.home: BarTabSpec(tab: BarTab.home, label: l.navTabHome, icon: Symbols.home),
    BarTab.approvals: BarTabSpec(
        tab: BarTab.approvals,
        label: l.navTabApprovals,
        icon: Symbols.fact_check,
        path: 'approvals',
        anyOf: const ['approval.decide']),
    BarTab.reports: BarTabSpec(
        tab: BarTab.reports,
        label: l.navTabReports,
        icon: Symbols.bar_chart,
        path: 'reports',
        anyOf: const ['sales.order.view', 'customer.view']),
    BarTab.orders: BarTabSpec(
        tab: BarTab.orders,
        label: l.navTabOrders,
        icon: Symbols.shopping_cart,
        path: 'orders',
        anyOf: const ['sales.order.view']),
    BarTab.collections: BarTabSpec(
        tab: BarTab.collections,
        label: l.navTabCollections,
        icon: Symbols.payments,
        path: 'collections',
        anyOf: const ['sales.collection.view']),
    BarTab.challans: BarTabSpec(
        tab: BarTab.challans,
        label: l.navTabChallans,
        icon: Symbols.local_shipping,
        path: 'challans',
        anyOf: const ['sales.challan.view']),
    BarTab.stock: BarTabSpec(
        tab: BarTab.stock,
        label: l.navTabStock,
        icon: Symbols.inventory_2,
        path: 'stock',
        anyOf: const ['inventory.stock.view']),
    BarTab.vouchers: BarTabSpec(
        tab: BarTab.vouchers,
        label: l.navTabVouchers,
        icon: Symbols.receipt_long,
        path: 'vouchers',
        anyOf: const ['accounts.voucher.create']),
    BarTab.modules: BarTabSpec(tab: BarTab.modules, label: l.navTabModules, icon: Symbols.apps),
    // ⭐ নিজের ছবি — নিচের বারের শেষ ঘর (মালিক, ১৩ নভেম্বর ২০২৬: উপরের বারে জায়গা; YouTube, Instagram, Facebook-এর একই নিয়ম)
    BarTab.me: BarTabSpec(tab: BarTab.me, label: l.homeMe, icon: Symbols.account_circle),
    };
  }

  /// নকশার অংশ ৪-এর সারণি — ⭐ ১৪ নভেম্বর ২০২৬ থেকে "সব মডিউল" সব ভূমিকায় মাঝখানে, তৃতীয় ঘরে (মালিক: "রিপোর্টের জায়গায়
  /// চলে যাবে হচ্ছে মডিউল, মডিউলের জায়গায় রিপোর্ট চলে আসবে")
  static const Map<BarRole, List<BarTab>> byRole = {
    BarRole.owner: [BarTab.home, BarTab.approvals, BarTab.modules, BarTab.reports],
    BarRole.sr: [BarTab.home, BarTab.orders, BarTab.modules, BarTab.collections],
    BarRole.depot: [BarTab.home, BarTab.challans, BarTab.modules, BarTab.stock],
    BarRole.accounts: [BarTab.home, BarTab.approvals, BarTab.modules, BarTab.vouchers],
    // ⓘ চারের কোনোটায় না পড়লে: হোম, সই করার চাবি থাকলে অনুমোদন, আর সব মডিউল
    BarRole.other: [BarTab.home, BarTab.approvals, BarTab.modules],
  };

  /// ⭐ চাবি দেখে ভূমিকা। ক্রমটাই নিয়ম — মালিকের সব চাবি থাকে, তাই আগে মালিক; তারপর হিসাব (ভাউচারের চাবি মাঠের নয়),
  /// তারপর বিক্রয়কর্মী (অর্ডার লেখেন), শেষে ডিপো (চালান বা মজুদ দেখেন)।
  static BarRole roleOf(Iterable<String> permissions) {
    final p = permissions.toSet();
    // মালিক / পরিচালক — "মালিকের কেন্দ্র"-এর চাবি কেবল তাঁদের
    if (p.contains('executive.view')) return BarRole.owner;
    // হিসাব — খাতায় ভাউচার লেখেন
    if (p.contains('accounts.voucher.create')) return BarRole.accounts;
    // বিক্রয়কর্মী — অর্ডার লেখেন
    if (p.contains('sales.order.create')) return BarRole.sr;
    // ডিপো / গুদাম — চালান বা মজুদ
    if (p.contains('sales.challan.view') || p.contains('inventory.stock.view')) return BarRole.depot;
    return BarRole.other;
  }

  /// একটা ঘর এই মানুষ পায় কি না — চাবি আর কোম্পানির ফোন-সুইচ দুটোই
  static bool allows(BarTab tab, Set<String> permissions, Set<String>? modulesOn) {
    final spec = specs[tab]!;
    if (spec.anyOf.isNotEmpty && !spec.anyOf.any(permissions.contains)) return false;
    return spec.path == null || ModuleGate.allows(modulesOn, spec.path!);
  }

  /// ⭐ বারের ঘর — ভূমিকার চারটা, যার চাবি বা সুইচ নেই সেটা বাদ
  static List<BarTab> tabsFor(Iterable<String> permissions, {Set<String>? modulesOn}) {
    final p = permissions.toSet();
    return [
      for (final tab in byRole[roleOf(p)]!)
        if (allows(tab, p, modulesOn)) tab,
      // ⓘ সবার শেষে নিজের ছবি — চাবি লাগে না; ভূমিকার সর্বোচ্চ চার ঘর + এটা = পাঁচ (Material 3-এর সীমা)
      BarTab.me,
    ];
  }

  /// ⭐ একটা ঠিকানা কোন ঘরের — কেবল ঘরের নিজের পাতা আর তার কাগজ; বাকি পাতা যে ঘর থেকে খোলা, সেই ঘরের থাকে।
  /// বিজ্ঞপ্তি বা লিংক থেকে খোলা ওয়েবের ঠিকানাও (`/home/web/<চিহ্ন>`) এখানে চেনা যায়।
  static BarTab? owningTab(String location) {
    var rest = Uri.parse(location).path;
    if (!rest.startsWith('/home/')) return null;
    rest = rest.substring('/home/'.length);
    if (WebPaths.isWeb(rest)) {
      final web = WebPaths.webPathOf(rest.substring(WebPaths.segment.length + 1));
      if (web == null) return null;
      final path = Uri.parse(web).path;
      for (final MapEntry(key: prefix, value: tab) in _webPrefixes.entries) {
        if (path == prefix || path.startsWith('$prefix/')) return tab;
      }
      return null;
    }
    if (rest.startsWith('paper/')) {
      final kind = rest.split('/').elementAtOrNull(1);
      return _paperKinds[kind];
    }
    final segment = rest.split('/').first;
    for (final spec in specs.values) {
      if (spec.path == segment) return spec.tab;
    }
    if (segment == 'new-order') return BarTab.orders;
    return null;
  }

  static const Map<String, BarTab> _webPrefixes = {
    '/approvals': BarTab.approvals,
    '/reports': BarTab.reports,
    '/sales/orders': BarTab.orders,
    '/sales/collections': BarTab.collections,
    '/sales/challans': BarTab.challans,
    '/inventory/stock': BarTab.stock,
    '/accounts/vouchers': BarTab.vouchers,
  };

  static const Map<String, BarTab> _paperKinds = {
    'order': BarTab.orders,
    'collection': BarTab.collections,
    'challan': BarTab.challans,
    'voucher': BarTab.vouchers,
  };

  /// ⭐ ＋ নতুন — কেবল যে পাতায় কিছু বানানো যায় (আদায়, অর্ডার, খরচ, ভাউচার), আর কেবল যার সেই লেখার চাবি আছে।
  /// [mayCollect]: ফোনে আদায় লেখার সার্ভারের উত্তর (`/me`-র `mayCollect` — আদায় আর ভাউচারের চাবি, ডিলারে বাঁধা নন)।
  ///
  /// <p>হোমে (মালিকের হোমের নমুনা, ২০ অক্টোবর ২০২৬) "＋ নতুন" — চাপলে ভূমিকার যা যা বানানো যায় তার তালিকা
  /// ([createChoices]); কিছুই না থাকলে বোতামও নেই।
  static FabSpec? fabFor(String location, Iterable<String> permissions,
      {Set<String>? modulesOn, bool mayCollect = false}) {
    var rest = Uri.parse(location).path;
    final p = permissions.toSet();
    if (rest == '/home') {
      final choices = createChoices(p, modulesOn: modulesOn, mayCollect: mayCollect);
      return choices.isEmpty
          ? null
          : FabSpec(label: AppLocale.l10n.navFabNew, drawnByScreen: false, choices: choices);
    }
    if (!rest.startsWith('/home/')) return null;
    rest = rest.substring('/home/'.length);
    final l = AppLocale.l10n;
    final FabSpec? spec = switch (rest) {
      'orders' when p.contains('sales.order.create') =>
        // ⓘ অর্ডারের পাতা কাঠামোর বোতাম নিজের Scaffold-এ বসায় ([ScreenFrame.fabOf]) — মোটের বারের ওপরে ভাসে
        FabSpec(label: l.navFabNewOrder, route: '/home/new-order', drawnByScreen: true),
      'collections' when mayCollect => FabSpec(label: l.navFabNewCollection, drawnByScreen: true),
      'claims' when p.contains('hr.claim.self') => FabSpec(label: l.navFabNewClaim, drawnByScreen: true),
      _ when (rest == 'vouchers' || rest.startsWith('vouchers/')) && p.contains('accounts.voucher.create') =>
        FabSpec(label: l.navFabNewVoucher, drawnByScreen: true),
      _ => null,
    };
    if (spec == null) return null;
    return ModuleGate.allows(modulesOn, rest) ? spec : null;
  }

  /// ⭐ হোমের "＋ নতুন"-এর তালিকা — অর্ডার, আদায়, খরচ, ভাউচার, খরচের দাবি; কেবল যার চাবি আর সুইচ আছে
  static List<FabChoice> createChoices(Iterable<String> permissions,
      {Set<String>? modulesOn, bool mayCollect = false}) {
    final p = permissions.toSet();
    final l = AppLocale.l10n;
    return [
      if (p.contains('sales.order.create'))
        FabChoice(label: l.navFabNewOrder, route: '/home/new-order', icon: Symbols.shopping_cart),
      if (mayCollect) FabChoice(label: l.navFabNewCollection, route: '/home/collections', icon: Symbols.payments),
      if (p.contains('accounts.voucher.create')) ...[
        FabChoice(label: l.navFabNewExpense, route: '/home/vouchers/expense', icon: Symbols.shopping_bag),
        FabChoice(label: l.navFabNewVoucher, route: '/home/vouchers', icon: Symbols.receipt_long),
      ],
      if (p.contains('hr.claim.self'))
        FabChoice(label: l.navFabExpenseClaim, route: '/home/claims', icon: Symbols.request_quote),
    ].where((c) => ModuleGate.allows(modulesOn, c.route.substring('/home/'.length))).toList();
  }

  /// ⭐ লাল গোনা —১ থেকে ৯৯, তার বেশি "99+", গোনা জানা না থাকলে (আরও আছে, কত জানা নেই) শুধু বিন্দু
  static TabBadge badgeFor(int? count, {bool more = false}) {
    final n = count ?? 0;
    if (n > 99) return TabBadge.text('99+', spoken: AppLocale.l10n.navTabBadgeOver(bnDigits(99)));
    if (more) return const TabBadge.dot();
    if (n <= 0) return const TabBadge.none();
    return TabBadge.text('$n', spoken: AppLocale.l10n.navTabBadgeCount(bnDigits(n)));
  }

  /// TalkBack যা পড়ে — "অনুমোদন, ৫টা অপেক্ষায়"; বিন্দুতে "অনুমোদন, নতুন অপেক্ষায়"
  static String spokenLabel(BarTabSpec spec, TabBadge badge) => switch (badge.kind) {
        TabBadgeKind.none => spec.label,
        TabBadgeKind.dot => AppLocale.l10n.navTabSpokenNew(spec.label, spec.waiting),
        TabBadgeKind.text => '${spec.label}, ${badge.spoken} ${spec.waiting}',
      };

  /// পর্দা-পাঠকের অঙ্ক — বাংলায় বাংলা অঙ্ক, ইংরেজিতে যেমন আছে ([top_bar.bnDigits])
  static String bnDigits(int n) => top_bar.bnDigits(n);

  /// ⭐ কোন বার — চওড়া ৬০০dp বা বেশি, অথবা আড়াআড়ি ফোন: বাঁ পাশে খাড়া বার; ৮৪০dp বা বেশি: পুরো পাশ-মেনু খোলা
  /// (Material 3 window size classes: compact < 600 ≤ medium < 840 ≤ expanded)।
  static BarLayout layoutFor(Size size) {
    if (size.width >= 840) return BarLayout.extendedRail;
    if (size.width >= 600 || size.width > size.height) return BarLayout.rail;
    return BarLayout.bottom;
  }
}

enum BarLayout { bottom, rail, extendedRail }

/// ＋ নতুন বোতাম। [drawnByScreen]: পর্দা নিজেই বোতামটা আঁকে (আদায়, ভাউচার, দাবি), বা কাঠামোর বোতাম নিজের Scaffold-এ
/// বসায় (অর্ডার, [route] সহ) — কাঠামো তখন দ্বিতীয়টা আঁকে না।
@immutable
class FabSpec {
  const FabSpec({required this.label, this.route, required this.drawnByScreen, this.choices = const []});

  final String label;
  final String? route;
  final bool drawnByScreen;

  /// হোমের "＋ নতুন": চাপলে এই তালিকা
  final List<FabChoice> choices;
}

@immutable
class FabChoice {
  const FabChoice({required this.label, required this.route, required this.icon});

  final String label;
  final String route;
  final IconData icon;
}

enum TabBadgeKind { none, dot, text }

@immutable
class TabBadge {
  const TabBadge.none()
      : kind = TabBadgeKind.none,
        text = null,
        spoken = null;
  const TabBadge.dot()
      : kind = TabBadgeKind.dot,
        text = null,
        spoken = null;
  const TabBadge.text(String this.text, {required String this.spoken}) : kind = TabBadgeKind.text;

  final TabBadgeKind kind;
  final String? text;
  final String? spoken;

  @override
  bool operator ==(Object other) =>
      other is TabBadge && other.kind == kind && other.text == text && other.spoken == spoken;

  @override
  int get hashCode => Object.hash(kind, text, spoken);

  @override
  String toString() => 'TabBadge($kind, $text)';
}
