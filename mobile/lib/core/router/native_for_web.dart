import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../features/accounts_chart/chart_screens.dart';
import '../../features/approval_rows/approval_rows_screens.dart';
import '../../features/approvals/my_requests_screen.dart';
import '../../features/books/principal_commission_screen.dart';
import '../../features/claims/claims_screens.dart';
import '../../features/customers/customer_list_screen.dart';
import '../../features/dashboards/dashboards_screen.dart';
import '../../features/deliveries/deliveries_screen.dart';
import '../../features/delivery_orders/delivery_order_screens.dart';
import '../../features/direct_sale/counter_screen.dart';
import '../../features/documents/document_screens.dart';
import '../../features/documents/document_upload_screen.dart';
import '../../features/executive/executive_screen.dart';
import '../../features/executive/owner_alerts_screen.dart';
import '../../features/executive/owner_analysis_screen.dart';
import '../../features/executive/owner_compare_screen.dart';
import '../../features/executive/owner_history_screen.dart';
import '../../features/executive/owner_reports_screen.dart';
import '../../features/executive/sister_links_screen.dart';
import '../../features/finance/finance_screens.dart';
import '../../features/hr_registers/hr_register_screens.dart';
import '../../features/money/money_screens.dart';
import '../../features/orders/new_order_screen.dart';
import '../../features/orders/order_list_screen.dart';
import '../../features/papers/paper_screens.dart';
import '../../features/quotations/quotation_compare_screens.dart';
import '../../features/quotations/quotation_screens.dart';
import '../../features/sales_desk/coming_soon_screen.dart';
import '../../features/sales_desk/sales_desk_screens.dart';
import '../../features/sales_papers/sales_paper_screens.dart';
import '../../features/sales_plan/sales_plan_screens.dart';
import '../../features/stock/stock_list_screen.dart';
import '../../features/stock_writes/quality_serial_screens.dart';
import '../../features/stock_writes/stock_write_screens.dart';
import '../../features/customers/deposit_request_screen.dart';
import '../../features/vouchers/voucher_screens.dart';
import '../../features/web_inside/web_inside_screen.dart';
import '../accounts/voucher_api.dart';
import '../books/collection_entry.dart';
import '../auth/auth_state.dart';
import '../locale/app_locale.dart';
import '../menu/module_gate.dart';
import '../navigation/paper_kinds.dart';
import '../orders/delivery_order_api.dart';
import 'report_paths.dart';

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
  /// <p>⭐ `hr`-ও — মালিক, ১৭ অক্টোবর ২০২৬ ("ক"): HR-এর ড্যাশবোর্ড ফোনে, আর বেতনের প্রতিটা অঙ্ক সার্ভারেই বাদ ([PhoneAnswer],
  /// চুক্তি §৪)। ফোনে তাই লুকানোর কিছু নেই — যা আসে, তা-ই আঁকা।
  static const Set<String> dashboards = {
    'accounts', 'finance', 'purchase', 'inventory', 'customer', 'credit_control', 'sales',
    'promotion', 'approval', 'system_admin', 'documents', 'hr',
  };

  /// ⭐ লেনদেনের তালিকা — ওয়েবের পথ → কাগজের চাবি ([PaperListScreen], মালিক, ১৭ অক্টোবর ২০২৬; fe-র ধাপ ৪)।
  ///
  /// <p>ⓘ সরাসরি ক্রয় (`/purchase/direct`) ওয়েবে কেবল লেখার ফর্ম, তার ফল বিল — তাই ফোনে বিলের তালিকা। দর তালিকার চার রকম
  /// একই পথে, `?target=`-এ ([priceLists])।
  static const Map<String, String> papers = {
    '/purchase/orders': 'purchase_order',
    '/purchase/bills': 'purchase_bill',
    '/purchase/direct': 'purchase_bill',
    '/purchase/receipts': 'purchase_receipt',
    '/purchase/returns': 'purchase_return',
    '/purchase/requisitions': 'purchase_requisition',
    '/purchase/rfqs': 'purchase_rfq',
    '/purchase/contracts': 'purchase_contract',
    '/sales/returns': 'sales_return',
    '/sales/gate-passes': 'sales_gate_pass',
    '/sales/shipments': 'sales_shipment',
    '/sales/transport': 'sales_transport',
    '/sales/loading-sheets': 'sales_loading_sheet',
    '/inventory/transfers': 'inventory_transfer',
    '/accounts/inter-company': 'accounts_inter_company',
    '/finance/capital': 'finance_capital',
    '/finance/insurance': 'finance_insurance',
  };

  /// ⭐ মজুদের পাঁচ লেখা আর মেনে নেওয়ার অপেক্ষার গণনা — ওয়েবের পথ → ফোনের ফর্ম ([StockDoor]; মালিক, ১৭ অক্টোবর ২০২৬)
  static const Map<String, String> stockDoors = {
    '/inventory/stock/placement': 'place',
    '/inventory/stock/adjust': 'adjust',
    '/inventory/stock/issue': 'issue',
    '/inventory/stock/opening': 'opening',
    '/inventory/stock/lot': 'lot',
    '/inventory/counts': 'counts',
  };

  /// ⭐ উদ্ধৃতি, আদেশ, ডেলিভারি নিশ্চিত, মান যাচাই আর সিরিয়াল — ওয়েবের পথ → ফোনের পর্দা (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
  ///
  /// <p>ⓘ উদ্ধৃতি, আদেশ আর ডেলিভারির পর্দা ফোনে আগে থেকেই ছিল (রাউটারের `quotations`, `orders`, `new-order`, `deliveries`) — এখানে
  /// কেবল ওয়েবের মেনু-সারি তাদের দিকে, একই মডিউল-সুইচের পেছনে। ⚠️ ওয়েবের "ডেলিভারি নিশ্চিত" ধাপের বোর্ড; ফোনের পর্দা পথের
  /// চালান বুঝিয়ে দেওয়া — নিশ্চিত করার কাজটা ওখানেই, বোর্ডের হাতে ধাপ বদল ওয়েবে।
  static const Set<String> desks = {
    '/sales/quotations', '/sales/quotations/create', '/sales/quotations/compare', '/sales/quotations/revisions',
    '/sales/orders', '/sales/orders/create', '/sales/deliveries', '/inventory/quality', '/inventory/serials',
    // ⛔ ওয়েবের পুরনো "নতুন আদায়"-এর লিংক (বিজ্ঞপ্তি, উইজেট) → টাকা জমার খবর; ফোনে আদায় লেখা নেই (মালিক, ২০ নভেম্বর ২০২৬:
    // টাকা নেওয়ার এক কাগজ; আগে আদায়ের এক পাতা, ২১ অক্টোবর ২০২৬)
    '/sales/collections/create',
  };

  /// ⭐ টাকার হাতবদল, নগদ গোনা আর অর্থের পাতা — ওয়েবের পথ → ফোনের পর্দা (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
  ///
  /// <p>ⓘ জমা, ব্যাংক ঋণ, হাতধার আর ভাড়ার পর্দা ফোনে আগে থেকেই ছিল — এখানে কেবল ওয়েবের মেনু-সারি তাদের দিকে। ⚠️ লাভ ভাগ আর
  /// প্রতিষ্ঠানের তালিকা আপাতত ওয়েব-জানালায় (লাভ ঘোষণা দুই খাতে বড় অঙ্ক, ফর্ম ওয়েবেই)।
  static const Set<String> moneyDesks = {
    '/accounts/money-custody', '/accounts/money-transfers', '/accounts/cash-counts',
    '/finance/cfo', '/finance/cash-forecast', '/finance/income', '/finance/budget',
    '/finance/bank-facilities', '/finance/hand-loans', '/finance/deposits/bank', '/finance/rentals', '/finance/tenancies',
  };

  static Widget? _money(String path) {
    bool can(WidgetRef ref, String key) => ref.watch(authStateProvider).user?.can(key) ?? false;

    return switch (path) {
      '/accounts/money-custody' => const ModuleGateView(path: 'vouchers', child: CustodyScreen()),
      '/accounts/money-transfers' => const ModuleGateView(path: 'vouchers', child: TransferListScreen()),
      '/accounts/cash-counts' => const ModuleGateView(path: 'vouchers', child: CashCountListScreen()),
      '/finance/cfo' => const ModuleGateView(path: 'deposits', child: CfoScreen()),
      '/finance/cash-forecast' => const ModuleGateView(path: 'deposits', child: CashForecastScreen()),
      '/finance/income' => const ModuleGateView(path: 'deposits', child: IncomeScreen()),
      '/finance/budget' => const ModuleGateView(path: 'deposits', child: BudgetScreen()),
      '/finance/bank-facilities' => const ModuleGateView(path: 'bank-loans', child: LoanListScreen()),
      '/finance/hand-loans' => ModuleGateView(
          path: 'hand-loans',
          child: Consumer(builder: (context, ref, _) => HandLoanListScreen(canMove: can(ref, 'finance.hand_loan.move')))),
      '/finance/deposits/bank' => const ModuleGateView(path: 'deposits', child: DepositListScreen()),
      '/finance/rentals' || '/finance/tenancies' => const ModuleGateView(path: 'rents', child: RentListScreen()),
      _ => null,
    };
  }

  static Widget? _desk(String path) {
    bool can(WidgetRef ref, String key) => ref.watch(authStateProvider).user?.can(key) ?? false;

    return switch (path) {
      '/sales/quotations' => ModuleGateView(
          path: 'quotations',
          child: Consumer(builder: (context, ref, _) => QuotationListScreen(canWrite: can(ref, 'sales.quotation.create')))),
      '/sales/quotations/create' => const ModuleGateView(path: 'quotations', child: NewQuotationScreen()),
      '/sales/quotations/compare' => const ModuleGateView(path: 'quotations', child: QuotationCompareScreen()),
      '/sales/quotations/revisions' => const ModuleGateView(path: 'quotations', child: QuotationRevisionsScreen()),
      '/sales/orders' => const ModuleGateView(path: 'orders', child: OrderListScreen()),
      '/sales/orders/create' => const ModuleGateView(path: 'new-order', child: NewOrderScreen()),
      '/sales/deliveries' => const ModuleGateView(path: 'deliveries', child: DeliveriesScreen()),
      '/sales/collections/create' => const ModuleGateView(path: 'deposit-report', child: DepositRequestScreen()),
      '/inventory/quality' => ModuleGateView(
          path: 'stock', child: Consumer(builder: (context, ref, _) => QualityListScreen(canCreate: can(ref, 'inventory.qc.create')))),
      '/inventory/serials' => ModuleGateView(
          path: 'stock', child: Consumer(builder: (context, ref, _) => SerialScreen(canManage: can(ref, 'inventory.serial.manage')))),
      _ => null,
    };
  }

  /// ⭐ a5-এর ভাগ — ওয়েব-পথ → ফোনের নিজের পর্দা; মালিকের কেন্দ্র মডিউলের ফটক মানে, `/owner`-এর মতো
  static const Map<String, Widget> ownerAndPlan = {
    '/owner/compare': ModuleGateView(path: 'owner-centre', child: OwnerCompareScreen()),
    '/owner/analysis': ModuleGateView(path: 'owner-centre', child: OwnerAnalysisScreen()),
    '/owner/alerts': ModuleGateView(path: 'owner-centre', child: OwnerAlertsScreen()),
    '/owner/history': ModuleGateView(path: 'owner-centre', child: OwnerHistoryScreen()),
    '/owner/reports': ModuleGateView(path: 'owner-centre', child: OwnerReportsScreen()),
    '/owner/sister-links': ModuleGateView(path: 'owner-centre', child: SisterLinksScreen()),
    '/sales/targets': SalesTargetsScreen(),
    '/sales/dealer-targets': DealerTargetsScreen(),
    '/sales/routes': RouteBookScreen(),
    '/sales/schemes': SchemeListScreen(),
    '/sales/commissions': CommissionListScreen(),
  };

  /// ⭐ প্রিন্সিপালের কমিশনের ওয়েব-পথ — ওয়েবের আসল পথ (`/suppliers/reports/principal-commission`, SupplierReportController)
  /// আর চুক্তির `web_url`-এর বানান (`/supplier/…`), দুটোই এক পর্দায়
  static const Set<String> principalCommissionPaths = {
    '/suppliers/reports/principal-commission',
    '/supplier/reports/principal-commission',
  };

  /// দর তালিকার চার রকম — ওয়েবের `/sales/price-lists?target=`
  static const Set<String> priceLists = {'customer', 'tier', 'territory', 'all'};

  /// এই ওয়েব-পথের ফোন-পর্দা, বা null — তখন ওয়েব-জানালা। [title] মেনুর নিজের নাম।
  static Widget? screenFor(String? webPath, {String title = ''}) {
    if (webPath == null) return null;
    final path = Uri.parse(webPath).path.replaceAll(RegExp(r'/+$'), '');

    if (path == '/owner') {
      return const ModuleGateView(path: 'owner-centre', child: ExecutiveScreen());
    }
    // ⭐ প্রিন্সিপালের কমিশন — ওয়েবের রিপোর্টের পথ (নোটিশ, ড্যাশবোর্ডের "সব দেখুন", মেনু) ফোনের নিজের পর্দায়, মাসসহ
    // (মালিকের আদেশ "app e dekhate hobe", ২০ অক্টোবর ২০২৬); রিপোর্টের সাধারণ পর্দার আগে
    if (principalCommissionPaths.contains(path)) {
      return ModuleGateView(
          path: 'principal-commission',
          child: PrincipalCommissionScreen(month: Uri.parse(webPath).queryParameters['month']));
    }
    // ⭐ a5-এর ভাগ — মালিকের কেন্দ্রের ছয় পাতা আর বিক্রয়ের পরিকল্পনার পাঁচ পাতা, শুধু দেখা; ওয়েবের একই সেবা আর চাবি
    // (মালিক, ১৭ অক্টোবর ২০২৬: "অ্যাপে ১০০%"; fe ১৯ অক্টোবর, a6-এর সাথে ভাগ — রিপোর্টের সারি a6-এর ব্লকে)
    final a5 = ownerAndPlan[path];
    if (a5 != null) return a5;
    if (path == '/sales/overview') {
      return const SalesOverviewScreen();
    }
    // ⭐ হিসাবের ছক আর তার খাতা — ওয়েবের একই সেবা ([AccountLedgerApi]; মালিক, ১৭ অক্টোবর ২০২৬)
    if (path == '/accounts/chart-of-accounts') {
      return const ChartOfAccountsScreen();
    }
    // ⭐ অনুমোদন-ভাগের তিন সারি — সইয়ের ভার, দলিলের অনুমোদনের সারি, সই কেন্দ্র (মালিক, ১৭ অক্টোবর ২০২৬)
    if (path == '/approvals/delegations') {
      return const DelegationScreen();
    }
    if (path == '/documents/approval') {
      return const PaperQueueScreen(kind: PaperQueueKind.documents);
    }
    if (path == '/documents/signatures') {
      return const PaperQueueScreen(kind: PaperQueueKind.signatures);
    }
    // ⭐ কাউন্টার আর বিক্রয়ের ডেস্ক-পাতা, দলিলের ছাঁচ (মালিক, ১৭ অক্টোবর ২০২৬; fe-র পাঠানো /me-এর পথ হুবহু)
    if (path == '/sales/direct') {
      return const ModuleGateView(path: 'counter', child: CounterScreen());
    }
    if (path == '/sales/direct/drafts') {
      return const ModuleGateView(path: 'counter', child: CounterScreen(openDrafts: true));
    }
    if (path == '/sales/direct/depot-check') {
      return const DepotCheckScreen();
    }
    if (path == '/sales/print-queue') {
      return const PrintQueueScreen();
    }
    if (path == '/sales/delivery-performance') {
      return const DeliveryPerformanceScreen();
    }
    if (path == '/sales/opportunities/pipeline') {
      return const PipelineScreen();
    }
    if (path == '/documents/templates') {
      return const TemplateListScreen();
    }
    // ⭐ কাগজ তোলা (গ্যালারি) আর ক্যামেরায় স্ক্যান — ওয়েবের তোলার একই নিয়ম (মালিক, ১৭ অক্টোবর ২০২৬)
    if (path == '/documents/upload') {
      return const ModuleGateView(path: 'documents', child: DocumentUploadScreen());
    }
    if (path == '/documents/scan') {
      return const ModuleGateView(path: 'documents', child: DocumentUploadScreen(scan: true));
    }
    // ⭐ দলিলের আটটা তালিকা — একই ফোন-পর্দা, মেনুর ঘর বাছা অবস্থায় (মালিক, ১৭ অক্টোবর ২০২৬)
    final library = RegExp(r'^/documents/(center|mine|shared|recent|expiry|archive|recycle|search)$').firstMatch(path)?.group(1);
    if (library != null) {
      return ModuleGateView(path: 'documents', child: DocumentListScreen(initialView: library));
    }
    // ⭐ HR — হাজিরা আর ছুটির রেজিস্টার (নাগালের সব কর্মী), নিজের খরচের দাবি; ⛔ বেতন (`/hr/payroll`) ওয়েবেই (fe, ১৭ অক্টোবর ২০২৬)
    if (path == '/hr/attendance') {
      return const AttendanceRegisterScreen();
    }
    if (path == '/hr/leave') {
      return const LeaveRegisterScreen();
    }
    if (path == '/hr/claims') {
      return const ModuleGateView(path: 'claims', child: ClaimListScreen());
    }
    // ⓘ ওয়েবের "planned" — এখনো তৈরি হয়নি এমন পর্দা; ফোনে স্পষ্ট "শীঘ্রই আসছে", ওয়েব-জানালা নয় (fe)
    if (path.startsWith('/sales/planned/')) {
      return ComingSoonScreen(title: title);
    }

    final desk = _desk(path);
    if (desk != null) return desk;

    final money = _money(path);
    if (money != null) return money;

    final stock = stockDoors[path];
    if (stock != null) {
      return ModuleGateView(path: 'stock', child: StockDoor(key: ValueKey('stock-web-$stock'), open: stock));
    }

    final target = Uri.parse(webPath).queryParameters['target'];
    final paper = papers[path] ??
        (path == '/sales/price-lists' && priceLists.contains(target) ? 'sales_price_list_$target' : null);
    if (paper != null) {
      return ModuleGateView(path: PaperKinds.all[paper]!.$1, child: PaperListScreen(paperKey: paper, title: title));
    }

    final dashboard = RegExp(r'^/dashboard/([a-z_]+)$').firstMatch(path)?.group(1);
    if (dashboard != null && dashboards.contains(dashboard)) {
      return ModuleDashboardScreen(module: dashboard, name: title);
    }

    // ⭐ রিপোর্টের সারি — ফোনের রিপোর্ট-পর্দা, চাবি ধরে ([ReportPaths]; a6, ১৯ অক্টোবর ২০২৬)
    final report = ReportPaths.screenFor(path, title: title);
    if (report != null) return report;

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
    // ⓘ ওয়েবের "প্রিন্সিপালের কমিশন" সারি = ফোনের একই নামের ঘর (২০ অক্টোবর ২০২৬)
    if (principalCommissionPaths.contains(Uri.parse(webPath).path.replaceAll(RegExp(r'/+$'), ''))) {
      return 'principal-commission';
    }
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
    if (principalCommissionPaths.contains(path)) return Symbols.percent;
    if (path.startsWith('/owner/') && ownerAndPlan.containsKey(path)) return Icons.insights_outlined;
    if (ownerAndPlan.containsKey(path)) return Icons.flag_outlined;
    final dashboard = RegExp(r'^/dashboard/([a-z_]+)$').firstMatch(path)?.group(1);
    if (dashboard != null && dashboards.contains(dashboard)) return Icons.dashboard_outlined;
    if (path.startsWith('/accounts/vouchers/') || path == '/accounts/voucher-list') return Icons.receipt_outlined;
    if (path == '/sales/invoices' || path == '/sales/challans') return Icons.receipt_long_outlined;
    if (path == '/sales/delivery-orders') return Icons.local_shipping_outlined;
    if (path == '/customers') return Icons.people_alt_outlined;
    if (path == '/inventory/stock') return Icons.warehouse_outlined;
    // ⓘ ক্রয়, বিক্রয়ের বাকি কাগজ আর স্থানান্তর — ফোনের নিজের তালিকা ([papers])
    if (papers.containsKey(path) || path == '/sales/price-lists') return Icons.description_outlined;
    if (stockDoors.containsKey(path)) return Icons.inventory_outlined;
    if (desks.contains(path)) return Icons.assignment_outlined;
    if (moneyDesks.contains(path)) return Icons.account_balance_wallet_outlined;
    if (path == '/approvals/mine') return Icons.outbox_outlined;
    return null;
  }

  static Widget? _papers(String webPath, String path, String title) {
    final query = Uri.parse(webPath).queryParameters;
    final named = title.isEmpty || title == 'ABOS' ? null : title;

    final type = RegExp(r'^/accounts/vouchers/([a-z_-]+)$').firstMatch(path)?.group(1);
    if (type != null && _voucherViews.containsKey(type)) {
      return ModuleGateView(
          path: 'vouchers',
          child: Consumer(
              builder: (context, ref, _) => VoucherListScreen(
                  key: ValueKey('vouchers-web-$type'),
                  view: _voucherViews[type],
                  canCreate: ref.watch(authStateProvider).user?.can('accounts.voucher.create') ?? false,
                  mayCollect: ref.watch(mayCollectProvider))));
    }
    if (path == '/accounts/voucher-list') {
      return ModuleGateView(
          path: 'vouchers',
          child: Consumer(
              builder: (context, ref, _) => VoucherListScreen(
                  canCreate: ref.watch(authStateProvider).user?.can('accounts.voucher.create') ?? false,
                  mayCollect: ref.watch(mayCollectProvider))));
    }

    if (path == '/sales/invoices') {
      final cancelled = query['stage'] == 'cancelled' || query['cancelled'] == '1';
      return ModuleGateView(
        path: 'invoices',
        child: SalesPaperListScreen(
          key: ValueKey('invoices-web-$cancelled'),
          kind: SalesPaperKind.invoice,
          api: ServerSalesPaperApi(cancelled: cancelled),
          title: cancelled ? (named ?? AppLocale.l10n.navScreenCancelledInvoices) : null,
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
