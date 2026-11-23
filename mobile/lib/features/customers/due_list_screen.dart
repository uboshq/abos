import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/list_queries.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/list_controls.dart';
import '../../core/widgets/search_debouncer.dart';
import '../../core/widgets/shown_rows.dart';
import '../../core/widgets/state_views.dart';
import '../../core/widgets/totals_bar.dart';
import '../../core/widgets/design_list_row.dart';
import '../../core/locale/l10n.dart';

/// Who owes what — the whole round's debt on one page.
///
/// <p><b>Needs nothing new from the server.</b> `CustomerDue` has been
/// syncing to the phone since the engine was built, and `CustomerDueSync`
/// goes to real trouble to keep it fresh: its watermark comes from the
/// ledger's last movement rather than the customer row, precisely so a phone
/// showing "৫,০০০ বাকি" cannot go on showing it while the real figure walks
/// to ৮০,০০০.
///
/// <p>Until now the only place that number appeared was one line on a
/// customer row and one notice on the order screen. Neither answers the
/// question an owner actually asks on a Thursday: *where is my money*.
///
/// <p><b>Sorted by what is owed, largest first</b> — not alphabetically.
/// A list of eighty shops sorted by name is a list nobody reads to the end;
/// the four that matter are at the top of this one. That stays the default;
/// সাজান can change it for this visit only (মালিক, ২ অক্টোবর — DueListQuery).
class DueListScreen extends StatefulWidget {
  const DueListScreen({super.key, this.refresh});

  /// নতুন করে আনা — না দিলে সিঙ্ক ইঞ্জিনের পুরো টান ([ReferenceSync.syncAll]); পরীক্ষা নিজের নকল দেয়
  final Future<void> Function()? refresh;

  @override
  State<DueListScreen> createState() => _DueListScreenState();
}

class _DueListScreenState extends State<DueListScreen> {
  bool _refreshing = false;
  String _query = '';
  late final _typed = SearchDebouncer(onSearch: (q) => setState(() => _query = q));

  @override
  void dispose() {
    _typed.dispose();
    super.dispose();
  }
  // ফিল্টার আর সাজানো — শুধু স্ক্রিন খোলা থাকা পর্যন্ত (দেখুন ListControls)।
  String _sort = DueListQuery.defaultSort;
  ListFilters _filters = const {};

