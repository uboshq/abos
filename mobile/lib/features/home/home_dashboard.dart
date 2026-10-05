import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_user.dart';
import '../../core/menu/menu_item.dart';
import '../../core/records/today_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/day_part.dart';
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

  /// Seam for the greeting — a test can ask for eight in the evening.
  final DateTime Function()? now;

  /// Which tiles earn a place above the fold, in this order. Everything
  /// else is one tap away on the অ্যাপ tab.
  static const List<String> _quickKeys = [
    'sales.order.create',
    'approval.inbox.index',
    'hr.attendance.self',
    'customer.dues',
    'reports',
  ];

  @override
  Widget build(BuildContext context) {
    final quick = <MenuItem>[
      for (final key in _quickKeys)
        for (final item in items)
          if (item.key == key && !item.planned) item,
    ];

    return TodayPanel(
      fetch: fetchToday,
      lastKnown: lastKnownToday,
      showOrigin: !headerNamesOrg,
      onRecord: onRecord,
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
      leading: (context, today) => [
        _GreetingCard(user: user, today: today, now: now),
        if (quick.isNotEmpty) ...[
          const SizedBox(height: AppSpacing.sm),
          _QuickActions(items: quick),
        ],
        const SizedBox(height: AppSpacing.md),
      ],
    );
  }
}

class _GreetingCard extends StatelessWidget {
  const _GreetingCard({required this.user, required this.today, this.now});

  final AuthUser user;
  final TodayRecord? today;
  final DateTime Function()? now;

  @override
  Widget build(BuildContext context) {
    final greeting = banglaGreeting((now ?? DateTime.now)());
    final name = user.name.trim();
    // The server's business day, when it has said which one it is
    // (docs/Contract §৮ rule গ) — never the phone's.
    final date = today?.date;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              name.isEmpty ? greeting : '$greeting, $name',
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              [
                if (user.roles.isNotEmpty) user.roles.join(', '),
                if (date != null) date,
              ].join(' · '),
              style: const TextStyle(
                  fontSize: 12.5, color: AppColors.onSurfaceMuted),
            ),
          ],
        ),
      ),
    );
  }
}

/// A row of chips, each one a tile from the অ্যাপ grid promoted to the front.
class _QuickActions extends StatelessWidget {
  const _QuickActions({required this.items});

  final List<MenuItem> items;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        itemCount: items.length,
        separatorBuilder: (_, __) => const SizedBox(width: AppSpacing.sm),
        itemBuilder: (context, index) {
          final item = items[index];
          return ActionChip(
            avatar: Icon(item.icon, size: 18, color: AppColors.primary),
            label: Text(item.label),
            onPressed: () => context.go('/home/${item.routeName}'),
          );
        },
      ),
    );
  }
}
