import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/auth/me_features.dart';
import '../../core/config/app_config.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/nav_prefs.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/profile/face_image.dart';
import '../../core/profile/me_profile_api.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/expressive.dart';
import '../../core/theme/glass.dart';
import '../home/quick_actions.dart';
import 'menu_page/menu_look.dart';

/// ⭐ Facebook-ধাঁচের ≡ মেনু — কাজের জায়গা (কোথায় যাব, কী করব); মালিকের অনুমোদিত নমুনা "খ. অ্যাপ: ≡ মেনু" (আইটেম ১০,
/// ২০ নভেম্বর ২০২৬)। তিন রূপে আঁকা: স্মার্ট ([AppLook.expressive]), গ্লাস ([AppLook.glass]), ক্লাসিক ([AppLook.abos])।
///
/// <p>উপর থেকে নিচে: নিজের সারি (→ "আমি"), কোম্পানি ও শাখা ("বদলান"; "সব শাখা"-য় হলুদ নোট), শর্টকাট (প্রথম ৮টা, বাকি
/// "আরও দেখুন"-এ), সাম্প্রতিক (শেষ ৩টা), আজকের কাজ, তিন ভাঁজ (সাহায্য · সেটিংস ও গোপনীয়তা · অ্যাপ সম্পর্কে), "বের হওয়া" আর
/// সংস্করণ। ⓘ উপরের আর নিচের বার এই পাতার নয় — এটা খোলসের ভেতরের অংশ।
///
/// <p>⛔ আগের "আমি" ট্যাবের কিছুই হারায় না: প্রিন্টার আর সিঙ্কের অবস্থা "সেটিংস ও গোপনীয়তা"-য়, অ্যাপ হালনাগাদ "অ্যাপ
/// সম্পর্কে"-তে; বাকি সারি ([settingsExtras]) খোলস যা দেয় তা-ই। callback null = সেই সারি নেই।
class MenuPage extends ConsumerStatefulWidget {
  const MenuPage({
    super.key,
    required this.name,
    this.designation,
    this.company = '',
    this.branch = '',
    this.avatarUrl,
    this.allBranches = false,
    this.onOpenProfile,
    this.onSwitchWorkspace,
    this.shortcuts = const [],
    this.shortcutCounts = const {},
    required this.onOpenPath,
    this.recents = const [],
    this.approvalsWaiting,
    this.unreadNotices,
    this.onApprovals,
    this.onNotices,
    this.onDayClose,
    this.onHelpCentre,
    this.onReportProblem,
    this.onLookAndColour,
    this.onLanguage,
    this.onFingerprint,
    this.onScreenshot,
    this.onNotifications,
    this.onPrinter,
    this.onSyncStatus,
    this.settingsExtras = const [],
    this.onCheckUpdate,
    this.updateTile,
    required this.onLogout,
    this.version = AppConfig.appVersion,
  });

  /// নিজের সারি — নাম, পদবি, কোম্পানি · শাখা, ছবি (`/me`-র ছবির ঠিকানা; Bearer দিয়ে [FaceImage])
  final String name;
  final String? designation;
  final String company;
  final String branch;
  final String? avatarUrl;

  /// এখন "সব শাখা" বাছা — তবেই হলুদ "কেবল দেখা" নোট
  final bool allBranches;

  /// নিজের সারিতে চাপ → "আমি" পাতা
  final VoidCallback? onOpenProfile;

  /// "বদলান" → কোম্পানি/শাখা বাছাই ([showWorkspacePicker]); null = বদলানোর অনুমতি নেই, বোতাম নেই
  final VoidCallback? onSwitchWorkspace;

  /// দ্রুত কাজগুলো (হোমের `_quickActions`) — প্রথম ৮টা, বাকি "আরও দেখুন"-এ
  final List<QuickAction> shortcuts;

  /// পথ → লাল সংখ্যা (যেমন `approvals` → 3); ০ বা না থাকলে ব্যাজ নেই
  final Map<String, int> shortcutCounts;

