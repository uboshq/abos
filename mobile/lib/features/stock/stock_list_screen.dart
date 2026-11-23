import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/records/list_queries.dart';
import '../../core/records/report_record.dart';
import '../../core/records/money.dart';
import '../../core/records/stock_record.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/list_controls.dart';
import '../../core/widgets/search_debouncer.dart';
import '../books/books_widgets.dart';
import '../reports/reports_screen.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

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
  late final _typed = SearchDebouncer(onSearch: (q) => setState(() => _query = q));

  @override
  void dispose() {
    _typed.dispose();
    super.dispose();
  }
  // ফিল্টার আর সাজানো — শুধু স্ক্রিন খোলা থাকা পর্যন্ত। শূন্য মজুদ শুরুতেই
  // লুকানো (মালিকের চাওয়া) — দেখুন StockListQuery.defaultFilters।
  String _sort = StockListQuery.defaultSort;
  ListFilters _filters = StockListQuery.defaultFilters;

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
    final all = StockRecord.all();
    // A stock row whose product has not been pulled yet has no name to search
    // by; it is still listed (the quantity is real), it simply cannot match a
    // typed query — StockListQuery.apply keeps that rule.
    final filtered = StockListQuery.apply(all,
        query: _query, sort: _sort, filters: _filters);
    // ⭐ দেখানো পণ্যের মোট বিক্রয়যোগ্য পরিমাণ — মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary/grand total"; ওয়েবের মজুদ-তালিকার
    // পট্টির একই সংখ্যা ("বিক্রয়যোগ্য")। ⓘ টাকার মূল্য এখানে নয় — খরচ দেখার চাবির পেছনে, "মূল্যসহ তালিকা"-য়।
    final available = filtered.fold<double>(0, (sum, row) => sum + row.available);
    final l = context.l10n;

    return Scaffold(
      appBar: AbosScreenBar(title: l.stockListTitle),
      body: Column(
        children: [
          // ⭐ মজুদ মডিউল — মূল্য আর রিপোর্ট (মালিক, ৪ অক্টোবর ২০২৬: "stock qty, value, ivinno report")। মূল্য ওয়েবের রিপোর্ট
          // থেকেই (`inventory.stock_value`) — খরচ দেখার চাবি না থাকলে সার্ভার মূল্যের কলামই পাঠায় না।
          Padding(
            padding: const EdgeInsets.fromLTRB(
                AppSpacing.md, AppSpacing.sm, AppSpacing.md, 0),
            child: Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  key: const Key('stock-value'),
                  icon: const Icon(Icons.payments_outlined, size: 18),
                  label: Text(l.stockListWithValue),
                  onPressed: () =>
                      Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => ReportViewScreen(
                      report: ReportSummary({
                        'key': 'inventory.stock_value',
                        'title': l.stockListValueReportTitle,
                        'module': 'inventory'
                      }),
                    ),
                  )),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: OutlinedButton.icon(
                  key: const Key('stock-reports'),
                  icon: const Icon(Icons.assessment_outlined, size: 18),
                  label: Text(l.stockListReports),
                  onPressed: () =>
                      Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => ReportsScreen(
                        module: 'inventory', title: l.stockListReports),
                  )),
                ),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: InputDecoration(
                hintText: l.stockListSearchHint,
                prefixIcon: const Icon(Icons.search),
              ),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
          ),
          ListControls(
            sortOptions: StockListQuery.sortOptions,
            sort: _sort,
            onSort: (value) => setState(() => _sort = value),
            filterGroups: StockListQuery.filterGroups,
            filters: _filters,
            onFilters: (value) => setState(() => _filters = value),
          ),
          if (filtered.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
              child: TotalStrip(label: l.stockListTotalAvailable, value: Money.plain(available), count: filtered.length),
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
                      children: [
                        EmptyState(
                          icon: trouble == null
                              ? Icons.warehouse_outlined
                              : Icons.cloud_off_outlined,
                          title: trouble == null
                              ? l.stockListNothingSynced
                              : l.stockListLoadFailed,
                          // See CustomerListScreen's comment on the same line.
                          message: trouble ?? l.stockListPullRetry,
                        ),
                      ],
                    )
                  : filtered.isEmpty
                      ? ListView(
                          children: [
                            EmptyState(
                              icon: Icons.search_off,
                              title: l.stockListNoMatch,
                              // ফিল্টার চালু থাকলে খালি তালিকার কারণ সেটাও হতে পারে।
                              message: _filters.isEmpty
                                  ? null
                                  : l.stockListClearFilters,
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
    final l = context.l10n;

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
                      Text(product?.name ?? l.stockListProductNotSynced,
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
                            ? context.palette.success
                            : context.palette.danger,
                      ),
                    ),
                    Text(l.stockListAvailable,
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
                        child: Text(l.stockListOnShelf('${Money.plain(row.floor)}$suffix'))),
                    if (row.reserved != 0)
                      Expanded(
                          child: Text(
                              l.stockListOnOrder('${Money.plain(row.reserved)}$suffix'))),
                    if (row.hold != 0)
                      Expanded(
                          child:
                              Text(l.stockListHeld('${Money.plain(row.hold)}$suffix'))),
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
