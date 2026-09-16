import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/records/sales_order_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// One shop, everything the phone already knows about it.
///
/// <p><b>Built entirely from what has already synced</b> — `Customer`,
/// `CustomerDue` and `SalesOrder` are all on the device, and until now the
/// only way to see a shop's history was to scroll the orders list looking for
/// its name. A rep standing at a counter asks two questions, and this screen
/// is those two: *what do they owe* and *what did they take last time*.
///
/// <p>It also does the two things a web page on the same data cannot: dial
/// the number and open the address. That is most of why an app is worth
/// having at all for someone who is standing up.
class CustomerDetailScreen extends StatelessWidget {
  const CustomerDetailScreen({super.key, required this.customerId});

  final String customerId;

  @override
  Widget build(BuildContext context) {
    final customer = CustomerRecord.byId(customerId);

    if (customer == null) {
      // A shop can drop out of the local catalogue between opening a list and
      // tapping a row — a re-sync, a deactivation. Saying so is better than a
      // screen of blanks that looks like a bug.
      return Scaffold(
        appBar: AppBar(title: const Text('গ্রাহক')),
        body: const Center(
          child: Padding(
            padding: EdgeInsets.all(AppSpacing.lg),
            child: Text('এই গ্রাহক আর এই ফোনে নেই — সিঙ্ক করে দেখুন।',
                textAlign: TextAlign.center),
          ),
        ),
      );
    }

    final due = CustomerDueRecord.forCustomer(customerId);
    final orders = SalesOrderRecord.all()
        .where((o) => o.customerId == customerId)
        .toList()
      ..sort((a, b) =>
          (b.trxDate ?? DateTime(0)).compareTo(a.trxDate ?? DateTime(0)));

    return Scaffold(
      appBar: AppBar(title: Text(customer.name)),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          _Header(customer: customer),
          const SizedBox(height: AppSpacing.md),
          if (due != null) _DueCard(due: due),
          const SizedBox(height: AppSpacing.md),
          _Actions(customer: customer),
          const SizedBox(height: AppSpacing.lg),
          Text('এই দোকানের অর্ডার (${orders.length})',
              style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: AppSpacing.sm),
          if (orders.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: AppSpacing.md),
              child: Text(
                // Not "this shop has never ordered": only orders that have
                // synced back are here, and a brand-new phone has none of
                // them yet.
                'এই ফোনে এই দোকানের কোনো অর্ডার সিঙ্ক হয়নি।',
                style: TextStyle(color: AppColors.onSurfaceMuted),
              ),
            )
          else
            ...orders.map((o) => Card(
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    title: Text(o.documentNo ?? 'নম্বর নেই'),
                    subtitle: Text([
                      if (o.trxDate != null)
                        DateFormat('dd/MM/yyyy').format(o.trxDate!),
                      o.statusLabel,
                    ].join(' · ')),
                    trailing: o.total == null
                        ? null
                        : Text(Money.taka(o.total),
                            style:
                                const TextStyle(fontWeight: FontWeight.w700)),
                  ),
                )),
        ],
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.customer});

  final CustomerRecord customer;

  @override
  Widget build(BuildContext context) {
    final lines = [
      if (customer.code != null) customer.code!,
      if (customer.ownerName != null) customer.ownerName!,
      if (customer.phone != null) customer.phone!,
      if (customer.address != null) customer.address!,
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(customer.name,
            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
        for (final line in lines)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.xs),
            child: Text(line, style: Theme.of(context).textTheme.bodySmall),
          ),
        if (!customer.isActive)
          const Padding(
            padding: EdgeInsets.only(top: AppSpacing.sm),
            child: Text('⚠️ এই গ্রাহক নিষ্ক্রিয়',
                style: TextStyle(
                    color: AppColors.warning, fontWeight: FontWeight.w600)),
          ),
      ],
    );
  }
}

class _DueCard extends StatelessWidget {
  const _DueCard({required this.due});

  final CustomerDueRecord due;

  @override
  Widget build(BuildContext context) {
    final owes = due.outstanding > 0;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(due.outstandingLabel,
                style: TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w700,
                  color: owes ? AppColors.danger : AppColors.onSurface,
                )),
            if (due.hasCreditLimit || due.creditDays > 0)
              Text(
                [
                  if (due.hasCreditLimit) 'সীমা ${Money.taka(due.creditLimit)}',
                  if (due.creditDays > 0) '${due.creditDays} দিনের শর্ত',
                ].join(' · '),
                style: Theme.of(context).textTheme.bodySmall,
              ),
            // The figure's age, because a due that synced this morning and one
            // that synced last Tuesday look identical otherwise — and this is
            // the number somebody is about to promise goods against.
            if (CustomerDueRecord.syncedAt(due.customerId) != null)
              Text(
                'সিঙ্ক ${DateFormat('dd/MM/yyyy hh:mm a').format(CustomerDueRecord.syncedAt(due.customerId)!)}',
                style: const TextStyle(
                    fontSize: 11.5, color: AppColors.onSurfaceMuted),
              ),
          ],
        ),
      ),
    );
  }
}

/// The two things a phone can do that a screen at a desk cannot, plus the one
/// that starts the actual work.
class _Actions extends StatelessWidget {
  const _Actions({required this.customer});

  final CustomerRecord customer;

  @override
  Widget build(BuildContext context) {
    return Wrap(
      spacing: AppSpacing.sm,
      runSpacing: AppSpacing.sm,
      children: [
        if (customer.phone != null)
          OutlinedButton.icon(
            onPressed: () => _open('tel:${customer.phone}'),
            icon: const Icon(Icons.call_outlined),
            label: const Text('কল করুন'),
          ),
        if (customer.address != null)
          OutlinedButton.icon(
            // geo: with a query — the shop's address is a name, not a
            // coordinate, and no part of this app pretends to know where a
            // shop is on a map.
            onPressed: () =>
                _open('geo:0,0?q=${Uri.encodeComponent(customer.address!)}'),
            icon: const Icon(Icons.location_on_outlined),
            label: const Text('ঠিকানা'),
          ),
        FilledButton.icon(
          onPressed: () => context.go('/home/new-order'),
          icon: const Icon(Icons.add_shopping_cart_outlined),
          label: const Text('নতুন অর্ডার'),
        ),
      ],
    );
  }

  Future<void> _open(String uri) async {
    final parsed = Uri.tryParse(uri);
    if (parsed == null) return;
    // Failing quietly is right here: a tablet with no dialler is a real
    // thing, and an error dialog about it helps nobody.
    await launchUrl(parsed, mode: LaunchMode.externalApplication);
  }
}
