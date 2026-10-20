import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../config/app_config.dart';
import '../../privacy/lock_delay.dart';
import '../../theme/bar_palette.dart';
import '../../theme/theme_choice.dart';

/// ছবির মেনু থেকে যা বাছা হলো — রং ছাড়া (রং মেনুর ভেতরেই বদলায়, মেনু খোলা থাকে, পুরো অ্যাপ সাথে সাথে বদলায়)
enum AvatarChoice { profile, company, lock, settings, logout }

/// ⭐ ডান কোণের ছবিতে চাপলে নিজের মেনু — মালিকের অনুমোদিত পুরো-পাতার নমুনা, পাতা ২, তৃতীয় ফোন (২০ অক্টোবর ২০২৬):
/// বারের ঠিক নিচে ডান দিকে ভাসমান কার্ড, পেছনটা আবছা; Google, Microsoft আর SAP-এর মতো রঙের পছন্দ এখানেই।
///
/// <p>[top] — বারের নিচের কিনারা, পর্দার হিসাবে ([AbosTopBar.onAvatar] দেয়)। বাইরে চাপলে বা "পিছনে" দিলে বন্ধ।
Future<AvatarChoice?> showAvatarMenu(
  BuildContext context, {
  required double top,
  required String name,
  String? designation,
  required String branch,
  ImageProvider? avatar,
  bool canSwitchCompany = false,
}) =>
    showGeneralDialog<AvatarChoice>(
      context: context,
      barrierDismissible: true,
      barrierLabel: 'মেনু বন্ধ',
      // ⓘ আবছা কেবল বারের নিচে (নমুনা) — বার, কোম্পানি-শাখা আর ছবির কমলা দাগ পরিষ্কার থাকে; পর্দা নিচে [_Anchored]-এ
      barrierColor: const Color(0x00000000), // dark-ok
      transitionDuration: const Duration(milliseconds: 150),
      pageBuilder: (ctx, _, __) => _Anchored(
        top: top,
        menu: AvatarMenu(
          name: name,
          designation: designation,
          branch: branch,
          avatar: avatar,
          canSwitchCompany: canSwitchCompany,
        ),
      ),
      transitionBuilder: (ctx, a, _, child) => FadeTransition(
        opacity: CurvedAnimation(parent: a, curve: Curves.easeOut),
        child: ScaleTransition(
          alignment: Alignment.topRight,
          scale: Tween(begin: 0.92, end: 1.0).animate(CurvedAnimation(parent: a, curve: Curves.easeOut)),
          child: child,
        ),
      ),
    );

/// বারের নিচে, ডানে ১০dp ফাঁক রেখে; পর্দার চেয়ে লম্বা হলে ভেতরে সরে
class _Anchored extends StatelessWidget {
  const _Anchored({required this.top, required this.menu});

  final double top;
  final Widget menu;

  @override
  Widget build(BuildContext context) {
    final size = MediaQuery.sizeOf(context);
    final bottom = MediaQuery.paddingOf(context).bottom + 8;
    final card = Padding(
      padding: EdgeInsets.fromLTRB(10, top + 4, 10, bottom),
      child: Align(
        alignment: AlignmentDirectional.topEnd,
        child: ConstrainedBox(
          // ⓘ নমুনায় ২৭০dp; বড় লেখায় একটু চওড়া হয়, ফোনের চওড়ার বাইরে কখনো নয়
          constraints: BoxConstraints(
            maxWidth: (MediaQuery.textScalerOf(context).scale(270)).clamp(270.0, size.width - 20),
            maxHeight: size.height - top - 4 - bottom,
          ),
          child: menu,
        ),
      ),
    );
    return Stack(children: [
      Positioned(
        left: 0,
        right: 0,
        top: top,
        bottom: 0,
        child: const IgnorePointer(child: ColoredBox(key: ValueKey('avatar-dim'), color: Color(0x59000000))), // dark-ok: নমুনার আবছা (কালো ৩৫%)
      ),
      Positioned.fill(child: card),
    ]);
  }
}

class AvatarMenu extends StatelessWidget {
  const AvatarMenu({
    super.key,
    required this.name,
    this.designation,
    required this.branch,
    this.avatar,
    this.canSwitchCompany = false,
  });

  final String name;

  /// পদবি — রোল নয় (মালিক, ২ অক্টোবর ২০২৬); না থাকলে কেবল শাখা
  final String? designation;
  final String branch;
  final ImageProvider? avatar;
  final bool canSwitchCompany;

