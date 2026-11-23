import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/widgets/day_part.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/app_status_pill.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/paged_list.dart';
import '../../core/widgets/search_debouncer.dart';
import '../books/books_widgets.dart';
import 'sales_plan_api.dart';

/// ⭐ বিক্রয়ের পরিকল্পনার পাঁচটা পাতা, ফোনের নিজের ছাঁদে — লক্ষ্যমাত্রা, ডিলারের লক্ষ্য, রুটের খাতা (আর একটা রুট), স্কিম (আর একটা
/// স্কিম), কমিশন (মালিক, ১৭ অক্টোবর ২০২৬: *"100% in the app, in the app's own style, all Bangla, totals on every list"*)।
///
/// <p>প্রতিটা তালিকা একই ছাঁদে: উপরে বাছাই (মাস, খোঁজা, অবস্থা), তারপর **গোটা তালিকার** মোট (সার্ভারের, সব পাতার — এই পাতার যোগ
/// নয়), তারপর এক সারি এক কার্ডে, নিচে "আরও দেখুন"। খালি বা ভুলের পর্দাও টানলে আবার আনে।
/// ⓘ কেবল দেখা — বসানো, ছকে দেওয়া, মেটানো ওয়েবে; পর্দায় তা এক লাইনে লেখা।

TextStyle _muted(BuildContext context) => TextStyle(fontSize: 13, color: context.palette.onSurfaceMuted);
const _bold = TextStyle(fontWeight: FontWeight.w600);
const _amount = TextStyle(fontWeight: FontWeight.w700);

/// `DateTime(2026, 10)` → `2026-10` (সার্ভারের `?month=`)
String monthKey(DateTime month) => '${month.year}-${month.month.toString().padLeft(2, '0')}';

/// `DateTime(2026, 10)` → "অক্টোবর 2026" (ইংরেজিতে "October 2026") — [monthText]
String monthLabel(DateTime month) => monthText(month);

/// শতাংশ — "85.0%"; লক্ষ্য না থাকলে "—"
String percentOf(String? percent) => percent == null ? '—' : '$percent%';

/// টাকা বা "—" — লক্ষ্য না বসানো আর শূন্য লক্ষ্য আলাদা কথা
String takaOrDash(String? value) => value == null ? '—' : Money.taka(value);

/// ⭐ গোটা তালিকা এক পাতা করে — প্রথম পাতার উত্তরেই মোট আর গোনা, তাই মাথার পট্টি সেটা থেকেই আঁকা হয়।
class _PlanList extends StatefulWidget {
  const _PlanList({
    required this.load,
    required this.rowsKey,
    required this.item,
    required this.emptyTitle,
    this.controls = const [],
    this.summary,
    this.reloadKey = '',
  });

  final Future<PlanRow> Function(int page) load;
  final String rowsKey;
  final Widget Function(BuildContext context, PlanRow row) item;
  final String emptyTitle;

  /// বাছাইয়ের ঘর — ভুল বা খালি হলেও থাকে, যাতে খোঁজা মোছার পথ না হারায়
  final List<Widget> controls;

  /// প্রথম পাতার উত্তর থেকে মাথার মোট
  final List<Widget> Function(BuildContext context, PlanRow first)? summary;

  /// বদলালে প্রথম পাতা থেকে আবার আনে — বাছাই বদলের সময়
  final String reloadKey;

  @override
  State<_PlanList> createState() => _PlanListState();
}

class _PlanListState extends State<_PlanList> {
  PlanRow? _first;
  final List<PlanRow> _rows = [];
  int? _next;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant _PlanList old) {
    super.didUpdateWidget(old);
    if (old.reloadKey != widget.reloadKey) _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.load(more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) {
          _first = page;
          _rows.clear();
        }
        _rows.addAll(page.rows(widget.rowsKey));
        _next = page.nextPage;
      });
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.salesPlanLoadFailed, whenAbsent: context.l10n.salesPlanNotOnServer));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final first = _first;

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          ...widget.controls,
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) BooksError(_error!),
          if (first != null && _error == null) ...?widget.summary?.call(context, first),
          if (first != null && _rows.isEmpty && _error == null && !_busy)
            EmptyState(icon: Icons.inbox_outlined, title: widget.emptyTitle),
          for (final row in _rows) widget.item(context, row),
          if (_next != null) MoreButton(busy: _busy, onPressed: () => _load(more: true)),
          const SizedBox(height: AppSpacing.lg),
        ],
      ),
    );
  }
}

