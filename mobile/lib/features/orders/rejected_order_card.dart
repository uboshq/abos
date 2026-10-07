import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/sync_engine/sync_capabilities_api.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/app_status_pill.dart';
import 'order_prefill.dart';

/// One rejected `SalesOrder`, named rather than shown as raw JSON — see
/// [RejectedOrderSummary]'s own doc comment for the "কোন দোকান · কোন অর্ডার ·
/// কবে · কেন" the coordinating session asked for, and why a rep is given an
/// action here rather than only a fact.
///
/// <p>Shared between the order-status screen and the sync-status screen —
/// both list the same [RejectedChange] rows, and a rep should not learn a
/// different amount about the identical rejection depending on which screen
/// happened to show it.
class RejectedOrderCard extends StatelessWidget {
  const RejectedOrderCard({
    super.key,
    required this.item,
    required this.onDismissed,
  });

  final RejectedChange item;
  final VoidCallback onDismissed;

  @override
  Widget build(BuildContext context) {
    final summary = RejectedOrderSummary.fromRejected(item);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const AppStatusPill(label: 'ব্যর্থ', kind: AppStatusKind.danger),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(
                    // Falls back to the bare entity type if this row is not
                    // a SalesOrder this build's decoder understands — see
                    // RejectedOrderSummary.fromRejected's own doc comment.
                    summary != null
                        ? '${summary.customerName} · ${summary.itemCount}টা পণ্য'
                        : syncEntityLabel(item.entityType),
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close),
                  tooltip: 'বুঝেছি, সরিয়ে ফেলুন',
                  onPressed: () async {
                    await SyncEngine.instance.dismissRejected(item.key);
                    onDismissed();
                  },
                ),
              ],
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(item.reason,
                style: const TextStyle(color: AppColors.danger, fontSize: 13)),
            const SizedBox(height: AppSpacing.xs),
            Text(
              DateFormat('dd/MM/yyyy hh:mm a').format(item.enqueuedAt),
              style:
                  const TextStyle(fontSize: 11, color: AppColors.onSurfaceMuted),
            ),
            if (summary != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Align(
                alignment: Alignment.centerRight,
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.edit_outlined, size: 18),
                  label: const Text('নতুন করে লিখুন'),
                  onPressed: () =>
                      context.push('/home/new-order', extra: summary.prefill),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