  /// শর্টকাট আর সাম্প্রতিক খোলা — হোমের `_open(path, label)`
  final Future<void> Function(String path, String label) onOpenPath;

  /// হোমের `_recentRows` — এই মানুষ এখনো যা খুলতে পারেন; প্রথম ৩টা দেখায়
  final List<RecentEntry> recents;

  /// সার্ভারের `/me/today` না থাকলে (পুরনো সার্ভার) নিচের বারের সংখ্যা; [approvalsWaiting] null = অনুমোদনকারী নন, সারি নেই
  final int? approvalsWaiting;
  final int? unreadNotices;

  final VoidCallback? onApprovals;
  final VoidCallback? onNotices;

  /// "দিন বন্ধ বাকি" — কেবল নতুন সার্ভারে (`/me/today`)
  final VoidCallback? onDayClose;

  final VoidCallback? onHelpCentre;
  final VoidCallback? onReportProblem;

  final VoidCallback? onLookAndColour;
  final VoidCallback? onLanguage;
  final VoidCallback? onFingerprint;
  final VoidCallback? onScreenshot;
  final VoidCallback? onNotifications;
  final VoidCallback? onPrinter;
  final VoidCallback? onSyncStatus;

  /// "সেটিংস ও গোপনীয়তা"-র শেষে খোলস যা বসায় (যেমন অ্যাপ-তালার সময়, রঙের সারি)
  final List<Widget> settingsExtras;

  /// "আপডেট দেখুন" — [updateTile] দিলে তার জায়গায় সেই widget
  final VoidCallback? onCheckUpdate;
  final Widget? updateTile;

  final VoidCallback onLogout;

  /// চলতি সংস্করণ — [AppUpdateTile]-এর একই উৎস ([AppConfig.appVersion])
  final String version;

  static const int firstShortcuts = 8;
  static const int recentCount = 3;

  @override
  ConsumerState<MenuPage> createState() => _MenuPageState();
}

enum _Fold { help, settings, about }

class _MenuPageState extends ConsumerState<MenuPage> {
  bool _allShortcuts = false;

  /// ⭐ স্মার্টে "সেটিংস ও গোপনীয়তা" শুরুতেই খোলা (নমুনার মতো)
  late final Set<_Fold> _open = {if (AppLooks.instance.look.value == AppLook.expressive) _Fold.settings};

  Future<MeToday>? _today;

  void _loadToday() {
    final api = ref.read(meProfileApiProvider);
    _today = api.today();
  }

  @override
  void didUpdateWidget(MenuPage old) {
    super.didUpdateWidget(old);
    // ⓘ খোলস অনুমোদন বা নোটিশ থেকে ফিরে সংখ্যা নতুন করে — তখন সার্ভারের "আজকের কাজ"-ও নতুন
    if (old.approvalsWaiting != widget.approvalsWaiting || old.unreadNotices != widget.unreadNotices) _today = null;
  }

