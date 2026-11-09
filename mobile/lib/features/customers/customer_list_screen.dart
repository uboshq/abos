import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/records/customer_record.dart';
import '../../core/records/list_queries.dart';
import '../../core/records/money.dart';
import '../../core/sync_engine/reference_sync.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/amount_text.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/list_controls.dart';
import '../books/books_widgets.dart';
import 'customer_detail_screen.dart';
import '../printing/row_actions.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

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
    // ⭐ দেখানো দোকানগুলোর মোট — মালিক, ১৭ অক্টোবর ২০২৬: "সব পর্দায় summary/grand total"; দেখার শাখার অঙ্কে
    // ([[CustomerDueRecord.outstandingInView]])।
    final owed = CustomerTotals.of(filtered.map((c) => CustomerDueRecord.forCustomer(c.id)?.outstandingInView ?? 0));

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
                ? Scaffold(
                    key: const ValueKey('customers-detail-empty'),
                    body: EmptyState(
                      icon: Icons.touch_app_outlined,
                      title: context.l10n.customersPickFromList,
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

  Widget _list(BuildContext context, List<CustomerRecord> all, List<CustomerRecord> filtered, CustomerTotals owed,
      String? trouble, bool twoPane) {
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.customersTitle),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: InputDecoration(
                hintText: context.l10n.customersSearchHint,
                prefixIcon: const Icon(Icons.search),
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
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                // ⭐ নিট = বকেয়া − অগ্রিম, ওয়েবের গ্রাহক-তালিকার সর্বমোটের হুবহু (CustomerController: `outstanding_in_view`-এর
                // চিহ্নসহ যোগ); নিচে বকেয়া আর অগ্রিম নিজের নিজের লাইনে (fe-র মোটের নিরীক্ষা, ২৪ অক্টোবর ২০২৬)।
                // ⛔ আগে কেবল বকেয়া যোগ হত — অগ্রিম বাদ পড়ত, ওয়েবের চেয়ে বড় অঙ্ক বলত।
                TotalStrip(label: context.l10n.customersTotalNet, value: Money.taka(owed.net), count: filtered.length),
                TotalLine(context.l10n.customersTotalDue, Money.taka(owed.dues),
                    key: const ValueKey('customers-total-dues')),
                TotalLine(context.l10n.customersTotalAdvances, Money.taka(owed.advances),
                    key: const ValueKey('customers-total-advances')),
              ]),
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
                              ? context.l10n.customersNoneSynced
                              : context.l10n.customersListFailed,
                          // ⚠️ A failed pull and an empty catalogue leave this
                          // screen looking identical, and only one of the two
                          // is fixed by pulling. Without the reason here, a
                          // phone whose token expired answers every pull with
                          // the same 'নিচে টেনে আবার চেষ্টা করুন' — the pull
                          // half of what SyncEngine.lastFailureFor documents.
                          message: trouble ?? context.l10n.customersPullToRetry,
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
    // ⭐ অঙ্ক কখনো ছোট হয় না (WCAG 1.4.4; fe, ২২ অক্টোবর ২০২৬): ডানে সারির ৪০%-এ না ধরলে নামের নিচে, ডানে, পুরো মাপে
    bool stacked(double rowWidth) =>
        due != null && (big || !Amounts.fitsBeside(context, rowWidth, _DuePill.lines(context, due), share: 0.4));
    Widget? duePill(double maxWidth) => due == null
        ? null
        : Semantics(
            container: true,
            button: true,
            onTapHint: context.l10n.customersOpenLedgerHint,
            child: InkWell(
              key: ValueKey('ledger-${customer.id}'),
              onTap: () => context.push('/home/customers/${customer.id}/ledger'),
              child: ConstrainedBox(
                constraints: BoxConstraints(minWidth: 48, minHeight: 48, maxWidth: maxWidth),
                child: _DuePill(due: due),
              ),
            ),
          );
    return Card(
      child: LayoutBuilder(
        builder: (context, constraints) {
        final stack = stacked(constraints.maxWidth);
        final tile = ListTile(
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
        subtitle: subtitle.isEmpty
            ? null
            : Text(subtitle, maxLines: stack ? 2 : 1, overflow: TextOverflow.ellipsis),
        onTap: onOpen,
        // No due row at all when this shop's CustomerDue has not been pulled
        // yet — the two entity types have separate watermarks, so one can
        // arrive a sync ahead of the other, and a shop showing "বকেয়া নেই"
        // when the truth is simply unknown is the one wrong thing to say.
        // ⭐ বকেয়ার অঙ্কে চাপলে সোজা খাতা — মালিক, ১২ অক্টোবর ২০২৬
        // ⓘ TalkBack-এ বকেয়ার অঙ্ক নিজেই একটা বোতাম — "বকেয়া ৳…, খাতা খুলুন", আঙুলের জায়গা অন্তত ৪৮ dp
        // (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)। ⛔ আগে এর চাপটা কার্ডের নামহীন ঘরে গিয়ে পড়ত।
        trailing: stack ? null : duePill(constraints.maxWidth * 0.4),
        );
        // ⭐ ওয়েবের মতো "অ্যাকশন ▾" (মালিক, ৯ নভেম্বর ২০২৬: "Abos er sokol list"; নমুনা v4: সারির ডানে, অঙ্কের নিচে) —
        //   দেখুন (দোকানের পাতা) · কাজ: খাতা খুলুন (বকেয়ার অঙ্কে চাপার একই পথ)
        final actions = RowActionsButton(
          number: customer.label,
          state: null,
          onView: onOpen,
          work: [
            RowAction(
              key: 'ledger',
              label: context.l10n.customersOpenLedgerHint,
              icon: Icons.menu_book_outlined,
              onTap: () => context.push('/home/customers/${customer.id}/ledger'),
            ),
          ],
        );
        // ⓘ দুই তলা হলে বকেয়া কার্ডের পুরো চওড়ায়, ডানে — সারির লেখার নিচে; তার নিচে বোতাম
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
          tile,
          if (stack)
            Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 4),
              child: Align(alignment: AlignmentDirectional.centerEnd, child: duePill(constraints.maxWidth - 32)!),
            ),
          Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(16, 0, 16, 8),
            child: Align(alignment: AlignmentDirectional.centerEnd, child: actions),
          ),
        ]);
        },
      ),
    );
  }
}

