import 'package:flutter/material.dart';

import 'app_colors.dart';

/// ⭐ অ্যাপের নিজের পট, লেখা আর রেখার রং — আলোতে হুবহু আগের [AppColors], অন্ধকারে Material 3-এর অন্ধকার রং-পরিবার থেকে
/// (মোবাইলের নকশা ৩; মালিকের "হ্যাঁ", ১৯ অক্টোবর ২০২৬)।
///
/// <p>পর্দায় `AppColors.surface` বা `AppColors.onSurfaceMuted`-এর জায়গায় `context.palette.surface` বা
/// `context.palette.onSurfaceMuted` — আলোর চেহারা এক বিন্দুও বদলায় না, অন্ধকারে ঠিক রং পায়। থিম না থাকলে (যেমন টেস্টে
/// শুধু `MaterialApp`) আলোর রং।
@immutable
class AppPalette extends ThemeExtension<AppPalette> {
  const AppPalette({
    required this.surface,
    required this.surfaceMuted,
    required this.onSurface,
    required this.onSurfaceMuted,
    required this.border,
    required this.primarySoft,
  });

  final Color surface;
  final Color surfaceMuted;
  final Color onSurface;
  final Color onSurfaceMuted;
  final Color border;
  final Color primarySoft;

  static const AppPalette light = AppPalette(
    surface: AppColors.surface,
    surfaceMuted: AppColors.surfaceMuted,
    onSurface: AppColors.onSurface,
    onSurfaceMuted: AppColors.onSurfaceMuted,
    border: AppColors.border,
    primarySoft: AppColors.primarySoft,
  );

  static AppPalette darkFrom(ColorScheme cs) => AppPalette(
        surface: cs.surfaceContainerLow,
        surfaceMuted: cs.surface,
        onSurface: cs.onSurface,
        onSurfaceMuted: cs.onSurfaceVariant,
        border: cs.outlineVariant,
        primarySoft: cs.primaryContainer,
      );

  @override
  AppPalette copyWith({
    Color? surface,
    Color? surfaceMuted,
    Color? onSurface,
    Color? onSurfaceMuted,
    Color? border,
    Color? primarySoft,
  }) =>
      AppPalette(
        surface: surface ?? this.surface,
        surfaceMuted: surfaceMuted ?? this.surfaceMuted,
        onSurface: onSurface ?? this.onSurface,
        onSurfaceMuted: onSurfaceMuted ?? this.onSurfaceMuted,
        border: border ?? this.border,
        primarySoft: primarySoft ?? this.primarySoft,
      );

  @override
  AppPalette lerp(AppPalette? other, double t) {
    if (other == null) return this;
    return AppPalette(
      surface: Color.lerp(surface, other.surface, t)!,
      surfaceMuted: Color.lerp(surfaceMuted, other.surfaceMuted, t)!,
      onSurface: Color.lerp(onSurface, other.onSurface, t)!,
      onSurfaceMuted: Color.lerp(onSurfaceMuted, other.onSurfaceMuted, t)!,
      border: Color.lerp(border, other.border, t)!,
      primarySoft: Color.lerp(primarySoft, other.primarySoft, t)!,
    );
  }
}

extension AppPaletteOf on BuildContext {
  /// এই পর্দার থিমের রং — থিমে না থাকলে আলোর রং
  AppPalette get palette => Theme.of(this).extension<AppPalette>() ?? AppPalette.light;
}
