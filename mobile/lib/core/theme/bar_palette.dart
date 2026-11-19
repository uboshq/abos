import 'package:flutter/material.dart';

/// ⭐ উপরের বার আর ছবির মেনুর রং — মালিকের অনুমোদিত পুরো-পাতার নমুনা (পাতা ২, ২০ অক্টোবর ২০২৬;
/// `পুরো-পাতার-নমুনা-ওয়েব-ও-অ্যাপ-২০-অক্টোবর.pdf`), আলো আর অন্ধকারে। পর্দা রং লেখে না, এখান থেকে চায়
/// (`context.bar`)।
///
/// <p>⭐ ডেমো সার্ভারে বারের রং আলাদা ([demoBar]) — বেগুনি, যাতে কেউ আসল খাতা ভেবে কাজ না করে।
@immutable
class BarPalette extends ThemeExtension<BarPalette> {
  const BarPalette({
    required this.bar,
    required this.onBar,
    required this.demoBar,
    required this.onDemoBar,
    required this.demoPill,
    required this.onDemoPill,
    required this.badge,
    required this.onBadge,
    required this.avatarFrom,
    required this.avatarTo,
    required this.onAvatar,
    required this.avatarRing,
    required this.ribbon,
    required this.onRibbon,
    required this.menu,
    required this.menuLine,
    required this.menuInk,
    required this.menuSub,
    required this.menuRow,
    required this.selected,
    required this.onSelected,
    required this.danger,
  });

  /// বারের পট আর লেখা — নীল (নমুনার `--bar`)
  final Color bar;
  final Color onBar;
  final Color demoBar;
  final Color onDemoBar;
  final Color demoPill;
  final Color onDemoPill;

  /// ঘণ্টার গোনা — লাল পটে সাদা
  final Color badge;
  final Color onBadge;

  /// নিজের ছবি না থাকলে — কমলা গোলের উপর নামের প্রথম অক্ষর
  final Color avatarFrom;
  final Color avatarTo;
  final Color onAvatar;

  /// মেনু খোলা থাকলে ছবির চারপাশের কমলা দাগ
  final Color avatarRing;

  /// অফলাইনের হলুদ ফিতা
  final Color ribbon;
  final Color onRibbon;

  /// ছবির মেনু — কার্ড, রেখা, লেখা, মলিন লেখা, রঙের সারির পট
  final Color menu;
  final Color menuLine;
  final Color menuInk;
  final Color menuSub;
  final Color menuRow;

  /// রঙের তিন ঘরের বাছা ঘর
  final Color selected;
  final Color onSelected;

  /// "বের হওয়া"
  final Color danger;

  static const BarPalette light = BarPalette(
    bar: Color(0xFF0B2A5B),
    onBar: Color(0xFFFFFFFF),
    demoBar: Color(0xFF4A1D6E),
    onDemoBar: Color(0xFFFFFFFF),
    demoPill: Color(0xFFFFE08A),
    onDemoPill: Color(0xFF3D2A00),
    badge: Color(0xFFD92D20),
    onBadge: Color(0xFFFFFFFF),
    avatarFrom: Color(0xFFF4C27A),
    avatarTo: Color(0xFFD9822B),
    onAvatar: Color(0xFF2E1900),
    avatarRing: Color(0xFFE8590C),
    ribbon: Color(0xFFFFF3CD),
    onRibbon: Color(0xFF6B4800),
    menu: Color(0xFFFFFFFF),
    menuLine: Color(0xFFDDE3EA),
    menuInk: Color(0xFF14202E),
    menuSub: Color(0xFF4A5768),
    menuRow: Color(0xFFF6F8FB),
    selected: Color(0xFFDBE6FF),
    onSelected: Color(0xFF1D3FA8),
    danger: Color(0xFFB42318),
  );

