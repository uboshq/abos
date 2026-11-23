import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../auth/auth_user.dart';
import '../auth/session_profile.dart';
import '../locale/app_locale.dart';
import 'me_api.dart';
import 'menu_item.dart';
import 'menu_module.dart';
import 'route_registry.dart';
import '../router/native_for_web.dart';
import '../web_inside/web_inside.dart';

/// Where the signed-in person's home menu comes from: `GET /me`, filtered
/// twice before a row becomes a tile —
///
///  1. by the server, against that person's permissions (already done by the
///     time this file sees the response);
///  2. by [RouteRegistry], against what **this app build** actually has a
///     screen for — see that file's own doc comment for why the server
///     cannot do this filtering itself.
///
/// <p><b>"নতুন অর্ডার" is not one of `/me`'s menu rows</b> — the
/// coordinating session confirmed 4 September that placing an order is an
/// action inside the order list, gated by the `sales.order.create`
/// permission directly, not a navigable menu entry. This file adds that tile
/// itself, from the same `permissions` list every other check already reads,
/// rather than waiting for a menu row that will never arrive.
///
/// <p>Offline, `GET /me` cannot be called at all, and a blank home screen the
/// one time someone opens the app with no signal is worse than a slightly
/// stale menu — so [menuFor] falls back to [_localFallback], built from the
/// same `permissions` list already saved on this device by the last
/// successful login (see `SessionRepository`). The fallback is
/// permission-filtered the identical way, so it degrades to "the same menu,
/// no icons any fresher than the last sign-in" rather than to nothing.
class HomeMenu {
  const HomeMenu({required this.items, this.profile, this.me, this.modules = const ServerModules()});

  final List<MenuItem> items;

  /// ⭐ The server's merged modules and their names ([ServerModules]); empty offline and from an older server.
  final ServerModules modules;

  /// `/me`-র মানুষটা — পদবি আর ছবিসহ; অফলাইনে null।
  final AuthUser? me;

  /// Null when `/me` could not be reached and the menu is the local
  /// fallback — see [MenuRepository.homeFor].
  final SessionProfile? profile;
}

class MenuRepository {
  const MenuRepository();

  static const _newOrderPermission = 'sales.order.create';

  /// Marking *your own* attendance — never the whole team's.
  ///
  /// <p>⛔ The menu can never carry this. `/me`'s `hr.attendance.index` row
  /// requires `hr.attendance.view`, which is the permission for seeing
  /// everybody's attendance and is deliberately withheld from field staff;
  /// their templates carry `hr.attendance.self`. A tile built from the menu
  /// would have been invisible to exactly the people who need it — and
  /// perfectly visible to whoever checked it from an office, which is how a
  /// bug like that survives. See docs/Contract §৭.
  static const _ownAttendancePermission = 'hr.attendance.self';

  /// The due list — who owes what, largest first.
  ///
  /// <p>Synthetic rather than a menu row, and for a different reason than
  /// হাজিরা: the server *does* have a row for it, but its route is
  /// `customer.report.show` — the same route name four different reports
  /// share, told apart only by a `route_params.slug` this app does not read.
  /// Mapping by route name alone would make "বকেয়া তালিকা", "বয়সভিত্তিক",
  /// "আদায়" and "সীমাহীন" indistinguishable, and three of the four would
  /// open the wrong screen.
  ///
  /// <p>⚠️ Worth revisiting when route_params is read: then it becomes an
  /// ordinary menu row and this tile can go.
  static const _dueListPermission = 'customer.view';

  /// One icon per app path segment — `/me` sends a label for every row but
  /// no icon, so the icon is this app's own, keyed by the path it already
  /// resolved the row to rather than by the server's route name (one path
  /// can only ever want one icon; the server's naming is not this app's
  /// concern here).
  static const Map<String, IconData> _iconByAppPath = {
    'approvals': Icons.fact_check_outlined,
    'attendance': Icons.how_to_reg_outlined,
    'dues': Icons.account_balance_wallet_outlined,
    'today': Icons.insights_outlined,
    'reports': Icons.bar_chart_outlined,
    'customers': Icons.people_alt_outlined,
    'products': Icons.inventory_2_outlined,
    'stock': Icons.warehouse_outlined,
    'orders': Icons.receipt_long_outlined,
  };