  @override
  Widget build(BuildContext context) {
    Theme.of(context); // রূপ বদলালে থিম বদলায় — পাতাও নতুন করে আঁকা হয়
    final t = MenuLook.of(context);
    final l = context.l10n;
    final serverToday = ref.watch(meFeaturesProvider)?.profilePage ?? false;
    if (serverToday && _today == null) _loadToday();
    final bottom = MediaQuery.paddingOf(context).bottom;

    final hidden = widget.shortcuts.length - MenuPage.firstShortcuts;
    final shownShortcuts =
        _allShortcuts ? widget.shortcuts : widget.shortcuts.take(MenuPage.firstShortcuts).toList();

    return DefaultTextStyle.merge(
      style: TextStyle(color: t.ink),
      child: ListView(
        key: const ValueKey('menu-page'),
        padding: EdgeInsets.only(bottom: bottom + 18),
        children: [
          _pad(t, _self(t)),
          _pad(t, _workspace(t)),
          if (widget.shortcuts.isNotEmpty) ...[
            _title(t, l.menuPageShortcuts),
            _shortcutGrid(t, shownShortcuts),
            if (!_allShortcuts && hidden > 0)
              Padding(
                padding: EdgeInsets.fromLTRB(t.side, 8, t.side, 0),
                child: _MoreButton(t: t, label: l.menuPageSeeMore, onTap: () => setState(() => _allShortcuts = true)),
              ),
          ],
          if (widget.recents.isNotEmpty) ...[
            _title(t, l.homeRecent),
            _rows(t, [
              for (final (i, e) in widget.recents.take(MenuPage.recentCount).indexed)
                _RowData(
                  key: 'menu-recent-$i',
                  icon: _recentIcon(e.path),
                  colour: MenuLook.recentColours[i % MenuLook.recentColours.length],
                  label: e.label,
                  small: RecentPapers.listPathOf(e.path) != null ? l.menuPageRecentPaper : l.menuPageRecentPage,
                  onTap: () => widget.onOpenPath(e.path, e.label),
                ),
            ]),
          ],
          _todaySection(t, serverToday),
          _folds(t),
          Padding(
            padding: EdgeInsets.fromLTRB(t.side, t.smart ? 16 : 14, t.side, 0),
            child: _LogoutButton(t: t, label: l.avatarMenuSignOut, onTap: widget.onLogout),
          ),
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(l.menuPageAppVersion(widget.version),
                key: const ValueKey('menu-version'),
                textAlign: TextAlign.center,
                style: TextStyle(color: t.muted, fontSize: 11.5)),
          ),
        ],
      ),
    );
  }

  static IconData _recentIcon(String path) {
    if (RecentPapers.listPathOf(path) != null) return Symbols.description;
    if (path.startsWith('customers/')) return Symbols.storefront;
    return Symbols.article;
  }

  Widget _pad(MenuLook t, Widget child) => Padding(padding: EdgeInsets.fromLTRB(t.side, 12, t.side, 0), child: child);

  Widget _title(MenuLook t, String text) => Padding(
        padding: t.smart ? const EdgeInsets.fromLTRB(20, 22, 20, 8) : const EdgeInsets.fromLTRB(14, 18, 14, 8),
        child: Text(text,
            style: TextStyle(fontSize: t.smart ? 17 : 15, fontWeight: t.smart ? FontWeight.w800 : FontWeight.w700, color: t.ink)),
      );

  // ── 1. নিজের সারি ─────────────────────────────────────────────────────────────

  Widget _self(MenuLook t) {
    final l = context.l10n;
    final line = [
      if (widget.designation?.trim().isNotEmpty ?? false) widget.designation!.trim(),
      if (widget.company.isNotEmpty) widget.company,
      if (widget.branch.isNotEmpty) widget.branch,
    ].join(' · ');
    final row = Row(children: [
      _Avatar(t: t, name: widget.name, url: widget.avatarUrl),
      const SizedBox(width: 12),
      Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(widget.name,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: 16, fontWeight: t.smart ? FontWeight.w800 : FontWeight.w700, height: 1.3, color: t.ink)),
          if (line.isNotEmpty)
            Text(line, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, color: t.muted, height: 1.3)),
          if (widget.onOpenProfile != null)
            Text(l.menuPageSeeProfile, style: TextStyle(fontSize: 12, color: t.link, fontWeight: FontWeight.w600, height: 1.3)),
        ]),
      ),
      if (widget.onOpenProfile != null) Icon(Symbols.chevron_right, color: t.muted),
    ]);
    return MenuBox(
      key: const ValueKey('menu-self'),
      tokens: t,
      padding: EdgeInsets.zero,
      child: Semantics(
        button: widget.onOpenProfile != null,
        child: InkWell(
          onTap: widget.onOpenProfile,
          borderRadius: BorderRadius.circular(t.secRadius),
          child: Padding(padding: EdgeInsets.all(t.smart ? 16 : 14), child: row),
        ),
      ),
    );
  }

  // ── 2. কোম্পানি ও শাখা ────────────────────────────────────────────────────────

  Widget _workspace(MenuLook t) {
    final l = context.l10n;
    final where = [if (widget.company.isNotEmpty) widget.company, if (widget.branch.isNotEmpty) widget.branch].join(' · ');
    return MenuBox(
      key: const ValueKey('menu-workspace'),
      tokens: t,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          MenuSquare(
              icon: Symbols.apartment,
              bg: t.workspaceSquare,
              ink: MenuLook.onColour,
              radius: t.smart ? 14 : 11),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(l.workspacePickerTitle, style: TextStyle(fontWeight: FontWeight.w700, color: t.ink, height: 1.3)),
              if (where.isNotEmpty)
                Text(where, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12, color: t.muted, height: 1.3)),
            ]),
          ),
          if (widget.onSwitchWorkspace != null) ...[
            const SizedBox(width: 8),
            Material(
              color: t.soft,
              shape: const StadiumBorder(),
              child: InkWell(
                key: const ValueKey('menu-switch'),
                customBorder: const StadiumBorder(),
                onTap: widget.onSwitchWorkspace,
                child: Padding(
                  padding: t.smart
                      ? const EdgeInsets.symmetric(horizontal: 14, vertical: 6)
                      : const EdgeInsets.symmetric(horizontal: 12, vertical: 3),
                  child: Text(l.menuPageSwitch,
                      style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: t.softInk)),
                ),
              ),
            ),
          ],
        ]),
        if (widget.allBranches)
          Container(
            key: const ValueKey('menu-all-branches-note'),
            margin: const EdgeInsets.only(top: 10),
            padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 7),
            decoration: BoxDecoration(
              color: t.noteBg,
              borderRadius: BorderRadius.circular(t.smart ? 16 : 10),
              border: Border.all(color: t.noteBorder),
            ),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Padding(padding: const EdgeInsets.only(top: 1), child: Icon(Symbols.visibility, size: 16, color: t.noteInk)),
              const SizedBox(width: 6),
              Expanded(
                child: Text(l.menuPageAllBranchesNote,
                    style: TextStyle(fontSize: 12, color: t.noteInk, height: 1.4, shadows: const [])),
              ),
            ]),
          ),
      ]),
    );
  }

  // ── 3. শর্টকাট ────────────────────────────────────────────────────────────────

  Widget _shortcutGrid(MenuLook t, List<QuickAction> actions) {
    final rows = <Widget>[];
    for (var i = 0; i < actions.length; i += 2) {
      final pair = [
        for (var j = i; j < i + 2; j++)
          Expanded(
            child: j < actions.length
                ? _ShortcutTile(
                    key: ValueKey('menu-tile-${actions[j].path}'),
                    t: t,
                    action: actions[j],
                    index: j,
                    count: widget.shortcutCounts[actions[j].path] ?? 0,
                    onTap: () => widget.onOpenPath(actions[j].path, actions[j].label),
                  )
                : const SizedBox.shrink(),
          ),
      ];
      rows.add(Padding(
        padding: EdgeInsets.only(top: i == 0 ? 0 : 8),
        child: IntrinsicHeight(
          child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [pair[0], const SizedBox(width: 8), pair[1]]),
        ),
      ));
    }
    return Padding(
      key: const ValueKey('menu-shortcuts'),
      padding: EdgeInsets.symmetric(horizontal: t.side),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: rows),
    );
  }

  // ── 4/5. সারির দল — সাম্প্রতিক আর আজকের কাজ ────────────────────────────────────

  Widget _rows(MenuLook t, List<_RowData> rows, {Key? key}) {
    if (t.smart) {
      // ⓘ স্মার্টে প্রতিটা সারি নিজের গোল বাক্সে, মাঝে 4
      return Padding(
        key: key,
        padding: EdgeInsets.symmetric(horizontal: t.side),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          for (final (i, r) in rows.indexed)
            Padding(
              padding: EdgeInsets.only(top: i == 0 ? 0 : 4),
              child: Material(
                color: t.card,
                borderRadius: BorderRadius.circular(24),
                child: InkWell(
                  key: ValueKey(r.key),
                  onTap: r.onTap,
                  borderRadius: BorderRadius.circular(24),
                  child: Padding(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13), child: _row(t, r)),
                ),
              ),
            ),
        ]),
      );
    }
    return Padding(
      key: key,
      padding: EdgeInsets.symmetric(horizontal: t.side),
      child: MenuBox(
        tokens: t,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          for (final (i, r) in rows.indexed) ...[
            if (i > 0) Divider(height: 1, thickness: 1, color: t.cardBorder),
            InkWell(
              key: ValueKey(r.key),
              onTap: r.onTap,
              child: Padding(padding: EdgeInsets.only(top: i == 0 ? 0 : 8, bottom: i == rows.length - 1 ? 0 : 8), child: _row(t, r)),
            ),
          ],
        ]),
      ),
    );
  }

  Widget _row(MenuLook t, _RowData r) => Row(children: [
        t.smart
            ? MenuSquare(icon: r.icon, bg: Expressive.secondaryBox, ink: Expressive.primaryDeep, size: 40, radius: 14)
            : MenuSquare(icon: r.icon, bg: r.colour, ink: MenuLook.onColour),
        SizedBox(width: t.smart ? 14 : 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
            Text(r.label,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: t.smart ? 14.5 : 13.5, fontWeight: t.smart ? FontWeight.w600 : FontWeight.w400, height: 1.3, color: t.ink)),
            if (r.small != null)
              Text(r.small!, style: TextStyle(fontSize: t.smart ? 12 : 11.5, color: t.muted, height: 1.3)),
          ]),
        ),
        const SizedBox(width: 8),
        if (r.count != null)
          MenuCount(
            key: ValueKey('${r.key}-count'),
            count: r.count!,
            bg: r.colour,
            height: t.smart ? 28 : 24,
            minWidth: t.smart ? 34 : 28,
          )
        else
          Icon(Symbols.chevron_right, size: 16, color: t.muted),
      ]);

  Widget _todaySection(MenuLook t, bool serverToday) {
    final l = context.l10n;
    List<_RowData> rowsFor(MeToday? server) => [
          if (widget.onApprovals != null && (server != null || widget.approvalsWaiting != null))
            _RowData(
              key: 'menu-today-approvals',
              icon: Symbols.check_circle,
              colour: MenuLook.approvals,
              label: l.lookRowApprovals,
              count: server?.approvalsWaiting ?? widget.approvalsWaiting ?? 0,
              onTap: widget.onApprovals,
            ),
          if (widget.onNotices != null)
            _RowData(
              key: 'menu-today-notices',
              icon: Symbols.notifications,
              colour: MenuLook.notices,
              label: l.menuPageUnreadNotices,
              count: server?.unreadNotices ?? widget.unreadNotices ?? 0,
              onTap: widget.onNotices,
            ),
          // ⓘ দিন বন্ধের সংখ্যা কেবল সার্ভারের `/me/today` জানে — পুরনো সার্ভারে সারি নেই
          if (server != null && widget.onDayClose != null)
            _RowData(
              key: 'menu-today-day-close',
              icon: Symbols.schedule,
              colour: MenuLook.dayClose,
              label: l.menuPageDayClosePending,
              count: server.dayClosePending,
              onTap: widget.onDayClose,
            ),
        ];

    Widget section(List<_RowData> rows) => rows.isEmpty
        ? const SizedBox.shrink()
        : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _title(t, l.lookTodayWork),
            _rows(t, rows, key: const ValueKey('menu-today')),
          ]);

    if (!serverToday || _today == null) return section(rowsFor(null));
    return FutureBuilder<MeToday>(
      future: _today,
      // ⓘ আসার আগে বা না এলে (নেট নেই) — খোলসের সংখ্যা দিয়ে, দিন বন্ধের সারি ছাড়া
      builder: (context, snap) => section(rowsFor(snap.hasData ? snap.data : null)),
    );
  }

  // ── 6. ভাঁজ ───────────────────────────────────────────────────────────────────

  Widget _folds(MenuLook t) {
    final l = context.l10n;
    final w = widget;
    final folds = <(_Fold, IconData, String, String?, List<Widget>)>[
      (
        _Fold.help,
        Symbols.help,
        l.menuPageHelp,
        null,
        [
          if (w.onHelpCentre != null) _sub(t, 'help-centre', l.menuPageHelpCentre, w.onHelpCentre),
          if (w.onReportProblem != null) _sub(t, 'report-problem', l.menuPageReportProblem, w.onReportProblem),
        ],
      ),
      (
        _Fold.settings,
        Symbols.settings,
        l.menuPageSettings,
        l.menuPageSettingsHint,
        [
          if (w.onLookAndColour != null) _sub(t, 'look', l.menuPageLookColour, w.onLookAndColour),
          if (w.onLanguage != null) _sub(t, 'language', l.avatarMenuLanguage, w.onLanguage),
          if (w.onFingerprint != null) _sub(t, 'fingerprint', l.menuPageFingerprint, w.onFingerprint),
          if (w.onScreenshot != null) _sub(t, 'screenshot', l.menuPageScreenshot, w.onScreenshot),
          if (w.onNotifications != null) _sub(t, 'notifications', l.menuPageNotifications, w.onNotifications),
          if (w.onPrinter != null) _sub(t, 'printer', l.homePrinter, w.onPrinter),
          if (w.onSyncStatus != null) _sub(t, 'sync-status', l.homeSyncStatus, w.onSyncStatus),
          ...w.settingsExtras,
        ],
      ),
      (
        _Fold.about,
        Symbols.info,
        l.menuPageAbout,
        l.menuPageVersion(w.version),
        [
          _sub(t, 'version', l.menuPageVersion(w.version), null),
          if (w.updateTile != null)
            w.updateTile!
          else if (w.onCheckUpdate != null)
            _sub(t, 'check-update', l.menuPageCheckUpdate, w.onCheckUpdate),
        ],
      ),
    ];
    final shown = folds.where((f) => f.$5.isNotEmpty).toList();
    if (shown.isEmpty) return const SizedBox.shrink();

    Widget fold((_Fold, IconData, String, String?, List<Widget>) f) {
      final (id, icon, title, small, subs) = f;
      final open = _open.contains(id);
      final head = InkWell(
        key: ValueKey('menu-fold-${id.name}'),
        onTap: () => setState(() => open ? _open.remove(id) : _open.add(id)),
        child: Padding(
          padding: EdgeInsets.symmetric(vertical: t.smart ? 12 : 9),
          child: Row(children: [
            t.smart
                ? MenuSquare(icon: icon, bg: Expressive.secondaryBox, ink: Expressive.primaryDeep, size: 40, radius: 14)
                : MenuSquare(icon: icon, bg: t.soft, ink: t.softInk),
            SizedBox(width: t.smart ? 14 : 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                Text(title,
                    style: TextStyle(fontWeight: t.smart ? FontWeight.w600 : FontWeight.w400, color: t.ink, height: 1.25)),
                if (small != null) Text(small, style: TextStyle(fontSize: 11.5, color: t.muted, height: 1.25)),
              ]),
            ),
            AnimatedRotation(
              key: ValueKey('menu-fold-${id.name}-chevron'),
              turns: open ? 0.5 : 0,
              duration: const Duration(milliseconds: 200),
              child: Icon(Symbols.expand_more, color: t.muted),
            ),
          ]),
        ),
      );
      final body = open
          ? Padding(
              key: ValueKey('menu-fold-${id.name}-body'),
              padding: EdgeInsets.only(left: t.smart ? 54 : 46, bottom: t.smart ? 14 : 10),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: subs),
            )
          : const SizedBox.shrink();
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [head, body]);
    }

    if (t.smart) {
      return Padding(
        padding: EdgeInsets.fromLTRB(t.side, 12, t.side, 0),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          for (final (i, f) in shown.indexed)
            Padding(
              padding: EdgeInsets.only(top: i == 0 ? 0 : 4),
              child: Material(
                color: t.card,
                borderRadius: BorderRadius.circular(24),
                clipBehavior: Clip.antiAlias,
                child: Padding(padding: const EdgeInsets.symmetric(horizontal: 16), child: fold(f)),
              ),
            ),
        ]),
      );
    }
    return Padding(
      padding: EdgeInsets.fromLTRB(t.side, 12, t.side, 0),
      child: MenuBox(
        tokens: t,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          for (final (i, f) in shown.indexed) ...[
            if (i > 0) Divider(height: 1, thickness: 1, color: t.cardBorder),
            fold(f),
          ],
        ]),
      ),
    );
  }

  Widget _sub(MenuLook t, String id, String label, VoidCallback? onTap) {
    final text = Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Text(label, style: TextStyle(fontSize: 13, color: t.ink, height: 1.4)),
    );
    if (onTap == null) return KeyedSubtree(key: ValueKey('menu-sub-$id'), child: text);
    // ⓘ চাপা যায় এমন সারি অন্তত ৪৮ উঁচু (Android-এর নিয়ম; আগের "আমি"-র সিঙ্ক/প্রিন্টারের সারিও ছিল) — লেখা মাঝখানে
    return InkWell(
      key: ValueKey('menu-sub-$id'),
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 48),
        child: Align(alignment: AlignmentDirectional.centerStart, child: text),
      ),
    );
  }
}

