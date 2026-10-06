import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/approvals/approval_inbox_screen.dart';
import '../../features/attendance/attendance_screen.dart';
import '../../features/auth/login_screen.dart';
import '../../features/customers/customer_detail_screen.dart';
import '../../features/customers/customer_list_screen.dart';
import '../../features/customers/deposit_request_screen.dart';
import '../../features/customers/due_list_screen.dart';
import '../../features/notifications/notifications_screen.dart';
import '../../features/home/home_shell.dart';
import '../orders/delivery_order_api.dart';
import '../../features/delivery_orders/delivery_order_screens.dart';
import '../../features/leads/lead_screens.dart';
import '../../features/quotations/quotation_screens.dart';
import '../../features/route/my_route_screen.dart';
import '../../features/direct_sale/counter_screen.dart';
import '../../features/orders/delivery_tracking_screen.dart';
import '../../features/orders/new_order_screen.dart';
import '../../features/orders/order_prefill.dart';
import '../../features/orders/order_list_screen.dart';
import '../../features/products/product_list_screen.dart';
import '../../features/scan/paper_scan_screen.dart';
import '../../features/splash/splash_screen.dart';
import '../../features/dashboards/dashboards_screen.dart';
import '../../features/reports/reports_screen.dart';
import '../../features/books/money_in_screens.dart';
import '../../features/deliveries/deliveries_screen.dart';
import '../../features/loading/loading_screens.dart';
import '../../features/books/principal_screens.dart';
import '../../features/books/purchase_screens.dart';
import '../../features/stock/stock_list_screen.dart';
import '../../features/today/today_screen.dart';
import '../../features/sync/sync_status_screen.dart';
import '../auth/auth_state.dart';
import '../menu/module_gate.dart';

/// Turns `ref.listen(authStateProvider, ...)` into the [Listenable] go_router
/// wants for [GoRouter.refreshListenable] — go_router has no native awareness
/// of Riverpod, so a redirect that reads [authStateProvider] still needs
/// something to tell the router *when* to re-evaluate it.
class _AuthRefreshNotifier extends ChangeNotifier {
  _AuthRefreshNotifier(Ref ref) {
    ref.listen<AuthState>(authStateProvider, (previous, next) {
      if (previous?.status != next.status) notifyListeners();
    });
  }
}

