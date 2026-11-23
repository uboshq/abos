import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/list_queries.dart';
import '../../core/records/money.dart';
import '../../core/records/product_record.dart';
import '../../core/records/stock_record.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/list_controls.dart';
import '../../core/widgets/search_debouncer.dart';

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
  late final _typed = SearchDebouncer(onSearch: (q) => setState(() => _query = q));

  @override
  void dispose() {
    _typed.dispose();
    super.dispose();
  }
  // ফিল্টার আর সাজানো — শুধু স্ক্রিন খোলা থাকা পর্যন্ত (দেখুন ListControls)।
  String _sort = ProductListQuery.defaultSort;
  ListFilters _filters = const {};

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
    // Why the last pull brought nothing down, when that is what happened —
    // null after a clean pull, and an empty list then means an empty list.
    final trouble = ReferenceSync.troubleSentence;
    final all = ProductRecord.all();
    final filtered = ProductListQuery.apply(all,
        query: _query, sort: _sort, filters: _filters);
    final l = context.l10n;

    return Scaffold(
      appBar: AbosScreenBar(title: l.productsListTitle),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: InputDecoration(
                hintText: l.productsListSearchHint,
                prefixIcon: const Icon(Icons.search),
              ),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
          ),
          ListControls(
            sortOptions: ProductListQuery.sortOptions,
            sort: _sort,
            onSort: (value) => setState(() => _sort = value),
            filterGroups: ProductListQuery.filterGroups(all),
            filters: _filters,
            onFilters: (value) => setState(() => _filters = value),
          ),
          // ⭐ কয়টা পণ্য — মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary"; ⓘ দামের যোগফল অর্থহীন, তাই কেবল গোনা
          if (filtered.isNotEmpty)
            Padding(
              key: const ValueKey('product-count'),
              padding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.xs),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text(l.productsListTotal(filtered.length),
                    style: TextStyle(fontWeight: FontWeight.w600, color: context.palette.onSurfaceMuted)),
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
                      children: [
                        EmptyState(
                          icon: trouble == null
                              ? Icons.inventory_2_outlined
                              : Icons.cloud_off_outlined,
                          title: trouble == null
                              ? l.productsListNothingSynced
                              : l.productsListLoadFailed,
                          // See CustomerListScreen's comment on the same line.
                          // This screen carries it twice over: নতুন অর্ডার
                          // sends people here to pull, so a silent failure
                          // here stalls the order screen too.
                          message: trouble ?? l.productsListPullRetry,
                        ),
                      ],
                    )
                  : filtered.isEmpty
                      ? ListView(
                          children: [
                            EmptyState(
                              icon: Icons.search_off,
                              title: l.productsListNoMatch,
                              // ফিল্টার চালু থাকলে খালি তালিকার কারণ সেটাও হতে পারে।
                              message: _filters.isEmpty
                                  ? null
                                  : l.productsListClearFilters,
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
                        context.l10n.productsListAvailable('${Money.plain(stock.available)}'
                            '${product.unit == null ? '' : ' ${product.unit}'}'),
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.w600,
                          color: stock.available > 0
                              ? context.palette.success
                              : context.palette.danger,
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
                  Text(context.l10n.productsListPurchase(Money.taka(product.purchasePrice)),
                      style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
