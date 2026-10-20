import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/features/money/money_screens.dart';

/// ⭐ ক্রয়ের বাজেট আলাদা কার্ডে (cb/fe, ২০ অক্টোবর ২০২৬): মজুদ (১১২০) খাতে কেনার বাজেট আর এ মাসে কত কেনা হলো — খরচ নয়,
/// তাই খরচের বাজেটের কার্ডে মেশে না। নতুন সার্ভার: CFO-র `purchase_budget`, বাজেট পাতার `purchase`; পুরনো সার্ভারে নেই।
class _Api implements MoneyApi {
  _Api(this.data);
  final Map<String, dynamic> data;

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, Object?>? query}) async => data;

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

const _expense = {'budget': '50,000.00', 'actual': '12,000.00', 'used_pct': 24};
const _purchase = {'budget': '5,000.00', 'purchased': '600.50', 'used_pct': 12};

Future<void> _pump(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('the CFO page shows the purchase budget on its own card, apart from the expense budget', (tester) async {
    await _pump(tester, CfoScreen(api: _Api({'budget': _expense, 'purchase_budget': _purchase})));
    expect(find.text('এই মাসের খরচের বাজেট'), findsOneWidget);
    expect(find.text('ক্রয়ের বাজেট'), findsOneWidget);
    final card = find.byKey(const ValueKey('purchase-budget'));
    expect(find.descendant(of: card, matching: find.text('কেনা হয়েছে')), findsOneWidget);
    expect(find.descendant(of: card, matching: find.text('600.50')), findsOneWidget);
    expect(find.descendant(of: card, matching: find.text('5,000.00')), findsOneWidget);
    expect(find.descendant(of: card, matching: find.text('12,000.00')), findsNothing, reason: '⛔ খরচ ক্রয়ের কার্ডে নয়');
  });

  testWidgets('no purchase budget (null) or an old server: no purchase card, the old title', (tester) async {
    await _pump(tester, CfoScreen(api: _Api({'budget': _expense, 'purchase_budget': null})));
    expect(find.byKey(const ValueKey('purchase-budget')), findsNothing);
    expect(find.text('এই মাসের খরচের বাজেট'), findsOneWidget, reason: 'নতুন সার্ভার — budget কেবল খরচের');

    await tester.pumpWidget(const SizedBox());
    await _pump(tester, CfoScreen(api: _Api({'budget': _expense})));
    expect(find.byKey(const ValueKey('purchase-budget')), findsNothing);
    expect(find.text('এই মাসের বাজেট'), findsOneWidget, reason: 'পুরনো সার্ভার — নাম আগের মতো');
  });

  testWidgets('the budget page shows both, and the purchase card even with no expense budget', (tester) async {
    await _pump(tester, BudgetScreen(api: _Api({'status': _expense, 'purchase': _purchase})));
    expect(find.text('এই মাসের খরচের বাজেট'), findsOneWidget);
    expect(find.byKey(const ValueKey('purchase-budget')), findsOneWidget);

    await tester.pumpWidget(const SizedBox());
    await _pump(tester, BudgetScreen(api: _Api({'status': null, 'purchase': _purchase})));
    expect(find.byKey(const ValueKey('purchase-budget')), findsOneWidget);
    expect(find.text('এই মাসের কোনো বাজেট নেই'), findsNothing);

    await tester.pumpWidget(const SizedBox());
    await _pump(tester, BudgetScreen(api: _Api({'status': null})));
    expect(find.text('এই মাসের কোনো বাজেট নেই'), findsOneWidget);
  });
}
