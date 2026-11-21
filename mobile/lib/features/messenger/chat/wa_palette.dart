import 'package:flutter/material.dart';

import '../../../core/theme/app_look.dart';
import '../../../core/theme/theme_choice.dart';

/// ⭐ চ্যাটের পাতার রং — নমুনা 3nuwRUdn v5-এর `--wa-*` (আলো আর অন্ধকার), তিন রূপেই একই।
///
/// <p>ⓘ অ্যাপের থিম অন্ধকার হলে অন্ধকারের রং ([WaPalette.of]); রূপের নিজের রং (স্মার্ট/গ্লাস/ক্লাসিক) এখানে আসে না — নমুনায়
/// চ্যাটের পাতা তিন রূপেই এক।
@immutable
class WaPalette {
  const WaPalette._({
    required this.dark,
    required this.wall,
    required this.doodle,
    required this.hdr,
    required this.hdrInk,
    required this.ico,
    required this.th,
    required this.me,
    required this.ink,
    required this.meta,
    required this.metaMe,
    required this.chip,
    required this.chipInk,
    required this.pill,
    required this.ph,
    required this.inset,
    required this.lineIn,
    required this.sheet,
    required this.line,
    required this.link,
  });

  final bool dark;
  final Color wall;
  final Color doodle;
  final Color hdr;
  final Color hdrInk;
  final Color ico;
  final Color th;
  final Color me;
  final Color ink;
  final Color meta;
  final Color metaMe;
  final Color chip;
  final Color chipInk;
  final Color pill;
  final Color ph;
  final Color inset;
  final Color lineIn;
  final Color sheet;
  final Color line;
  final Color link;

  /// দুই মোডেই এক
  static const Color green = Color(0xFF00A884);
  static const Color read = Color(0xFF53BDEB);
  static const Color online = Color(0xFF25D366);
  static const Color failed = Color(0xFFE5484D);

  /// ডায়ালগ আর শিটের পেছনের ছায়া — নমুনার `.dlgw` `rgba(11,20,26,.45)`, রূপ যা-ই হোক
  static const Color scrim = Color(0x730B141A);

  static const WaPalette light = WaPalette._(
    dark: false,
    wall: Color(0xFFEFEAE2),
    doodle: Color(0xFFDCD3C6),
    hdr: Color(0xFFFFFFFF),
    hdrInk: Color(0xFF111B21),
    ico: Color(0xFF54656F),
    th: Color(0xFFFFFFFF),
    me: Color(0xFFD9FDD3),
    ink: Color(0xFF111B21),
    meta: Color(0xFF667781),
    metaMe: Color(0xFF667781),
    chip: Color(0xFFFFFFFF),
    chipInk: Color(0xFF54656F),
    pill: Color(0xFFFFFFFF),
    ph: Color(0xFF8696A0),
    inset: Color(0x0D0B141A),
    lineIn: Color(0x170B141A),
    sheet: Color(0xFFFFFFFF),
    line: Color(0xFFE9EDEF),
    link: Color(0xFF008069),
  );

  static const WaPalette darkPalette = WaPalette._(
    dark: true,
    wall: Color(0xFF0B141A),
    doodle: Color(0xFF17232A),
    hdr: Color(0xFF1F2C34),
    hdrInk: Color(0xFFE9EDEF),
    ico: Color(0xFFAEBAC1),
    th: Color(0xFF202C33),
    me: Color(0xFF005C4B),
    ink: Color(0xFFE9EDEF),
    meta: Color(0xFF8696A0),
    metaMe: Color(0x99E9EDEF),
    chip: Color(0xFF182229),
    chipInk: Color(0xFF8696A0),
    pill: Color(0xFF2A3942),
    ph: Color(0xFF8696A0),
    inset: Color(0x33000000),
    lineIn: Color(0x1AFFFFFF),
    sheet: Color(0xFF1F2C34),
    line: Color(0xFF2A3942),
    link: Color(0xFF21C063),
  );

  /// ⭐ তিন রূপেই এক — ক্লাসিকে অ্যাপের থিম (সেটাই মানুষের আলো/অন্ধকার বাছাই); গ্লাস (সবসময় অন্ধকার থিম) আর স্মার্ট
  /// (সবসময় আলো থিম)-এ থিম নয়, মানুষের নিজের বাছাই ([ThemeChoices]; "ফোনের মতো" হলে ফোনের আলো/অন্ধকার) — তাই একই মানুষ
  /// যে রূপেই থাকুন, চ্যাটের পাতা একই রঙে।
  static WaPalette of(BuildContext context) {
    if (AppLooks.instance.look.value == AppLook.abos) {
      return Theme.of(context).brightness == Brightness.dark ? darkPalette : light;
    }
    final dark = switch (ThemeChoices.instance.current.value.mode) {
      ThemeMode.dark => true,
      ThemeMode.light => false,
      ThemeMode.system => MediaQuery.maybePlatformBrightnessOf(context) == Brightness.dark,
    };
    return dark ? darkPalette : light;
  }

  /// বুদবুদ, তারিখ আর কাগজের ছায়া — `0 1px .5px rgba(11,20,26,.13)`
  static const List<BoxShadow> bubbleShadow = [BoxShadow(color: Color(0x210B141A), offset: Offset(0, 1), blurRadius: .5)];
}
