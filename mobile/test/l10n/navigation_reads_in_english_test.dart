import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/navigation/role_tabs.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_screen_bar.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/features/auth/login_screen.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';

/// ⭐ ইংরেজিতে অ্যাপ চললে পথের সব লেখা ইংরেজিতে (0.4.43, a4 l10n-x, ২১ অক্টোবর ২০২৬): নিচের বারের ঘর, ভেতরের পাতার বারের
/// "› মডিউল › পাতা" আর ঢোকার পর্দা — একটাও বাংলা অক্ষর নয়। বাংলায় একই জায়গা আগের মতোই বাংলা।
final RegExp _bengali = RegExp('[ঀ-৿]');

/// পর্দায় যা লেখা আছে — প্রতিটা Text, RichText আর Tooltip
List<String> _shown(WidgetTester tester) => [
      for (final t in tester.widgetList<Text>(find.byType(Text))) t.data ?? t.textSpan?.toPlainText() ?? '',
      for (final t in tester.widgetList<RichText>(find.byType(RichText))) t.text.toPlainText(),
      for (final t in tester.widgetList<Tooltip>(find.byType(Tooltip))) t.message ?? '',
    ];

Widget _app(Widget child, {Locale locale = const Locale('en')}) => MaterialApp(
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: child,
    );

class _NoAuth extends AuthController {
  @override
  Future<void> login({required String identifier, required String password, String? code}) async {}
}

void main() {
  setUp(() => AppLocale.current = AppLocale.english);
  tearDown(() => AppLocale.current = AppLocale.locale);

  testWidgets('the bottom bar: every tab of every role reads English', (tester) async {
    const tabs = BarTab.values;
    await tester.pumpWidget(_app(Scaffold(
      bottomNavigationBar: RoleBottomBar(
        tabs: const [BarTab.home, BarTab.approvals, BarTab.reports, BarTab.modules],
        selected: BarTab.home,
        badges: {BarTab.approvals: RoleTabs.badgeFor(5)},
        onSelect: (_) {},
      ),
    )));
    await tester.pumpAndSettle();
    // ⓘ ১৪ নভেম্বর ২০২৬ থেকে ফোনের বারে লেখা নেই — নাম থাকে লম্বা চাপের Tooltip-এ আর TalkBack-এ; সেটাই ইংরেজি হতে হবে
    for (final label in ['Home', 'Approvals', 'Reports', 'All modules']) {
      expect(find.byTooltip(label), findsOneWidget, reason: label);
    }
    expect(_shown(tester).where(_bengali.hasMatch), isEmpty);
    for (final tab in tabs) {
      expect(RoleTabs.specs[tab]!.label, isNot(matches(_bengali)), reason: '$tab');
    }
    expect(RoleTabs.spokenLabel(RoleTabs.specs[BarTab.approvals]!, RoleTabs.badgeFor(5)), 'Approvals, 5 waiting');
    expect(RoleTabs.spokenLabel(RoleTabs.specs[BarTab.approvals]!, RoleTabs.badgeFor(500)),
        'Approvals, more than 99 waiting');
  });

  testWidgets("an inner screen's bar: the crumbs read module › page in English", (tester) async {
    final crumbs = AbosScreenBar.crumbsFor(NavCatalog.moduleDef('sales')!.label, NavCatalog.screenDef('collections')!.label);
    expect(crumbs, ['Sales', 'Collections']);
    await tester.pumpWidget(_app(Scaffold(
      appBar: AbosTopBar(
        company: 'ADI Wholesale',
        companyCode: 'ADI',
        branch: 'Mymensingh',
        crumbs: crumbs,
        leading: TopBarLeading.back,
        onLeading: () {},
        onSearch: () {},
        onBell: () {},
        onMessages: () {},
        unread: 3,
        avatarName: 'Karim',
        onAvatar: (_) async {},
        demo: false,
        syncStatus: SyncStatus(pending: () => 0),
        pendingCount: () => 0,
      ),
    )));
    await tester.pumpAndSettle();
    expect(find.text('› Sales › Collections'), findsOneWidget);
    expect(find.byTooltip('Back'), findsOneWidget);
    expect(find.byTooltip('Search'), findsOneWidget);
    expect(_shown(tester).where(_bengali.hasMatch), isEmpty);
    expect(titleLabel('ADI Wholesale', 'Mymensingh', crumbs), 'ADI Wholesale, Mymensingh branch, Sales, Collections');
    // every module and every screen of the catalogue, not only the two on the bar
    for (final m in NavCatalog.modules) {
      expect(m.label, isNot(matches(_bengali)), reason: m.code);
    }
    for (final s in [...NavCatalog.screens, ...NavCatalog.outside]) {
      expect(s.label, isNot(matches(_bengali)), reason: s.path);
      expect(s.group, isNot(matches(_bengali)), reason: s.path);
    }
  });

  testWidgets('the sign-in screen reads English when the app does', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => _NoAuth())],
      child: _app(LoginScreen(
        now: () => DateTime.utc(2026, 10, 1, 4), // 10:00 in Dhaka
        readRemembered: () async => null,
        saveRemembered: (_) async {},
      )),
    ));
    await tester.pumpAndSettle();
    expect(find.text('Good morning'), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.text('Forgot password?'), findsOneWidget);
    // ⓘ কেবল ভাষার নিজের নাম "বাংলা" (পাদটীকার বাছাই) বাংলায় থাকে
    expect(_shown(tester).where(_bengali.hasMatch).toSet(), {'বাংলা'});
  });

  testWidgets('in Bengali the same places read exactly as before', (tester) async {
    AppLocale.current = AppLocale.locale;
    expect(RoleTabs.specs[BarTab.modules]!.label, 'সব মডিউল');
    expect(AbosScreenBar.crumbsFor(NavCatalog.moduleDef('sales')!.label, NavCatalog.screenDef('collections')!.label),
        ['বিক্রয়', 'টাকা আদায়']);
    await tester.pumpWidget(ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => _NoAuth())],
      child: _app(
          LoginScreen(now: () => DateTime.utc(2026, 10, 1, 4), readRemembered: () async => null, saveRemembered: (_) async {}),
          locale: AppLocale.locale),
    ));
    await tester.pumpAndSettle();
    expect(find.text('শুভ সকাল'), findsOneWidget);
    expect(find.text('প্রবেশ করুন'), findsOneWidget);
  });
}
