import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/dashboard/dashboard_api.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/customer_line.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/core/widgets/row_icon.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';
import 'package:abos_mobile/features/dashboards/dashboards_screen.dart';
import 'package:abos_mobile/features/home/home_dashboard.dart';

/// ⭐ হোম আর ড্যাশবোর্ডের পর্দা নকশা ৩ মানে (a4-এর নিয়ম ০.৪.৩৮; মালিক, ১৯ অক্টোবর ২০২৬: "100% international standard and
/// enterprise grade")। আলো আর অন্ধকার দুই থিমে, ৩৬০dp ফোনে:
/// - লোড হতে ধূসর খসড়া ([LoadingSkeleton]), ঘোরানো চাকা নয়;
/// - খালি হলে [EmptyState] আর তার কাজের বোতাম — চাপলে সার্ভারকে আবার ডাকে;
/// - ভুল হলে [ErrorState] আর "আবার চেষ্টা করুন" — চাপলে সার্ভারকে আবার ডাকে, তারপর তথ্য দেখায়;
/// - তালিকার সারিতে গ্রাহক "নাম · পয়েন্ট · শাখা" (কখনো খালি নাম নয়), নম্বর দ্বিতীয় লাইনে, টাকা ডানে ৳ আর লাখের কমায়;
/// - সার্ভার সর্বমোট পাঠালে নিচের "সর্বমোট" সারির অঙ্ক সার্ভারের অঙ্কই — ফোন যোগ করে না।

final _themes = <String, ThemeData Function()>{'আলো': AppTheme.light, 'অন্ধকার': AppTheme.dark};

Future<void> _pump(WidgetTester tester, ThemeData theme, Widget screen) async {
  tester.view.physicalSize = const Size(360, 2000);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(const SizedBox());
  await tester.pumpWidget(MaterialApp(theme: theme, home: screen));
}

/// নকল সার্ভার: প্রথম ডাকে [first] (ছুড়লে ভুল), পরের ডাকে [then]; কতবার ডাকা হলো গোনে
class _Server<T> {
  _Server(this.first, this.then);

  final FutureOr<T> Function() first;
  final FutureOr<T> Function() then;
  int calls = 0;

  Future<T> call() async {
    calls++;
    return calls == 1 ? await first() : await then();
  }
}

const _shopName = 'রহিম ট্রেডার্স';
const _salesDash = ModuleDashboard({
  'title': 'বিক্রয়',
  'stats': [
    {'label': 'আজকের বিক্রি', 'value': '4,52,000.00', 'hidden': false, 'tone': 'neutral'},
  ],
  'panels': [],
  'listings': [
    {
      'label': 'সবচেয়ে বড় বকেয়া',
      'empty': 'কোনো বকেয়া নেই',
      'columns': [
        {'key': 'no', 'label': 'নম্বর'},
        {'key': 'customer', 'label': 'গ্রাহক'},
        {'key': 'point', 'label': 'পয়েন্ট'},
        {'key': 'branch', 'label': 'শাখা'},
        {'key': 'due', 'label': 'বকেয়া'},
      ],
      'rows': [
        ['INV-2610-0391', _shopName, 'মুক্তাগাছা', 'SL-Super Group', '48,500.00'],
        ['INV-2610-0388', 'করিম স্টোর', 'ফুলপুর', 'SL-Super Group', '1,25,000.00'],
      ],
      // ⓘ সার্ভারের পুরো তালিকার সর্বমোট — ইচ্ছে করে দেখানো সারির যোগফল (1,73,500) নয়, যাতে ফোন নিজে যোগ করলে ধরা পড়ে
      'total': {'due': '18,42,650.00', 'no': '37টি বিল'},
    },
  ],
});

const _empty = ModuleDashboard({'title': 'বিক্রয়', 'stats': [], 'panels': [], 'listings': []});

