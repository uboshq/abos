import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Which of the home's own pages is on screen — shared, so the bottom bar on
/// a screen ([ScreenFrame]) can send somebody back to "সব মডিউল" or "আমি".
enum HomePageKind { home, modules, module, subList, me }

class HomePage {
  const HomePage._(this.kind, [this.module, this.parent]);

  static const HomePage home = HomePage._(HomePageKind.home);
  static const HomePage modules = HomePage._(HomePageKind.modules);
  static const HomePage me = HomePage._(HomePageKind.me);

  const HomePage.module(String code) : this._(HomePageKind.module, code);
  const HomePage.subList(String code, String parentId)
      : this._(HomePageKind.subList, code, parentId);

  final HomePageKind kind;
  final String? module;
  final String? parent;

  /// One level up — Android's back (the owner: back always goes one level up).
  /// Null on the home itself: there the app may close.
  HomePage? get up => switch (kind) {
        HomePageKind.home => null,
        HomePageKind.modules || HomePageKind.me => HomePage.home,
        HomePageKind.module => HomePage.modules,
        HomePageKind.subList => HomePage.module(module!),
      };

  @override
  bool operator ==(Object other) =>
      other is HomePage &&
      other.kind == kind &&
      other.module == module &&
      other.parent == parent;

  @override
  int get hashCode => Object.hash(kind, module, parent);
}

final homePageProvider = StateProvider<HomePage>((ref) => HomePage.home);

/// The counts on the bottom bar — set by the home, read by every screen's bar.
class NavBadges {
  const NavBadges({this.pending, this.pendingMore = false, this.unread});

  /// Null: this person may not approve (the server said no) — no tab.
  final int? pending;
  final bool pendingMore;
  final int? unread;

  bool get approver => pending != null;
}

final navBadgesProvider = StateProvider<NavBadges>((ref) => const NavBadges());
