import 'package:flutter/material.dart';

import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

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
/// the four that matter are at the top of this one.
class DueListScreen extends StatefulWidget {
  const DueListScreen({super.key});

  @override
  State<DueListScreen> createState() => _DueListScreenState();
}

class _DueListScreenState extends State<DueListScreen> {
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
    final rows = _owing()..sort((a, b) => b.due.outstanding.compareTo(a.due.outstanding));
    final filtered =
        rows.where((row) => row.customer.matches(_query)).toList();
    final total = rows.fold<double>(0, (sum, row) => sum + row.due.outstanding);

    return Scaffold(
      appBar: AppBar(title: const Text('বকেয়া তালিকা')),
      body: Column(
        children: [
          if (rows.isNotEmpty) _TotalStrip(total: total, shops: rows.length),
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: const InputDecoration(
                hintText: 'নাম বা মোবাইল দিয়ে খুঁজুন',
                prefixIcon: Icon(Icons.search),
              ),
              onChanged: (value) => setState(() => _query = value),
            ),
          ),
          if (_refreshing) const LinearProgressIndicator(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: rows.isEmpty
                  ? ListView(
                      children: const [
                        EmptyState(
                          icon: Icons.check_circle_outline,
                          // Good news, not an error — the same distinction the
                          // approvals inbox draws.
                          title: 'কারো কাছে বকেয়া নেই',
                          message: 'সিঙ্ক হয়নি মনে হলে নিচে টেনে দেখুন।',
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
  List<_DueRow> _owing() {
    final rows = <_DueRow>[];
    for (final customer in CustomerRecord.all()) {
      final due = CustomerDueRecord.forCustomer(customer.id);
      if (due == null || due.outstanding <= 0) continue;
      rows.add(_DueRow(customer: customer, due: due));
    }
    return rows;
  }
}

class _DueRow {
  const _DueRow({required this.customer, required this.due});

  final CustomerRecord customer;
  final CustomerDueRecord due;
}

/// The one number an owner opens this page for.
class _TotalStrip extends StatelessWidget {
  const _TotalStrip({required this.total, required this.shops});

  final double total;
  final int shops;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      color: AppColors.primary,
      padding: const EdgeInsets.symmetric(
          horizontal: AppSpacing.md, vertical: AppSpacing.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('মোট বকেয়া',
              style: TextStyle(color: Colors.white70, fontSize: 12.5)),
          const SizedBox(height: AppSpacing.xs),
          Text(
            Money.taka(total),
            style: const TextStyle(
                color: Colors.white, fontSize: 26, fontWeight: FontWeight.w700),
          ),
          Text('$shops টি দোকান',
              style: const TextStyle(color: Colors.white70, fontSize: 12.5)),
        ],
      ),
    );
  }
}

class _DueTile extends StatelessWidget {
  const _DueTile({required this.row});

  final _DueRow row;

  @override
  Widget build(BuildContext context) {
    final due = row.due;

    return Card(
      child: ListTile(
        title: Text(row.customer.name,
            style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(
          [
            if (row.customer.phone != null) row.customer.phone!,
            if (due.creditDays > 0) '${due.creditDays} দিনের শর্ত',
            // The limit is shown, never judged against. CustomerDueSync says
            // it plainly: a zero limit means cash or advance, and whether any
            // limit blocks a sale is a company switch the phone is not sent.
            // Drawing a verdict here would be this app deciding something it
            // was deliberately not told how to decide.
            if (due.hasCreditLimit) 'সীমা ${Money.taka(due.creditLimit)}',
          ].join(' · '),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: Text(
          Money.taka(due.outstanding),
          style: const TextStyle(
              fontWeight: FontWeight.w700,
              fontSize: 15,
              color: AppColors.danger),
        ),
      ),
    );
  }
}
