import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/records/customer_record.dart';
import '../../core/records/list_queries.dart';
import '../../core/records/money.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/list_controls.dart';
import '../books/books_widgets.dart';
import 'customer_detail_screen.dart';

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
  const CustomerListScreen({super.key, this.detailBuilder});

  /// ⭐ ট্যাবলেটে বা আড়াআড়ি ফোনে ([twoPaneWidth]-এর বেশি চওড়া) ডানের ঘরে যে পাতা বসে — রাউটার দেয় "বাকির অবস্থা"-র
  /// চাবিসহ; না দিলে সাধারণ [CustomerDetailScreen]।
  final Widget Function(BuildContext context, String customerId)? detailBuilder;

  /// এর বেশি চওড়া হলে দুই ঘর: বাঁয়ে তালিকা, ডানে বাছা গ্রাহক (Material-এর "medium/expanded" মাপ ৬০০ dp থেকে)।
  static const twoPaneWidth = 600.0;

  @override
  State<CustomerListScreen> createState() => _CustomerListScreenState();
}

class _CustomerListScreenState extends State<CustomerListScreen> {
  bool _refreshing = false;
  String _query = '';
  // ফিল্টার আর সাজানো — শুধু স্ক্রিন খোলা থাকা পর্যন্ত (দেখুন ListControls)।
  String _sort = CustomerListQuery.defaultSort;
  ListFilters _filters = const {};

  /// ⭐ দুই ঘরে বাছা গ্রাহক — এই State-এ থাকে, তাই ফোন ঘোরালে (চওড়া বদলালে) হারায় না: সরু হলে তালিকায় দাগ দেওয়া থাকে,
  /// আবার চওড়া হলে সেই গ্রাহকই ডানে (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)।
  String? _selectedId;

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
    // Why the last pull brought nothing down, when that is what happened —
    // null after a clean pull, and an empty list then means an empty list.
    final trouble = ReferenceSync.troubleSentence;
    final all = CustomerRecord.all();
    final filtered = CustomerListQuery.apply(all,
        query: _query, sort: _sort, filters: _filters);
    // ⭐ দেখানো দোকানগুলোর মোট বকেয়া — মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary/grand total"। বকেয়ার তালিকার একই নিয়ম:
    // কেবল যাঁর কাছে পাওনা (অগ্রিম মোট থেকে কাটে না), দেখার শাখার অঙ্কে ([[CustomerDueRecord.outstandingInView]])।
    final owed = filtered.fold<double>(0, (sum, customer) {
      final due = CustomerDueRecord.forCustomer(customer.id)?.outstandingInView ?? 0;
      return due > 0 ? sum + due : sum;
    });