/// মাসের বাছাই — ‹ অক্টোবর 2026 ›
class MonthBar extends StatelessWidget {
  const MonthBar({super.key, required this.month, required this.onChanged});

  final DateTime month;
  final ValueChanged<DateTime> onChanged;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          IconButton(
            key: const ValueKey('month-previous'),
            tooltip: context.l10n.salesPlanPreviousMonth,
            icon: const Icon(Icons.chevron_left),
            onPressed: () => onChanged(DateTime(month.year, month.month - 1)),
          ),
          Expanded(
            child: Text(monthLabel(month),
                key: const ValueKey('month-label'), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
          IconButton(
            key: const ValueKey('month-next'),
            tooltip: context.l10n.salesPlanNextMonth,
            icon: const Icon(Icons.chevron_right),
            onPressed: () => onChanged(DateTime(month.year, month.month + 1)),
          ),
        ],
      );
}

/// খোঁজার ঘর — তালিকার খোঁজার নিয়মে ([SearchDebouncer]: ৩ অক্ষর + ৬০০ ms, Enter সাথে সাথে, খালি করলে সাথে সাথে)
class _Search extends StatefulWidget {
  const _Search({required this.hint, required this.onSearch, required this.fieldKey});

  final String hint;
  final ValueChanged<String> onSearch;
  final String fieldKey;

  @override
  State<_Search> createState() => _SearchState();
}

class _SearchState extends State<_Search> {
  final _controller = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (q) => widget.onSearch(q));

  @override
  void dispose() {
    _controller.dispose();
    _typed.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: TextField(
          key: ValueKey(widget.fieldKey),
          controller: _controller,
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: widget.hint),
          onChanged: _typed.onChanged,
          onSubmitted: _typed.onSubmitted,
        ),
      );
}

/// মোটের কার্ড — বড় সংখ্যাটা [TotalStrip], বাকিগুলো নিচে এক লাইনে একটা করে
List<Widget> _totals(String label, String value, int count, List<(String, String)> more) => [
      TotalStrip(label: label, value: value, count: count),
      if (more.isNotEmpty)
        Card(
          key: const ValueKey('plan-totals'),
          margin: const EdgeInsets.only(bottom: AppSpacing.sm),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: AppSpacing.xs),
            child: Column(children: [for (final (l, v) in more) FactRow(l, v)]),
          ),
        ),
    ];

Widget _note(BuildContext context, String text) => Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Text(text, style: _muted(context)),
    );

Widget _card({Key? key, required List<Widget> children, VoidCallback? onTap}) => Card(
      key: key,
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.sm),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: children),
        ),
      ),
    );

/// নাম · পয়েন্ট · শাখা — মালিকের নিয়ম, গ্রাহকের পাশে সবসময় পয়েন্ট (৭ অক্টোবর ২০২৬), আর শাখাও (১৯ অক্টোবর ২০২৬, স্থায়ী)।
/// ⓘ পুরনো সার্ভার শাখা পাঠায় না — তখন "নাম · পয়েন্ট" ([withPoint])।
String _withPoint(PlanRow r, [String nameKey = 'name']) =>
    withPoint(r.text(nameKey), r.maybe('point') ?? r.maybe('customer_point'), r.maybe('branch') ?? r.maybe('customer_branch'));

/// শতাংশের রং — ১০০ ছুঁলে সবুজ, অর্ধেকের নিচে লাল
Color _percentColour(BuildContext context, String? percent) {
  final value = double.tryParse(percent ?? '');
  if (value == null) return context.palette.onSurfaceMuted;
  if (value >= 100) return context.palette.success;
  if (value < 50) return context.palette.danger;
  return context.palette.warning;
}

