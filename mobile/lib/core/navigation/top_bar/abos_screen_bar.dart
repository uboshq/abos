import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../auth/auth_state.dart';
import '../../locale/l10n.dart';
import '../nav_state.dart';
import 'abos_top_bar.dart';
import 'top_bar_context.dart';

/// ⭐ ভেতরের পর্দার উপরের বার — হোমের একই নীল বার, সব পর্দায় "কোম্পানি · শাখা" (মালিকের নিয়ম: "প্রতিটা পাতায়, সবসময়";
/// নকশা ৩ §২, ২০ অক্টোবর ২০২৬)। একটা পর্দার `AppBar(title: Text(x), actions: [...], bottom: ...)`-এর জায়গায় সরাসরি বসে:
///
/// ```dart
/// appBar: AbosScreenBar(title: x, actions: [...], bottom: ...),
/// ```
///
/// <p>বাঁয়ে ← (`Navigator.maybePop` — আধা-লেখা ফর্মে [UnsavedGuard] আগের মতো জিজ্ঞেস করে); মাঝে "কোম্পানি · শাখা ▾" আর
/// "› মডিউল › [title]" (মডিউল [module] থেকে, না দিলে পথ থেকে — `/home/customers/…` → গ্রাহকের মডিউল); ডানে হোমের মতোই
/// খোঁজা, বিজ্ঞপ্তি (গোনাসহ) আর নিজের ছবি (একই মেনু)। পর্দার নিজের বোতাম দ্বিতীয় সারির ডান প্রান্তে, দুটোর বেশি হলে "⋮"-এ
/// ([ScreenActionsRow])। `bottom` (যেমন TabBar) বারের নিচে, বারের রঙে।
///
/// <p>কোম্পানি, শাখা, ছবি আর হোমের কাজগুলো [topBarContextProvider] থেকে (হোম লেখে); বিজ্ঞপ্তির গোনা [navBadgesProvider]
/// থেকে; নেট আর জমা কাজ [SyncStatus.instance] থেকে। কোনো নতুন সার্ভার-ডাক নেই।
///
/// <p>⛔ সেশন নেই (Riverpod নেই, কেউ ঢোকেনি, বা হোম এখনো কিছু লেখেনি — যেমন একা একটা পর্দার টেস্ট) → সাদামাটা
/// `AppBar(title: Text(title), actions:, bottom:)`, আগের মতোই।
class AbosScreenBar extends StatelessWidget implements PreferredSizeWidget {
  const AbosScreenBar({
    super.key,
    required this.title,
    this.module,
    this.actions = const [],
    this.bottom,
    this.onBack,
  });

  /// পাতার নাম — "› মডিউল › পাতা"-র শেষ অংশ
  final String title;

  /// মডিউলের নাম ("বিক্রয়"); null হলে পথ থেকে খোঁজা হয়, তাও না পেলে কেবল পাতার নাম
  final String? module;

  /// পর্দার নিজের বোতাম — আগে `AppBar.actions`-এ যা ছিল, হুবহু
  final List<Widget> actions;

  /// বারের নিচে (যেমন TabBar)
  final PreferredSizeWidget? bottom;

  /// ← চাপলে — না দিলে `Navigator.maybePop` (পেছনে কিছু না থাকলে হোমে)
  final VoidCallback? onBack;

  @override
  Size get preferredSize => const Size.fromHeight(AbosTopBar.maxHeight);

  @override
  Widget build(BuildContext context) {
    final scoped = context.getElementForInheritedWidgetOfExactType<UncontrolledProviderScope>() != null;
    if (!scoped) return plain(context);
    return _LiveScreenBar(bar: this);
  }

  /// সেশন ছাড়া — আগের সাদামাটা বার, আগের উচ্চতায়।
  /// ⛔ [preferredSize] নীল বারের ছাদ (৪৮০dp); সাদামাটা AppBar পুরো ছাদ জুড়ে বসে যায় — তাই নিজের মাপে বাঁধা।
  Widget plain(BuildContext context) {
    final bar = AppBar(
      key: const ValueKey('screen-bar-plain'),
      title: Text(title),
      actions: actions,
      bottom: bottom,
      leading: onBack == null
          ? null
          : IconButton(tooltip: context.l10n.topBarBack, onPressed: onBack, icon: const Icon(Symbols.arrow_back)),
    );
    return Align(
      alignment: Alignment.topCenter,
      heightFactor: 1,
      child: SizedBox(height: bar.preferredSize.height + MediaQuery.paddingOf(context).top, child: bar),
    );
  }

