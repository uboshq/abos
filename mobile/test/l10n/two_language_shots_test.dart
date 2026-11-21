import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:image/image.dart' as img;

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/core/records/report_record.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:abos_mobile/features/books/collect_screen.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';
import 'package:abos_mobile/features/profile/profile_screen.dart';
import 'package:abos_mobile/features/reports/reports_screen.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';

import '../navigation/bar_harness.dart' show rolePermissions, loadRealFonts, realFontTheme;
import '../profile/profile_fakes.dart' show FakeProfileApi, oldFace;
import '../support/collect_fake.dart';
import '../support/screens_0441.dart' show ownerHomeBody, ownerToday, waitingPapers;

/// ⭐ ছয় মূল পর্দা, বাংলায় আর ইংরেজিতে — ভাষার সুইচ চালুর দিনের ছবি (a4, ০.৪.৪৪, ২২ অক্টোবর ২০২৬): হোম, টাকা নেওয়া (সহজ
/// আদায়), ভাউচার, অনুমোদন, রিপোর্ট, প্রোফাইল। ৪১২dp, আলো, আসল ফন্টে (Roboto, Shonar Bangla, Material Symbols), নীল বার আর
/// নিচের বারসহ। ইংরেজির ছবিতে পর্দার নিজের কোনো বাংলা নেই — কেবল সার্ভারের নাম (দোকান, মানুষ, শাখা)।
/// ছবি `test/goldens/two_languages/`; মালিকের ফোল্ডারে নকল হয়।
final _bengali = RegExp('[ঀ-৲৴-৿]');

/// সার্ভার থেকে আসা নাম — ইংরেজিতেও বাংলা থাকে
const _serverWords = [
  'আল-আমিন শুভ',
  'রহিম স্টোর',
  'কেন্দুয়া',
  'ময়মনসিংহ',
  'নেত্রকোনা',
  'ACI লিমিটেড',
  'গাড়ির ভাড়া',
  'করিম স্টোর',
  'ফুলপুর পয়েন্ট',
  'প্রধান নগদ',
  'আল-আমিনের নগদ',
  'ডাচ-বাংলা',
  'রহিম ট্রেডার্স',
  'মেসার্স রহমান ট্রেডার্স',
  'গাড়িভাড়া',
];

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

final _owner = AuthUser(
    id: 'u1', name: 'আল-আমিন শুভ', email: 'alamin@adi.test', roles: const ['Owner'], permissions: rolePermissions['owner']!);

class _Pref extends StatelessWidget implements PreferredSizeWidget {
  const _Pref(this.child);
  final Widget child;
  @override
  Size get preferredSize => const Size.fromHeight(AbosTopBar.maxHeight);
  @override
  Widget build(BuildContext context) => child;
}

class _Vouchers implements VoucherApi {
  const _Vouchers(this.en);
  final bool en;

