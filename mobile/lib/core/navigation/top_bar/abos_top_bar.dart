import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../config/app_config.dart';
import '../../locale/app_locale.dart';
import '../../locale/l10n.dart';
import '../../sync_engine/sync_engine.dart';
import '../../sync_engine/sync_status.dart';
import '../../theme/bar_palette.dart';
import '../../widgets/unsaved_guard.dart';
import '../../../features/copilot/copilot_mark.dart';

/// ⭐ উপরের বার — মালিকের চূড়ান্ত নকশা, সংস্করণ ৩ (২০ অক্টোবর ২০২৬; Material 3 top app bar, Apple HIG, SAP Fiori shell
/// bar, WCAG 2.2 AA)।
///
/// <p>বাঁয়ে একটা বোতাম (≡ পুরো মেনু, ভেতরের পাতায় ← পিছনে); মাঝে প্রথম লাইনে সবসময় "কোম্পানি · শাখা ▾", দ্বিতীয় লাইনে
/// "› মডিউল › পাতা" (হোমে নেই); ডানে সর্বোচ্চ তিনটা — খোঁজা, বিজ্ঞপ্তি (গোনাসহ), নিজের ছবি।
///
/// <p>⛔ লম্বা নাম: কেবল কোম্পানির নাম "…" দিয়ে ছোট হয়; শাখা আর মডিউল কখনো কাটে না — জায়গা না হলে শাখা নিচের লাইনে নামে
/// আর বার লম্বা হয় (২০০% লেখাতেও)। আঙুল চেপে রাখলে পুরো নাম।
///
/// <p>⭐ নেট না থাকলে বারের ঠিক নিচে হলুদ ফিতা, পুরো বাক্যে (Material 3 banner)। ডেমো সার্ভারে বারের রং আলাদা, সাথে "ডেমো"।
class AbosTopBar extends StatefulWidget implements PreferredSizeWidget {
  const AbosTopBar({
    super.key,
    required this.company,
    required this.branch,
    this.companyCode = '',
    this.fallbackTitle = '',
    this.crumbs = const [],
    this.leading = TopBarLeading.menu,
    this.onLeading,
    this.onOrgTap,
    this.onSearch,
    this.onCopilot,
    this.onBell,
    this.unread,
    this.news = false,
    required this.avatarName,
    this.avatar,
    required this.onAvatar,
    this.menuOpen = false,
    this.showAvatar = true,
    this.demo,
    this.syncStatus,
    this.pendingCount,
    this.screenActions = const [],
    this.bottom,
  });

  /// কোম্পানির নাম — খালি হলে [fallbackTitle] (নাম এখনো জানা যায়নি)
  final String company;

  /// শাখা — "সব শাখা" বা একটা শাখার নাম
  final String branch;

  /// কোম্পানির ছোট নাম ("ADI") — পুরো নাম মাঝের জায়গায় না ধরলে এটা দেখায় (মাপে, আন্দাজে নয়); পুরো নাম তখনো
  /// আঙুল চেপে রাখলে আর পর্দা-পাঠকে
  final String companyCode;
  final String fallbackTitle;

  /// "› মডিউল › পাতা" — হোমে খালি
  final List<String> crumbs;

  final TopBarLeading leading;
  final VoidCallback? onLeading;

  /// শাখা বদলের তালিকা — null মানে বদলানোর কিছু নেই (এক শাখা), তখন ▾ নেই, চাপ নেই
  final VoidCallback? onOrgTap;
  final VoidCallback? onSearch;

  /// ⭐ জীবন্ত মগজ — খোঁজার আগে; null মানে কোপাইলটের চাবি নেই, বোতামও নেই (মালিক, ১২ নভেম্বর ২০২৬)
  final VoidCallback? onCopilot;
  final VoidCallback? onBell;

  /// না-পড়া বিজ্ঞপ্তি — null বা ০ হলে গোনা নেই
  final int? unread;

  /// গোনা ছাড়া নতুন কিছু — ছোট লাল বিন্দু
  final bool news;

