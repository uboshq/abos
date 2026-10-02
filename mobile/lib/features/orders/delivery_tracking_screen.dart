import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/orders/tracking_api.dart';
import '../../core/records/list_queries.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/list_controls.dart';

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
            fallback: 'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent:
                'সার্ভারে ডেলিভারি ট্র্যাকিং এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
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
      appBar: AppBar(title: const Text('ডেলিভারি ট্র্যাকিং')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            TextField(
              controller: _search,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _load(),
              decoration: InputDecoration(
                hintText: 'বিক্রয় নম্বর, ডিও বা দোকান',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                    icon: const Icon(Icons.arrow_forward), onPressed: _load),
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
                        '${key == 'all' ? 'সব' : trackingStepLabels[key]} (${counts[key] ?? 0})'),
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
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              Card(
                color: AppColors.dangerSurface,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Text(_error!,
                      style: const TextStyle(color: AppColors.danger)),
                ),
              ),
            if (list != null && list.rows.isEmpty && !_busy)
              const Padding(
                padding: EdgeInsets.all(AppSpacing.lg),
                child: Text('কোনো বিক্রি নেই।', textAlign: TextAlign.center),
              ),
            for (final sale in rows)
              Card(
                child: InkWell(
                  onTap: () => _open(sale),
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(sale.no,
                            style:
                                const TextStyle(fontWeight: FontWeight.w700)),
                        if (sale.customer != null) Text(sale.customer!),
                        if (sale.date != null)
                          Text(sale.date!,
                              style: const TextStyle(
                                  color: AppColors.onSurfaceMuted)),
                        Text(Money.taka(sale.total)),
                        Text(
                          [
                            trackingStepLabels[sale.step] ?? sale.step,
                            if (sale.billed) 'বিল হয়েছে'
                          ].join(' · '),
                          style: TextStyle(
                              color: _saleColour(sale),
                              fontWeight: FontWeight.w600),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// সার্ভারের ৯ রং ([trackingColours]); পুরনো সার্ভারে category না এলে ধাপ থেকে আন্দাজ।
Color _saleColour(TrackedSale sale) =>
    trackingColours[sale.category] ?? _colour(sale.step);

Color _colour(String step) => switch (step) {
      'delivered' => AppColors.success,
      'cancelled' => AppColors.danger,
      'approval' || 'draft' => AppColors.warning,
      _ => AppColors.pending,
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
            _error = errorMessageFor(e, fallback: 'সময়রেখা আনা গেল না।'));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final sale = _sale ?? widget.sale;
    final when = DateFormat('dd/MM/yyyy hh:mm a');
    return Scaffold(
      appBar: AppBar(title: Text(sale.no)),
      // ⭐ টেনে নামালে নতুন — মালিকের আদেশ (ধাপ ২)
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (sale.customer != null)
              Text(sale.customer!,
                  style: const TextStyle(fontWeight: FontWeight.w700)),
            Text(Money.taka(sale.total)),
            Text(
              [
                trackingStepLabels[sale.step] ?? sale.step,
                if (sale.billed) 'বিল হয়েছে'
              ].join(' · '),
              style: TextStyle(
                  color: _saleColour(sale), fontWeight: FontWeight.w600),
            ),
            const Divider(),
            // ⭐ টিকচিহ্নের অগ্রগতি-দাগ — হয়েছে ✓, এখন গোল-দাগ, ফেরত ✕, থামানো ■, সামনে ফাঁপা
            for (var i = 0; i < _milestones.length; i++)
              _MilestoneRow(
                  milestone: _milestones[i],
                  last: i == _milestones.length - 1,
                  when: when),
            if (_milestones.isNotEmpty) ...[
              const SizedBox(height: AppSpacing.sm),
              const Text('কে কখন কী করলেন',
                  style: TextStyle(fontWeight: FontWeight.w700)),
            ],
            if (_error != null)
              Text(_error!, style: const TextStyle(color: AppColors.danger)),
            if (_sale == null && _error == null)
              const LinearProgressIndicator(),
            for (final e in _events)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.circle, size: 10, color: _colour(e.step)),
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
                              if (e.at != null) when.format(e.at!),
                              if (e.by != null) e.by!
                            ].join(' · '),
                            style: const TextStyle(
                                fontSize: 12, color: AppColors.onSurfaceMuted),
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
      {required this.milestone, required this.last, required this.when});

  final Milestone milestone;
  final bool last;
  final DateFormat when;

  @override
  Widget build(BuildContext context) {
    final m = milestone;
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
                    color: filled ? colour : Colors.white,
                    border: Border.all(
                        color: lit ? colour : const Color(0xFFD1D5DB),
                        width: 2),
                  ),
                  child: Text(
                    switch (m.state) {
                      'done' => '✓',
                      'rejected' => '✕',
                      'hold' => '■',
                      _ => ''
                    },
                    style: const TextStyle(
                        color: Colors.white,
                        fontSize: 12,
                        fontWeight: FontWeight.w700),
                  ),
                ),
                if (!last)
                  Expanded(
                    child: Container(
                      width: 2,
                      color:
                          m.state == 'done' ? colour : const Color(0xFFE5E7EB),
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
                          : (lit ? null : AppColors.onSurfaceMuted),
                    ),
                  ),
                  if (m.at != null || m.by != null)
                    Text(
                      [
                        if (m.at != null) when.format(m.at!),
                        if (m.by != null) m.by!
                      ].join(' · '),
                      style: const TextStyle(
                          fontSize: 12, color: AppColors.onSurfaceMuted),
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
