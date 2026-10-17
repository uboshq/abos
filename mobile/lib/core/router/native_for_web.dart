import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/approvals/my_requests_screen.dart';
import '../../features/customers/customer_list_screen.dart';
import '../../features/dashboards/dashboards_screen.dart';
import '../../features/delivery_orders/delivery_order_screens.dart';
import '../../features/executive/executive_screen.dart';
import '../../features/sales_papers/sales_paper_screens.dart';
import '../../features/stock/stock_list_screen.dart';
import '../../features/vouchers/voucher_screens.dart';
import '../../features/web_inside/web_inside_screen.dart';
import '../accounts/voucher_api.dart';
import '../auth/auth_state.dart';
import '../menu/module_gate.dart';
import '../orders/delivery_order_api.dart';

/// ⭐ মেনুর ওয়েব-সারি, ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay sei vabe koro,
/// direct webpage app e bujajayna"*।
///
/// <p>`/me` যে সারির `web` পথ পাঠায়, মেনু তাকে `/home/web/<চিহ্ন>`-এ বসায় ([WebPaths.navPathFor])। এই ফাইল সেই পথ দেখে বলে
/// ফোনের কোন পর্দা ওটার উত্তর — থাকলে সেটাই খোলে, না থাকলে আগের মতো ভিতরের ওয়েব-জানালা ([WebInsideScreen])।
/// ⓘ মেনু, প্রিয় আর সাম্প্রতিক কিছুই বদলায় না: পথটা একই, কেবল যে পর্দা আঁকে সেটা ফোনের।
///
/// <p>⛔ সংখ্যা ফোনে কষা হয় না — প্রতিটা পর্দা ওয়েবের একই ইঞ্জিনের JSON আঁকে ([DashboardApi])। চাবি আর ফোনের মডিউল-সুইচ
/// সার্ভার দেখে; পর্দা কেবল তার উত্তর (৪০৩-এর বাংলা কথাসহ) দেখায়।
class NativeForWeb {
  const NativeForWeb._();

  /// ড্যাশবোর্ডের দরজা যে মডিউলগুলো ফোনে খোলে — ওয়েবের `/dashboard/{module}`।
  ///
  /// <p>⚠️ `hr` নেই, ইচ্ছা করে: HR-এর ড্যাশবোর্ড বেতন-খরচ দেখায়, আর সার্ভার সেটা ফোনে পাঠায় না (চুক্তি §৪;
  /// [DashboardApiController::DESK_ONLY])। ফোন-পর্দায় খুললে কেবল "বন্ধ" আসত — তাই ওটা আগের মতো ওয়েব-জানালায়।
  static const Set<String> dashboards = {
    'accounts', 'finance', 'purchase', 'inventory', 'customer', 'credit_control', 'sales',
    'promotion', 'approval', 'system_admin', 'documents',
  };

  /// এই ওয়েব-পথের ফোন-পর্দা, বা null — তখন ওয়েব-জানালা। [title] মেনুর নিজের নাম।
  static Widget? screenFor(String? webPath, {String title = ''}) {
    if (webPath == null) return null;
    final path = Uri.parse(webPath).path.replaceAll(RegExp(r'/+$'), '');

    if (path == '/owner') {
      return const ModuleGateView(path: 'owner-centre', child: ExecutiveScreen());
    }
    if (path == '/sales/overview') {
      return const SalesOverviewScreen();
    }

    final dashboard = RegExp(r'^/dashboard/([a-z_]+)$').firstMatch(path)?.group(1);
    if (dashboard != null && dashboards.contains(dashboard)) {
      return ModuleDashboardScreen(module: dashboard, name: title);
    }

    return _papers(webPath, path, title);
  }

  // ── ⭐ লেনদেনের তালিকা — ফোনের পর্দা আগে থেকেই আছে, কেবল ছাঁকনি বসিয়ে খোলে (a6, ১৭ অক্টোবর ২০২৬) ──────────────
  //
  // ⓘ ওয়েবের একই রুটের দ্বিতীয়-তৃতীয় সারি (বাতিল বিল, DO-র ট্যাব, ভাউচারের ধরন) ওয়েব-জানালায় খুলত, কারণ মেনু চিনত কেবল
  // রুটের নাম ([RouteRegistry]), প্রশ্নাংশ নয়। ⛔ সংখ্যা ফোনে কষা নয় — প্রতিটা পর্দা সার্ভারের একই ছাঁকনি আর পুরো মোট নেয়।

  /// ওয়েবের ভাউচারের ধরন → ফোনের পর্দার চাবি ([VoucherView])। ⓘ "আদায়" মানে সব রসিদ ([VoucherView.receipts])।
  static const Map<String, String> _voucherViews = {
    'receipt': 'receipt',
    'payment': 'payment',
    'expense': 'expense',
    'journal': 'journal',
    'contra': 'contra',
  };

  /// ফোনের DO-পর্দা যে ট্যাবগুলো সার্ভারে চায় ([[DeliveryOrderDeskController::TABS]]; "সব" মানে ট্যাব নেই)
  static const Set<String> _doTabs = {'drafts', 'pending', 'moving', 'partial', 'back', 'history'};

