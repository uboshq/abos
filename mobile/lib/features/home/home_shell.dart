import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_state.dart';
import '../../core/auth/auth_user.dart';
import '../../core/auth/session_profile.dart';
import '../../core/auth/session_repository.dart';
import '../../core/menu/me_api.dart';
import '../../core/menu/menu_item.dart';
import '../../core/menu/menu_repository.dart';
import '../../core/menu/module_gate.dart';
import '../../core/orders/delivery_order_api.dart';
import '../../core/books/collection_entry.dart';
import '../../core/records/notice_bar.dart';
import '../../core/records/notification_record.dart';
import '../../core/records/today_record.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/update/app_version_check.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/workspace/workspace_switcher.dart';
import '../update/app_update_tile.dart';
import '../update/update_gate.dart';
import 'home_dashboard.dart';
import 'notice_ticker.dart';
import 'workspace_picker.dart';

/// The signed-in person's home: three tabs under one header that always
/// says which company and branch the phone is looking at.
///
/// <p><b>হোম</b> — the day's figures under a greeting ([HomeDashboard]).
/// <b>অ্যাপ</b> — the grid of what `GET /me` says they may do and this build
/// can actually open; see [MenuRepository]'s own doc comment for the two
/// filters a row passes through before it becomes a tile. <b>আরও</b> — who is
/// signed in, the sync status, and the way out.
///
/// <p>The header names the company because docs/Contract §৮ rule খ says the
/// figures must, and the header is the one place that is on screen for every
/// tab. The names come from `/me`; when that cannot be reached they come from
/// the last `/me` that could (see [SessionRepository.readOrg]); only when the
/// phone has never heard does the header fall back to the person's own name.
///
/// <p>⭐ Tapping the header opens the company/branch picker (0.4.2) — only
/// when `/me` lists more than one company or branch, since a picker with
/// nothing to pick is a button that does nothing.
class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({
    super.key,
    this.loadHome,
    this.fetchToday,
    this.lastKnownToday,
    this.readCachedOrg,
    this.cacheOrg,
    this.checkUpdate,
    this.now,
    this.reloadProfile,
    this.switcher,
    this.fetchNotifications,
    this.fetchNoticeBar,
  });

  /// Seams — the real ones need a server or a secure store.
  final Future<HomeMenu> Function(AuthUser user)? loadHome;
  final Future<TodayRecord> Function()? fetchToday;
  final TodayRecord? Function()? lastKnownToday;
  final Future<OrgSnapshot?> Function()? readCachedOrg;
  final Future<void> Function(OrgSnapshot org)? cacheOrg;
  final Future<UpdateStatus> Function()? checkUpdate;
  final DateTime Function()? now;

  /// `GET /me` again, for the picker after a company change.
  final Future<SessionProfile?> Function()? reloadProfile;
  final WorkspaceSwitcher? switcher;

  /// Seam for the bell's count — the real one needs a server.
  final Future<NotificationPage> Function()? fetchNotifications;

  /// Seam for the running notice line — the real one needs a server.
  final Future<List<NoticeBarItem>> Function()? fetchNoticeBar;

  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> {
  static const MenuRepository _menuRepository = MenuRepository();

  int _tab = 0;
  List<MenuItem> _items = const [];
  bool _menuLoading = true;
  OrgSnapshot? _org;
  SessionProfile? _profile;

  /// `/me`-র পদবি আর ছবি — লগইনের উত্তরে নেই, তাই আলাদা রাখা ([[HomeMenu.me]])।
  String? _designation;
  String? _avatarUrl;

  /// How many bell messages are unread — null until the server has said.
  int? _unread;

  Future<void> _loadUnread() async {
    try {
      final page = await (widget.fetchNotifications ?? NotificationApi.fetch)();
      if (mounted) setState(() => _unread = page.unread);
    } catch (_) {
      // ⓘ পুরনো সার্ভার বা সিগন্যাল নেই — ঘণ্টা থাকে, কেবল গোনা ছাড়া
    }
  }

  /// Bumped after a move, so the day's figures are asked for again — they
  /// belong to the company and branch they were fetched for.
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _load();
    _loadUnread();
  }

  Future<void> _load() async {
    final user = ref.read(authStateProvider).user;
    if (user == null) return;

    // The cached names first, so the header is right on the first frame of
    // a phone with no signal; `/me` overwrites them the moment it answers.
    final cached =
        await (widget.readCachedOrg ?? SessionRepository.instance.readOrg)();
    if (!mounted) return;
    if (cached != null && !cached.isEmpty && _org == null) {
      setState(() => _org = cached);
    }
    if (cached?.phoneModules != null &&
        ref.read(phoneModulesProvider) == null) {
      ref.read(phoneModulesProvider.notifier).state = cached!.phoneModules;
    }

    final home = await (widget.loadHome ?? _menuRepository.homeFor)(user);
    if (!mounted) return;
    final profile = home.profile;
    final fresh = profile == null
        ? null
        : OrgSnapshot(
            company: profile.company.name,
            branch: profile.viewAllBranches && profile.branches.length > 1
                ? 'সব শাখা'
                : profile.branch.name,
            phoneModules: profile.phoneModules,
          );
    if (profile?.phoneModules != null) {
      ref.read(phoneModulesProvider.notifier).state = profile!.phoneModules;
    }
    if (profile != null) {
      ref.read(ordersReplaceDoProvider.notifier).state =
          profile.ordersReplaceDo;
      ref.read(mayCollectProvider.notifier).state = profile.mayCollect;
    }
    final modules = ref.read(phoneModulesProvider);
    setState(() {
      // ⭐ The company's phone switches ([ModuleGate]) — `/me`'s menu is
      // already filtered, this catches this app's own tiles too.
      _items = ModuleGate.visible(home.items, modules);
      _menuLoading = false;
      if (profile != null) _profile = profile;
      if (home.me != null) {
        _designation = home.me!.designation;
        _avatarUrl = home.me!.avatarUrl;
      }
      if (fresh != null && !fresh.isEmpty) _org = fresh;
    });
    if (fresh != null && !fresh.isEmpty) {
      await (widget.cacheOrg ?? SessionRepository.instance.saveOrg)(fresh);
    }
  }

  Future<void> _openPicker() async {
    final profile = _profile;
    if (profile == null || !profile.canSwitch) return;
    final moved = await showWorkspacePicker(
      context,
      profile: profile,
      reload: widget.reloadProfile ?? _fetchProfile,
      switcher: widget.switcher,
    );
    if (!moved || !mounted) return;
    setState(() => _generation++);
    await _load();
  }

  static Future<SessionProfile?> _fetchProfile() async {
    try {
      return (await MeApi.fetch()).profile;
    } catch (_) {
      return null;
    }
  }

  /// The day's payload names its company too (docs/Contract §৮). When `/me`
  /// never answered and nothing is cached, that is still a real name for the
  /// header — better than the person's own, which says nothing about whose
  /// figures are on screen.
  void _onRecord(TodayRecord record) {
    if (_org != null && !_org!.isEmpty) return;
    final company = record.company;
    if (company == null) return;
    setState(() =>
        _org = OrgSnapshot(company: company, branch: record.branch ?? ''));
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authStateProvider).user;
    if (user == null) {
      // app_router.dart's redirect keeps this from being reachable
      // signed-out, but a screen must still not crash on a null it can
      // technically be handed mid-transition.
      return const Scaffold(body: SizedBox.shrink());
    }

    final org = _org;
    final headerNamesOrg = org != null && org.company.isNotEmpty;

    return Scaffold(
      appBar: AppBar(
        titleSpacing: AppSpacing.md,
        title: _Header(
          title: headerNamesOrg ? org.company : user.name,
          subtitle: headerNamesOrg
              ? (org.branch.isNotEmpty ? org.branch : null)
              // ⭐ পদবি, রোল নয় (মালিক, ২ অক্টোবর ২০২৬)
              : _designation,
          onTap: (_profile?.canSwitch ?? false) ? _openPicker : null,
        ),
        // ⭐ মালিক, ৬ অক্টোবর ২০২৬: সবুজ মাথার একদম ডানে ব্যবহারকারীর ছবি (চাপলে প্রোফাইল), তার পাশে ঘণ্টা
        actions: [
          _SyncAction(onTap: () => context.go('/home/sync-status')),
          _BellAction(
            unread: _unread,
            onTap: () async {
              await context.push('/home/notifications');
              if (mounted) _loadUnread();
            },
          ),
          _AvatarAction(
            name: user.name,
            avatarUrl: _avatarUrl,
            onTap: () => setState(() => _tab = 2),
          ),
          const SizedBox(width: AppSpacing.xs),
        ],
      ),
      // docs/Contract §৬. Wrapped here rather than around the whole app: the
      // login screen must stay reachable for somebody whose build is too old,
      // because signing in is how they find out anything at all, and a wall
      // in front of it would leave them with a blank app and no explanation.
      body: UpdateGate(
        check: widget.checkUpdate,
        child: Column(children: [
          // ⭐ ওয়েবের চলমান নোটিশ — মাথার ঠিক নিচে, প্রতিটা ট্যাবে (মালিক, ৬ অক্টোবর ২০২৬); কিছু না থাকলে চুপ।
          // ⓘ কোম্পানি বদলালে নতুন key — আগের কোম্পানির নোটিশ এক মুহূর্তও থাকে না
          NoticeTicker(
              key: ValueKey('notice-ticker-$_generation'),
              fetch: widget.fetchNoticeBar),
          Expanded(
              child: IndexedStack(
            index: _tab,
            children: [
              HomeDashboard(
                key: ValueKey('home-dashboard-$_generation'),
                user: user,
                items: _items,
                headerNamesOrg: headerNamesOrg,
                fetchToday: widget.fetchToday,
                lastKnownToday: widget.lastKnownToday,
                onRecord: _onRecord,
                now: widget.now,
              ),
              _menuLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _items.isEmpty
                      ? const EmptyState(
                          icon: Icons.lock_outline,
                          title: 'আপনার জন্য কোনো মেনু নেই',
                          message:
                              'অফিসে জানান — আপনার অ্যাকাউন্টে কোনো অনুমতি বসানো নেই।',
                        )
                      : _MenuGrid(items: _items),
              _MoreTab(
                  user: user,
                  org: org,
                  designation: _designation,
                  avatarUrl: _avatarUrl),
            ],
          )),
        ]),
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (index) => setState(() => _tab = index),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'হোম',
          ),
          NavigationDestination(
            icon: Icon(Icons.grid_view_outlined),
            selectedIcon: Icon(Icons.grid_view),
            label: 'অ্যাপ',
          ),
          NavigationDestination(
            icon: Icon(Icons.menu),
            label: 'আরও',
          ),
        ],
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.title, this.subtitle, this.onTap});

  final String title;
  final String? subtitle;

  /// Opens the company/branch picker; null when there is nothing to pick.
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final names = _names();
    if (onTap == null) return names;
    return InkWell(
      key: const ValueKey('workspace-header'),
      onTap: onTap,
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Flexible(child: names),
          const SizedBox(width: AppSpacing.xs),
          const Icon(Icons.swap_horiz, size: 18),
        ],
      ),
    );
  }

  Widget _names() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(title,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
        if (subtitle != null && subtitle!.isNotEmpty)
          Text(subtitle!,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 12, color: Colors.white70)),
      ],
    );
  }
}

