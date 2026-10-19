import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';

/// ⭐ অন্ধকার মোডের পাহারা (মোবাইলের নকশা ৩; মালিকের "হ্যাঁ", ১৯ অক্টোবর ২০২৬)।
///
/// <p>বাঁধা আলোর রং (`AppColors.surface`, `onSurface`, `border` …, `Colors.white/black/grey`) অন্ধকারে ভাঙে: থিমের হালকা
/// লেখা সাদা পটে মিলিয়ে যায়। [remaining] আজকের গোনা — একটা ফাইলের সংখ্যা কেবল কমতে পারে, নতুন ফাইল ঢুকতে পারে না।
/// তালিকা খালি হলে [AppTheme.mode] [ThemeMode.system] হবে। রঙিন পটের উপর সাদা আইকনের মতো যেটা দুই মোডেই ঠিক, সেই
/// লাইনে `// dark-ok` লিখে ছাড়।
const remaining = <String, int>{
  'lib/core/privacy/app_lock.dart': 2,
  'lib/core/widgets/app_status_pill.dart': 2,
  'lib/core/widgets/confirm_overview_sheet.dart': 1,
  'lib/core/widgets/list_controls.dart': 2,
  'lib/core/widgets/number_with_party.dart': 1,
  'lib/features/accounts_chart/chart_screens.dart': 5,
  'lib/features/approval_rows/approval_rows_screens.dart': 6,
  'lib/features/approvals/my_requests_screen.dart': 3,
  'lib/features/attendance/attendance_screen.dart': 2,
  'lib/features/auth/login_screen.dart': 4,
  'lib/features/books/books_widgets.dart': 2,
  'lib/features/books/principal_screens.dart': 1,
  'lib/features/claims/claims_screens.dart': 1,
  'lib/features/credit_control/credit_screens.dart': 15,
  'lib/features/customers/customer_detail_screen.dart': 3,
  'lib/features/customers/customer_ledger_screen.dart': 5,
  'lib/features/customers/customer_list_screen.dart': 1,
  'lib/features/customers/deposit_request_screen.dart': 2,
  'lib/features/customers/due_list_screen.dart': 1,
  'lib/features/dashboards/dashboards_screen.dart': 8,
  'lib/features/deliveries/deliveries_screen.dart': 2,
  'lib/features/delivery_orders/delivery_order_screens.dart': 3,
  'lib/features/documents/document_screens.dart': 3,
  'lib/features/documents/document_upload_screen.dart': 1,
  'lib/features/executive/executive_screen.dart': 4,
  'lib/features/executive/owner_alerts_screen.dart': 2,
  'lib/features/executive/owner_compare_screen.dart': 2,
  'lib/features/executive/owner_page_frame.dart': 3,
  'lib/features/executive/owner_reports_screen.dart': 1,
  'lib/features/finance/finance_screens.dart': 5,
  'lib/features/home/home_dashboard.dart': 4,
  'lib/features/home/home_shell.dart': 1,
  'lib/features/home/module_pages.dart': 12,
  'lib/features/home/nav_search.dart': 10,
  'lib/features/home/workspace_picker.dart': 2,
  'lib/features/hr_registers/hr_register_screens.dart': 3,
  'lib/features/loading/loading_screens.dart': 1,
  'lib/features/money/money_screens.dart': 2,
  'lib/features/notices/notice_board_screen.dart': 3,
  'lib/features/notifications/notifications_screen.dart': 2,
  'lib/features/orders/delivery_tracking_screen.dart': 6,
  'lib/features/orders/new_order_screen.dart': 10,
  'lib/features/orders/order_sheets.dart': 2,
  'lib/features/orders/rejected_order_card.dart': 1,
  'lib/features/papers/paper_screens.dart': 3,
  'lib/features/printing/document_actions_sheet.dart': 1,
  'lib/features/printing/printer_settings_screen.dart': 1,
  'lib/features/products/product_list_screen.dart': 1,
  'lib/features/promotion/offers_screen.dart': 1,
  'lib/features/quotations/quotation_compare_screens.dart': 2,
  'lib/features/reports/reports_screen.dart': 1,
  'lib/features/route/my_route_screen.dart': 1,
  'lib/features/sales_desk/sales_desk_screens.dart': 1,
  'lib/features/sales_papers/sales_paper_screens.dart': 6,
  'lib/features/sales_plan/sales_plan_screens.dart': 3,
  'lib/features/scan/paper_scan_screen.dart': 1,
  'lib/features/scan/receive_screen.dart': 1,
  'lib/features/self_service/self_service_screens.dart': 3,
  'lib/features/stock_writes/stock_write_screens.dart': 1,
  'lib/features/sync/sync_status_screen.dart': 2,
  'lib/features/today/today_panel.dart': 17,
  'lib/features/update/update_gate.dart': 1,
  'lib/features/web_inside/web_inside_screen.dart': 1,
};

final _fixed = RegExp(r'AppColors\.(surface|surfaceMuted|onSurface|onSurfaceMuted|border|primarySoft)\b|Colors\.(white|black|grey)\b');

Map<String, int> countFixedColours() {
  final out = <String, int>{};
  for (final f in Directory('lib').listSync(recursive: true).whereType<File>()) {
    final path = f.path.replaceAll(r'\', '/');
    if (!path.endsWith('.dart') || path.startsWith('lib/core/theme/')) continue;
    var n = 0;
    for (final line in f.readAsLinesSync()) {
      if (line.contains('// dark-ok')) continue;
      n += _fixed.allMatches(line).length;
    }
    if (n > 0) out[path] = n;
  }
  return out;
}

void main() {
  test('no screen gains a fixed light colour, and none comes back', () {
    final now = countFixedColours();
    expect(now, isNotEmpty, reason: 'গোনা চলছে কিনা — খালি হলে নিচের টেস্ট অন্ধকার চালু করতে বলবে');
    for (final MapEntry(key: path, value: n) in now.entries) {
      expect(remaining.containsKey(path), isTrue, reason: '⛔ $path: নতুন বাঁধা রং ($n) — colorScheme থেকে নাও');
      expect(n, lessThanOrEqualTo(remaining[path]!), reason: '⛔ $path: বাঁধা রং বেড়েছে ($n > ${remaining[path]})');
    }
  });

  test('dark mode stays off until every fixed colour is gone', () {
    if (countFixedColours().isEmpty) {
      expect(AppTheme.mode, ThemeMode.system, reason: 'সব পর্দা তৈরি — এখন অন্ধকার মোড চালু করো');
    } else {
      expect(AppTheme.mode, ThemeMode.light, reason: '⛔ বাঁধা রং থাকতে অন্ধকার চালু করলে লেখা মিলিয়ে যায়');
    }
  });

  test('the dark theme reads: body text 4.5:1 or more on the page, the card and the field', () {
    final cs = AppTheme.dark().colorScheme;
    for (final bg in [cs.surface, cs.surfaceContainerLow, cs.surfaceContainerHighest, cs.surfaceContainer]) {
      expect(AppColors.contrast(cs.onSurface, bg), greaterThanOrEqualTo(4.5));
      expect(AppColors.contrast(cs.onSurfaceVariant, bg), greaterThanOrEqualTo(4.5));
    }
    expect(AppColors.contrast(cs.onPrimary, cs.primary), greaterThanOrEqualTo(4.5));
    expect(AppColors.contrast(cs.error, cs.surface), greaterThanOrEqualTo(4.5));
    expect(AppTheme.dark().scaffoldBackgroundColor.computeLuminance(), lessThan(0.1), reason: 'পট সত্যিই অন্ধকার');
  });
}
