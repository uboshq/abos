import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/navigation/nav_state.dart';
import '../../core/theme/app_colors.dart';

/// The five places one tap away from anywhere — the owner's mockup, 12 Oct
/// 2026: হোম · অনুমোদন · সব মডিউল · বিজ্ঞপ্তি · আমি.
enum NavTab { home, approvals, modules, notifications, me }

/// ⭐ নিচের বার — অনুমোদনে আর বিজ্ঞপ্তিতে লাল গোনা। অনুমোদনের ঘর কেবল তাঁর, যাঁকে সার্ভার সই করতে দেয়
/// (আগের মাথার আইকনের একই নিয়ম): দরজা খুলে ৪০৩ দেখানো চলে না।
class AbosBottomBar extends StatelessWidget {
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
  Widget build(BuildContext context) {
    final tabs = [
      NavTab.home,
      if (badges.approver) NavTab.approvals,
      NavTab.modules,
      NavTab.notifications,
      NavTab.me,
    ];
    final index = tabs.indexOf(selected);
    return NavigationBar(
      key: const ValueKey('nav-bar'),
      selectedIndex: index < 0 ? tabs.indexOf(NavTab.modules) : index,
      onDestinationSelected: (i) => onSelect(tabs[i]),
      destinations: [for (final tab in tabs) _destination(tab)],
    );
  }

  Widget _destination(NavTab tab) {
    switch (tab) {
      case NavTab.home:
        return const NavigationDestination(
          key: ValueKey('nav-home'),
          icon: Icon(Icons.home_outlined),
          selectedIcon: Icon(Icons.home),
          label: 'হোম',
        );
      case NavTab.approvals:
        final pending = badges.pending ?? 0;
        final more = badges.pendingMore;
        return NavigationDestination(
          key: const ValueKey('nav-approvals'),
          icon: Badge(
            backgroundColor: AppColors.badge,
            isLabelVisible: pending > 0 || more,
            label: Text(more || pending > 99 ? '$pending+' : '$pending'),
            child: const Icon(Icons.fact_check_outlined),
          ),
          label: 'অনুমোদন',
        );
      case NavTab.modules:
        return const NavigationDestination(
          key: ValueKey('nav-modules'),
          icon: Icon(Icons.grid_view_outlined),
          selectedIcon: Icon(Icons.grid_view),
          label: 'সব মডিউল',
        );
      case NavTab.notifications:
        final unread = badges.unread ?? 0;
        return NavigationDestination(
          key: const ValueKey('nav-notifications'),
          icon: Badge(
            backgroundColor: AppColors.badge,
            isLabelVisible: unread > 0,
            label: Text(unread > 99 ? '99+' : '$unread'),
            child: const Icon(Icons.notifications_outlined),
          ),
          label: 'বিজ্ঞপ্তি',
        );
      case NavTab.me:
        return const NavigationDestination(
          key: ValueKey('nav-me'),
          icon: Icon(Icons.person_outline),
          selectedIcon: Icon(Icons.person),
          label: 'আমি',
        );
    }
  }
}

/// ⭐ একটা পর্দা, নিচে একই বার — যেকোনো পর্দা থেকে এক চাপে হোমে, সব মডিউলে বা আমিতে (মালিকের নমুনা, ছবি ৪)।
///
/// <p>Wraps every `/home/<screen>` route (app_router.dart's `_framed`). The
/// screen itself is untouched — its own app bar, back arrow and buttons stay —
/// this only adds the bar under it. The route stays on the root navigator,
/// so the back arrow and Android's back still go one level up.
class ScreenFrame extends ConsumerWidget {
  const ScreenFrame({super.key, required this.location, required this.child});

  /// The route's own path, e.g. `/home/approvals` — which tab is lit.
  final String location;
  final Widget child;

  static NavTab tabFor(String location) {
    if (location.startsWith('/home/approvals')) return NavTab.approvals;
    if (location.startsWith('/home/notifications')) return NavTab.notifications;
    if (location.startsWith('/home/sync-status')) return NavTab.me;
    return NavTab.modules;
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final selected = tabFor(location);
    return Scaffold(
      body: child,
      bottomNavigationBar: AbosBottomBar(
        selected: selected,
        badges: ref.watch(navBadgesProvider),
        onSelect: (tab) {
          final page = ref.read(homePageProvider.notifier);
          switch (tab) {
            case NavTab.home:
              page.state = HomePage.home;
              context.go('/home');
            case NavTab.modules:
              page.state = HomePage.modules;
              context.go('/home');
            case NavTab.me:
              page.state = HomePage.me;
              context.go('/home');
            case NavTab.approvals:
              if (selected != NavTab.approvals) context.go('/home/approvals');
            case NavTab.notifications:
              if (selected != NavTab.notifications) {
                context.go('/home/notifications');
              }
          }
        },
      ),
    );
  }
}
