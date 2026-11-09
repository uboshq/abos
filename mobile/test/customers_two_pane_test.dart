import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/features/customers/customer_detail_screen.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart';

/// ⭐ ট্যাবলেট আর আড়াআড়ি ফোন — ৬০০ dp-র বেশি চওড়ায় গ্রাহকের তালিকা আর গ্রাহকের পাতা পাশাপাশি; ফোনে এক ঘর, চাপলে
/// নিজের পাতা; ঘোরালে বাছা গ্রাহক হারায় না (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)।
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
  });

  tearDownAll(() async => harness.tearDown());

  /// আসল অ্যাপের মতো পথ — ফোনে সারি চাপলে `/home/customers/:id`-এ যায়
  Widget routed() => MaterialApp.router(
        routerConfig: GoRouter(
          initialLocation: '/home/customers',
          routes: [
            GoRoute(
              path: '/home/customers',
              builder: (context, state) => const CustomerListScreen(),
              routes: [
                GoRoute(
                  path: ':id',
                  builder: (context, state) =>
                      CustomerDetailScreen(customerId: state.pathParameters['id']!, refresh: () async => null),
                ),
              ],
            ),
          ],
        ),
      );

  Future<void> open(WidgetTester tester, Size size) async {
    setScreenSize(tester, size);
    await tester.runAsync(seedMainData);
    await tester.pumpWidget(routed());
    await tester.pumpAndSettle();
  }

  testWidgets('at 900x600 the list and the chosen customer sit side by side', (tester) async {
    await open(tester, const Size(900, 600));

    expect(find.byKey(const ValueKey('customers-two-pane')), findsOneWidget);
    expect(find.byKey(const ValueKey('customer-row-$shop2Id')), findsOneWidget);
    expect(find.byKey(const ValueKey('customers-detail-empty')), findsOneWidget,
        reason: 'nothing chosen yet — the right pane says to choose');

    // ⓘ সারিতে এখন "অ্যাকশন ▾" (৯ নভেম্বর ২০২৬) — সারি উঁচু, ৬০০dp-তে দ্বিতীয় দোকান পর্দার নিচে; মানুষ যেমন গড়িয়ে নেন
    await tester.ensureVisible(find.byKey(const ValueKey('customer-row-$shop2Id')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('customer-row-$shop2Id')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('customer-row-$shop2Id')), findsOneWidget, reason: '⛔ তালিকা চলে গেল');
    expect(find.byKey(const ValueKey('customers-detail-$shop2Id')), findsOneWidget, reason: '⛔ ডানের ঘরে গ্রাহক নেই');
    expect(find.byType(CustomerDetailScreen), findsOneWidget);
    expect(find.text('CUS-0002'), findsOneWidget, reason: 'the right pane is that customer\'s page');
    // ⓘ পথ বদলায়নি — একই পর্দায় দুই ঘর
    expect(find.byKey(const ValueKey('customers-two-pane')), findsOneWidget);
  });

  testWidgets('at 400x800 only the list shows, and a tap opens the customer\'s own page', (tester) async {
    await open(tester, const Size(400, 800));

    expect(find.byKey(const ValueKey('customers-two-pane')), findsNothing, reason: '⛔ ফোনে দুই ঘর');
    expect(find.byType(CustomerDetailScreen), findsNothing);

    // ⓘ সারিতে এখন "অ্যাকশন ▾" (৯ নভেম্বর ২০২৬) — সারি উঁচু, ৬০০dp-তে দ্বিতীয় দোকান পর্দার নিচে; মানুষ যেমন গড়িয়ে নেন
    await tester.ensureVisible(find.byKey(const ValueKey('customer-row-$shop2Id')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('customer-row-$shop2Id')));
    await tester.pumpAndSettle();

    expect(find.byType(CustomerListScreen), findsNothing, reason: 'a phone goes to the page, not a pane');
    expect(find.byType(CustomerDetailScreen), findsOneWidget);
    expect(find.text('CUS-0002'), findsOneWidget);
  });

  testWidgets('turning the tablet keeps the chosen customer', (tester) async {
    await open(tester, const Size(900, 600));
    // ⓘ সারিতে এখন "অ্যাকশন ▾" (৯ নভেম্বর ২০২৬) — সারি উঁচু, ৬০০dp-তে দ্বিতীয় দোকান পর্দার নিচে; মানুষ যেমন গড়িয়ে নেন
    await tester.ensureVisible(find.byKey(const ValueKey('customer-row-$shop2Id')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('customer-row-$shop2Id')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('customers-detail-$shop2Id')), findsOneWidget);

    // দাঁড় করানো: ৬০০ চওড়া — এক ঘর
    tester.view.physicalSize = const Size(600, 900);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('customers-two-pane')), findsNothing);
    expect(find.byKey(const ValueKey('customer-row-$shop2Id')), findsOneWidget);

    // আবার শোয়ানো — সেই গ্রাহকই ডানে
    tester.view.physicalSize = const Size(900, 600);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('customers-detail-$shop2Id')), findsOneWidget,
        reason: '⛔ ঘোরাতেই বাছা গ্রাহক হারিয়ে গেল');
    expect(find.text('CUS-0002'), findsOneWidget);
  });

  // ⭐ ওয়েবের মতো "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list"): দেখুন (দোকানের পাতা) আর খাতা খুলুন
  testWidgets('every shop row carries "Actions ▾": view opens the shop, the ledger is one tap away', (tester) async {
    await open(tester, const Size(412, 915));
    final button = find.descendant(
        of: find.ancestor(of: find.byKey(const ValueKey('customer-row-$shop2Id')), matching: find.byType(Card)),
        matching: find.byType(OutlinedButton));
    expect(button, findsOneWidget, reason: '⛔ দোকানের সারিতে "অ্যাকশন ▾" নেই');
    await tester.ensureVisible(button);
    await tester.pumpAndSettle();
    await tester.tap(button);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('row-action-ledger')), findsOneWidget, reason: '⛔ শিটে "খাতা খুলুন" নেই');
    await tester.tap(find.byKey(const ValueKey('row-action-view')));
    await tester.pumpAndSettle();
    expect(find.byType(CustomerDetailScreen), findsOneWidget, reason: '⛔ "দেখুন" দোকানের পাতা খোলেনি');
    expect(find.text('CUS-0002'), findsWidgets, reason: '⛔ অন্য দোকানের পাতা খুলেছে');
  });

  testWidgets('a phone on its side (800x400) is wide enough for two panes', (tester) async {
    await open(tester, const Size(800, 400));
    expect(find.byKey(const ValueKey('customers-two-pane')), findsOneWidget);
  });
}
