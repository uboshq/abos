import 'package:flutter/material.dart';

import '../../../core/theme/app_look.dart';
import '../../../core/theme/app_palette.dart';
import '../../../core/theme/expressive.dart';
import '../../../core/theme/glass.dart';

/// ⭐ ≡ মেনুর রূপ-নির্ভর মাপ আর রং — মালিকের অনুমোদিত নমুনা "খ. অ্যাপ: ≡ মেনু" (আইটেম ১০, ২০ নভেম্বর ২০২৬)।
///
/// <p>তিন রূপ: [AppLook.expressive] = নমুনার `.m3` (স্মার্ট), [AppLook.glass] = `.lg` (গ্লাস), [AppLook.abos] = `.cl` (ক্লাসিক)।
/// ⓘ ক্লাসিকের রং থিম থেকে (অন্ধকার মোডেও পড়া যায়); স্মার্ট আর গ্লাস নমুনার হুবহু।
@immutable
class MenuLook {
  const MenuLook._({
    required this.look,
    required this.ink,
    required this.muted,
    required this.link,
    required this.soft,
    required this.softInk,
    required this.card,
    required this.cardBorder,
    required this.side,
    required this.secRadius,
    required this.noteBg,
    required this.noteInk,
    required this.noteBorder,
    required this.outBg,
    required this.outInk,
    required this.outBorder,
  });

  factory MenuLook.of(BuildContext context) {
    final theme = Theme.of(context);
    final cs = theme.colorScheme;
    final look = AppLooks.instance.look.value;
    switch (look) {
      case AppLook.expressive:
        return const MenuLook._(
          look: AppLook.expressive,
          ink: Expressive.ink,
          muted: Expressive.inkMuted,
          link: Expressive.primary,
          soft: Expressive.secondaryBox,
          softInk: Color(0xFF1D192B),
          card: Expressive.surfaceBox,
          cardBorder: Color(0xFFE7E0EC),
          side: 16,
          secRadius: 24,
          noteBg: Color(0xFFFFF7E6),
          noteInk: Color(0xFF8A5300),
          noteBorder: Color(0xFFF5D9A8),
          outBg: Color(0xFFF9DEDC),
          outInk: Color(0xFFB3261E),
          outBorder: null,
        );
      case AppLook.glass:
        return const MenuLook._(
          look: AppLook.glass,
          ink: Color(0xFFFFFFFF),
          muted: Color(0xB8FFFFFF),
          link: Color(0xFFC4B5FD),
          soft: Color(0x21FFFFFF),
          softInk: Color(0xFFFFFFFF),
          card: Color(0x17FFFFFF),
          cardBorder: Color(0x3DFFFFFF),
          side: 12,
          secRadius: 18,
          noteBg: Color(0x24FFC864),
          noteInk: Color(0xFFFFE2A8),
          noteBorder: Color(0x59FFC864),
          outBg: Color(0x2EE11D48),
          outInk: Color(0xFFFFB3C1),
          outBorder: Color(0x73FF7896),
        );
      case AppLook.abos:
        final light = theme.brightness == Brightness.light;
        final palette = context.palette;
        return MenuLook._(
          look: AppLook.abos,
          ink: cs.onSurface,
          muted: cs.onSurfaceVariant,
          link: cs.primary,
          soft: cs.primaryContainer,
          softInk: cs.onPrimaryContainer,
          card: theme.cardTheme.color ?? cs.surface,
          cardBorder: cs.outlineVariant,
          side: 12,
          secRadius: 10,
          noteBg: light ? const Color(0xFFFFF7E6) : palette.warningSurface,
          noteInk: light ? const Color(0xFF8A5300) : palette.warning,
          noteBorder: light ? const Color(0xFFF5D9A8) : palette.warning,
          outBg: light ? const Color(0xFFFFF1F2) : palette.dangerSurface,
          outInk: light ? const Color(0xFFE11D48) : palette.danger,
          outBorder: light ? const Color(0xFFFECDD3) : palette.danger,
        );
    }
  }

  final AppLook look;
  final Color ink;
  final Color muted;

  /// "আমার প্রোফাইল দেখুন"
  final Color link;

  /// হালকা ঘর — "বদলান", "আরও দেখুন", ভাঁজের চিহ্ন-ঘর
  final Color soft;
  final Color softInk;
  final Color card;
  final Color cardBorder;