  final String avatarName;

  /// ⭐ নিজের ছবি বারে — অ্যাপের পর্দায় false: ছবি নিচের বারের "আমি" ঘরে (মালিক, ১৩ নভেম্বর ২০২৬; Material 3 — উপরে ডানে
  /// সর্বোচ্চ তিনটা আইকন: কোপাইলট, খোঁজা, ঘণ্টা)
  final bool showAvatar;

  final ImageProvider? avatar;

  /// ছবিতে চাপ — বারের নিচের কিনারা (পর্দার হিসাবে) সাথে আসে, মেনু ঠিক তার নিচে বসে ([showAvatarMenu])
  final ValueChanged<double> onAvatar;

  /// মেনু খোলা — ছবির চারপাশে কমলা দাগ (নমুনা পাতা ২)
  final bool menuOpen;

  /// ডেমো সার্ভার — null হলে [AppConfig.apiBaseUrl] দেখে ([isDemoServer])
  final bool? demo;

  /// পরীক্ষার জন্য — অ্যাপে [SyncStatus.instance] আর [SyncEngine.pendingCount]
  final SyncStatus? syncStatus;
  final int Function()? pendingCount;

  /// ⭐ পর্দার নিজের বোতাম (ছাঁকনি, ছাপা, ভাগ …) — ডান কোণের তিনটার পাশে নয় (Material 3: সর্বোচ্চ ৩), দ্বিতীয় সারিতে
  /// "› মডিউল › পাতা"-র ডান প্রান্তে; দুটোর বেশি হলে বাকিগুলো "⋮"-এ ([ScreenActionsRow])। খালি হলে দ্বিতীয় লাইন হোমের মতো।
  final List<Widget> screenActions;

  /// বারের নিচে (যেমন TabBar) — বারের রঙেই, লেখা বারের লেখার রঙে
  final PreferredSizeWidget? bottom;

  /// ⓘ Scaffold বারকে এর বেশি উঁচু হতে দেয় না — বার নিজের লেখা মেপে এর ভেতরে যত দরকার তত উঁচু হয় (২০০% লেখায় দুই-তিন
  /// লাইন, নিচে অফলাইনের ফিতা)। এটা ছাদ, বারের আসল উচ্চতা নয়।
  static const double maxHeight = 480;

  /// বারের সারির নিচের মাপ — নমুনার ৫৮dp (৪৮dp চাপার জায়গা, দুই পাশে ৪dp, আরো ২dp শ্বাস)
  static const double rowHeight = 58;

  @override
  Size get preferredSize => const Size.fromHeight(maxHeight);

  @override
  State<AbosTopBar> createState() => _AbosTopBarState();
}

enum TopBarLeading { menu, back }

/// আসল খাতার সার্ভার `erp.adi.com.bd`; অন্য যেকোনো ঠিকানা ডেমো/পরীক্ষার
bool isDemoServer([String? baseUrl]) => Uri.tryParse(baseUrl ?? AppConfig.apiBaseUrl)?.host != 'erp.adi.com.bd';

/// বাংলা অঙ্ক — পর্দা-পাঠকের বাংলা বাক্যে; ইংরেজিতে ([locale] বা [AppLocale.current]) অঙ্ক যেমন আছে
String bnDigits(Object value, [Locale? locale]) => AppLocale.isEnglish(locale)
    ? value.toString()
    : value.toString().split('').map((c) => '0123456789'.contains(c) ? '০১২৩৪৫৬৭৮৯'['0123456789'.indexOf(c)] : c).join(); // l10n-ok: the digit table itself

/// ঘণ্টার গোনা — ১ থেকে ৯৯, তার বেশি "99+"; ০ বা অজানা হলে null (Material 3 badge)।
/// ⓘ অঙ্ক ইংরেজিতে — মালিকের অনুমোদিত পুরো-পাতার নমুনা ("21", "3"; ২০ অক্টোবর ২০২৬), টাকার অঙ্কের মতোই;
/// পর্দা-পাঠক বাংলায় বলে ([bellLabel])।
String? badgeText(int? count) {
  if (count == null || count <= 0) return null;
  return count > 99 ? '99+' : '$count';
}

