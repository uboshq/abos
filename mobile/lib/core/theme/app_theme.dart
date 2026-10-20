import 'package:flutter/material.dart';

import 'app_colors.dart';
import 'app_palette.dart';

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
      extensions: [palette],
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
      extensions: const [palette],
    );
  }
}
