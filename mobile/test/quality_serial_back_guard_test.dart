import 'package:abos_mobile/core/records/product_record.dart';
import 'package:abos_mobile/features/stock_writes/quality_serial_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মান যাচাই আর সিরিয়ালের ফর্মে লেখা অবস্থায় "পিছনে" জিজ্ঞেস করে; খালি ফর্মে নয় (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬;
/// [[UnsavedGuard]])।
class _NoServer implements QualitySerialApi {
  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError('${invocation.memberName}');
}

const _biscuit = ProductRecord({'id': 'pr-1', 'code': 'P-1', 'nameBn': 'কসমস বিস্কুট', 'isActive': true});

void main() {
  Future<void> backAndSettle(WidgetTester tester) async {
    await tester.binding.handlePopRoute();
    await tester.pumpAndSettle();
  }

  testWidgets('a new inspection with a product chosen asks before going back, an empty one does not', (tester) async {
    await tester.pumpWidget(MaterialApp(home: NewInspectionScreen(api: _NoServer(), products: const [_biscuit])));
    await tester.pumpAndSettle();
    await backAndSettle(tester);
    expect(find.text('লেখা ফেলে চলে যাবেন?'), findsNothing, reason: 'খালি ফর্মে প্রশ্ন নয়');

    await tester.tap(find.byKey(const ValueKey('qc-product')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('stock-product-pr-1')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('qc-qty')), '10');
    await tester.pump();
    await backAndSettle(tester);
    expect(find.text('লেখা ফেলে চলে যাবেন?'), findsOneWidget, reason: '⛔ যাচাই লেখা, তবু "পিছনে" কিছু না বলে ফর্ম ফেলে দিল');
  });
}
