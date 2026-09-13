import 'package:flutter/material.dart';

import 'package:intl/intl.dart';

import '../../core/records/money.dart';
import '../../core/records/sales_order_record.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/app_status_pill.dart';
import '../../core/widgets/empty_state.dart';
import 'rejected_order_card.dart';

/// Three states an order taken on this phone can be in, all in one list
/// rather than three screens: waiting for a connection, refused by the
/// server with a reason, or confirmed and pulled back with its real number —
/// see docs/Contract §০ (owner's decision ২) for why the number only ever
/// appears in this last group.
class OrderListScreen extends StatefulWidget {
  const OrderListScreen({super.key});

  @override
  State<OrderListScreen> createState() => _OrderListScreenState();
}

class _OrderListScreenState extends State<OrderListScreen> {
  bool _refreshing = false;

  Future<void> _refresh() async {
    setState(() => _refreshing = true);
    try {
      await SyncEngine.instance.flush('sales');
      await ReferenceSync.syncAll();
    } catch (_) {
      // Same reasoning as CustomerListScreen: a failed round leaves whatever
      // was already on screen rather than clearing it.
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rejected = SyncEngine.instance.rejectedItems
        .where((item) => item.entityType == 'SalesOrder')
        .toList();
    final pendingCount = SyncEngine.instance.pendingCount;
    final synced = SalesOrderRecord.all()
      ..sort((a, b) => (b.trxDate ?? DateTime(0))
          .compareTo(a.trxDate ?? DateTime(0)));

    final nothingAtAll =
        rejected.isEmpty && pendingCount == 0 && synced.isEmpty;

    return Scaffold(
      appBar: AppBar(title: const Text('অর্ডারের অবস্থা')),
      body: nothingAtAll
          ? const EmptyState(
              icon: Icons.receipt_long_outlined,
              title: 'এখনো কোনো অর্ডার নেই',
            )
          : RefreshIndicator(
              onRefresh: _refresh,
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  if (_refreshing) const LinearProgressIndicator(),
                  if (pendingCount > 0) ...[
                    _SectionHeader('অপেক্ষমাণ ($pendingCount)'),
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(AppSpacing.md),
                        child: Row(
                          children: [
                            const AppStatusPill(
                                label: 'অপেক্ষমাণ', kind: AppStatusKind.pending),
                            const SizedBox(width: AppSpacing.sm),
                            Expanded(
                              child: Text(
                                'সংযোগ পেলেই পাঠানো হবে — অর্ডার এই ফোনেই আছে।',
                                style: Theme.of(context).textTheme.bodySmall,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                  if (rejected.isNotEmpty) ...[
                    _SectionHeader('যা যায়নি (${rejected.length})'),
                    ...rejected.map((item) => RejectedOrderCard(
                          item: item,
                          onDismissed: () => setState(() {}),
                        )),
                  ],
                  if (synced.isNotEmpty) ...[
                    _SectionHeader('সিঙ্ক হয়েছে (${synced.length})'),
                    // The number is what this section exists for — the rep
                    // could not give it to the shopkeeper offline (docs/
                    // Contract §০, decision ২), so this is the screen where
                    // they finally get it. It used to read `orderNumber`,
                    // which the server has never sent, so every confirmed
                    // order here said "নম্বর নেই"; see SalesOrderRecord.
                    ...synced.map((order) => Card(
                          child: ListTile(
                            leading: const AppStatusPill(
                                label: 'সম্পন্ন', kind: AppStatusKind.success),
                            title: Text(order.documentNo ?? 'নম্বর নেই'),
                            subtitle: Text([
                              order.customerName,
                              if (order.trxDate != null)
                                DateFormat('dd/MM/yyyy').format(order.trxDate!),
                              order.statusLabel,
                            ].join(' · ')),
                            trailing: order.total == null
                                ? null
                                : Text(Money.taka(order.total),
                                    style: const TextStyle(
                                        fontWeight: FontWeight.w700)),
                          ),
                        )),
                  ],
                ],
              ),
            ),
    );
  }
}

class _SectionHeader extends StatelessWidget {
  const _SectionHeader(this.label);

  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
      child: Text(label,
          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
    );
  }
}