  @override
  Widget build(BuildContext context) {
    final c = context.bar;
    void pick(AvatarChoice choice) => Navigator.of(context).pop(choice);
    final sub = [
      if (designation != null && designation!.trim().isNotEmpty) designation!.trim(),
      if (branch.trim().isNotEmpty) branch.trim(),
    ].join(' · ');
    final line = Divider(height: 1, thickness: 1, color: c.menuLine);

    return Material(
      key: const ValueKey('avatar-menu'),
      color: c.menu,
      elevation: 6,
      shadowColor: const Color(0x47000000), // dark-ok: নমুনার ছায়া
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: c.menuLine)),
      clipBehavior: Clip.antiAlias,
      child: DefaultTextStyle.merge(
        style: TextStyle(color: c.menuInk, fontSize: 14),
        child: IconTheme.merge(
          data: IconThemeData(color: c.menuInk, size: 20),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              MergeSemantics(
                child: Padding(
                  key: const ValueKey('avatar-header'),
                  padding: const EdgeInsets.all(12),
                  child: Row(children: [
                    _BigAvatar(name: name, image: avatar),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                        if (sub.isNotEmpty) Text(sub, style: TextStyle(color: c.menuSub, fontSize: 12.5)),
                      ]),
                    ),
                  ]),
                ),
              ),
              line,
              _Item(
                key: const ValueKey('avatar-profile'),
                icon: Symbols.person,
                title: 'প্রোফাইল ও ছবি বদল',
                onTap: () => pick(AvatarChoice.profile),
              ),
              line,
              if (canSwitchCompany) ...[
                _Item(
                  key: const ValueKey('avatar-company'),
                  icon: Symbols.domain,
                  title: 'কোম্পানি বদল',
                  onTap: () => pick(AvatarChoice.company),
                ),
                line,
              ],
              const _ThemeRow(),
              line,
              ValueListenableBuilder<Duration>(
                valueListenable: LockDelay.instance.current,
                builder: (context, delay, _) => _Item(
                  key: const ValueKey('avatar-lock'),
                  icon: Symbols.lock,
                  title: 'পিন ও আঙুলের ছাপ',
                  hint: 'তালার সময়: ${LockDelay.label(delay.inMinutes)}',
                  onTap: () => pick(AvatarChoice.lock),
                ),
              ),
              line,
              // ⓘ কোন কোন ফোনে চালু — সার্ভারের অংশ এখনো নেই; বানানো ডাক নয়, তাই এখন বন্ধ, কারণ লেখা
              const _Item(
                key: ValueKey('avatar-devices'),
                icon: Symbols.smartphone,
                title: 'আমার ফোনগুলো',
                note: 'শীঘ্রই: কোন কোন ফোনে চালু, এখানে দেখা যাবে',
                onTap: null,
              ),
              line,
              _Item(
                key: const ValueKey('avatar-settings'),
                icon: Symbols.settings,
                title: 'সেটিংস · সংস্করণ ${AppConfig.appVersion}',
                onTap: () => pick(AvatarChoice.settings),
              ),
              line,
              _Item(
                key: const ValueKey('avatar-logout'),
                icon: Symbols.power_settings_new,
                title: 'বের হওয়া',
                color: c.danger,
                onTap: () => pick(AvatarChoice.logout),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

class _BigAvatar extends StatelessWidget {
  const _BigAvatar({required this.name, this.image});

  final String name;
  final ImageProvider? image;

  @override
  Widget build(BuildContext context) {
    final c = context.bar;
    final letter = name.trim().isEmpty ? '?' : name.trim().characters.first;
    final initial = Center(
      child: Text(letter,
          textScaler: TextScaler.noScaling,
          style: TextStyle(fontWeight: FontWeight.w700, fontSize: 19, color: c.onAvatar, height: 1.1)),
    );
    return ExcludeSemantics(
      child: Container(
        width: 44,
        height: 44,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [c.avatarFrom, c.avatarTo]),
        ),
        child: ClipOval(
          child: image == null
              ? initial
              : Image(image: image!, fit: BoxFit.cover, errorBuilder: (_, __, ___) => initial),
        ),
      ),
    );
  }
}

/// এক সারি — আইকন আর লেখা; চাপার জায়গা অন্তত ৪৮dp (WCAG 2.5.8)
class _Item extends StatelessWidget {
  const _Item({super.key, required this.icon, required this.title, this.hint, this.note, required this.onTap, this.color});

