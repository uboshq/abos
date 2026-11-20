import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/orders/sales_return_api.dart';
import 'package:abos_mobile/core/records/notification_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';
import 'package:abos_mobile/features/direct_sale/return_screen.dart';
import 'package:abos_mobile/features/notifications/notifications_screen.dart';
import 'package:abos_mobile/features/profile/my_phones_screen.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';

import 'profile/profile_fakes.dart' show FakeProfileApi;
import 'profile/profile_host.dart' show profileContainer;
import 'return_screen_test.dart' show FakeReturnApi;
import 'support/bar_fonts.dart';
import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart' show seedMainData;

/// ⭐ ফাঁকা পর্দার ছবি — কিছু না থাকলে প্রতিটা তালিকা/ফর্ম একটা ঠিক "কিছু নেই" কথা বলে, ফাঁকা সাদা পাতা বা ঘুরতে থাকা চাকা নয়
/// (fe-র ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬: আগের ছবিগুলো সব ভরা তালিকার; ফাঁকা অবস্থা কেউ দেখেনি)।
/// ৪১২dp, আলো, বাংলা আর ইংরেজি, আসল ফন্টে (Roboto + Shonar Bangla + Material Symbols)। ছবি `test/goldens/empty/`।
Future<void> _loadRounded() async {
  final config = File('.dart_tool/package_config.json').readAsStringSync();
  final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
  final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
  final loader = FontLoader('packages/material_symbols_icons/MaterialSymbolsRounded')
    ..addFont(Future.value(
        ByteData.view(Uint8List.fromList(File('$root/lib/fonts/MaterialSymbolsRounded.ttf').readAsBytesSync()).buffer)));
  await loader.load();
}

class _NoVouchers implements VoucherApi {
  const _NoVouchers();

