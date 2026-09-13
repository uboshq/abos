import 'package:flutter/material.dart';

import '../../core/records/money.dart';
import '../../core/records/stock_record.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// Hand-on-shelf quantities — reachable only from a menu tile gated on
/// `inventory.stock.view` (see menu_repository.dart), so a role without that
/// permission never lands here to find an empty list and wonder if the app
/// is broken.
///
/// <p>Reads through [StockRecord]; see that class's own doc comment for the
/// four key names this screen used to read that the server has never sent,
/// and for why the shelf figure is shown broken into its parts rather than
/// as one number.
class StockListScreen extends StatefulWidget {
  const StockListScreen({super.key});

  @override
  State<StockListScreen> createState() => _StockListScreenState();
}

class _StockListScreenState extends State<StockListScreen> {
  bool _refreshing = false;
  String _query = '';

  Future<void> _refresh() async {
    setState(() => _refreshing = true);
    try {
      await ReferenceSync.syncAll();
    } catch (_) {
      // See CustomerListScreen's own comment on the same catch.
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final all = StockRecord.all();
    // A stock row whose product has not been pulled yet has no name to search
    // by; it is still listed (the quantity is real), it simply cannot match a
    // typed query.
    final filtered = all
        .where((row) => row.product?.matches(_query) ?? _query.trim().isEmpty)
        .toList()
      ..sort((a, b) =>
          (a.product?.name ?? '').compareTo(b.product?.name ?? ''));

    return Scaffold(
      appBar: AppBar(title: const Text('হাতে থাকা মজুদ')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: const InputDecoration(
                hintText: 'পণ্যের নাম দিয়ে খুঁজুন',
                prefixIcon: Icon(Icons.search),
              ),
              onChanged: (value) => setState(() => _query = value),
            ),
          ),
          if (_refreshing) const LinearProgressIndicator(),
          Expanded(
            // Always a RefreshIndicator, never a bare EmptyState — see
            // CustomerListScreen's own comment: without it, pulling down on
            // an empty first launch does nothing, which is exactly the one
            // moment this screen tells someone to do that.
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: all.isEmpty
                  ? ListView(
                      children: const [
                        EmptyState(
                          icon: Icons.warehouse_outlined,
                          title: 'এখনো কোনো মজুদ সিঙ্ক হয়নি',
                          message: 'নিচে টেনে আবার চেষ্টা করুন।',
                        ),
                      ],
                    )
                  : filtered.isEmpty
                      ? ListView(
                          children: const [
                            EmptyState(
                              icon: Icons.search_off,
                              title: 'কোনো মিল পাওয়া যায়নি',
                            ),
                          ],
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.symmetric(
                              horizontal: AppSpacing.md),
                          itemCount: filtered.length,
                          separatorBuilder: (_, __) =>
                              const SizedBox(height: AppSpacing.xs),
                          itemBuilder: (context, index) =>
                              _StockTile(row: filtered[index]),
                        ),
            ),
          ),
        ],
      ),
    );
  }
}

class _StockTile extends StatelessWidget {
  const _StockTile({required this.row});

  final StockRecord row;

  @override
  Widget build(BuildContext context) {
    final product = row.product;
    final unit = product?.unit;
    final suffix = unit == null ? '' : ' $unit';

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // The name comes from the cached Product, not from this
                      // row — the server sends the product's public_id as the
                      // stock row's own id rather than repeating its name on
                      // every row. A product that has not synced yet shows a
                      // line saying so rather than a blank.
                      Text(product?.name ?? 'পণ্য এখনো সিঙ্ক হয়নি',
                          style: const TextStyle(fontWeight: FontWeight.w600)),
                      if (product?.code != null)
                        Text(product!.code!,
                            style: Theme.of(context).textTheme.bodySmall),
                    ],
                  ),
                ),
                const SizedBox(width: AppSpacing.sm),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      '${Money.plain(row.available)}$suffix',
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 16,
                        color: row.available > 0
                            ? AppColors.success
                            : AppColors.danger,
                      ),
                    ),
                    Text('বিক্রয়যোগ্য',
                        style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              ],
            ),
            // Only when the figures differ. A shelf of 100 that can all be
            // sold needs no explanation; a shelf of 100 that can sell 40
            // needs to say where the other 60 went, or the rep tells the shop
            // "স্টক নাই" and walks — which is the failure StockOnHandSync's
            // own comment says it sends all five figures to prevent.
            if (row.hasCommitments) ...[
              const SizedBox(height: AppSpacing.sm),
              const Divider(height: 1),
              const SizedBox(height: AppSpacing.sm),
              DefaultTextStyle.merge(
                style: Theme.of(context).textTheme.bodySmall,
                child: Row(
                  children: [
                    Expanded(
                        child: Text('তাকে ${Money.plain(row.floor)}$suffix')),
                    if (row.reserved != 0)
                      Expanded(
                          child: Text(
                              'অর্ডারে ${Money.plain(row.reserved)}$suffix')),
                    if (row.hold != 0)
                      Expanded(
                          child:
                              Text('আটকানো ${Money.plain(row.hold)}$suffix')),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
