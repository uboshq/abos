import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/auth/auth_state.dart';
import '../../core/books/collection_entry.dart';
import '../../core/menu/module_gate.dart';
import '../../core/navigation/nav_state.dart';
import '../../core/navigation/role_tabs.dart';
import '../../core/theme/app_palette.dart';

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
    final tabs = _tabsOf(ref);
    final shown = selected == NavTab.modules ? BarTab.modules : BarTab.home;
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
          default:
            GoRouter.maybeOf(context)?.push(RoleTabs.specs[tab]!.location);
        }
      },
    );
  }
}

List<BarTab> _tabsOf(WidgetRef ref) => RoleTabs.tabsFor(
      ref.watch(authStateProvider).user?.permissions ?? const <String>[],
      modulesOn: ref.watch(phoneModulesProvider),
    );

Map<BarTab, TabBadge> _badgesOf(NavBadges b) => {
      BarTab.approvals: RoleTabs.badgeFor(b.pending, more: b.pendingMore),
    };

/// ⭐ "＋ নতুন …" — বারের নকশা আর পুরো পাতার নমুনার বোতাম (মালিক, ২০ অক্টোবর ২০২৬): ডান-নিচে ভাসে, ১৬dp গোল কোণ,
/// থিমের মূল রঙের পট, মোটা লেখা। আইকন Material Symbols-এর ＋। যে পর্দা নিজে বোতাম আঁকে (আদায়, ভাউচার, দাবি), সে-ও
/// এটাই বসাবে, যাতে সব পাতায় একই চেহারা।
class BarFab extends StatelessWidget {
  const BarFab({super.key, required this.label, required this.onPressed});