// ───────────────────────────── লক্ষ্যমাত্রা ─────────────────────────────

/// ⭐ বিক্রয়কর্মীর মাসের লক্ষ্য আর অর্জন — ওয়েবের স্কোরবোর্ডের একই সারি; উপরে গোটা দলের মোট।
class SalesTargetsScreen extends StatefulWidget {
  const SalesTargetsScreen({super.key, this.api = const ServerSalesPlanApi(), this.today});

  final SalesPlanApi api;
  final DateTime? today;

  @override
  State<SalesTargetsScreen> createState() => _SalesTargetsScreenState();
}

class _SalesTargetsScreenState extends State<SalesTargetsScreen> {
  late DateTime _month;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _month = DateTime(now.year, now.month);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.salesPlanTargetsTitle),
        body: _PlanList(
          reloadKey: monthKey(_month),
          load: (page) => widget.api.targets(month: monthKey(_month), page: page),
          rowsKey: 'rows',
          emptyTitle: context.l10n.salesPlanTargetsEmpty,
          controls: [
            MonthBar(month: _month, onChanged: (m) => setState(() => _month = m)),
            _note(context, context.l10n.salesPlanTargetsNote),
          ],
          summary: (context, p) {
            final t = p.child('totals');
            return _totals(context.l10n.salesPlanTotalTarget, Money.taka(t.text('target')), p.number('count'), [
              (context.l10n.salesPlanTotalAchieved, Money.taka(t.text('achieved'))),
              (context.l10n.salesPlanTargetHoldersAchieved, percentOf(t.maybe('percent'))),
              (context.l10n.salesPlanTargetSet, context.l10n.salesPlanPeople(t.number('with_target'))),
            ]);
          },
          item: (context, r) => _card(key: ValueKey('target-${r.text('id')}'), children: [
            Row(children: [
              Expanded(child: Text(r.text('name'), style: _bold)),
              Text(percentOf(r.maybe('percent')),
                  style: TextStyle(fontWeight: FontWeight.w700, color: _percentColour(context, r.maybe('percent')))),
            ]),
            Text(context.l10n.salesPlanTargetAchieved(takaOrDash(r.maybe('target')), Money.taka(r.text('achieved'))), style: _muted(context)),
          ]),
        ),
      );
}

// ───────────────────────────── ডিলারের লক্ষ্য ─────────────────────────────

/// ⭐ ডিলারের মাসিক আদায়ের লক্ষ্য — লক্ষ্য, শেষ দিন, অর্জন (খাতায় আসা টাকা) আর বাকি; উপরে গোটা তালিকার মোট।
class DealerTargetsScreen extends StatefulWidget {
  const DealerTargetsScreen({super.key, this.api = const ServerSalesPlanApi(), this.today});

  final SalesPlanApi api;
  final DateTime? today;

  @override
  State<DealerTargetsScreen> createState() => _DealerTargetsScreenState();
}