@immutable
class _RowData {
  const _RowData({
    required this.key,
    required this.icon,
    required this.colour,
    required this.label,
    this.small,
    this.count,
    this.onTap,
  });

  final String key;
  final IconData icon;
  final Color colour;
  final String label;
  final String? small;
  final int? count;
  final VoidCallback? onTap;
}

/// নিজের ছবি — Bearer দিয়ে [FaceImage]; না এলে নামের প্রথম অক্ষর
class _Avatar extends StatelessWidget {
  const _Avatar({required this.t, required this.name, required this.url});

  final MenuLook t;
  final String name;
  final String? url;

  @override
  Widget build(BuildContext context) {
    final initial = name.isEmpty ? '?' : name.characters.first;
    final image = url == null ? null : ResizeImage(FaceImage(url!), width: 156, policy: ResizeImagePolicy.fit);
    return Container(
      key: const ValueKey('menu-avatar'),
      width: 52,
      height: 52,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: t.smart ? Expressive.secondaryBox : null,
        gradient: t.smart
            ? null
            : const LinearGradient(
                begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color(0xFFF59E0B), Color(0xFFEA580C)]),
        border: t.smart ? Border.all(color: const Color(0xFFCAC4D0)) : null,
      ),
      child: CircleAvatar(
        radius: 26,
        backgroundColor: const Color(0x00000000),
        foregroundImage: image,
        onForegroundImageError: image == null ? null : (_, __) {},
        child: Text(initial,
            style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: t.smart ? Expressive.primaryDeep : MenuLook.onColour,
                shadows: const [])),
      ),
    );
  }
}

