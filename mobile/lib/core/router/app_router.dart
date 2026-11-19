import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/approvals/approval_inbox_screen.dart';
import '../../features/attendance/attendance_screen.dart';
import '../../features/claims/claims_screens.dart';
import '../../features/vouchers/voucher_screens.dart';
import '../../features/auth/login_screen.dart';
import '../../features/customers/customer_detail_screen.dart';
import '../../features/customers/customer_ledger_screen.dart';
import '../../features/customers/customer_list_screen.dart';
import '../../features/customers/deposit_request_screen.dart';
import '../../features/customers/due_list_screen.dart';
import '../../features/notifications/notifications_screen.dart';
import '../../features/profile/my_phones_screen.dart';
import '../../features/profile/sms_gateway_screen.dart';
import '../../features/profile/profile_screen.dart';
import '../../features/home/home_shell.dart';
import '../../features/home/nav_bottom_bar.dart';
import '../../features/home/paper_route.dart';
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
import '../books/collection_entry.dart';
import '../../features/deliveries/deliveries_screen.dart';
import '../../features/loading/loading_screens.dart';
import '../../features/books/principal_screens.dart';
import '../../features/books/principal_commission_screen.dart';
import '../../features/books/purchase_screens.dart';
import '../../features/stock/stock_list_screen.dart';
import '../../features/today/today_screen.dart';
import '../../features/sync/sync_status_screen.dart';
import '../../features/executive/executive_screen.dart';
import '../../features/copilot/copilot_screen.dart';
import '../../features/credit_control/credit_screens.dart';
import '../../features/notices/notice_board_screen.dart';
import '../../features/sales_papers/sales_paper_screens.dart';
import '../auth/auth_state.dart';
import 'deep_links.dart';
import 'profile_routes.dart';
import '../../features/documents/document_screens.dart';
import '../../features/finance/finance_screens.dart';
import '../../features/promotion/offers_screen.dart';
import '../../features/self_service/self_service_screens.dart';
import '../menu/module_gate.dart';
import 'native_for_web.dart';
import '../web_inside/web_inside.dart';
import '../../features/web_inside/web_inside_screen.dart';

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

      // ⭐ নোটিশ বা SMS-এর লিংক ([DeepLinks]) — সাইন-ইন থাকলে সোজা সেই পাতা; নইলে মনে রেখে আগে লগইন
      final viaLink = DeepLinks.route(state.uri, auth.status);
      if (viaLink != null) return viaLink;

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
      if (goingToLogin || state.matchedLocation == '/') return DeepLinks.take() ?? '/home';
      return null;
    },
    routes: [
      GoRoute(path: '/', builder: (context, state) => const SplashScreen()),
      GoRoute(path: '/login', builder: (context, state) => const LoginScreen()),
      // ⭐ Every screen under /home sits behind [ModuleGateView]: a module
      // the company switched off for the phone shows "এই অংশটা এখন বন্ধ"
      // however it was reached — tile, saved link or a widget's tap.
      // ⭐ And every one is [_framed]: the bottom bar under it (owner, 12 Oct
      // 2026) — the screen and its route unchanged.
      // ⭐ হোমও একই কাঠামোয় — বার, খাড়া বার, ＋ নতুন আর ঘরের ইতিহাস এক জায়গায় (বারের নকশা ৩)
      _framed(
        path: '/home',
        builder: (context, state) => const HomeShell(),
        routes: [
          _framed(
            path: 'approvals',
            builder: (context, state) => const ModuleGateView(
                path: 'approvals', child: ApprovalInboxScreen()),
          ),
          _framed(
            path: 'attendance',
            builder: (context, state) => const ModuleGateView(
                path: 'attendance', child: AttendanceScreen()),
          ),
          _framed(
            path: 'vouchers',
            // ⭐ অফিসের লোকের ভাউচার (৭ অক্টোবর ২০২৬) — ফোনের হিসাব-সুইচের পিছনে
            builder: (context, state) => ModuleGateView(
                path: 'vouchers',
                child: Consumer(
                    builder: (context, ref, _) => VoucherListScreen(
                        canCreate: ref.watch(authStateProvider).user?.can('accounts.voucher.create') ?? false,
                  mayCollect: ref.watch(mayCollectProvider)))),
          ),
          // ⭐ প্রতিটা ভাউচার-ধরন আলাদা ঘরে — নগদ/MFS/ব্যাংক রসিদ, পেমেন্ট, কন্ট্রা, জার্নাল, খরচ (মালিক, ১৭ অক্টোবর ২০২৬);
          // একই হিসাব-সুইচের পিছনে, অচেনা ধরন হলে "সব"
          _framed(
            path: 'vouchers/:view',
            builder: (context, state) => ModuleGateView(
                path: 'vouchers',
                child: Consumer(
                    builder: (context, ref, _) => VoucherListScreen(
                        key: ValueKey('vouchers-${state.pathParameters['view']}'),
                        view: state.pathParameters['view'],
                        canCreate: ref.watch(authStateProvider).user?.can('accounts.voucher.create') ?? false,
                  mayCollect: ref.watch(mayCollectProvider)))),
          ),
          _framed(
            path: 'claims',
            // ⭐ খরচের দাবি ও অগ্রিম (৭ অক্টোবর ২০২৬) — ফোনের HR সুইচের পিছনে
            builder: (context, state) => const ModuleGateView(
                path: 'claims', child: ClaimListScreen()),
          ),
          // ⭐ মডিউলের ড্যাশবোর্ড — মালিক, ৪ অক্টোবর ২০২৬; দরজা সার্ভারের (চাবি, ফোনে চালু)
          _framed(
            path: 'dashboards',
            builder: (context, state) => const DashboardsScreen(),
          ),
          _framed(
            path: 'reports',
            builder: (context, state) =>
                const ModuleGateView(path: 'reports', child: ReportsScreen()),
          ),
          _framed(
            path: 'today',
            builder: (context, state) =>
                const ModuleGateView(path: 'today', child: TodayScreen()),
          ),
          _framed(
            path: 'dues',
            builder: (context, state) =>
                const ModuleGateView(path: 'dues', child: DueListScreen()),
          ),
          _framed(
            path: 'customers',
            // ⓘ চওড়া পর্দায় ডানের ঘরেও "বাকির অবস্থা"-র একই চাবি (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)
            builder: (context, state) => ModuleGateView(
                path: 'customers',
                child: CustomerListScreen(
                    detailBuilder: (context, id) =>
                        CustomerDetailScreen(customerId: id, showCredit: _mayOpenCredit(context)))),
            routes: [
              _framed(
                // The public_id in the path, never a sequential one — the
                // same rule the wire follows (docs/Contract §৩ ক).
                path: ':id',
                builder: (context, state) => ModuleGateView(
                  path: 'customers',
                  child: CustomerDetailScreen(
                    customerId: state.pathParameters['id'] ?? '',
                    // ⭐ "বাকির অবস্থা" — বাকি নিয়ন্ত্রণের চাবি আর ফোন-সুইচে (১৩ অক্টোবর ২০২৬)
                    showCredit: _mayOpenCredit(context),
                  ),
                ),
                routes: [
                  // ⭐ দোকানের খাতা, সহজ করে — মালিক, ১২ অক্টোবর ২০২৬
                  _framed(
                    path: 'ledger',
                    builder: (context, state) => ModuleGateView(
                      path: 'customers',
                      child: CustomerLedgerScreen(
                        customerId: state.pathParameters['id'] ?? '',
                      ),
                    ),
                  ),
                  // জমার বিজ্ঞপ্তি, স্লিপসহ (0.4.3) — দোকানের পাতা থেকে
                  _framed(
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
          // ⭐ টাকা জমার খবর, দোকান বাছাসহ (0.4.58; মালিক, ১৬ ও ২০ নভেম্বর ২০২৬) — ≡-এর দ্রুত কাজ আর নিচের "জমা"
          _framed(
            path: 'deposit-report',
            builder: (context, state) => const ModuleGateView(
                path: 'deposit-report', child: DepositRequestScreen()),
          ),
          _framed(
            path: 'products',
            builder: (context, state) => const ModuleGateView(
                path: 'products', child: ProductListScreen()),
          ),
          _framed(
            path: 'stock',
            builder: (context, state) =>
                const ModuleGateView(path: 'stock', child: StockListScreen()),
          ),
          _framed(
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
          _framed(
            path: 'orders',
            builder: (context, state) =>
                const ModuleGateView(path: 'orders', child: OrderListScreen()),
          ),
          // ⭐ সরাসরি বিক্রয়ের কাউন্টার (0.4.9) — ওয়েবের কাউন্টারের চাবি; টাকা আছে, তাই কেবল অনলাইনে
          _framed(
            path: 'counter',
            builder: (context, state) =>
                const ModuleGateView(path: 'counter', child: CounterScreen()),
          ),
          // ⭐ ডেলিভারি অর্ডার (0.4.8) — লেখা, জমা, সুপারভাইজারের পরিমাণ আর সই; "নতুন DO" কেবল লেখার চাবিতে
          _framed(
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
          _framed(
            path: 'deliveries',
            builder: (context, state) => const ModuleGateView(
                path: 'deliveries', child: DeliveriesScreen()),
          ),
          // ⭐ লোডিং শিট — খোলা ট্রিপ, "প্যাক হয়েছে" (ধাপ ৪, ৬ অক্টোবর ২০২৬)
          _framed(
            path: 'loading',
            builder: (context, state) => const ModuleGateView(
                path: 'loading', child: LoadingListScreen()),
          ),
          _framed(
            path: 'collections',
            // ⭐ অফিসের লোকের "নতুন আদায়" (`mayCollect`, ৭ অক্টোবর ২০২৬)
            builder: (context, state) => Consumer(
                builder: (context, ref, _) => ModuleGateView(
                    path: 'collections',
                    child: MoneyInListScreen(canCollect: ref.watch(mayCollectProvider)))),
          ),
          _framed(
            path: 'principals',
            builder: (context, state) => const ModuleGateView(
                path: 'principals', child: PrincipalListScreen()),
          ),
          // ⭐ প্রিন্সিপালের কমিশন — সব মডিউল → সরবরাহকারী (মালিকের আদেশ "app e dekhate hobe", ২০ অক্টোবর ২০২৬)
          _framed(
            path: 'principal-commission',
            builder: (context, state) => ModuleGateView(
                path: 'principal-commission',
                child: PrincipalCommissionScreen(month: state.uri.queryParameters['month'])),
          ),
          _framed(
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
          _framed(
            path: 'my-route',
            builder: (context, state) =>
                const ModuleGateView(path: 'my-route', child: MyRouteScreen()),
          ),
          // ⭐ লিড — মাঠ থেকে নতুন দোকানের খোঁজ (সমন্বয়কের ক্রম "ঘ")
          _framed(
            path: 'leads',
            builder: (context, state) =>
                const ModuleGateView(path: 'leads', child: LeadListScreen()),
          ),
          // ⭐ উদ্ধৃতি — মাঠ থেকে দাম, জমা, পাঠানো, দোকানির উত্তর, আদেশে রূপান্তর (সমন্বয়কের ক্রম "ঘ")
          _framed(
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
          _framed(
            path: 'tracking',
            builder: (context, state) => const ModuleGateView(
                path: 'tracking', child: DeliveryTrackingScreen()),
          ),
          _framed(
            path: 'scan',
            builder: (context, state) =>
                const ModuleGateView(path: 'scan', child: PaperScanScreen()),
          ),
          _framed(
            path: 'sync-status',
            builder: (context, state) => const SyncStatusScreen(),
          ),
          // ⭐ মাথার ঘণ্টা — নিজের নোটিফিকেশন (মালিক, ৬ অক্টোবর ২০২৬)
          _framed(
            path: 'notifications',
            builder: (context, state) => NotificationsScreen(openId: state.uri.queryParameters['open']),
          ),
          // ⭐ প্রোফাইল মেনু — নিজের ছবি আর নিজের ফোনগুলো (অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)। ⓘ মডিউলের পর্দা নয়: খোলে ছবির
          // মেনু থেকে (মালিকের নমুনা, পাতা ২), তাই মডিউলের মেনুতে ঘর নেই আর মডিউলের সুইচে আটকায় না — `/me`-র মতোই নিজের জিনিস
          _framed(
            path: ProfileRoutes.profile,
            builder: (context, state) => const ProfileScreen(),
          ),
          _framed(
            path: ProfileRoutes.phones,
            builder: (context, state) => const MyPhonesScreen(),
          ),
          // ⭐ অফিসের SMS ফোন — প্রোফাইল থেকে, কেবল SMS-এর ফোনের চাবিওয়ালা মানুষের (নমুনা ধাপ ২, ১১ নভেম্বর ২০২৬)
          _framed(
            path: ProfileRoutes.smsGateway,
            builder: (context, state) => const SmsGatewayScreen(),
          ),
          // ⭐ বিক্রয় বিল আর চালান — তালিকা; একটা কাগজ খোলে `paper/invoice|challan/:id`-এ (মালিক, ১৩ অক্টোবর ২০২৬)
          _framed(
            path: 'invoices',
            builder: (context, state) => const ModuleGateView(
                path: 'invoices', child: SalesPaperListScreen(kind: SalesPaperKind.invoice)),
          ),
          _framed(
            path: 'challans',
            builder: (context, state) => const ModuleGateView(
                path: 'challans', child: SalesPaperListScreen(kind: SalesPaperKind.challan)),
          ),
          // ── ⭐ ফোনের নতুন মডিউল, ১৩ অক্টোবর ২০২৬ (মালিক: "app e baki module & menu") ──
          // মালিকের কেন্দ্র — ওয়েবের "আজ" পাতা, কেবল পড়া
          // ⭐ কোপাইলট — ওয়েবের একই ইঞ্জিন আর চাবি (মালিক, ১১ নভেম্বর ২০২৬: "কোপাইলটটা মোবাইল অ্যাপে একই বিল্ডে")
          _framed(
            path: 'copilot',
            builder: (context, state) =>
                const ModuleGateView(path: 'copilot', child: CopilotScreen()),
          ),
          _framed(
            path: 'owner-centre',
            builder: (context, state) =>
                const ModuleGateView(path: 'owner-centre', child: ExecutiveScreen()),
          ),
          // বাকি নিয়ন্ত্রণ — ড্যাশবোর্ড ও তালিকা; গ্রাহকের বাকির পাতা public_id-তে
          _framed(
            path: 'credit',
            builder: (context, state) =>
                const ModuleGateView(path: 'credit', child: CreditDeskScreen()),
            routes: [
              _framed(
                path: ':id',
                builder: (context, state) => ModuleGateView(
                  path: 'credit',
                  child: CustomerCreditScreen(customerId: state.pathParameters['id'] ?? ''),
                ),
              ),
            ],
          ),
          // নোটিশ বোর্ড — সবার, সুইচের বাইরে (সার্ভারেও নোটিশ কোনো মডিউলের সুইচে নেই)
          _framed(
            path: 'notices',
            builder: (context, state) => const NoticeBoardScreen(),
          ),
          // ⭐ "সাম্প্রতিক" থেকে খোলা কাগজ — DO, আদেশ, উদ্ধৃতি, আদায়, ক্রয়, ভাউচার, দাবি (মালিক, ১২ অক্টোবর ২০২৬);
          // সুইচ কাগজের নিজের তালিকার ([[PaperRoute]])
          _framed(
            path: 'paper/:kind/:id',
            builder: (context, state) => PaperRoute(
              kind: state.pathParameters['kind'] ?? '',
              id: state.pathParameters['id'] ?? '',
              extra: state.uri.queryParameters['k'],
            ),
          ),
          // ⭐ বাকি মডিউল, দ্বিতীয় ভাগ — অর্থ, ডকুমেন্টস, প্রমোশন, নিজের ছুটি ও স্লিপ (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)
          _framed(
            path: 'deposits',
            builder: (context, state) => const ModuleGateView(path: 'deposits', child: DepositListScreen()),
          ),
          _framed(
            path: 'bank-loans',
            builder: (context, state) => const ModuleGateView(path: 'bank-loans', child: LoanListScreen()),
          ),
          _framed(
            path: 'rents',
            builder: (context, state) => const ModuleGateView(path: 'rents', child: RentListScreen()),
          ),
          _framed(
            path: 'hand-loans',
            builder: (context, state) => ModuleGateView(
                path: 'hand-loans',
                child: Consumer(
                    builder: (context, ref, _) =>
                        HandLoanListScreen(canMove: ref.watch(authStateProvider).user?.can('finance.hand_loan.move') ?? false))),
          ),
          _framed(
            path: 'documents',
            builder: (context, state) => const ModuleGateView(path: 'documents', child: DocumentListScreen()),
          ),
          _framed(
            path: 'offers',
            builder: (context, state) => const ModuleGateView(path: 'offers', child: OffersScreen()),
          ),
          _framed(
            path: 'leave',
            builder: (context, state) => const ModuleGateView(path: 'leave', child: LeaveScreen()),
          ),
          // ── ⭐ ওয়েবের বাকি সব পর্দা, অ্যাপের ভিতরে (মালিক, ১৩ অক্টোবর ২০২৬: "web e na dhuke jar jar kaj 100% app ei korte pare") ──
          // ⓘ সুইচ সার্ভারে: মেনু-সারির `web` পথ আর একবারের চাবি দুটোই কোম্পানির সুইচ, মানুষের চাবি আর ফোনের মডিউল-সুইচ মানে
          _framed(
            path: '${WebPaths.segment}/:code',
            builder: (context, state) {
              final code = state.pathParameters['code'] ?? '';
              final webPath = WebPaths.webPathOf(code);
              final title = WebPaths.titleOf('${WebPaths.segment}/$code');
              // ⭐ ফোনের নিজের পর্দা থাকলে সেটাই, ওয়েব-জানালা নয় (মালিক, ১৭ অক্টোবর ২০২৬: "direct webpage app e bujajayna")
              return NativeForWeb.screenFor(webPath, title: title) ??
                  WebInsideScreen(webPath: webPath, title: title);
            },
          ),
          _framed(
            path: 'payslips',
            builder: (context, state) => const ModuleGateView(path: 'payslips', child: PayslipListScreen()),
          ),
        ],
      ),
    ],
  );
});

/// A `/home/<screen>` route with the bottom bar under its screen
/// ([ScreenFrame]). A plain [GoRoute] otherwise — same path, same builder, on
/// the root navigator, so the screen's own back arrow still goes one level up.
GoRoute _framed({
  required String path,
  required Widget Function(BuildContext context, GoRouterState state) builder,
  List<RouteBase> routes = const <RouteBase>[],
}) =>
    GoRoute(
      path: path,
      routes: routes,
      builder: (context, state) =>
          ScreenFrame(location: state.matchedLocation, child: builder(context, state)),
    );

/// ⭐ গ্রাহকের পাতার "বাকির অবস্থা" — বাকি নিয়ন্ত্রণের দেখার চাবি আর কোম্পানির ফোন-সুইচ (১৩ অক্টোবর ২০২৬)। ⓘ পাতা খোলার
/// মুহূর্তের উত্তর; দরজাটা তবু নিজে [ModuleGateView] আর সার্ভারের চাবিতে আটকায়।
bool _mayOpenCredit(BuildContext context) {
  final container = ProviderScope.containerOf(context, listen: false);
  return (container.read(authStateProvider).user?.can('credit_control.view') ?? false) &&
      ModuleGate.allows(container.read(phoneModulesProvider), 'credit');
}