  @override
  Future<VoucherListPage> list(
          {String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: [], count: 0, total: 0);

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

class _NoMoneyIn implements BooksApi {
  @override
  Future<MoneyInPage> moneyIn({required String from, required String to, String? q, String? method, int page = 1}) async =>
      const MoneyInPage(rows: [], total: 0, count: 0);

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// ⓘ সার্ভারে ফেরতের কোনো কারণ বসানো নেই — বিল আছে, কারণ নেই
class _NoReasons extends FakeReturnApi {
  @override
  Future<ReturnSetup> setup({String? customerId}) async {
    final s = await super.setup(customerId: customerId);
    return ReturnSetup(reasons: const [], invoices: s.invoices);
  }
}

/// এক পর্দা — তার নাম, কী দিয়ে খোলে, খোলার পরে কী করে (যেমন খোঁজে লেখা)
typedef _Shot = ({
  String name,
  Widget Function() screen,
  bool seed,
  Future<void> Function(WidgetTester tester)? then,
});

final List<_Shot> _shots = [
  (
    name: 'vouchers',
    screen: () => VoucherListScreen(
        api: const _NoVouchers(), newChangeId: () => 'k', branches: () async => const [], today: DateTime(2026, 10, 20), canCreate: true),
    seed: false,
    then: null,
  ),
  (name: 'customer_list', screen: () => const CustomerListScreen(), seed: false, then: null),
  (
    name: 'customer_list_no_match',
    screen: () => const CustomerListScreen(),
    seed: true,
    then: (tester) async {
      await tester.enterText(find.byType(TextField).first, 'zzzz');
      await tester.pumpAndSettle();
      FocusManager.instance.primaryFocus?.unfocus();
      await tester.pumpAndSettle();
    },
  ),
  (name: 'due_list', screen: () => DueListScreen(refresh: () async {}), seed: false, then: null),
  (
    name: 'approvals',
    screen: () => ApprovalInboxScreen(
        loadPending: () async => const ApprovalPage(rows: []), approve: (_, __) async {}, reject: (_, __) async {}),
    seed: false,
    then: null,
  ),
  (
    name: 'notifications',
    screen: () => NotificationsScreen(
        fetch: () async => const NotificationPage(items: [], unread: 0), markRead: (_) async {}, markAllRead: () async {}),
    seed: false,
    then: null,
  ),
  (
    name: 'collections_today',
    screen: () => MoneyInListScreen(
        api: _NoMoneyIn(), today: DateTime(2026, 10, 22), canReportDeposit: true, dues: () => const [], unsent: () => const []),
    seed: false,
    then: (tester) async {
      await tester.tap(find.byKey(const ValueKey('range-today')));
      await tester.pumpAndSettle();
    },
  ),
  (name: 'my_phones', screen: () => MyPhonesScreen(api: FakeProfileApi(pages: [[]])), seed: false, then: null),
  (
    name: 'sales_return_no_reason',
    screen: () => ReturnScreen(customerId: 'cus-1', api: _NoReasons()),
    seed: false,
    then: (tester) async {
      await tester.tap(find.byKey(const ValueKey('return-bill-inv-1')));
      await tester.pumpAndSettle();
    },
  ),
];

void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    await loadBarFonts();
    await _loadRounded();
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  for (final shot in _shots) {
    for (final (locale, tag) in [(AppLocale.locale, 'bn'), (AppLocale.english, 'en')]) {
      testWidgets('${shot.name} with nothing to show says so, $tag', (tester) async {
        AppLocale.current = locale;
        addTearDown(() => AppLocale.current = AppLocale.locale);
        tester.view.physicalSize = const Size(412, 915);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.reset);
        await tester.runAsync(() async {
          await ReferenceCache.instance.clearAll();
          if (shot.seed) await seedMainData();
        });
        // ⓘ বারের "সব শাখা" অ্যাপের নিজের কথা (ARB-এর homeAllBranches) — ইংরেজিতে "All branches"; পদবি সার্ভারের, তার ভাষায়
        final c = profileContainer(withBar: false);
        addTearDown(c.dispose);
        c.read(topBarContextProvider.notifier).state = TopBarContext(
          company: 'ADI Wholesale',
          companyCode: 'ADI',
          branch: AppLocale.l10n.homeAllBranches,
          designation: tag == 'en' ? 'Owner' : 'মালিক',
          search: (_) async {},
          openNotifications: (_) async {},
          openMenu: (_, __) async {},
        );
        await tester.pumpWidget(RepaintBoundary(
          key: const ValueKey('shot'),
          child: UncontrolledProviderScope(
            container: c,
            child: MaterialApp(
              debugShowCheckedModeBanner: false,
              theme: withBarFonts(AppTheme.light()),
              themeMode: ThemeMode.light,
              locale: locale,
              supportedLocales: AppLocale.supported,
              localizationsDelegates: AppLocale.delegates,
              home: shot.screen(),
            ),
          ),
        ));
        await tester.pumpAndSettle();
        await shot.then?.call(tester);
        expect(tester.takeException(), isNull, reason: '⛔ ফাঁকা পর্দা উপচে পড়ল');

        if (shot.name == 'sales_return_no_reason') {
          // ⓘ কারণ নেই — ঘরের জায়গায় কারণ-নেই কথা, আর "নিশ্চিত" বন্ধ (বাছার কিছু নেই, অথচ "কারণ বাছুন" বলা ভুল)
          expect(find.byKey(const ValueKey('return-no-reasons')), findsOneWidget,
              reason: '⛔ কারণ নেই, অথচ পর্দা চুপ — "নিশ্চিত" চাপলে "কারণ বাছুন", বাছার ঘরই নেই');
          expect(tester.widget<FilledButton>(find.byKey(const ValueKey('return-go'))).onPressed, isNull);
        } else if (shot.name == 'vouchers') {
          // ⓘ ভাউচারের তালিকা ভাগ করা EmptyState নয়, এক লাইনের কথা বলে — পর্দাটা এখন অন্য কাজের (a4/golden-fix-a) হাতে,
          // তাই এখানে কেবল কথাটা আছে কি না
          expect(find.text(AppLocale.l10n.vouchersListEmpty), findsOneWidget, reason: '⛔ ফাঁকা ভাউচার তালিকা চুপ');
          expect(find.byType(LinearProgressIndicator), findsNothing, reason: '⛔ ফাঁকা পর্দায় আনা চলছে');
        } else {
          final empty = find.byType(EmptyState);
          expect(empty, findsOneWidget, reason: '⛔ ফাঁকা পর্দায় "কিছু নেই" কথা নেই');
          final title = tester.widget<EmptyState>(empty).title;
          expect(title.trim(), isNotEmpty);
          expect(find.text(title), findsOneWidget, reason: 'কথাটা আঁকা');
          expect(find.byType(CircularProgressIndicator), findsNothing, reason: '⛔ ফাঁকা পর্দায় চাকা ঘোরে');
          expect(find.byType(LinearProgressIndicator), findsNothing, reason: '⛔ ফাঁকা পর্দায় আনা চলছে');
        }
        await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/empty/${shot.name}_412_light_$tag.png'));
      });
    }
  }
}