/// ⭐ দেখানো দোকানগুলোর মোট — ওয়েবের গ্রাহক-তালিকার মতো নিট (বকেয়া − অগ্রিম), সাথে বকেয়া আর অগ্রিম আলাদা।
///
/// <p>ⓘ ধনাত্মক বকেয়া মানে দোকান দেবে, ঋণাত্মক মানে অগ্রিম ([CustomerDueRecord.outstanding]); ওয়েব চিহ্নসহ যোগ করে, তাই নিট।
/// ⓘ পয়সায় যোগ — দশমিকের ভাসমান ভুল জমে না।
class CustomerTotals {
  const CustomerTotals._(this._duePaisa, this._advancePaisa);

  factory CustomerTotals.of(Iterable<double> balances) {
    var due = 0, advance = 0;
    for (final b in balances) {
      final paisa = (b * 100).round();
      if (paisa > 0) {
        due += paisa;
      } else {
        advance -= paisa;
      }
    }
    return CustomerTotals._(due, advance);
  }

  final int _duePaisa;
  final int _advancePaisa;

  /// যাঁদের কাছে পাওনা, তাঁদের যোগ
  double get dues => _duePaisa / 100;

  /// যাঁরা আগে দিয়ে রেখেছেন, তাঁদের যোগ (ধনাত্মক)
  double get advances => _advancePaisa / 100;

  /// বকেয়া − অগ্রিম — ওয়েবের সর্বমোট
  double get net => (_duePaisa - _advancePaisa) / 100;
}

/// ⓘ ফোনের লেখা দেড়গুণের বেশি বড় — তখন সারির ডানের অঙ্ক নিচে নামে (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)
bool isLargeText(BuildContext context) => MediaQuery.textScalerOf(context).scale(10) > 15;

class _DuePill extends StatelessWidget {
  const _DuePill({required this.due});

  final CustomerDueRecord due;

  /// অঙ্ক আর দিনের লাইন, যে মাপে আঁকা হয় — সারি এগুলো মেপে ঠিক করে ডানে বসবে না নিচে নামবে
  static List<(String, TextStyle)> lines(BuildContext context, CustomerDueRecord due) {
    // Owed is the only state worth a colour. A shop that is square, or in
    // advance, is not news — and colouring it green would make the ordinary
    // case shout as loudly as the one a rep has to act on.
    final owes = due.outstandingInView > 0;
    return [
      (due.outstandingLabel, Amounts.main(context, color: owes ? context.palette.danger : context.palette.onSurfaceMuted)),
      if (due.creditDays > 0)
        (context.l10n.customersCreditDaysShort(due.creditDays), Amounts.second(context, color: context.palette.onSurfaceMuted)),
    ];
  }

  @override
  Widget build(BuildContext context) {
    // ⭐ অঙ্ক আর দিন — সারির লেখার চেয়ে ছোট নয় (১৬ আর ১৪), লেখার মাপের সাথে বাড়ে, এক লাইনে; ঘরে না ধরলে সারি নিজেই
    // দুই তলা হয় (fe, ২২ অক্টোবর ২০২৬)। ⛔ আগে FittedBox(scaleDown) দুটোকে ৬–৭px-এ নামিয়ে আনত।
    final l = lines(context, due);
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        AmountText(l[0].$1, key: const ValueKey('due-amount'), semanticsLabel: Money.speakable(l[0].$1), style: l[0].$2),
        if (l.length > 1) AmountText(l[1].$1, style: l[1].$2),
      ],
    );
  }
}
