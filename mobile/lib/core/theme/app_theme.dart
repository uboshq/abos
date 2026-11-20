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
    Color white(double a) => Colors.white.withValues(alpha: a.clamp(0.0, 1.0));
    // ⓘ ০.৪.৬০: সাধারণ কার্ডে (BackdropFilter ছাড়া) পেছনের গাঢ় রঙের হালকা ধোঁয়া — স্বচ্ছ কাচেও উজ্জ্বল ছোপের উপর লেখা পড়া যায়
    final card = Color.alphaBlend(white(0.04 + spec.fill), GlassBackdrop.ground.withValues(alpha: 0.40));
    final edge = white(spec.edge);
    // ⭐ ০.৪.৬০ — বাকি পর্দাও কাচে: রং-পরিবারের পট আর ঘর স্বচ্ছ সাদা আভা, তাই `colorScheme` থেকে রং নেওয়া প্রতিটা পর্দা নিজে
    // থেকেই কাচ (পাতা স্বচ্ছ, ঘর আর বাক্স আভার কাচ, লেখা সাদা)। ⓘ ভাসমান অংশ (পপ-আপ, মেনু, তারিখ বাছাই, নিচের শিট) ঘন
    // কাচ — [glassOverlay] — যাতে নিচের লেখার উপর লেখা না পড়ে।
    final scheme = base.colorScheme.copyWith(
      surface: Colors.transparent,
      onSurface: Colors.white,
      onSurfaceVariant: glassMuted,
      surfaceDim: Colors.transparent,
      surfaceBright: white(0.10 + spec.fill),
      surfaceContainerLowest: white(0.02 + spec.fill / 2),
      surfaceContainerLow: card,
      surfaceContainer: white(0.06 + spec.fill),
      surfaceContainerHigh: white(0.09 + spec.fill),
      surfaceContainerHighest: white(0.12 + spec.fill),
      primary: const Color(0xFF9CC9FF),
      primaryContainer: white(0.20),
      onPrimaryContainer: Colors.white,
      secondaryContainer: white(0.18),
      onSecondaryContainer: Colors.white,
      tertiaryContainer: white(0.16),
      onTertiaryContainer: Colors.white,
      outline: white(0.55),
      outlineVariant: white(0.22),
      shadow: Colors.transparent,
    );
    const overlayShape = RoundedRectangleBorder(
        borderRadius: BorderRadius.all(Radius.circular(28)), side: BorderSide(color: Color(0x40FFFFFF)));
    final glassButton = ButtonStyle(
      backgroundColor: WidgetStateProperty.resolveWith(
          (s) => s.contains(WidgetState.disabled) ? white(0.08) : const Color(0x4778DCFF)),
      foregroundColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.disabled) ? white(0.5) : Colors.white),
      shape: const WidgetStatePropertyAll(StadiumBorder(side: BorderSide(color: Color(0x59FFFFFF)))),
    );
    // ⓘ অবস্থার রং: লেখা সেই রঙের খুব হালকা সুর (কাচের গাঢ় পটে ৮:১) — উজ্জ্বল রঙিন ছোপের উপরেও সবুজ/লাল লেখা পড়া যায়;
    //   পট সেই রঙের স্বচ্ছ ছোঁয়া
    Color tint(Color c) => c.withValues(alpha: 0.32);
    Color ink(Color c) => AppPalette.lighten(c, const [GlassBackdrop.ground], 8);
    final palette = AppPalette.darkFrom(base.colorScheme).copyWith(
      success: ink(AppColors.success),
      danger: ink(AppColors.danger),
      warning: ink(AppColors.warning),
      pending: ink(AppColors.pending),
      surface: card,
      surfaceMuted: white(0.08),
      onSurface: Colors.white,
      onSurfaceMuted: glassMuted,
      border: white(0.22),
      primarySoft: white(0.20),
      successSurface: tint(AppColors.success),
      dangerSurface: tint(AppColors.danger),
      warningSurface: tint(AppColors.warning),
      pendingSurface: tint(AppColors.pending),
    );
    return base.copyWith(
      scaffoldBackgroundColor: Colors.transparent,
      canvasColor: glassOverlay,
      // ⓘ পট স্বচ্ছ, তাই উঁচু Material-এর ছায়া কালো পট্টি আঁকত (যেমন টাকার ফর্মের নিচের বোতামের সারি) — কাচে ছায়া নেই;
      // কাচের প্যানেল নিজের ছায়া নিজে আঁকে ([GlassPanel])
      shadowColor: Colors.transparent,
      colorScheme: scheme,
      textTheme: _shaded(base.textTheme),
      filledButtonTheme: FilledButtonThemeData(style: glassButton),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
            foregroundColor: Colors.white, side: BorderSide(color: white(0.45)), shape: const StadiumBorder()),
      ),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(foregroundColor: const Color(0xFFCFE6FF))),
      chipTheme: base.chipTheme.copyWith(
        backgroundColor: white(0.08),
        selectedColor: white(0.26),
        side: BorderSide(color: white(0.30)),
        shape: const StadiumBorder(),
      ),
      dividerTheme: DividerThemeData(color: white(0.18)),
      dialogTheme: const DialogThemeData(
          backgroundColor: glassOverlay, surfaceTintColor: Colors.transparent, shape: overlayShape),
      popupMenuTheme: const PopupMenuThemeData(color: glassOverlay, surfaceTintColor: Colors.transparent),
      menuTheme: const MenuThemeData(
          style: MenuStyle(
              backgroundColor: WidgetStatePropertyAll(glassOverlay),
              surfaceTintColor: WidgetStatePropertyAll(Colors.transparent))),
      dropdownMenuTheme: const DropdownMenuThemeData(
          menuStyle: MenuStyle(
              backgroundColor: WidgetStatePropertyAll(glassOverlay),
              surfaceTintColor: WidgetStatePropertyAll(Colors.transparent))),
      datePickerTheme: const DatePickerThemeData(
          backgroundColor: glassOverlay, surfaceTintColor: Colors.transparent, headerForegroundColor: Colors.white),
      timePickerTheme: const TimePickerThemeData(backgroundColor: glassOverlay),
      drawerTheme: const DrawerThemeData(backgroundColor: glassOverlay, surfaceTintColor: Colors.transparent),
      searchViewTheme: const SearchViewThemeData(backgroundColor: glassOverlay, surfaceTintColor: Colors.transparent),
      snackBarTheme: base.snackBarTheme.copyWith(backgroundColor: glassOverlay, contentTextStyle: base.textTheme.bodyMedium?.copyWith(color: Colors.white)),
      tabBarTheme: base.tabBarTheme.copyWith(
          labelColor: Colors.white,
          unselectedLabelColor: glassMuted,
          indicatorColor: Colors.white,
          dividerColor: white(0.18)),
      appBarTheme: const AppBarTheme(
          backgroundColor: Colors.transparent,
          foregroundColor: Colors.white,
          elevation: 0,
          scrolledUnderElevation: 0,
          surfaceTintColor: Colors.transparent,
          centerTitle: false),
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
      bottomSheetTheme: const BottomSheetThemeData(backgroundColor: glassOverlay, modalBackgroundColor: glassOverlay),
      extensions: [palette, BarPalette.dark.withBar(Colors.transparent, Colors.white)],
    );
  }

  /// Liquid Glass-এর ভাসমান অংশের ঘন কাচ — পপ-আপ, মেনু, তারিখ বাছাই, নিচের শিট (আগের শিটের একই রং)
  static const Color glassOverlay = Color(0xF0161C33);

  /// Liquid Glass-এর মলিন লেখা — সাদার কাছাকাছি, যাতে রঙিন ছোপের উপরেও পড়া যায়
  static const Color glassMuted = Color(0xFFDCE3F0);

  /// লেখার হালকা ছায়া — [GlassPanel]-এর একই
  static const List<Shadow> glassShadow = [Shadow(color: Color(0x59000000), blurRadius: 6)];

  /// ⭐ লেখার হালকা ছায়া সব লেখায় (০.৪.৬০) — আয়নার মতো স্বচ্ছ কাচেও (দাগ 0) রঙিন ছোপের উপর সাদা লেখা পড়া যায়। ⓘ পর্দার নিজের
  /// `TextStyle(color: …)` ছায়া না দিলে এই ছায়াই থাকে (Material-এর DefaultTextStyle থেকে মেশে)।
  static TextTheme _shaded(TextTheme t) {
    TextStyle? s(TextStyle? x) => x?.copyWith(shadows: glassShadow);
    return t.copyWith(
      displayLarge: s(t.displayLarge),
      displayMedium: s(t.displayMedium),
      displaySmall: s(t.displaySmall),
      headlineLarge: s(t.headlineLarge),
      headlineMedium: s(t.headlineMedium),
      headlineSmall: s(t.headlineSmall),
      titleLarge: s(t.titleLarge),
      titleMedium: s(t.titleMedium),
      titleSmall: s(t.titleSmall),
      bodyLarge: s(t.bodyLarge),
      bodyMedium: s(t.bodyMedium),
      bodySmall: s(t.bodySmall),
      labelLarge: s(t.labelLarge),
      labelMedium: s(t.labelMedium),
      labelSmall: s(t.labelSmall),
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
      // ⭐ ০.৪.৬০ — বাকি পর্দাও Material 3 Expressive-এ: বোতাম ক্যাপসুল, চিপ গোল কোনা আর বেগুনি সুরের বাছাই, পপ-আপ আর নিচের শিট
      // বড় গোল কোনা (28), ট্যাব বেগুনি; পর্দার নিজের ঘর `colorScheme`-এর বেগুনি সুরের পটে (tonal)
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(shape: const StadiumBorder()),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
            foregroundColor: Expressive.primary,
            side: const BorderSide(color: Color(0xFF79747E)),
            shape: const StadiumBorder()),
      ),
      textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(foregroundColor: Expressive.primary)),
      chipTheme: base.chipTheme.copyWith(
        backgroundColor: Expressive.background,
        selectedColor: Expressive.secondaryBox,
        side: const BorderSide(color: Color(0xFFCAC4D0)),
        shape: const RoundedRectangleBorder(borderRadius: BorderRadius.all(Radius.circular(12))),
      ),
      dividerTheme: const DividerThemeData(color: Color(0xFFE7E0EC)),
      dialogTheme: const DialogThemeData(
        backgroundColor: Expressive.surfaceBox,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.all(Radius.circular(28))),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: Expressive.background,
        modalBackgroundColor: Expressive.background,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      ),
      tabBarTheme: base.tabBarTheme.copyWith(
          labelColor: Expressive.primary, unselectedLabelColor: Expressive.inkMuted, indicatorColor: Expressive.primary),
      navigationBarTheme: base.navigationBarTheme.copyWith(backgroundColor: Expressive.background),
      extensions: [
        AppPalette.light.copyWith(
          surface: Expressive.surfaceBox,
          surfaceMuted: Expressive.background,
          onSurface: Expressive.ink,
          onSurfaceMuted: Expressive.inkMuted,
          border: const Color(0xFFCAC4D0),
          primarySoft: Expressive.secondaryBox,
        ),
        BarPalette.light.withBar(Expressive.background, Expressive.ink),
      ],
    );
  }
}