    // ⭐ চওড়া পর্দায় দুই ঘর — বাঁয়ে তালিকা, ডানে গ্রাহকের পাতা; ফোনে আগের মতো এক ঘর, চাপলে নিজের পাতা
    // (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)
    return LayoutBuilder(builder: (context, constraints) {
      final twoPane = constraints.maxWidth > CustomerListScreen.twoPaneWidth;
      final list = _list(context, all, filtered, owed, trouble, twoPane);
      if (!twoPane) return list;
      final selected = _selectedId == null ? null : CustomerRecord.byId(_selectedId!);
      return Row(
        key: const ValueKey('customers-two-pane'),
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(width: (constraints.maxWidth * 0.4).clamp(320.0, 420.0), child: list),
          const VerticalDivider(width: 1),
          Expanded(
            child: selected == null
                ? const Scaffold(
                    key: ValueKey('customers-detail-empty'),
                    body: EmptyState(
                      icon: Icons.touch_app_outlined,
                      title: 'বাঁয়ের তালিকা থেকে একজন গ্রাহক বাছুন',
                    ),
                  )
                : KeyedSubtree(
                    key: ValueKey('customers-detail-${selected.id}'),
                    child: widget.detailBuilder?.call(context, selected.id) ??
                        CustomerDetailScreen(customerId: selected.id),
                  ),
          ),
        ],
      );
    });
  }

  Widget _list(BuildContext context, List<CustomerRecord> all, List<CustomerRecord> filtered, double owed,
      String? trouble, bool twoPane) {
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
          ListControls(
            sortOptions: CustomerListQuery.sortOptions,
            sort: _sort,
            onSort: (value) => setState(() => _sort = value),
            filterGroups: CustomerListQuery.filterGroups(all),
            filters: _filters,
            onFilters: (value) => setState(() => _filters = value),
          ),
          if (filtered.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
              child: TotalStrip(label: 'মোট বকেয়া', value: Money.taka(owed), count: filtered.length),
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
                      children: [
                        EmptyState(
                          icon: trouble == null
                              ? Icons.people_outline
                              : Icons.cloud_off_outlined,
                          title: trouble == null
                              ? 'এখনো কোনো গ্রাহক সিঙ্ক হয়নি'
                              : 'গ্রাহকের তালিকা আনা যায়নি',
                          // ⚠️ A failed pull and an empty catalogue leave this
                          // screen looking identical, and only one of the two
                          // is fixed by pulling. Without the reason here, a
                          // phone whose token expired answers every pull with
                          // the same 'নিচে টেনে আবার চেষ্টা করুন' — the pull
                          // half of what SyncEngine.lastFailureFor documents.
                          message: trouble ?? 'নিচে টেনে আবার চেষ্টা করুন।',
                        ),
                      ],
                    )
                  : filtered.isEmpty
                      ? ListView(
                          children: [
                            EmptyState(
                              icon: Icons.search_off,
                              title: 'কোনো মিল পাওয়া যায়নি',
                              // ফিল্টার চালু থাকলে খালি তালিকার কারণ সেটাও হতে পারে।
                              message: _filters.isEmpty
                                  ? null
                                  : 'ফিল্টার মুছে আবার দেখুন।',
                            ),
                          ],
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.symmetric(
                              horizontal: AppSpacing.md),
                          itemCount: filtered.length,
                          separatorBuilder: (_, __) =>
                              const SizedBox(height: AppSpacing.xs),
                          itemBuilder: (context, index) {
                            final customer = filtered[index];
                            return _CustomerTile(
                              customer: customer,
                              selected: twoPane && customer.id == _selectedId,
                              // চওড়া পর্দায় ডানের ঘরে খোলে; ফোনে আগের মতো নিজের পাতায়
                              onOpen: twoPane
                                  ? () => setState(() => _selectedId = customer.id)
                                  : () => context.go('/home/customers/${customer.id}'),
                            );
                          },
                        ),
            ),
          ),
        ],
      ),
    );
  }
}

class _CustomerTile extends StatelessWidget {
  const _CustomerTile({required this.customer, required this.onOpen, this.selected = false});

  final CustomerRecord customer;
  final VoidCallback onOpen;

  /// দুই ঘরে ডানে যে খোলা — সারিটা রঙে আর TalkBack-এ "বাছা"
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final name = customer.name;
    // ⓘ পয়েন্ট আর শাখা নামের লাইনে ([CustomerRecord.label]) — নিচে কেবল ফোন আর ঠিকানা
    final subtitle = [
      if (customer.phone != null) customer.phone!,
      if (customer.address != null) customer.address!,
    ].join(' · ');
    final due = CustomerDueRecord.forCustomer(customer.id);

