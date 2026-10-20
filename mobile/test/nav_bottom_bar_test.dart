import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'navigation/bar_harness.dart';

/// ⭐ নিচের বার পর্দার নিচেও — যেকোনো পর্দা থেকে এক চাপে হোম বা সব মডিউল (মালিক, ১২ অক্টোবর ২০২৬; বারের নকশা ৩,
/// ২০ অক্টোবর ২০২৬)। পুরো নিয়ম আর মিউট্যান্ট: `test/navigation/`।
void main() {
  Future<GoRouter> pump(WidgetTester tester) async {
    await setScreen(tester, const Size(360, 800));
    final router = barRouter();
    await tester.pumpWidget(barApp(router, role: 'sr'));
    await tester.pumpAndSettle();
    return router;
  }

  testWidgets('a screen keeps its own back arrow and gets the bar under it', (tester) async {
    final router = await pump(tester);
    router.push('/home/customers');
    await tester.pumpAndSettle();
    expect(find.text('CUSTOMERS'), findsOneWidget);
    expect(find.byKey(const ValueKey('nav-bar')), findsOneWidget);
    expect(find.byType(BackButton), findsOneWidget, reason: 'the screen sits on the home, so its arrow still goes up');
  });

  testWidgets('from a screen, one tap reaches the home or all modules', (tester) async {
    final router = await pump(tester);
    router.push('/home/customers');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('nav-modules')));
    await tester.pumpAndSettle();
    expect(find.text('HOME modules'), findsOneWidget);

    // ⓘ হোম ঘর নিজের ইতিহাসে ফেরে (গ্রাহকের পাতা), আবার চাপলে হোমের প্রথম পাতা (বারের নকশা ৩, অংশ ৭)
    await tester.tap(find.byKey(const ValueKey('nav-home')));
    await tester.pumpAndSettle();
    expect(find.text('CUSTOMERS'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('nav-home')));
    await tester.pumpAndSettle();
    expect(find.text('HOME home'), findsOneWidget);
  });

  testWidgets('Android back on a screen goes one level up, to where it was opened from', (tester) async {
    final router = await pump(tester);
    router.push('/home/customers');
    await tester.pumpAndSettle();
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.text('CUSTOMERS'), findsNothing);
    expect(find.text('HOME home'), findsOneWidget);
  });
}