/// ⭐ The bell — this person's messages (server `NotificationApiController`),
/// with how many are unread.
class _BellAction extends StatelessWidget {
  const _BellAction({required this.unread, required this.onTap});

  final int? unread;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final count = unread ?? 0;
    return IconButton(
      key: const ValueKey('header-bell'),
      tooltip: 'নোটিফিকেশন',
      onPressed: onTap,
      icon: Badge(
        isLabelVisible: count > 0,
        label: Text(count > 99 ? '99+' : '$count'),
        child: const Icon(Icons.notifications_outlined),
      ),
    );
  }
}

/// ⭐ The person's photo at the far right of the green header — a tap opens
/// the profile (the "আরও" tab, which is the profile page).
class _AvatarAction extends StatelessWidget {
  const _AvatarAction(
      {required this.name, required this.avatarUrl, required this.onTap});

  final String name;
  final String? avatarUrl;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      key: const ValueKey('header-avatar'),
      tooltip: 'প্রোফাইল',
      onPressed: onTap,
      icon: CircleAvatar(
        radius: 15,
        backgroundColor: Colors.white,
        foregroundColor: AppColors.primary,
        foregroundImage: avatarUrl == null ? null : NetworkImage(avatarUrl!),
        child: Text(name.trim().isEmpty ? '?' : name.trim().characters.first,
            style: const TextStyle(fontWeight: FontWeight.w700)),
      ),
    );
  }
}

