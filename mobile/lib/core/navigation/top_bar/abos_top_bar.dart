import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../config/app_config.dart';
import '../../locale/app_locale.dart';
import '../../locale/l10n.dart';
import '../../messenger/messenger_unread.dart';
import '../../sync_engine/sync_engine.dart';
import '../../sync_engine/sync_status.dart';
import '../../theme/app_look.dart';
import '../../theme/bar_palette.dart';
import '../../theme/expressive.dart';
import '../../widgets/unsaved_guard.dart';

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
    this.menuTooltip,
    this.onOrgTap,
    this.onSearch,
    this.onCopilot,
    this.onCreate,
    this.onBell,
    this.onMessages,
    this.messagesActive = false,
    this.unread,
    this.news = false,
    required this.avatarName,
    this.avatar,
    required this.onAvatar,
    this.menuOpen = false,
    this.leadingActive = false,
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

  /// ≡-এর নাম (লম্বা চাপ আর TalkBack) — না দিলে "পুরো মেনু"; হোমে "দ্রুত কাজ" (0.4.58)
  final String? menuTooltip;

  /// শাখা বদলের তালিকা — null মানে বদলানোর কিছু নেই (এক শাখা), তখন ▾ নেই, চাপ নেই
  final VoidCallback? onOrgTap;
  final VoidCallback? onSearch;

  /// ⭐ জীবন্ত মগজ — খোঁজার আগে; null মানে কোপাইলটের চাবি নেই, বোতামও নেই (মালিক, ১২ নভেম্বর ২০২৬)
  final VoidCallback? onCopilot;

  /// ⭐ ＋ তৈরি করুন — দ্রুত কাজের তালিকা; কেবল Liquid Glass আর Material 3-এর বারে, সবার আগে (মালিক, ২০ নভেম্বর ২০২৬ "Ok")।
  /// null মানে বোতাম নেই
  final VoidCallback? onCreate;
  final VoidCallback? onBell;

  /// ⭐ বার্তা — বার্তার তালিকা খোলে/বন্ধ করে (নমুনা 3nuwRUdn v5 "ক"); null = বার্তা চালু নেই (`/me`-র `features.messaging`
  /// false) বা হোম নেই — বোতামই নেই
  final VoidCallback? onMessages;

  /// বার্তার তালিকা খোলা — বার্তার বোতাম নীল ঘরে, ভরাট চিহ্নে (নমুনার `.ib.act`), লাল গোনা ছাড়া
  final bool messagesActive;

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

  /// ⭐ ≡ মেনুর পাতা খোলা (আইটেম ১০, নমুনা "ক" খ-ভাগ) — ≡ নীল, হালকা নীল ঘরে; আবার চাপলে বন্ধ
  final bool leadingActive;

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
                // ⓘ বন্ধ থাকলে null — আগের বোতামের হুবহু (false দিলে M3 "বাছাইযোগ্য" বোতাম হয়, পিক্সেল নড়ে)
                isSelected: widget.leadingActive ? true : null,
                style: widget.leadingActive
                    ? IconButton.styleFrom(
                        foregroundColor: const Color(0xFF3B82F6),
                        backgroundColor: const Color(0x243B82F6),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      )
                    : null,
                tooltip: widget.leading == TopBarLeading.menu
                    ? (widget.menuTooltip ?? context.l10n.topBarFullMenu)
                    : context.l10n.topBarBack,
                color: widget.leadingActive ? const Color(0xFF3B82F6) : fg,
                onPressed: widget.onLeading,
                icon: Icon(widget.leading == TopBarLeading.menu ? Symbols.menu : Symbols.arrow_back),
              );
              final title = _OrgBlock(
                company: widget.company.trim().isEmpty ? widget.fallbackTitle : widget.company.trim(),
                spokenCompany: widget.company.trim(),
                branch: widget.branch.trim(),
                crumbs: widget.screenActions.isEmpty ? widget.crumbs : const [],
                allCrumbs: widget.crumbs,
                demo: demo,
                colors: colors,
                onTap: widget.onOrgTap == null ? null : _tapOrg,
              );
              // ⭐ উপরের বার, তিন রূপেই (মালিক, ২০ নভেম্বর ২০২৬ "Ok", বারের নমুনা v8): ডানে ＋ · খোঁজা · বার্তা — সাদামাটা আইকন,
              // কোনো গোল বা বাক্স নয়, ডান কিনারায় গায়ে-গায়ে; প্রতিটার চাপার জায়গা ৪৮dp (অদৃশ্য)। ঘণ্টা আর Copilot নিচের বারে।
              final actions = <Widget>[
                if (widget.onCreate != null)
                  _PlainAction(
                      key: const ValueKey('top-create'),
                      tooltip: context.l10n.lookCreate,
                      icon: Symbols.add,
                      color: fg,
                      onPressed: widget.onCreate),
                _PlainAction(
                    key: const ValueKey('top-search'),
                    tooltip: context.l10n.topBarSearch,
                    icon: Symbols.search,
                    color: fg,
                    onPressed: widget.onSearch),
                if (widget.onMessages != null) _MessagesButton(color: fg, onPressed: widget.onMessages!, active: widget.messagesActive),
                if (widget.showAvatar)
                  IconButton(
                    key: const ValueKey('header-avatar'),
                    tooltip: context.l10n.topBarMyMenu,
                    padding: EdgeInsets.zero,
                    onPressed: _tapAvatar,
                    icon: AvatarDisc(name: widget.avatarName, image: widget.avatar, size: 36, ring: widget.menuOpen),
                  ),
              ];
              final rows = <Widget>[
                Row(children: [leading, Expanded(child: title), const SizedBox(width: 4), ...actions]),
                // ⭐ পর্দার বোতাম — "› মডিউল › পাতা"-র সারির ডান প্রান্তে
                if (widget.screenActions.isNotEmpty)
                  Padding(
                    key: const ValueKey('top-action-row'),
                    padding: const EdgeInsetsDirectional.only(start: 52),
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
  const AvatarDisc(
      {super.key,
      required this.name,
      this.image,
      this.size = 36,
      this.ring = false,
      this.edge = true,
      this.ringColor,
      this.ringWidth = 3});

  final String name;
  final ImageProvider? image;
  final double size;
  final bool ring;

  /// রিংয়ের রং আর মোটা — না দিলে বারের কমলা, ৩dp (নিচের বারে নীল ২dp; মালিক, ২০ নভেম্বর ২০২৬)
  final Color? ringColor;
  final double ringWidth;

  /// ⓘ উপরের বারের রঙে সরু কিনারা — গাঢ় উপরের বারে ছবি আলাদা করে; নিচের বারে নয় (সেখানে সাদা কিনারা অন্ধকার থিমে বাছা
  /// ঘরের রিংয়ের মতো দেখাত, ১৫ নভেম্বর ২০২৬)
  final bool edge;

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
        border: edge ? Border.all(color: c.onBar, width: 2) : null,
      ),
      child: ClipOval(
        child: image == null ? initial : Image(image: image!, fit: BoxFit.cover, width: size, height: size, errorBuilder: (_, __, ___) => initial),
      ),
    );
    if (!ring) return disc;
    return DecoratedBox(
      key: const ValueKey('avatar-ring'),
      decoration: BoxDecoration(shape: BoxShape.circle, border: Border.all(color: ringColor ?? c.avatarRing, width: ringWidth)),
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

/// ⭐ বাঁয়ে ≡-এর পরে দুই লাইন (মালিক, ২০ নভেম্বর ২০২৬ "Ok", নমুনা v7–v8): ওপরে কোম্পানির পুরো নাম (১৫, মোটা, এক লাইন —
/// কখনো ছোট নাম নয়; "…" কেবল শেষ উপায়), নিচে শাখা গোল চিপে (১১, আধা-মোটা, রূপের রঙে); ভেতরের পাতায় চিপের পাশে
/// "› মডিউল › পাতা"। চাপলে শাখা বদল (থাকলে, চিপে ▾); আঙুল চেপে রাখলে পুরো নাম; পর্দা-পাঠক পুরো বাক্য।
class _OrgBlock extends StatelessWidget {
  const _OrgBlock({
    required this.company,
    required this.spokenCompany,
    required this.branch,
    required this.crumbs,
    required this.allCrumbs,
    required this.demo,
    required this.colors,
    required this.onTap,
  });

  final String company;
  final String spokenCompany;
  final String branch;

  /// এখানে দেখানো "› মডিউল › পাতা" — পর্দার বোতাম থাকলে সেটা বোতামের সারিতে যায়, তখন খালি
  final List<String> crumbs;

  /// পর্দা-পাঠকের জন্য সবসময় পুরোটা
  final List<String> allCrumbs;
  final bool demo;
  final TopBarColors colors;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final fg = colors.foreground;
    final chip = TopBarChipColors.of(context, fg);
    final name = Text(
      company,
      key: const ValueKey('top-company'),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      softWrap: false,
      style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: fg, height: 1.2),
    );
    final branchChip = branch.isEmpty
        ? null
        : Container(
            key: const ValueKey('top-branch-chip'),
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 1),
            decoration: BoxDecoration(
              color: chip.fill,
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: chip.border),
            ),
            child: Row(mainAxisSize: MainAxisSize.min, children: [
              Flexible(
                child: Text(branch,
                    key: const ValueKey('top-branch'),
                    softWrap: true,
                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: chip.ink, height: 1.3)),
              ),
              if (onTap != null)
                Icon(Symbols.arrow_drop_down, key: const ValueKey('top-chevron'), size: 16, color: chip.ink),
            ]),
          );
    final second = [
      if (branchChip != null) branchChip,
      if (demo || crumbs.isNotEmpty) _Crumbs(crumbs: crumbs, demo: demo, colors: colors),
    ];
    final body = Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
      name,
      if (second.isNotEmpty) ...[
        const SizedBox(height: 3),
        Wrap(spacing: 6, runSpacing: 2, crossAxisAlignment: WrapCrossAlignment.center, children: second),
      ],
    ]);
    final full = [company, if (branch.isNotEmpty) branch].join(' · ');
    final label = titleLabel(spokenCompany, branch, allCrumbs, demo: demo);
    return Semantics(
      key: const ValueKey('top-title'),
      container: true,
      label: label.isEmpty ? company : label,
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
              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 4),
              child: Align(alignment: AlignmentDirectional.centerStart, widthFactor: 1, child: body),
            ),
          ),
        ),
      ),
    );
  }
}

