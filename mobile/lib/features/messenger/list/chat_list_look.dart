import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../../core/locale/l10n.dart';
import '../../../core/messenger/messenger_api.dart';
import '../../../core/profile/face_image.dart';
import '../../../core/theme/app_look.dart';
import '../../../core/theme/expressive.dart';

/// ⭐ বার্তার তালিকার রূপ-নির্ভর রং — মালিকের অনুমোদিত নমুনা 3nuwRUdn v5 "ক" (২১ নভেম্বর ২০২৬): `.m3` = স্মার্ট
/// ([AppLook.expressive]), `.lg` = গ্লাস ([AppLook.glass]), `.cl` = ক্লাসিক ([AppLook.abos])। ⓘ ক্লাসিকের অন্ধকার থিমের রং থেকে।
@immutable
class ChatListLook {
  const ChatListLook._({
    required this.look,
    required this.bg,
    required this.ink,
    required this.muted,
    required this.search,
    required this.chip,
    required this.chipOn,
    required this.chipOnInk,
    required this.divider,
    required this.ring,
    required this.unreadTime,
  });

  factory ChatListLook.of(BuildContext context) {
    final theme = Theme.of(context);
    final cs = theme.colorScheme;
    switch (AppLooks.instance.look.value) {
      case AppLook.expressive:
        return const ChatListLook._(
          look: AppLook.expressive,
          bg: Expressive.background,
          ink: Expressive.ink,
          muted: Expressive.inkMuted,
          search: Color(0xFFF0F2F5),
          chip: Color(0xFFF0F2F5),
          chipOn: Color(0xFFD9FDD3),
          chipOnInk: Color(0xFF008069),
          divider: Color(0x141D1B20),
          ring: Expressive.background,
          unreadTime: Color(0xFF1DAA61),
        );
      case AppLook.glass:
        return const ChatListLook._(
          look: AppLook.glass,
          bg: Color(0x00000000),
          ink: Color(0xFFFFFFFF),
          muted: Color(0xB8FFFFFF),
          search: Color(0x1FFFFFFF),
          chip: Color(0x1FFFFFFF),
          chipOn: Color(0x4725D366),
          chipOnInk: Color(0xFFDCFCE7),
          divider: Color(0x24FFFFFF),
          ring: Color(0xFF3A0C4F),
          unreadTime: Color(0xFF4ADE80),
        );
      case AppLook.abos:
        final light = theme.brightness == Brightness.light;
        return ChatListLook._(
          look: AppLook.abos,
          bg: light ? const Color(0xFFFFFFFF) : cs.surface,
          ink: light ? const Color(0xFF1D2D3E) : cs.onSurface,
          muted: light ? const Color(0xFF5B6B7D) : cs.onSurfaceVariant,
          search: light ? const Color(0xFFF0F2F5) : cs.surfaceContainerHighest,
          chip: light ? const Color(0xFFF0F2F5) : cs.surfaceContainerHighest,
          chipOn: light ? const Color(0xFFD9FDD3) : const Color(0xFF0A3D33),
          chipOnInk: light ? const Color(0xFF008069) : const Color(0xFFD9FDD3),
          divider: light ? const Color(0xFFE9EDEF) : cs.outlineVariant,
          ring: light ? const Color(0xFFFFFFFF) : cs.surface,
          unreadTime: light ? const Color(0xFF1DAA61) : const Color(0xFF21C063),
        );
    }
  }

  final AppLook look;

  /// পাতার পট — `--lbg`
  final Color bg;
  final Color ink;
  final Color muted;

  /// খোঁজার গোল ঘর — `--lsrch`
  final Color search;

  /// না-বাছা চিপ — `--lchip`; বাছা চিপ সবুজ ([chipOn], [chipOnInk])
  final Color chip;
  final Color chipOn;
  final Color chipOnInk;

  /// সারির নিচের দাগ — `--ldiv`
  final Color divider;

  /// অনলাইনের সবুজ বিন্দুর কিনারা — পটের রঙে
  final Color ring;

  /// না-পড়া সারির সময় — সবুজ, মোটা
  final Color unreadTime;

  bool get glass => look == AppLook.glass;

  /// WhatsApp-এর সবুজ — "নতুন বার্তা", পাঠান, টিক-ঘর
  static const Color green = Color(0xFF00A884);

  /// না-পড়ার গোনার বড়ি আর অনলাইনের বিন্দু
  static const Color badge = Color(0xFF25D366);

  /// পড়েছে — নীল দুই টিক
  static const Color read = Color(0xFF53BDEB);

  /// রঙিন পটের সাদা লেখা/চিহ্ন — দুই মোডেই
  static const Color onColour = Color(0xFFFFFFFF);
}

