import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/customers/customer_list_screen.dart';
import 'package:abos_mobile/features/products/product_list_screen.dart';
import 'package:abos_mobile/features/stock/stock_list_screen.dart';

/// A real bug, found only by running the app on a device, not by
/// `flutter analyze` or any of this suite's other tests: on first launch —
/// nothing synced yet — each of these three screens rendered its "নিচে টেনে
/// আবার চেষ্টা করুন" ("pull down to try again") message as a bare
/// [EmptyState], with no [RefreshIndicator] wrapping it. A [RefreshIndicator]
/// only recognises a pull gesture on a scrollable descendant, so the one
/// instruction on screen did nothing when followed.
///
/// <p>This test checks the structural fix directly — an [EmptyState] must
/// have a [RefreshIndicator] as an ancestor — rather than driving an actual
/// drag gesture and a real network call, which would need mocking
/// [ReferenceCache]/[ReferenceSync]'s underlying platform channels the same
/// way `sync_engine_queue_test.dart` does. The structural check is enough to
/// catch a regression of this exact bug: an [EmptyState] rendered outside any
/// [RefreshIndicator] again.
void main() {
  testWidgets(
      'CustomerListScreen: the empty-catalogue message can be pulled to refresh',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(home: CustomerListScreen()));
    await tester.pump();

    expect(find.byType(EmptyState), findsOneWidget);
    expect(
      find.ancestor(
        of: find.byType(EmptyState),
        matching: find.byType(RefreshIndicator),
      ),
      findsOneWidget,
      reason: 'a RefreshIndicator only recognises a pull gesture on a '
          'scrollable descendant — an EmptyState outside of one makes the '
          'on-screen "pull down" instruction do nothing',
    );
  });

  testWidgets(
      'ProductListScreen: the empty-catalogue message can be pulled to refresh',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(home: ProductListScreen()));
    await tester.pump();

    expect(
      find.ancestor(
        of: find.byType(EmptyState),
        matching: find.byType(RefreshIndicator),
      ),
      findsOneWidget,
    );
  });

  testWidgets(
      'StockListScreen: the empty-catalogue message can be pulled to refresh',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(home: StockListScreen()));
    await tester.pump();

    expect(
      find.ancestor(
        of: find.byType(EmptyState),
        matching: find.byType(RefreshIndicator),
      ),
      findsOneWidget,
    );
  });
}