/// শাখার চিপের রং — রূপের নিজের (নমুনা v7): ম্যাটেরিয়ালে secondaryContainer, লিকুইড গ্লাসে সাদা ১৪% আর ৪০% কিনারা,
/// ক্লাসিকে নিরপেক্ষ ধূসর আভা
@immutable
class TopBarChipColors {
  const TopBarChipColors({required this.fill, required this.border, required this.ink});

  final Color fill;
  final Color border;
  final Color ink;

  factory TopBarChipColors.of(BuildContext context, Color fg) => switch (AppLooks.instance.look.value) {
        AppLook.expressive => const TopBarChipColors(
            fill: Expressive.secondaryBox, border: Color(0xFFCBBFE0), ink: Color(0xFF1D192B)),
        AppLook.glass => TopBarChipColors(
            fill: Colors.white.withValues(alpha: 0.14), // dark-ok: কাচের চিপ, অন্ধকার পটে
            border: Colors.white.withValues(alpha: 0.40), // dark-ok: কাচের চিপ, অন্ধকার পটে
            ink: fg),
        AppLook.abos => TopBarChipColors(
            fill: const Color(0xFF7F7F7F).withValues(alpha: 0.16),
            border: const Color(0xFF7F7F7F).withValues(alpha: 0.35),
            ink: fg),
      };
}

