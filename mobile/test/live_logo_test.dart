import 'dart:math' as math;

import 'package:abos_mobile/core/widgets/live_logo.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ জীবন্ত লোগো (মালিক, ১ নভেম্বর ২০২৬: "App eo jibonto live logo dibe") — দশটা নড়াচড়া এলোমেলো ক্রমে,
/// বিরতি ছাড়া, একই নড়াচড়া পরপর দুবার নয়; ফোনে "নড়াচড়া কমান" চালু থাকলে স্থির।
void main() {
  test('দশটাই আসে, আর একই নড়াচড়া পরপর দুবার কখনো নয়', () {
    expect(LiveMove.values.length, 10, reason: '⛔ নড়াচড়া দশটা নয়');
    final random = math.Random(7);
    final seen = <LiveMove>{};
    LiveMove? previous;
    for (var i = 0; i < 400; i++) {
      final move = nextMove(previous, random);
      expect(move, isNot(previous), reason: '⛔ একই নড়াচড়া পরপর দুবার — লোগো থেমে আছে মনে হয়');
      seen.add(move);
      previous = move;
    }
    expect(seen.length, 10, reason: '⛔ দশটার কয়েকটা কখনো আসে না');
  });

  testWidgets('নড়াচড়া শেষ হলেই পরেরটা শুরু — মাঝে থামা নেই', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: Center(child: LiveLogo(animate: true))));
    expect(tester.hasRunningAnimations, isTrue, reason: '⛔ লোগো নড়ছে না');
    await tester.pump(LiveLogo.moveDuration * 3 + const Duration(milliseconds: 50));
    expect(tester.hasRunningAnimations, isTrue, reason: '⛔ তিনটা নড়াচড়ার পর লোগো থেমে গেছে — বিরতি এসেছে');
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('ফোনে "নড়াচড়া কমান" চালু থাকলে লোগো স্থির', (tester) async {
    await tester.pumpWidget(const MediaQuery(
      data: MediaQueryData(disableAnimations: true),
      child: MaterialApp(home: Center(child: LiveLogo(animate: true))),
    ));
    expect(tester.hasRunningAnimations, isFalse, reason: '⛔ "নড়াচড়া কমান" মানা হয় না');
  });
}
