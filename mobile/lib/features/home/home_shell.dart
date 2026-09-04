import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_state.dart';
import '../../core/menu/menu_item.dart';
import '../../core/menu/menu_repository.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// The signed-in person's home: their name and role, a grid of what `GET
/// /me` says they may do and this app build can actually open — see
/// [MenuRepository]'s own doc comment for the two filters a row passes
/// through before it becomes a tile here.
class HomeShell extends ConsumerWidget {
  const HomeShell({super.key});

  static const MenuRepository _menuRepository = MenuRepository();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authStateProvider).user;
    if (user == null) {
      // app_router.dart's redirect keeps this from being reachable
      // signed-out, but a screen must still not crash on a null it can
      // technically be handed mid-transition.
      return const Scaffold(body: SizedBox.shrink());
    }

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(user.name,
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            Text(
              user.roles.isEmpty ? '' : user.roles.join(', '),
              style: const TextStyle(fontSize: 12, color: Colors.white70),
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'বেরিয়ে যান',
            onPressed: () => ref.read(authStateProvider.notifier).logout(),
          ),
        ],
      ),
      body: FutureBuilder<List<MenuItem>>(
        future: _menuRepository.menuFor(user),
        builder: (context, snapshot) {
          final items = snapshot.data ?? const [];
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const Center(child: CircularProgressIndicator());
          }
          if (items.isEmpty) {
            return const EmptyState(
              icon: Icons.lock_outline,
              title: 'আপনার জন্য কোনো মেনু নেই',
              message: 'অফিসে জানান — আপনার অ্যাকাউন্টে কোনো অনুমতি বসানো নেই।',
            );
          }
          return _MenuGrid(items: items);
        },
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