  Future<List<MenuItem>> menuFor(AuthUser user) async =>
      (await homeFor(user)).items;

  /// The menu plus which company and branch `/me` says this session is in —
  /// one call, because they arrive in one response and the home screen
  /// wants both. [HomeMenu.profile] is null on the offline fallback: the
  /// header then reads the names the last successful `/me` left in
  /// `SessionRepository`, and only after that falls back to the person's own
  /// name.
  Future<HomeMenu> homeFor(AuthUser user) async {
    try {
      final response = await MeApi.fetch();
      final items = <MenuItem>[];
      for (final module in response.menu) {
        for (final entry in module.allEntries) {
          final tile = tileFor(entry);
          if (tile != null) items.add(tile);
        }
      }
      final native = ordered(items, response.user,
          ordersReplaceDo: response.profile.ordersReplaceDo);
      return HomeMenu(
        // ⭐ ফোন-পর্দা আগে, তারপর বাকি সব ওয়েবের পর্দা — অ্যাপের ভিতরে খোলে ([webTiles], ১৩ অক্টোবর ২০২৬)
        items: [...native, ...webTiles(response.menu, native)],
        profile: response.profile,
        me: response.user,
        modules: ServerModules.of(response.menu),
      );
    } catch (_) {
      return HomeMenu(items: ordered(_localFallback(user), user));
    }
  }

  /// One `/me` menu row turned into a tile, or null when it has no place on
  /// this build's home grid.
  ///
  /// <p><b>Two different "there is no screen", and they must not end the same
  /// way.</b>
  ///
  /// <p>The server says `planned` — the *system* does not have the thing yet,
  /// on the web either. That row is shown dimmed and inert, never omitted,
  /// which is the web side's own convention and the reason `MeController`
  /// sends the flag at all. Hiding it would let a person conclude the feature
  /// is not coming and ask the office for it again; showing it greyed says
  /// "planned, not yet" in the one place they are already looking.
  ///
  /// <p>The route is live but [RouteRegistry] has no path for it — the
  /// *server* has the screen and this *build* has not caught up. That row is
  /// dropped silently, which is correct rather than a gap; see
  /// RouteRegistry's own doc comment.
  ///
  /// <p>Until this method existed the first case could never happen: every
  /// row went through the RouteRegistry filter first, and a planned row has
  /// no path by definition, so all twenty-two of them were dropped before the
  /// dimmed tile that home_shell.dart had already built for them could ever
  /// draw. The tile, the flag, the parsing and the "শীঘ্রই আসছে" label were
  /// all in place and unreachable.
  @visibleForTesting
  MenuItem? tileFor(MenuRouteEntry entry) {
    if (entry.planned) {
      return MenuItem(
        key: entry.route,
        label: entry.label,
        // No path was resolved and none is wanted — the tile is inert. The
        // clock is this app's own choice for "not yet", not a server icon.
        icon: Icons.schedule_outlined,
        routeName: '',
        planned: true,
      );
    }

    final appPath = RouteRegistry.appPathFor(entry.route);
    if (appPath == null) return null;

    return MenuItem(
      key: entry.route,
      label: entry.label,
      icon: _iconByAppPath[appPath] ?? Icons.circle_outlined,
      routeName: appPath,
    );
  }

