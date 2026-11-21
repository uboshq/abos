import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../config/app_config.dart';
import '../../locale/l10n.dart';
import '../../locale/language_switch.dart';
import '../../locale/locale_choice.dart';
import '../../privacy/lock_delay.dart';
import '../../router/profile_routes.dart';
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
  String version = AppConfig.appVersion,
}) =>
    showGeneralDialog<AvatarChoice>(
      context: context,
      barrierDismissible: true,
      barrierLabel: context.l10n.avatarMenuClose,
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
          version: version,
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
    this.version = AppConfig.appVersion,
  });

  final String name;

  /// "সেটিংস · সংস্করণ …"-এর সংখ্যা — অ্যাপের নিজের ধ্রুবক; পরীক্ষায় স্থির (সোনালি ছবি প্রতি সংস্করণে না ভাঙে)
  final String version;

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
    // ⓘ সার্ভার কী পারে (/me features) — Riverpod না থাকলে (একা-চলা টেস্ট) কিছুই চালু ধরা হয় না
    final features = ProfileRoutes.featuresOf(context);
    final words = context.l10n;

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
                // ⭐ সার্ভার ছবি বদল নিলে "প্রোফাইল ও ছবি বদল", নইলে "আমার প্রোফাইল" — অচল প্রতিশ্রুতি নয় (/me features)
                title: ProfileRoutes.profileTitle(features),
                onTap: () => pick(AvatarChoice.profile),
              ),
              line,
              if (canSwitchCompany) ...[
                _Item(
                  key: const ValueKey('avatar-company'),
                  icon: Symbols.domain,
                  title: words.avatarMenuSwitchCompany,
                  onTap: () => pick(AvatarChoice.company),
                ),
                line,
              ],
              const _ThemeRow(),
              line,
              // ⭐ চেহারা (ABOS · Liquid Glass · Material 3) মেনুতেই — মালিক, ২০ নভেম্বর ২০২৬: "চেহারা eta nai"; আগে কেবল প্রোফাইলের ভিতরে ছিল
              _Item(
                key: const ValueKey('avatar-look'),
                // ⓘ "রং"-এর প্যালেট নয় — দুই সারিতে একই চিহ্ন চোখে গুলিয়ে দেয়
                icon: Symbols.style,
                title: words.lookTitle,
                hint: words.lookProfileSubtitle(words.lookExpressive, words.lookGlass, words.lookAbos),
                onTap: () => ProfileRoutes.openLookFromMenu(context),
              ),
              line,
              // ⛔ ভাষার সারি লুকানো — সব লেখা ARB-তে না যাওয়া পর্যন্ত ([LanguageSwitch.ready])
              if (LanguageSwitch.shown) ...[
                const _LanguageRow(),
                line,
              ],
              ValueListenableBuilder<Duration>(
                valueListenable: LockDelay.instance.current,
                builder: (context, delay, _) => _Item(
                  key: const ValueKey('avatar-lock'),
                  icon: Symbols.lock,
                  title: words.avatarMenuPinAndFingerprint,
                  hint: words.avatarMenuLockAfter(LockDelay.label(delay.inMinutes)),
                  onTap: () => pick(AvatarChoice.lock),
                ),
              ),
              line,
              // ⭐ "আমার ফোনগুলো" কেবল যখন সার্ভারের দরজা আছে (/me features.my_phones) — মালিকের নিয়ম: অচল সারি নয়
              if (ProfileRoutes.showsPhones(features)) ...[
                _Item(
                  key: const ValueKey('avatar-devices'),
                  icon: Symbols.devices,
                  title: words.avatarMenuMyPhones,
                  onTap: () => ProfileRoutes.openPhonesFromMenu(context),
                ),
                line,
              ],
              _Item(
                key: const ValueKey('avatar-settings'),
                icon: Symbols.settings,
                // ⓘ সংস্করণ অ্যাপের নিজের ধ্রুবক থেকে ([AppConfig.appVersion], pubspec-এর সাথে বাঁধা) — হাতে লেখা নয়
                title: words.avatarMenuSettingsVersion(version),
                onTap: () => pick(AvatarChoice.settings),
              ),
              line,
              _Item(
                key: const ValueKey('avatar-logout'),
                icon: Symbols.power_settings_new,
                title: words.avatarMenuSignOut,
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
  const _Item({super.key, required this.icon, required this.title, this.hint, required this.onTap, this.color});

  final IconData icon;
  final String title;

  /// পর্দা-পাঠকের বাড়তি কথা (চোখে দেখা যায় না, নমুনার মতো সারি এক লাইনের)
  final String? hint;
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

  static String _label(BuildContext context, ThemeChoice t) => switch (t) {
        ThemeChoice.phone => context.l10n.avatarMenuThemePhone,
        ThemeChoice.light => context.l10n.avatarMenuThemeLight,
        ThemeChoice.dark => context.l10n.avatarMenuThemeDark,
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
            Text(context.l10n.avatarMenuColour, style: TextStyle(fontWeight: FontWeight.w700, color: c.menuInk)),
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
                    // ⛔ লেখা কখনো ছোট হয় না — আগে FittedBox(scaleDown) "অন্ধকার"-কে পাশের ঘরের চেয়ে ছোট আর সরু আঁকত
                    // (avatar_menu_light/dark, ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬)। জায়গা কম হলে শব্দের ফাঁকে দুই লাইনে ভাঙে; বড় লেখায়
                    // ঘরগুলো তো একটার নিচে একটা। ⓘ আইকন লেখার ভেতরে, ButtonSegment.icon-এ নয়: সেখানে দিলে Flutter ঘরের ফাঁক নিজে
                    // বসায় (বাঁয়ে ১২, ডানে ১৬, আইকনের পরে ৮) আর এই সারির ফাঁক মানে না — "অন্ধকার"-এর জন্য ২৮dp-ও থাকত না
                    label: _SegmentLabel(_label(context, t), icon: _icons[t], textKey: ValueKey('theme-${t.name}')),
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

/// ⭐ ভাষা: ফোনের মতো · বাংলা · English — চাপ দিলেই পুরো অ্যাপ বদলায় ([LocaleChoices]); [_ThemeRow]-এর হুবহু গড়ন।
/// ⓘ ভাষার নাম নিজের লিপিতে ("বাংলা", "English") — যে ভাষাই চলুক, মানুষ নিজের ভাষার নাম চেনে (Android আর iOS-এর নিয়ম)।
/// ⛔ সারিটা মেনুতে আসে কেবল [LanguageSwitch.shown] হলে।
class _LanguageRow extends StatelessWidget {
  const _LanguageRow();

  static String _label(BuildContext context, LocaleChoice t) => switch (t) {
        LocaleChoice.phone => context.l10n.avatarMenuLanguagePhone,
        LocaleChoice.bn => 'বাংলা', // l10n-ok: ভাষার নাম নিজের লিপিতে, সব ভাষায় একই
        LocaleChoice.en => 'English',
      };

  @override
  Widget build(BuildContext context) {
    final c = context.bar;
    final narrow = MediaQuery.textScalerOf(context).scale(14) > 14 * 1.3;
    return ColoredBox(
      key: const ValueKey('avatar-language'),
      color: c.menuRow,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Icon(Symbols.translate, color: c.menuInk, size: 20),
            const SizedBox(width: 10),
            Text(context.l10n.avatarMenuLanguage, style: TextStyle(fontWeight: FontWeight.w700, color: c.menuInk)),
          ]),
          const SizedBox(height: 6),
          ValueListenableBuilder<LocaleChoice>(
            valueListenable: LocaleChoices.instance.current,
            builder: (context, choice, _) => SegmentedButton<LocaleChoice>(
              key: const ValueKey('me-language'),
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
                for (final t in LocaleChoice.values)
                  ButtonSegment(
                    value: t,
                    // ⛔ "ফোনের মতো" আগে FittedBox-এ ছোট হত — এখন পাশের ঘরের সমান মাপে, দরকারে দুই লাইনে ([_SegmentLabel])
                    label: _SegmentLabel(_label(context, t), textKey: ValueKey('language-${t.name}')),
                  ),
              ],
              selected: {choice},
              onSelectionChanged: (picked) => LocaleChoices.instance.choose(picked.single),
            ),
          ),
        ]),
      ),
    );
  }
}

