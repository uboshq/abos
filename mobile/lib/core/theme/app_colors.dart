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

  // ⭐ ABOS স্কিন — টিয়া, ঘন মাথা, সাদা কার্ড, হালকা জমি (মালিকের বাছাই, ১২ অক্টোবর ২০২৬; নমুনা
  // 4.10.2026/মোবাইল-নেভিগেশন-১০-স্কিনের-নমুনা.pdf, স্কিন ১)। অন্য স্কিন (Tiles …) পরে এই ফাইলেই বসবে —
  // পর্দাগুলো কেবল নাম চায়, রং নয়।
  static const Color primary = Color(0xFF087F91);
  static const Color primaryDark = Color(0xFF06323C);
  static const Color onPrimary = Color(0xFFFFFFFF);

  /// The app bar — darker than [primary], the ABOS skin's header.
  static const Color header = Color(0xFF06323C);
  static const Color onHeader = Color(0xFFFFFFFF);

  /// [primary] at a whisper — the selected tab's pill, a favourite's tile.
  static const Color primarySoft = Color(0xFFE0F2F4);

  static const Color surface = Color(0xFFFFFFFF);
  static const Color surfaceMuted = Color(0xFFF3F6F7);
  static const Color onSurface = Color(0xFF14212A);
  static const Color onSurfaceMuted = Color(0xFF5A6872);

  static const Color border = Color(0xFFDCE3E6);

  /// A count that wants attention — the red dot on a tile or a tab.
  static const Color badge = Color(0xFFDC2626);

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

  /// ⭐ Each module's colour — the web's rail colours, word for word
  /// (`resources/css/tokens.css`, `--color-module-<code>`), so a module reads
  /// the same on the phone as on the web.
  static const Map<String, Color> module = {
    'sales': Color(0xFF10B981),
    'purchase': Color(0xFFF59E0B),
    'inventory': Color(0xFF06B6D4),
    'accounts': Color(0xFF4F46E5),
    'finance': Color(0xFF0369A1),
    'customer': Color(0xFF8B5CF6),
    'supplier': Color(0xFF0D9488),
    'approval': Color(0xFFEA580C),
    'hr': Color(0xFFF43F5E),
    'reports': Color(0xFF475569),
    'documents': Color(0xFF65A30D),
    'executive': Color(0xFFA16207),
    // ⭐ প্রমোশন — ওয়েবের রেলের গোলাপি (tokens.css `--color-module-promotion`; ১৩ অক্টোবর ২০২৬)
    'promotion': Color(0xFFDB2777),
  };

  static Color moduleColor(String code) => module[code] ?? primary;
}