class _DealerTargetsScreenState extends State<DealerTargetsScreen> {
  late DateTime _month;
  String _q = '';
  bool _onlySet = false;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _month = DateTime(now.year, now.month);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.salesPlanDealerTargetsTitle),
        body: _PlanList(
          reloadKey: '${monthKey(_month)}|$_q|$_onlySet',
          load: (page) => widget.api.dealerTargets(month: monthKey(_month), q: _q, onlySet: _onlySet, page: page),
          rowsKey: 'rows',
          emptyTitle: _onlySet ? context.l10n.salesPlanDealerTargetsNoneSet : context.l10n.salesPlanDealerTargetsEmpty,
          controls: [
            MonthBar(month: _month, onChanged: (m) => setState(() => _month = m)),
            _Search(fieldKey: 'dealer-target-search', hint: context.l10n.salesPlanDealerTargetsSearch, onSearch: (v) => setState(() => _q = v)),
            Wrap(spacing: AppSpacing.xs, children: [
              FilterChip(
                key: const ValueKey('dealer-target-only-set'),
                label: Text(context.l10n.salesPlanDealerTargetsOnlySet),
                selected: _onlySet,
                onSelected: (v) => setState(() => _onlySet = v),
              ),
            ]),
            _note(context, context.l10n.salesPlanDealerTargetsNote),
          ],
          summary: (context, p) {
            final t = p.child('totals');
            return _totals(context.l10n.salesPlanTotalTarget, Money.taka(t.text('target')), p.number('count'), [
              (context.l10n.salesPlanTotalAchieved, Money.taka(t.text('achieved'))),
              (context.l10n.salesPlanTotalDue, Money.taka(t.text('remaining'))),
              (context.l10n.salesPlanTargetSet, context.l10n.salesPlanPeople(t.number('with_target'))),
            ]);
          },
          item: (context, r) => _card(key: ValueKey('dealer-target-${r.text('code')}'), children: [
            Row(children: [
              Expanded(child: Text('${r.text('code')} · ${_withPoint(r)}', style: _bold)),
              Text(takaOrDash(r.maybe('target')), style: _amount),
            ]),
            Text(
              [
                context.l10n.salesPlanAchieved(Money.taka(r.text('achieved'))),
                if (r.maybe('remaining') != null) context.l10n.salesPlanDue(Money.taka(r.text('remaining'))),
                if (r.maybe('closes_on') != null) context.l10n.salesPlanEnds(dayOf(r.maybe('closes_on'))),
              ].join(' · '),
              style: _muted(context),
            ),
          ]),
        ),
      );
}

// ───────────────────────────── রুটের খাতা ─────────────────────────────

/// ⭐ সব রুট, এক মাসের অঙ্কসহ — ডিলার, কে যান, বিক্রি, আদায়, বাকি, লক্ষ্য; চাপলে রুটের পাতা।
class RouteBookScreen extends StatefulWidget {
  const RouteBookScreen({super.key, this.api = const ServerSalesPlanApi(), this.today});

  final SalesPlanApi api;
  final DateTime? today;

  @override
  State<RouteBookScreen> createState() => _RouteBookScreenState();
}

class _RouteBookScreenState extends State<RouteBookScreen> {
  late DateTime _month;
  String _q = '';

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _month = DateTime(now.year, now.month);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.salesPlanRouteBookTitle),
        body: _PlanList(
          reloadKey: '${monthKey(_month)}|$_q',
          load: (page) => widget.api.routes(month: monthKey(_month), q: _q, page: page),
          rowsKey: 'rows',
          emptyTitle: context.l10n.salesPlanRouteBookEmpty,
          controls: [
            MonthBar(month: _month, onChanged: (m) => setState(() => _month = m)),
            _Search(fieldKey: 'route-search', hint: context.l10n.salesPlanRouteBookSearch, onSearch: (v) => setState(() => _q = v)),
          ],
          summary: (context, p) {
            final t = p.child('totals');
            return [
              ..._totals(context.l10n.salesPlanTotalSales, Money.taka(t.text('sales')), p.number('count'), [
                (context.l10n.salesPlanTotalCollections, Money.taka(t.text('collections'))),
                (context.l10n.salesPlanTotalDue, Money.taka(t.text('outstanding'))),
                (context.l10n.salesPlanTotalTarget, Money.taka(t.text('target'))),
                (context.l10n.salesPlanTargetAchievement, percentOf(t.maybe('percent'))),
                (context.l10n.salesPlanDealers, context.l10n.salesPlanPeople(t.number('customers'))),
              ]),
              if (p.number('unrouted') > 0)
                Card(
                  key: const ValueKey('route-unrouted'),
                  color: context.palette.warningSurface,
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.sm),
                    child: Text(context.l10n.salesPlanUnrouted(p.number('unrouted')),
                        style: TextStyle(color: context.palette.warning)),
                  ),
                ),
            ];
          },
          item: (context, r) => _card(
            key: ValueKey('route-${r.text('id')}'),
            onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
              builder: (_) => RouteDetailScreen(id: r.text('id'), title: r.text('name'), month: _month, api: widget.api),
            )),
            children: [
              Row(children: [
                Expanded(child: Text('${r.text('code')} · ${r.text('name')}', style: _bold)),
                Icon(Icons.chevron_right, color: context.palette.onSurfaceMuted),
              ]),
              Text(
                [
                  if (r.maybe('point') != null) r.text('point'),
                  context.l10n.salesPlanDealerCount(r.number('customers')),
                  if (r.list('salespeople').isNotEmpty) r.list('salespeople').join(', '),
                ].join(' · '),
                style: _muted(context),
              ),
              Text(context.l10n.salesPlanSalesCollections(Money.taka(r.text('sales')), Money.taka(r.text('collections'))), style: _muted(context)),
              Row(children: [
                Expanded(child: Text(context.l10n.salesPlanDue(Money.taka(r.text('outstanding'))), style: _amount)),
                Text(context.l10n.salesPlanTargetPercent(takaOrDash(r.maybe('target')), percentOf(r.maybe('percent'))),
                    style: TextStyle(fontSize: 13, color: _percentColour(context, r.maybe('percent')))),
              ]),
            ],
          ),
        ),
      );
}