/// The sync icon, with how many orders are still on this phone. A plain
/// count, not a status colour: waiting for a connection is normal, not a
/// problem, so it must not read the way a red badge would.
class _SyncAction extends StatelessWidget {
  const _SyncAction({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final pending = SyncEngine.instance.pendingCount;
    return IconButton(
      tooltip: 'সিঙ্কের অবস্থা',
      onPressed: onTap,
      icon: Badge(
        isLabelVisible: pending > 0,
        label: Text('$pending'),
        child: const Icon(Icons.sync_outlined),
      ),
    );
  }
}

class _MenuGrid extends StatelessWidget {
  const _MenuGrid({required this.items});

  final List<MenuItem> items;

  @override
  Widget build(BuildContext context) {
    return GridView.builder(
      padding: const EdgeInsets.all(AppSpacing.md),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        mainAxisSpacing: AppSpacing.md,
        crossAxisSpacing: AppSpacing.md,
        childAspectRatio: 1.1,
      ),
      itemCount: items.length,
      itemBuilder: (context, index) {
        final item = items[index];
        return _MenuTile(item: item);
      },
    );
  }
}

class _MenuTile extends ConsumerWidget {
  const _MenuTile({required this.item});

  final MenuItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // A "coming soon" row is shown, not hidden — matching the web side's own
    // convention — but dimmed and inert, per the /me briefing's point ৪:
    // planned means visible, never tappable.
    final color = item.planned ? AppColors.onSurfaceMuted : AppColors.primary;

