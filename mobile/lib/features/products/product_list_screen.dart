import 'package:flutter/material.dart';

import '../../core/records/money.dart';
import '../../core/records/product_record.dart';
import '../../core/records/stock_record.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// The product catalogue, with the price a person is allowed to see.
///
/// <p><b>`purchasePrice` will not be in the payload for most roles</b> — see
/// docs/Contract, §৩ rule ঙ: the server omits the field entirely rather than
/// sending `null` or a masked value, for anyone without `inventory.cost.view`.
/// This screen never assumes the key exists; a product card simply has no
/// cost line when it is missing — [ProductRecord.hasPurchasePrice] tests for
/// the key, never for the value.
///
/// <p><b>The selling price is the line this screen exists for</b>, and until
/// now it did not draw: the tile read `salesPrice`/`price` and the server
/// sends `salePrice`, so every product showed a name-less row with no price
/// at all. See [ProductRecord]'s own doc comment.
class ProductListScreen extends StatefulWidget {
  const ProductListScreen({super.key});

  @override
  State<ProductListScreen> createState() => _ProductListScreenState();
}

class _ProductListScreenState extends State<ProductListScreen> {
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
    final all = ProductRecord.all();
    final filtered = all.where((product) => product.matches(_query)).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('পণ্যের তালিকা')),
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
            // CustomerListScreen's own comment: a RefreshIndicator needs a
            // scrollable descendant to recognise the pull gesture at all, so
            // the "নিচে টেনে" instruction on an empty first launch must not
            // be the one case with nothing to pull.
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: all.isEmpty
                  ? ListView(
                      children: const [
                        EmptyState(
                          icon: Icons.inventory_2_outlined,
                          title: 'এখনো কোনো পণ্য সিঙ্ক হয়নি',
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
                          padding:
                              const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                          itemCount: filtered.length,
                          separatorBuilder: (_, __) =>
                              const SizedBox(height: AppSpacing.xs),
                          itemBuilder: (context, index) =>
                              _ProductTile(product: filtered[index]),
                        ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ProductTile extends StatelessWidget {
  const _ProductTile({required this.product});

  final ProductRecord product;

  @override
  Widget build(BuildContext context) {
    // Null only for a role whose stock records never sync at all (a salesman
    // — docs/Contract §০: "মজুদ ❌ রেকর্ডই আসবে না"), and for a product whose
    // stock row simply has not been pulled yet. Both mean the same thing to
    // this tile: draw no stock line rather than a zero.
    final stock = StockRecord.forProduct(product.id);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(product.name,
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text(
                    [
                      if (product.code != null) product.code!,
                      if (product.unit != null) product.unit!,
                    ].join(' · '),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  if (stock != null)
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpacing.xs),
                      child: Text(
                        'বিক্রয়যোগ্য ${Money.plain(stock.available)}'
                        '${product.unit == null ? '' : ' ${product.unit}'}',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: stock.available > 0
                              ? AppColors.success
                              : AppColors.danger,
                        ),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                if (product.salePrice != null)
                  Text(Money.taka(product.salePrice),
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                // Absent, not null and not zero — see this file's own class
                // comment.
                if (product.hasPurchasePrice)
                  Text('ক্রয়: ${Money.taka(product.purchasePrice)}',
                      style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
