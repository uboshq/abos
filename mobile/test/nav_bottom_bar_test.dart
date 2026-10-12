import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';

/// ⭐ নিচের বার পর্দার নিচেও — যেকোনো পর্দা থেকে এক চাপে হোম, সব মডিউল, আমি (মালিক, ১২ অক্টোবর ২০২৬)।
/// The real router builds the whole home with its network behind it, so the
/// frame is held here in a router of the same shape: `/home` with its screens
/// under it, each screen wrapped the way app_router.dart's `_framed` wraps it.
void main() {
  Widget app({NavBadges badges = const NavBadges()}) {
    GoRoute framed(String path, String title) => GoRoute(
          path: path,
          builder: (context, state) => ScreenFrame(
            location: state.uri.path,
            child: Scaffold(appBar: AppBar(title: Text(title))),
          ),
        );
    final router = GoRouter(initialLocation: '/home', routes: [
      GoRoute(
        path: '/home',
        builder: (context, state) => Consumer(
          builder: (context, ref, _) => Scaffold(
            body: Text('HOME PAGE: ${ref.watch(homePageProvider).kind.name}'),
          ),
        ),
        routes: [
          framed('orders', 'ORDERS SCREEN'),
          framed('approvals', 'APPROVALS SCREEN'),
          framed('notifications', 'NOTIFICATIONS SCREEN'),
        ],
      ),
    ]);
    return ProviderScope(
      overrides: [navBadgesProvider.overrideWith((ref) => badges)],
      child: MaterialApp.router(routerConfig: router),
    );
  }

  Future<void> openOrders(WidgetTester tester) async {
    final router = GoRouter.of(tester.element(find.textContaining('HOME PAGE')));
    router.go('/home/orders');
    await tester.pumpAndSettle();
  }

  testWidgets('a screen keeps its own back arrow and gets the bar under it', (tester) async {
    await tester.pumpWidget(app());
    await openOrders(tester);

    expect(find.text('ORDERS SCREEN'), findsOneWidget);
    expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget);
    expect(find.byType(BackButton), findsOneWidget,
        reason: 'the screen sits on the home, so its arrow still goes up');
  });

  testWidgets('from a screen, one tap reaches the home, all modules or me', (tester) async {
    await tester.pumpWidget(app());

    await openOrders(tester);
    await tester.tap(find.byKey(const ValueKey('nav-modules')));
    await tester.pumpAndSettle();
    expect(find.text('HOME PAGE: modules'), findsOneWidget);

    await openOrders(tester);
    await tester.tap(find.byKey(const ValueKey('nav-me')));
    await tester.pumpAndSettle();
    expect(find.text('HOME PAGE: me'), findsOneWidget);

    await openOrders(tester);
    await tester.tap(find.byKey(const ValueKey('nav-home')));
    await tester.pumpAndSettle();
    expect(find.text('HOME PAGE: home'), findsOneWidget);
  });

  testWidgets('Android back on a screen goes one level up, to where it was opened from',
      (tester) async {
    await tester.pumpWidget(app());
    await openOrders(tester);

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('ORDERS SCREEN'), findsNothing);
    expect(find.textContaining('HOME PAGE'), findsOneWidget);
  });

  testWidgets('somebody the server does not let approve gets no approval tab', (tester) async {
    await tester.pumpWidget(app());
    await openOrders(tester);

    expect(find.byKey(const ValueKey('nav-approvals')), findsNothing);
    expect(find.byKey(const ValueKey('nav-notifications')), findsOneWidget);
  });

  testWidgets('an approver sees the waiting count in red, and the tab opens the inbox',
      (tester) async {
    await tester.pumpWidget(app(badges: const NavBadges(pending: 4, unread: 2)));
    await openOrders(tester);

    expect(find.byKey(const ValueKey('nav-approvals')), findsOneWidget);
    expect(find.text('4'), findsOneWidget);
    expect(find.text('2'), findsOneWidget, reason: 'the bell counts what is unread');

    await tester.tap(find.byKey(const ValueKey('nav-approvals')));
    await tester.pumpAndSettle();
    expect(find.text('APPROVALS SCREEN'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('nav-notifications')));
    await tester.pumpAndSettle();
    expect(find.text('NOTIFICATIONS SCREEN'), findsOneWidget);
  });

  test('which tab is lit on a screen', () {
    expect(ScreenFrame.tabFor('/home/approvals'), NavTab.approvals);
    expect(ScreenFrame.tabFor('/home/notifications'), NavTab.notifications);
    expect(ScreenFrame.tabFor('/home/sync-status'), NavTab.me);
    expect(ScreenFrame.tabFor('/home/customers/c1'), NavTab.modules);
  });
}
