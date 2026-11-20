import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/auth/auth_state.dart';
import '../../core/menu/module_gate.dart';
import '../../core/navigation/nav_state.dart';
import '../../core/navigation/colour_tab_icon.dart';
import '../../core/navigation/deposit_icon.dart';
import '../../core/navigation/role_tabs.dart';
import '../../core/navigation/top_bar/abos_top_bar.dart' show AvatarDisc;
import '../../core/navigation/top_bar/top_bar_context.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/locale/app_locale.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/expressive.dart';
import '../../core/theme/glass.dart';
import '../../core/widgets/fab_clearance.dart';
import '../../core/widgets/totals_bar.dart';
import '../copilot/copilot_mark.dart';

/// The home's own pages, as [HomeShell] names them for its [IndexedStack].
/// ⓘ বিজ্ঞপ্তি আর "আমি" আর নিচের বারে নেই (বারের নকশা ৩, অংশ ৪): ঘণ্টা আর ছবি ওপরের বারে (a4)। পথ দুটো আগের মতোই আছে।
enum NavTab { home, approvals, modules, notifications, me }

/// ⭐ যে ঘর এখন সামনে — নতুন খোলা পাতা এই ঘরেরই হয় (প্রতিটা ঘরের নিজের ইতিহাস)।
final activeTabProvider = StateProvider<BarTab>((ref) => BarTab.home);

/// ⭐ প্রতিটা ঘরের নিজের পাতার ইতিহাস — ঘর ছাড়লে রাখা, ফিরলে আবার বসানো (বারের নকশা ৩, অংশ ৭: "পিছনে যাওয়া")।
final tabHistoryProvider = Provider<TabHistory>((ref) => TabHistory());

class TabHistory {
  final Map<BarTab, ({RouteMatchList matches, HomePage page})> _saved = {};
  String? _owner;

  void save(String owner, BarTab tab, RouteMatchList matches, HomePage page) {
    if (_owner != owner) _saved.clear();
    _owner = owner;
    _saved[tab] = (matches: matches, page: page);
  }

  /// আরেকজন ঢুকলে আগের জনের ইতিহাস কখনো নয়
  ({RouteMatchList matches, HomePage page})? take(String owner, BarTab tab) {
    if (_owner != owner) {
      _saved.clear();
      return null;
    }
    return _saved.remove(tab);
  }

  void forget(BarTab tab) => _saved.remove(tab);
}

/// ⓘ পুরনো ডাক — [HomeShell] এখনো তার মাথার Scaffold-এ এটা বসায়। বার এখন [ScreenFrame]-এর (`/home`-সহ প্রতিটা পাতায়),
/// তাই কাঠামোর ভিতরে এটা কিছুই আঁকে না; কাঠামো ছাড়া (HomeShell একা) একই ভূমিকার বার আঁকে।
class AbosBottomBar extends ConsumerWidget {
  const AbosBottomBar({
    super.key,
    required this.selected,
    required this.badges,
    required this.onSelect,
  });

  final NavTab selected;
  final NavBadges badges;
  final ValueChanged<NavTab> onSelect;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (_InFrame.of(context)) return const SizedBox.shrink();
    final top = ref.watch(topBarContextProvider);
    // ⭐ কাঠামোর একই সাত ঘর — Copilot (চাবি থাকলে) আর নোটিশ সহ (মালিক, ২০ নভেম্বর ২০২৬ "Ok")
    final tabs = RoleTabs.lookTabsFor(
      ref.watch(authStateProvider).user?.permissions ?? const <String>[],
      modulesOn: ref.watch(phoneModulesProvider),
      copilot: top?.copilot != null,
    );
    final shown = switch (selected) {
      NavTab.modules => BarTab.modules,
      NavTab.me => BarTab.me,
      _ => BarTab.home,
    };
    return RoleBottomBar(
      tabs: tabs,
      selected: tabs.contains(shown) ? shown : null,
      badges: _badgesOf(badges),
      onSelect: (tab) {
        switch (tab) {
          case BarTab.home:
            onSelect(NavTab.home);
          case BarTab.modules:
            onSelect(NavTab.modules);
          case BarTab.approvals:
            onSelect(NavTab.approvals);
          // ⭐ ছবির ঘর — 'আমি' পাতা খোলে, অন্য ঠিকানায় ঠেলে না (মালিক, ১৪ নভেম্বর ২০২৬: ছবি নিচের বারে)
          case BarTab.me:
            onSelect(NavTab.me);
          case BarTab.notices:
            onSelect(NavTab.notifications);
          case BarTab.copilot:
            top?.copilot?.call(context, RoleTabs.specs[BarTab.home]!.label);
          default:
            GoRouter.maybeOf(context)?.push(RoleTabs.specs[tab]!.location);
        }
      },
    );
  }
}

Map<BarTab, TabBadge> _badgesOf(NavBadges b) => {
      BarTab.approvals: RoleTabs.badgeFor(b.pending, more: b.pendingMore),
      // ⭐ নোটিশের ঘরে উপরের ঘণ্টার একই না-পড়ার গোনা (নতুন দুই রূপ; মালিক, ২০ নভেম্বর ২০২৬)
      BarTab.notices: RoleTabs.badgeFor(b.unread),
    };