class _ShortcutTile extends StatelessWidget {
  const _ShortcutTile({super.key, required this.t, required this.action, required this.index, required this.count, required this.onTap});

  final MenuLook t;
  final QuickAction action;
  final int index;
  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final colour = MenuLook.tileColours[index % MenuLook.tileColours.length];
    final shape = t.smart ? Expressive.tiles[index % 4] : null;
    final ink = shape?.$2 ?? t.ink;
    final capsule = t.smart && index % 4 == 2;
    final body = ConstrainedBox(
      constraints: BoxConstraints(minHeight: t.smart ? 92 : 86),
      child: Padding(
        padding: t.smart
            ? EdgeInsets.symmetric(horizontal: capsule ? 24 : 16, vertical: 14)
            : const EdgeInsets.symmetric(horizontal: 12, vertical: 11),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Padding(
            // ⓘ ব্যাজের জায়গা — নাম ব্যাজের নিচে যায় না
            padding: EdgeInsets.only(right: count > 0 ? 24 : 0),
            child: t.smart
                ? MenuSquare(icon: action.icon, bg: const Color(0x99FFFFFF), ink: ink, radius: 12)
                : MenuSquare(icon: action.icon, bg: colour, ink: MenuLook.onColour),
          ),
          const SizedBox(height: 8),
          Text(action.label,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                  fontSize: t.smart ? 14.5 : 14, fontWeight: FontWeight.w700, height: 1.2, color: ink)),
        ]),
      ),
    );
    final tap = Semantics(
      button: true,
      label: count > 0 ? '${action.label} ($count)' : action.label,
      excludeSemantics: true,
      child: InkWell(onTap: onTap, customBorder: _border(), child: body),
    );
    // ⓘ passthrough — জোড়ার উঁচু ঘরের মাপ ([IntrinsicHeight]) চাপের জায়গাতেও যায়
    final badged = Stack(fit: StackFit.passthrough, children: [
      tap,
      if (count > 0)
        Positioned(
          right: 10,
          top: 10,
          child: MenuCount(
              key: ValueKey('menu-tile-${action.path}-count'),
              count: count,
              bg: MenuLook.badge,
              height: 20,
              minWidth: 20,
              fontSize: 11),
        ),
    ]);
    if (t.glass) return GlassPanel(radius: 16, child: badged);
    return Material(
      color: shape?.$1 ?? t.card,
      shape: _border(),
      clipBehavior: Clip.antiAlias,
      shadowColor: const Color(0x0F000000),
      elevation: t.smart ? 0 : 1,
      child: badged,
    );
  }

  ShapeBorder _border() {
    if (t.smart) return RoundedRectangleBorder(borderRadius: Expressive.tiles[index % 4].$3);
    return RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(t.glass ? 16 : 10),
      side: t.glass ? BorderSide.none : BorderSide(color: t.cardBorder),
    );
  }
}