/// নাম থেকে স্থির রং — সার্ভার রং না দিলে (নমুনার ছয় রঙের ঘোরানো)
const List<Color> _fallbackColours = [
  Color(0xFFEA580C),
  Color(0xFF4338CA),
  Color(0xFF0F766E),
  Color(0xFFBE185D),
  Color(0xFF4D7C0F),
  Color(0xFF0E7490),
];

Color chatColour(String? hex, String name) {
  if (hex != null && hex.length == 7) {
    final v = int.tryParse(hex.substring(1), radix: 16);
    if (v != null) return Color(0xFF000000 | v);
  }
  final sum = name.runes.fold<int>(0, (s, r) => s + r);
  return _fallbackColours[sum % _fallbackColours.length];
}

/// গ্রুপের চিহ্ন — calc · tag · truck · users (অচেনা হলে users)
IconData groupIcon(String key) => switch (key) {
      'calc' => Symbols.calculate,
      'tag' => Symbols.sell,
      'truck' => Symbols.local_shipping,
      _ => Symbols.group,
    };

/// ⭐ গোল অবতার — মানুষের ছবি বা নামের প্রথম অক্ষর রঙিন ঢালে, অনলাইন হলে নিচে-ডানে সবুজ বিন্দু; গ্রুপ হলে তার চিহ্ন
/// (নমুনার `.av`, `.on-dot`)
class ChatAvatar extends StatelessWidget {
  const ChatAvatar({super.key, required this.size, required this.ring, this.person, this.thread});

  final double size;

  /// অনলাইনের বিন্দুর কিনারা
  final Color ring;
  final ChatPerson? person;
  final ChatThread? thread;

  @override
  Widget build(BuildContext context) {
    final t = thread;
    final p = person ?? t?.person;
    final group = t != null && t.isGroup;
    final name = p?.name ?? t?.title ?? '';
    final base = chatColour(group ? t.colour : (p?.colour ?? t?.colour), name);
    final top = Color.lerp(base, const Color(0xFFFFFFFF), 0.22)!;
    final letter = name.trim().isEmpty ? '?' : name.trim().characters.first;
    final photo = p?.photoUrl;
    Widget inner = group
        ? Icon(groupIcon(t.groupIcon), size: size * 0.55, color: ChatListLook.onColour)
        : Text(letter,
            textScaler: TextScaler.noScaling,
            style: TextStyle(fontSize: size * 0.36, fontWeight: FontWeight.w700, color: ChatListLook.onColour, height: 1.1));
    inner = Center(child: inner);
    final disc = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [top, base]),
      ),
      child: ClipOval(
        child: photo == null || group
            ? inner
            : Image(
                image: ResizeImage(FaceImage(photo), width: 150, policy: ResizeImagePolicy.fit),
                fit: BoxFit.cover,
                width: size,
                height: size,
                errorBuilder: (_, __, ___) => inner),
      ),
    );
    final online = !group && (p?.online ?? false);
    return ExcludeSemantics(
      child: SizedBox(
        width: size,
        height: size,
        child: Stack(clipBehavior: Clip.none, children: [
          disc,
          if (online)
            Positioned(
              right: 0,
              bottom: 0,
              child: Container(
                key: const ValueKey('chat-online-dot'),
                width: (size * 0.3).clamp(11.0, 20.0),
                height: (size * 0.3).clamp(11.0, 20.0),
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: ChatListLook.badge,
                  border: Border.all(color: ring, width: 2.5),
                ),
              ),
            ),
        ]),
      ),
    );
  }
}

/// ⭐ তালিকার সময়ের ঘর — আজ "10:42", গতকাল "গতকাল", এই সপ্তাহে "বুধ", তার আগে "18/11/2026"। ⓘ অঙ্ক ইংরেজিতে (মালিক,
/// ১৬ নভেম্বর ২০২৬)
String chatListTime(DateTime at, DateTime now, AppL10n l) {
  final day = DateTime(at.year, at.month, at.day);
  final today = DateTime(now.year, now.month, now.day);
  final days = today.difference(day).inDays;
  String two(int n) => n.toString().padLeft(2, '0');
  if (days <= 0) return '${two(at.hour)}:${two(at.minute)}';
  if (days == 1) return l.msgListYesterday;
  if (days < 7) {
    const names = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    return l.msgListWeekday(names[at.weekday - 1]);
  }
  return '${two(at.day)}/${two(at.month)}/${at.year}';
}

/// ভয়েস বার্তার দৈর্ঘ্য — "0:18"
String voiceLength(int ms) {
  final s = (ms / 1000).round();
  return '${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}';
}
