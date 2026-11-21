import 'package:abos_mobile/features/copilot/copilot_mark.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিকের চলন্ত "Ai" চিহ্ন (২১ নভেম্বর ২০২৬) — আঁকা হয়, চলে, "কম নড়াচড়া"-য় থামে, ব্যস্ত হলে দ্বিগুণ জোরে।
void main() {
  setUp(() => CopilotMark.animateInTests = true);
  tearDown(() => CopilotMark.animateInTests = false);

  CopilotMarkPainter painterOf(WidgetTester tester) => tester
      .widgetList<CustomPaint>(find.descendant(of: find.byType(CopilotMark), matching: find.byType(CustomPaint)))
      .map((c) => c.painter)
      .whereType<CopilotMarkPainter>()
      .single;

  Widget host(Widget mark, {bool disableAnimations = false, bool accessibleNavigation = false}) => MediaQuery(
        data: MediaQueryData(disableAnimations: disableAnimations, accessibleNavigation: accessibleNavigation),
        child: Directionality(
          textDirection: TextDirection.ltr,
          child: Center(child: RepaintBoundary(key: const ValueKey('shot'), child: SizedBox.square(dimension: 120, child: Center(child: mark)))),
        ),
      );

  testWidgets('the mark renders at its size, behind its own RepaintBoundary, silent to TalkBack', (tester) async {
    await tester.pumpWidget(host(const CopilotMark(size: 40)));
    expect(tester.getSize(find.byType(CopilotMark)), const Size(40, 40));
    expect(find.descendant(of: find.byType(CopilotMark), matching: find.byType(RepaintBoundary)), findsOneWidget);
    expect(find.descendant(of: find.byType(CopilotMark), matching: find.byType(ExcludeSemantics)), findsOneWidget);
    expect(painterOf(tester).storySeconds, 0);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the animation advances on its 8-second story', (tester) async {
    await tester.pumpWidget(host(const CopilotMark(size: 40)));
    await tester.pump(const Duration(milliseconds: 1500));
    expect(painterOf(tester).storySeconds, closeTo(1.5, 0.01));
    await tester.pump(const Duration(milliseconds: 2500));
    expect(painterOf(tester).storySeconds, closeTo(4.0, 0.01));
    expect(tester.hasRunningAnimations, isTrue);
  });

  testWidgets('it stays static when animations are disabled or accessible navigation is on', (tester) async {
    await tester.pumpWidget(host(const CopilotMark(size: 40), disableAnimations: true));
    await tester.pump(const Duration(seconds: 3));
    expect(painterOf(tester).storySeconds, isNull, reason: 'কম নড়াচড়া — স্থির, পুরো আঁকা');
    expect(tester.hasRunningAnimations, isFalse);

    await tester.pumpWidget(host(const CopilotMark(size: 40), accessibleNavigation: true));
    await tester.pump(const Duration(seconds: 3));
    expect(painterOf(tester).storySeconds, isNull);
    expect(tester.hasRunningAnimations, isFalse);
  });

  testWidgets('offscreen (TickerMode off) the clock stops', (tester) async {
    await tester.pumpWidget(TickerMode(enabled: false, child: host(const CopilotMark(size: 40))));
    await tester.pump(const Duration(seconds: 2));
    expect(painterOf(tester).storySeconds, 0);
  });

  testWidgets('busy runs twice as fast, and switching keeps its place', (tester) async {
    await tester.pumpWidget(host(const CopilotMark(size: 40, busy: true)));
    await tester.pump(const Duration(milliseconds: 1000));
    expect(painterOf(tester).storySeconds, closeTo(2.0, 0.01), reason: '১ সেকেন্ডে গল্পের ২ সেকেন্ড');

    await tester.pumpWidget(host(const CopilotMark(size: 40)));
    await tester.pump(const Duration(milliseconds: 1000));
    expect(painterOf(tester).storySeconds, closeTo(3.0, 0.02), reason: 'আবার স্বাভাবিক গতি, যেখানে ছিল সেখান থেকে');
  });

  testWidgets('golden — the mark at rest', (tester) async {
    await tester.pumpWidget(host(const CopilotMark(size: 96), disableAnimations: true));
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/copilot/ai_mark_rest.png'));
  });

  testWidgets('golden — the mark mid-animation (4.4 s: the light runs round the border)', (tester) async {
    await tester.pumpWidget(host(const CopilotMark(size: 96)));
    await tester.pump(const Duration(milliseconds: 4400));
    expect(painterOf(tester).storySeconds, closeTo(4.4, 0.01));
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/copilot/ai_mark_mid.png'));
  });
}
