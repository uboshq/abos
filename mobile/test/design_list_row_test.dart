import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/widgets/design_list_row.dart';

/// ⭐ নকশার তালিকার সারি — অর্ডার, DO আর ট্র্যাকিংয়ের জন্য তিনটা ঐচ্ছিক ঘর (a5-এর অনুরোধ, ২০ অক্টোবর ২০২৬)।
void main() {
  Future<void> pump(WidgetTester tester, Widget row) async {
    await tester.pumpWidget(MaterialApp(home: Scaffold(body: ListView(children: [row]))));
    await tester.pumpAndSettle();
  }

  testWidgets('a row without money shows only its chip on the right, no ৳', (tester) async {
    await pump(tester, const DesignListRow(
      icon: Icons.local_shipping_outlined,
      name: 'Muskan Enterprise · মুক্তাগাছা · SL-Super Group',
      second: 'CHA-0141 · পথে',
      pill: Text('পথে আছে', key: ValueKey('chip')),
    ));
    expect(find.textContaining('৳'), findsNothing);
    expect(find.byKey(const ValueKey('chip')), findsOneWidget);
  });

  testWidgets('a line below the second line sits under it, and the second line can be found by its key', (tester) async {
    await pump(tester, const DesignListRow(
      icon: Icons.receipt_long,
      name: 'Nava Enterprise · ত্রিশাল · SL-Lion Group',
      second: 'DO-2610-0012 · ২০ অক্টো',
      secondKey: ValueKey('do-second'),
      amount: 125000,
      below: Text('ব্যাক অর্ডার', key: ValueKey('do-chips')),
    ));
    expect(tester.widget<Text>(find.byKey(const ValueKey('do-second'))).data, 'DO-2610-0012 · ২০ অক্টো');
    expect(tester.getTopLeft(find.byKey(const ValueKey('do-chips'))).dy,
        greaterThan(tester.getTopLeft(find.byKey(const ValueKey('do-second'))).dy));
    expect(find.text('৳1,25,000.00'), findsOneWidget);
    expect(tester.getSize(find.byType(DesignListRow)).height, greaterThanOrEqualTo(72));
  });
}
