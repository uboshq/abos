import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/widgets/row_icon.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';

/// ⭐ a5-এর দুই অনুরোধ (২০ অক্টোবর ২০২৬): অন্ধকারে সারির আইকনের পট অন্ধকার, আর ভুলের পর্দায় আলাদা শিরোনাম।
void main() {
  test('in dark a row icon sits on a dark tint and still reads at 3:1, by module or by tone', () {
    final kinds = [
      for (final code in [...AppColors.module.keys, 'no-such-module']) RowIcon(Icons.receipt_long, module: code),
      for (final tone in [AppColors.success, AppColors.warning, const Color(0xFF475569), const Color(0xFF10B981)])
        RowIcon(Icons.call_received, tone: tone),
    ];
    for (final icon in kinds) {
      final (bg, ink) = icon.coloursOn(Brightness.dark);
      expect(bg.computeLuminance(), lessThan(0.1), reason: '${icon.module ?? icon.tone}: পট অন্ধকার');
      expect(AppColors.contrast(ink, bg), greaterThanOrEqualTo(3.0), reason: '${icon.module ?? icon.tone}');
    }
  });

  testWidgets('the row icon follows the theme it is drawn in', (tester) async {
    Future<Color> fillIn(ThemeData theme) async {
      await tester.pumpWidget(MaterialApp(theme: theme, home: const Scaffold(body: RowIcon(Icons.add, module: 'sales'))));
      await tester.pumpAndSettle();
      return (tester.widget<Container>(find.byType(Container)).decoration! as BoxDecoration).color!;
    }

    expect(await fillIn(AppTheme.light()), AppColors.moduleTint('sales'), reason: 'আলোতে আগের মতো হুবহু');
    expect((await fillIn(AppTheme.dark())).computeLuminance(), lessThan(0.1));
  });

  testWidgets('an error can carry a bold title above its detail', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(body: ErrorState(title: 'তথ্য আনা গেল না', message: 'নেট আছে কিনা দেখে আবার চেষ্টা করুন', onRetry: () {})),
    ));
    final title = tester.widget<Text>(find.text('তথ্য আনা গেল না'));
    expect(title.style!.fontWeight, FontWeight.w700);
    expect(find.text('নেট আছে কিনা দেখে আবার চেষ্টা করুন'), findsOneWidget);
    expect(tester.getTopLeft(find.text('তথ্য আনা গেল না')).dy,
        lessThan(tester.getTopLeft(find.text('নেট আছে কিনা দেখে আবার চেষ্টা করুন')).dy));
  });
}