/// পর্দা-পাঠকের কথা: "বিজ্ঞপ্তি, ৩টা না-পড়া" — ইংরেজিতে "Notifications, 3 unread" ([locale] না দিলে [AppLocale.current])
String bellLabel(int? unread, {bool news = false, Locale? locale}) {
  final words = lookupAppL10n(locale ?? AppLocale.current);
  if (unread != null && unread > 0) return words.topBarBellUnread(bnDigits(unread, locale ?? AppLocale.current));
  return news ? words.topBarBellNews : words.topBarBell;
}

/// পর্দা-পাঠকের কথা: "ADI Wholesale, ময়মনসিংহ শাখা, বিক্রয়"
String titleLabel(String company, String branch, List<String> crumbs, {bool demo = false}) {
  final b = branch.trim();
  final l = AppLocale.l10n;
  // ⓘ সার্ভারের বাংলা নাম নিজেই "… শাখা" হলে আর জোড়া নয়
  final saysBranch = b.endsWith('শাখা') || b.toLowerCase().endsWith('branch'); // l10n-ok: reads the server's branch name
  return [
    if (company.trim().isNotEmpty) company.trim(),
    if (b.isNotEmpty) saysBranch ? b : l.topBarBranchSpoken(b),
    ...crumbs,
    if (demo) l.topBarDemoSpoken,
  ].join(', ');
}

/// অফলাইনের ফিতার বাক্য
String offlineSentence(int pending) => pending > 0 ? AppLocale.l10n.topBarOfflinePending(pending, bnDigits(pending)) : AppLocale.l10n.topBarOfflineNoNet;

/// ⭐ শাখা বা কোম্পানি বদলের আগে — কোথাও আধা-লেখা ফর্ম থাকলে আগে জিজ্ঞেস ([UnsavedGuard.anyDirty])।
/// `true` হলে বদলানো চলে।
Future<bool> mayLeaveUnsaved(BuildContext context) async {
  if (!UnsavedGuard.anyDirty) return true;
  final answer = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      key: const ValueKey('top-unsaved'),
      icon: const Icon(Symbols.edit_note),
      title: Text(ctx.l10n.topBarUnsavedTitle),
      content: Text(ctx.l10n.topBarUnsavedBody),
      actions: [
        TextButton(
          key: const ValueKey('top-unsaved-stay'),
          onPressed: () => Navigator.of(ctx).pop(false),
          child: Text(ctx.l10n.topBarUnsavedStay),
        ),
        FilledButton(
          key: const ValueKey('top-unsaved-go'),
          onPressed: () => Navigator.of(ctx).pop(true),
          child: Text(ctx.l10n.topBarUnsavedGo),
        ),
      ],
    ),
  );
  return answer ?? false;
}