  /// ← — `maybePop`, যাতে আধা-লেখা ফর্ম আগে জিজ্ঞেস করে; পেছনে কিছু না থাকলে হোমে
  static Future<void> back(BuildContext context) async {
    final nav = Navigator.of(context);
    if (nav.canPop()) {
      await nav.maybePop();
    } else {
      GoRouter.maybeOf(context)?.go('/home');
    }
  }

  /// পথ থেকে পাতার প্রথম অংশ — `/home/customers/42` → `customers`
  static String? routeHead(BuildContext context) {
    try {
      final segments = GoRouterState.of(context).uri.pathSegments;
      final i = segments.indexOf('home');
      return i >= 0 && i + 1 < segments.length ? segments[i + 1] : null;
    } catch (_) {
      return null; // ⓘ GoRouter-এর বাইরে (টেস্ট) — মডিউল জানা নেই
    }
  }

  /// "› মডিউল › পাতা" — মডিউল আর পাতার নাম এক হলে একবার
  static List<String> crumbsFor(String? module, String title) => [
        if (module != null && module.trim().isNotEmpty && module.trim() != title.trim()) module.trim(),
        if (title.trim().isNotEmpty) title.trim(),
      ];
}

class _LiveScreenBar extends ConsumerStatefulWidget {
  const _LiveScreenBar({required this.bar});

  final AbosScreenBar bar;

  @override
  ConsumerState<_LiveScreenBar> createState() => _LiveScreenBarState();
}

class _LiveScreenBarState extends ConsumerState<_LiveScreenBar> {
  bool _menuOpen = false;

  @override
  Widget build(BuildContext context) {
    final bar = widget.bar;
    // ⓘ হোম আগে — হোম লেখেনি মানে সেশন নেই; তখন authStateProvider ছোঁয়াই হয় না (টেস্টে সেটা override ছাড়া ছুড়ে মারে)
    final top = ref.watch(topBarContextProvider);
    if (top == null) return bar.plain(context);
    final user = ref.watch(authStateProvider).user;
    if (user == null) return bar.plain(context);
    final unread = ref.watch(navBadgesProvider).unread;
    final head = AbosScreenBar.routeHead(context);
    final module = bar.module ?? (head == null ? null : top.moduleOf?.call(head));
    final switchBranch = top.switchBranch;

    return AbosTopBar(
      key: const ValueKey('screen-bar'),
      company: top.company,
      companyCode: top.companyCode,
      branch: top.branch,
      fallbackTitle: user.name,
      crumbs: AbosScreenBar.crumbsFor(module, bar.title),
      leading: TopBarLeading.back,
      onLeading: bar.onBack ?? () => AbosScreenBar.back(context),
      onOrgTap: switchBranch == null ? null : () => switchBranch(context),
      onSearch: () => top.search(context),
      onCopilot: top.copilot == null ? null : () => top.copilot!(context, bar.title),
      // ⭐ ＋ তৈরি করুন — নতুন দুই রূপে উপরের বারে (মালিক, ২০ নভেম্বর ২০২৬ "Ok")
      onCreate: top.quick == null ? null : () => top.quick!(context),
      onBell: () => top.openNotifications(context),
      onMessages: top.messages == null ? null : () => top.messages!(context),
      unread: unread,
      avatarName: user.name,
      avatar: top.avatar,
      menuOpen: _menuOpen,
      showAvatar: false,
      onAvatar: (y) async {
        setState(() => _menuOpen = true);
        try {
          await top.openMenu(context, y);
        } finally {
          if (mounted) setState(() => _menuOpen = false);
        }
      },
      screenActions: bar.actions,
      bottom: bar.bottom,
    );
  }
}
