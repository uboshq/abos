import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/profile/me_profile_api.dart';
import '../fakes/fake_me_profile_api.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/home/quick_actions.dart';
import 'package:abos_mobile/features/menu/menu_page.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../support/bar_fonts.dart';

/// ≡ মেনুর পরীক্ষার মোড়ক — রূপের থিম, গ্লাসে কাচের পট (main.dart-এর একই), [FakeMeProfileApi] আর `/me`-র features
ThemeData themeOf(AppLook look) => withBarFonts(switch (look) {
      AppLook.glass => AppTheme.glass(GlassSpec.of(AppLooks.instance.blur.value)),
      AppLook.expressive => AppTheme.expressive(),
      AppLook.abos => AppTheme.light(),
    });

Widget menuHost(
  AppLook look,
  Widget page, {
  MeFeatures? features = const MeFeatures(profilePage: true),
  MeProfileApi? api,
}) {
  AppLooks.instance.look.value = look;
  return ProviderScope(
    overrides: [
      meProfileApiProvider.overrideWithValue(api ?? FakeMeProfileApi()),
      meFeaturesProvider.overrideWith((ref) => features),
    ],
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: themeOf(look),
      locale: AppLocale.locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      builder: (context, inner) => look == AppLook.glass ? GlassBackdrop(child: inner!) : inner!,
      home: Scaffold(backgroundColor: look == AppLook.glass ? Colors.transparent : null, body: page),
    ),
  );
}

/// দশটা শর্টকাট — প্রথম ৮টা দেখায়, বাকি ২টা "আরও দেখুন"-এ
final List<QuickAction> tenShortcuts = [
  for (final (i, (icon, path)) in <(IconData, String)>[
    (Symbols.payments, 'deposit-report'),
    (Symbols.add_shopping_cart, 'new-order'),
    (Symbols.fact_check, 'approvals'),
    (Symbols.insights, 'today'),
    (Symbols.storefront, 'customers'),
    (Symbols.inventory_2, 'stock'),
    (Symbols.calculate, 'vouchers'),
    (Symbols.bar_chart, 'reports'),
    (Symbols.local_shipping, 'purchases'),
    (Symbols.badge, 'payslips'),
  ].indexed)
    QuickAction(icon: icon, path: path, label: 'Shortcut ${i + 1} with a longer name'),
];

const List<RecentEntry> threeRecents = [
  RecentEntry(path: 'paper/money-in/42', label: 'RCV-0142 · Rahim Store'),
  RecentEntry(path: 'day-close', label: 'Day close'),
  RecentEntry(path: 'orders', label: 'Sales list'),
  RecentEntry(path: 'customers', label: 'Fourth — not shown'),
];

/// সব callback-সহ পাতা; [log] প্রতিটা চাপ লেখে
MenuPage fullMenu(
  List<String> log, {
  bool allBranches = false,
  int? approvals = 5,
  int? notices = 7,
  List<QuickAction>? shortcuts,
}) =>
    MenuPage(
      name: 'Shuvo Ahmed',
      designation: 'Owner',
      company: 'ADI Corporation',
      branch: 'TD Branch',
      allBranches: allBranches,
      onOpenProfile: () => log.add('profile'),
      onSwitchWorkspace: () => log.add('switch'),
      shortcuts: shortcuts ?? tenShortcuts,
      shortcutCounts: const {'approvals': 3},
      onOpenPath: (path, label) async => log.add('open:$path'),
      recents: threeRecents,
      approvalsWaiting: approvals,
      unreadNotices: notices,
      onApprovals: () => log.add('approvals'),
      onNotices: () => log.add('notices'),
      onDayClose: () => log.add('day-close'),
      onHelpCentre: () => log.add('help-centre'),
      onReportProblem: () => log.add('report-problem'),
      onLookAndColour: () => log.add('look'),
      onLanguage: () => log.add('language'),
      onFingerprint: () => log.add('fingerprint'),
      onScreenshot: () => log.add('screenshot'),
      onNotifications: () => log.add('notifications'),
      onPrinter: () => log.add('printer'),
      onSyncStatus: () => log.add('sync-status'),
      onCheckUpdate: () => log.add('check-update'),
      onLogout: () => log.add('logout'),
      version: '0.4.62',
    );