  static const BarPalette dark = BarPalette(
    bar: Color(0xFF14233D),
    onBar: Color(0xFFFFFFFF),
    demoBar: Color(0xFF3A1856),
    onDemoBar: Color(0xFFFFFFFF),
    demoPill: Color(0xFFFFE08A),
    onDemoPill: Color(0xFF3D2A00),
    badge: Color(0xFFD92D20),
    onBadge: Color(0xFFFFFFFF),
    avatarFrom: Color(0xFFF4C27A),
    avatarTo: Color(0xFFD9822B),
    onAvatar: Color(0xFF2E1900),
    avatarRing: Color(0xFFF08C4A),
    ribbon: Color(0xFF5C4A12),
    onRibbon: Color(0xFFFFE08A),
    menu: Color(0xFF1A212B),
    menuLine: Color(0xFF2A3442),
    menuInk: Color(0xFFE6EDF7),
    menuSub: Color(0xFF9FB0C4),
    menuRow: Color(0xFF212A36),
    selected: Color(0xFF2B3F73),
    onSelected: Color(0xFFDBE6FF),
    danger: Color(0xFFFFB4AB),
  );

  @override
  BarPalette copyWith() => this;

  /// ⭐ একই রং-পরিবার, কেবল বারের জমি আর লেখা অন্য — Liquid Glass-এ স্বচ্ছ বার আর সাদা লেখা, Material 3-এ নমুনার হালকা
  /// বেগুনি-সাদা জমি (০.৪.৫৮; মালিক, ২০ নভেম্বর ২০২৬)
  BarPalette withBar(Color bar, Color onBar) => BarPalette(
        bar: bar,
        onBar: onBar,
        demoBar: demoBar,
        onDemoBar: onDemoBar,
        demoPill: demoPill,
        onDemoPill: onDemoPill,
        badge: badge,
        onBadge: onBadge,
        avatarFrom: avatarFrom,
        avatarTo: avatarTo,
        onAvatar: onAvatar,
        avatarRing: avatarRing,
        ribbon: ribbon,
        onRibbon: onRibbon,
        menu: menu,
        menuLine: menuLine,
        menuInk: menuInk,
        menuSub: menuSub,
        menuRow: menuRow,
        selected: selected,
        onSelected: onSelected,
        danger: danger,
      );

  @override
  BarPalette lerp(BarPalette? other, double t) {
    if (other == null) return this;
    Color l(Color a, Color b) => Color.lerp(a, b, t)!;
    return BarPalette(
      bar: l(bar, other.bar),
      onBar: l(onBar, other.onBar),
      demoBar: l(demoBar, other.demoBar),
      onDemoBar: l(onDemoBar, other.onDemoBar),
      demoPill: l(demoPill, other.demoPill),
      onDemoPill: l(onDemoPill, other.onDemoPill),
      badge: l(badge, other.badge),
      onBadge: l(onBadge, other.onBadge),
      avatarFrom: l(avatarFrom, other.avatarFrom),
      avatarTo: l(avatarTo, other.avatarTo),
      onAvatar: l(onAvatar, other.onAvatar),
      avatarRing: l(avatarRing, other.avatarRing),
      ribbon: l(ribbon, other.ribbon),
      onRibbon: l(onRibbon, other.onRibbon),
      menu: l(menu, other.menu),
      menuLine: l(menuLine, other.menuLine),
      menuInk: l(menuInk, other.menuInk),
      menuSub: l(menuSub, other.menuSub),
      menuRow: l(menuRow, other.menuRow),
      selected: l(selected, other.selected),
      onSelected: l(onSelected, other.onSelected),
      danger: l(danger, other.danger),
    );
  }
}

extension BarPaletteOf on BuildContext {
  /// এই পর্দার থিমের বারের রং — থিমে না থাকলে আলো/অন্ধকার দেখে
  BarPalette get bar =>
      Theme.of(this).extension<BarPalette>() ??
      (Theme.of(this).brightness == Brightness.dark ? BarPalette.dark : BarPalette.light);
}
