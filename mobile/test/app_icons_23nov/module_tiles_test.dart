import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_module.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/copilot/copilot_mark.dart';
import 'package:abos_mobile/features/home/module_pages.dart';

import '../support/bar_fonts.dart';

/// ⭐ মডিউলের আইকন আর টাইল — মালিকের অনুমোদিত নমুনা v2 (২৩ নভেম্বর ২০২৬): ১৩টা টাইল এই ক্রমে, এক করা টাইলের নাম
/// সার্ভারের, Copilot নিজের টাইলে পট ছাড়া, অতিথির পর্দা পুরনো নামেও খোঁজায়।

MenuItem _item(String path, String label) => MenuItem(key: path, label: label, icon: Icons.circle, routeName: path);

/// The phone's own screens a super admin opens — one or more in every module.
List<MenuItem> get _native => [
      _item('new-order', 'নতুন অর্ডার'),
      _item('customers', 'গ্রাহক তালিকা'),
      _item('credit', 'বাকির হিসাব'),
      _item('offers', 'চলতি অফার'),
      _item('purchases', 'ক্রয়'),
      _item('principal-commission', 'প্রিন্সিপালের কমিশন'),
      _item('stock', 'হাতে থাকা মজুদ'),
      _item('vouchers', 'সব ভাউচার'),
      _item('deposits', 'জমা ও সঞ্চয়'),
      _item('approvals', 'অনুমোদন'),
      _item('attendance', 'হাজিরা'),
      _item('reports', 'রিপোর্ট'),
      _item('documents', 'ডকুমেন্টস'),
      _item('owner-centre', 'আজ — গোটা গ্রুপ'),
      _item('notices', 'নোটিশ বোর্ড'),
      _item('copilot', 'কোপাইলট'),
    ];

Map<String, dynamic> _row(String label, String route, String web, [String? module]) =>
    {'label': label, 'route': route, 'planned': false, 'web': web, if (module != null) 'module': module};

/// `/me`'s menu after the web merges (20 and 23 Nov 2026) — guests' rows name their own module.
List<MenuModule> get _mergedMe => [
      for (final m in [
        {'code': 'sales', 'label': 'বিক্রয়', 'groups': {
          'transactions': [_row('বিক্রয় আদেশ', 'sales.order.index', '/sales/orders')],
          'customer:master': [_row('গ্রাহক', 'customer.index', '/customers', 'customer')],
        }},
        {'code': 'purchase', 'label': 'ক্রয়', 'groups': {
          'supplier:master': [_row('সরবরাহকারী', 'supplier.index', '/suppliers', 'supplier')],
        }},
        {'code': 'accounts', 'label': 'হিসাব ও অর্থ', 'groups': {
          'finance:master': [_row('ব্যাংক হিসাব', 'finance.bank.index', '/finance/banks', 'finance')],
          'fixed_assets:master': [_row('সম্পদের তালিকা', 'fixed_assets.asset.index', '/fixed-assets', 'fixed_assets')],
        }},
        {'code': 'notification', 'label': 'যোগাযোগ', 'groups': {
          'messaging:transactions': [_row('বার্তা ও কল', 'messaging.index', '/messages', 'messaging')],
        }},
        {'code': 'system_admin', 'label': 'সিস্টেম ও প্রশাসন', 'groups': {
          'settings': [_row('ব্যবহারকারী', 'system_admin.user.index', '/admin/users')],
          'governance:reports': [_row('অডিট লগ', 'governance.audit.index', '/governance/audit', 'governance')],
          'backup:settings': [_row('ব্যাকআপ চালান', 'backup.run.index', '/backup', 'backup')],
        }},
        {'code': 'master_data', 'label': 'মাস্টার ডাটা', 'groups': {
          'master': [_row('একক', 'master_data.unit.index', '/master/units')],
        }},
      ])
        MenuModule.fromJson(m),
    ];

