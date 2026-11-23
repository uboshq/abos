import 'package:flutter/material.dart';

import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/orders/tracking_api.dart';
import '../../core/records/list_queries.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/app_status_pill.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/search_debouncer.dart';
import '../../core/widgets/list_controls.dart';
import '../../core/widgets/state_views.dart';
import '../delivery_orders/delivery_order_screens.dart' show PaperListRow;
import '../../core/widgets/day_part.dart';

/// ডেলিভারি ট্র্যাকিং — প্রতিটা বিক্রি এখন কোথায়, আর চাপলে কে কখন কী করলেন (0.4.6)।
///
/// <p>⭐ মালিক, ২ অক্টোবর ২০২৬। ⓘ মালিকের নিয়ম: এক লাইনে এক জিনিস, টেবিল নয়।
class DeliveryTrackingScreen extends StatefulWidget {
  const DeliveryTrackingScreen(
      {super.key, this.api = const ServerTrackingApi()});

  final TrackingApi api;

  @override
  State<DeliveryTrackingScreen> createState() => _DeliveryTrackingScreenState();
}

class _DeliveryTrackingScreenState extends State<DeliveryTrackingScreen> {
  final _search = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (_) => _load());
  String? _step;
  // ⭐ সাজান (মালিক, ২ অক্টোবর) — শুধু হাতে আসা সারির ক্রম, সার্ভারে যায় না।
  // ফিল্টারের কাজ ধাপের chip আর খোঁজ আগেই করে, তাই এখানে ফিল্টার বোতাম নেই।
  String _sort = TrackingListQuery.defaultSort;
  TrackingList? _list;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    _typed.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final list =
          await widget.api.list(query: _search.text.trim(), step: _step);
      if (mounted) setState(() => _list = list);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.ordersTrackingLoadFailed,
            whenAbsent: context.l10n.ordersTrackingAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool get _filtered => _step != null || _search.text.trim().isNotEmpty;

  void _showAll() {
    _search.clear();
    setState(() => _step = null);
    _load();
  }

  void _open(TrackedSale sale) {
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => _StoryScreen(sale: sale, api: widget.api),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final list = _list;
    final counts = list?.counts ?? const {};
    final rows = TrackingListQuery.apply(list?.rows ?? const <TrackedSale>[],
        sort: _sort);
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.ordersTrackingTitle, module: context.l10n.ordersModuleSales),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            TextField(
              controller: _search,
              textInputAction: TextInputAction.search,
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
              decoration: InputDecoration(
                hintText: context.l10n.ordersTrackingSearchHint,
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                    tooltip: context.l10n.ordersSearch, icon: const Icon(Icons.arrow_forward), onPressed: _load),
              ),
            ),
            const SizedBox(height: AppSpacing.sm),
            Wrap(
              spacing: AppSpacing.xs,
              runSpacing: AppSpacing.xs,
              children: [
                for (final key in [
                  'all',
                  ...trackingStepLabels.keys.where((k) => k != 'billed')
                ])
                  ChoiceChip(
                    label: Text(
                        '${key == 'all' ? context.l10n.commonAll : trackingStepLabels[key]} (${counts[key] ?? 0})'),
                    selected: (_step ?? 'all') == key,
                    onSelected: (_) {
                      setState(() => _step = key == 'all' ? null : key);
                      _load();
                    },
                  ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            ListControls(
              padding: EdgeInsets.zero,
              sortOptions: TrackingListQuery.sortOptions,
              sort: _sort,
              onSort: (value) => setState(() => _sort = value),
            ),
            const SizedBox(height: AppSpacing.sm),
            // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): প্রথম আনায় খসড়া সারি, ভুলে "আবার চেষ্টা করুন", খালিতে কাজের বোতাম
            if (_busy && list == null && _error == null) const LoadingSkeleton(),
            if (_busy && list != null) const LinearProgressIndicator(),
            if (_error != null)
              ErrorState(key: const ValueKey('tracking-error'), message: _error!, onRetry: _busy ? null : _load),
            if (list != null && list.rows.isEmpty && !_busy && _error == null)
              EmptyState(
                icon: Icons.local_shipping_outlined,
                title: context.l10n.ordersTrackingEmptyTitle,
                message: _filtered ? context.l10n.ordersTrackingEmptyFiltered : context.l10n.ordersTrackingEmptyHint,
                action: OutlinedButton.icon(
                  key: const ValueKey('tracking-empty-action'),
                  style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                  onPressed: _filtered ? _showAll : _load,
                  icon: const Icon(Icons.refresh),
                  label: Text(_filtered ? context.l10n.ordersTrackingShowAll : context.l10n.ordersTrackingReload),
                ),
              ),
            // ⭐ নকশা ৩-এর সারি — গ্রাহক "নাম · পয়েন্ট · শাখা", নিচে বিক্রির নম্বর (আর কাগজের নম্বর) · তারিখ, ডানে টাকা আর
            // ধাপের চিপ; বাঁয়ের আইকন সার্ভারের ৯ রঙের ধাপের রঙে
            for (final sale in rows)
              PaperListRow(
                key: ValueKey('tracked-row-${sale.id}'),
                icon: Icons.local_shipping_outlined,
                tone: _saleColour(context, sale),
                party: sale.customer ?? context.l10n.ordersTrackingNoCustomer,
                // ⭐ বিক্রির নম্বর আর কাগজের নিজের নম্বর, দুটোই — খোঁজায় যেকোনোটা চলে
                number: _numbers(sale),
                numberKey: ValueKey('tracked-no-${sale.id}'),
                detail: sale.date,
                amount: sale.total,
                pill: AppStatusPill(label: _stepText(sale), kind: _kind(sale)),
                onTap: () => _open(sale),
              ),
          ],
        ),
      ),
    );
  }
}

