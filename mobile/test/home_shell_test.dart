import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/intl.dart';

import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/auth/session_repository.dart';
import 'package:abos_mobile/core/menu/menu_item.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/privacy/device_integrity.dart';
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

const _approver = AuthUser(
  id: 'u2',
  name: 'করিম',
  email: '',
  roles: ['Manager'],
  permissions: ['sales.order.view', 'customer.view', 'approval.decide'],
);

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
    Future<ApprovalPage> Function()? pending,
    Future<void> Function()? syncNow,
    AuthUser? as,
  }) =>
      ProviderScope(
        overrides: [authStateProvider.overrideWith((ref) => _SignedIn(as ?? user))],
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
            // ⓘ ডিফল্টে সার্ভার "না" বলে (৪০৩-এর মতো) — অনুমোদন আইকন লুকানো
            fetchPending:
                pending ?? () async => throw StateError('not an approver'),
            syncNow: syncNow ?? () async {},
            prefs: MemoryNavPrefsStore(),
            openPath: (_) async {},
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
  // at the far right of the header and opens the profile. ⓘ 12 Oct 2026: the
  // bell moved to the bottom bar ("বিজ্ঞপ্তি"), one tap from every screen.
  testWidgets(
      'no greeting on the home; the photo is in the header, the bell not in the bottom bar',
      (tester) async {
    await tester.pumpWidget(shell(now: () => DateTime(2026, 9, 27, 20, 15)));
    await tester.pumpAndSettle();

    expect(find.textContaining('শুভ রাত্রি'), findsNothing);
    // ⓘ বারের নকশা ৩ (২০ অক্টোবর ২০২৬): ঘণ্টা ওপরের বারে (a4), নিচের বারে নয়
    expect(find.byKey(const ValueKey('nav-notifications')), findsNothing);
    expect(find.byKey(const ValueKey('header-avatar')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('header-avatar')));
    await tester.pumpAndSettle();
    // ⓘ ছবি এখন নিজের মেনু খোলে; "আমি" পাতা তার "সেটিংস" সারিতে (মালিকের নকশা ৩ §৩, ২০ অক্টোবর ২০২৬)
    await tester.ensureVisible(find.byKey(const ValueKey('avatar-settings')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('avatar-settings')));
    await tester.pumpAndSettle();
    await _scrollToLogout(tester);
    expect(find.text('বেরিয়ে যান'), findsOneWidget,
        reason: 'the photo opens my menu, its settings row the profile');
  });

  // ⭐ "আমার প্রোফাইল" — কেবল নিজের তথ্য, কোনো প্রতিশ্রুতি নয় (মালিক, ২০ অক্টোবর ২০২৬)
  testWidgets('my profile shows who I am and where, and promises nothing', (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('header-avatar')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('avatar-profile')));
    await tester.pumpAndSettle();
    final dialog = find.byKey(const ValueKey('profile-dialog'));
    expect(dialog, findsOneWidget);
    expect(find.descendant(of: dialog, matching: find.text('করিম')), findsOneWidget);
    expect(find.descendant(of: dialog, matching: find.text('karim@testco.local')), findsOneWidget);
    expect(find.descendant(of: dialog, matching: find.text('টেষ্ট কোম্পানী লিমিটেড · Head Office')), findsOneWidget);
    expect(find.textContaining('শীঘ্রই'), findsNothing);
    expect(find.textContaining('ছবি বদল'), findsNothing);
  });

  // ⭐ মালিক, ১০ অক্টোবর ২০২৬: ঘণ্টার পাশে অনুমোদনের আইকন — অপেক্ষার সংখ্যাসহ, চাপলে তালিকা
  // ⓘ ১২ অক্টোবর ২০২৬: এখন নিচের বারের "অনুমোদন" ঘর
  testWidgets('beside the bell, the approval tab counts what waits for me',
      (tester) async {
    await tester.pumpWidget(shell(
        as: _approver,
        pending: () async => const ApprovalPage(rows: [], nextCursor: null)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('nav-approvals')), findsOneWidget,
        reason: 'an approver sees the tab even with nothing waiting');
    expect(find.text('0'), findsNothing, reason: 'nothing waits — no badge');
  });

  testWidgets('more pages waiting show as a plus on the approval icon',
      (tester) async {
    await tester.pumpWidget(shell(
        as: _approver,
        pending: () async =>
            const ApprovalPage(rows: [], nextCursor: 'next-page')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('nav-approvals')), findsOneWidget);
    // ⓘ বারের নকশা ৩: কত জানা নেই — শুধু লাল বিন্দু
    expect(find.byKey(const ValueKey('nav-approvals-dot')), findsOneWidget,
        reason: 'more pages wait — a dot, the count is not known');
  });

  // ⭐ মালিক, ১০ অক্টোবর ২০২৬: "Sync icon tule daw r upor theke niche tanlei zate sync hoy"
  testWidgets('no sync icon in the header; pulling the home down syncs',
      (tester) async {
    var syncs = 0;
    await tester.pumpWidget(shell(syncNow: () async => syncs++));
    await tester.pumpAndSettle();

    expect(
        find.descendant(
            of: find.byType(AbosTopBar), matching: find.byIcon(Icons.sync_outlined)),
        findsNothing);

    await tester.fling(find.byType(ListView).first, const Offset(0, 400), 1000);
    await tester.pumpAndSettle();

    expect(syncs, 1, reason: 'a pull on the home is a full sync');
  });

  testWidgets('somebody who may not approve gets no approval icon',
      (tester) async {
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('nav-approvals')), findsNothing);
    expect(find.byKey(const ValueKey('nav-notifications')), findsNothing);
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

    await tester.tap(find.byKey(const ValueKey('nav-modules')));
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

    expect(find.text('৳45,200.00'), findsOneWidget);
    expect(find.text('৳31,000.00'), findsOneWidget);
    expect(find.text('৳8,12,500.00'), findsOneWidget);
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

  // ⓘ ১২ অক্টোবর ২০২৬: তিন ট্যাব (হোম · অ্যাপ · আরও) এখন নিচের পাঁচ ঘরের বার
  testWidgets('the tabs are in Bangla and each one opens', (tester) async {
    await tester.pumpWidget(shell(
        pending: () async => const ApprovalPage(rows: [], nextCursor: null)));
    await tester.pumpAndSettle();

    // ⓘ বারের নকশা ৩: বিক্রয়কর্মীর (অর্ডার লেখেন) ঘর; আদায়ের চাবি নেই, তাই আদায় নেই; "আমি" মাথার ছবিতে
    for (final label in ['হোম', 'অর্ডার', 'সব মডিউল']) {
      expect(find.descendant(of: find.byKey(const ValueKey('nav-bar')), matching: find.text(label)),
          findsOneWidget, reason: label);
    }
    for (final label in ['বিজ্ঞপ্তি', 'আমি']) {
      expect(find.descendant(of: find.byKey(const ValueKey('nav-bar')), matching: find.text(label)),
          findsNothing, reason: label);
    }

    await tester.tap(find.byKey(const ValueKey('nav-modules')));
    await tester.pumpAndSettle();
    expect(find.text('গ্রাহক'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('header-avatar')));
    await tester.pumpAndSettle();
    expect(find.textContaining('karim@testco.local'), findsOneWidget);
    // ⭐ প্রিন্টার — থার্মাল আর নরমাল (মালিক, ১৪ অক্টোবর ২০২৬)
    expect(find.byKey(const ValueKey('me-printer')), findsOneWidget);
    await _scrollToLogout(tester);
    expect(find.text('বেরিয়ে যান'), findsOneWidget);
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

    await tester.tap(find.byKey(const ValueKey('header-avatar')));
    await tester.pumpAndSettle();
    await _scrollToLogout(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'বেরিয়ে যান'));
    await tester.pumpAndSettle();

    expect(find.text('বেরিয়ে যাবেন?'), findsOneWidget);
    await tester.tap(find.text('থাক'));
    await tester.pumpAndSettle();
    expect(find.text('বেরিয়ে যাবেন?'), findsNothing);
  });
  // ⭐ root করা বা ছদ্ম-ফোন — হোমে একবার বলা, আটকানো নয় (অ্যাপের নিরাপত্তা, MASVS-RESILIENCE-1, ১৯ অক্টোবর ২০২৬)
  testWidgets('a rooted phone is warned once a run and the app goes on; a clean phone is not', (tester) async {
    final before = DeviceIntegrity.checker;
    addTearDown(() {
      DeviceIntegrity.checker = before;
      DeviceIntegrity.reset();
    });

    DeviceIntegrity.reset();
    DeviceIntegrity.checker = () async => const IntegrityReport(rooted: true, emulator: false);
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('device-integrity-warning')), findsOneWidget, reason: '⛔ root করা ফোনে কিছুই বলা হলো না');
    expect(find.textContaining('root করা'), findsOneWidget);
    await tester.tap(find.text('বুঝেছি'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('device-integrity-warning')), findsNothing, reason: '⛔ সতর্কতা অ্যাপ আটকে রাখল');

    // ⓘ একই চালুতে হোম আবার খুললে আর বলা নয়
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('device-integrity-warning')), findsNothing, reason: '⛔ প্রতিবার হোমে ফিরলেই একই সতর্কতা');

    DeviceIntegrity.reset();
    DeviceIntegrity.checker = () async => const IntegrityReport(rooted: false, emulator: false);
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(shell());
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('device-integrity-warning')), findsNothing, reason: '⛔ ভালো ফোনেও সতর্কতা');
    await tester.pumpWidget(const SizedBox());
    await tester.pumpAndSettle();
  });

}

/// "আমি" grew a printer row (14 Oct 2026); on the test's 800×600 screen the
/// way out now sits below the fold, as it does on a short phone.
Future<void> _scrollToLogout(WidgetTester tester) async {
  await tester.dragUntilVisible(find.text('বেরিয়ে যান'),
      find.byKey(const ValueKey('me-sync-status')), const Offset(0, -150));
  await tester.pumpAndSettle();
}
