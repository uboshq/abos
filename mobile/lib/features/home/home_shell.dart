import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_state.dart';
import '../../core/auth/auth_user.dart';
import '../../core/auth/session_repository.dart';
import '../../core/menu/menu_item.dart';
import '../../core/menu/menu_repository.dart';
import '../../core/records/today_record.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/update/app_version_check.dart';
import '../../core/widgets/empty_state.dart';
import '../update/update_gate.dart';
import 'home_dashboard.dart';

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
  });

  /// Seams — the real ones need a server or a secure store.
  final Future<HomeMenu> Function(AuthUser user)? loadHome;
  final Future<TodayRecord> Function()? fetchToday;
  final TodayRecord? Function()? lastKnownToday;
  final Future<OrgSnapshot?> Function()? readCachedOrg;
  final Future<void> Function(OrgSnapshot org)? cacheOrg;
  final Future<UpdateStatus> Function()? checkUpdate;
  final DateTime Function()? now;

  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> {
  static const MenuRepository _menuRepository = MenuRepository();

  int _tab = 0;
  List<MenuItem> _items = const [];
  bool _menuLoading = true;
  OrgSnapshot? _org;

  @override
  void initState() {
    super.initState();
    _load();
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

    final home = await (widget.loadHome ?? _menuRepository.homeFor)(user);
    if (!mounted) return;
    final profile = home.profile;
    final fresh = profile == null
        ? null
        : OrgSnapshot(company: profile.company.name, branch: profile.branch.name);
    setState(() {
      _items = home.items;
      _menuLoading = false;
      if (fresh != null && !fresh.isEmpty) _org = fresh;
    });
    if (fresh != null && !fresh.isEmpty) {
      await (widget.cacheOrg ?? SessionRepository.instance.saveOrg)(fresh);
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
              : (user.roles.isEmpty ? null : user.roles.join(', ')),
        ),
        actions: [
          _SyncAction(onTap: () => context.go('/home/sync-status')),
          const SizedBox(width: AppSpacing.xs),
        ],
      ),
      // docs/Contract §৬. Wrapped here rather than around the whole app: the
      // login screen must stay reachable for somebody whose build is too old,
      // because signing in is how they find out anything at all, and a wall
      // in front of it would leave them with a blank app and no explanation.
      body: UpdateGate(
        check: widget.checkUpdate,
        child: IndexedStack(
          index: _tab,
          children: [
            HomeDashboard(
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
            _MoreTab(user: user, org: org),
          ],
        ),
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
  const _Header({required this.title, this.subtitle});

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
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
        onTap: item.planned
            ? null
            : () => context.go('/home/${item.routeName}'),
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
                    style: TextStyle(fontSize: 11, color: AppColors.onSurfaceMuted)),
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
  const _MoreTab({required this.user, required this.org});

  final AuthUser user;
  final OrgSnapshot? org;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: [
        Card(
          child: ListTile(
            leading: CircleAvatar(
              backgroundColor: AppColors.primary,
              foregroundColor: AppColors.onPrimary,
              child: Text(user.name.isEmpty ? '?' : user.name.characters.first),
            ),
            title: Text(user.name,
                style: const TextStyle(fontWeight: FontWeight.w700)),
            subtitle: Text([
              if (user.roles.isNotEmpty) user.roles.join(', '),
              if (user.email.isNotEmpty) user.email,
            ].join('\n')),
            isThreeLine: user.roles.isNotEmpty && user.email.isNotEmpty,
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