  Future<void> _refresh() async {
    setState(() => _refreshing = true);
    try {
      await (widget.refresh ?? ReferenceSync.syncAll)();
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
    final rows = _owing();
    final filtered =
        DueListQuery.apply(rows, query: _query, sort: _sort, filters: _filters);
    // ⓘ মোট সবসময় পুরো তালিকার — ফিল্টারে কটা দোকান লুকালেও মোট বকেয়া বদলায়
    // না, কারণ এই সংখ্যাটার জন্যই মালিক পাতাটা খোলেন।
    // ⭐ দেখার শাখার বকেয়া — ওয়েবের তালিকার একই সংখ্যা (মালিক, ৬ অক্টোবর ২০২৬)
    final total =
        rows.fold<double>(0, (sum, row) => sum + row.due.outstandingInView);

    // ⭐ নিচের স্থির পট্টি (নকশা ৩, পাতা ৩): "এই পাতা" = খোঁজ আর ফিল্টারের পরে যা দেখা যাচ্ছে ([ShownRows], অ্যাপজুড়ে এক মানে);
    // "সর্বমোট" = পুরো তালিকা — খোঁজায় কটা দোকান লুকালেও সর্বমোট বদলায় না, কারণ এই সংখ্যাটার জন্যই মালিক পাতাটা খোলেন
    final shown = ShownRows.of(filtered, (row) => row.due.outstandingInView);

    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.customersDueTitle, module: context.l10n.customersTitle),
      bottomNavigationBar: rows.isEmpty
          ? null
          : TotalsBar(pageCount: shown.count, pageSum: shown.sum, allCount: rows.length, allSum: total),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: InputDecoration(
                hintText: context.l10n.customersSearchHint,
                prefixIcon: const Icon(Icons.search),
              ),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
          ),
          if (rows.isNotEmpty)
            ListControls(
              sortOptions: DueListQuery.sortOptions,
              sort: _sort,
              onSort: (value) => setState(() => _sort = value),
              filterGroups: DueListQuery.filterGroups(rows),
              filters: _filters,
              onFilters: (value) => setState(() => _filters = value),
            ),
          if (_refreshing && rows.isNotEmpty) const LinearProgressIndicator(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: rows.isEmpty
                  ? ListView(
                      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                      children: [
                        // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): আনা চলছে → খসড়া সারি; টান ব্যর্থ → ভুল আর "আবার চেষ্টা করুন";
                        // টান ঠিক, কেউ বাকি নেই → খালি, সাথে "নতুন করে দেখুন"
                        if (_refreshing)
                          const LoadingSkeleton()
                        else if (trouble != null) ...[
                          // ⛔ The worst one on this list to get wrong. After a failed pull 'কারো কাছে বকেয়া নেই' is a
                          // claim about money made on no data, and somebody acts on it: no collection round, no call,
                          // a credit limit read as headroom. An empty list is only news when the list actually arrived.
                          ErrorState(
                              key: const ValueKey('due-error'),
                              message: context.l10n.customersDueListFailed,
                              onRetry: _refresh),
                          Text(trouble,
                              textAlign: TextAlign.center,
                              style: TextStyle(fontSize: 13, color: Theme.of(context).colorScheme.onSurfaceVariant)),
                        ] else
                          // After a clean pull this is good news, not an error — the same distinction the approvals
                          // inbox draws.
                          EmptyState(
                            key: const ValueKey('due-empty'),
                            icon: Icons.check_circle_outline,
                            title: context.l10n.customersDueNone,
                            message: context.l10n.customersDueNoneHint,
                            action: OutlinedButton.icon(
                                key: const ValueKey('due-empty-refresh'),
                                style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                                onPressed: _refresh,
                                icon: const Icon(Icons.refresh),
                                label: Text(context.l10n.customersRefresh)),
                          ),
                      ],
                    )
                  : filtered.isEmpty
                      ? ListView(
                          children: [
                            EmptyState(
                              icon: Icons.search_off,
                              title: context.l10n.customersNoMatch,
                              // ফিল্টার চালু থাকলে খালি তালিকার কারণ সেটাও হতে পারে।
                              message: _filters.isEmpty
                                  ? null
                                  : context.l10n.customersClearFilters,
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
                              _DueTile(row: filtered[index]),
                        ),
            ),
          ),
        ],
      ),
    );
  }

  /// Only shops that actually owe.
  ///
  /// <p>⚠️ A shop in advance (a negative balance) is deliberately left out
  /// rather than shown as a negative row. This page answers one question —
  /// *who owes me* — and money the business owes back is a different question
  /// with a different urgency; mixing the two makes the total meaningless.
  ///
  /// <p>A shop whose `CustomerDue` has not arrived yet is also absent, and
  /// that is not the same as owing nothing. The two entity types have
  /// separate watermarks, so one can lag the other by a sync.
  List<DueRow> _owing() {
    final rows = <DueRow>[];
    for (final customer in CustomerRecord.all()) {
      final due = CustomerDueRecord.forCustomer(customer.id);
      if (due == null || due.outstandingInView <= 0) continue;
      rows.add(DueRow(customer: customer, due: due));
    }
    return rows;
  }
}

/// One shop — design 3's row (নকশার নিয়ম ০.৪.৩৮ §৪, ১৯ অক্টোবর ২০২৬):
///
///     [ওয়ালেট]  M/S. Alif mim International Enterprise · ফুলপুর · Super      ৳3,543.00
///               C-0042 · বকেয়া
///
/// ⭐ উপরে "নাম · পয়েন্ট · শাখা" (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম: "গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে অবশ্যই, সব
/// জায়গায়, আর শাখার নামও দিতে হবে"), ডানে বকেয়া; নিচের লাইনে গ্রাহকের নম্বর। The mobile number, the credit days and the
/// limit are not on the row: they are on the shop's own page, which a tap opens ("mobile no to click ba touch kore open
/// korle vitore ache", 6 Oct 2026). The limit is still never judged here or there — see the detail page and
/// `CustomerDueSync`.
class _DueTile extends StatelessWidget {
  const _DueTile({required this.row});

  final DueRow row;

  @override
  Widget build(BuildContext context) {
    final code = row.customer.code;
    return DesignListRow(
      key: ValueKey('due-${row.customer.id}'),
      icon: Icons.account_balance_wallet_outlined,
      tone: context.palette.danger,
      name: row.customer.label,
      second: code == null ? context.l10n.customersDueWord : context.l10n.customersDueWithCode(code),
      amount: row.due.outstandingInView,
      amountColor: Theme.of(context).colorScheme.error,
      onTap: () => context.push('/home/customers/${row.customer.id}'),
    );
  }
}