/// ⭐ উপরের বারের সাদামাটা আইকন — গোল বা বাক্স নেই, আইকন ২৪; চাপার জায়গা অদৃশ্য ৪৮×৪৮, পাশাপাশি গায়ে-গায়ে (WCAG 2.5.8, Material 3)
class _PlainAction extends StatelessWidget {
  const _PlainAction({super.key, required this.tooltip, required this.icon, required this.color, required this.onPressed});

  final String tooltip;
  final IconData icon;
  final Color color;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => IconButton(
        tooltip: tooltip,
        color: color,
        onPressed: onPressed,
        padding: EdgeInsets.zero,
        constraints: const BoxConstraints.tightFor(width: 48, height: 48),
        style: IconButton.styleFrom(tapTargetSize: MaterialTapTargetSize.shrinkWrap),
        icon: Icon(icon, size: 24),
      );
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

/// ⭐ বার্তা — বার্তার তালিকা খোলে/বন্ধ করে (মালিকের অনুমোদিত নমুনা 3nuwRUdn v5 "ক", ২১ নভেম্বর ২০২৬)। বন্ধ থাকলে না-পড়ার
/// লাল গোনা ([MessengerUnread]); খোলা থাকলে নমুনার `.ib.act` — ভরাট চিহ্ন, ক্লাসিকে নীল ঘরে সাদা, স্মার্ট আর গ্লাসে রূপের নীল
/// ১৬% আভায়। ⓘ বার্তা চালু না থাকলে বারই এটা বসায় না ([AbosTopBar.onMessages])
class _MessagesButton extends StatelessWidget {
  const _MessagesButton({required this.color, required this.onPressed, this.active = false});