class _AbosTopBarState extends State<AbosTopBar> {
  ScrollNotificationObserverState? _observer;
  bool _scrolledUnder = false;
  final GlobalKey _barKey = GlobalKey();

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _observer?.removeListener(_onScroll);
    _observer = ScrollNotificationObserver.maybeOf(context);
    _observer?.addListener(_onScroll);
  }

  @override
  void dispose() {
    _observer?.removeListener(_onScroll);
    _observer = null;
    super.dispose();
  }

  /// ⭐ নিচে নামালে বার জায়গায় থাকে, নিচে ছায়া পড়ে — কোম্পানি আর শাখা কখনো লুকায় না (Material 3 scroll behavior)
  void _onScroll(ScrollNotification n) {
    if (n is! ScrollUpdateNotification || n.depth != 0) return;
    final m = n.metrics;
    if (m.axis != Axis.vertical) return;
    final under = m.extentBefore > 0;
    if (under != _scrolledUnder) setState(() => _scrolledUnder = under);
  }

  Future<void> _tapOrg() async {
    if (!await mayLeaveUnsaved(context)) return;
    widget.onOrgTap?.call();
  }

  void _tapAvatar() {
    final box = _barKey.currentContext?.findRenderObject() as RenderBox?;
    final bottom = box == null || !box.hasSize ? MediaQuery.paddingOf(context).top + AbosTopBar.rowHeight : box.localToGlobal(Offset(0, box.size.height)).dy;
    widget.onAvatar(bottom);
  }

  /// শাখা (আর ▾) আর মডিউলের লাইনের প্রতিটা শব্দ [width]-এ ধরে কি না — না ধরলে শিরোনাম নিজের সারিতে নামে
  bool _titleFits(BuildContext context, double width) {
    final base = Theme.of(context).textTheme.bodyMedium ?? const TextStyle();
    final scaler = MediaQuery.textScalerOf(context);
    double measure(String s, TextStyle style) {
      final p = TextPainter(text: TextSpan(text: s, style: base.merge(style)), textDirection: Directionality.of(context), textScaler: scaler, maxLines: 1)
        ..layout();
      final w = p.width;
      p.dispose();
      return w;
    }

    const line1 = TextStyle(fontSize: 15, fontWeight: FontWeight.w700);
    const line2 = TextStyle(fontSize: 12, fontWeight: FontWeight.w500);
    final branch = widget.branch.trim();
    if (branch.isNotEmpty && measure(branch, line1) + (widget.onOrgTap == null ? 0 : 20) > width) return false;
    for (final crumb in widget.crumbs) {
      for (final word in '› $crumb'.split(' ')) {
        if (measure(word, line2) > width) return false;
      }
    }
    return true;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final demo = widget.demo ?? isDemoServer();
    final colors = TopBarColors.of(context, demo: demo);
    final fg = colors.foreground;

    final bar = Material(
      key: _barKey,
      color: colors.background,
      surfaceTintColor: Colors.transparent,
      shadowColor: theme.shadowColor,
      elevation: _scrolledUnder ? 3 : 0,
      child: SafeArea(
        bottom: false,
        child: ConstrainedBox(
          key: const ValueKey('top-bar'),
          constraints: const BoxConstraints(minHeight: AbosTopBar.rowHeight),
          child: IconTheme.merge(
            data: IconThemeData(color: fg),
            child: LayoutBuilder(builder: (context, box) {
              final leading = IconButton(
                key: const ValueKey('top-leading'),
                tooltip: widget.leading == TopBarLeading.menu ? context.l10n.topBarFullMenu : context.l10n.topBarBack,
                color: fg,
                onPressed: widget.onLeading,
                icon: Icon(widget.leading == TopBarLeading.menu ? Symbols.menu : Symbols.arrow_back),
              );
              final title = _Title(
                company: widget.company,
                code: widget.companyCode,
                branch: widget.branch,
                fallback: widget.fallbackTitle,
                crumbs: widget.crumbs,
                demo: demo,
                colors: colors,
                line2: widget.screenActions.isEmpty,
                onTap: widget.onOrgTap == null ? null : _tapOrg,
              );
              final actions = [
                if (widget.onCopilot != null)
                  IconButton(
                    key: const ValueKey('top-copilot'),
                    tooltip: context.l10n.copilotQuickOpen,
                    onPressed: widget.onCopilot,
                    icon: const CopilotMark(size: 26),
                  ),
                IconButton(
                  key: const ValueKey('top-search'),
                  tooltip: context.l10n.topBarSearch,
                  color: fg,
                  onPressed: widget.onSearch,
                  icon: const Icon(Symbols.search),
                ),
                _Bell(unread: widget.unread, news: widget.news, onTap: widget.onBell, color: fg),
                if (widget.showAvatar)
                  IconButton(
                    key: const ValueKey('header-avatar'),
                    tooltip: context.l10n.topBarMyMenu,
                    padding: EdgeInsets.zero,
                    onPressed: _tapAvatar,
                    icon: AvatarDisc(name: widget.avatarName, image: widget.avatar, size: 36, ring: widget.menuOpen),
                  ),
              ];
              // ⭐ শাখার নাম মাঝের জায়গায় না ধরলে (ছোট ফোন, বড় লেখা) শিরোনাম আইকনের সারির নিচে পুরো চওড়ায় নামে —
              // Material 3-এর medium top app bar; শাখা কাটে না, বার লম্বা হয়
              final middle = box.maxWidth - 10 - 48 * (3 + (widget.onCopilot == null ? 0 : 1) + (widget.showAvatar ? 1 : 0)) - 8;
              final fits = _titleFits(context, middle);
              final rows = <Widget>[
                if (fits)
                  Row(children: [leading, Expanded(child: title), ...actions])
                else ...[
                  Row(children: [leading, const Spacer(), ...actions]),
                  Padding(padding: const EdgeInsets.symmetric(horizontal: 8), child: title),
                ],
                // ⭐ পর্দার বোতাম — "› মডিউল › পাতা"-র সারির ডান প্রান্তে
                if (widget.screenActions.isNotEmpty)
                  Padding(
                    key: const ValueKey('top-action-row'),
                    padding: EdgeInsetsDirectional.only(start: fits ? 52 : 12),
                    child: Row(children: [
                      Expanded(child: _Crumbs(crumbs: widget.crumbs, demo: demo, colors: colors)),
                      ScreenActionsRow(actions: widget.screenActions, color: fg),
                    ]),
                  ),
              ];
              return Padding(
                padding: const EdgeInsets.fromLTRB(4, 4, 6, 4),
                child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: rows),
              );
            }),
          ),
        ),
      ),
    );

    final bottom = widget.bottom;
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: ThemeData.estimateBrightnessForColor(colors.background) == Brightness.dark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark,
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        bar,
        if (bottom != null)
          Material(
            key: const ValueKey('top-bottom'),
            color: colors.background,
            child: Theme(
              data: theme.copyWith(
                tabBarTheme: theme.tabBarTheme.copyWith(
                  labelColor: fg,
                  unselectedLabelColor: colors.crumb,
                  indicatorColor: fg,
                  dividerColor: Colors.transparent,
                ),
              ),
              child: IconTheme.merge(data: IconThemeData(color: fg), child: bottom),
            ),
          ),
        OfflineRibbon(status: widget.syncStatus, pendingCount: widget.pendingCount),
      ]),
    );
  }
}

