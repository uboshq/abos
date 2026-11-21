import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/core/messenger/messenger_unread.dart';
import 'package:abos_mobile/core/navigation/role_tabs.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/core/profile/me_profile_api.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';
import 'package:abos_mobile/features/messenger/list/chat_list_page.dart';
import 'package:abos_mobile/features/messenger/list/send_paper_sheet.dart';
import 'package:abos_mobile/features/profile/me_page.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fakes/fake_me_profile_api.dart';
import '../fakes/fake_messenger_api.dart';
import '../profile/profile_fakes.dart';
import '../support/bar_fonts.dart';

/// ⭐ বার্তার তালিকা আর "বার্তায় পাঠান"-এর ছবি — মালিকের নমুনা 3nuwRUdn v5 "ক" (তিন রূপ) আর "গ" ৩ (ক্লাসিক), ৪১২×৯০০; আর
/// ক্লাসিকের অন্ধকার। অ্যাপের নিজের উপরের বার (বার্তা-বোতাম খোলা, নীল) আর নিচের বারের ভেতরে। ছবি `test/messenger_list/goldens/`-এ।
void main() {
  setUpAll(loadBarFonts);

  late AppLooks saved;
  setUp(() {
    saved = AppLooks.instance;
    AppLooks.instance = AppLooks(read: (_) async => null, write: (_, __) async {});
    AppLooks.instance.motion.value = false;
  });
  tearDown(() {
    AppLooks.instance = saved;
    MessengerUnread.count.value = null;
  });

  ThemeData themeOf(AppLook look, {bool dark = false}) => withBarFonts(switch (look) {
        AppLook.glass => AppTheme.glass(GlassSpec.of(AppLooks.instance.blur.value)),
        AppLook.expressive => AppTheme.expressive(),
        AppLook.abos => dark ? AppTheme.dark() : AppTheme.light(),
      });

  Future<void> shoot(WidgetTester tester, AppLook look, String name, {bool dark = false, bool chatOpen = true, Widget? page}) async {
    AppLooks.instance.look.value = look;
    tester.view.physicalSize = const Size(412, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final body = Scaffold(
      extendBody: look != AppLook.abos,
      backgroundColor: look == AppLook.glass ? Colors.transparent : null,
      appBar: AbosTopBar(
        company: 'ADI Corporation',
        branch: 'TD শাখা',
        onOrgTap: () {},
        demo: false,
        syncStatus: SyncStatus(),
        onLeading: () {},
        onSearch: () {},
        onCreate: () {},
        onMessages: () {},
        messagesActive: chatOpen,
        avatarName: 'শুভ',
        showAvatar: false,
        onAvatar: (_) {},
      ),
      body: page ?? ChatListPage(now: () => FakeMessengerApi.at(12, 0)),
      bottomNavigationBar: RoleBottomBar(
        tabs: const [BarTab.home, BarTab.copilot, BarTab.deposit, BarTab.modules, BarTab.orders, BarTab.notices, BarTab.me],
        selected: null,
        badges: {BarTab.notices: RoleTabs.badgeFor(20)},
        onSelect: (_) {},
      ),
    );
    final c = ProviderContainer(overrides: [
      authStateProvider.overrideWith((ref) => SignedIn()),
      messengerApiProvider.overrideWithValue(FakeMessengerApi()),
      meProfileApiProvider.overrideWithValue(FakeMeProfileApi()),
    ]);
    addTearDown(c.dispose);
    c.read(meFeaturesProvider.notifier).state = const MeFeatures(messaging: true, calls: true);
    c.read(myFaceProvider.notifier).state = null;
    await tester.pumpWidget(UncontrolledProviderScope(
      container: c,
      child: RepaintBoundary(
        key: const ValueKey('shot'),
        child: MaterialApp(
          debugShowCheckedModeBanner: false,
          theme: themeOf(look, dark: dark),
          locale: AppLocale.locale,
          supportedLocales: AppLocale.supported,
          localizationsDelegates: AppLocale.delegates,
          home: look == AppLook.glass ? GlassBackdrop(child: body) : body,
        ),
      ),
    ));
    await tester.pumpAndSettle();
  }

  Future<void> match(String name) => expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/$name.png'));

  for (final look in [AppLook.expressive, AppLook.glass, AppLook.abos]) {
    testWidgets('chat list — ${look.name}', (tester) async {
      await shoot(tester, look, 'list_${look.name}');
      await match('list_${look.name}');
    });
  }

  testWidgets('chat list — classic dark', (tester) async {
    await shoot(tester, AppLook.abos, 'list_abos_dark', dark: true);
    await match('list_abos_dark');
  });

  testWidgets('top bar closed shows the unread 8 — classic', (tester) async {
    MessengerUnread.count.value = 8;
    await shoot(tester, AppLook.abos, 'bar_unread', chatOpen: false, page: const SizedBox.expand());
    await match('bar_unread_abos');
  });

  for (final (look, dark) in [(AppLook.abos, false), (AppLook.abos, true), (AppLook.expressive, false), (AppLook.glass, false)]) {
    final name = 'send_sheet_${look.name}${dark ? '_dark' : ''}';
    testWidgets('send paper sheet — $name', (tester) async {
      await shoot(tester, look, name,
          dark: dark,
          chatOpen: false,
          page: Builder(
            builder: (c) => Center(
              child: TextButton(
                onPressed: () => showSendPaperSheet(
                    c,
                    const PaperRef(
                        type: 'SalesInvoice', id: 'inv517', no: 'INV-0517', title: 'বিক্রয় চালান', amount: '৳86,400', party: 'রহমান এন্টারপ্রাইজ')),
                child: const Text('open'),
              ),
            ),
          ));
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('send-row-person:rak')));
      await tester.tap(find.byKey(const ValueKey('send-row-thread:t-acc')));
      await tester.pumpAndSettle();
      await match(name);
    });
  }

  // ⭐ "আমি"-র "বার্তা" আর "কল" — `features.messaging`/`calls` চালু, শেল দুটোই দেয়: "শীঘ্রই" চিহ্ন নেই
  for (final (look, dark) in [(AppLook.abos, false), (AppLook.abos, true), (AppLook.expressive, false), (AppLook.glass, false)]) {
    final name = 'me_buttons_${look.name}${dark ? '_dark' : ''}';
    testWidgets('me page message and call — $name', (tester) async {
      await shoot(tester, look, name,
          dark: dark,
          chatOpen: false,
          page: MePage(
            fallback: MeProfile.fallback(
                name: 'শুভ আহমেদ', designation: 'মালিক', company: 'ADI Corporation', branch: 'TD শাখা', email: 'shuvo@example.com'),
            profileApi: FakeProfileApi(),
            now: () => DateTime(2026, 11, 20, 12),
            onEditProfile: () {},
            onMessage: () {},
            onCall: () {},
          ));
      expect(find.byKey(const ValueKey('me-message')), findsOneWidget);
      expect(find.byKey(const ValueKey('me-call')), findsOneWidget);
      expect(find.text('শীঘ্রই'), findsNothing);
      await match(name);
    });
  }
}
