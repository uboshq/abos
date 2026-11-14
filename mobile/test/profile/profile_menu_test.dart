import 'dart:io';

import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/core/router/profile_routes.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/home/home_shell.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'profile_fakes.dart';

/// ⭐ ছবির মেনু থেকে দুই পর্দা, আর নতুন মুখ সবখানে (অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>দাবি: মেনুর "প্রোফাইল" সারি `/home/me/profile` খোলে (আগের ছোট dialog নয়); `/me`-র মুখ [myFaceProvider]-এ যায়, আর ছবির
/// পর্দা সেটা বদলালে ওপরের বার আর ভেতরের পর্দার বার সাথে সাথে নতুন মুখে — `/me` আবার না ডেকে; রাউটারে দুই পথ আছে; মেনুর
/// ভবিষ্যৎ "আমার ফোনগুলো" সারির জন্য [ProfileRoutes.openPhonesFromMenu] মেনু বন্ধ করে পর্দা খোলে।
void main() {
  const meWithFace = AuthUser(id: 'u1', name: 'আল-আমিন শুভ', email: '', roles: [], permissions: [], avatarUrl: oldFace);

  Widget shell(ProviderContainer c, List<String> opened) => UncontrolledProviderScope(
        container: c,
        child: MaterialApp(
          home: HomeShell(
            loadHome: (_) async => const HomeMenu(
              items: [],
              me: meWithFace,
              profile: SessionProfile(
                company: OrgRef(publicId: 'c1', code: 'ADI', name: 'ADI Wholesale'),
                branch: OrgRef(publicId: 'b1', code: 'MMS', name: 'ময়মনসিংহ'),
                locale: 'bn',
                features: MeFeatures(profilePhoto: true),
              ),
            ),
            fetchToday: () async => throw StateError('offline'),
            lastKnownToday: () => null,
            readCachedOrg: () async => null,
            cacheOrg: (_) async {},
            checkUpdate: () async => UpdateStatus.fine,
            now: () => DateTime(2026, 10, 20, 10),
            fetchNoticeBar: () async => const [],
            fetchPending: () async => throw StateError('not an approver'),
            fetchNotifications: () async => throw StateError('offline'),
            syncNow: () async {},
            prefs: MemoryNavPrefsStore(),
            openPath: (path) async => opened.add(path),
          ),
        ),
      );

  ProviderContainer container() => ProviderContainer(overrides: [authStateProvider.overrideWith((ref) => SignedIn(me))]);

  // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): হোমের মুখ এখন নিচের বারের "আমি" ঘরে, উপরের বারে নয়
  ImageProvider? headerFace(WidgetTester tester) {
    expect(find.byKey(const ValueKey('header-avatar')), findsNothing, reason: 'the photo is on the bottom bar now');
    return tester.widget<AvatarDisc>(find.descendant(of: find.byKey(const ValueKey('nav-me')), matching: find.byKey(const ValueKey('nav-me-face')))).image;
  }

  testWidgets('the menu\'s profile row opens the profile screen', (tester) async {
    final opened = <String>[];
    final c = container();
    addTearDown(c.dispose);
    await tester.pumpWidget(shell(c, opened));
    await tester.pumpAndSettle();
    // ⭐ ১৪ নভেম্বর ২০২৬: ছবির মেনু নেই — "আমি" পাতার "প্রোফাইল ও ছবি" সারি
    await tester.tap(find.byKey(const ValueKey('nav-me')));
    await tester.pumpAndSettle();
    try {
      await tester.scrollUntilVisible(find.byKey(const ValueKey('me-profile')), 150,
          scrollable: find.descendant(of: find.byKey(const ValueKey('me-page')), matching: find.byType(Scrollable)).first);
    } on StateError {
      fail('⛔ "আমি" পাতার শেষ পর্যন্ত নেমেও "প্রোফাইল ও ছবি" সারি নেই');
    }
    expect(find.text('প্রোফাইল ও ছবি বদল'), findsOneWidget, reason: '⛔ সার্ভার ছবি বদল নেয়, তবু সারি "আমার প্রোফাইল"');
    await tester.tap(find.byKey(const ValueKey('me-profile')));
    await tester.pumpAndSettle();
    expect(opened, [ProfileRoutes.profile]);
    expect(ProfileRoutes.profile, 'me/profile');
    expect(find.byKey(const ValueKey('profile-dialog')), findsNothing, reason: 'আগের ছোট dialog নয় — পুরো পর্দা');
  });

  testWidgets('/me\'s features reach the menu and the profile screen', (tester) async {
    final c = container();
    addTearDown(c.dispose);
    expect(c.read(meFeaturesProvider), isNull, reason: '/me আসার আগে — জানা নেই');
    await tester.pumpWidget(shell(c, []));
    await tester.pumpAndSettle();
    expect(c.read(meFeaturesProvider), const MeFeatures(profilePhoto: true));
  });

  testWidgets('/me\'s face is shared, and a new face from the photo screen reaches the bottom bar\'s আমি and inner bars at once', (tester) async {
    final c = container();
    addTearDown(c.dispose);
    await tester.pumpWidget(shell(c, []));
    await tester.pumpAndSettle();
    expect(c.read(myFaceProvider), oldFace, reason: '/me-র মুখ ছবির পর্দার জন্য');
    expect((headerFace(tester)! as ResizeImage).imageProvider, const FaceImage(oldFace));

    c.read(myFaceProvider.notifier).state = newFace;
    await tester.pumpAndSettle();
    expect((headerFace(tester)! as ResizeImage).imageProvider, const FaceImage(newFace), reason: '⛔ নতুন মুখ বারে এল না');
    expect((c.read(topBarContextProvider)!.avatar! as ResizeImage).imageProvider, const FaceImage(newFace),
        reason: 'ভেতরের পর্দার বারও');

    c.read(myFaceProvider.notifier).state = null;
    await tester.pumpAndSettle();
    expect(headerFace(tester), isNull, reason: 'ছবি সরালে নামের অক্ষর');
  });

  test('the router has both screens under /home', () {
    final source = File('lib/core/router/app_router.dart').readAsStringSync();
    expect(source, contains('path: ProfileRoutes.profile,\n            builder: (context, state) => const ProfileScreen()'));
    expect(source, contains('path: ProfileRoutes.phones,\n            builder: (context, state) => const MyPhonesScreen()'));
    expect(ProfileRoutes.phones, 'me/phones');
  });

  testWidgets('a menu row can open "আমার ফোনগুলো" directly: the menu closes, the screen opens', (tester) async {
    final router = GoRouter(routes: [
      GoRoute(
        path: '/',
        builder: (context, _) => Scaffold(
          body: Center(
            child: TextButton(
              onPressed: () => showDialog<void>(
                context: context,
                builder: (menu) => AlertDialog(
                  key: const ValueKey('menu'),
                  content: TextButton(onPressed: () => ProfileRoutes.openPhonesFromMenu(menu), child: const Text('আমার ফোনগুলো')),
                ),
              ),
              child: const Text('ছবি'),
            ),
          ),
        ),
      ),
      GoRoute(path: '/home/me/phones', builder: (_, __) => const Scaffold(body: Text('PHONES'))),
    ]);
    await tester.pumpWidget(MaterialApp.router(routerConfig: router));
    await tester.tap(find.text('ছবি'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('আমার ফোনগুলো'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('menu')), findsNothing);
    expect(find.text('PHONES'), findsOneWidget);
  });

}