/// ⭐ "＋ নতুন …" — বারের নকশা আর পুরো পাতার নমুনার বোতাম (মালিক, ২০ অক্টোবর ২০২৬): ডান-নিচে ভাসে, ১৬dp গোল কোণ,
/// থিমের মূল রঙের পট, মোটা লেখা। আইকন Material Symbols-এর ＋। যে পর্দা নিজে বোতাম আঁকে (আদায়, ভাউচার, দাবি), সে-ও
/// এটাই বসাবে, যাতে সব পাতায় একই চেহারা।
///
/// <p>⭐ খাড়া বারের পাশে (ট্যাবলেট, আড়াআড়ি ফোন) বোতাম পাতার ওপরে ভাসে না: নিজের নাম আর কাজ কাঠামোর বারের মাথায় রাখে
/// ([FrameFab.railSlotOf]), বার সেটা আঁকে, এখানে কিছু আঁকে না (Material 3: মাঝারি আর চওড়া জানালায় FAB রেলের leading-এ;
/// fe, ২২ অক্টোবর ২০২৬)। ⭐ ফোনে পাতার ওপরে ভাসে না: পর্দার নিচের বারে ([ActionBottomBar]) মোটের পাশে বসে (০.৪.৪৮)।
class BarFab extends StatefulWidget {
  const BarFab({super.key, required this.label, required this.onPressed, this.icon = Symbols.add});

  final String label;
  final VoidCallback onPressed;

  /// সাধারণত ＋; টাকা পাঠানো বা গোনার মতো কাজে নিজের আইকন
  final IconData icon;

  @override
  State<BarFab> createState() => _BarFabState();
}

class _BarFabState extends State<BarFab> {
  ValueNotifier<RailAction?>? _slot;

  void _leave() {
    final slot = _slot;
    if (slot != null && slot.value?.owner == this) slot.value = null;
    _slot = null;
  }

  @override
  void dispose() {
    final slot = _slot;
    _slot = null;
    // ⓘ বার এখন আঁকার মাঝে থাকতে পারে — পরের ফ্রেমে সরানো
    if (slot != null && slot.value?.owner == this) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        try {
          if (slot.value?.owner == this) slot.value = null;
        } on FlutterError {
          // ⓘ কাঠামোও চলে গেছে — ঘরটাই নেই
        }
      });
    }
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final slot = FrameFab.railSlotOf(context);
    if (slot != _slot) _leave();
    if (slot != null) {
      _slot = slot;
      final label = widget.label;
      final icon = widget.icon;
      if (slot.value?.owner != this || slot.value?.label != label || slot.value?.icon != icon) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (!mounted || _slot != slot) return;
          slot.value = RailAction(label: label, icon: icon, onPressed: () => widget.onPressed(), owner: this);
        });
      }
      return const SizedBox.shrink();
    }
    final bar = _InActionBar.of(context);
    return barFabButton(context,
        label: widget.label, icon: widget.icon, onPressed: widget.onPressed, contained: bar != null, compact: bar?.compact ?? false);
  }
}

/// [BarFab]-এর চেহারা — নিচের বারের ভেতরে ([contained]: ছায়া নেই, Material 3-এর বারে-বসা বোতাম), বা খাড়া বারের মাথায়
/// ([railed]: হিরো ছাড়া, যাতে দুই পাতার বোতাম না জড়ায়)। [compact]: জায়গা কম (৩২০dp, ২০০% অক্ষর) — কেবল আইকন, নাম
/// ছুঁয়ে-ধরার লেখায় আর TalkBack-এ।
Widget barFabButton(BuildContext context,
    {required String label,
    required VoidCallback onPressed,
    IconData icon = Symbols.add,
    Key? key,
    bool railed = false,
    bool contained = false,
    bool compact = false}) {
  // ⓘ বোতামের রং থিমের ভূমিকা থেকে (নকশার নিয়ম ০.৪.৩৮ §১: "বোতাম: primary") — আলো আর অন্ধকারে দুটোতেই
  final cs = Theme.of(context).colorScheme;
  final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(16));
  if (compact) {
    return FloatingActionButton(
      key: key,
      heroTag: railed ? null : const _BarFabHero(),
      tooltip: label,
      elevation: contained ? 0 : null,
      backgroundColor: cs.primary,
      foregroundColor: cs.onPrimary,
      shape: shape,
      onPressed: onPressed,
      child: Icon(icon, semanticLabel: label),
    );
  }
  return FloatingActionButton.extended(
    key: key,
    heroTag: railed ? null : const _BarFabHero(),
    elevation: contained ? 0 : null,
    backgroundColor: cs.primary,
    foregroundColor: cs.onPrimary,
    shape: shape,
    onPressed: onPressed,
    icon: Icon(icon),
    label: Text(label, style: _fabLabel),
  );
}

const _fabLabel = TextStyle(fontWeight: FontWeight.w700, fontSize: 14);

/// FloatingActionButton-এর নিজের হিরো চিহ্নের মতোই — একটা পাতায় একটাই বোতাম, পাতা বদলে বোতাম উড়ে যায়
class _BarFabHero {
  const _BarFabHero();

  @override
  String toString() => '<bar fab hero>';
}

/// [ActionBottomBar]-এর ভেতরে — বোতাম ছোট রূপে কি না
class _InActionBar extends InheritedWidget {
  const _InActionBar({required this.compact, required super.child});

  final bool compact;

  static _InActionBar? of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<_InActionBar>();

  @override
  bool updateShouldNotify(_InActionBar oldWidget) => compact != oldWidget.compact;
}