/// An older server's `/me` — no row module, every module on its own, the old names.
List<MenuModule> get _oldMe => [
      for (final m in [
        {'code': 'finance', 'label': 'অর্থ', 'groups': {
          'master': [_row('ব্যাংক হিসাব', 'finance.bank.index', '/finance/banks')],
        }},
        {'code': 'notification', 'label': 'বিজ্ঞপ্তি', 'groups': {
          'master': [_row('আমার নোটিশ', 'notification.mine', '/notifications/mine')],
        }},
        {'code': 'system_admin', 'label': 'সিস্টেম প্রশাসন', 'groups': {
          'settings': [_row('ব্যবহারকারী', 'system_admin.user.index', '/admin/users')],
        }},
        {'code': 'governance', 'label': 'নিয়ন্ত্রণ ও নিরীক্ষা', 'groups': {
          'reports': [_row('অডিট লগ', 'governance.audit.index', '/governance/audit')],
        }},
        {'code': 'backup', 'label': 'ব্যাকআপ ও পুনরুদ্ধার', 'groups': {
          'settings': [_row('ব্যাকআপ চালান', 'backup.run.index', '/backup')],
        }},
        {'code': 'master_data', 'label': 'মাস্টার ডাটা', 'groups': {
          'master': [_row('একক', 'master_data.unit.index', '/master/units')],
        }},
      ])
        MenuModule.fromJson(m),
    ];

NavTree _treeOf(List<MenuModule> me) {
  const repo = MenuRepository();
  final native = _native;
  return NavTree.from([...native, ...repo.webTiles(me, native)], server: ServerModules.of(me));
}

const _approvedOrder = [
  'sales', 'purchase', 'inventory', 'accounts', 'approval', 'hr', 'reports', 'documents', 'executive',
  'notification', 'assistant', 'system_admin', 'master_data',
];