  /// ⭐ বাকি সব মডিউল, অ্যাপের ভিতরে — মালিক, ১৩ অক্টোবর ২০২৬: *"web e na dhuke jar jar kaj 100% app ei korte pare"*।
  ///
  /// <p>Every `/me` row the server gave a `web` path (it does so only where this person may open that web screen inside
  /// the app — their own keys, their company's switch, no stock screens for somebody who may not see stock) and this
  /// build has no native screen for becomes a tile that opens the real web screen in the in-app window
  /// ([WebPaths.navPathFor]).
  ///
  /// <p>⚠️ A native screen wins: a row whose route [RouteRegistry] maps, or whose route is the key of one of this app's
  /// own tiles in [native], is not offered twice — but only its *first* row: the web's second and third rows on one
  /// route (cancelled invoices beside invoices) are different screens and stay. The same web path twice is one tile.
  @visibleForTesting
  List<MenuItem> webTiles(List<MenuModule> menu, List<MenuItem> native) {
    final nativeKeys = {for (final item in native) item.key, ..._nativeTwins};
    final coveredOnce = <String>{};
    final seen = <String>{};
    final out = <MenuItem>[];
    for (final module in menu) {
      module.groups.forEach((group, entries) {
        for (final entry in entries) {
          final web = entry.web;
          if (entry.planned || web == null) continue;
          if (RouteRegistry.appPathFor(entry.route) != null || nativeKeys.contains(entry.route)) {
            if (coveredOnce.add(entry.route)) continue;
          }
          // ⭐ ফোনের একই ঘর আগে থেকেই আছে (ওয়েবের "পরিশোধ" = ফোনের "পেমেন্ট") — দুইবার নয় ([NativeForWeb.twinOf])
          final twin = NativeForWeb.twinOf(web);
          if (twin != null && native.any((item) => item.routeName == twin)) continue;
          if (!seen.add(web)) continue;
          WebPaths.rememberTitle(WebPaths.navPathFor(web), entry.label);
          out.add(MenuItem(
            key: 'web:$web',
            label: entry.label,
            // ⭐ ফোনের নিজের পর্দা খুললে ওয়েবের গ্লোব নয় (মালিক, ১৭ অক্টোবর ২০২৬: "direct webpage app e bujajayna")
            icon: NativeForWeb.iconFor(web) ?? Icons.language_outlined,
            routeName: WebPaths.navPathFor(web),
            module: module.code,
            moduleLabel: module.label,
            group: group,
            webPath: web,
            ownModule: entry.module == null || entry.module == module.code ? null : entry.module,
          ));
        }
      });
    }
    return out;
  }

  /// ⭐ ভাউচারের আলাদা ঘর, পর্দার বোতামের ক্রমে — চাবিগুলো [VoucherView.kinds]-এর (মালিক, ১৭ অক্টোবর ২০২৬)
  static List<(String, String, IconData)> get _voucherKinds => [
    ('cash-receipt', AppLocale.l10n.navScreenCashReceipt, Icons.payments_outlined),
    ('mfs-receipt', AppLocale.l10n.navScreenMfsReceipt, Icons.phone_android_outlined),
    ('bank-receipt', AppLocale.l10n.navScreenBankReceipt, Icons.account_balance_outlined),
    ('payment', AppLocale.l10n.navScreenPayment, Icons.outbox_outlined),
    ('contra', AppLocale.l10n.navScreenContra, Icons.swap_horiz_outlined),
    ('journal', AppLocale.l10n.navScreenJournal, Icons.menu_book_outlined),
    ('expense', AppLocale.l10n.navScreenExpense, Icons.shopping_bag_outlined),
  ];

  /// Server routes this app answers natively under another key — the same screen, so not a second web row.
  static const Set<String> _nativeTwins = {'sales.tracking.index'};

  List<MenuItem> _localFallback(AuthUser user) {
    final items = <MenuItem>[];
    final localLabels = {
      'customer.index': (AppLocale.l10n.navScreenCustomers, 'customer.view', Icons.people_alt_outlined),
      'sales.order.index': (
        AppLocale.l10n.navScreenOrders,
        'sales.order.view',
        Icons.receipt_long_outlined
      ),
      'inventory.product.index': (
        AppLocale.l10n.navScreenProducts,
        'inventory.product.view',
        Icons.inventory_2_outlined
      ),
      'inventory.stock.index': (
        AppLocale.l10n.navScreenStock,
        'inventory.stock.view',
        Icons.warehouse_outlined
      ),
    };
    localLabels.forEach((serverRoute, tuple) {
      final (label, permission, icon) = tuple;
      final appPath = RouteRegistry.appPathFor(serverRoute);
      if (appPath == null || !user.can(permission)) return;
      items.add(MenuItem(
          key: serverRoute, label: label, icon: icon, routeName: appPath));
    });
    return items;
  }