/// ⭐ নিজের ছবি — ছবি থাকলে ছবি, নইলে কমলা গোলে নামের প্রথম অক্ষর, চারপাশে সাদা দাগ; মেনু খোলা থাকলে বাইরে কমলা দাগ
/// (নমুনা পাতা ২)। অক্ষরটা প্রতীক — বড় লেখায় বাড়ে না, গোলের ভেতরেই থাকে।
class AvatarDisc extends StatelessWidget {
  const AvatarDisc({super.key, required this.name, this.image, this.size = 36, this.ring = false});

  final String name;
  final ImageProvider? image;
  final double size;
  final bool ring;

  @override
  Widget build(BuildContext context) {
    final c = context.bar;
    final letter = name.trim().isEmpty ? '?' : name.trim().characters.first;
    final initial = Center(
      child:
          Text(letter, textScaler: TextScaler.noScaling, style: TextStyle(fontWeight: FontWeight.w700, fontSize: size * 0.44, color: c.onAvatar, height: 1.1)),
    );
    final disc = Container(
      key: const ValueKey('avatar-disc'),
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [c.avatarFrom, c.avatarTo]),
        border: Border.all(color: c.onBar, width: 2),
      ),
      child: ClipOval(
        child: image == null ? initial : Image(image: image!, fit: BoxFit.cover, width: size, height: size, errorBuilder: (_, __, ___) => initial),
      ),
    );
    if (!ring) return disc;
    return DecoratedBox(
      key: const ValueKey('avatar-ring'),
      decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: c.avatarRing, width: 3)),
      child: disc,
    );
  }
}

