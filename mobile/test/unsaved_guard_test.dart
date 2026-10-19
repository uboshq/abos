import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/widgets/unsaved_guard.dart';

/// ⭐ লেখা অবস্থায় "পিছনে" জিজ্ঞেস করে — মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬ ([[UnsavedGuard]])।
void main() {
  Widget app(bool dirty) => MaterialApp(
        home: Builder(
          builder: (context) => Scaffold(
            body: Center(
              child: FilledButton(
                key: const ValueKey('open'),
                onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(
                  builder: (_) => UnsavedGuard(
                    dirty: dirty,
                    child: Scaffold(appBar: AppBar(title: const Text('নতুন অর্ডার')), body: const Text('ফর্ম')),
                  ),
                )),
                child: const Text('খুলুন'),
              ),
            ),
          ),
        ),
      );

  Future<void> openAndGoBack(WidgetTester tester, bool dirty) async {
    await tester.pumpWidget(app(dirty));
    await tester.tap(find.byKey(const ValueKey('open')));
    await tester.pumpAndSettle();
    expect(find.text('ফর্ম'), findsOneWidget);
    // ⓘ ফোনের "পিছনে" — maybePop, যা পাহারা মানে
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
  }

  testWidgets('an empty form goes back without asking', (tester) async {
    await openAndGoBack(tester, false);
    expect(find.byKey(const ValueKey('unsaved-guard')), findsNothing);
    expect(find.text('ফর্ম'), findsNothing);
  });

  testWidgets('a form with writing asks, and "থাকুন" keeps it', (tester) async {
    await openAndGoBack(tester, true);
    expect(find.text('লেখা ফেলে চলে যাবেন?'), findsOneWidget, reason: '⛔ লেখা অবস্থায় কিছু না বলে ফর্ম বন্ধ হলো');
    await tester.tap(find.byKey(const ValueKey('unsaved-stay')));
    await tester.pumpAndSettle();
    expect(find.text('ফর্ম'), findsOneWidget, reason: '"থাকুন" চাপার পরেও ফর্ম বন্ধ হলো');
  });

  testWidgets('a form with writing asks, and "ফেলে যান" leaves', (tester) async {
    await openAndGoBack(tester, true);
    await tester.tap(find.byKey(const ValueKey('unsaved-leave')));
    await tester.pumpAndSettle();
    expect(find.text('ফর্ম'), findsNothing);
    expect(find.text('খুলুন'), findsOneWidget);
  });
}
