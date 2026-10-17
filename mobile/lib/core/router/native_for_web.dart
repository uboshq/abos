import 'package:flutter/widgets.dart';

import '../../features/dashboards/dashboards_screen.dart';
import '../../features/executive/executive_screen.dart';
import '../../features/papers/paper_screens.dart';
import '../menu/module_gate.dart';
import '../navigation/paper_kinds.dart';

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
    if (path == '/sales/overview') {
      return const SalesOverviewScreen();
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

    return null;
  }
}
