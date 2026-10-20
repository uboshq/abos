import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/unsaved_guard.dart';

/// ⭐ predictive back (Android 14+; মোবাইলের নকশা ৩, মালিকের "হ্যাঁ", ১৯ অক্টোবর ২০২৬) — আর না-রাখা ফর্মের পাহারা তাতেও থামায়।
void main() {
  test('both themes slide pages with the predictive back gesture on Android', () {
    for (final theme in [AppTheme.light(), AppTheme.dark()]) {
      expect(theme.pageTransitionsTheme.builders[TargetPlatform.android], isA<PredictiveBackPageTransitionsBuilder>());
    }
  });

  test('the manifest opts in to the system back callback', () {
    final manifest = File('android/app/src/main/AndroidManifest.xml').readAsStringSync();
    expect(manifest, contains('android:enableOnBackInvokedCallback="true"'));
  });

  testWidgets('a system back on a form with unsaved changes still asks first', (tester) async {
    final nav = GlobalKey<NavigatorState>();
    await tester.pumpWidget(MaterialApp(
      navigatorKey: nav,
      theme: AppTheme.light(),
      home: const Scaffold(body: Text('তালিকা')),
    ));
    nav.currentState!.push(MaterialPageRoute<void>(
      builder: (_) => const UnsavedGuard(dirty: true, child: Scaffold(body: Text('নতুন অর্ডার'))),
    ));
    await tester.pumpAndSettle();

    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('unsaved-stay')), findsOneWidget, reason: 'জিজ্ঞেস করে');
    await tester.tap(find.byKey(const ValueKey('unsaved-stay')));
    await tester.pumpAndSettle();
    expect(find.text('নতুন অর্ডার'), findsOneWidget, reason: 'থাকলে লেখা হারায় না');
  });
}