/// ⭐ পর্দার নিচের বার — মোট ([totals]) আর পাতার মূল কাজ ([action], "＋ নতুন …") এক বারে, বোতাম বারের ভেতরে শেষ প্রান্তে
/// (Material 3: BottomAppBar + FloatingActionButtonLocation.endContained)। পাতার তালিকা বারের ওপরেই শেষ হয়, তাই বোতাম
/// কোনো সারি বা মোট কখনো ঢাকে না — স্থির অবস্থায়, স্ক্রলের মাঝে, শেষে (মালিকের নিয়ম ০.৪.৪৭ থেকে; fe, ২৪ অক্টোবর ২০২৬)।
///
/// <p>মোট না থাকলে সরু বার, কেবল বোতাম। বোতাম পুরো রূপে (＋ আর নাম) বসে যদি মোটের প্রতিটা লাইন তবুও এক লাইনে ধরে; নইলে
/// কেবল আইকন (নাম ছুঁয়ে-ধরার লেখায় আর TalkBack-এ) — বার উপচে পড়ে না, মোট কাটা পড়ে না। কেবল-আইকনের পাশেও কোনো টাকা না
/// ধরলে (যেমন ৩২০dp-তে ২০০% অক্ষর) বোতাম বারের ওপরের সারিতে, মোট নিচে পুরো চওড়ায় — টাকা কখনো মাঝখানে ভাঙে না, "…" হয় না।
///
/// <p>খাড়া বার দেখানো হলে (ট্যাবলেট) আগের মতো: বোতাম বারের মাথায় ([BarFab]), এখানে কেবল মোট।
class ActionBottomBar extends StatelessWidget {
  const ActionBottomBar({super.key, this.totals, this.action});

  /// দুটোই না থাকলে null — Scaffold-এর `bottomNavigationBar`-এ সোজা বসানো যায়
  static Widget? maybe({TotalsBar? totals, Widget? action}) =>
      totals == null && action == null ? null : ActionBottomBar(totals: totals, action: action);

  final TotalsBar? totals;

  /// সাধারণত [BarFab] (বা কাঠামোর দেওয়া [FrameFab.offeredTo])
  final Widget? action;

  /// বোতাম আর মোটের মাঝে
  static const double gap = AppSpacing.sm;

  /// পুরো রূপের বোতামের চওড়া — Material 3: শুরুতে ১৬, আইকন ২৪, ফাঁক ৮, নাম, শেষে ২০ (আর মাপের গোলমালে ২)
  static double extendedWidth(BuildContext context, String label) {
    final painter = TextPainter(
        text: TextSpan(text: label, style: (Theme.of(context).textTheme.labelLarge ?? const TextStyle()).merge(_fabLabel)),
        textDirection: Directionality.of(context),
        textScaler: MediaQuery.textScalerOf(context),
        maxLines: 1)
      ..layout();
    final w = painter.width;
    painter.dispose();
    return 16 + 24 + 8 + w + 20 + 2;
  }

  @override
  Widget build(BuildContext context) {
    final totals = this.totals, action = this.action;
    // ⓘ ট্যাবলেট: বোতাম খাড়া বারের মাথায় নিজেকে রাখে (কিছু আঁকে না) — মোটের বার আগের মতো
    if (FrameFab.railSlotOf(context) != null || action == null) {
      return Column(mainAxisSize: MainAxisSize.min, children: [
        if (totals != null) totals,
        if (action != null) action,
      ]);
    }
    final cs = Theme.of(context).colorScheme;
    final label = action is BarFab ? action.label : null;
    return Material(
      key: const ValueKey('action-bar'),
      color: cs.surfaceContainer,
      child: DecoratedBox(
        decoration: BoxDecoration(border: Border(top: BorderSide(color: cs.outlineVariant))),
        child: SafeArea(
          top: false,
          child: LayoutBuilder(builder: (context, box) {
            final width = box.maxWidth;
            const fab = 56.0, end = AppSpacing.md;
            final full = label == null ? 0.0 : extendedWidth(context, label);
            var compact = false, stacked = false;
            if (totals == null) {
              compact = full + 2 * end > width;
            } else {
              final need = totals.widthsIn(context);
              if (need.oneLine + gap + full + end > width) {
                compact = true;
                stacked = need.narrowest + gap + fab + end > width;
              }
            }
            final button = Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(0, AppSpacing.sm, end, AppSpacing.sm),
              child: _InActionBar(compact: compact, child: action),
            );
            // ⓘ heightFactor: 1 — বার কেবল বোতামের সমান উঁচু; নইলে Align পুরো পাতা জুড়ে বসত
            if (totals == null) return Align(alignment: AlignmentDirectional.centerEnd, heightFactor: 1, child: button);
            final hosted = TotalsBarHost(child: totals);
            if (stacked) {
              return Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Align(alignment: AlignmentDirectional.centerEnd, heightFactor: 1, child: button),
                Padding(padding: const EdgeInsetsDirectional.only(end: end), child: hosted),
              ]);
            }
            return Row(children: [
              Expanded(child: hosted),
              const SizedBox(width: gap),
              button,
            ]);
          }),
        ),
      ),
    );
  }
}

/// খাড়া বারের মাথার বোতাম — চওড়া বারে নাম-সহ, সরু বারে কেবল ＋ (নাম TalkBack আর ছুঁয়ে-ধরার লেখায়)
class _RailFab extends StatelessWidget {
  const _RailFab({required this.action, required this.extended});

  final RailAction action;
  final bool extended;

  @override
  Widget build(BuildContext context) {
    if (extended) {
      return Align(
        alignment: AlignmentDirectional.centerStart,
        child: barFabButton(context,
            key: const ValueKey('rail-fab'), label: action.label, icon: action.icon, onPressed: action.onPressed, railed: true),
      );
    }
    final cs = Theme.of(context).colorScheme;
    return FloatingActionButton(
      key: const ValueKey('rail-fab'),
      heroTag: null,
      tooltip: action.label,
      backgroundColor: cs.primary,
      foregroundColor: cs.onPrimary,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      onPressed: action.onPressed,
      child: Icon(action.icon),
    );
  }
}