  @override
  Future<VoucherListPage> list(
          {String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      VoucherListPage(rows: [
        VoucherRow(
            id: 'v1',
            no: 'RV-2610-0142',
            type: 'receipt',
            state: 'awaiting',
            amount: 48500,
            stateLabel: en ? 'Awaiting signature' : 'অনুমোদনের অপেক্ষায়',
            party: 'রহিম ট্রেডার্স'),
        VoucherRow(
            id: 'v2',
            no: 'PV-2610-0057',
            type: 'payment',
            state: 'posted',
            amount: 125000,
            stateLabel: en ? 'Posted' : 'পাকা',
            party: 'মেসার্স রহমান ট্রেডার্স'),
        VoucherRow(
            id: 'v3',
            no: 'EV-2610-0031',
            type: 'expense',
            state: 'draft',
            amount: 3200,
            stateLabel: en ? 'Draft' : 'খসড়া',
            party: 'গাড়িভাড়া'),
      ], count: 37, total: 1842650);

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

/// সার্ভারের রিপোর্টের নাম — সার্ভার মানুষের ভাষায় পাঠায় (`Accept-Language`)
List<ReportSummary> _reports(bool en) => [
      ReportSummary({'key': 'sales.daily', 'title': en ? 'Daily sales' : 'দৈনিক বিক্রয়', 'module': 'sales'}),
      ReportSummary({'key': 'sales.dues', 'title': en ? 'Customer dues' : 'গ্রাহকের বকেয়া', 'module': 'sales'}),
      ReportSummary({'key': 'accounts.trial', 'title': en ? 'Trial balance' : 'রেওয়ামিল', 'module': 'accounts'}),
      ReportSummary({'key': 'accounts.cash_book', 'title': en ? 'Cash book' : 'নগদান বই', 'module': 'accounts'}),
      ReportSummary({'key': 'inventory.stock_value', 'title': en ? 'Stock value' : 'মজুদের মূল্য', 'module': 'inventory'}),
    ];

/// মুখের জায়গায় একটা স্থির ছবি
final Uint8List _face = () {
  final image = img.Image(width: 96, height: 96);
  for (final p in image) {
    p
      ..r = 230 - p.y
      ..g = 140 - p.y ~/ 2
      ..b = 60;
  }
  return Uint8List.fromList(img.encodePng(image));
}();

Future<void> _loadRounded() async {
  final config = File('.dart_tool/package_config.json').readAsStringSync();
  final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
  final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
  final loader = FontLoader('packages/material_symbols_icons/MaterialSymbolsRounded')
    ..addFont(Future.value(
        ByteData.view(Uint8List.fromList(File('$root/lib/fonts/MaterialSymbolsRounded.ttf').readAsBytesSync()).buffer)));
  await loader.load();
}

/// এক পর্দা — পথ আর পাতা
Widget _screen(String screen, bool en) => switch (screen) {
      'home' => Scaffold(
          appBar: _Pref(AbosTopBar(
            company: 'ADI Wholesale',
            companyCode: 'ADI',
            branch: en ? 'All branches' : 'সব শাখা',
            fallbackTitle: _owner.name,
            crumbs: const [],
            leading: TopBarLeading.menu,
            onLeading: () {},
            onSearch: () {},
            // ⓘ HomeShell-এর মতোই উপরে ＋ (দ্রুত কাজ) — 0.4.63 থেকে হোমে নিচের "＋ নতুন" নেই
            onCreate: () {},
            onBell: () {},
            unread: 3,
            avatarName: _owner.name,
            onAvatar: (_) async {},
            // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): HomeShell-এর মতোই — উপরের বারে ছবি নেই, ছবি নিচের বারের "আমি"
            showAvatar: false,
            syncStatus: SyncStatus(),
            pendingCount: () => 0,
          )),
          body: ownerHomeBody(today: {...ownerToday(), 'branch': en ? 'All branches' : 'সব শাখা'}, user: _owner),
        ),
      'collect' => CollectScreen(
          api: FakeCollect(preview: previewJson()),
          books: FakeBooks(),
          newChangeId: () => 'k',
          today: DateTime(2026, 10, 21),
          openReceipt: (_, __, ___) async {},
          otherParty: (_, __) async {},
          pickSlip: (_, {required camera}) async => null,
        ),
      'vouchers' => VoucherListScreen(
          api: _Vouchers(en),
          newChangeId: () => 'k',
          branches: () async => const [],
          today: DateTime(2026, 10, 20),
          canCreate: true,
          mayCollect: true,
        ),
      'approvals' => ApprovalInboxScreen(
          loadPending: () async => waitingPapers,
          approve: (_, __) async {},
          reject: (_, __) async {},
        ),
      'reports' => ReportsScreen(loadList: () async => _reports(en)),
      _ => ProfileScreen(api: FakeProfileApi(), faceOf: (_) => MemoryImage(_face)),
    };

GoRouter _router(String screen, bool en) => GoRouter(initialLocation: screen == 'home' ? '/home' : '/home/$screen', routes: [
      GoRoute(
        path: '/home',
        builder: (context, state) => ScreenFrame(location: state.matchedLocation, child: _screen('home', en)),
        routes: [
          for (final s in ['collect', 'vouchers', 'approvals', 'reports', 'profile'])
            GoRoute(
              path: s,
              builder: (context, state) => ScreenFrame(location: state.matchedLocation, child: _screen(s, en)),
            ),
        ],
      ),
    ]);

Widget _app(String screen, Locale locale) {
  final en = AppLocale.isEnglish(locale);
  final c = ProviderContainer(overrides: [
    authStateProvider.overrideWith((ref) => _SignedIn(_owner)),
    topBarContextProvider.overrideWith((ref) => TopBarContext(
          company: 'ADI Wholesale',
          companyCode: 'ADI',
          branch: 'ময়মনসিংহ',
          designation: en ? 'Owner' : 'মালিক',
          search: (_) async {},
          openNotifications: (_) async {},
          openMenu: (_, __) async {},
        )),
    navBadgesProvider.overrideWith((ref) => const NavBadges(unread: 3, pending: 3)),
    mayCollectProvider.overrideWith((ref) => true),
  ]);
  c.read(myFaceProvider.notifier).state = oldFace;
  c.read(meFeaturesProvider.notifier).state = const MeFeatures(profilePhoto: true, myPhones: true);
  addTearDown(c.dispose);
  return UncontrolledProviderScope(
    container: c,
    child: MaterialApp.router(
      debugShowCheckedModeBanner: false,
      theme: realFontTheme(AppTheme.light()),
      themeMode: ThemeMode.light,
      locale: locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      routerConfig: _router(screen, en),
      builder: (context, child) => RepaintBoundary(key: const ValueKey('shot'), child: child!),
    ),
  );
}

/// টাকা নেওয়ার পাতা ভরা — নকশার নমুনা: করিম স্টোর, ৳45,000, ব্যাংক, TrxID
Future<void> _fillCollect(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('collect-customer')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('collect-pick-cus-1')));
  await tester.pumpAndSettle();
  await tester.enterText(find.byKey(const ValueKey('collect-amount')), '45000');
  await tester.pumpAndSettle();
  FocusManager.instance.primaryFocus?.unfocus();
  await tester.pumpAndSettle();
}

List<String> _texts(WidgetTester tester) => [
      for (final t in tester.widgetList<Text>(find.byType(Text))) t.data ?? t.textSpan?.toPlainText() ?? '',
      for (final t in tester.widgetList<RichText>(find.byType(RichText))) t.text.toPlainText(),
      for (final d in tester.widgetList<InputDecorator>(find.byType(InputDecorator))) ...[
        d.decoration.labelText ?? '',
        d.decoration.hintText ?? '',
        d.decoration.helperText ?? '',
      ],
    ];

void main() {
  setUpAll(() async {
    await loadRealFonts();
    await _loadRounded();
  });

  const screens = ['home', 'collect', 'vouchers', 'approvals', 'reports', 'profile'];
  // ⓘ পাতার পুরোটা এক ছবিতে — ফোনের প্রস্থ, লম্বা পাতা
  const heights = {'home': 1700.0, 'collect': 1900.0, 'vouchers': 915.0, 'approvals': 1000.0, 'reports': 915.0, 'profile': 1100.0};

  for (final screen in screens) {
    for (final (locale, tag) in [(AppLocale.locale, 'bn'), (AppLocale.english, 'en')]) {
      testWidgets('$screen at 412dp, light, $tag', (tester) async {
        AppLocale.current = locale;
        addTearDown(() => AppLocale.current = AppLocale.locale);
        tester.view.physicalSize = Size(412, heights[screen]!) * 2;
        tester.view.devicePixelRatio = 2;
        addTearDown(tester.view.reset);
        debugDisableShadows = false;
        try {
          await tester.pumpWidget(_app(screen, locale));
          await tester.pumpAndSettle();
          if (screen == 'collect') await _fillCollect(tester);
          if (screen == 'profile') {
            await tester
                .runAsync(() => precacheImage(MemoryImage(_face), tester.element(find.byKey(const ValueKey('profile-screen')))));
            await tester.pumpAndSettle();
          }
          expect(tester.takeException(), isNull, reason: '⛔ পর্দায় উপচে পড়া বা ভুল');
          if (tag == 'en') {
            // ⓘ ছবির জায়গার আদ্যক্ষর — নামের প্রথম অক্ষর, নামেরই অংশ
            final own = _texts(tester)
                .where(
                    (t) => _bengali.hasMatch(t) && !_serverWords.any(t.contains) && !(t.length <= 2 && _owner.name.startsWith(t)))
                .toSet()
                .toList();
            expect(own, isEmpty, reason: 'ইংরেজিতে পর্দার নিজের বাংলা: $own');
          }
          await expectLater(
              find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/two_languages/${screen}_412_light_$tag.png'));
        } finally {
          debugDisableShadows = true;
        }
      });
    }
  }
}
