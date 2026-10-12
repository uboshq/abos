import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/home/home_shell.dart';
import 'package:abos_mobile/features/home/nav_search.dart';

/// ⭐ মালিক, ১২ অক্টোবর ২০২৬: "app icon e tap korle age module asbe, module e dhukle tar menu, menu r vitore
/// kichu thakle vitore dekhabe" — the home, all modules, a module's menu, a
/// sub-list, the search, favourites and recents, and back one level at a time.
class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

const _user = AuthUser(
  id: 'u1',
  name: 'করিম',
  email: 'karim@testco.local',
  roles: ['Manager'],
  permissions: ['sales.order.view', 'customer.view'],
);

MenuItem _item(String path, String label) =>
    MenuItem(key: path, label: label, icon: Icons.circle, routeName: path);

final _salesman = [
  _item('new-order', 'নতুন অর্ডার'),
  _item('orders', 'অর্ডারের অবস্থা'),
  _item('deliveries', 'আজকের ডেলিভারি'),
  _item('tracking', 'ডেলিভারি ট্র্যাকিং'),
  _item('customers', 'গ্রাহক'),
  _item('dues', 'বকেয়া তালিকা'),
  _item('sync-status', 'সিঙ্কের অবস্থা'),
];

void main() {
  late List<String> opened;
  late MemoryNavPrefsStore store;

  setUp(() {
    opened = [];
    store = MemoryNavPrefsStore();
  });

  Widget shell({
    List<MenuItem>? items,
    Future<ApprovalPage> Function()? pending,
    List<CustomerHit> Function(String)? customers,
  }) =>
      ProviderScope(
        overrides: [authStateProvider.overrideWith((ref) => _SignedIn(_user))],
        child: MaterialApp(
          home: HomeShell(
            loadHome: (_) async => HomeMenu(items: items ?? _salesman),
            fetchToday: () async => const TodayRecord({}),
            lastKnownToday: () => null,
            readCachedOrg: () async => null,
            cacheOrg: (_) async {},
            checkUpdate: () async => UpdateStatus.fine,
            fetchNoticeBar: () async => const [],
            fetchPending: pending ?? () async => throw StateError('not an approver'),
            syncNow: () async {},
            prefs: store,
            openPath: (path) async => opened.add(path),
            findCustomers: customers ?? (_) => const [],
            customerName: (id) => id == 'c1' ? 'রহিম ট্রেডার্স' : null,
          ),
        ),
      );

  Future<void> toModules(WidgetTester tester) async {
    await tester.tap(find.byKey(const ValueKey('nav-modules')));
    await tester.pumpAndSettle();
  }

  testWidgets('the home opens first: search, today, favourites, the bar', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('nav-search')), findsWidgets);
    expect(find.text('আজ'), findsOneWidget);
    expect(find.text('প্রিয়'), findsOneWidget);
    expect(find.byKey(const ValueKey('favourites-empty')), findsOneWidget);
    expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget);
  });

  testWidgets('a module tile opens its menu, grouped, with a crumb back', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    await toModules(tester);

    expect(find.byKey(const ValueKey('module-sales')), findsOneWidget);
    expect(find.byKey(const ValueKey('module-customer')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('module-sales')));
    await tester.pumpAndSettle();

    expect(find.text('লেনদেন'), findsOneWidget);
    expect(find.text('নতুন অর্ডার'), findsOneWidget);
    expect(find.text('অর্ডারের অবস্থা'), findsOneWidget);
    expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget,
        reason: 'the bar stays on the menu too');

    await tester.tap(find.byKey(const ValueKey('menu-crumb')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('module-sales')), findsOneWidget);
  });

  testWidgets('a menu row opens its own route', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    await toModules(tester);
    await tester.tap(find.byKey(const ValueKey('module-customer')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('বকেয়া তালিকা'));
    await tester.pumpAndSettle();
    expect(opened, ['dues']);
  });

  testWidgets('a row with children opens a short sub-list, then the screen', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    await toModules(tester);
    await tester.tap(find.byKey(const ValueKey('module-sales')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('ডেলিভারি ও লোডিং'));
    await tester.pumpAndSettle();
    expect(find.text('আজকের ডেলিভারি'), findsOneWidget);
    expect(find.text('ডেলিভারি ট্র্যাকিং'), findsOneWidget);

    await tester.tap(find.text('ডেলিভারি ট্র্যাকিং'));
    await tester.pumpAndSettle();
    expect(opened, ['tracking']);
  });

  testWidgets('without the permission there is no tile and no row', (tester) async {
    await tester.pumpWidget(shell(items: [_item('orders', 'অর্ডারের অবস্থা')]));
    await tester.pumpAndSettle();
    await toModules(tester);

    expect(find.byKey(const ValueKey('module-customer')), findsNothing);
    expect(find.byKey(const ValueKey('module-hr')), findsNothing);
    expect(find.byKey(const ValueKey('module-approval')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('module-sales')));
    await tester.pumpAndSettle();
    expect(find.text('নতুন অর্ডার'), findsNothing);
    expect(find.text('অর্ডারের অবস্থা'), findsOneWidget);
  });

  testWidgets('an approver sees the red count on the approval tile', (tester) async {
    await tester.pumpWidget(shell(
      items: [..._salesman, _item('approvals', 'অনুমোদন')],
      pending: () async => const ApprovalPage(
          rows: [ApprovalRecord({}), ApprovalRecord({}), ApprovalRecord({})], nextCursor: null),
    ));
    await tester.pumpAndSettle();
    await toModules(tester);

    expect(
        find.descendant(
            of: find.byKey(const ValueKey('module-approval')), matching: find.text('3')),
        findsOneWidget);
  });

  testWidgets('back goes one level up: sub-list, menu, all modules, home', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    await toModules(tester);
    await tester.tap(find.byKey(const ValueKey('module-sales')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ডেলিভারি ও লোডিং'));
    await tester.pumpAndSettle();

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('ডেলিভারি ও লোডিং'), findsOneWidget, reason: 'back on the sales menu');
    expect(find.text('নতুন অর্ডার'), findsOneWidget);

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('module-sales')), findsOneWidget);

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('আজ'), findsOneWidget, reason: 'the home');
  });

  testWidgets('the search finds a screen by name and opens it', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('nav-search')).first);
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'চালান');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('hit-deliveries')), findsOneWidget);
    expect(find.byKey(const ValueKey('hit-counter')), findsNothing,
        reason: 'no door to the counter, no hit');

    await tester.tap(find.byKey(const ValueKey('hit-deliveries')));
    await tester.pumpAndSettle();
    expect(opened, ['deliveries']);
  });

  testWidgets('the search finds a synced customer and opens the shop', (tester) async {
    await tester.pumpWidget(shell(
        customers: (q) => q.contains('রহিম')
            ? const [CustomerHit(id: 'c1', label: 'রহিম ট্রেডার্স')]
            : const []));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('nav-search')).first);
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextField), 'রহিম');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('hit-customer-c1')));
    await tester.pumpAndSettle();

    expect(opened, ['customers/c1']);
    expect(find.byKey(const ValueKey('recent-customers/c1')), findsOneWidget,
        reason: 'the shop is under সাম্প্রতিক now');
  });

  testWidgets('favourites: held down in a menu, on the home, kept for the next launch',
      (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    await toModules(tester);
    await tester.tap(find.byKey(const ValueKey('module-sales')));
    await tester.pumpAndSettle();

    await tester.longPress(find.text('নতুন অর্ডার'));
    await tester.pumpAndSettle();
    expect(await NavPrefs(store, 'u1').favourites(), ['new-order']);

    // A fresh home over the same store — the next time the app opens.
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('favourite-new-order')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('favourite-new-order')));
    await tester.pumpAndSettle();
    expect(opened, ['new-order']);

    await tester.longPress(find.byKey(const ValueKey('favourite-new-order')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('favourite-new-order')), findsNothing);
  });

  testWidgets('a favourite whose door is gone is not on the home', (tester) async {
    await NavPrefs(store, 'u1').toggleFavourite('attendance');
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('favourite-attendance')), findsNothing);
  });

  testWidgets('the sync status and the update button are under আমি', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('nav-me')));
    await tester.pumpAndSettle();

    expect(find.text('অ্যাপ হালনাগাদ'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('me-sync-status')));
    await tester.pumpAndSettle();
    expect(opened, ['sync-status']);
  });

  testWidgets('the bottom bar opens the approvals and the notifications', (tester) async {
    await tester.pumpWidget(shell(
        pending: () async => const ApprovalPage(rows: [], nextCursor: null)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('nav-approvals')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('nav-notifications')));
    await tester.pumpAndSettle();
    expect(opened, ['approvals', 'notifications']);
  });
}
