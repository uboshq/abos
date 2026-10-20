import 'package:flutter/material.dart';

import 'app_colors.dart';

/// ⭐ অ্যাপের নিজের পট, লেখা, রেখা আর অবস্থার রং — আলোতে হুবহু আগের [AppColors], অন্ধকারে Material 3-এর অন্ধকার রং-পরিবার
/// থেকে (মোবাইলের নকশা ৩; মালিকের "হ্যাঁ", ১৯ অক্টোবর ২০২৬)।
///
/// <p>পর্দায় `AppColors.surface` বা `AppColors.successSurface`-এর জায়গায় `context.palette.surface` বা
/// `context.palette.successSurface` — আলোর চেহারা এক বিন্দুও বদলায় না, অন্ধকারে ঠিক রং পায়। থিম না থাকলে (যেমন টেস্টে
/// শুধু `MaterialApp`) আলোর রং।
///
/// <p>⭐ অবস্থার রং (সবুজ পাকা, লাল বাকি, হলুদ অপেক্ষা, ধূসর খসড়া; ২০ অক্টোবর ২০২৬): অন্ধকারে হালকা পট থাকলে থিমের হালকা
/// লেখা তাতে মিলিয়ে যেত, আর গাঢ় লাল লেখা অন্ধকার পটে — তাই অন্ধকারে পট সেই রঙের অন্ধকার ছোঁয়া, লেখা সেই রঙেরই হালকা সুর,
/// পটে আর নিজের ছোঁয়ায় দুটোতেই অন্তত ৪.৫:১।
@immutable
class AppPalette extends ThemeExtension<AppPalette> {
  const AppPalette({
    required this.surface,
    required this.surfaceMuted,
    required this.onSurface,
    required this.onSurfaceMuted,
    required this.border,
    required this.primarySoft,
    required this.success,
    required this.successSurface,
    required this.danger,
    required this.dangerSurface,
    required this.warning,
    required this.warningSurface,
    required this.pending,
    required this.pendingSurface,
  });

  final Color surface;
  final Color surfaceMuted;
  final Color onSurface;
  final Color onSurfaceMuted;
  final Color border;
  final Color primarySoft;
  final Color success;
  final Color successSurface;
  final Color danger;
  final Color dangerSurface;
  final Color warning;
  final Color warningSurface;
  final Color pending;
  final Color pendingSurface;

  static const AppPalette light = AppPalette(
    surface: AppColors.surface,
    surfaceMuted: AppColors.surfaceMuted,
    onSurface: AppColors.onSurface,
    onSurfaceMuted: AppColors.onSurfaceMuted,
    border: AppColors.border,
    primarySoft: AppColors.primarySoft,
    success: AppColors.success,
    successSurface: AppColors.successSurface,
    danger: AppColors.danger,
    dangerSurface: AppColors.dangerSurface,
    warning: AppColors.warning,
    warningSurface: AppColors.warningSurface,
    pending: AppColors.pending,
    pendingSurface: AppColors.pendingSurface,
  );

  static AppPalette darkFrom(ColorScheme cs) {
    final card = cs.surfaceContainerLow;
    final page = cs.surface;
    Color tint(Color c) => Color.alphaBlend(c.withValues(alpha: 0.22), card);
    // লেখা: সেই রঙ আলো বাড়িয়ে — পাতায়, কার্ডে আর নিজের ছোঁয়ায়, তিনটাতেই ৪.৫:১
    Color ink(Color c) => lighten(c, [page, card, tint(c)], 4.5);
    return AppPalette(
      surface: card,
      surfaceMuted: page,
      onSurface: cs.onSurface,
      onSurfaceMuted: cs.onSurfaceVariant,
      border: cs.outlineVariant,
      primarySoft: cs.primaryContainer,
      success: ink(AppColors.success),
      successSurface: tint(AppColors.success),
      danger: ink(AppColors.danger),
      dangerSurface: tint(AppColors.danger),
      warning: ink(AppColors.warning),
      warningSurface: tint(AppColors.warning),
      pending: ink(AppColors.pending),
      pendingSurface: tint(AppColors.pending),
    );
  }

  /// রঙের আলো একটু একটু বাড়ায়, সব পটের সাথে [target]:১ না হওয়া পর্যন্ত; রঙের ধরন (hue) একই থাকে
  static Color lighten(Color c, List<Color> backgrounds, double target) {
    var hsl = HSLColor.fromColor(c);
    bool reads() => backgrounds.every((b) => AppColors.contrast(hsl.toColor(), b) >= target);
    while (!reads() && hsl.lightness < 0.98) {
      hsl = hsl.withLightness((hsl.lightness + 0.01).clamp(0.0, 1.0));
    }
    return hsl.toColor();
  }

  @override
  AppPalette copyWith({
    Color? surface,
    Color? surfaceMuted,
    Color? onSurface,
    Color? onSurfaceMuted,
    Color? border,
    Color? primarySoft,
    Color? success,
    Color? successSurface,
    Color? danger,
    Color? dangerSurface,
    Color? warning,
    Color? warningSurface,
    Color? pending,
    Color? pendingSurface,
  }) =>
      AppPalette(
        surface: surface ?? this.surface,
        surfaceMuted: surfaceMuted ?? this.surfaceMuted,
        onSurface: onSurface ?? this.onSurface,
        onSurfaceMuted: onSurfaceMuted ?? this.onSurfaceMuted,
        border: border ?? this.border,
        primarySoft: primarySoft ?? this.primarySoft,
        success: success ?? this.success,
        successSurface: successSurface ?? this.successSurface,
        danger: danger ?? this.danger,
        dangerSurface: dangerSurface ?? this.dangerSurface,
        warning: warning ?? this.warning,
        warningSurface: warningSurface ?? this.warningSurface,
        pending: pending ?? this.pending,
        pendingSurface: pendingSurface ?? this.pendingSurface,
      );

  @override
  AppPalette lerp(AppPalette? other, double t) {
    if (other == null) return this;
    Color l(Color a, Color b) => Color.lerp(a, b, t)!;
    return AppPalette(
      surface: l(surface, other.surface),
      surfaceMuted: l(surfaceMuted, other.surfaceMuted),
      onSurface: l(onSurface, other.onSurface),
      onSurfaceMuted: l(onSurfaceMuted, other.onSurfaceMuted),
      border: l(border, other.border),
      primarySoft: l(primarySoft, other.primarySoft),
      success: l(success, other.success),
      successSurface: l(successSurface, other.successSurface),
      danger: l(danger, other.danger),
      dangerSurface: l(dangerSurface, other.dangerSurface),
      warning: l(warning, other.warning),
      warningSurface: l(warningSurface, other.warningSurface),
      pending: l(pending, other.pending),
      pendingSurface: l(pendingSurface, other.pendingSurface),
    );
  }
}

extension AppPaletteOf on BuildContext {
  /// এই পর্দার থিমের রং — থিমে না থাকলে আলোর রং
  AppPalette get palette => Theme.of(this).extension<AppPalette>() ?? AppPalette.light;
}
