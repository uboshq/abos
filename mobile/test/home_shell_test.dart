import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/intl.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/auth/session_repository.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/records/notice_bar.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/home/home_shell.dart';

/// The home screen the owner asked for: the company and branch in the
/// header, a greeting that looks at the clock, the day's figures under it,
/// and the day's work one tap away.
class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

void main() {
  const user = AuthUser(
    id: 'u1',
    name: 'করিম',
    email: 'karim@testco.local',
    roles: ['Manager'],
    permissions: ['sales.order.view', 'customer.view', 'sales.order.create'],
  );

  const today = TodayRecord({
    'date': '2026-09-27',
    'company': 'টেষ্ট কোম্পানী লিমিটেড',
    'branch': 'Head Office',
    'asOf': '2026-09-27T10:04:11+06:00',
    'sales': {'count': 12, 'amount': '45200.0000'},
    'collections': {'count': 7, 'amount': '31000.0000'},
    'dues': {'amount': '812500.0000', 'shops': 41},
    'approvals': {'pending': 3},
  });

  const menu = HomeMenu(
    items: [
      MenuItem(
        key: 'sales.order.create',
        label: 'নতুন অর্ডার',
        icon: Icons.add_shopping_cart_outlined,
        routeName: 'new-order',
      ),
      MenuItem(
        key: 'customer.index',
        label: 'গ্রাহক',
        icon: Icons.people_alt_outlined,
        routeName: 'customers',
      ),
      MenuItem(
        key: 'sync_status',
        label: 'সিঙ্কের অবস্থা',
        icon: Icons.sync_outlined,
        routeName: 'sync-status',
      ),
    ],
    profile: SessionProfile(
      company:
          OrgRef(publicId: 'c1', code: 'TCL', name: 'টেষ্ট কোম্পানী লিমিটেড'),
      branch: OrgRef(publicId: 'b1', code: 'HO', name: 'Head Office'),
      locale: 'bn',
    ),
  );

  Widget shell({
    Future<HomeMenu> Function(AuthUser)? loadHome,
    Future<OrgSnapshot?> Function()? readCachedOrg,
    DateTime Function()? now,
    TodayRecord? Function()? lastKnown,
    Future<List<NoticeBarItem>> Function()? noticeBar,
  }) =>
      ProviderScope(
        overrides: [authStateProvider.overrideWith((ref) => _SignedIn(user))],
        child: MaterialApp(
          home: HomeShell(
            loadHome: loadHome ?? (_) async => menu,
            fetchToday: () async => today,
            lastKnownToday: lastKnown ?? () => null,
            readCachedOrg: readCachedOrg ?? () async => null,
            cacheOrg: (_) async {},
            checkUpdate: () async => UpdateStatus.fine,
            now: now ?? () => DateTime(2026, 9, 27, 10, 30),
            fetchNoticeBar: noticeBar ?? () async => const [],
          ),
        ),
      );

  testWidgets('the header names the company and branch, not the person',
      (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();

    // docs/Contract §৮ rule খ — whose figures these are, on every tab.
    expect(find.text('টেষ্ট কোম্পানী লিমিটেড'), findsOneWidget);
    expect(find.text('Head Office'), findsOneWidget);
    // Said once, in the header — not again above the cards.
    expect(find.textContaining('টেষ্ট কোম্পানী লিমিটেড · Head Office'),
        findsNothing);
  });

  // ⭐ The owner, 6 Oct 2026: no greeting, no name on the home — the photo sits
  // at the far right of the green header and opens the profile; the bell
  // sits beside it.
  testWidgets(
      'no greeting on the home; the photo and the bell are in the header',
      (tester) async {
    await tester.pumpWidget(shell(now: () => DateTime(2026, 9, 27, 20, 15)));
    await tester.pumpAndSettle();

    expect(find.textContaining('শুভ রাত্রি'), findsNothing);
    expect(find.byKey(const ValueKey('header-bell')), findsOneWidget);
    expect(find.byKey(const ValueKey('header-avatar')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('header-avatar')));
    await tester.pumpAndSettle();
    expect(find.text('বেরিয়ে যান'), findsOneWidget,
        reason: 'the photo opens the profile');
  });

  // ⭐ মালিক, ৬ অক্টোবর ২০২৬: ওয়েবের চলমান নোটিশ ফোনেও — মাথার নিচে, প্রতিটা ট্যাবে; কিছু না থাকলে চুপ
  testWidgets(
      'the notice line of the web sits under the header on every tab, and is quiet when empty',
      (tester) async {
    await tester.pumpWidget(shell(
        noticeBar: () async =>
            const [NoticeBarItem(id: 'n1', title: 'কাল অফিস বন্ধ')]));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('notice-ticker')), findsOneWidget);
    expect(find.textContaining('কাল অফিস বন্ধ'), findsWidgets);

    await tester.tap(find.text('অ্যাপ'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('notice-ticker')), findsOneWidget,
        reason: 'নোটিশ কেবল হোম ট্যাবে — মাথার নিচে নয়');

    // ⓘ নতুন গাছ — একই গাছে আবার পাম্প করলে আগের অবস্থা থেকে যেত
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('notice-ticker')), findsNothing);
  });

  testWidgets('the figures and the hour they were true are on the home tab',
      (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();

    expect(find.text('৳45,200'), findsOneWidget);
    expect(find.text('৳31,000'), findsOneWidget);
    expect(find.text('৳812,500'), findsOneWidget);
    expect(find.textContaining('3 টি নথি'), findsOneWidget);
    // ⓘ যন্ত্রের নিজের সময়-অঞ্চলে (ঢাকায় 10:04 AM) — পরীক্ষা যেকোনো অঞ্চলের যন্ত্রে চলে
    expect(find.textContaining(DateFormat('hh:mm a').format(DateTime.parse('2026-09-27T10:04:11+06:00').toLocal())), findsOneWidget);
    // No cash block came back, so no cash card — absent is not zero.
    expect(find.text('হাতে নগদ'), findsNothing);
  });

  // ⛔ The shortcut row is gone (owner, 6 Oct 2026: "ei sort cutgulo dewar
  // dorkar nai") — the অ্যাপ tab holds every door.
  testWidgets('no shortcut row on the home', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();

    expect(find.byType(ActionChip), findsNothing);
  });

  testWidgets('the tabs are in Bangla and each one opens', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();

    await tester.tap(find.text('অ্যাপ'));
    await tester.pumpAndSettle();
    expect(find.text('গ্রাহক'), findsOneWidget);

    await tester.tap(find.text('আরও'));
    await tester.pumpAndSettle();
    expect(find.text('বেরিয়ে যান'), findsOneWidget);
    expect(find.textContaining('karim@testco.local'), findsOneWidget);
    // ⭐ হাতে হালনাগাদ যাচাই — মালিক, ৭ অক্টোবর ২০২৬
    expect(find.text('অ্যাপ হালনাগাদ'), findsOneWidget);
  });

  testWidgets('with no signal the header still names the company it last knew',
      (tester) async {
    await tester.pumpWidget(shell(
      loadHome: (_) async => const HomeMenu(items: []),
      readCachedOrg: () async => const OrgSnapshot(
          company: 'টেষ্ট কোম্পানী লিমিটেড', branch: 'Head Office'),
    ));
    await tester.pumpAndSettle();

    expect(find.text('টেষ্ট কোম্পানী লিমিটেড'), findsOneWidget);
  });

  testWidgets('signing out asks first', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();

    await tester.tap(find.text('আরও'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(OutlinedButton, 'বেরিয়ে যান'));
    await tester.pumpAndSettle();

    expect(find.text('বেরিয়ে যাবেন?'), findsOneWidget);
    await tester.tap(find.text('থাক'));
    await tester.pumpAndSettle();
    expect(find.text('বেরিয়ে যাবেন?'), findsNothing);
  });
}