/// ⭐ একটা রুট — মাসের খাতা (আগের জের থেকে শেষ জের), লক্ষ্য, সাপ্তাহিক ছক আর ডিলার; ডিলারের তালিকার মোট = রুটের অঙ্ক।
class RouteDetailScreen extends StatelessWidget {
  const RouteDetailScreen({
    super.key,
    required this.id,
    required this.title,
    required this.month,
    this.api = const ServerSalesPlanApi(),
  });

  final String id;
  final String title;
  final DateTime month;
  final SalesPlanApi api;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: title),
        body: _PlanList(
          load: (page) => api.route(id, month: monthKey(month), page: page),
          rowsKey: 'customers',
          emptyTitle: context.l10n.salesPlanRouteEmpty,
          summary: (context, p) {
            final f = p.child('figures');
            final t = p.child('target');
            return [
              Text('${monthLabel(month)} · ${dayOf(p.maybe('from'))} – ${dayOf(p.maybe('to'))}', key: const ValueKey('route-period'), style: _muted(context)),
              const SizedBox(height: AppSpacing.xs),
              TotalStrip(label: context.l10n.salesPlanClosingBalance, value: Money.taka(f.text('outstanding')), count: p.number('customers_count')),
              Card(
                key: const ValueKey('route-figures'),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: AppSpacing.xs),
                  child: Column(children: [
                    FactRow(context.l10n.salesPlanOpeningBalance, Money.taka(f.text('opening'))),
                    FactRow(context.l10n.salesPlanSales, Money.taka(f.text('sales'))),
                    FactRow(context.l10n.salesPlanReturns, Money.taka(f.text('returns'))),
                    FactRow(context.l10n.salesPlanCollections, Money.taka(f.text('collections')), colour: context.palette.success),
                    FactRow(context.l10n.salesPlanOther, Money.taka(f.text('other'))),
                    FactRow(context.l10n.salesPlanTarget, takaOrDash(t.maybe('target'))),
                    FactRow(context.l10n.salesPlanTargetAchievement, '${Money.taka(t.text('achieved'))} · ${percentOf(t.maybe('percent'))}',
                        colour: _percentColour(context, t.maybe('percent'))),
                  ]),
                ),
              ),
              SectionTitle(context.l10n.salesPlanWeeklyPlan),
              if (p.rows('schedule').isEmpty) _note(context, context.l10n.salesPlanWeeklyPlanEmpty),
              for (final v in p.rows('schedule'))
                _card(key: ValueKey('visit-${v.text('who')}-${v.number('weekday')}-${v.text('from')}'), children: [
                  Row(children: [
                    Expanded(child: Text('${v.text('weekday_label')} · ${v.text('who')}', style: _bold)),
                    AppStatusPill(
                        label: v.flag('active') ? context.l10n.salesPlanVisitActive : context.l10n.salesPlanVisitEnded, kind: v.flag('active') ? AppStatusKind.success : AppStatusKind.neutral),
                  ]),
                  Text(context.l10n.salesPlanFromTo(dayOf(v.maybe('from')), v.maybe('to') == null ? context.l10n.salesPlanOngoing : dayOf(v.maybe('to'))), style: _muted(context)),
                ]),
              _note(context, context.l10n.salesPlanRouteNote),
              SectionTitle(context.l10n.salesPlanDealers),
            ];
          },
          item: (context, c) => _card(key: ValueKey('route-dealer-${c.text('id')}'), children: [
            Row(children: [
              Expanded(child: Text('${c.text('code')} · ${_withPoint(c)}', style: _bold)),
              Text(Money.taka(c.text('outstanding')), style: _amount),
            ]),
            Text(context.l10n.salesPlanSalesCollections(Money.taka(c.text('sales')), Money.taka(c.text('collections'))), style: _muted(context)),

            if (c.text('phone').isNotEmpty) Text(c.text('phone'), style: _muted(context)),
          ]),
        ),
      );
}