final goRouterProvider = Provider<GoRouter>((ref) {
  final refresh = _AuthRefreshNotifier(ref);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    initialLocation: '/',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authStateProvider);
      final goingToLogin = state.matchedLocation == '/login';

      // Nobody has looked yet — restoreSession() is still running. Stay on
      // the splash rather than guessing signed-in or signed-out; guessing
      // wrong either flashes a login form for a session that turns out to
      // exist, or drops someone with no session onto a home screen that
      // then has nothing to show.
      if (auth.status == AuthStatus.unknown) {
        return state.matchedLocation == '/' ? null : '/';
      }
      if (auth.status == AuthStatus.signedOut) {
        return goingToLogin ? null : '/login';
      }
      // Signed in — never leave someone stranded on the splash or the login
      // form once a session exists.
      if (goingToLogin || state.matchedLocation == '/') return '/home';
      return null;
    },
    routes: [
      GoRoute(path: '/', builder: (context, state) => const SplashScreen()),
      GoRoute(path: '/login', builder: (context, state) => const LoginScreen()),
      // ⭐ Every screen under /home sits behind [ModuleGateView]: a module
      // the company switched off for the phone shows "এই অংশটা এখন বন্ধ"
      // however it was reached — tile, saved link or a widget's tap.
      GoRoute(
        path: '/home',
        builder: (context, state) => const HomeShell(),
        routes: [
          GoRoute(
            path: 'approvals',
            builder: (context, state) => const ModuleGateView(
                path: 'approvals', child: ApprovalInboxScreen()),
          ),
          GoRoute(
            path: 'attendance',
            builder: (context, state) => const ModuleGateView(
                path: 'attendance', child: AttendanceScreen()),
          ),
          // ⭐ মডিউলের ড্যাশবোর্ড — মালিক, ৪ অক্টোবর ২০২৬; দরজা সার্ভারের (চাবি, ফোনে চালু)
          GoRoute(
            path: 'dashboards',
            builder: (context, state) => const DashboardsScreen(),
          ),
          GoRoute(
            path: 'reports',
            builder: (context, state) =>
                const ModuleGateView(path: 'reports', child: ReportsScreen()),
          ),
          GoRoute(
            path: 'today',
            builder: (context, state) =>
                const ModuleGateView(path: 'today', child: TodayScreen()),
          ),
          GoRoute(
            path: 'dues',
            builder: (context, state) =>
                const ModuleGateView(path: 'dues', child: DueListScreen()),
          ),
          GoRoute(
            path: 'customers',
            builder: (context, state) => const ModuleGateView(
                path: 'customers', child: CustomerListScreen()),
            routes: [
              GoRoute(
                // The public_id in the path, never a sequential one — the
                // same rule the wire follows (docs/Contract §৩ ক).
                path: ':id',
                builder: (context, state) => ModuleGateView(
                  path: 'customers',
                  child: CustomerDetailScreen(
                    customerId: state.pathParameters['id'] ?? '',
                  ),
                ),
                routes: [
                  // স্লিপসহ জমার অনুরোধ (0.4.3) — দোকানের পাতা থেকে
                  GoRoute(
                    path: 'deposit',
                    builder: (context, state) => ModuleGateView(
                      path: 'customers',
                      child: DepositRequestScreen(
                        customerId: state.pathParameters['id'] ?? '',
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ),
          GoRoute(
            path: 'products',
            builder: (context, state) => const ModuleGateView(
                path: 'products', child: ProductListScreen()),
          ),
          GoRoute(
            path: 'stock',
            builder: (context, state) =>
                const ModuleGateView(path: 'stock', child: StockListScreen()),
          ),
          GoRoute(
            path: 'new-order',
            // `extra` carries an OrderPrefill only when opened from "নতুন
            // করে লিখুন" on a rejected order (order_list_screen.dart /
            // sync_status_screen.dart) — absent on the plain "নতুন অর্ডার"
            // tile, which is the ordinary case.
            builder: (context, state) => ModuleGateView(
              path: 'new-order',
              child: NewOrderScreen(prefill: state.extra as OrderPrefill?),
            ),
          ),
          GoRoute(
            path: 'orders',
            builder: (context, state) =>
                const ModuleGateView(path: 'orders', child: OrderListScreen()),
          ),
          // ⭐ সরাসরি বিক্রয়ের কাউন্টার (0.4.9) — ওয়েবের কাউন্টারের চাবি; টাকা আছে, তাই কেবল অনলাইনে
          GoRoute(
            path: 'counter',
            builder: (context, state) =>
                const ModuleGateView(path: 'counter', child: CounterScreen()),
          ),
          // ⭐ ডেলিভারি অর্ডার (0.4.8) — লেখা, জমা, সুপারভাইজারের পরিমাণ আর সই; "নতুন DO" কেবল লেখার চাবিতে
          GoRoute(
            path: 'delivery-orders',
            builder: (context, state) => ModuleGateView(
              path: 'delivery-orders',
              child: Consumer(
                builder: (context, ref, _) {
                  // ⭐ কোম্পানি বিক্রয় আদেশে চলে গেলে একই পর্দা আদেশের দরজায়, আদেশের চাবিতে (DO+SO মেশানো, ধাপ ১০)
                  final orders = ref.watch(ordersReplaceDoProvider);
                  return DeliveryOrderListScreen(
                    api: ServerDeliveryOrderApi(orders: orders),
                    canWrite: ref.watch(authStateProvider).user?.can(orders
                            ? 'sales.order.create'
                            : 'sales.do.create') ??
                        false,
                  );
                },
              ),
            ),
          ),
          // ⭐ টাকা আদায়, প্রিন্সিপাল আর ক্রয় — কেবল পড়া (মালিক, ৬ অক্টোবর ২০২৬: "অ্যাপে payment received, principal list
          // আর purchase list দরকার"); চাবি সার্ভারে, টাইল চাবি অনুযায়ী ([[MenuRepository]])
          // ⭐ আজকের ডেলিভারি — পথে থাকা চালান, "বুঝিয়ে দিন" (ধাপ ৭, ৬ অক্টোবর ২০২৬)
          GoRoute(
            path: 'deliveries',
            builder: (context, state) => const ModuleGateView(
                path: 'deliveries', child: DeliveriesScreen()),
          ),
          // ⭐ লোডিং শিট — খোলা ট্রিপ, "প্যাক হয়েছে" (ধাপ ৪, ৬ অক্টোবর ২০২৬)
          GoRoute(
            path: 'loading',
            builder: (context, state) => const ModuleGateView(
                path: 'loading', child: LoadingListScreen()),
          ),
          GoRoute(
            path: 'collections',
            builder: (context, state) => const ModuleGateView(
                path: 'collections', child: MoneyInListScreen()),
          ),
          GoRoute(
            path: 'principals',
            builder: (context, state) => const ModuleGateView(
                path: 'principals', child: PrincipalListScreen()),
          ),
          GoRoute(
            path: 'purchases',
            builder: (context, state) => ModuleGateView(
              path: 'purchases',
              child: Consumer(
                builder: (context, ref, _) => PurchaseListScreen(
                  canSeeReceipts: ref
                          .watch(authStateProvider)
                          .user
                          ?.can('purchase.receipt.view') ??
                      false,
                ),
              ),
            ),
          ),
          // ⭐ আজকের রুট — সাপ্তাহিক ছকের রুট আর তার দোকান (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)
          GoRoute(
            path: 'my-route',
            builder: (context, state) =>
                const ModuleGateView(path: 'my-route', child: MyRouteScreen()),
          ),
          // ⭐ লিড — মাঠ থেকে নতুন দোকানের খোঁজ (সমন্বয়কের ক্রম "ঘ")
          GoRoute(
            path: 'leads',
            builder: (context, state) =>
                const ModuleGateView(path: 'leads', child: LeadListScreen()),
          ),
          // ⭐ উদ্ধৃতি — মাঠ থেকে দাম, জমা, পাঠানো, দোকানির উত্তর, আদেশে রূপান্তর (সমন্বয়কের ক্রম "ঘ")
          GoRoute(
            path: 'quotations',
            builder: (context, state) => ModuleGateView(
              path: 'quotations',
              child: Consumer(
                builder: (context, ref, _) => QuotationListScreen(
                  canWrite: ref
                          .watch(authStateProvider)
                          .user
                          ?.can('sales.quotation.create') ??
                      false,
                ),
              ),
            ),
          ),
          GoRoute(
            path: 'tracking',
            builder: (context, state) => const ModuleGateView(
                path: 'tracking', child: DeliveryTrackingScreen()),
          ),
          GoRoute(
            path: 'scan',
            builder: (context, state) =>
                const ModuleGateView(path: 'scan', child: PaperScanScreen()),
          ),
          GoRoute(
            path: 'sync-status',
            builder: (context, state) => const SyncStatusScreen(),
          ),
          // ⭐ মাথার ঘণ্টা — নিজের নোটিফিকেশন (মালিক, ৬ অক্টোবর ২০২৬)
          GoRoute(
            path: 'notifications',
            builder: (context, state) => const NotificationsScreen(),
          ),
        ],
      ),
    ],
  );
});
