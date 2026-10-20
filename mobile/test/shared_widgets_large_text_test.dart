import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';
import 'package:abos_mobile/core/widgets/totals_bar.dart';

/// ⭐ বড় অক্ষর (২০০%, Android-এর সবচেয়ে বড়) — দলের সাধারণ widget কোথাও কাটা পড়ে না বা উপচে পড়ে না, সবচেয়ে ছোট
/// ফোনেও (৩২০dp; WCAG 2.1 1.4.4; মোবাইলের নকশা ৩)।
void main() {
  Future<void> pumpAt(WidgetTester tester, Widget child, {double scale = 2.0, double width = 320}) async {
    tester.view.physicalSize = Size(width * 3, 2400);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(MaterialApp(
      theme: AppTheme.light(),
      home: MediaQuery(
        data: MediaQueryData(size: Size(width, 800), textScaler: TextScaler.linear(scale)),
        child: Scaffold(body: SingleChildScrollView(child: child)),
      ),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('the totals bar keeps the grand total whole at 200% on a 320dp phone', (tester) async {
    await pumpAt(tester, const TotalsBar(pageCount: 25, pageSum: 98765432.10, allCount: 1250, allSum: 1234567890.55));
    expect(tester.takeException(), isNull, reason: '⛔ উপচে পড়েছে');
    expect(find.text('৳1,23,45,67,890.55'), findsOneWidget);
    final money = tester.renderObject<RenderParagraph>(find.text('৳1,23,45,67,890.55'));
    expect(money.didExceedMaxLines, isFalse, reason: '⛔ সর্বমোট কাটা পড়েছে');
  });

  testWidgets('the error and empty states wrap their words and keep the 48dp retry at 200%', (tester) async {
    await pumpAt(tester, Column(children: [
      ErrorState(message: 'সার্ভারের সাথে যোগাযোগ হলো না। নেট আছে কিনা দেখে আবার চেষ্টা করুন।', onRetry: () {}),
      EmptyState(
        icon: Icons.inbox_outlined,
        title: 'এই মাসে কোনো আদায় নেই',
        message: 'নতুন আদায় লিখলে এখানে দেখাবে',
        action: FilledButton(onPressed: () {}, child: const Text('নতুন আদায়')),
      ),
    ]));
    expect(tester.takeException(), isNull, reason: '⛔ উপচে পড়েছে');
    expect(tester.getSize(find.byKey(const ValueKey('error-retry'))).height, greaterThanOrEqualTo(48));
  });

  testWidgets('the skeleton has no text, so it holds its shape at any size', (tester) async {
    await pumpAt(tester, const LoadingSkeleton(rows: 3), scale: 3.0);
    expect(tester.takeException(), isNull);
  });
}