class _InFrame extends InheritedWidget {
  const _InFrame({required super.child});

  static bool of(BuildContext context) =>
      context.getInheritedWidgetOfExactType<_InFrame>() != null;

  @override
  bool updateShouldNotify(_InFrame oldWidget) => false;
}

/// ⭐ নিচের বার — ভূমিকার চার ঘর, প্রতিটায় আইকন আর বাংলা নাম (Material 3 Navigation bar-এর মাপ: ৮০dp উঁচু,
/// ৬৪×৩২ নির্দেশক, ২৪dp আইকন)। নিজে আঁকা, যাতে ২০০% অক্ষরে নাম দুই লাইনে নামে আর বার নিজে বাড়ে, আর TalkBack
/// পুরো বাক্য পড়ে ("অনুমোদন, ৫টা অপেক্ষায়")।
class RoleBottomBar extends StatelessWidget {
  const RoleBottomBar({
    super.key,
    required this.tabs,
    required this.selected,
    required this.badges,
    required this.onSelect,
  });

  final List<BarTab> tabs;
  final BarTab? selected;
  final Map<BarTab, TabBadge> badges;
  final ValueChanged<BarTab> onSelect;

  /// ⭐ বাছা ঘরের নীল (মালিক, ২০ নভেম্বর ২০২৬ "ok": Facebook-এর মতো; নমুনা v8-এর #3b82f6) — তিন রূপেই একই
  static const Color lookBlue = Color(0xFF3B82F6); // dark-ok: সাদা বার, গাঢ় কাচ আর গাঢ় টুলবার — তিনটাতেই ৩:১-এর বেশি

  /// ⭐ একটা ঘর — Facebook-এর মতো (মালিক, ২০ নভেম্বর ২০২৬ "ok", তিন রূপেই): পেছনে গোল বা বড়ি নেই; বাছা ঘরের আইকন ভরাট আর
  /// নীল, তার ঠিক ওপরে বারের কিনারায় সরু নীল দাগ (৩×২৮, গোল মাথা); বাকিরা রেখার আইকন, মলিন রঙে; "আমি"-র ছবিতে নীল রিং
  Widget _slot(BarTab tab, {required Color blue, required Color muted, required bool strongBadge}) {
    final on = tab == selected;
    return Expanded(
      child: Stack(children: [
        Positioned.fill(
          child: Center(
            child: _BarItem(
              spec: RoleTabs.specs[tab]!,
              selected: on,
              badge: badges[tab] ?? const TabBadge.none(),
              onTap: () => onSelect(tab),
              onLook: true,
              blue: blue,
              muted: muted,
              strongBadge: strongBadge,
            ),
          ),
        ),
        if (on)
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: Center(
              child: Container(
                key: ValueKey('nav-${tab.name}-line'),
                width: 28,
                height: 3,
                decoration: BoxDecoration(color: blue, borderRadius: BorderRadius.circular(1.5)),
              ),
            ),
          ),
      ]),
    );
  }

  Widget _row({required Color blue, required Color muted, required bool strongBadge}) => Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [for (final tab in tabs) _slot(tab, blue: blue, muted: muted, strongBadge: strongBadge)],
      );

  /// ⭐ Liquid Glass — একটাই ভাসমান কাচের ক্যাপসুল, সাত ঘর পর্যন্ত, কেবল আইকন। ⛔ পাশের খোঁজার বল নেই — খোঁজা উপরের বারে
  /// (মালিক, ২০ নভেম্বর ২০২৬ "Ok", বারের নমুনা)
  Widget _glass(BuildContext context) => SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(10, 0, 10, 14),
          child: GlassPanel(
            key: const ValueKey('nav-bar-glass'),
            radius: 32,
            child: SizedBox(
              height: 64,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: _row(
                    blue: lookBlue,
                    muted: Colors.white.withValues(alpha: 0.72), // dark-ok: কাচের বার, অন্ধকার পটে
                    strongBadge: true),
              ),
            ),
          ),
        ),
      );

  /// ⭐ Material 3 Expressive — একটাই ভাসমান গাঢ় টুলবার, সাত ঘর পর্যন্ত, কেবল আইকন; Material 3-এর বড়ি-নির্দেশকও নেই
  /// (মালিক, ২০ নভেম্বর ২০২৬ "ok")। ⛔ ডানের আলাদা ＋ নেই — ＋ উপরের বারে
  Widget _expressive(BuildContext context) => SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(10, 0, 10, 14),
          child: Container(
            key: const ValueKey('nav-bar-expressive'),
            height: 64,
            padding: const EdgeInsets.symmetric(horizontal: 4),
            decoration: const BoxDecoration(
              color: Expressive.toolbar,
              borderRadius: BorderRadius.all(Radius.circular(32)),
              boxShadow: [BoxShadow(color: Color(0x4D000000), blurRadius: 20, offset: Offset(0, 8))],
            ),
            child: _row(blue: lookBlue, muted: Expressive.toolbarMuted, strongBadge: true),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    // ⓘ রূপ বদলালে থিম বদলায়, তাই এই বারও নতুন করে আঁকা হয়
    Theme.of(context);
    switch (AppLooks.instance.look.value) {
      case AppLook.glass:
        return Semantics(container: true, explicitChildNodes: true, child: _glass(context));
      case AppLook.expressive:
        return Semantics(container: true, explicitChildNodes: true, child: _expressive(context));
      case AppLook.abos:
        break;
    }
    final palette = context.palette;
    return Semantics(
      container: true,
      explicitChildNodes: true,
      child: Material(
        key: const ValueKey('nav-bar'),
        color: palette.surface,
        child: DecoratedBox(
          decoration: BoxDecoration(border: Border(top: BorderSide(color: palette.border))),
          child: SafeArea(
            top: false,
            child: SizedBox(
              // ⭐ লেখা নেই, কেবল আইকন — বার ৬৪ উঁচু; বাছা ঘরের নীল দাগ বারের ওপরের কিনারায় (মালিক, ২০ নভেম্বর ২০২৬ "ok")
              height: 64,
              child: _row(blue: lookBlue, muted: palette.onSurfaceMuted, strongBadge: false),
            ),
          ),
        ),
      ),
    );
  }
}

