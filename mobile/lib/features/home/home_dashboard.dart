import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_user.dart';
import '../../core/menu/menu_item.dart';
import '../../core/navigation/nav_catalog.dart';
import '../../core/navigation/nav_prefs.dart';
import '../../core/records/today_record.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/row_icon.dart';
import '../today/today_panel.dart';

/// The হোম tab: the search box, the day's figures ("আজ"), the person's own
/// favourites ("প্রিয়") and what they opened last ("সাম্প্রতিক") — the owner's
/// mockup of 12 Oct 2026, skin ১, picture ১.
///
/// <p>Built on [TodayPanel] rather than beside it, so one pull refreshes the
/// lot, and so the figures here can never drift from the ones on the "আজকের
/// হিসাব" page. Nothing new is counted here: "আজ" is the same panel, the same
/// server figures, as before.
class HomeDashboard extends StatelessWidget {
  const HomeDashboard({
    super.key,
    required this.user,
    required this.items,
    this.headerNamesOrg = false,
    this.fetchToday,
    this.lastKnownToday,
    this.onRecord,
    this.onPull,
    this.now,
    this.search,
    this.favourites = const [],
    this.recents = const [],
    this.onOpen,
    this.onUnfavourite,
  });

  final AuthUser user;

  /// The menu this person has.
  final List<MenuItem> items;

  /// True when the app bar above already names the company and branch; the
  /// panel then leaves its own origin line off rather than saying it twice.
  final bool headerNamesOrg;

  final Future<TodayRecord> Function()? fetchToday;
  final TodayRecord? Function()? lastKnownToday;
  final void Function(TodayRecord record)? onRecord;

  /// The full sync, run on a pull before the figures refresh ([TodayPanel.onPull]).
  final Future<void> Function()? onPull;

  /// Seam for the greeting — a test can ask for eight in the evening.
  final DateTime Function()? now;

  /// The search box, at the very top.
  final Widget? search;

  /// "প্রিয়" — the screens this person pinned, at most six, only the ones they
  /// may still open.
  final List<NavRow> favourites;

  /// "সাম্প্রতিক" — what this person opened last on this phone.
  final List<RecentEntry> recents;

  /// Opens `/home/<path>`; null falls back to the router.
  final void Function(String path, String label)? onOpen;

  /// A favourite held down — take it off the home.
  final ValueChanged<NavRow>? onUnfavourite;