    // ⓘ বড় লেখায় (দেড়গুণের বেশি) বকেয়া ডানে নয়, নামের নিচে নিজের লাইনে — পুরো মাপে, পড়ার মতো; ডানে থাকলে সারির
    // চওড়ার ৪০%-এর বেশি নেয় না (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)। ⛔ ২০০% লেখায় আগে বকেয়া পুরো সারি খেয়ে
    // ফেলত: "Trailing widget consumes the entire tile width"।
    final big = isLargeText(context);
    Widget? duePill(double maxWidth) => due == null
        ? null
        : Semantics(
            container: true,
            button: true,
            onTapHint: 'খাতা খুলুন',
            child: InkWell(
              key: ValueKey('ledger-${customer.id}'),
              onTap: () => context.push('/home/customers/${customer.id}/ledger'),
              child: ConstrainedBox(
                constraints: BoxConstraints(minWidth: 48, minHeight: 48, maxWidth: maxWidth),
                child: _DuePill(due: due, shrinkToFit: !big),
              ),
            ),
          );
    return Card(
      child: LayoutBuilder(
        builder: (context, constraints) => ListTile(
        key: ValueKey('customer-row-${customer.id}'),
        selected: selected,
        // ⓘ নামের প্রথম অক্ষরের বৃত্ত সাজ — TalkBack নামটা একবারই পড়ে (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)
        leading: ExcludeSemantics(
          child: CircleAvatar(
            backgroundColor: AppColors.primary.withValues(alpha: 0.12),
            child: Text(
              name.isNotEmpty ? name[0].toUpperCase() : '?',
              style: const TextStyle(
                  color: AppColors.primary, fontWeight: FontWeight.w700),
            ),
          ),
        ),
        // ⭐ "নাম · পয়েন্ট · শাখা" এক লাইনে — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম); লম্বা হলে "…", পুরোটা ছুঁয়ে ধরে রাখলে
        title: CustomerLine(customer.label, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: big && due != null
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (subtitle.isNotEmpty) Text(subtitle, maxLines: 2, overflow: TextOverflow.ellipsis),
                  duePill(constraints.maxWidth)!,
                ],
              )
            : subtitle.isEmpty
                ? null
                : Text(subtitle, maxLines: 1, overflow: TextOverflow.ellipsis),
        onTap: onOpen,
        // No due row at all when this shop's CustomerDue has not been pulled
        // yet — the two entity types have separate watermarks, so one can
        // arrive a sync ahead of the other, and a shop showing "বকেয়া নেই"
        // when the truth is simply unknown is the one wrong thing to say.
        // ⭐ বকেয়ার অঙ্কে চাপলে সোজা খাতা — মালিক, ১২ অক্টোবর ২০২৬
        // ⓘ TalkBack-এ বকেয়ার অঙ্ক নিজেই একটা বোতাম — "বকেয়া ৳…, খাতা খুলুন", আঙুলের জায়গা অন্তত ৪৮ dp
        // (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)। ⛔ আগে এর চাপটা কার্ডের নামহীন ঘরে গিয়ে পড়ত।
        trailing: big ? null : duePill(constraints.maxWidth * 0.4),
        ),
      ),
    );
  }
}

/// ⓘ ফোনের লেখা দেড়গুণের বেশি বড় — তখন সারির ডানের অঙ্ক নিচে নামে (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)
bool isLargeText(BuildContext context) => MediaQuery.textScalerOf(context).scale(10) > 15;

class _DuePill extends StatelessWidget {
  const _DuePill({required this.due, this.shrinkToFit = true});

  final CustomerDueRecord due;

  /// ডানের ঘরে — জায়গা না হলে ছোট হয়; নামের নিচে (বড় লেখায়) পুরো মাপে
  final bool shrinkToFit;

  @override
  Widget build(BuildContext context) {
    // Owed is the only state worth a colour. A shop that is square, or in
    // advance, is not news — and colouring it green would make the ordinary
    // case shout as loudly as the one a rep has to act on.
    final owes = due.outstandingInView > 0;

    // ⓘ অঙ্ক আর দিন — সারির ঘরে না ধরলে দুটোই একসাথে ছোট হয়; অঙ্ক মাঝখানে ভাঙে না, নিচে কেটেও যায় না
    // (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)। ⛔ ২০০% লেখায় আগে ১৮ dp নিচে বেরিয়ে যেত।
    return FittedBox(
      fit: BoxFit.scaleDown,
      alignment: shrinkToFit ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: shrinkToFit ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          Text(
            due.outstandingLabel,
            semanticsLabel: Money.speakable(due.outstandingLabel),
            maxLines: 1,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              fontSize: 13,
              color: owes ? AppColors.danger : context.palette.onSurfaceMuted,
            ),
          ),
          if (due.creditDays > 0)
            Text(
              '${due.creditDays} দিন',
              maxLines: 1,
              style: Theme.of(context).textTheme.bodySmall,
            ),
        ],
      ),
    );
  }
}