  final IconData icon;
  final String title;

  /// পর্দা-পাঠকের বাড়তি কথা (চোখে দেখা যায় না, নমুনার মতো সারি এক লাইনের)
  final String? hint;

  /// বন্ধ সারির কারণ — চোখেও দেখা যায়
  final String? note;
  final VoidCallback? onTap;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = context.bar;
    final enabled = onTap != null;
    final ink = color ?? (enabled ? c.menuInk : c.menuSub);
    return Semantics(
      button: true,
      enabled: enabled,
      hint: hint,
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 48),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            child: Row(children: [
              Icon(icon, color: ink, size: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                  Text(title, style: TextStyle(color: ink, fontSize: 14)),
                  if (note != null) Text(note!, style: TextStyle(color: c.menuSub, fontSize: 12)),
                ]),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

/// রং: ফোনের মতো · আলো · অন্ধকার — চাপ দিলেই পুরো অ্যাপ বদলায় ([ThemeChoices]); বাছা ঘর রঙিন পটে আর মোটা লেখায়
/// (শুধু রঙে নয়, WCAG 1.4.1)। বড় লেখায় তিন ঘর পাশাপাশি না ধরলে একটার নিচে একটা।
class _ThemeRow extends StatelessWidget {
  const _ThemeRow();

  static const Map<ThemeChoice, IconData> _icons = {
    ThemeChoice.light: Symbols.light_mode,
    ThemeChoice.dark: Symbols.dark_mode,
  };

  @override
  Widget build(BuildContext context) {
    final c = context.bar;
    final narrow = MediaQuery.textScalerOf(context).scale(14) > 14 * 1.3;
    return ColoredBox(
      key: const ValueKey('avatar-theme'),
      color: c.menuRow,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Icon(Symbols.palette, color: c.menuInk, size: 20),
            const SizedBox(width: 10),
            Text('রং', style: TextStyle(fontWeight: FontWeight.w700, color: c.menuInk)),
          ]),
          const SizedBox(height: 6),
          ValueListenableBuilder<ThemeChoice>(
            valueListenable: ThemeChoices.instance.current,
            builder: (context, choice, _) => SegmentedButton<ThemeChoice>(
              key: const ValueKey('me-theme'),
              direction: narrow ? Axis.vertical : Axis.horizontal,
              showSelectedIcon: false,
              style: ButtonStyle(
                visualDensity: VisualDensity.standard,
                tapTargetSize: MaterialTapTargetSize.padded,
                minimumSize: const WidgetStatePropertyAll(Size(0, 48)),
                padding: const WidgetStatePropertyAll(EdgeInsets.symmetric(horizontal: 6)),
                side: WidgetStatePropertyAll(BorderSide(color: c.menuLine)),
                backgroundColor: WidgetStateProperty.resolveWith(
                    (s) => s.contains(WidgetState.selected) ? c.selected : c.menu),
                foregroundColor: WidgetStateProperty.resolveWith(
                    (s) => s.contains(WidgetState.selected) ? c.onSelected : c.menuInk),
                textStyle: WidgetStateProperty.resolveWith((s) => (Theme.of(context).textTheme.labelLarge ?? const TextStyle()).copyWith(
                    fontSize: 12.5, fontWeight: s.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w400)),
              ),
              segments: [
                for (final t in ThemeChoice.values)
                  ButtonSegment(
                    value: t,
                    // ⓘ নমুনার মতো: "ফোনের মতো · ☀ আলো · 🌙 অন্ধকার" — বাছা ঘর রঙিন পট আর মোটা লেখায় (শুধু রঙে নয়, WCAG 1.4.1)
                    icon: _icons[t] == null ? null : Icon(_icons[t], size: 16),
                    // ⓘ শব্দ মাঝখানে ভাঙে না — জায়গা কম হলে একটু ছোট হয় (বড় লেখায় তো ঘরগুলো একটার নিচে একটা)
                    label: FittedBox(
                      fit: BoxFit.scaleDown,
                      child: Text(t.label, key: ValueKey('theme-${t.name}'), maxLines: 1, softWrap: false),
                    ),
                  ),
              ],
              selected: {choice},
              onSelectionChanged: (picked) => ThemeChoices.instance.choose(picked.single),
            ),
          ),
        ]),
      ),
    );
  }
}