  @override
  Widget build(BuildContext context) {
    // ⭐ রং কেবল থিমের ভূমিকা থেকে — আলো আর অন্ধকার দুই মোডেই পড়া যায় (নকশার নিয়ম ০.৪.৩৮ §১, a4, ১৯ অক্টোবর ২০২৬)
    final cs = Theme.of(context).colorScheme;
    // ⓘ "আজ"-এর লোড, ভুল আর খালি অবস্থা TodayPanel-এর নিজের (today_panel.dart) — হোম নিজে কিছু আনে না
    return TodayPanel(
      fetch: fetchToday,
      lastKnown: lastKnownToday,
      showOrigin: !headerNamesOrg,
      onRecord: onRecord,
      onPull: onPull,
      onOpenSales: () => _go(context, 'orders', 'অর্ডারের অবস্থা'),
      onOpenDues: () => _go(context, 'dues', 'বকেয়া তালিকা'),
      onOpenApprovals: () => _go(context, 'approvals', 'অনুমোদন'),
      // ⓘ সংখ্যাগুলোর নিচে — আজকের অঙ্কই পর্দার প্রথম জিনিস থাকে
      trailing: [
        const SizedBox(height: AppSpacing.sm),
        // ⭐ ব্যবসার ড্যাশবোর্ড — মজুদ, বিক্রি, হিসাব … (মালিক, ৪ অক্টোবর ২০২৬: "egulo nadile bujbo kikore kihocche")
        Card(
          key: const Key('home-dashboards'),
          child: ListTile(
            // ⭐ নকশার সারি (নকশা ৩, পাতা ৩): RowIcon ৪০dp · নাম 16 w600 · নিচে মলিন 13 · সারি অন্তত ৭২dp
            minTileHeight: 72,
            leading: const RowIcon(Icons.insights_outlined),
            title: const Text('ব্যবসার ড্যাশবোর্ড',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            subtitle: Text('মজুদ, বিক্রি, হিসাব — ওয়েবের একই সংখ্যা',
                style: TextStyle(fontSize: 13, color: cs.onSurfaceVariant)),
            trailing: Icon(Icons.chevron_right, color: cs.onSurfaceVariant),
            onTap: () => _go(context, 'dashboards', 'ব্যবসার ড্যাশবোর্ড'),
          ),
        ),
        const SizedBox(height: AppSpacing.md),
        const _SectionTitle('প্রিয়', icon: Icons.star_outline_rounded),
        if (favourites.isEmpty)
          const Padding(
            key: ValueKey('favourites-empty'),
            padding: EdgeInsets.symmetric(vertical: AppSpacing.sm),
            child: _Muted(
                'কোনো মডিউলের মেনুতে একটা সারি চেপে ধরে রাখুন — এখানে এসে বসবে (ছয়টা পর্যন্ত)।'),
          )
        else
          // ⭐ চারটা করে পাশাপাশি, কিন্তু ঘরের উচ্চতা বাঁধা নয় — বড় অক্ষরে (২০০%) নাম ভাঙে না, ঘর নিজের মাপে বাড়ে
          // (নকশার নিয়ম ০.৪.৩৮ §২, a4, ১৯ অক্টোবর ২০২৬)। ⓘ আগে GridView-এর childAspectRatio উচ্চতা বেঁধে রাখত।
          LayoutBuilder(
            key: const ValueKey('favourites'),
            builder: (context, box) {
              final width = (box.maxWidth - 3 * AppSpacing.sm) / 4;
              return Wrap(
                spacing: AppSpacing.sm,
                runSpacing: AppSpacing.sm,
                children: [
                  for (final row in favourites)
                    SizedBox(
                      width: width,
                      child: _FavouriteTile(
                        row: row,
                        onTap: () => _go(context, row.path!, row.label),
                        onLongPress: onUnfavourite == null
                            ? null
                            : () => onUnfavourite!(row),
                      ),
                    ),
                ],
              );
            },
          ),
        if (recents.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.md),
          const _SectionTitle('সাম্প্রতিক'),
          Card(
            key: const ValueKey('recents'),
            child: Column(children: [
              for (var i = 0; i < recents.length && i < 5; i++) ...[
                if (i > 0) Divider(height: 1, color: context.palette.border),
                // ⓘ তালিকার সারি অন্তত ৭২dp (নকশার নিয়ম ০.৪.৩৮ §২)
                ListTile(
                  key: ValueKey('recent-${recents[i].path}'),
                  minTileHeight: 72,
                  // ⓘ পর্দার নিজের মডিউলের আইকন — অচেনা পথে (যেমন একটা গ্রাহক) ইতিহাসের আইকন
                  leading: _recentIcon(recents[i].path),
                  title: Text(recents[i].label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 14)),
                  trailing: Icon(Icons.chevron_right,
                      color: context.palette.onSurfaceMuted),
                  onTap: () =>
                      _go(context, recents[i].path, recents[i].label),
                ),
              ],
            ]),
          ),
        ],
      ],
      // ⛔ শুভেচ্ছা আর শর্টকাটের সারি নেই — মালিক, ৬ অক্টোবর ২০২৬: "ei sort cutgulo dewar dorkar nai, user nam, suvo
      // sokal etaw bad daw"; নাম আর ছবি মাথার ডানে।
      // ⭐ উপরে কেবল খোঁজার বাক্স আর "আজ" (মালিকের নমুনা, ১২ অক্টোবর ২০২৬)
      leading: (context, today) => [
        if (search != null) ...[
          search!,
          const SizedBox(height: AppSpacing.md),
        ],
        const _SectionTitle('আজ'),
      ],
    );
  }

  static Widget _recentIcon(String path) {
    final def = NavCatalog.screenDef(path);
    return def == null
        ? const RowIcon(Icons.history)
        : RowIcon(def.icon, module: def.module);
  }

  void _go(BuildContext context, String path, String label) {
    final open = onOpen;
    if (open != null) {
      open(path, label);
    } else {
      context.go('/home/$path');
    }
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text, {this.icon});

  final String text;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: Row(children: [
          if (icon != null) ...[
            Icon(icon, size: 18, color: Theme.of(context).colorScheme.primary),
            const SizedBox(width: AppSpacing.xs),
          ],
          Text(text,
              style: TextStyle(
                  fontSize: 13.5,
                  fontWeight: FontWeight.w700,
                  color: context.palette.onSurface)),
        ]),
      );
}

class _Muted extends StatelessWidget {
  const _Muted(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Text(text,
      style: TextStyle(
          fontSize: 12.5,
          color: context.palette.onSurfaceMuted));
}

class _FavouriteTile extends StatelessWidget {
  const _FavouriteTile(
      {required this.row, required this.onTap, required this.onLongPress});

  final NavRow row;
  final VoidCallback onTap;
  final VoidCallback? onLongPress;

  @override
  Widget build(BuildContext context) {
    return Card(
      key: ValueKey('favourite-${row.path}'),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        onLongPress: onLongPress,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.xs),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              // ⓘ নকশার RowIcon — ৪০dp গোল, হালকা পটে মডিউলের গাঢ় সুর, অন্তত ৩:১ (নকশা ৩, পাতা ২)
              RowIcon(row.icon, module: row.module),
              const SizedBox(height: AppSpacing.xs),
              Text(row.label,
                  maxLines: 2,
                  textAlign: TextAlign.center,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 11.5)),
            ],
          ),
        ),
      ),
    );
  }
}