  /// Everything this person can open, in the order a day actually uses it.
  ///
  /// <p><b>Work first, "coming soon" last.</b> Seen on a real device on 15
  /// September: a super_admin's grid put নতুন অর্ডার, হাজিরা and সিঙ্কের অবস্থা
  /// — the three things used every single day — *below twenty greyed tiles
  /// for features that do not exist yet*. Someone had to scroll past
  /// everything that does not work to reach everything that does.
  ///
  /// <p>The planned rows still belong on screen (docs/Contract: hiding them
  /// lets a person conclude the feature is never coming and ask the office
  /// for it again) — they just do not belong in front.
  ///
  /// <p>⚠️ Within the live group the server's own order is preserved
  /// untouched. That order is a decision made on the web side — the menu
  /// stands in the order the goods actually move — and this file has no
  /// business second-guessing it. All that changes here is that dead tiles
  /// sink.
  @visibleForTesting
  List<MenuItem> ordered(List<MenuItem> items, AuthUser user,
      {bool ordersReplaceDo = false}) {
    final live = <MenuItem>[];
    final planned = <MenuItem>[];
    for (final item in items) {
      (item.planned ? planned : live).add(item);
    }
    return [...live, ..._syntheticTiles(user, ordersReplaceDo), ...planned];
  }