// ───────────────────────────── স্কিম ─────────────────────────────

AppStatusKind _schemeKind(String status) => switch (status) {
      'active' => AppStatusKind.success,
      'draft' => AppStatusKind.pending,
      'cancelled' => AppStatusKind.danger,
      _ => AppStatusKind.neutral,
    };

/// অবস্থার চিপ — "সব" আর সার্ভারের পাঠানো অবস্থা, প্রতিটার গোনাসহ
Widget _statusChips(BuildContext context, String prefix, List<PlanRow> statuses, String selected, ValueChanged<String> onSelected) => Padding(
      padding: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
        ChoiceChip(
          key: ValueKey('$prefix-status-all'),
          label: Text(context.l10n.salesPlanStatusAll),
          selected: selected.isEmpty,
          onSelected: (_) => onSelected(''),
        ),
        for (final s in statuses)
          ChoiceChip(
            key: ValueKey('$prefix-status-${s.text('status')}'),
            label: Text('${s.text('label')} · ${s.number('count')}'),
            selected: selected == s.text('status'),
            onSelected: (_) => onSelected(s.text('status')),
          ),
      ]),
    );

/// ⭐ স্কিম — কোন পণ্যে, কার জন্য, কবে পর্যন্ত; উপরে গোটা তালিকার গোনা আর অবস্থা ধরে গোনা; চাপলে ধাপগুলো।
class SchemeListScreen extends StatefulWidget {
  const SchemeListScreen({super.key, this.api = const ServerSalesPlanApi()});

  final SalesPlanApi api;

  @override
  State<SchemeListScreen> createState() => _SchemeListScreenState();
}

class _SchemeListScreenState extends State<SchemeListScreen> {
  String _q = '';
  String _status = '';