/// বারের রং — থিমের [BarPalette] থেকে; ডেমোতে আলাদা বেগুনি, যাতে কেউ আসল খাতা ভেবে কাজ না করে
@immutable
class TopBarColors {
  const TopBarColors({required this.background, required this.foreground, required this.crumb, required this.pill, required this.onPill});

  final Color background;
  final Color foreground;

  /// দ্বিতীয় লাইন — লেখার রং একটু মলিন (নমুনার ৮৫%), তবু ৪.৫:১
  final Color crumb;
  final Color pill;
  final Color onPill;

  factory TopBarColors.of(BuildContext context, {required bool demo}) {
    final c = context.bar;
    final bg = demo ? c.demoBar : c.bar;
    final fg = demo ? c.onDemoBar : c.onBar;
    return TopBarColors(
      background: bg,
      foreground: fg,
      crumb: Color.alphaBlend(fg.withValues(alpha: 0.85), bg),
      pill: c.demoPill,
      onPill: c.onDemoPill,
    );
  }
}

class _Title extends StatelessWidget {
  const _Title({
    required this.company,
    required this.code,
    required this.branch,
    required this.fallback,
    required this.crumbs,
    required this.demo,
    required this.colors,
    this.line2 = true,
    required this.onTap,
  });

  final String company;
  final String code;

  /// দ্বিতীয় লাইন এখানে — পর্দার বোতাম থাকলে সেটা বোতামের সারিতে যায় ([_Crumbs]); পর্দা-পাঠক তবু পুরোটা বলে
  final bool line2;
  final String branch;
  final String fallback;
  final List<String> crumbs;
  final bool demo;
  final TopBarColors colors;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final named = company.trim().isNotEmpty;
    final first = named ? company.trim() : fallback;
    final full = [first, if (branch.trim().isNotEmpty) branch.trim()].join(' · ');
    final line1 = TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: colors.foreground, height: 1.25);

    final body = Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _OrgLine(company: first, code: named ? code.trim() : '', branch: branch.trim(), style: line1, chevron: onTap != null),
        if (line2 && (crumbs.isNotEmpty || demo)) Padding(padding: const EdgeInsets.only(top: 1), child: _Crumbs(crumbs: crumbs, demo: demo, colors: colors)),
      ],
    );

    final label = titleLabel(named ? first : '', branch, crumbs, demo: demo);
    return Semantics(
      key: const ValueKey('top-title'),
      container: true,
      label: label.isEmpty ? fallback : label,
      button: onTap != null,
      onTap: onTap,
      onTapHint: onTap == null ? null : context.l10n.topBarSwitchBranch,
      excludeSemantics: true,
      child: Tooltip(
        message: full,
        triggerMode: TooltipTriggerMode.longPress,
        excludeFromSemantics: true,
        child: InkWell(
          key: onTap == null ? null : const ValueKey('workspace-header'),
          onTap: onTap,
          borderRadius: BorderRadius.circular(8),
          child: ConstrainedBox(
            // ⓘ চাপার জায়গা অন্তত ৪৮dp উঁচু (WCAG 2.5.8, Material 3)
            constraints: const BoxConstraints(minHeight: 48),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 4),
              child: Align(alignment: AlignmentDirectional.centerStart, widthFactor: 1, child: body),
            ),
          ),
        ),
      ),
    );
  }
}

/// দ্বিতীয় লাইন: "ডেমো" চিহ্ন আর "› মডিউল › পাতা" — শব্দ ভাঙে না, দরকারে নিচের লাইনে নামে
class _Crumbs extends StatelessWidget {
  const _Crumbs({required this.crumbs, required this.demo, required this.colors});

  final List<String> crumbs;
  final bool demo;
  final TopBarColors colors;