/// ⭐ বাঁ পাশে খাড়া বার — ৬০০dp বা বেশি চওড়া, বা আড়াআড়ি ফোন; [extended] (৮৪০dp বা বেশি) হলে পুরো পাশ-মেনু খোলা,
/// আইকনের পাশে নাম।
class RoleRail extends StatelessWidget {
  const RoleRail({
    super.key,
    required this.tabs,
    required this.selected,
    required this.badges,
    required this.onSelect,
    required this.extended,
    this.action,
  });

  final List<BarTab> tabs;
  final BarTab? selected;
  final Map<BarTab, TabBadge> badges;
  final ValueChanged<BarTab> onSelect;
  final bool extended;

  /// পাতার মূল কাজ ("＋ নতুন …") — Material 3-এর রেলের leading: ঘরগুলোর ওপরে
  final RailAction? action;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    return Semantics(
      container: true,
      explicitChildNodes: true,
      child: Material(
        key: ValueKey(extended ? 'nav-drawer' : 'nav-rail'),
        color: palette.surface,
        child: DecoratedBox(
          decoration: BoxDecoration(border: Border(right: BorderSide(color: palette.border))),
          child: SafeArea(
            right: false,
            child: SizedBox(
              width: extended ? 256 : 96,
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
                child: Column(
                  children: [
                    if (action != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 4, bottom: 16),
                        child: _RailFab(action: action!, extended: extended),
                      ),
                    for (final tab in tabs)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 4),
                        child: _BarItem(
                          spec: RoleTabs.specs[tab]!,
                          selected: tab == selected,
                          badge: badges[tab] ?? const TabBadge.none(),
                          onTap: () => onSelect(tab),
                          wide: extended,
                          rail: true,
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// ⭐ "আমি" ঘরের আইকন — নিজের ছবি, না থাকলে নামের প্রথম অক্ষর (উপরের বারের একই [AvatarDisc]); বাছা থাকলে চারপাশে দাগ
class _MyFace extends ConsumerWidget {
  const _MyFace({required this.selected, this.size = 26, this.ringColor});

  final bool selected;
  final double size;

  /// ⭐ নিচের বারে বাছা থাকলে ২dp নীল রিং (মালিক, ২০ নভেম্বর ২০২৬ "ok"); null হলে আগের কমলা দাগ (খাড়া বার)
  final Color? ringColor;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final name = ref.watch(authStateProvider).user?.name ?? '';
    final face = ref.watch(topBarContextProvider)?.avatar;
    // ⭐ নিচের বারে বড় ছবি (৫৬, মালিকের নমুনা ৩), পাশের খাড়া বারে আইকনের মাপে
    return AvatarDisc(
        key: const ValueKey('nav-me-face'),
        name: name,
        image: face,
        size: size,
        ring: selected,
        edge: false,
        ringColor: ringColor,
        ringWidth: ringColor == null ? 3 : 2);
  }
}

class _BarItem extends StatelessWidget {
  const _BarItem({
    required this.spec,
    required this.selected,
    required this.badge,
    required this.onTap,
    this.wide = false,
    this.rail = false,
    this.onLook = false,
    this.blue,
    this.muted,
    this.strongBadge = false,
  });

  /// ⭐ নিচের ফোনের বার, তিন রূপেই (মালিক, ২০ নভেম্বর ২০২৬ "ok": Facebook-এর মতো) — পেছনে পটি নেই; বাছা ঘর ভরাট নীল
  /// আইকন ([blue]), বাকিরা রেখার আইকন ([muted]); নীল দাগ বার আঁকে ([RoleBottomBar._slot])
  final bool onLook;
  final Color? blue;
  final Color? muted;

  /// গাঢ় বারে গোনা ভরাট লাল, সাদা অঙ্ক
  final bool strongBadge;

  final BarTabSpec spec;
  final bool selected;
  final TabBadge badge;
  final VoidCallback onTap;
  final bool wide;

  /// ⓘ পাশের খাড়া বার — নামসহ থাকে (জায়গা আছে); নিচের ফোনের বারেই কেবল লেখা ছাড়া
  final bool rail;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final ink = selected ? palette.onSurface : palette.onSurfaceMuted;
    final scale = MediaQuery.textScalerOf(context).scale(1).clamp(1.0, 2.0);
    // ⭐ নিচের বার (wide নয়, রেল নয়) — লেখা ছাড়া বড় রঙিন আইকন; রেলে নামসহ, আইকন ২৮
    final bare = ! wide && ! rail;
    final icon = onLook
        ? _lookIcon(spec, selected, blue ?? Theme.of(context).colorScheme.primary, muted ?? palette.onSurfaceMuted)
        : spec.tab == BarTab.me
            ? _MyFace(selected: selected, size: bare ? 56 : 30)
            : ColourTabIcon(key: ValueKey('nav-${spec.tab.name}-icon'), tab: spec.tab, size: bare ? 36 : 28);
    // ⓘ নতুন দুই রূপের গাঢ় বারে গোনা নমুনার মতো ভরাট লাল, সাদা অঙ্ক (#e11d48) — থিমের হালকা লাল কাচে ফিকে দেখাত
    final badgeInk = strongBadge ? const Color(0xFFE11D48) : palette.danger; // dark-ok: গাঢ় বারের ওপর, দুই মোডেই একই
    final badgeText = strongBadge ? Colors.white : palette.surface; // dark-ok: ভরাট লালের ওপর
    final badged = switch (badge.kind) {
      TabBadgeKind.none => icon,
      TabBadgeKind.dot => Badge(
          key: ValueKey('nav-${spec.tab.name}-dot'),
          smallSize: 8,
          backgroundColor: badgeInk,
          child: icon,
        ),
      TabBadgeKind.text => Badge(
          key: ValueKey('nav-${spec.tab.name}-badge'),
          largeSize: 16 * scale,
          backgroundColor: badgeInk,
          textColor: badgeText,
          label: Text(badge.text!),
          child: icon,
        ),
    };
    final indicator = onLook
        ? Center(child: badged)
        : AnimatedContainer(
      duration: const Duration(milliseconds: 150),
      width: wide ? null : (bare ? 68 : 64),
      height: bare ? 48 : 36,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        // ⓘ ছবির ঘরে পটি নয় — বাছা থাকলে ছবির চারপাশে রিং ([AvatarDisc] ring)
        color: selected && spec.tab != BarTab.me ? palette.primarySoft : null,
        borderRadius: BorderRadius.circular(bare ? 24 : 18),
      ),
      child: badged,
    );
    final label = Text(
      spec.label,
      // ⓘ নাম কখনো কাটে না, "…" হয় না: বড় অক্ষরে দুই লাইনে নামে, বার নিজে উঁচু হয়
      softWrap: true,
      textAlign: wide ? TextAlign.start : TextAlign.center,
      style: TextStyle(
        fontSize: wide ? 14 : 12,
        height: 1.3,
        color: ink,
        fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
      ),
    );
    final body = wide
        ? Container(
            constraints: const BoxConstraints(minHeight: 56),
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            decoration: BoxDecoration(
              color: selected ? palette.primarySoft : null,
              borderRadius: BorderRadius.circular(28),
            ),
            child: Row(children: [
              badged,
              const SizedBox(width: 12),
              Expanded(child: label),
            ]),
          )
        : bare
            // ⓘ লেখা নেই — নাম লম্বা চাপে ভেসে ওঠে ([Tooltip]) আর TalkBack পড়ে (নিচের Semantics)
            ? Tooltip(
                message: spec.label,
                // ⛔ উচ্চতা বাঁধা — নিচের বার ঢিলা মাপ পায়, খালি Center পুরো পর্দা জুড়ে বার লম্বা করত আর তালিকা ঢাকত
                // (fab_never_hides_data_test ধরেছে, ১৫ নভেম্বর ২০২৬)
                child: SizedBox(height: onLook ? 48 : 64, child: Center(child: indicator)),
              )
            : ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 64, minWidth: 48),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 2),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                indicator,
                const SizedBox(height: 4),
                label,
              ]),
            ),
          );
    return Semantics(
      key: ValueKey('nav-${spec.tab.name}'),
      container: true,
      button: true,
      selected: selected,
      inMutuallyExclusiveGroup: true,
      label: RoleTabs.spokenLabel(spec, badge),
      onTap: onTap,
      excludeSemantics: true,
      child: InkWell(
        onTap: onTap,
        customBorder: onLook ? const CircleBorder() : null,
        borderRadius: onLook ? null : BorderRadius.circular(wide ? 28 : 16),
        child: body,
      ),
    );
  }

  /// ⭐ নতুন দুই রূপের বারের আইকন — নমুনার মতো এক রঙের রেখা ([IconTheme]-এর রং), ঘরের নাম কেবল লম্বা চাপে আর TalkBack-এ;
  /// Copilot নিজের রঙিন AI চিহ্নে, "আমি" নিজের ছবিতে, জমা ৳-নোটে (মালিক, ২০ নভেম্বর ২০২৬ "Ok")
  /// ⓘ বাছা = ভরাট (Material Symbols fill 1) আর নীল; না-বাছা = রেখা (fill 0), মলিন রঙে
  static Widget _lookIcon(BarTabSpec spec, bool selected, Color blue, Color muted) {
    final key = ValueKey('nav-${spec.tab.name}-icon');
    final ink = selected ? blue : muted;
    final fill = selected ? 1.0 : 0.0;
    return switch (spec.tab) {
      BarTab.me => _MyFace(selected: selected, size: 30, ringColor: blue),
      // ⓘ Copilot নিজের রঙিন AI চিহ্নে — ভরাট রূপ নেই; বাছা বোঝায় ওপরের নীল দাগ
      BarTab.copilot => CopilotMark(key: key, size: 28),
      BarTab.deposit => DepositIcon(key: key, size: 26, color: ink, filled: selected),
      // ⓘ নমুনার চার গোল-চারকোনা টালি
      BarTab.modules => Icon(Symbols.grid_view, key: key, size: 26, color: ink, fill: fill),
      _ => Icon(spec.icon, key: key, size: 26, color: ink, fill: fill),
    };
  }
}