  /// ⓘ অবস্থার চিপ "সব"-এর উত্তর থেকে — একটা অবস্থা বাছলে বাকিগুলোর গোনা হারায় না
  List<PlanRow> _statuses = const [];

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.salesPlanSchemesTitle),
        body: _PlanList(
          reloadKey: '$_q|$_status',
          load: (page) async {
            final p = await widget.api.schemes(q: _q, status: _status, page: page);
            if (_status.isEmpty && page == 1 && mounted) setState(() => _statuses = p.rows('by_status'));
            return p;
          },
          rowsKey: 'rows',
          emptyTitle: context.l10n.salesPlanSchemesEmpty,
          controls: [
            _Search(fieldKey: 'scheme-search', hint: context.l10n.salesPlanSchemesSearch, onSearch: (v) => setState(() => _q = v)),
            if (_statuses.isNotEmpty) _statusChips(context, 'scheme', _statuses, _status, (s) => setState(() => _status = s)),
          ],
          summary: (context, p) => [
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: Text(context.l10n.salesPlanSchemesCount('${p.number('count')}'), key: const ValueKey('scheme-count'), style: _bold),
            ),
          ],
          item: (context, s) => _card(
            key: ValueKey('scheme-${s.text('id')}'),
            onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
              builder: (_) => SchemeDetailScreen(id: s.text('id'), title: s.text('name'), api: widget.api),
            )),
            children: [
              Row(children: [
                Expanded(child: Text('${s.text('code')} · ${s.text('name')}', style: _bold)),
                AppStatusPill(label: s.text('status_label'), kind: _schemeKind(s.text('status'))),
              ]),
              Text(context.l10n.salesPlanSchemeLine(s.text('basis_label'), s.text('applies_to_label'), s.number('rules')), style: _muted(context)),
              Text('${dayOf(s.maybe('valid_from'))} – ${dayOf(s.maybe('valid_to'))}${s.flag('live') ? context.l10n.salesPlanSchemeLive : ''}', style: _muted(context)),
            ],
          ),
        ),
      );
}

/// ⭐ একটা স্কিম — অবস্থা, ভিত্তি, কীসের উপর, মেয়াদ, আর ভূমিকা ধরে ধাপ (কত থেকে কত, হার বা থোক টাকা)।
class SchemeDetailScreen extends StatelessWidget {
  const SchemeDetailScreen({super.key, required this.id, required this.title, this.api = const ServerSalesPlanApi()});

  final String id;
  final String title;
  final SalesPlanApi api;

  @override
  Widget build(BuildContext context) => PaperPage<PlanRow>(
        title: title,
        load: () => api.scheme(id),
        failed: context.l10n.salesPlanLoadFailed,
        body: (context, s) => [
          Card(
            key: const ValueKey('scheme-facts'),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: AppSpacing.xs),
              child: Column(children: [
                FactRow(context.l10n.salesPlanSchemeCode, s.text('code')),
                FactRow(context.l10n.salesPlanSchemeStatus, s.text('status_label')),
                FactRow(context.l10n.salesPlanSchemeBasis, s.text('basis_label')),
                FactRow(context.l10n.salesPlanSchemeAppliesTo, [s.text('applies_to_label'), if (s.maybe('target') != null) s.text('target')].join(' · ')),
                FactRow(context.l10n.salesPlanSchemeValidity, '${dayOf(s.maybe('valid_from'))} – ${dayOf(s.maybe('valid_to'))}'),
                if (s.text('notes').isNotEmpty) FactRow(context.l10n.salesPlanSchemeNotes, s.text('notes')),
              ]),
            ),
          ),
          SectionTitle(context.l10n.salesPlanSchemeSteps(s.rows('rules').length)),
          if (s.rows('rules').isEmpty) EmptyState(icon: Icons.stairs_outlined, title: context.l10n.salesPlanSchemeNoSteps),
          for (final (i, r) in s.rows('rules').indexed)
            _card(key: ValueKey('scheme-rule-$i'), children: [
              Row(children: [
                Expanded(child: Text(context.l10n.salesPlanSchemeRoleLevel(r.text('role'), '${r.number('level')}'), style: _bold)),
                Text(r.maybe('fixed_amount') != null ? Money.taka(r.text('fixed_amount')) : percentOf(r.maybe('rate_percent')),
                    style: _amount),
              ]),
              Text(
                r.maybe('slab_to') == null
                    ? context.l10n.salesPlanSchemeFromUp(Money.taka(r.text('slab_from')))
                    : context.l10n.salesPlanFromTo(Money.taka(r.text('slab_from')), Money.taka(r.text('slab_to'))),
                style: _muted(context),
              ),
            ]),
          _note(context, context.l10n.salesPlanSchemeNote),
        ],
      );
}

// ───────────────────────────── কমিশন ─────────────────────────────

AppStatusKind _claimKind(String status) => switch (status) {
      'settled' => AppStatusKind.success,
      'rejected' || 'cancelled' => AppStatusKind.danger,
      _ => AppStatusKind.pending,
    };

