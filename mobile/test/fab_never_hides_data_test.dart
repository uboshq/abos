import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/totals_bar.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';
import 'package:abos_mobile/features/orders/order_list_screen.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';

import 'navigation/bar_harness.dart' show rolePermissions;
import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart' show seedMainData, setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ ভাসমান "＋ নতুন" বোতাম কখনো তথ্য ঢাকে না (fe, ২২ অক্টোবর ২০২৬; Material 3: স্ক্রল করা লেখা FAB-এর নিচে আটকে
/// থাকে না)। প্রতিটা পর্দায় তালিকার একেবারে শেষে গেলে শেষ সারি আর মোট পুরো দেখা যায় — কোনো লেখার ঘর, মোটের সারি বা
/// কার্ডের নিচের দাগ বোতামের ঘরের সাথে মেলে না। ট্যাবলেটের চওড়া পাতা আর ৩৬০dp ফোন দুটোই।
class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

/// সব খাড়া তালিকা একেবারে শেষে
Future<void> _scrollToEnd(WidgetTester tester) async {
  for (var round = 0; round < 3; round++) {
    for (final s in tester.stateList<ScrollableState>(find.byType(Scrollable))) {
      final p = s.position;
      if (p.axis == Axis.vertical && p.hasContentDimensions) p.jumpTo(p.maxScrollExtent);
    }
    await tester.pumpAndSettle();
  }
}

/// বোতামের ঘরের সাথে মেলা প্রতিটা লেখা (বোতামের নিজের লেখা বাদে) আর প্রতিটা চাবি-দেওয়া ঘর
List<String> _underFab(WidgetTester tester, Finder fab, {List<String> keyed = const []}) {
  final fabRect = tester.getRect(fab);
  final hidden = <String>[];
  final inFab = find.descendant(of: fab, matching: find.byType(RichText)).evaluate().toSet();
  for (final e in find.byType(RichText).evaluate()) {
    if (inFab.contains(e)) continue;
    final box = e.renderObject! as RenderParagraph;
    if (!box.hasSize || !box.attached) continue;
    final rect = MatrixUtils.transformRect(box.getTransformTo(null), Offset.zero & box.size);
    if (rect.isEmpty) continue;
    if (rect.overlaps(fabRect)) hidden.add('"${box.text.toPlainText()}" $rect under FAB $fabRect');
  }
  // ⓘ কার্ডের নিচের দাগও তথ্য — শেষে কোনো কার্ড বোতামের ঘরে ঢোকে না
  for (final e in find.byType(Card).evaluate()) {
    final box = e.renderObject! as RenderBox;
    if (!box.hasSize || !box.attached) continue;
    final rect = MatrixUtils.transformRect(box.getTransformTo(null), Offset.zero & box.size);
    if (rect.overlaps(fabRect)) hidden.add('card ${e.widget.key} $rect under FAB $fabRect');
  }
  for (final k in keyed) {
    final rect = tester.getRect(find.byKey(ValueKey(k)));
    if (rect.overlaps(fabRect)) hidden.add('$k $rect under FAB $fabRect');
  }
  return hidden;
}

GoRouter _ownerRouter(String initial) => GoRouter(initialLocation: initial, routes: [
      GoRoute(
        path: '/home',
        builder: (context, state) => ScreenFrame(location: state.matchedLocation, child: ownerHomeBody()),
        routes: [
          GoRoute(
            path: 'orders',
            builder: (context, state) => ScreenFrame(location: state.matchedLocation, child: const OrderListScreen()),
          ),
        ],
      ),
    ]);

Widget _ownerApp({String initial = '/home'}) => ProviderScope(
      overrides: [
        authStateProvider.overrideWith((ref) => _SignedIn(AuthUser(
            id: 'u1', name: ownerUser.name, email: ownerUser.email, roles: ownerUser.roles, permissions: rolePermissions['owner']!))),
        topBarContextProvider.overrideWith((ref) => sampleTopBar()),
        navBadgesProvider.overrideWith((ref) => const NavBadges(unread: 3, pending: 3)),
        mayCollectProvider.overrideWith((ref) => false),
      ],
      child: MaterialApp.router(
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light(),
        routerConfig: _ownerRouter(initial),
      ),
    );

void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(SyncStatus.instance.reset);

  const sizes = {'360': Size(360, 800), '412': Size(412, 915), 'tablet_wide': Size(1280, 800)};

  for (final MapEntry(key: size, value: dp) in sizes.entries) {
    testWidgets('sr collect $size: at the end the last due and the total sit above "+ new collection"', (tester) async {
      setScreenSize(tester, dp);
      await tester.pumpWidget(inSession(framed(srCollectBody(canCollect: true))));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('range-today')));
      await tester.pumpAndSettle();
      await _scrollToEnd(tester);
      expect(find.byKey(const ValueKey('shop-dues-total')), findsOneWidget);
      expect(_underFab(tester, find.byKey(const ValueKey('money-in-new')), keyed: ['shop-dues-total', 'shop-dues']), isEmpty);
    });

    testWidgets('owner home $size: at the end the branch sales sit above the frame\'s "+ new"', (tester) async {
      setScreenSize(tester, dp);
      await tester.pumpWidget(_ownerApp());
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('frame-fab')), findsOneWidget);
      await _scrollToEnd(tester);
      expect(_underFab(tester, find.byKey(const ValueKey('frame-fab'))), isEmpty);
    });

    testWidgets('orders $size: the frame\'s "+ new order" sits above the totals bar and the last order', (tester) async {
      setScreenSize(tester, dp);
      await tester.runAsync(seedMainData);
      await tester.pumpWidget(_ownerApp(initial: '/home/orders'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('frame-fab')), findsOneWidget);
      expect(find.byType(TotalsBar), findsOneWidget);
      await _scrollToEnd(tester);
      expect(_underFab(tester, find.byKey(const ValueKey('frame-fab'))), isEmpty);
    });

    testWidgets('vouchers $size: at the end the last card sits above "+ new voucher"', (tester) async {
      setScreenSize(tester, dp);
      await tester.pumpWidget(MaterialApp(
        theme: AppTheme.light(),
        home: VoucherListScreen(
            api: const _Vouchers(), newChangeId: () => 'k', branches: () async => const [], today: DateTime(2026, 10, 20), canCreate: true),
      ));
      await tester.pumpAndSettle();
      await _scrollToEnd(tester);
      expect(_underFab(tester, find.byKey(const ValueKey('voucher-new'))), isEmpty);
    });
  }
}

class _Vouchers implements VoucherApi {
  const _Vouchers();

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: [
        VoucherRow(id: 'v1', no: 'RV-2610-0142', type: 'receipt', state: 'awaiting', amount: 48500, stateLabel: 'সইয়ের অপেক্ষায়', party: 'রহিম ট্রেডার্স'),
        VoucherRow(id: 'v2', no: 'PV-2610-0057', type: 'payment', state: 'posted', amount: 125000, stateLabel: 'পাকা', party: 'মেসার্স রহমান ট্রেডার্স'),
        VoucherRow(id: 'v3', no: 'EV-2610-0031', type: 'expense', state: 'draft', amount: 3200, stateLabel: 'খসড়া', party: 'গাড়িভাড়া'),
      ], count: 37, total: 1842650);

  @override
  Future<VoucherSetup> setup(String type) => throw UnimplementedError();

  @override
  Future<VoucherPage> one(String id) => throw UnimplementedError();

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) => throw UnimplementedError();

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) => throw UnimplementedError();
}
