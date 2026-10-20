import 'package:flutter/material.dart';

import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/list_queries.dart';
import '../../core/records/sales_order_record.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/app_status_pill.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/list_controls.dart';
import '../../core/widgets/state_views.dart';
import '../../core/widgets/totals_bar.dart';
import '../delivery_orders/delivery_order_screens.dart' show PaperListRow, orderStatusKind;
import 'rejected_order_card.dart';

/// Three states an order taken on this phone can be in, all in one list
/// rather than three screens: waiting for a connection, refused by the
/// server with a reason, or confirmed and pulled back with its real number —
/// see docs/Contract §০ (owner's decision ২) for why the number only ever
/// appears in this last group.
class OrderListScreen extends StatefulWidget {
  const OrderListScreen({super.key, this.onNewOrder});

  /// খালি তালিকার "নতুন অর্ডার" — না দিলে অ্যাপের নতুন অর্ডারের পথ (`/home/new-order`); পরীক্ষা নিজেরটা দেয়
  final VoidCallback? onNewOrder;

  @override
  State<OrderListScreen> createState() => _OrderListScreenState();
}

class _OrderListScreenState extends State<OrderListScreen> {
  bool _refreshing = false;
  // সিঙ্ক হওয়া অংশের ফিল্টার আর সাজানো — শুধু স্ক্রিন খোলা থাকা পর্যন্ত।
  // অপেক্ষমাণ আর ফেরত আসা অর্ডার এতে পড়ে না: ওগুলো হাতে গোনা, আর ফেরতগুলো
  // সবসময় চোখের সামনে থাকা দরকার।
  String _sort = OrderListQuery.defaultSort;
  ListFilters _filters = const {};

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
    final synced = SalesOrderRecord.all();
    final shown =
        OrderListQuery.apply(synced, sort: _sort, filters: _filters);
    // ⭐ দেখানো আদেশের মোট — মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary/grand total"। ⓘ বাতিল আদেশ সারিতে থাকে, মোটে নয়
    // (ওয়েবের ভাউচার আর আদেশের তালিকার একই নিয়ম)।
    double sumOf(List<SalesOrderRecord> list) =>
        list.fold<double>(0, (sum, order) => order.status == 'cancelled' ? sum : sum + (order.total ?? 0));
    final shownTotal = sumOf(shown);
    // ⭐ সর্বমোট — ফোনে সিঙ্ক হওয়া পুরো তালিকা, ছাঁকনির আগে; "এই পাতা" যা এখন দেখা যাচ্ছে (নকশা ৩, পাতা ৩)
    final allTotal = sumOf(synced);

    // Why the last pull brought nothing down, when that is what happened —
    // null after a clean pull, and an empty list then means an empty list.
    final trouble = ReferenceSync.troubleSentence;
    final nothingAtAll =
        rejected.isEmpty && pendingCount == 0 && synced.isEmpty;

    return Scaffold(
      appBar: const AbosScreenBar(title: 'অর্ডারের অবস্থা', module: 'বিক্রয়'),
      bottomNavigationBar: synced.isEmpty
          ? null
          : TotalsBar(pageCount: shown.length, pageSum: shownTotal, allCount: synced.length, allSum: allTotal),
      // ⛔ Even with nothing to show, the empty state lives inside a
      // RefreshIndicator over a scrollable. A RefreshIndicator only
      // recognises a pull on a scrollable descendant, so a bare EmptyState
      // makes the gesture do nothing — on the one screen where somebody is
      // most likely to try it, because nothing is there yet.
      //
      // ⚠️ This is commit b2392ce's bug, found on a device and fixed on three
      // screens; this fourth one was missed, and the test written that day
      // listed those three by hand so it never noticed. Found again on 16
      // September the same way: by pulling on a real screen and watching
      // nothing happen. pull_to_refresh_test.dart now counts the screens that
      // can draw an EmptyState, so the next one cannot slip past either.
      body: nothingAtAll
          ? RefreshIndicator(
              onRefresh: _refresh,
              child: ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): টানার সময় খসড়া সারি; শেষ টান ব্যর্থ হলে কারণসহ "আবার চেষ্টা করুন";
                  // সত্যিই খালি হলে "নতুন অর্ডার"
                  if (_refreshing)
                    const LoadingSkeleton()
                  else if (trouble != null) ...[
                    ErrorState(
                        key: const ValueKey('orders-error'),
                        message: 'অর্ডারের তালিকা আনা যায়নি',
                        onRetry: _refresh),
                    // See CustomerListScreen's comment on the same line.
                    Text(trouble,
                        textAlign: TextAlign.center,
                        style: TextStyle(fontSize: 13, color: Theme.of(context).colorScheme.onSurfaceVariant)),
                  ] else
                    EmptyState(
                      icon: Icons.receipt_long_outlined,
                      title: 'এখনো কোনো অর্ডার নেই',
                      message: 'নিচে টেনে আবার চেষ্টা করুন।',
                      action: FilledButton.icon(
                        key: const ValueKey('orders-empty-new'),
                        style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
                        onPressed: widget.onNewOrder ?? () => context.push('/home/new-order'),
                        icon: const Icon(Icons.add),
                        label: const Text('নতুন অর্ডার'),
                      ),
                    ),
                ],
              ),
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
                    ListControls(
                      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                      sortOptions: OrderListQuery.sortOptions,
                      sort: _sort,
                      onSort: (value) => setState(() => _sort = value),
                      filterGroups: OrderListQuery.filterGroups(synced),
                      filters: _filters,
                      onFilters: (value) => setState(() => _filters = value),
                    ),
                    if (shown.isEmpty)
                      const Padding(
                        padding: EdgeInsets.all(AppSpacing.md),
                        child: Text('এই ফিল্টারে কোনো অর্ডার নেই।',
                            textAlign: TextAlign.center),
                      ),
                    // The number is what this section exists for — the rep
                    // could not give it to the shopkeeper offline (docs/
                    // Contract §০, decision ২), so this is the screen where
                    // they finally get it. It used to read `orderNumber`,
                    // which the server has never sent, so every confirmed
                    // order here said "নম্বর নেই"; see SalesOrderRecord.
                    // ⭐ নকশা ৩-এর সারি: গ্রাহক "নাম · পয়েন্ট · শাখা" (CustomerRecord.label), নিচে নম্বর · তারিখ, ডানে টাকা আর অবস্থা
                    ...shown.map((order) => PaperListRow(
                          key: ValueKey('order-row-${order.id}'),
                          icon: Icons.receipt_long_outlined,
                          party: order.customerName,
                          number: order.documentNo ?? 'নম্বর নেই',
                          detail: order.trxDate == null ? null : DateFormat('dd/MM/yyyy').format(order.trxDate!),
                          amount: order.total,
                          pill: AppStatusPill(label: order.statusLabel, kind: orderStatusKind(order.status ?? '')),
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
