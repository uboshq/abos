import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_user.dart';
import '../../core/menu/menu_item.dart';
import '../../core/records/today_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../today/today_panel.dart';

/// The হোম tab: a greeting, the day's figures, and the two or three things
/// this person does every day within one thumb's reach.
///
/// <p>Built on [TodayPanel] rather than beside it, so one pull refreshes the
/// greeting's date line and the cards together, and so the figures here can
/// never drift from the ones on the "আজকের হিসাব" page.
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
  });

  final AuthUser user;

  /// The menu this person has — the quick actions are picked from it, so a
  /// button never appears for a screen the person cannot open.
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


  @override
  Widget build(BuildContext context) {
    return TodayPanel(
      fetch: fetchToday,
      lastKnown: lastKnownToday,
      showOrigin: !headerNamesOrg,
      onRecord: onRecord,
      onPull: onPull,
      onOpenSales: () => context.go('/home/orders'),
      onOpenDues: () => context.go('/home/dues'),
      onOpenApprovals: () => context.go('/home/approvals'),
      // ⓘ সংখ্যাগুলোর নিচে — আজকের অঙ্কই পর্দার প্রথম জিনিস থাকে
      trailing: [
        const SizedBox(height: AppSpacing.sm),
        // ⭐ ব্যবসার ড্যাশবোর্ড — মজুদ, বিক্রি, হিসাব … (মালিক, ৪ অক্টোবর ২০২৬: "egulo nadile bujbo kikore kihocche")
        Card(
          key: const Key('home-dashboards'),
          child: ListTile(
            leading:
                const Icon(Icons.insights_outlined, color: AppColors.primary),
            title: const Text('ব্যবসার ড্যাশবোর্ড',
                style: TextStyle(fontWeight: FontWeight.w700)),
            subtitle: const Text('মজুদ, বিক্রি, হিসাব — ওয়েবের একই সংখ্যা'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.go('/home/dashboards'),
          ),
        ),
      ],
      // ⛔ শুভেচ্ছা আর শর্টকাটের সারি নেই — মালিক, ৬ অক্টোবর ২০২৬: "ei sort cutgulo dewar dorkar nai, user nam, suvo
      // sokal etaw bad daw"; খালি জায়গায় সংখ্যাগুলো উঠে আসে, প্রিন্সিপালের বাক্সসহ। নাম আর ছবি এখন মাথার ডানে।
      leading: (context, today) => const [],
    );
  }
}