  @override
  Widget build(BuildContext context) => Wrap(
        spacing: 6,
        runSpacing: 2,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          if (demo)
            DecoratedBox(
              key: const ValueKey('top-demo'),
              decoration: BoxDecoration(color: colors.pill, borderRadius: BorderRadius.circular(6)),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 6),
                child: Text(context.l10n.topBarDemo, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: colors.onPill, height: 1.3)),
              ),
            ),
          if (crumbs.isNotEmpty)
            Text('› ${crumbs.join(' › ')}',
                key: const ValueKey('top-crumbs'),
                softWrap: true,
                style: TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: colors.crumb, height: 1.25)),
        ],
      );
}

/// ⭐ পর্দার নিজের বোতাম — দুটো পর্যন্ত সরাসরি (৪৮dp, নিজের tooltip সহ); দুটোর বেশি হলে প্রথম দুটো সারিতে, বাকিগুলো "⋮"
/// মেনুতে (Material 3)। ⓘ মেনুতে যায় কেবল [IconButton] (তার আইকন, tooltip আর চাপ নিয়ে); অন্য ধরনের বোতাম ([TextButton]
/// …) সারিতেই থাকে, কখনো হারায় না।
class ScreenActionsRow extends StatelessWidget {
  const ScreenActionsRow({super.key, required this.actions, required this.color});

  final List<Widget> actions;
  final Color color;

  /// সারিতে সরাসরি কয়টা
  static const int shown = 2;

  @override
  Widget build(BuildContext context) {
    final fixed = [
      for (final a in actions)
        if (a is! IconButton) a
    ];
    final icons = [
      for (final a in actions)
        if (a is IconButton) a
    ];
    final overflow = actions.length > shown;
    final room = overflow ? (shown - fixed.length).clamp(0, shown) : icons.length;
    final direct = icons.take(room).toSet();
    final spill = icons.skip(room).toList();
    return Row(mainAxisSize: MainAxisSize.min, children: [
      for (final a in actions)
        if (a is! IconButton || direct.contains(a)) a,
      if (spill.isNotEmpty)
        PopupMenuButton<int>(
          key: const ValueKey('top-overflow'),
          tooltip: context.l10n.topBarMore,
          icon: Icon(Symbols.more_vert, color: color),
          itemBuilder: (_) => [
            for (var i = 0; i < spill.length; i++)
              PopupMenuItem<int>(
                value: i,
                enabled: spill[i].onPressed != null,
                child: Row(children: [
                  IconTheme.merge(data: const IconThemeData(size: 20), child: spill[i].icon),
                  const SizedBox(width: 12),
                  Flexible(child: Text(spill[i].tooltip ?? '')),
                ]),
              ),
          ],
          onSelected: (i) => spill[i].onPressed?.call(),
        ),
    ]);
  }
}

/// প্রথম লাইন: "কোম্পানি · শাখা ▾" — জায়গা হলে এক লাইনে (কোম্পানি "…"), না হলে শাখা নিচের লাইনে, পুরোটা
class _OrgLine extends StatelessWidget {
  const _OrgLine({required this.company, this.code = '', required this.branch, required this.style, required this.chevron});

  final String company;

  /// ছোট নাম — পুরো নাম না ধরলে
  final String code;
  final String branch;
  final TextStyle style;
  final bool chevron;

  static const double _chevron = 20;