  /// ⭐ যে ওয়েব-সারির ঠিক একই ফোন-ঘর মেনুতে আগে থেকেই আছে — মেনু ওটা দ্বিতীয়বার দেখায় না ([MenuRepository.webTiles])।
  /// ⓘ ওয়েবের "পরিশোধ" (`/accounts/vouchers/payment`) আর ফোনের "পেমেন্ট" (`vouchers/payment`) একই পর্দা, একই ছাঁকনি।
  static String? twinOf(String? webPath) {
    if (webPath == null) return null;
    final type = RegExp(r'^/accounts/vouchers/([a-z_-]+)/?$').firstMatch(Uri.parse(webPath).path)?.group(1);
    final view = _voucherViews[type];
    if (view == null || view == VoucherView.receipts.key) return null;
    return 'vouchers/$view';
  }

  /// ⭐ মেনুর ঘরের ছবি — ফোনের নিজের পর্দা খুললে ওয়েবের গ্লোব নয়; null হলে ওয়েব-জানালা, গ্লোবই থাকে
  static IconData? iconFor(String? webPath) {
    if (webPath == null) return null;
    final path = Uri.parse(webPath).path.replaceAll(RegExp(r'/+$'), '');
    if (path == '/owner' || path == '/sales/overview') return Icons.insights_outlined;
    final dashboard = RegExp(r'^/dashboard/([a-z_]+)$').firstMatch(path)?.group(1);
    if (dashboard != null && dashboards.contains(dashboard)) return Icons.dashboard_outlined;
    if (path.startsWith('/accounts/vouchers/') || path == '/accounts/voucher-list') return Icons.receipt_outlined;
    if (path == '/sales/invoices' || path == '/sales/challans') return Icons.receipt_long_outlined;
    if (path == '/sales/delivery-orders') return Icons.local_shipping_outlined;
    if (path == '/customers') return Icons.people_alt_outlined;
    if (path == '/inventory/stock') return Icons.warehouse_outlined;
    if (path == '/approvals/mine') return Icons.outbox_outlined;
    return null;
  }

  static Widget? _papers(String webPath, String path, String title) {
    final query = Uri.parse(webPath).queryParameters;
    final named = title.isEmpty || title == 'ABOS' ? null : title;

    final type = RegExp(r'^/accounts/vouchers/([a-z_-]+)$').firstMatch(path)?.group(1);
    if (type != null && _voucherViews.containsKey(type)) {
      return ModuleGateView(
          path: 'vouchers', child: VoucherListScreen(key: ValueKey('vouchers-web-$type'), view: _voucherViews[type]));
    }
    if (path == '/accounts/voucher-list') {
      return const ModuleGateView(path: 'vouchers', child: VoucherListScreen());
    }

    if (path == '/sales/invoices') {
      final cancelled = query['stage'] == 'cancelled' || query['cancelled'] == '1';
      return ModuleGateView(
        path: 'invoices',
        child: SalesPaperListScreen(
          key: ValueKey('invoices-web-$cancelled'),
          kind: SalesPaperKind.invoice,
          api: ServerSalesPaperApi(cancelled: cancelled),
          title: cancelled ? (named ?? 'বাতিল বিক্রয় বিল') : null,
        ),
      );
    }
    if (path == '/sales/challans') {
      return const ModuleGateView(path: 'challans', child: SalesPaperListScreen(kind: SalesPaperKind.challan));
    }

    if (path == '/sales/delivery-orders') {
      final tab = _doTabs.contains(query['tab']) ? query['tab'] : null;
      return ModuleGateView(
        path: 'delivery-orders',
        child: _DeliveryOrderDoor(tab: tab, title: tab == null ? null : named, webPath: webPath),
      );
    }

    if (path == '/customers') {
      return const ModuleGateView(path: 'customers', child: CustomerListScreen());
    }
    if (path == '/inventory/stock') {
      return const ModuleGateView(path: 'stock', child: StockListScreen());
    }
    // ⭐ আমার পাঠানো অনুরোধ — সার্ভারের `GET /approvals/mine` (a6/app-native-server)
    if (path == '/approvals/mine') {
      return ModuleGateView(path: 'approvals', child: MyRequestsScreen(title: named));
    }

    return null;
  }
}

/// ⭐ DO-র তালিকা, ওয়েবের ট্যাব বসিয়ে — রাউটারের `delivery-orders`-এর একই চাবি আর একই দরজা।
///
/// <p>⚠️ কোম্পানি বিক্রয় আদেশে চলে গেলে ([ordersReplaceDoProvider]) ফোনের পর্দা আদেশের দরজা খোলে, আর সেখানে ওয়েবের DO-ট্যাব
/// নেই — তখন ট্যাবের সারি আগের মতো ওয়েব-জানালায়, যাতে "অপেক্ষমাণ" লিখে সব আদেশ না দেখায়।
class _DeliveryOrderDoor extends ConsumerWidget {
  const _DeliveryOrderDoor({required this.tab, required this.title, required this.webPath});

  final String? tab;
  final String? title;
  final String webPath;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final orders = ref.watch(ordersReplaceDoProvider);
    if (orders && tab != null) {
      return WebInsideScreen(webPath: webPath, title: title ?? 'ABOS');
    }
    return DeliveryOrderListScreen(
      key: ValueKey('delivery-orders-web-$tab'),
      api: ServerDeliveryOrderApi(orders: orders, tab: tab),
      title: title,
      canWrite: ref.watch(authStateProvider).user?.can(orders ? 'sales.order.create' : 'sales.do.create') ?? false,
    );
  }
}