void main() {
  for (final t in _themes.entries) {
    group('${t.key} থিম', () {
      // ── ব্যবসার ড্যাশবোর্ডের তালিকা ───────────────────────────────────────
      testWidgets('ড্যাশবোর্ডের তালিকা: লোডে খসড়া, ভুলে আবার চেষ্টা, সারিতে মডিউলের আইকন', (tester) async {
        final wait = Completer<List<DashboardEntry>>();
        await _pump(tester, t.value(), DashboardsScreen(loadList: () => wait.future));
        await tester.pump();
        expect(find.byType(LoadingSkeleton), findsOneWidget);
        expect(find.byType(CircularProgressIndicator), findsNothing, reason: 'ঘোরানো চাকা নয়, খসড়া');

        final server = _Server<List<DashboardEntry>>(
          () => throw Exception('down'),
          () => const [DashboardEntry({'module': 'sales', 'name': 'বিক্রয়'})],
        );
        await _pump(tester, t.value(), DashboardsScreen(loadList: server.call));
        await tester.pumpAndSettle();
        expect(find.byType(ErrorState), findsOneWidget);
        expect(find.textContaining('আনা গেল না'), findsOneWidget, reason: 'বাংলায় কারণ');
        await tester.tap(find.byKey(const ValueKey('error-retry')));
        await tester.pumpAndSettle();
        expect(server.calls, 2, reason: '"আবার চেষ্টা" সার্ভারকে আবার ডাকে');
        expect(find.byType(ErrorState), findsNothing);
        expect(find.text('বিক্রয়'), findsOneWidget);
        final icon = tester.widget<RowIcon>(find.byType(RowIcon));
        expect(icon.module, 'sales');
        expect(icon.icon, NavCatalog.moduleDef('sales')!.icon);
        expect(tester.getSize(find.byType(ListTile)).height, greaterThanOrEqualTo(72));
      });

      testWidgets('ড্যাশবোর্ডের তালিকা খালি: কারণ আর "নতুন করে দেখুন", চাপলে আবার আনে', (tester) async {
        final server = _Server<List<DashboardEntry>>(() => const [], () => const []);
        await _pump(tester, t.value(), DashboardsScreen(loadList: server.call));
        await tester.pumpAndSettle();
        expect(find.byType(EmptyState), findsOneWidget);
        final button = find.byKey(const ValueKey('empty-refresh'));
        expect(tester.getSize(button).height, greaterThanOrEqualTo(48));
        await tester.tap(button);
        await tester.pumpAndSettle();
        expect(server.calls, 2);
      });

      // ── একটা মডিউলের ড্যাশবোর্ড ──────────────────────────────────────────
      testWidgets('মডিউলের ড্যাশবোর্ড: খসড়া → ভুল → আবার চেষ্টা → নকশার সারি আর সার্ভারের সর্বমোট', (tester) async {
        final wait = Completer<ModuleDashboard>();
        await _pump(tester, t.value(),
            ModuleDashboardScreen(module: 'sales', name: 'বিক্রয়', open: (_) => wait.future));
        await tester.pump();
        expect(find.byType(LoadingSkeleton), findsOneWidget);

        final server = _Server<ModuleDashboard>(() => throw Exception('down'), () => _salesDash);
        await _pump(tester, t.value(),
            ModuleDashboardScreen(module: 'sales', name: 'বিক্রয়', open: (_) => server.call()));
        await tester.pumpAndSettle();
        expect(find.byType(ErrorState), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('error-retry')));
        await tester.pumpAndSettle();
        expect(server.calls, 2);

        // ⭐ গ্রাহক "নাম · পয়েন্ট · শাখা" — খালি নাম কোথাও নয় (মালিকের স্থায়ী নিয়ম, ১৯ অক্টোবর ২০২৬)
        final line = find.widgetWithText(CustomerLine, '$_shopName · মুক্তাগাছা · SL-Super Group');
        expect(line, findsOneWidget);
        expect(find.text(_shopName), findsNothing, reason: 'গ্রাহকের খালি নাম দেখানো যাবে না');
        expect(tester.widget<Text>(find.descendant(of: line, matching: find.byType(Text))).style?.fontSize, 16);

        // ⓘ নম্বর দ্বিতীয় লাইনে — নামের নিচে
        final number = find.text('INV-2610-0391');
        expect(number, findsOneWidget, reason: 'নম্বর নিজের লাইনে, পয়েন্ট/শাখা নামের সাথে জোড়া — আলাদা ঘর নয়');
        expect(tester.getTopLeft(number).dy, greaterThan(tester.getTopLeft(line).dy));

        // ⓘ টাকা ডানে, ৳ আর লাখের কমা, 15 w700
        final money = find.text('৳48,500.00');
        expect(money, findsOneWidget);
        expect(tester.getTopRight(money).dx, greaterThan(tester.getTopRight(number).dx));
        final style = tester.widget<Text>(money).style!;
        expect((style.fontSize, style.fontWeight), (15, FontWeight.w700));
        expect(find.byType(RowIcon), findsNWidgets(2));
        expect(tester.getSize(find.byKey(const ValueKey('listing-row-0'))).height, greaterThanOrEqualTo(72));

        // ⭐ সর্বমোট = সার্ভারের পুরো তালিকার অঙ্ক, দেখানো দুই সারির যোগ নয়
        await tester.scrollUntilVisible(find.byKey(const ValueKey('listing-total')), 200);
        expect(tester.widget<Text>(find.byKey(const ValueKey('listing-total-money'))).data, '৳18,42,650.00');
        expect(find.text('৳1,73,500.00'), findsNothing, reason: 'ফোন নিজে যোগ করে না');
        expect(find.text('নম্বর: 37টি বিল'), findsOneWidget);
      });

      testWidgets('মডিউলের ড্যাশবোর্ড খালি: কারণ আর "নতুন করে দেখুন", চাপলে আবার আনে', (tester) async {
        final server = _Server<ModuleDashboard>(() => _empty, () => _empty);
        await _pump(tester, t.value(),
            ModuleDashboardScreen(module: 'sales', name: 'বিক্রয়', open: (_) => server.call()));
        await tester.pumpAndSettle();
        expect(find.byType(EmptyState), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('empty-refresh')));
        await tester.pumpAndSettle();
        expect(server.calls, 2);
      });

      // ── বিক্রয়ের হালচাল ─────────────────────────────────────────────────
      testWidgets('বিক্রয়ের হালচাল: খসড়া, ভুলে আবার চেষ্টা, খালিতে বোতাম', (tester) async {
        final today = DateTime(2026, 10, 20);
        final wait = Completer<ModuleDashboard>();
        await _pump(tester, t.value(), SalesOverviewScreen(open: (_, __) => wait.future, today: today));
        await tester.pump();
        expect(find.byType(LoadingSkeleton), findsOneWidget);
        expect(find.byType(LinearProgressIndicator), findsNothing, reason: 'প্রথমবার খসড়া, দাগ নয়');

        final server = _Server<ModuleDashboard>(() => throw Exception('down'), () => _empty);
        await _pump(tester, t.value(), SalesOverviewScreen(open: (_, __) => server.call(), today: today));
        await tester.pumpAndSettle();
        expect(find.byType(ErrorState), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('error-retry')));
        await tester.pumpAndSettle();
        expect(server.calls, 2);
        expect(find.byType(EmptyState), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('empty-refresh')));
        await tester.pumpAndSettle();
        expect(server.calls, 3);
      });

      // ── হোম ────────────────────────────────────────────────────────────
      testWidgets('হোম: ড্যাশবোর্ড, প্রিয় আর সাম্প্রতিকের সারিতে নকশার RowIcon, সারি ৭২dp', (tester) async {
        await _pump(
          tester,
          t.value(),
          Scaffold(
            body: HomeDashboard(
              user: const AuthUser(id: 'u1', name: 'করিম', email: 'k@x', roles: [], permissions: []),
              items: const [],
              fetchToday: () async => const TodayRecord({}),
              favourites: const [
                NavRow(id: 'new-order', label: 'নতুন অর্ডার', icon: Icons.add, module: 'sales', path: 'new-order'),
              ],
              recents: const [
                RecentEntry(path: 'orders', label: 'অর্ডারের অবস্থা'),
                RecentEntry(path: 'customers/c1', label: 'রহিম ট্রেডার্স · মুক্তাগাছা'),
              ],
              onOpen: (_, __) {},
            ),
          ),
        );
        await tester.pumpAndSettle();
        RowIcon iconIn(Key key) =>
            tester.widget<RowIcon>(find.descendant(of: find.byKey(key), matching: find.byType(RowIcon)));
        expect(iconIn(const Key('home-dashboards')).icon, Icons.insights_outlined);
        expect(iconIn(const ValueKey('favourite-new-order')).module, 'sales');
        expect(iconIn(const ValueKey('recent-orders')).module, NavCatalog.screenDef('orders')?.module);
        expect(iconIn(const ValueKey('recent-customers/c1')).icon, Icons.history);
        for (final key in [
          const Key('home-dashboards'),
          const ValueKey('recent-orders'),
          const ValueKey('recent-customers/c1'),
        ]) {
          expect(tester.getSize(find.byKey(key)).height, greaterThanOrEqualTo(72), reason: '$key');
        }
      });
    });
  }
}
