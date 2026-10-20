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

  test('the state colours are the old ones in light, and in dark each reads on the page, the card and its own tint', () {
    final l = AppTheme.light().extension<AppPalette>()!;
    expect([l.success, l.successSurface, l.danger, l.dangerSurface, l.warning, l.warningSurface, l.pending, l.pendingSurface], [
      AppColors.success, AppColors.successSurface, AppColors.danger, AppColors.dangerSurface,
      AppColors.warning, AppColors.warningSurface, AppColors.pending, AppColors.pendingSurface,
    ]);
    final d = AppTheme.dark().extension<AppPalette>()!;
    for (final (ink, tint) in [(d.success, d.successSurface), (d.danger, d.dangerSurface), (d.warning, d.warningSurface), (d.pending, d.pendingSurface)]) {
      expect(tint.computeLuminance(), lessThan(0.1), reason: 'অন্ধকারে ছোঁয়াও অন্ধকার');
      for (final bg in [d.surface, d.surfaceMuted, tint]) {
        expect(AppColors.contrast(ink, bg), greaterThanOrEqualTo(4.5));
      }
      expect(AppColors.contrast(d.onSurface, tint), greaterThanOrEqualTo(4.5), reason: 'ছোঁয়ার উপর থিমের সাধারণ লেখা');
    }
  });
}