  @override
  Widget build(BuildContext context) {
    final style = DefaultTextStyle.of(context).style.merge(this.style);
    final scaler = MediaQuery.textScalerOf(context);
    final arrow = chevron ? Icon(Symbols.arrow_drop_down, size: _chevron, color: style.color, key: const ValueKey('top-chevron')) : null;
    Text companyText(String text) =>
        Text(text, key: const ValueKey('top-company'), maxLines: 1, overflow: TextOverflow.ellipsis, softWrap: false, style: style);

    return LayoutBuilder(builder: (context, c) {
      double width(String s) {
        final p = TextPainter(
          text: TextSpan(text: s, style: style),
          textDirection: Directionality.of(context),
          textScaler: scaler,
          maxLines: 1,
        )..layout();
        final w = p.width;
        p.dispose();
        return w;
      }

      // ⭐ পুরো নাম ধরলে পুরো নাম, না ধরলে ছোট নাম ("ADI") — "ADI …"-এর মতো আধা নাম নয় (মালিক, ২০ অক্টোবর ২০২৬)
      String fit(double room) => width(company) <= room || code.isEmpty ? company : code;
      if (branch.isEmpty) {
        return Row(mainAxisSize: MainAxisSize.min, children: [
          Flexible(child: companyText(fit(c.maxWidth - (chevron ? _chevron : 0)))),
          if (arrow != null) arrow,
        ]);
      }
      final branchWidth = width(' · ') + width(branch) + (chevron ? _chevron : 0);
      final shown = fit(c.maxWidth - branchWidth);
      // কোম্পানির জন্য অন্তত দুই অক্ষর আর "…"
      final companyMin = width(shown == code ? code : '${company.characters.take(2)}…');
      final oneLine = c.maxWidth - branchWidth >= companyMin;
      final branchText = Text(branch, key: const ValueKey('top-branch'), softWrap: true, style: style);
      if (oneLine) {
        return Row(mainAxisSize: MainAxisSize.min, children: [
          Flexible(child: companyText(shown)),
          Text(' · ', style: style),
          branchText,
          if (arrow != null) arrow,
        ]);
      }
      return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        companyText(fit(c.maxWidth)),
        Row(mainAxisSize: MainAxisSize.min, children: [Flexible(child: branchText), if (arrow != null) arrow]),
      ]);
    });
  }
}

class _Bell extends StatelessWidget {
  const _Bell({required this.unread, required this.news, required this.onTap, required this.color});

  final int? unread;
  final bool news;
  final VoidCallback? onTap;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final c = context.bar;
    final text = badgeText(unread);
    final scale = MediaQuery.textScalerOf(context).scale(1).clamp(1.0, 1.3);
    return IconButton(
      key: const ValueKey('top-bell'),
      tooltip: bellLabel(unread, news: news),
      color: color,
      onPressed: onTap,
      icon: Badge(
        key: const ValueKey('top-bell-badge'),
        isLabelVisible: text != null || news,
        backgroundColor: c.badge,
        textColor: c.onBadge,
        largeSize: 15 * scale,
        smallSize: 8,
        textStyle: (Theme.of(context).textTheme.labelSmall ?? const TextStyle()).copyWith(fontSize: 10, fontWeight: FontWeight.w700),
        label: text == null
            ? null
            : MediaQuery.withClampedTextScaling(
                maxScaleFactor: 1.3,
                child: ExcludeSemantics(child: Text(text, key: const ValueKey('top-bell-count'))),
              ),
        child: const Icon(Symbols.notifications),
      ),
    );
  }
}

/// ⭐ নেট না থাকলে বারের ঠিক নিচে হলুদ ফিতা — আইকন নয়, পুরো বাক্য (Material 3 banner; WCAG 1.4.1; নমুনা পাতা ২)
class OfflineRibbon extends StatelessWidget {
  const OfflineRibbon({super.key, this.status, this.pendingCount});

  final SyncStatus? status;
  final int Function()? pendingCount;

  int _pending() {
    try {
      return (pendingCount ?? () => SyncEngine.instance.pendingCount)();
    } catch (_) {
      return 0;
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = status ?? SyncStatus.instance;
    return ListenableBuilder(
      listenable: s,
      builder: (context, _) {
        if (!s.offline) return const SizedBox.shrink();
        final c = context.bar;
        return Semantics(
          liveRegion: true,
          container: true,
          child: Material(
            key: const ValueKey('top-offline'),
            color: c.ribbon,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
              child: Text(offlineSentence(_pending()),
                  key: const ValueKey('top-offline-text'), style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: c.onRibbon, height: 1.3)),
            ),
          ),
        );
      },
    );
  }
}
