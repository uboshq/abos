import 'package:flutter/material.dart';

import '../../core/navigation/nav_catalog.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// What a row or tile can tell about itself beyond its name — the counts the
/// app already knows (nothing here asks the server anything).
class NavCounts {
  const NavCounts({this.byModule = const {}, this.byPath = const {}, this.quietByPath = const {}});

  /// A red count on a module tile.
  final Map<String, int> byModule;

  /// A red count on a menu row.
  final Map<String, int> byPath;

  /// A grey line under a row — waiting to be sent is normal, not alarming.
  final Map<String, String> quietByPath;
}

/// ⭐ "সব মডিউল" — তিন কলামে রঙিন টাইল, সাদা রেখার আইকন, বাংলা নাম, অপেক্ষার কাজে লাল গোনা (মালিকের নমুনা, ছবি ২)।
class AllModulesPage extends StatelessWidget {
  const AllModulesPage({
    super.key,
    required this.modules,
    required this.counts,
    required this.onOpen,
    required this.onRefresh,
    this.header,
    this.loading = false,
  });

  final List<NavModule> modules;
  final NavCounts counts;
  final ValueChanged<NavModule> onOpen;
  final Future<void> Function() onRefresh;

  /// The search box, above the tiles.
  final Widget? header;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: onRefresh,
      child: CustomScrollView(
        // ⓘ কম টাইল হলেও টানা যায় — নিচে টানলে সিঙ্ক
        physics: const AlwaysScrollableScrollPhysics(),
        slivers: [
          if (header != null)
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(
                  AppSpacing.md, AppSpacing.md, AppSpacing.md, 0),
              sliver: SliverToBoxAdapter(child: header),
            ),
          if (loading)
            const SliverFillRemaining(
                hasScrollBody: false,
                child: Center(child: CircularProgressIndicator()))
          else if (modules.isEmpty)
            const SliverFillRemaining(
              hasScrollBody: false,
              child: EmptyState(
                icon: Icons.lock_outline,
                title: 'আপনার জন্য কোনো মেনু নেই',
                message: 'অফিসে জানান — আপনার অ্যাকাউন্টে কোনো অনুমতি বসানো নেই।',
              ),
            )
          else
            SliverPadding(
              padding: const EdgeInsets.all(AppSpacing.md),
              sliver: SliverGrid(
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 3,
                  mainAxisSpacing: AppSpacing.sm + 2,
                  crossAxisSpacing: AppSpacing.sm + 2,
                  childAspectRatio: 0.9,
                ),
                delegate: SliverChildBuilderDelegate(
                  (context, i) => _ModuleTile(
                    module: modules[i],
                    count: counts.byModule[modules[i].code] ?? 0,
                    onTap: () => onOpen(modules[i]),
                  ),
                  childCount: modules.length,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _ModuleTile extends StatelessWidget {
  const _ModuleTile({required this.module, required this.count, required this.onTap});

  final NavModule module;
  final int count;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Card(
      key: ValueKey('module-${module.code}'),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs, vertical: AppSpacing.sm),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Badge(
                backgroundColor: AppColors.badge,
                isLabelVisible: count > 0,
                label: Text(count > 99 ? '99+' : '$count'),
                offset: const Offset(4, -4),
                child: Container(
                  width: 54,
                  height: 54,
                  decoration: BoxDecoration(
                    color: module.color,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Icon(module.def.icon, color: Colors.white, size: 28),
                ),
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                module.label,
                maxLines: 2,
                textAlign: TextAlign.center,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// ⭐ একটা মডিউলের মেনু — ছোট শিরোনামের নিচে সারি, ডানে তীর আর জানা গোনা, উপরে "‹ সব মডিউল" (ছবি ৩)।
/// A row held down adds it to (or takes it out of) the home's "প্রিয়".
class ModuleMenuPage extends StatelessWidget {
  const ModuleMenuPage({
    super.key,
    required this.crumb,
    required this.onCrumb,
    required this.groups,
    required this.counts,
    required this.favourites,
    required this.onRow,
    required this.onFavourite,
  });

  /// "সব মডিউল", or the module's own name on a sub-list.
  final String crumb;
  final VoidCallback onCrumb;
  final List<NavGroup> groups;
  final NavCounts counts;
  final Set<String> favourites;
  final ValueChanged<NavRow> onRow;
  final ValueChanged<NavRow> onFavourite;

  @override
  Widget build(BuildContext context) {
    return ListView(
      key: const ValueKey('module-menu'),
      padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.lg),
      children: [
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: TextButton.icon(
            key: const ValueKey('menu-crumb'),
            onPressed: onCrumb,
            style: TextButton.styleFrom(
              foregroundColor: AppColors.primary,
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
            ),
            icon: const Icon(Icons.chevron_left, size: 20),
            label: Text(crumb),
          ),
        ),
        for (final group in groups) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.xs, AppSpacing.sm, 0, AppSpacing.xs),
            child: Text(group.title,
                style: const TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.onSurface)),
          ),
          Card(
            child: Column(children: [
              for (var i = 0; i < group.rows.length; i++) ...[
                if (i > 0) const Divider(height: 1, color: AppColors.border),
                _MenuRow(
                  row: group.rows[i],
                  count: counts.byPath[group.rows[i].path] ?? 0,
                  quiet: counts.quietByPath[group.rows[i].path],
                  favourite: favourites.contains(group.rows[i].path),
                  onTap: () => onRow(group.rows[i]),
                  onLongPress: group.rows[i].path == null
                      ? null
                      : () => onFavourite(group.rows[i]),
                ),
              ],
            ]),
          ),
        ],
      ],
    );
  }
}

class _MenuRow extends StatelessWidget {
  const _MenuRow({
    required this.row,
    required this.count,
    required this.quiet,
    required this.favourite,
    required this.onTap,
    required this.onLongPress,
  });

  final NavRow row;
  final int count;
  final String? quiet;
  final bool favourite;
  final VoidCallback onTap;
  final VoidCallback? onLongPress;

  @override
  Widget build(BuildContext context) {
    final muted = row.planned;
    return ListTile(
      key: ValueKey('row-${row.id}'),
      dense: true,
      minVerticalPadding: 12,
      enabled: !muted,
      title: Text(row.label,
          style: TextStyle(
              fontSize: 14.5,
              color: muted ? AppColors.onSurfaceMuted : AppColors.onSurface)),
      subtitle: muted
          ? const Text('শীঘ্রই আসছে', style: TextStyle(fontSize: 11.5))
          : quiet == null
              ? null
              : Text(quiet!, style: const TextStyle(fontSize: 11.5, color: AppColors.onSurfaceMuted)),
      trailing: muted
          ? null
          : Row(mainAxisSize: MainAxisSize.min, children: [
              if (favourite)
                const Padding(
                  padding: EdgeInsets.only(right: AppSpacing.xs),
                  child: Icon(Icons.star_rounded, size: 18, color: AppColors.warning),
                ),
              if (count > 0)
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
                  decoration: BoxDecoration(
                    color: AppColors.badge,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Text(count > 99 ? '99+' : '$count',
                      style: const TextStyle(
                          color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.w700)),
                ),
              const Icon(Icons.chevron_right, color: AppColors.onSurfaceMuted),
            ]),
      onTap: muted ? null : onTap,
      onLongPress: muted ? null : onLongPress,
    );
  }
}
