import 'package:flutter/widgets.dart';

import '../../features/accounts_chart/chart_screens.dart';
import '../../features/approval_rows/approval_rows_screens.dart';
import '../../features/dashboards/dashboards_screen.dart';
import '../../features/direct_sale/counter_screen.dart';
import '../../features/sales_desk/coming_soon_screen.dart';
import '../../features/sales_desk/sales_desk_screens.dart';
import '../../features/executive/executive_screen.dart';
import '../menu/module_gate.dart';

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
    // ⓘ ওয়েবের "planned" — এখনো তৈরি হয়নি এমন পর্দা; ফোনে স্পষ্ট "শীঘ্রই আসছে", ওয়েব-জানালা নয় (fe)
    if (path.startsWith('/sales/planned/')) {
      return ComingSoonScreen(title: title);
    }

    final dashboard = RegExp(r'^/dashboard/([a-z_]+)$').firstMatch(path)?.group(1);
    if (dashboard != null && dashboards.contains(dashboard)) {
      return ModuleDashboardScreen(module: dashboard, name: title);
    }

    return null;
  }
}