  /// Tiles that are not `/me` menu rows at all — see this class's own doc
  /// comment for "নতুন অর্ডার" and হাজিরা, and the trailing comment below for
  /// the sync-status tile every role gets regardless.
  List<MenuItem> _syntheticTiles(AuthUser user,
          [bool ordersReplaceDo = false]) =>
      [
        if (user.can(_newOrderPermission))
          MenuItem(
            key: 'sales.order.create',
            label: AppLocale.l10n.navScreenNewOrder,
            icon: Icons.add_shopping_cart_outlined,
            routeName: 'new-order',
          ),
        // ⭐ ডেলিভারি ট্র্যাকিং (0.4.6, মালিক ২ অক্টোবর ২০২৬) — SR-এর অর্ডার দেখার চাবি, বা গুদাম/ডেলিভারির চাবি।
        // মেনু-সারি নয়: ওয়েবের সারিটা `sales.screen_orders`-এর পেছনে, আর গুদামের মানুষ ওটা পান না।
        if (user.can('sales.order.view') || user.can('sales.delivery.view'))
          MenuItem(
            key: 'sales.tracking',
            label: AppLocale.l10n.navScreenTracking,
            icon: Icons.local_shipping_outlined,
            routeName: 'tracking',
          ),
        // ⭐ আজকের রুট — ছকে আজ যে রুট আর তার দোকান; মাঠের মানুষের আদেশ দেখার চাবিতে (সমন্বয়কের ক্রম "ঘ")
        if (user.can('sales.order.view'))
          MenuItem(
            key: 'sales.my_route',
            label: AppLocale.l10n.navScreenMyRoute,
            icon: Icons.route_outlined,
            routeName: 'my-route',
          ),
        // ⭐ লিড — মাঠ থেকে নতুন দোকানের খোঁজ, ওয়েবের লিডের একই চাবি
        if (user.can('sales.lead.view'))
          MenuItem(
            key: 'sales.lead',
            label: AppLocale.l10n.navScreenLeads,
            icon: Icons.person_search_outlined,
            routeName: 'leads',
          ),
        // ⭐ উদ্ধৃতি — ওয়েবের উদ্ধৃতির একই চাবি
        if (user.can('sales.quotation.view'))
          MenuItem(
            key: 'sales.quotation',
            label: AppLocale.l10n.navScreenQuotations,
            icon: Icons.request_quote_outlined,
            routeName: 'quotations',
          ),
        // ⭐ আজকের ডেলিভারি — যিনি পৌঁছানো লিখতে পারেন (ধাপ ৭, ৬ অক্টোবর ২০২৬)
        if (user.can('sales.delivery.update'))
          MenuItem(
            key: 'sales.deliveries',
            label: AppLocale.l10n.navScreenDeliveries,
            icon: Icons.local_shipping_outlined,
            routeName: 'deliveries',
          ),
        // ⭐ লোডিং শিট — পণ্য ধরে কত, চালান ধরে কার জন্য, "প্যাক হয়েছে" (ধাপ ৪, ৬ অক্টোবর ২০২৬); দেখা ট্রিপের চাবিতে
        if (user.can('sales.shipment.view'))
          MenuItem(
            key: 'sales.loading',
            label: AppLocale.l10n.navScreenLoading,
            icon: Icons.inventory_2_outlined,
            routeName: 'loading',
          ),
        // ⭐ টাকা আদায়, প্রিন্সিপাল আর ক্রয় — কেবল পড়া, ওয়েবের দেখার চাবিতে (মালিক, ৬ অক্টোবর ২০২৬)
        if (user.can('sales.collection.view'))
          MenuItem(
            key: 'sales.collections',
            label: AppLocale.l10n.navScreenCollections,
            icon: Icons.payments_outlined,
            routeName: 'collections',
          ),
        // ⭐ টাকা জমার খবর — জমার খবরের নিজের চাবি (সার্ভার `sales.claim.submit`, ১৬ নভেম্বর ২০২৬); দোকান পাতার ভেতরে বাছা
        // (মালিক, ১৬ ও ২০ নভেম্বর ২০২৬: ≡-এর দ্রুত কাজ আর নিচের "জমা" থেকে সরাসরি)
        if (user.can('sales.claim.submit'))
          MenuItem(
            key: 'sales.deposit_report',
            label: AppLocale.l10n.lookQuickDeposit,
            icon: Icons.payments_outlined,
            routeName: 'deposit-report',
          ),
        if (user.can('supplier.view'))
          MenuItem(
            key: 'purchase.principals',
            label: AppLocale.l10n.navScreenPrincipals,
            icon: Icons.business_outlined,
            routeName: 'principals',
          ),
        // ⭐ প্রিন্সিপালের কমিশন — ওয়েবের রিপোর্টের একই চাবি `supplier.report` (মালিকের আদেশ "app e dekhate hobe", fe-র
        // মাধ্যমে; ২০ অক্টোবর ২০২৬)। ⓘ ওয়েবের মেনু-সারিটা ([NativeForWeb.twinOf]) এই ঘরেরই যমজ — দুবার নয়
        if (user.can('supplier.report'))
          MenuItem(
            key: 'supplier.principal_commission',
            label: AppLocale.l10n.navScreenPrincipalCommission,
            icon: Symbols.percent,
            routeName: 'principal-commission',
          ),
        if (user.can('purchase.bill.view'))
          MenuItem(
            key: 'purchase.purchases',
            label: AppLocale.l10n.navScreenPurchases,
            icon: Icons.shopping_cart_outlined,
            routeName: 'purchases',
          ),
        // ⭐ সরাসরি বিক্রয়ের কাউন্টার (0.4.9) — ওয়েবের কাউন্টারের একই চাবি
        if (user.can('sales.challan.create'))
          MenuItem(
            key: 'sales.direct',
            label: AppLocale.l10n.navScreenCounter,
            icon: Icons.point_of_sale_outlined,
            routeName: 'counter',
          ),
        // ⭐ ডেলিভারি অর্ডার (0.4.8) — DO দেখার চাবি যাঁর; লেখা আর সই পর্দার ভিতরে নিজের চাবিতে।
        // ⭐ কোম্পানি বিক্রয় আদেশে চলে গেলে একই টাইল "বিক্রয় আদেশ" — আদেশ দেখার চাবিতে (DO+SO মেশানো, ধাপ ১০)
        if (ordersReplaceDo
            ? user.can('sales.order.view')
            : user.can('sales.do.view'))
          MenuItem(
            key: 'sales.delivery_order',
            label: ordersReplaceDo ? AppLocale.l10n.navScreenSalesOrders : AppLocale.l10n.navScreenDeliveryOrders,
            icon: Icons.assignment_outlined,
            routeName: 'delivery-orders',
          ),
        // First tile on the grid for whoever can see the day's sales — it is
        // the question asked most often and from the furthest away.
        if (user.can('sales.order.view'))
          MenuItem(
            key: 'dashboard.today',
            label: AppLocale.l10n.navScreenToday,
            icon: Icons.insights_outlined,
            routeName: 'today',
          ),
        // One tile for every report the server will let this person run —
        // docs/Contract §৯. Which ones those are is the server's answer, not
        // a list kept here; the tile only opens the door.
        if (user.can('sales.order.view') || user.can('customer.view'))
          MenuItem(
            key: 'reports',
            label: AppLocale.l10n.navScreenReports,
            icon: Icons.bar_chart_outlined,
            routeName: 'reports',
          ),
        if (user.can(_dueListPermission))
          MenuItem(
            key: 'customer.dues',
            label: AppLocale.l10n.navScreenDues,
            icon: Icons.account_balance_wallet_outlined,
            routeName: 'dues',
          ),
        if (user.can(_ownAttendancePermission))
          MenuItem(
            key: 'hr.attendance.self',
            label: AppLocale.l10n.navScreenAttendance,
            icon: Icons.how_to_reg_outlined,
            routeName: 'attendance',
          ),
        // ⭐ অফিসের লোকের ভাউচার — ওয়েবের লেখার চাবি (মালিক, ৭ অক্টোবর ২০২৬: "সব ভাউচার দেওয়ার কথা ছিল")
        if (user.can('accounts.voucher.create'))
          MenuItem(
            key: 'accounts.voucher.create',
            label: AppLocale.l10n.navScreenAllVouchers,
            icon: Icons.receipt_long_outlined,
            routeName: 'vouchers',
          ),
        // ⭐ প্রতিটা ভাউচার-ধরন আলাদা ঘরে, একই চাবিতে (মালিক, ১৭ অক্টোবর ২০২৬: "receipt app e cash, mfs, bank, payment,
        // contra, journal, expense egulo alada alada asena") — `/home/vouchers/<ধরন>`, পর্দা সেই ধরনেই খোলে
        if (user.can('accounts.voucher.create'))
          for (final (kind, label, icon) in _voucherKinds)
            MenuItem(
              key: 'accounts.voucher.$kind',
              label: label,
              icon: icon,
              routeName: 'vouchers/$kind',
            ),
        // ⭐ খরচের দাবি আর অগ্রিম — কর্মীর নিজের (টাকা-আসা-যাওয়ার পরিকল্পনা ১৩, ৭ অক্টোবর ২০২৬; ওয়েবের একই চাবি)
        if (user.can('hr.claim.self'))
          MenuItem(
            key: 'hr.claim.self',
            label: AppLocale.l10n.navScreenClaims,
            icon: Icons.request_quote_outlined,
            routeName: 'claims',
          ),
        // ⭐ বিক্রয় বিল আর চালান — ওয়েবের তালিকার দেখার চাবি (মালিক, ১৩ অক্টোবর ২০২৬: "kono kichuri list show korche na")
        if (user.can('sales.invoice.view'))
          MenuItem(
            key: 'sales.invoice.index',
            label: AppLocale.l10n.navScreenInvoices,
            icon: Icons.receipt_long_outlined,
            routeName: 'invoices',
          ),
        if (user.can('sales.challan.view'))
          MenuItem(
            key: 'sales.challan.index',
            label: AppLocale.l10n.navScreenChallans,
            icon: Icons.local_shipping_outlined,
            routeName: 'challans',
          ),
        // ── ⭐ ফোনের নতুন মডিউল, ১৩ অক্টোবর ২০২৬ (মালিক: "app e baki module & menu") ──
        // মালিকের কেন্দ্র — ওয়েবের "আজ" পাতার চাবি; কোম্পানির ফোন-সুইচ [[ModuleGate]]-এ
        // ⭐ কোপাইলট — মগজ (মালিক, ১০–১১ নভেম্বর ২০২৬); ওয়েবের চাবি `assistant.use`
        if (user.can('assistant.use'))
          MenuItem(
            key: 'assistant.today',
            label: AppLocale.l10n.navScreenCopilot,
            icon: Icons.psychology_outlined,
            routeName: 'copilot',
          ),
        if (user.can('executive.view'))
          MenuItem(
            key: 'executive.today',
            label: AppLocale.l10n.navScreenOwnerCentre,
            icon: Icons.speed_outlined,
            routeName: 'owner-centre',
          ),
        // বাকি নিয়ন্ত্রণ — ওয়েবের বাকির ড্যাশবোর্ডের চাবি (মেনু-সারি নয়: ওর রুট `module.dashboard`, সব মডিউলের এক নাম)
        if (user.can('credit_control.view'))
          MenuItem(
            key: 'credit_control.dashboard',
            label: AppLocale.l10n.navScreenCredit,
            icon: Icons.credit_score_outlined,
            routeName: 'credit',
          ),
        // নোটিশ বোর্ড — সবার; কে কোনটা পাবেন সার্ভার বলে ([[NoticeAudience]]), চাবি নেই ওয়েবের মতোই
        MenuItem(
          key: 'notices',
          label: AppLocale.l10n.navScreenNotices,
          icon: Icons.campaign_outlined,
          routeName: 'notices',
        ),
        // What this device has and has not sent is a fact about the phone,
        // not a business permission — every signed-in role can open it, live
        // menu or fallback alike.
        MenuItem(
          key: 'sync_status',
          label: AppLocale.l10n.navScreenSyncStatus,
          icon: Icons.sync_outlined,
          routeName: 'sync-status',
        ),
        ..._moreModuleTiles(user),
      ];