/// ⭐ একটা পর্দা, তার নিচে (বা বাঁয়ে) একই বার — `/home` আর প্রতিটা `/home/<পর্দা>` (app_router.dart-এর `_framed`)।
///
/// <p>এখানে বারের সব আচরণ (বারের নকশা ৩, অংশ ৪ আর ৭):
/// - ভূমিকার চার ঘর ([RoleTabs.tabsFor]), অনুমোদনে লাল গোনা;
/// - ৬০০dp বা আড়াআড়ি: খাড়া বার; ৮৪০dp: পুরো পাশ-মেনু;
/// - কিবোর্ড খুললে নিচের বার সরে যায়;
/// - একই ঘরে আবার চাপ: ঘরের প্রথম পাতায় ফেরে, সেখানেই থাকলে তালিকার একেবারে ওপরে;
/// - প্রতিটা ঘরের নিজের ইতিহাস ([TabHistory]); হোম ছাড়া ঘরের প্রথম পাতায় পিছনে = হোম, হোমে পিছনে = অ্যাপ বন্ধ;
/// - ঘর বদলালে হালকা কাঁপুনি (ফোনের সেটিং মেনে);
/// - ＋ নতুন কেবল যেখানে বানানো যায় আর চাবি আছে ([RoleTabs.fabFor])।
///
/// <p>বার আঁকে কেবল চাবি থেকে, সার্ভারের উত্তরের অপেক্ষা ছাড়া — ধীর নেটে বার আর ঘর আগে, তথ্য পরে।
class ScreenFrame extends ConsumerStatefulWidget {
  const ScreenFrame({super.key, required this.location, required this.child});

