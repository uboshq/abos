import 'package:flutter/material.dart';

import 'app_colors.dart';
import 'app_palette.dart';
import 'bar_palette.dart';
import 'expressive.dart';
import 'glass.dart';

/// The one [ThemeData] the app builds from tokens — screens ask for
/// `Theme.of(context)` and its extensions, never a literal colour or size.
class AppTheme {
  const AppTheme._();

  /// ⭐ অন্ধকার মোড কখন চালু — ফোন অন্ধকারে থাকলে অ্যাপও অন্ধকার (মোবাইলের নকশা ৩, পাতা ১১; মালিকের "হ্যাঁ", ১৯ অক্টোবর ২০২৬)।
  ///
  /// <p>⭐ চালু (০.৪.৩৯, ২০ অক্টোবর ২০২৬): সব পর্দার পট, লেখা, রেখা আর অবস্থার রং (সবুজ, লাল, হলুদ, ধূসর ও তাদের পট) এখন
  /// থিম থেকে (`context.palette`, `colorScheme`)। ⓘ একবার বন্ধ হয়েছিল — অন্ধকারের ছবি-টেস্টে সবুজ মোটের কার্ড হালকা থেকে
  /// গিয়েছিল; অবস্থার রংও palette-এ আসার পরে আবার চালু।
  /// ⛔ `test/dark_mode_ready_test.dart` পাহারা দেয় — কোনো পর্দায় আবার বাঁধা আলোর রং (`AppColors.surface`, `Colors.white` …)
  /// ঢুকলে লাল, কারণ অন্ধকারে সেখানে থিমের হালকা লেখা সাদা পটে মিলিয়ে যায়।
  static const ThemeMode mode = ThemeMode.system;

  /// ⭐ predictive back — পেছনে টানলে আগের পাতা উঁকি দেয়, ছাড়লে ফেরে (Android 14+; মোবাইলের নকশা ৩)। ম্যানিফেস্টে
  /// `enableOnBackInvokedCallback`। ⓘ না-রাখা ফর্মের পাহারা ([UnsavedGuard], PopScope) এতেও আগের মতো থামায়।
  static const PageTransitionsTheme pageTransitions = PageTransitionsTheme(builders: {
    TargetPlatform.android: PredictiveBackPageTransitionsBuilder(),
  });

