import 'package:flutter/material.dart';

import '../../core/records/customer_record.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// Every customer this device has pulled, each with what the shop owes.
///
/// <p>Reads through [CustomerRecord] and [CustomerDueRecord] rather than
/// reaching into the cached payload by hand — see [CustomerRecord]'s own doc
/// comment for what reading guessed key names cost this screen.
///
/// <p><b>Why the due belongs on the list and not only on a detail screen</b>
/// — docs/Contract §০ names outstanding credit as one of the four things a
/// phone cannot learn offline, and `CustomerDueSync` goes to real trouble to
/// keep the figure fresh. A rep walking a route decides which shop to call on
/// from this list; the figure is worth nothing a tap away.
class CustomerListScreen extends StatefulWidget {
  const CustomerListScreen({super.key});

  @override
  State<CustomerListScreen> createState() => _CustomerListScreenState();
}

class _CustomerListScreenState extends State<CustomerListScreen> {
  bool _refreshing = false;
  String _query = '';

  Future<void> _refresh() async {
    setState(() => _refreshing = true);
    try {
      await ReferenceSync.syncAll();
    } catch (_) {
      // A failed pull leaves whatever was already cached on screen — see
      // reference_sync.dart's own doc comment on why this is left to throw
      // rather than swallowed there; this caller's answer to that failure is
      // simply "the list on screen is whatever it already was".
    } finally {
      if (mounted) setState(() => _refreshing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final all = CustomerRecord.all();
    final filtered =
        all.where((customer) => customer.matches(_query)).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('গ্রাহক')),
      body: Column(
        children: [
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
            // Always a RefreshIndicator, never a bare EmptyState — a
            // RefreshIndicator needs a scrollable descendant to recognise the
            // pull gesture at all. Confirmed on a real device: the earlier
            // version showed "নিচে টেনে আবার চেষ্টা করুন" on first launch (an
            // empty cache) with no RefreshIndicator wrapping it, so the one
            // instruction on screen did nothing when followed.
            child: RefreshIndicator(
              onRefresh: _refresh,
              child: all.isEmpty
                  ? ListView(
                      children: const [
                        EmptyState(
                          icon: Icons.people_outline,
                          title: 'এখনো কোনো গ্রাহক সিঙ্ক হয়নি',
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
                              _CustomerTile(customer: filtered[index]),
                        ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CustomerTile extends StatelessWidget {
  const _CustomerTile({required this.customer});

  final CustomerRecord customer;

  @override
  Widget build(BuildContext context) {
    final name = customer.name;
    final subtitle = [
      if (customer.phone != null) customer.phone!,
      if (customer.address != null) customer.address!,
    ].join(' · ');
    final due = CustomerDueRecord.forCustomer(customer.id);

    return Card(
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: AppColors.primary.withValues(alpha: 0.12),
          child: Text(
            name.isNotEmpty ? name[0].toUpperCase() : '?',
            style: const TextStyle(
                color: AppColors.primary, fontWeight: FontWeight.w700),
          ),
        ),
        title: Text(name, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: subtitle.isEmpty
            ? null
            : Text(subtitle, maxLines: 1, overflow: TextOverflow.ellipsis),
        // No due row at all when this shop's CustomerDue has not been pulled
        // yet — the two entity types have separate watermarks, so one can
        // arrive a sync ahead of the other, and a shop showing "বকেয়া নেই"
        // when the truth is simply unknown is the one wrong thing to say.
        trailing: due == null ? null : _DuePill(due: due),
      ),
    );
  }
}

class _DuePill extends StatelessWidget {
  const _DuePill({required this.due});

  final CustomerDueRecord due;

  @override
  Widget build(BuildContext context) {
    // Owed is the only state worth a colour. A shop that is square, or in
    // advance, is not news — and colouring it green would make the ordinary
    // case shout as loudly as the one a rep has to act on.
    final owes = due.outstanding > 0;

    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Text(
          due.outstandingLabel,
          style: TextStyle(
            fontWeight: FontWeight.w700,
            fontSize: 13,
            color: owes ? AppColors.danger : AppColors.onSurfaceMuted,
          ),
        ),
        if (due.creditDays > 0)
          Text(
            '${due.creditDays} দিন',
            style: Theme.of(context).textTheme.bodySmall,
          ),
      ],
    );
  }
}