  /// The route's own path, e.g. `/home/approvals`.
  final String location;
  final Widget child;

  @override
  ConsumerState<ScreenFrame> createState() => _ScreenFrameState();
}

class _ScreenFrameState extends ConsumerState<ScreenFrame> {
  /// যে ঘর থেকে এই পাতা খোলা হয়েছিল
  late final BarTab _openedFrom;

  /// খাড়া বারের মাথার বোতাম — পর্দার নিজের "＋ নতুন" (BarFab) এখানে নিজের নাম আর কাজ রাখে
  final _railSlot = ValueNotifier<RailAction?>(null);

  @override
  void dispose() {
    _railSlot.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    _openedFrom = ref.read(activeTabProvider);
  }

  bool get _isHome => Uri.parse(widget.location).path == '/home';

  BarTab? _shownTab(List<BarTab> tabs, HomePage page) {
    if (_isHome) {
      if (page.kind == HomePageKind.me && tabs.contains(BarTab.me)) return BarTab.me;
      final modules = page.kind != HomePageKind.home && page.kind != HomePageKind.me;
      return modules && tabs.contains(BarTab.modules) ? BarTab.modules : BarTab.home;
    }
    final own = RoleTabs.owningTab(widget.location);
    if (own != null && tabs.contains(own)) return own;
    return tabs.contains(_openedFrom) ? _openedFrom : null;
  }

  String get _owner => ref.read(authStateProvider).user?.id ?? '';

  void _select(BarTab target, BarTab? current) {
    final top = ref.read(topBarContextProvider);
    // ⭐ Copilot পাতা নয় — চাপলে এখনকার মতো পুরো-পর্দার "জিজ্ঞেস করুন" (মালিক, ২০ নভেম্বর ২০২৬, বারের নমুনা)
    if (target == BarTab.copilot) {
      final ask = top?.copilot;
      if (ask != null) {
        HapticFeedback.lightImpact();
        ask(context, current == null ? AppLocale.l10n.navTabHome : RoleTabs.specs[current]!.label);
      }
      return;
    }
    if (target == current) {
      _reselect(target);
      return;
    }
    // ⭐ নোটিশ — উপরের ঘণ্টা যা খুলত তা-ই (তালিকা, ফিরলে না-পড়ার গোনা আবার)
    if (target == BarTab.notices && top != null) {
      HapticFeedback.lightImpact();
      top.openNotifications(context);
      return;
    }
    HapticFeedback.lightImpact();
    final router = GoRouter.of(context);
    final history = ref.read(tabHistoryProvider);
    if (current != null) {
      history.save(_owner, current, router.routerDelegate.currentConfiguration, ref.read(homePageProvider));
    }
    final saved = history.take(_owner, target);
    ref.read(homePageProvider.notifier).state = switch (target) {
      BarTab.modules => saved?.page ?? HomePage.modules,
      BarTab.me => HomePage.me,
      _ => HomePage.home,
    };
    ref.read(activeTabProvider.notifier).state = target;
    if (saved != null) {
      router.restore(saved.matches);
    } else {
      router.go(RoleTabs.specs[target]!.location);
    }
  }

  /// একই ঘরে আবার চাপ — প্রথম পাতায় না থাকলে সেখানে ফেরা; থাকলে তালিকার একেবারে ওপরে
  void _reselect(BarTab tab) {
    final spec = RoleTabs.specs[tab]!;
    final page = ref.read(homePageProvider);
    final atRoot = switch (tab) {
      BarTab.home => _isHome && page.kind == HomePageKind.home,
      BarTab.modules => _isHome && page == HomePage.modules,
      BarTab.me => _isHome && page.kind == HomePageKind.me,
      _ => Uri.parse(widget.location).path == spec.location,
    };
    if (atRoot) {
      final controller = PrimaryScrollController.maybeOf(context);
      if (controller != null) {
        for (final position in controller.positions.toList()) {
          position.animateTo(0, duration: const Duration(milliseconds: 300), curve: Curves.easeOutCubic);
        }
      }
      return;
    }
    ref.read(tabHistoryProvider).forget(tab);
    ref.read(homePageProvider.notifier).state = switch (tab) {
      BarTab.modules => HomePage.modules,
      BarTab.me => HomePage.me,
      _ => HomePage.home,
    };
    GoRouter.of(context).go(spec.location);
  }