  /// অন্ধকারের থিম — একই বীজ রং, Material 3-এর অন্ধকার রং-পরিবার; পট, কার্ড আর লেখা সব `colorScheme` থেকে
  static ThemeData dark() {
    final colorScheme = ColorScheme.fromSeed(
      seedColor: AppColors.primary,
      brightness: Brightness.dark,
      error: const Color(0xFFF2B8B5),
    );
    final input = OutlineInputBorder(
      borderRadius: BorderRadius.circular(8),
      borderSide: BorderSide(color: colorScheme.outlineVariant),
    );
    final palette = AppPalette.darkFrom(colorScheme);
    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: colorScheme.surface,
      appBarTheme: AppBarTheme(
        backgroundColor: colorScheme.surfaceContainer,
        foregroundColor: colorScheme.onSurface,
        elevation: 0,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        color: colorScheme.surfaceContainerLow,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: colorScheme.outlineVariant),
        ),
        margin: EdgeInsets.zero,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: colorScheme.surfaceContainerHighest,
        border: input,
        enabledBorder: input,
        focusedBorder: input.copyWith(borderSide: BorderSide(color: colorScheme.primary, width: 2)),
        errorBorder: input.copyWith(borderSide: BorderSide(color: colorScheme.error)),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: colorScheme.primary,
          foregroundColor: colorScheme.onPrimary,
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 20),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: colorScheme.surfaceContainer,
        indicatorColor: colorScheme.secondaryContainer,
        height: 64,
        labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
              fontSize: 11.5,
              fontWeight: states.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
              color: states.contains(WidgetState.selected) ? colorScheme.primary : colorScheme.onSurfaceVariant,
            )),
        iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(
              color: states.contains(WidgetState.selected) ? colorScheme.onSecondaryContainer : colorScheme.onSurfaceVariant,
            )),
      ),
      snackBarTheme: const SnackBarThemeData(behavior: SnackBarBehavior.floating),
      pageTransitionsTheme: pageTransitions,
      extensions: [palette, BarPalette.dark],
    );
  }

  static ThemeData light() {
    final colorScheme = ColorScheme.fromSeed(
      seedColor: AppColors.primary,
      brightness: Brightness.light,
      primary: AppColors.primary,
      onPrimary: AppColors.onPrimary,
      surface: AppColors.surface,
      onSurface: AppColors.onSurface,
      error: AppColors.danger,
    );

    const palette = AppPalette.light;
    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: AppColors.surfaceMuted,
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.header,
        foregroundColor: AppColors.onHeader,
        elevation: 0,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        color: AppColors.surface,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: const BorderSide(color: AppColors.border),
        ),
        margin: EdgeInsets.zero,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surface,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(8),
          borderSide: const BorderSide(color: AppColors.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(8),
          borderSide: const BorderSide(color: AppColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(8),
          borderSide: const BorderSide(color: AppColors.primary, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(8),
          borderSide: const BorderSide(color: AppColors.danger),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.primary,
          foregroundColor: AppColors.onPrimary,
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 20),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(8),
          ),
        ),
      ),
      // ⭐ নিচের পাঁচ ঘর — সাদা জমি, বাছা ঘরে টিয়া (ABOS স্কিন)
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: AppColors.surface,
        indicatorColor: AppColors.primarySoft,
        height: 64,
        labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
              fontSize: 11.5,
              fontWeight: states.contains(WidgetState.selected)
                  ? FontWeight.w700
                  : FontWeight.w500,
              color: states.contains(WidgetState.selected)
                  ? AppColors.primary
                  : AppColors.onSurfaceMuted,
            )),
        iconTheme: WidgetStateProperty.resolveWith((states) => IconThemeData(
              color: states.contains(WidgetState.selected)
                  ? AppColors.primary
                  : AppColors.onSurfaceMuted,
            )),
      ),
      snackBarTheme: const SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
      ),
      pageTransitionsTheme: pageTransitions,
      extensions: const [palette, BarPalette.light],
    );
  }

  /// ⭐ Liquid Glass — অন্ধকারের থিমের উপর: পট স্বচ্ছ (পেছনে [GlassBackdrop]-এর রঙের ছোপ), কার্ড আর ঘর সাদা আভার কাচ, লেখা সাদা।
  /// ⓘ আভা আর কিনারা মানুষের ঝাপসার দাগ থেকে ([GlassSpec]); আসল ঝাপসা (BackdropFilter) কেবল বড় অংশে — হোমের কার্ড, উপরের বল,
  ///   নিচের ক্যাপসুল — যাতে লম্বা তালিকায় ফোন ধীর না হয়। (মালিক, ২০ নভেম্বর ২০২৬ "ok")
  static ThemeData glass(GlassSpec spec) {
    final base = dark();
    final card = Colors.white.withValues(alpha: 0.04 + spec.fill);
    final edge = Colors.white.withValues(alpha: spec.edge);
    return base.copyWith(
      scaffoldBackgroundColor: Colors.transparent,
      canvasColor: const Color(0xF0161C33),
      colorScheme: base.colorScheme.copyWith(surface: const Color(0xFF161C33), primary: const Color(0xFF9CC9FF)),
      appBarTheme: const AppBarTheme(
          backgroundColor: Colors.transparent, foregroundColor: Colors.white, elevation: 0, centerTitle: false),
      cardTheme: CardThemeData(
        color: card,
        elevation: 0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22), side: BorderSide(color: edge)),
        margin: EdgeInsets.zero,
      ),
      inputDecorationTheme: base.inputDecorationTheme.copyWith(
        fillColor: Colors.white.withValues(alpha: 0.06 + spec.fill / 2),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: edge)),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: edge)),
        focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: Colors.white, width: 1.6)),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: const Color(0x4778DCFF),
          foregroundColor: Colors.white,
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 20),
          shape: const StadiumBorder(side: BorderSide(color: Color(0x59FFFFFF))),
        ),
      ),
      navigationBarTheme: base.navigationBarTheme.copyWith(backgroundColor: Colors.transparent),
      bottomSheetTheme: const BottomSheetThemeData(backgroundColor: Color(0xF0161C33)),
      extensions: [AppPalette.darkFrom(base.colorScheme), BarPalette.dark.withBar(Colors.transparent, Colors.white)],
    );
  }

  /// ⭐ Material 3 Expressive — নমুনার বেগুনি রং-পরিবার, বড় গোল কোনা, মোটা শিরোনাম; ABOS-এর আলোর থিমের উপর, তাই
  /// অবস্থার রং (`context.palette`) আগের মতোই কাজ করে। (মালিক, ২০ নভেম্বর ২০২৬ "ok")
  static ThemeData expressive() {
    final base = light();
    final scheme = ColorScheme.fromSeed(
      seedColor: Expressive.primary,
      primary: Expressive.primary,
      onPrimary: Colors.white,
      surface: Expressive.background,
      onSurface: Expressive.ink,
      error: AppColors.danger,
    );
    return base.copyWith(
      colorScheme: scheme,
      scaffoldBackgroundColor: Expressive.background,
      appBarTheme: const AppBarTheme(
        backgroundColor: Expressive.background,
        foregroundColor: Expressive.ink,
        elevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Expressive.ink),
      ),
      cardTheme: const CardThemeData(
        color: Expressive.surfaceBox,
        elevation: 0,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.all(Radius.circular(24))),
        margin: EdgeInsets.zero,
      ),
      inputDecorationTheme: base.inputDecorationTheme.copyWith(
        fillColor: Expressive.surfaceBox,
        border: const UnderlineInputBorder(
            borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
            borderSide: BorderSide(color: Expressive.primary, width: 2)),
        enabledBorder: const UnderlineInputBorder(
            borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
            borderSide: BorderSide(color: Expressive.primary, width: 2)),
        focusedBorder: const UnderlineInputBorder(
            borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
            borderSide: BorderSide(color: Expressive.primaryDeep, width: 3)),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: Expressive.primary,
          foregroundColor: Colors.white,
          padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 24),
          shape: const StadiumBorder(),
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: Color(0xFFFFD8E4),
        foregroundColor: Color(0xFF31111D),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.all(Radius.circular(24))),
      ),
      navigationBarTheme: base.navigationBarTheme.copyWith(backgroundColor: Expressive.background),
      extensions: [AppPalette.light, BarPalette.light.withBar(Expressive.background, Expressive.ink)],
    );
  }
}