  final String label;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    // ⓘ বোতামের রং থিমের ভূমিকা থেকে (নকশার নিয়ম ০.৪.৩৮ §১: "বোতাম: primary") — আলো আর অন্ধকারে দুটোতেই
    final cs = Theme.of(context).colorScheme;
    return FloatingActionButton.extended(
      backgroundColor: cs.primary,
      foregroundColor: cs.onPrimary,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      onPressed: onPressed,
      icon: const Icon(Symbols.add),
      label: Text(label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
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

  @override
  Widget build(BuildContext context) {
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
            child: ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 80),
              child: Padding(
                // ⓘ পুরো পাতার নমুনা: ওপরে ৮, নিচে ১৪
                padding: const EdgeInsets.only(top: 8, bottom: 14),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    for (final tab in tabs)
                      Expanded(
                        child: _BarItem(
                          spec: RoleTabs.specs[tab]!,
                          selected: tab == selected,
                          badge: badges[tab] ?? const TabBadge.none(),
                          onTap: () => onSelect(tab),
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
  });

  final List<BarTab> tabs;
  final BarTab? selected;
  final Map<BarTab, TabBadge> badges;
  final ValueChanged<BarTab> onSelect;
  final bool extended;

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
                    for (final tab in tabs)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 4),
                        child: _BarItem(
                          spec: RoleTabs.specs[tab]!,
                          selected: tab == selected,
                          badge: badges[tab] ?? const TabBadge.none(),
                          onTap: () => onSelect(tab),
                          wide: extended,
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

class _BarItem extends StatelessWidget {
  const _BarItem({
    required this.spec,
    required this.selected,
    required this.badge,
    required this.onTap,
    this.wide = false,
  });

  final BarTabSpec spec;
  final bool selected;
  final TabBadge badge;
  final VoidCallback onTap;
  final bool wide;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final ink = selected ? palette.onSurface : palette.onSurfaceMuted;
    final scale = MediaQuery.textScalerOf(context).scale(1).clamp(1.0, 2.0);
    final icon = Icon(spec.icon, size: 24, color: ink, fill: selected ? 1 : 0);
    final badged = switch (badge.kind) {
      TabBadgeKind.none => icon,
      TabBadgeKind.dot => Badge(
          key: ValueKey('nav-${spec.tab.name}-dot'),
          smallSize: 8,
          backgroundColor: palette.danger,
          child: icon,
        ),
      TabBadgeKind.text => Badge(
          key: ValueKey('nav-${spec.tab.name}-badge'),
          largeSize: 16 * scale,
          backgroundColor: palette.danger,
          textColor: palette.surface,
          label: Text(badge.text!),
          child: icon,
        ),
    };
    final indicator = AnimatedContainer(
      duration: const Duration(milliseconds: 150),
      width: wide ? null : 64,
      height: 32,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: selected ? palette.primarySoft : null,
        borderRadius: BorderRadius.circular(16),
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
        borderRadius: BorderRadius.circular(wide ? 28 : 16),
        child: body,
      ),
    );
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

  @override
  void initState() {
    super.initState();
    _openedFrom = ref.read(activeTabProvider);
  }

  bool get _isHome => Uri.parse(widget.location).path == '/home';

  BarTab? _shownTab(List<BarTab> tabs, HomePage page) {
    if (_isHome) {
      final modules = page.kind != HomePageKind.home && page.kind != HomePageKind.me;
      return modules && tabs.contains(BarTab.modules) ? BarTab.modules : BarTab.home;
    }
    final own = RoleTabs.owningTab(widget.location);
    if (own != null && tabs.contains(own)) return own;
    return tabs.contains(_openedFrom) ? _openedFrom : null;
  }

  String get _owner => ref.read(authStateProvider).user?.id ?? '';

  void _select(BarTab target, BarTab? current) {
    if (target == current) {
      _reselect(target);
      return;
    }
    HapticFeedback.lightImpact();
    final router = GoRouter.of(context);
    final history = ref.read(tabHistoryProvider);
    if (current != null) {
      history.save(_owner, current, router.routerDelegate.currentConfiguration, ref.read(homePageProvider));
    }
    final saved = history.take(_owner, target);
    ref.read(homePageProvider.notifier).state =
        target == BarTab.modules ? (saved?.page ?? HomePage.modules) : HomePage.home;
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
      BarTab.home => _isHome && (page.kind == HomePageKind.home || page.kind == HomePageKind.me),
      BarTab.modules => _isHome && page == HomePage.modules,
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
    ref.read(homePageProvider.notifier).state = tab == BarTab.modules ? HomePage.modules : HomePage.home;
    GoRouter.of(context).go(spec.location);
  }

  Future<void> _chooseNew(List<FabChoice> choices) async {
    final route = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (sheet) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          for (final c in choices)
            ListTile(
              key: ValueKey('fab-choice-${c.route}'),
              minTileHeight: 56,
              leading: Icon(c.icon),
              title: Text(c.label),
              onTap: () => Navigator.of(sheet).pop(c.route),
            ),
        ]),
      ),
    );
    if (route != null && mounted) await context.push(route);
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authStateProvider).user;
    final permissions = user?.permissions ?? const <String>[];
    final modulesOn = ref.watch(phoneModulesProvider);
    final tabs = RoleTabs.tabsFor(permissions, modulesOn: modulesOn);
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
        : RoleTabs.fabFor(widget.location, permissions,
            modulesOn: modulesOn, mayCollect: ref.watch(mayCollectProvider));
    final fabButton = fab == null || fab.drawnByScreen
        ? null
        : BarFab(
            key: const ValueKey('frame-fab'),
            label: fab.label,
            onPressed: () => fab.choices.isEmpty ? context.push(fab.route!) : _chooseNew(fab.choices),
          );

    final child = _InFrame(child: widget.child);
    if (layout == BarLayout.bottom) {
      return Scaffold(
        body: child,
        floatingActionButton: fabButton,
        bottomNavigationBar: keyboardOpen || tabs.length < 2
            ? null
            : RoleBottomBar(tabs: tabs, selected: shown, badges: badges, onSelect: onSelect),
      );
    }
    return Scaffold(
      floatingActionButton: fabButton,
      body: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (tabs.length >= 2)
          RoleRail(
            tabs: tabs,
            selected: shown,
            badges: badges,
            onSelect: onSelect,
            extended: layout == BarLayout.extendedRail,
          ),
        Expanded(child: child),
      ]),
    );
  }
}