class _MoreButton extends StatelessWidget {
  const _MoreButton({required this.t, required this.label, required this.onTap});

  final MenuLook t;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(t.smart ? 22 : 12);
    return Material(
      color: t.soft,
      borderRadius: radius,
      child: InkWell(
        key: const ValueKey('menu-more'),
        borderRadius: radius,
        onTap: onTap,
        child: SizedBox(
          height: t.smart ? 44 : 38,
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            Text(label, style: TextStyle(fontWeight: FontWeight.w600, color: t.softInk)),
            const SizedBox(width: 6),
            Icon(Symbols.expand_more, size: 16, color: t.softInk),
          ]),
        ),
      ),
    );
  }
}

class _LogoutButton extends StatelessWidget {
  const _LogoutButton({required this.t, required this.label, required this.onTap});

  final MenuLook t;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(t.smart ? 24 : (t.glass ? 14 : 8));
    return Material(
      color: t.outBg,
      shape: RoundedRectangleBorder(
        borderRadius: radius,
        side: t.outBorder == null ? BorderSide.none : BorderSide(color: t.outBorder!),
      ),
      child: InkWell(
        key: const ValueKey('menu-logout'),
        borderRadius: radius,
        onTap: onTap,
        child: SizedBox(
          height: t.smart ? 48 : 44,
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(Symbols.logout, color: t.outInk, size: 20),
            const SizedBox(width: 8),
            Text(label, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15, color: t.outInk, shadows: const [])),
          ]),
        ),
      ),
    );
  }
}