/// বিক্রির নম্বর, আর কাগজের নিজের নম্বর আলাদা হলে সেটাও
String _numbers(TrackedSale sale) => [
      sale.no,
      if (sale.documentNo != null && sale.documentNo!.isNotEmpty && sale.documentNo != sale.no) sale.documentNo!,
    ].join(' · ');

String _stepText(TrackedSale sale) => [
      trackingStepLabels[sale.step] ?? sale.step,
      if (sale.billed) AppLocale.l10n.ordersTrackingStepBilled,
      if (sale.backOrder) AppLocale.l10n.ordersTrackingBackOrder
    ].join(' · ');

/// ধাপের চিপের মানে — পৌঁছানো সবুজ, আটকানো/বাতিল লাল, অপেক্ষা হলুদ, বাকিটা চলছে
AppStatusKind _kind(TrackedSale sale) => switch (sale.step) {
      'delivered' || 'billed' => AppStatusKind.success,
      'cancelled' || 'credit_hold' => AppStatusKind.danger,
      'approval' || 'draft' => AppStatusKind.warning,
      _ => AppStatusKind.pending,
    };

/// সার্ভারের ৯ রং ([trackingColours]); পুরনো সার্ভারে category না এলে ধাপ থেকে আন্দাজ।
Color _saleColour(BuildContext context, TrackedSale sale) =>
    trackingColours[sale.category] ?? _colour(context, sale.step);

Color _colour(BuildContext context, String step) => switch (step) {
      'delivered' => context.palette.success,
      'cancelled' || 'credit_hold' => context.palette.danger,
      'approval' || 'draft' => context.palette.warning,
      _ => context.palette.pending,
    };

/// একটা বিক্রির সময়রেখা — পুরনো আগে, এক লাইনে এক ঘটনা।
class _StoryScreen extends StatefulWidget {
  const _StoryScreen({required this.sale, required this.api});

  final TrackedSale sale;
  final TrackingApi api;

  @override
  State<_StoryScreen> createState() => _StoryScreenState();
}