  final Color color;
  final VoidCallback onPressed;
  final bool active;

  @override
  Widget build(BuildContext context) => ValueListenableBuilder<int?>(
        valueListenable: MessengerUnread.count,
        builder: (context, unread, _) {
          final look = AppLooks.instance.look.value;
          final classic = look == AppLook.abos;
          final activeInk = classic ? const Color(0xFFFFFFFF) : (look == AppLook.glass ? const Color(0xFF7CB4FF) : const Color(0xFF3B82F6));
          final activeBg = classic ? const Color(0xFF3B82F6) : const Color(0x293B82F6);
          final count = active ? null : badgeText(unread);
          final icon = Icon(Symbols.chat_bubble, size: 24, fill: active ? 1 : 0, color: active ? activeInk : color);
          return Semantics(
            key: const ValueKey('top-messages'),
            selected: active,
            // ⓘ চাপার জায়গা সবসময় ৪৮×৪৮; খোলা থাকলে ভেতরে ৪০-এর নীল ঘর (নমুনার `.ib.act`)
            child: SizedBox.square(
              dimension: 48,
              child: Center(
                child: IconButton(
                  tooltip: count == null ? context.l10n.lookMessages : context.l10n.msgListMessagesUnread(bnDigits(unread!)),
                  color: active ? activeInk : color,
                  isSelected: active ? true : null,
                  onPressed: onPressed,
                  padding: EdgeInsets.zero,
                  constraints: BoxConstraints.tightFor(width: active ? 40 : 48, height: active ? 40 : 48),
                  style: active
                      ? IconButton.styleFrom(
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          backgroundColor: activeBg,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          fixedSize: const Size(40, 40),
                          minimumSize: const Size(40, 40),
                        )
                      : IconButton.styleFrom(tapTargetSize: MaterialTapTargetSize.shrinkWrap),
                  icon: count == null
                      ? icon
                      : Badge(
                          key: const ValueKey('top-messages-badge'),
                          backgroundColor: const Color(0xFFE11D48),
                          textColor: const Color(0xFFFFFFFF),
                          label: Text(count),
                          child: icon,
                        ),
                ),
              ),
            ),
          );
        },
      );
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