void main() {
  test('the server says which guest sits in which host, and the merged names', () {
    final server = ServerModules.of(_mergedMe);
    expect(server.hosts, {
      'customer': 'sales',
      'supplier': 'purchase',
      'finance': 'accounts',
      'fixed_assets': 'accounts',
      'messaging': 'notification',
      'governance': 'system_admin',
      'backup': 'system_admin',
    });
    expect(server.mergedLabels['accounts'], 'হিসাব ও অর্থ');
    expect(server.mergedLabels['notification'], 'যোগাযোগ');
    expect(server.mergedLabels['system_admin'], 'সিস্টেম ও প্রশাসন');
    expect(server.mergedLabels.containsKey('master_data'), isFalse, reason: 'no guest, no merged name');
    expect(ServerModules.of(_oldMe).isEmpty, isTrue, reason: 'an older server merged nothing');
  });

  test('the approved 13 tiles, in order, merged names from the server', () {
    final tree = _treeOf(_mergedMe);
    expect(tree.modules.map((m) => m.code), _approvedOrder);
    expect(tree.modules.map((m) => m.label), [
      'বিক্রয়', 'ক্রয়', 'মজুদ', 'হিসাব ও অর্থ', 'অনুমোদন', 'কর্মী', 'রিপোর্ট', 'নথি', 'মালিকের কেন্দ্র',
      'যোগাযোগ', 'Copilot', 'সিস্টেম ও প্রশাসন', 'মাস্টার ডাটা',
    ]);
    expect(tree.modules.map((m) => m.def.icon).toList(), [
      Icons.shopping_cart,
      Icons.shopping_bag_outlined,
      Icons.view_in_ar_outlined,
      Icons.account_balance_wallet_outlined,
      Icons.approval_outlined,
      Icons.groups_outlined,
      Icons.bar_chart_rounded,
      Icons.folder_copy_outlined,
      Icons.space_dashboard_outlined,
      Icons.mark_chat_unread_outlined,
      Icons.psychology_outlined, // ⓘ drawn as the live mark, never shown
      Icons.admin_panel_settings_outlined,
      Icons.dataset_outlined,
    ]);
    // ⭐ অতিথির সারি আশ্রয়দাতার টাইলে — কোনো সারি হারায় না
    expect(tree.screen('customers')!.module, 'sales');
    expect(tree.screen('credit')!.module, 'sales');
    expect(tree.screen('offers')!.module, 'sales');
    expect(tree.screen('principal-commission')!.module, 'purchase');
    expect(tree.screen('deposits')!.module, 'accounts');
    for (final guest in ['customer', 'credit_control', 'promotion', 'supplier', 'finance', 'fixed_assets',
      'governance', 'backup', 'messaging']) {
      expect(tree.module(guest), isNull, reason: '$guest has no tile of its own while its host is there');
    }
    expect(tree.module('system_admin')!.screens.map((r) => r.label), containsAll(['ব্যবহারকারী', 'অডিট লগ', 'ব্যাকআপ চালান']));
    expect(tree.module('notification')!.screens.map((r) => r.label), containsAll(['নোটিশ বোর্ড']));
  });

  test('system and master data wear the sample\'s slate, and a white icon reads on it', () {
    for (final code in ['system_admin', 'master_data']) {
      expect(AppColors.moduleColor(code), const Color(0xFF475569), reason: code);
      expect(AppColors.moduleSolid(code), AppColors.moduleColor(code), reason: '$code already passes 3:1 — not darkened');
      expect(AppColors.contrast(const Color(0xFFFFFFFF), AppColors.moduleSolid(code)), greaterThanOrEqualTo(3.0));
    }
  });

  test('an older /me: the same tiles, the app\'s own names where the server merged nothing', () {
    final tree = _treeOf(_oldMe);
    expect(tree.modules.map((m) => m.code), _approvedOrder);
    expect(tree.module('accounts')!.label, 'হিসাব', reason: 'NavCatalog name — the server sent no merged one');
    expect(tree.module('notification')!.label, 'নোটিশ');
    expect(tree.module('sales')!.label, 'বিক্রয়');
    expect(tree.module('system_admin')!.label, 'সিস্টেম প্রশাসন', reason: 'the server\'s own name for it, as before');
    expect(tree.module('finance'), isNull);
    expect(tree.module('governance'), isNull);
    expect(tree.module('backup'), isNull);
    expect(tree.screen('deposits')!.module, 'accounts');
  });

  test('a guest whose host is not there keeps its own tile, as the web does', () {
    final tree = NavTree.from([_item('customers', 'গ্রাহক তালিকা')]);
    expect(tree.modules.map((m) => m.code), ['customer', 'reports']);
    expect(tree.module('customer')!.label, 'গ্রাহক');
  });

  test('Copilot gets its own tile, drawn with its live mark', () {
    final tree = NavTree.from([_item('copilot', 'কোপাইলট')]);
    final copilot = tree.module('assistant');
    expect(copilot, isNotNull, reason: 'its rows used to be dropped — no tile');
    expect(copilot!.label, 'Copilot');
    expect(copilot.def.mark, isNotNull);
    expect(tree.screen('copilot'), isNotNull);
  });

  testWidgets('the Copilot tile has no disc behind its mark', (tester) async {
    final tree = NavTree.from([_item('copilot', 'কোপাইলট'), _item('new-order', 'নতুন অর্ডার')]);
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: AllModulesPage(modules: tree.modules, counts: const NavCounts(), onOpen: (_) {}, onRefresh: () async {})),
    ));
    await tester.pumpAndSettle();
    final tile = find.byKey(const ValueKey('module-assistant'));
    expect(find.descendant(of: tile, matching: find.byType(CopilotMark)), findsOneWidget);
    expect(find.descendant(of: tile, matching: find.byIcon(Icons.psychology_outlined)), findsNothing);
    final fills = tester
        .widgetList<Container>(find.descendant(of: tile, matching: find.byType(Container)))
        .map((c) => (c.decoration as BoxDecoration?)?.color)
        .whereType<Color>();
    expect(fills, isEmpty, reason: 'no tinted disc behind the mark');
    // ⓘ বিক্রয়ের টাইলের পট আগের মতোই
    expect(find.descendant(of: find.byKey(const ValueKey('module-sales')), matching: find.byIcon(Icons.shopping_cart)),
        findsOneWidget);
  });

  test('a guest\'s screens are still found by the guest\'s old name', () {
    final tree = _treeOf(_mergedMe);
    List<String?> hits(String q) => tree.search(q).map((r) => r.path).toList();
    expect(hits('গ্রাহক'), contains('customers'));
    expect(hits('সরবরাহকারী'), contains('principal-commission'));
    expect(hits('অর্থ'), contains('deposits'));
    expect(hits('বাকি নিয়ন্ত্রণ'), contains('credit'));
    expect(tree.search('নিয়ন্ত্রণ ও নিরীক্ষা').map((r) => r.label), contains('অডিট লগ'));
    expect(tree.search('ব্যাকআপ').map((r) => r.label), contains('ব্যাকআপ চালান'));
    expect(tree.search('স্থায়ী সম্পদ').map((r) => r.label), contains('সম্পদের তালিকা'));
    expect(tree.search('বার্তা').map((r) => r.label), contains('বার্তা ও কল'));
    expect(tree.search('বিজ্ঞপ্তি').map((r) => r.path), contains('notices'));
    // ⓘ খোঁজার সারির মডিউল-নাম আশ্রয়দাতার — এক করা টাইলের নাম
    expect(tree.moduleLabel(tree.screen('deposits')!.module), 'হিসাব ও অর্থ');

    final old = _treeOf(_oldMe);
    expect(old.search('নিয়ন্ত্রণ ও নিরীক্ষা').map((r) => r.label), contains('অডিট লগ'));
    expect(old.search('ব্যাকআপ').map((r) => r.label), contains('ব্যাকআপ চালান'));
  });

  group('golden — all modules in the three looks', () {
    setUpAll(loadBarFonts);
    late AppLooks saved;
    setUp(() {
      saved = AppLooks.instance;
      AppLooks.instance = AppLooks(read: (_) async => null, write: (_, __) async {});
      AppLooks.instance.motion.value = false; // ⓘ ছবিতে ছোপ স্থির
    });
    tearDown(() => AppLooks.instance = saved);

    ThemeData themeOf(AppLook look) => withBarFonts(switch (look) {
          AppLook.glass => AppTheme.glass(GlassSpec.of(AppLooks.instance.blur.value)),
          AppLook.expressive => AppTheme.expressive(),
          AppLook.abos => AppTheme.light(),
        });

    for (final look in [AppLook.expressive, AppLook.glass, AppLook.abos]) {
      testWidgets('modules — ${look.name}', (tester) async {
        AppLooks.instance.look.value = look;
        tester.view.physicalSize = const Size(412, 760);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        final tree = _treeOf(_mergedMe);
        final body = Scaffold(
          backgroundColor: look == AppLook.glass ? Colors.transparent : null,
          body: AllModulesPage(
            modules: tree.modules,
            counts: const NavCounts(byModule: {'approval': 3}),
            onOpen: (_) {},
            onRefresh: () async {},
          ),
        );
        await tester.pumpWidget(RepaintBoundary(
          key: const ValueKey('shot'),
          child: MaterialApp(
            debugShowCheckedModeBanner: false,
            theme: themeOf(look),
            locale: AppLocale.locale,
            supportedLocales: AppLocale.supported,
            localizationsDelegates: AppLocale.delegates,
            home: look == AppLook.glass ? GlassBackdrop(child: body) : body,
          ),
        ));
        await tester.pumpAndSettle();
        await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/app_icons/modules_${look.name}.png'));
      });
    }
  });
}