class _StoryScreenState extends State<_StoryScreen> {
  TrackedSale? _sale;
  List<TrackingEvent> _events = const [];
  List<Milestone> _milestones = const [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (_error != null) setState(() => _error = null);
    try {
      final (sale, events, milestones) = await widget.api.story(widget.sale);
      if (mounted) {
        setState(() {
          _sale = sale;
          _events = events;
          _milestones = milestones;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() =>
            _error = errorMessageFor(e, fallback: context.l10n.ordersTrackingStoryFailed));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sale = _sale ?? widget.sale;
    return Scaffold(
      appBar: AbosScreenBar(title: sale.no, module: context.l10n.ordersModuleSales),
      // ⭐ টেনে নামালে নতুন — মালিকের আদেশ (ধাপ ২)
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            // ⭐ মাথায় গ্রাহক "নাম · পয়েন্ট · শাখা", তার নিচে বিক্রির নম্বর · তারিখ (মালিকের স্থায়ী নিয়ম, ১৯ অক্টোবর ২০২৬)
            CustomerLine(sale.customer ?? context.l10n.ordersTrackingNoCustomer,
                key: const ValueKey('story-customer'),
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: Theme.of(context).colorScheme.onSurface)),
            Text([_numbers(sale), if (sale.date != null) sale.date!].join(' · '),
                key: const ValueKey('story-number'),
                style: TextStyle(fontSize: 13, color: Theme.of(context).colorScheme.onSurfaceVariant)),
            const SizedBox(height: AppSpacing.xs),
            Row(children: [
              Expanded(
                child: Text(Money.taka(sale.total),
                    style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: Theme.of(context).colorScheme.onSurface)),
              ),
              Flexible(child: AppStatusPill(label: _stepText(sale), kind: _kind(sale))),
            ]),
            const Divider(),
            // ⭐ টিকচিহ্নের অগ্রগতি-দাগ — হয়েছে ✓, এখন গোল-দাগ, ফেরত ✕, থামানো ■, সামনে ফাঁপা
            for (var i = 0; i < _milestones.length; i++)
              _MilestoneRow(
                  milestone: _milestones[i],
                  last: i == _milestones.length - 1),
            if (_milestones.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(context.l10n.ordersTrackingStoryWho,
                  style: const TextStyle(fontWeight: FontWeight.w700)),
            ],
            if (_error != null)
              ErrorState(key: const ValueKey('story-error'), message: _error!, onRetry: _load),
            if (_sale == null && _error == null) const LoadingSkeleton(rows: 3),
            for (final e in _events)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.circle, size: 10, color: _colour(context, e.step)),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(e.text,
                              style:
                                  const TextStyle(fontWeight: FontWeight.w600)),
                          Text(
                            [
                              if (e.at != null) stampText(e.at!),
                              if (e.by != null) e.by!
                            ].join(' · '),
                            style: TextStyle(
                                fontSize: 12,
                                color: context.palette.onSurfaceMuted),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _MilestoneRow extends StatelessWidget {
  const _MilestoneRow(
      {required this.milestone, required this.last});

  final Milestone milestone;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final m = milestone;
    // ⭐ ফাঁপা গোল, রেখা আর মলিন লেখা থিম থেকে (নকশার নিয়ম ০.৪.৩৮ §১); ধাপের রং সার্ভারের ৯ রং
    final colour = trackingColours[m.category] ?? const Color(0xFF9CA3AF);
    final filled =
        m.state == 'done' || m.state == 'rejected' || m.state == 'hold';
    final lit = m.state != 'todo';
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 26,
            child: Column(
              children: [
                Container(
                  key: ValueKey('milestone-${m.key}-${m.state}'),
                  width: 22,
                  height: 22,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: filled ? colour : context.palette.surface,
                    border: Border.all(
                        color: lit ? colour : context.palette.border, width: 2),
                  ),
                  child: Text(
                    switch (m.state) {
                      'done' => '✓',
                      'rejected' => '✕',
                      'hold' => '■',
                      _ => ''
                    },
                    // ⓘ ভরা গোলে ধাপের রঙের উপর সাদা চিহ্ন — দুই মোডেই একই (AppColors.onPrimary)
                    style: const TextStyle(
                        color: AppColors.onPrimary, // dark-ok
                        fontSize: 12,
                        fontWeight: FontWeight.w700),
                  ),
                ),
                if (!last)
                  Expanded(
                    child: Container(
                      width: 2,
                      color: m.state == 'done' ? colour : context.palette.border,
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    m.label,
                    style: TextStyle(
                      fontWeight: lit ? FontWeight.w700 : FontWeight.w400,
                      color: m.state == 'current'
                          ? colour
                          : (lit ? null : context.palette.onSurfaceMuted),
                    ),
                  ),
                  if (m.at != null || m.by != null)
                    Text(
                      [
                        if (m.at != null) stampText(m.at!),
                        if (m.by != null) m.by!
                      ].join(' · '),
                      style: TextStyle(fontSize: 12, color: context.palette.onSurfaceMuted),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