  /// ⭐ বাকি মডিউল, দ্বিতীয় ভাগ — অর্থ, ডকুমেন্টস, প্রমোশন, নিজের ছুটি ও স্লিপ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
  ///
  /// <p>প্রতিটা টাইল ওয়েবের পর্দার একই চাবিতে, আর সার্ভারের দরজাও ঠিক সেই চাবি চায় — চাবি নেই তো টাইলও নেই, তাই কোনো টাইল
  /// ৪০৩-এর দরজায় নিয়ে যায় না। ফোনে মডিউল বন্ধ থাকলে [ModuleGate] লুকায়।
  List<MenuItem> _moreModuleTiles(AuthUser user) => [
        if (user.can('finance.deposit.view'))
          MenuItem(
              key: 'finance.deposits', label: AppLocale.l10n.navScreenDeposits, icon: Icons.savings_outlined, routeName: 'deposits'),
        if (user.can('finance.bank_facility.view'))
          MenuItem(
              key: 'finance.bank_loans',
              label: AppLocale.l10n.navScreenBankLoans,
              icon: Icons.account_balance_outlined,
              routeName: 'bank-loans'),
        if (user.can('finance.rental.view'))
          MenuItem(key: 'finance.rents', label: AppLocale.l10n.navScreenRents, icon: Icons.home_work_outlined, routeName: 'rents'),
        if (user.can('finance.hand_loan.view'))
          MenuItem(
              key: 'finance.hand_loans', label: AppLocale.l10n.navScreenHandLoans, icon: Icons.handshake_outlined, routeName: 'hand-loans'),
        if (user.can('documents.view'))
          MenuItem(
              key: 'documents.library', label: AppLocale.l10n.navScreenDocuments, icon: Icons.folder_open_outlined, routeName: 'documents'),
        if (user.can('promotion.view'))
          MenuItem(
              key: 'promotion.offers', label: AppLocale.l10n.navScreenOffers, icon: Icons.local_offer_outlined, routeName: 'offers'),
        if (user.can('hr.leave.self'))
          MenuItem(
              key: 'hr.leave.self', label: AppLocale.l10n.navScreenLeave, icon: Icons.event_available_outlined, routeName: 'leave'),
        if (user.can('hr.payslip.self'))
          MenuItem(
              key: 'hr.payslip.self', label: AppLocale.l10n.navScreenPayslips, icon: Icons.receipt_long_outlined, routeName: 'payslips'),
      ];
}