/// ⭐ ডিলারের কমিশনের দাবি — উপরে কোম্পানির কাছে আটকে থাকা টাকা আর গোটা তালিকার সর্বমোট (ওয়েবের পট্টির হুবহু), অবস্থা ধরে।
class CommissionListScreen extends StatefulWidget {
  const CommissionListScreen({super.key, this.api = const ServerSalesPlanApi()});

  final SalesPlanApi api;

  @override
  State<CommissionListScreen> createState() => _CommissionListScreenState();
}

class _CommissionListScreenState extends State<CommissionListScreen> {
  String _q = '';
  String _status = '';
  List<PlanRow> _statuses = const [];

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.salesPlanCommissionTitle),
        body: _PlanList(
          reloadKey: '$_q|$_status',
          load: (page) async {
            final p = await widget.api.commissions(q: _q, status: _status, page: page);
            if (_status.isEmpty && page == 1 && mounted) setState(() => _statuses = p.rows('by_status'));
            return p;
          },
          rowsKey: 'rows',
          emptyTitle: context.l10n.salesPlanCommissionEmpty,
          controls: [
            _Search(fieldKey: 'commission-search', hint: context.l10n.salesPlanCommissionSearch, onSearch: (v) => setState(() => _q = v)),
            if (_statuses.isNotEmpty) _statusChips(context, 'commission',
 _statuses, _status, (s) => setState(() => _status = s)),
          ],
          summary: (context, p) {
            final t = p.child('totals');
            return [
              ..._totals(context.l10n.salesPlanCommissionGrandTotal, Money.taka(t.text('amount')), p.number('count'), [
                (context.l10n.salesPlanCommissionPending, Money.taka(t.text('pending'))),
                for (final s in p.rows('by_status'))
                  if (s.number('count') > 0) (context.l10n.salesPlanCommissionByStatus(s.text('label'), s.number('count')), Money.taka(s.text('amount'))),
              ]),
              // ⭐ মোটে কী আছে — ওয়েবের পাতার নিচের একই কথা (fe, ১৯ অক্টোবর ২০২৬; সার্ভারের CommissionClaim::UNCOUNTED)
              _note(context, context.l10n.salesPlanCommissionTotalNote),
              _note(context, context.l10n.salesPlanCommissionWebNote),
            ];
          },
          item: (context, c) => _card(key: ValueKey('commission-${c.text('id')}'), children: [
            Row(children: [
              Expanded(child: Text('${c.text('document_no')} · ${_withPoint(c, 'customer')}', style: _bold)),
              Text(Money.taka(c.text('amount')), style: _amount),
            ]),
            Text(
              [
                dayOf(c.maybe('date')),
                if (c.text('supplier').isNotEmpty) c.text('supplier'),
                if (c.maybe('invoice_no') != null) context.l10n.salesPlanCommissionInvoice(c.text('invoice_no')),
              ].join(' · '),
              style: _muted(context),
            ),
            Row(children: [
              Expanded(
                child: Text(
                  [
                    if (c.maybe('base_amount') != null) context.l10n.salesPlanCommissionBase(Money.taka(c.text('base_amount'))),
                    if (c.maybe('rate') != null) context.l10n.salesPlanCommissionRate(c.text('rate')),
                    if (c.maybe('rate') == null && c.maybe('rate_amount') != null) context.l10n.salesPlanCommissionLump(Money.taka(c.text('rate_amount'))),
                  ].join(' · '),
                  style: _muted(context),
                ),
              ),
              AppStatusPill(label: c.text('status_label'), kind: _claimKind(c.text('status'))),
            ]),
            if (c.text('decision_reason').isNotEmpty) Text(context.l10n.salesPlanCommissionReason(c.text('decision_reason')), style: _muted(context)),
            if (c.text('narration').isNotEmpty) Text(c.text('narration'), style: _muted(context)),
          ]),
        ),
      );
}