  Future<void> _chooseNew(List<FabChoice> choices) async {
    final route = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      // ⓘ ছোট ফোনে ২০০% অক্ষরে সব বাছাই না ধরলে গড়ায় — নিচের সারি কাটা পড়ে না
      builder: (sheet) => SafeArea(
        child: SingleChildScrollView(child: Column(mainAxisSize: MainAxisSize.min, children: [
          for (final c in choices)
            ListTile(
              key: ValueKey('fab-choice-${c.route}'),
              minTileHeight: 56,
              leading: Icon(c.icon),
              title: Text(c.label),
              onTap: () => Navigator.of(sheet).pop(c.route),
            ),
        ])),
      ),
    );
    if (route != null && mounted) await context.push(route);
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authStateProvider).user;
    final permissions = user?.permissions ?? const <String>[];
    final modulesOn = ref.watch(phoneModulesProvider);
    // ⓘ রূপ বদলালে থিম বদলায় — তাতে কাঠামোও নতুন করে আঁকা হয়, বারের ঘরও
    Theme.of(context);
    final top = ref.watch(topBarContextProvider);
    // ⭐ নতুন দুই রূপে সাত ঘর পর্যন্ত — Copilot (চাবি থাকলে) আর নোটিশ যোগ ([RoleTabs.lookTabsFor]); ABOS আগের মতো
    // ⭐ তিন রূপেই সাত ঘর পর্যন্ত — উপরের বারে ঘণ্টা আর Copilot আর নেই, তাই ক্লাসিকেও নিচে (মালিক, ২০ নভেম্বর ২০২৬ "Ok",
    // নমুনা v8: "ক্লাসিক চেহারাতেও একই উপরের বার আর একই নীল বাছাই")
    final tabs = RoleTabs.lookTabsFor(permissions, modulesOn: modulesOn, copilot: top?.copilot != null);
    final page = ref.watch(homePageProvider);
    final shown = _shownTab(tabs, page);
    final badges = _badgesOf(ref.watch(navBadgesProvider));

    final route = ModalRoute.of(context);
    if (shown != null && (route?.isCurrent ?? true)) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        final active = ref.read(activeTabProvider.notifier);
        if (active.state != shown) active.state = shown;
      });
    }

    final media = MediaQuery.of(context);
    final layout = RoleTabs.layoutFor(media.size);
    final keyboardOpen = media.viewInsets.bottom > 0;
    void onSelect(BarTab t) => _select(t, shown);

    // ⓘ হোমের "＋ নতুন" কেবল হোমের নিজের পাতায় — সব মডিউলে বা "আমি"-তে নয়
    final fab = _isHome && page.kind != HomePageKind.home
        ? null
        : RoleTabs.fabFor(widget.location, permissions, modulesOn: modulesOn);
    final built = fab == null || (fab.route == null && fab.choices.isEmpty)
        ? null
        : BarFab(
            key: const ValueKey('frame-fab'),
            label: fab.label,
            onPressed: () => fab.choices.isEmpty ? context.push(fab.route!) : _chooseNew(fab.choices),
          );
    // ⭐ বোতাম কখনো তথ্য ঢাকে না: পাতার ওপরে ভাসে না — কাঠামোর নিজের বোতাম (হোম) নিচের বারের ওপরে নিজের সরু বারে
    // ([ActionBottomBar]); যে পর্দা নিজে বসায় ([FabSpec.drawnByScreen] আর পথ আছে — অর্ডার), সে নিজের নিচের বারে মোটের পাশে
    // ⭐ খাড়া বার দেখানো হলে বারের মাথায় বসে (Material 3; fe, ২২ অক্টোবর ২০২৬)
    final inRail = layout != BarLayout.bottom && tabs.length >= 2;
    final fabButton = inRail || (fab != null && fab.drawnByScreen) ? null : built;
    final child = _InFrame(
        child: FrameFab(
            offered: !inRail && fab != null && fab.drawnByScreen ? built : null,
            railSlot: inRail ? _railSlot : null,
            child: widget.child));
    final actionBar = fabButton == null ? null : ActionBottomBar(action: fabButton);
    if (layout == BarLayout.bottom) {
      final nav = keyboardOpen || tabs.length < 2
          ? null
          : RoleBottomBar(
              tabs: tabs,
              selected: shown,
              badges: badges,
              // ⛔ নিচে খোঁজার বল আর ＋ নেই — দুটোই উপরের বারে (মালিক, ২০ নভেম্বর ২০২৬ "Ok", বারের নমুনা)
              onSelect: onSelect,
            );
      return Scaffold(
        body: child,
        bottomNavigationBar: actionBar == null
            ? nav
            : nav == null
                ? actionBar
                : Column(mainAxisSize: MainAxisSize.min, children: [
                    // ⓘ ফোনের নিচের কিনারা নিচের বারের — বোতামের বার তার ওপরে, নিজে ফাঁক রাখে না
                    MediaQuery.removePadding(context: context, removeBottom: true, child: actionBar),
                    nav,
                  ]),
      );
    }
    return Scaffold(
      bottomNavigationBar: actionBar,
      body: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (inRail)
          ValueListenableBuilder<RailAction?>(
            valueListenable: _railSlot,
            builder: (context, fromScreen, _) => RoleRail(
              tabs: tabs,
              selected: shown,
              badges: badges,
              onSelect: onSelect,
              extended: layout == BarLayout.extendedRail,
              // ⓘ কাঠামোর নিজের বোতাম (হোম, অর্ডার) আগে; নইলে পর্দার নিজের (আদায়, ভাউচার, দাবি …)
              action: built == null
                  ? fromScreen
                  : RailAction(label: built.label, icon: built.icon, onPressed: built.onPressed, owner: this),
            ),
          ),
        Expanded(child: child),
      ]),
    );
  }
}
