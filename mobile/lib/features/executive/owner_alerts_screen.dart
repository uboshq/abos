import 'package:flutter/material.dart';

import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../books/books_widgets.dart';
import 'executive_screen.dart';
import 'owner_page_frame.dart';
import 'owner_pages_api.dart';

/// ⭐ মালিকের কেন্দ্র — সতর্কতা, সব কোম্পানির (ওয়েবের `/owner/alerts`; মালিক, ১৭ অক্টোবর ২০২৬)।
///
/// <p>প্রতিটা সতর্কতা একটা কার্ড: ডানে গ্রুপের মোট (ওয়েবের "গ্রুপের মোট" কলাম), নিচে কোম্পানি ধরে ভাগ। "অনুমতি নেই" মানে শূন্য
/// নয়। নিচে সইয়ের অপেক্ষার কাগজ, নতুনগুলো আগে। ⛔ প্রতিটা সংখ্যা একটা রিপোর্টের সারি গোনা ([[Alerts]]) — ফোন কিছু গোনে না।
class OwnerAlertsScreen extends StatelessWidget {
  const OwnerAlertsScreen({super.key, this.api = const ServerOwnerPagesApi()});

  final OwnerPagesApi api;

  @override
  Widget build(BuildContext context) => OwnerPageFrame<OwnerAlerts>(
        title: 'সতর্কতা',
        failed: 'সতর্কতার তালিকা আনা গেল না।',
        load: api.alerts,
        body: (context, data) => [
          const OwnerNote('সংখ্যাগুলো ৫ মিনিট পর্যন্ত পুরনো হতে পারে। টেনে নামালে নতুন করে আনে।'),
          for (final alert in data.alerts) _AlertCard(alert: alert, many: data.companies.length > 1),
          SectionHeading('সইয়ের অপেক্ষায় (${data.waiting.length}টা)'),
          if (data.waiting.isEmpty)
            const Padding(
              padding: EdgeInsets.all(AppSpacing.md),
              child: Text('কোনো কাগজ সইয়ের অপেক্ষায় নেই।'),
            ),
          for (final w in data.waiting)
            Card(
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: ListTile(
                leading: const Icon(Icons.draw_outlined),
                title: Text(w.label, maxLines: 2, overflow: TextOverflow.ellipsis),
                subtitle: Text([w.company, dayOf(w.requestedAt)].join(' · ')),
                trailing: w.amount == null ? null : Text(Money.taka(w.amount)),
              ),
            ),
        ],
      );
}

class _AlertCard extends StatelessWidget {
  const _AlertCard({required this.alert, required this.many});

  final OwnerAlert alert;
  final bool many;

  @override
  Widget build(BuildContext context) {
    final hot = alert.total > 0;
    return Card(
      key: ValueKey('alert-${alert.kind}'),
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(hot ? Icons.warning_amber_rounded : Icons.check_circle_outline,
                    color: hot ? AppColors.danger : AppColors.success),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(ExecutiveToday.alertLabels[alert.kind] ?? alert.label,
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                ),
                Text(hot ? Money.plain(alert.total) : 'কিছু নেই',
                    key: ValueKey('alert-total-${alert.kind}'),
                    style: TextStyle(fontWeight: FontWeight.w800, color: hot ? AppColors.danger : AppColors.onSurfaceMuted)),
              ],
            ),
            if (many) ...[
              for (final p in alert.parts)
                FactRow(p.name, p.count == null ? 'অনুমতি নেই' : Money.plain(p.count),
                    colour: (p.count ?? 0) > 0 ? AppColors.danger : AppColors.onSurfaceMuted),
              FactRow('গ্রুপের মোট', Money.plain(alert.total)),
            ],
          ],
        ),
      ),
    );
  }
}
