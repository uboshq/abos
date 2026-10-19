import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/features/home/module_pages.dart';

/// ⭐ মডিউলের রঙে আইকন — অন্তত ৩:১, মেপে (WCAG 2.1 1.4.11; মোবাইলের নকশা-যাচাই, ১৯ অক্টোবর ২০২৬)।
///
/// ⚠️ আগে সাদা আইকন বিক্রির রঙে ২.৫:১, ক্রয়ে ২.২:১, মজুদে ২.৪:১ ছিল — চোখে প্রায় মিলিয়ে যেত।
void main() {
  const white = Color(0xFFFFFFFF);
  final codes = [...AppColors.module.keys, 'no-such-module'];

  test('a white icon on every module tile reads at 3:1 or more', () {
    for (final code in codes) {
      final ratio = AppColors.contrast(white, AppColors.moduleSolid(code));
      expect(ratio, greaterThanOrEqualTo(3.0), reason: '⛔ $code: সাদা আইকন ${ratio.toStringAsFixed(2)}:১');
    }
  });

  test('a module icon on its light tint reads at 3:1 or more', () {
    for (final code in codes) {
      final ratio = AppColors.contrast(AppColors.moduleInk(code), AppColors.moduleTint(code));
      expect(ratio, greaterThanOrEqualTo(3.0), reason: '⛔ $code: হালকা পটে আইকন ${ratio.toStringAsFixed(2)}:১');
    }
  });

  test('a colour that already reads well is kept as it is, and the hue stays', () {
    // ⓘ হিসাবের নীল আগেই ৬:১-এর উপরে — বদলায় না
    expect(AppColors.moduleSolid('accounts'), AppColors.moduleColor('accounts'));
    final before = HSLColor.fromColor(AppColors.moduleColor('sales')).hue;
    final after = HSLColor.fromColor(AppColors.moduleSolid('sales')).hue;
    expect((before - after).abs(), lessThan(1.5), reason: 'বিক্রি সবুজই থাকে, কেবল গাঢ়');
  });

  testWidgets('the all-modules tile draws its white icon on the safe shade', (tester) async {
    final sales = NavModule(
      def: NavCatalog.moduleDef('sales')!,
      groups: const [
        NavGroup('লেনদেন', [NavRow(id: 'new-order', label: 'নতুন অর্ডার', icon: Icons.add, module: 'sales', path: 'new-order')]),
      ],
    );
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: AllModulesPage(modules: [sales], counts: const NavCounts(), onOpen: (_) {}, onRefresh: () async {}),
      ),
    ));
    await tester.pumpAndSettle();

    final fills = tester
        .widgetList<Container>(find.descendant(of: find.byKey(const ValueKey('module-sales')), matching: find.byType(Container)))
        .map((c) => (c.decoration as BoxDecoration?)?.color)
        .whereType<Color>()
        .toList();
    expect(fills, contains(AppColors.moduleSolid('sales')), reason: '⛔ টাইলের পট আগের হালকা সবুজই');
    expect(AppColors.contrast(white, fills.firstWhere((c) => c == AppColors.moduleSolid('sales'))), greaterThanOrEqualTo(3.0));
  });
}
