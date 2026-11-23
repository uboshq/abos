import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/navigation/nav_catalog.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/state_views.dart';
import 'nav_search.dart' show webRowNote;

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
          // ⭐ ধীর নেটে ধূসর কাঠামো, ঘোরানো চাকা নয় (বারের নকশা ৩, অংশ ৭: "ধীর নেট")
          if (loading)
            const SliverToBoxAdapter(
                child: Padding(
                    padding: EdgeInsets.all(AppSpacing.md),
                    child: LoadingSkeleton()))
          else if (modules.isEmpty)
            SliverFillRemaining(
              hasScrollBody: false,
              child: EmptyState(
                icon: Icons.lock_outline,
                title: context.l10n.homeNoMenu,
                message: context.l10n.homeNoMenuHint,
              ),
            )
          else
            // ⭐ তিনটা করে পাশাপাশি, ঘরের উচ্চতা বাঁধা নয় — বড় অক্ষরে (২০০%) মডিউলের নাম ভাঙে না
            // (নকশার নিয়ম ০.৪.৩৮ §২, a4, ১৯ অক্টোবর ২০২৬)। ⓘ আগে SliverGrid-এর childAspectRatio উচ্চতা বেঁধে রাখত।
            SliverPadding(
              padding: const EdgeInsets.all(AppSpacing.md),
              sliver: SliverToBoxAdapter(
                child: LayoutBuilder(builder: (context, box) {
                  const gap = AppSpacing.sm + 2;
                  final width = (box.maxWidth - 2 * gap) / 3;
                  return Wrap(
                    spacing: gap,
                    runSpacing: gap,
                    children: [
                      for (final m in modules)
                        SizedBox(
                          width: width,
                          child: _ModuleTile(
                            module: m,
                            count: counts.byModule[m.code] ?? 0,
                            onTap: () => onOpen(m),
                          ),
                        ),
                    ],
                  );
                }),
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
    // ⭐ সব সারি "শীঘ্রই আসছে" — টাইল থাকে, ধূসর (মালিক, ১২ অক্টোবর ২০২৬)
    final live = module.live;
    // ⭐ নিজের চিহ্নের টাইল (Copilot-এর জীবন্ত "Ai") — পেছনে কোনো পট নেই (মালিক, ২৩ নভেম্বর ২০২৬, নমুনা v2)
    final mark = module.def.mark;
    // ⭐ রং থিমের ভূমিকা থেকে (নকশার নিয়ম ০.৪.৩৮ §১)
    return Card(
      key: ValueKey('module-${module.code}'),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs, vertical: AppSpacing.sm),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Badge(
                backgroundColor: AppColors.badge,
                isLabelVisible: count > 0,
                label: Text(count > 99 ? '99+' : '$count'),
                offset: const Offset(4, -4),
                child: mark != null
                    ? SizedBox.square(
                        key: ValueKey('module-mark-${module.code}'),
                        dimension: 54,
                        child: Center(child: mark(48)),
                      )
                    : Container(
                        width: 54,
                        height: 54,
                        decoration: BoxDecoration(
                          // ⓘ সাদা আইকনের সাথে অন্তত ৩:১ — মডিউলের রঙের গাঢ় সুর ([[AppColors.moduleSolid]])
                          color: live ? AppColors.moduleSolid(module.code) : context.palette.border,
                          borderRadius: BorderRadius.circular(14),
                        ),
                        // ⓘ moduleSolid সাদার সাথে ৩:১ মেপে বানানো, তাই আইকন দুই মোডেই সাদা (AppColors.onPrimary);
                        // ধূসর (শীঘ্রই আসছে) টাইলে থিমের মলিন রং
                        child: Icon(module.def.icon,
                            color: live ? AppColors.onPrimary : context.palette.onSurfaceMuted, size: 28), // dark-ok
                      ),
              ),
              const SizedBox(height: AppSpacing.sm),
              Text(
                module.label,
                maxLines: 2,
                textAlign: TextAlign.center,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: live ? context.palette.onSurface : context.palette.onSurfaceMuted),
              ),
              if (!live)
                Text(context.l10n.homeComingSoon,
                    style: TextStyle(fontSize: 10.5, color: context.palette.onSurfaceMuted)),
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
    this.note,
  });

  /// A line above the rows — said on a module whose rows are all coming.
  final String? note;

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
    // ⭐ রং থিমের ভূমিকা থেকে (নকশার নিয়ম ০.৪.৩৮ §১)
    final cs = Theme.of(context).colorScheme;
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
              foregroundColor: cs.primary,
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
            ),
            icon: const Icon(Icons.chevron_left, size: 20),
            label: Text(crumb),
          ),
        ),
        if (note != null)
          Container(
            key: const ValueKey('menu-note'),
            margin: const EdgeInsets.only(bottom: AppSpacing.sm),
            padding: const EdgeInsets.all(AppSpacing.md),
            decoration: BoxDecoration(
              color: context.palette.pendingSurface,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Row(children: [
              Icon(Icons.schedule_outlined, size: 20, color: context.palette.pending),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                  child: Text(note!,
                      style: TextStyle(fontSize: 13, color: context.palette.pending))),
            ]),
          ),
        for (final group in groups) ...[
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.xs, AppSpacing.sm, 0, AppSpacing.xs),
            child: Text(group.title,
                style: TextStyle(
                    fontSize: 13, fontWeight: FontWeight.w700, color: context.palette.onSurface)),
          ),
          Card(
            child: Column(children: [
              for (var i = 0; i < group.rows.length; i++) ...[
                if (i > 0) Divider(height: 1, color: context.palette.border),
                _MenuRow(
                  row: group.rows[i],
                  count: counts.byPath[group.rows[i].path] ?? 0,
                  // ⭐ ওয়েবের পর্দা — নামের নিচে ছোট ধূসর লেখা (১৩ অক্টোবর ২০২৬)
                  quiet: counts.quietByPath[group.rows[i].path] ??
                      (group.rows[i].web ? webRowNote : null),
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
              color: muted ? context.palette.onSurfaceMuted : context.palette.onSurface)),
      subtitle: muted
          ? Text(context.l10n.homeComingSoon, style: const TextStyle(fontSize: 11.5))
          : quiet == null
              ? null
              : Text(quiet!, style: TextStyle(fontSize: 11.5, color: context.palette.onSurfaceMuted)),
      trailing: muted
          ? null
          : Row(mainAxisSize: MainAxisSize.min, children: [
              if (favourite)
                Padding(
                  padding: const EdgeInsets.only(right: AppSpacing.xs),
                  child: Icon(Icons.star_rounded, size: 18, color: context.palette.warning),
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
                          color: AppColors.onPrimary, fontSize: 11.5, fontWeight: FontWeight.w700)), // dark-ok
                ),
              Icon(Icons.chevron_right, color: context.palette.onSurfaceMuted),
            ]),
      onTap: muted ? null : onTap,
      onLongPress: muted ? null : onLongPress,
    );
  }
}