    return Card(
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap:
            item.planned ? null : () => context.go('/home/${item.routeName}'),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(item.icon, size: 36, color: color),
              const SizedBox(height: AppSpacing.sm),
              Text(
                item.label,
                textAlign: TextAlign.center,
                style: TextStyle(
                    fontWeight: FontWeight.w600,
                    color: item.planned ? AppColors.onSurfaceMuted : null),
              ),
              if (item.planned) ...[
                const SizedBox(height: AppSpacing.xs),
                const Text('শীঘ্রই আসছে',
                    style: TextStyle(
                        fontSize: 11, color: AppColors.onSurfaceMuted)),
              ] else if (item.key == 'sales.order.index') ...[
                const SizedBox(height: AppSpacing.xs),
                const _PendingBadge(),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

/// "N অপেক্ষমাণ" — a plain count, not a status colour: waiting for a
/// connection is normal, not a problem, so it must not read the way a red
/// badge would. Rejected changes have their own, separate warning colour on
/// the sync-status screen once someone opens it.
class _PendingBadge extends StatelessWidget {
  const _PendingBadge();

  @override
  Widget build(BuildContext context) {
    final pending = SyncEngine.instance.pendingCount;
    if (pending == 0) return const SizedBox.shrink();
    return Text(
      '$pending অপেক্ষমাণ',
      style: const TextStyle(fontSize: 11, color: AppColors.onSurfaceMuted),
    );
  }
}

/// Who is signed in, where, and the way out. Signing out asks first: on a
/// shared phone one stray tap must not cost somebody their session and the
/// login that follows.
class _MoreTab extends ConsumerWidget {
  const _MoreTab(
      {required this.user,
      required this.org,
      this.designation,
      this.avatarUrl});

  final String? designation;
  final String? avatarUrl;

  final AuthUser user;
  final OrgSnapshot? org;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: [
        Card(
          child: ListTile(
            // ⭐ ছবি আর পদবি — মালিক, ২ অক্টোবর ২০২৬: "profile photo dekhay na, role nadekiye designation dekhabe"
            leading: CircleAvatar(
              key: const ValueKey('profile-avatar'),
              backgroundColor: AppColors.primary,
              foregroundColor: AppColors.onPrimary,
              foregroundImage:
                  avatarUrl == null ? null : NetworkImage(avatarUrl!),
              child: Text(user.name.isEmpty ? '?' : user.name.characters.first),
            ),
            title: Text(user.name,
                style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text([
              if (designation != null) designation!,
              if (user.email.isNotEmpty) user.email,
            ].join('\n')),
            isThreeLine: designation != null && user.email.isNotEmpty,
          ),
        ),
        if (org != null && !org!.isEmpty) ...[
          const SizedBox(height: AppSpacing.sm),
          Card(
            child: ListTile(
              leading: const Icon(Icons.business_outlined),
              title: Text(org!.company),
              subtitle: org!.branch.isEmpty ? null : Text(org!.branch),
            ),
          ),
        ],
        const SizedBox(height: AppSpacing.sm),
        Card(
          child: ListTile(
            leading: const Icon(Icons.sync_outlined),
            title: const Text('সিঙ্কের অবস্থা'),
            subtitle: const Text('কী গেছে, কী যায়নি, কী ফেরত এসেছে'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/home/sync-status'),
          ),
        ),
        const SizedBox(height: AppSpacing.sm),
        // ⭐ অ্যাপ হালনাগাদ — হাতে যাচাই (মালিক, ৭ অক্টোবর ২০২৬)
        const AppUpdateTile(),
        const SizedBox(height: AppSpacing.lg),
        OutlinedButton.icon(
          style: OutlinedButton.styleFrom(
            foregroundColor: AppColors.danger,
            side: const BorderSide(color: AppColors.danger),
            padding: const EdgeInsets.symmetric(vertical: 14),
          ),
          onPressed: () => _confirmLogout(context, ref),
          icon: const Icon(Icons.logout),
          label: const Text('বেরিয়ে যান'),
        ),
      ],
    );
  }

  Future<void> _confirmLogout(BuildContext context, WidgetRef ref) async {
    final pending = SyncEngine.instance.pendingCount;
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('বেরিয়ে যাবেন?'),
        content: Text(pending > 0
            ? 'এই ফোনে $pending টি অর্ডার এখনো পাঠানো হয়নি। বেরিয়ে গেলেও '
                'ওগুলো ফোনেই থাকবে, পরের বার ঢুকে পাঠানো যাবে।'
            : 'আবার ঢুকতে পাসওয়ার্ড লাগবে।'),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: const Text('থাক'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('বেরিয়ে যান'),
          ),
        ],
      ),
    );
    if (yes == true) {
      await ref.read(authStateProvider.notifier).logout();
    }
  }
}
