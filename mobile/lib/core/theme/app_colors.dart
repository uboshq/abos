import 'package:flutter/material.dart';

/// Colour tokens, not literals sprinkled through screens.
///
/// The owner has not yet decided how many themes the app will ship (the web
/// side has nine); routing every colour through this one file is what makes
/// that decision free to change later instead of a find-and-replace across
/// every screen. A screen should never write `Color(0xFF...)` — it asks
/// [AppColors] for a name instead.
class AppColors {
  const AppColors._();

  static const Color primary = Color(0xFF0F6B4C);
  static const Color primaryDark = Color(0xFF0A4A34);
  static const Color onPrimary = Color(0xFFFFFFFF);

  static const Color surface = Color(0xFFFFFFFF);
  static const Color surfaceMuted = Color(0xFFF4F6F5);
  static const Color onSurface = Color(0xFF161D1A);
  static const Color onSurfaceMuted = Color(0xFF5B655F);

  static const Color border = Color(0xFFDDE3E0);

  static const Color danger = Color(0xFFB3261E);
  static const Color dangerSurface = Color(0xFFFBE9E7);
  static const Color warning = Color(0xFF9A6300);
  static const Color warningSurface = Color(0xFFFFF3E0);
  static const Color success = Color(0xFF1E7A3D);
  static const Color successSurface = Color(0xFFE7F5EB);

  /// Offline / not-yet-synced state — neither an error nor a success, its own
  /// colour so a rep does not read "pending" as "failed".
  static const Color pending = Color(0xFF4A5568);
  static const Color pendingSurface = Color(0xFFEDEFF2);
}
