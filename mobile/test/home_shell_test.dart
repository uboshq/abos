import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:abos_mobile/core/widgets/day_part.dart';

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
import 'package:abos_mobile/core/locale/locale_choice.dart';
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
    Future<void> Function(String path)? openPath,
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
            openPath: openPath ?? (_) async {},
          ),
        ),
      );

  // ⭐ ভাষা বদলালে মেনু আবার আনা — সার্ভারে লেখা শেষ হলে (মেনুর নাম তৈরির সময় বাঁধা; a4, ২১ অক্টোবর ২০২৬)
  testWidgets('a language saved on the server makes the home load its menu again, and a closed home does not', (tester) async {
    var loads = 0;
    await tester.pumpWidget(shell(loadHome: (_) async {
      loads++;
      return menu;
    }));
    await tester.pumpAndSettle();
    final first = loads;

    LocaleChoices.savedOnServer.value++;
    await tester.pumpAndSettle();
    expect(loads, first + 1, reason: '⛔ নতুন ভাষায় পুরনো ভাষার মেনু থেকে গেল');

    await tester.pumpWidget(const SizedBox());
    LocaleChoices.savedOnServer.value++;
    await tester.pumpAndSettle();
    expect(loads, first + 1, reason: 'বন্ধ হোম আর শোনে না');
  });

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
  // ⓘ 13 Nov 2026 — the owner's decision "ছবি নিচের বারে": the photo left the
  // header for the bottom bar's last tab, "আমি", which opens the আমি page.
  testWidgets(
      'no greeting on the home; the photo is the bottom bar\'s আমি, the bell not in the bottom bar',
      (tester) async {
    await tester.pumpWidget(shell(now: () => DateTime(2026, 9, 27, 20, 15)));
    await tester.pumpAndSettle();

    expect(find.textContaining('শুভ রাত্রি'), findsNothing);
    // ⓘ বারের নকশা ৩ (২০ অক্টোবর ২০২৬): ঘণ্টা ওপরের বারে (a4), নিচের বারে নয়
    expect(find.byKey(const ValueKey('nav-notifications')), findsNothing);
    expect(find.byKey(const ValueKey('top-bell')), findsOneWidget);
    expect(find.byKey(const ValueKey('header-avatar')), findsNothing,
        reason: 'the photo is no longer in the header');
    expect(
        find.descendant(
            of: find.byKey(const ValueKey('nav-me')),
            matching: find.byKey(const ValueKey('nav-me-face'))),
        findsOneWidget,
        reason: 'the photo is the bottom bar\'s আমি tab');

    await tester.tap(find.byKey(const ValueKey('nav-me')));
    await tester.pumpAndSettle();
    await _scrollToLogout(tester);
    expect(find.text('বেরিয়ে যান'), findsOneWidget,
        reason: 'the photo opens the আমি page');
  });

  // ⭐ "আমার প্রোফাইল" → পুরো পর্দা "প্রোফাইল ও ছবি" (০.৪.৪১, a6, ২০ অক্টোবর ২০২৬: সার্ভারের ছবি আর ফোনের দরজা এসেছে;
  // নিজের তথ্য, ছবি বদল আর "আমার ফোনগুলো" সেখানে — test/profile/profile_screen_test.dart, profile_menu_test.dart)
  testWidgets('my profile opens the profile and photo screen', (tester) async {
    final opened = <String>[];
    await tester.pumpWidget(shell(openPath: (path) async => opened.add(path)));
    await tester.pumpAndSettle();
    // ⭐ ১৪ নভেম্বর ২০২৬: ছবি নিচের বারের "আমি" — সেখানে নামের কার্ড আর "প্রোফাইল ও ছবি" সারি দুটোই প্রোফাইল খোলে
    await _openMe(tester);
    expect(find.byKey(const ValueKey('me-page')), findsOneWidget, reason: '⛔ নিচের বারের ছবিতে চাপ দিলে "আমি" পাতা খোলেনি');
    await tester.tap(find.byKey(const ValueKey('profile-avatar')));
    await tester.pumpAndSettle();
    expect(opened, ['me/profile'], reason: '⛔ নামের কার্ডে চাপ দিলে প্রোফাইল খোলেনি');
    await _scrollMeTo(tester, find.byKey(const ValueKey('me-profile')));
    await tester.tap(find.byKey(const ValueKey('me-profile')));
    await tester.pumpAndSettle();
    expect(opened, ['me/profile', 'me/profile'], reason: '⛔ "প্রোফাইল ও ছবি" সারি প্রোফাইল খোলেনি');
    expect(find.byKey(const ValueKey('profile-dialog')), findsNothing);
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
    expect(find.textContaining(banglaStamp(DateTime.parse('2026-09-27T10:04:11+06:00').toLocal())), findsOneWidget);
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

    // ⓘ বারের নকশা ৩: বিক্রয়কর্মীর (অর্ডার লেখেন) ঘর; আদায়ের চাবি নেই, তাই আদায় নেই।
    // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): "আমি" — নিজের ছবি — এখন বারের শেষ ঘর; বিজ্ঞপ্তি এখনো বারে নয়
    for (final label in ['হোম', 'অর্ডার', 'সব মডিউল', 'আমি']) {
      expect(find.descendant(of: find.byKey(const ValueKey('nav-bar')), matching: find.text(label)),
          findsOneWidget, reason: label);
    }
    for (final label in ['বিজ্ঞপ্তি']) {
      expect(find.descendant(of: find.byKey(const ValueKey('nav-bar')), matching: find.text(label)),
          findsNothing, reason: label);
    }

    await tester.tap(find.byKey(const ValueKey('nav-modules')));
    await tester.pumpAndSettle();
    expect(find.text('গ্রাহক'), findsOneWidget);

    // ⓘ "আমি" — নিচের বারের শেষ ঘর (১৩ নভেম্বর ২০২৬)
    await _openMe(tester);
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

    // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): ছবির মেনু হোমের মাথায় আর নেই — বেরোনোর পথ নিচের বারের "আমি" পাতা;
    // মেনুর "বের হওয়া" নিজে test/top_bar_test.dart-এ যাচাই হয়
    await _openMe(tester);
    await _scrollToLogout(tester);
    await tester.tap(find.widgetWithText(OutlinedButton, 'বেরিয়ে যান'));
    await tester.pumpAndSettle();

    expect(find.text('বেরিয়ে যাবেন?'), findsOneWidget,
        reason: 'the আমি page\'s way out asks first');
    await tester.tap(find.text('থাক'));
    await tester.pumpAndSettle();
    expect(find.text('বেরিয়ে যাবেন?'), findsNothing);
    expect(find.byKey(const ValueKey('nav-me')), findsOneWidget,
        reason: '"থাক" keeps me signed in');
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

/// "আমি" — the bottom bar's last tab, the person's photo (the owner's decision
/// "ছবি নিচের বারে", 13 Nov 2026), opens the page.
Future<void> _openMe(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('nav-me')));
  await tester.pumpAndSettle();
}

/// "আমি" grew a printer row (14 Oct 2026); on the test's 800×600 screen the
/// way out now sits below the fold, as it does on a short phone.
Future<void> _scrollToLogout(WidgetTester tester) => _scrollMeTo(tester, find.text('বেরিয়ে যান'));

/// ⓘ "আমি" পাতার নিজের তালিকা ধরে নামা — ১৪ নভেম্বর ২০২৬ পাতায় প্রোফাইল, রঙ আর ভাষার সারি এল, কোনো একটা সারি ধরে টানলে সেটা নিজেই পর্দা ছাড়ে
Future<void> _scrollMeTo(WidgetTester tester, Finder target) async {
  final list = find.descendant(of: find.byKey(const ValueKey('me-page')), matching: find.byType(Scrollable)).first;
  await tester.scrollUntilVisible(target, 150, scrollable: list);
  await tester.pumpAndSettle();
}
