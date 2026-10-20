import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_palette.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';

/// ⭐ অ্যাপের পট আর লেখার রং থিম থেকে — আলোতে হুবহু আগের মতো, অন্ধকারে পড়া যায় (মোবাইলের নকশা ৩)।
void main() {
  test('in light the palette is the old colours to the pixel, so nothing the owner sees today moves', () {
    final p = AppTheme.light().extension<AppPalette>()!;
    expect(p.surface, AppColors.surface);
    expect(p.surfaceMuted, AppColors.surfaceMuted);
    expect(p.onSurface, AppColors.onSurface);
    expect(p.onSurfaceMuted, AppColors.onSurfaceMuted);
    expect(p.border, AppColors.border);
    expect(p.primarySoft, AppColors.primarySoft);
  });

  test('in dark both text colours read at 4.5:1 or more on both page colours, and the page is dark', () {
    final p = AppTheme.dark().extension<AppPalette>()!;
    for (final bg in [p.surface, p.surfaceMuted, p.primarySoft]) {
      expect(AppColors.contrast(p.onSurface, bg), greaterThanOrEqualTo(4.5));
    }
    for (final bg in [p.surface, p.surfaceMuted]) {
      expect(AppColors.contrast(p.onSurfaceMuted, bg), greaterThanOrEqualTo(4.5));
    }
    expect(p.surface.computeLuminance(), lessThan(0.1));
    expect(p.surfaceMuted.computeLuminance(), lessThan(0.1));
  });

  testWidgets('a screen reads the palette of its theme, and light where no theme is set', (tester) async {
    late AppPalette seen;
    Widget probe() => Builder(builder: (context) {
          seen = context.palette;
          return const SizedBox();
        });

    await tester.pumpWidget(MaterialApp(home: probe()));
    expect(seen.surface, AppColors.surface);

    await tester.pumpWidget(MaterialApp(theme: AppTheme.dark(), home: probe()));
    await tester.pumpAndSettle(); // ⓘ MaterialApp থিম বদলায় ধীরে (AnimatedTheme)
    expect(seen.surface, AppTheme.dark().extension<AppPalette>()!.surface);
    expect(seen.surface, isNot(AppColors.surface));
  });
}