/// ⭐ রং আর ভাষার ঘরের লেখা — সব ঘরে একই মাপ, কখনো ছোট করা নয়; জায়গা কম হলে শব্দের ফাঁকে দুই লাইনে, মাঝখানে
class _SegmentLabel extends StatelessWidget {
  const _SegmentLabel(this.text, {this.icon, this.textKey});

  final String text;

  /// লেখার আগে ছোট আইকন (☀ আলো, 🌙 অন্ধকার) — না থাকলে কেবল লেখা
  final IconData? icon;

  /// লেখার নিজের চাবি — টেস্ট লেখার মাপ পড়ে
  final Key? textKey;

  @override
  Widget build(BuildContext context) {
    final label = Text(text, key: textKey, maxLines: 2, textAlign: TextAlign.center);
    if (icon == null) return label;
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 16),
      const SizedBox(width: 4),
      Flexible(child: label),
    ]);
  }
}

/// ⭐ 'আমি' পাতার রঙ বাছাই — ছবির মেনুর একই সারি (মালিক, ১৪ নভেম্বর ২০২৬: ছবি নিচের বারে, তাই মেনুর কাজ 'আমি' পাতায়)
class MeThemeRow extends StatelessWidget {
  const MeThemeRow({super.key});

  @override
  Widget build(BuildContext context) => const _ThemeRow();
}

/// ⭐ 'আমি' পাতার ভাষা বাছাই — ছবির মেনুর একই সারি; ⛔ দেখায় কেবল [LanguageSwitch.shown] হলে
class MeLanguageRow extends StatelessWidget {
  const MeLanguageRow({super.key});

  @override
  Widget build(BuildContext context) => LanguageSwitch.shown ? const _LanguageRow() : const SizedBox.shrink();
}