  /// পাতার দুই পাশের ফাঁক — স্মার্টে 16, বাকি দুইয়ে 12
  final double side;
  final double secRadius;

  /// "সব শাখা — কেবল দেখা"-র হলুদ নোট
  final Color noteBg;
  final Color noteInk;
  final Color noteBorder;

  /// লাল "বের হওয়া"
  final Color outBg;
  final Color outInk;
  final Color? outBorder;

  bool get smart => look == AppLook.expressive;
  bool get glass => look == AppLook.glass;

  /// রঙিন চিহ্ন-ঘরের সাদা চিহ্ন — দুই মোডেই রঙিন পটে সাদা
  static const Color onColour = Color(0xFFFFFFFF);

  /// লাল সংখ্যা-ব্যাজ
  static const Color badge = Color(0xFFE11D48);

  /// নমুনার শর্টকাটের চিহ্ন-রং, ক্রমে
  static const List<Color> tileColours = [
    Color(0xFFF59E0B),
    Color(0xFF16A34A),
    Color(0xFFEA580C),
    Color(0xFF2563EB),
    Color(0xFF0D9488),
    Color(0xFF7C3AED),
    Color(0xFFE11D48),
    Color(0xFF4F46E5),
  ];

  /// নমুনার "সাম্প্রতিক"-এর চিহ্ন-রং, ক্রমে
  static const List<Color> recentColours = [Color(0xFF16A34A), Color(0xFF2563EB), Color(0xFFEA580C)];

  static const Color approvals = Color(0xFFE11D48);
  static const Color notices = Color(0xFFF59E0B);
  static const Color dayClose = Color(0xFF2563EB);

  /// "কোম্পানি ও শাখা"-র চিহ্ন-ঘর — স্মার্টে বেগুনি, বাকি দুইয়ে গাঢ় নীল
  Color get workspaceSquare => smart ? Expressive.primary : const Color(0xFF0B2A5C);
}

/// নমুনার `.sec` — স্মার্টে হালকা বেগুনি গোল বাক্স, গ্লাসে কাচ, ক্লাসিকে সাদা কার্ড
class MenuBox extends StatelessWidget {
  const MenuBox({super.key, required this.tokens, required this.child, this.padding});

  final MenuLook tokens;
  final Widget child;

  /// null = নমুনার মাপ (স্মার্টে 16, বাকি দুইয়ে 14)
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    final t = tokens;
    final pad = padding ?? EdgeInsets.all(t.smart ? 16 : 14);
    if (t.glass) return GlassPanel(radius: t.secRadius, padding: pad, child: child);
    return Container(
      padding: pad,
      decoration: BoxDecoration(
        color: t.card,
        borderRadius: BorderRadius.circular(t.secRadius),
        border: t.smart ? null : Border.all(color: t.cardBorder),
      ),
      child: Material(type: MaterialType.transparency, child: child),
    );
  }
}

/// রঙিন চারকোনা চিহ্ন-ঘর (`.sq`)
class MenuSquare extends StatelessWidget {
  const MenuSquare({super.key, required this.icon, required this.bg, required this.ink, this.size = 36, this.radius = 11});

  final IconData icon;
  final Color bg;
  final Color ink;
  final double size;
  final double radius;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(radius)),
        alignment: Alignment.center,
        child: Icon(icon, size: size * 0.55, color: ink),
      );
}

/// সংখ্যার বড়ি — শর্টকাটের লাল ব্যাজ আর "আজকের কাজ"-এর রঙিন বড়ি
class MenuCount extends StatelessWidget {
  const MenuCount({super.key, required this.count, required this.bg, this.height = 24, this.minWidth = 28, this.fontSize = 13});

  final int count;
  final Color bg;
  final double height;
  final double minWidth;
  final double fontSize;

  @override
  Widget build(BuildContext context) => Container(
        constraints: BoxConstraints(minWidth: minWidth, minHeight: height),
        padding: EdgeInsets.symmetric(horizontal: height / 3),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(12)),
        alignment: Alignment.center,
        child: Text('$count',
            style: TextStyle(color: MenuLook.onColour, fontWeight: FontWeight.w700, fontSize: fontSize, height: 1)),
      );
}
