import 'package:flutter/material.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/orders/sales_desk_api.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/number_with_party.dart';
import '../../core/widgets/search_debouncer.dart';
import '../books/books_widgets.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ বিক্রয়ের ডেস্ক-পাতা আর দলিলের ছাঁচ, ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*)।
///
/// <p>প্রতিটা পর্দা একই ছাঁদে: উপরে বাছাই, তারপর মোট বা কার্ড, তারপর এক সারি এক কার্ডে; খালি বা ভুলের পর্দাও টানলে আবার আনে।

/// একটা পাতা আনা আর আঁকা — সব ডেস্ক-পর্দার একই চাকা
class _DeskPage extends StatefulWidget {
  const _DeskPage({
    required this.title,
    required this.load,
    required this.body,
    this.header,
    this.isEmpty,
    this.emptyTitle,
    this.reloadKey = '',
  });

  final String title;
  final Future<DeskRow> Function() load;
  final List<Widget> Function(BuildContext context, DeskRow page, Future<void> Function() reload) body;
  final List<Widget> Function(BuildContext context, Future<void> Function() reload)? header;
  final bool Function(DeskRow page)? isEmpty;
  final String? emptyTitle;

  /// বদলালে আবার আনে — বাছাই বদলের সময়
  final String reloadKey;

  @override
  State<_DeskPage> createState() => _DeskPageState();
}

class _DeskPageState extends State<_DeskPage> {
  DeskRow? _page;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant _DeskPage old) {
    super.didUpdateWidget(old);
    if (old.reloadKey != widget.reloadKey) _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.load();
      if (mounted) setState(() => _page = page);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.salesDeskLoadFailed, whenAbsent: context.l10n.salesDeskNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _page;

    return Scaffold(
      appBar: AbosScreenBar(title: widget.title),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            ...?widget.header?.call(context, _load),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: context.l10n.salesDeskErrorTitle, message: _error)
            else if (p != null && (widget.isEmpty?.call(p) ?? false) && !_busy)
              EmptyState(icon: Icons.inbox_outlined, title: widget.emptyTitle ?? context.l10n.salesDeskNothing)
            else if (p != null)
              ...widget.body(context, p, _load),
            const SizedBox(height: AppSpacing.lg),
          ],
        ),
      ),
    );
  }
}

Widget _card({Key? key, required List<Widget> children}) => Card(
      key: key,
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.sm),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: children),
      ),
    );

TextStyle _muted(BuildContext context) => TextStyle(fontSize: 13, color: context.palette.onSurfaceMuted);
const _bold = TextStyle(fontWeight: FontWeight.w600);

/// ⭐ ডিপোর যাচাই — হিসাবের অনুমোদন পাওয়া DO আর ধরে রাখা বিক্রয় আদেশ, উপরে DO-র সর্বমোট।
/// ⓘ "যাচাই করে বিক্রয়ে খুলুন" এখনো ওয়েবের কাউন্টারে — ফোনের কাউন্টার এখনো DO থেকে খোলে না, তাই পর্দায় তা স্পষ্ট লেখা।
class DepotCheckScreen extends StatefulWidget {
  const DepotCheckScreen({super.key, this.api = const ServerSalesDeskApi()});

  final SalesDeskApi api;

  @override
  State<DepotCheckScreen> createState() => _DepotCheckScreenState();
}

class _DepotCheckScreenState extends State<DepotCheckScreen> {
  final _search = TextEditingController();
  String _q = '';
  late final _typed = SearchDebouncer(onSearch: (q) => setState(() => _q = q));

  @override
  void dispose() {
    _search.dispose();
    _typed.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => _DeskPage(
        title: context.l10n.salesDeskDepotTitle,
        reloadKey: _q,
        load: () => widget.api.depotCheck(q: _q),
        isEmpty: (p) => p.rows('orders').isEmpty && p.rows('held').isEmpty,
        emptyTitle: context.l10n.salesDeskDepotEmpty,
        header: (context, reload) => [
          TextField(
            key: const ValueKey('depot-search'),
            controller: _search,
            textInputAction: TextInputAction.search,
            decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: context.l10n.salesDeskDepotSearch),
            onChanged: _typed.onChanged,
            onSubmitted: _typed.onSubmitted,
          ),
          const SizedBox(height: AppSpacing.sm),
        ],
        body: (context, p, reload) => [
          TotalStrip(label: context.l10n.salesDeskDepotTotal, value: Money.taka(p.text('orders_total')), count: p.number('orders_count')),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
            child: Text(context.l10n.salesDeskDepotWebNote, key: const ValueKey('depot-web-note'), style: _muted(context)),
          ),
          for (final o in p.rows('orders'))
            _card(key: ValueKey('depot-${o.text('id')}'), children: [
              Row(children: [
                Expanded(child: NumberWithParty(number: o.text('document_no'), party: _customerOf(o), style: _bold)),
                Text(Money.taka(o.text('total')), style: const TextStyle(fontWeight: FontWeight.w700)),
              ]),
              Text(context.l10n.salesDeskDepotLine(o.text('date'), o.number('lines'), o.text('status')), style: _muted(context)),
              for (final w in o.list('warnings')) Text('⚠ $w', style: TextStyle(fontSize: 13, color: context.palette.warning)),
            ]),
          if (p.rows('held').isNotEmpty) ...[
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.sm, bottom: AppSpacing.xs),
              child: Text(context.l10n.salesDeskDepotHeld, style: const TextStyle(fontWeight: FontWeight.w700)),
            ),
            for (final o in p.rows('held'))
              _card(children: [
                Row(children: [
                  Expanded(child: NumberWithParty(number: o.text('document_no'), party: _customerOf(o), style: _bold)),
                  Text(Money.taka(o.text('total')), style: const TextStyle(fontWeight: FontWeight.w700)),
                ]),
                Text(context.l10n.salesDeskDepotLine(o.text('date'), o.number('lines'), o.text('status')), style: _muted(context)),
              ]),
          ],
        ],
      );
}

/// ⭐ না ছাপা কাগজ — অপেক্ষায় আর ব্যর্থ, কারণসহ; "হাতে ছাপা হলো" চাপলে সারি থেকে সরে।
class PrintQueueScreen extends StatelessWidget {
  const PrintQueueScreen({super.key, this.api = const ServerSalesDeskApi()});

  final SalesDeskApi api;

  @override
  Widget build(BuildContext context) => _DeskPage(
        title: context.l10n.salesDeskPrintTitle,
        load: () => api.printQueue(),
        isEmpty: (p) => p.rows('jobs').isEmpty,
        emptyTitle: context.l10n.salesDeskPrintEmpty,
        body: (context, p, reload) => [
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: Text(context.l10n.salesDeskTotalCount('${p.number('count')}'), key: const ValueKey('print-count'), style: _bold),
          ),
          for (final j in p.rows('jobs'))
            _card(key: ValueKey('print-${j.text('id')}'), children: [
              Row(children: [
                Expanded(child: Text(j.text('document_no').isEmpty ? '—' : j.text('document_no'), style: _bold)),
                Text(j.text('status_label'),
                    style: TextStyle(color: j.flag('failed') ? context.palette.danger : context.palette.warning, fontWeight: FontWeight.w600)),
              ]),
              if (j.text('paper').isNotEmpty) Text(context.l10n.salesDeskPrintPaper(j.text('paper')), style: _muted(context)),
              if (j.text('failure').isNotEmpty) Text(context.l10n.salesDeskPrintReason(j.text('failure')), style: TextStyle(color: context.palette.danger)),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  key: ValueKey('print-settle-${j.text('id')}'),
                  onPressed: () async {
                    try {
                      await api.settlePrint(j.text('id'));
                      if (context.mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.l10n.salesDeskPrintSettled)));
                      }
                      await reload();
                    } catch (e) {
                      if (context.mounted) {
                        ScaffoldMessenger.of(context)
                            .showSnackBar(SnackBar(content: Text(errorMessageFor(e, fallback: context.l10n.salesDeskPrintSettleFailed))));
                      }
                    }
                  },
                  child: Text(context.l10n.salesDeskPrintSettle),
                ),
              ),
            ]),
        ],
      );
}

/// ⭐ ডেলিভারির কাজের হিসাব — সময়মতো ও পুরো (OTIF), সময়মতো, পুরো, গড় সময়, দেরি; নিচে দেরির চালান।
class DeliveryPerformanceScreen extends StatefulWidget {
  const DeliveryPerformanceScreen({super.key, this.api = const ServerSalesDeskApi(), this.today});

  final SalesDeskApi api;
  final DateTime? today;

  @override
  State<DeliveryPerformanceScreen> createState() => _DeliveryPerformanceScreenState();
}

class _DeliveryPerformanceScreenState extends State<DeliveryPerformanceScreen> {
  late DateTime _from;
  late DateTime _to;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _to = DateTime(now.year, now.month, now.day);
    _from = DateTime(now.year, now.month);
  }

  @override
  Widget build(BuildContext context) => _DeskPage(
        title: context.l10n.salesDeskDeliveryTitle,
        reloadKey: '${isoOf(_from)}..${isoOf(_to)}',
        load: () => widget.api.deliveryPerformance(from: isoOf(_from), to: isoOf(_to)),
        header: (context, reload) => [
          DateRangeBar(
            from: _from,
            to: _to,
            today: widget.today,
            onChanged: (from, to) => setState(() {
              _from = from;
              _to = to;
            }),
          ),
          const SizedBox(height: AppSpacing.sm),
        ],
        body: (context, p, reload) => [
          for (final c in p.rows('cards'))
            _card(children: [
              Text(c.text('label'), style: _muted(context)),
              Text(c.text('value'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              if (c.text('hint').isNotEmpty) Text(c.text('hint'), style: const TextStyle(fontSize: 12)),
            ]),
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.sm, bottom: AppSpacing.xs),
            child: Text(context.l10n.salesDeskDeliveryLate('${p.number('late_count')}'),
                key: const ValueKey('late-count'), style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
          for (final d in p.rows('late'))
            _card(key: ValueKey('late-${d.text('id')}'), children: [
              Row(children: [
                Expanded(child: NumberWithParty(number: d.text('document_no'), party: _customerOf(d), style: _bold)),
                Text(context.l10n.salesDeskDeliveryDaysLate('${d.number('days_late')}'), style: TextStyle(color: context.palette.danger, fontWeight: FontWeight.w600)),
              ]),
              Text(context.l10n.salesDeskDeliveryPromised(d.text('promised_on'), d.text('arrived_on')), style: _muted(context)),
              Text([if (d.text('order_no').isNotEmpty) context.l10n.salesDeskDeliveryOrder(d.text('order_no')), d.text('stage')].join(' · '), style: _muted(context)),
            ]),
        ],
      );
}

/// ⭐ সম্ভাবনার পাইপলাইন — ধাপ ধরে, প্রতিটা ধাপের গোনা, মোট আর ওজন-করা মোট; উপরে চলমান ধাপগুলোর ওজন-করা মোট।
class PipelineScreen extends StatelessWidget {
  const PipelineScreen({super.key, this.api = const ServerSalesDeskApi()});

  final SalesDeskApi api;

  @override
  Widget build(BuildContext context) => _DeskPage(
        title: context.l10n.salesDeskPipelineTitle,
        load: api.pipeline,
        isEmpty: (p) => p.rows('stages').every((s) => s.number('count') == 0),
        emptyTitle: context.l10n.salesDeskPipelineEmpty,
        body: (context, p, reload) => [
          TotalStrip(label: context.l10n.salesDeskPipelineOpen, value: Money.taka(p.text('open_weighted'))),
          for (final s in p.rows('stages'))
            Card(
              key: ValueKey('stage-${s.text('name')}'),
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: ExpansionTile(
                initiallyExpanded: s.number('count') > 0,
                title: Text(context.l10n.salesDeskPipelineStage(s.text('name'), '${s.number('count')}'), style: _bold),
                subtitle: Text(context.l10n.salesDeskPipelineTotals(Money.taka(s.text('total')), Money.taka(s.text('weighted'))), style: _muted(context)),
                children: [
                  for (final o in s.rows('rows'))
                    ListTile(
                      dense: true,
                      title: Text(o.text('title')),
                      subtitle: Text([
                        o.text('party'),
                        Money.taka(o.text('value')),
                        '${o.number('probability')}%',
                        if (o.text('close_on').isNotEmpty) context.l10n.salesDeskPipelineCloses(o.text('close_on')),
                        if (o.text('salesperson').isNotEmpty) o.text('salesperson'),
                      ].join(' · ')),
                    ),
                ],
              ),
            ),
        ],
      );
}

/// ⭐ দলিলের ছাঁচ — তালিকা; চাপলে ছাঁচের লেখা। ⓘ লেখা ও বদল ওয়েবের ডেস্কে।
class TemplateListScreen extends StatelessWidget {
  const TemplateListScreen({super.key, this.api = const ServerSalesDeskApi()});

  final SalesDeskApi api;

  @override
  Widget build(BuildContext context) => _DeskPage(
        title: context.l10n.salesDeskTemplatesTitle,
        load: () => api.templates(),
        isEmpty: (p) => p.rows('rows').isEmpty,
        emptyTitle: context.l10n.salesDeskTemplatesEmpty,
        body: (context, p, reload) => [
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: Text(context.l10n.salesDeskTemplatesCount('${p.number('count')}'), key: const ValueKey('template-count'), style: _muted(context)),
          ),
          for (final t in p.rows('rows'))
            Card(
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: ListTile(
                key: ValueKey('template-${t.text('id')}'),
                title: Text(t.text('title'), style: _bold),
                subtitle: Text([t.text('code'), t.text('type'), t.text('folder'), if (!t.flag('active')) context.l10n.salesDeskTemplatesInactive
].join(' · ')),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
                  builder: (_) => TemplateScreen(id: t.text('id'), title: t.text('title'), api: api),
                )),
              ),
            ),
        ],
      );
}

class TemplateScreen extends StatelessWidget {
  const TemplateScreen({super.key, required this.id, required this.title, this.api = const ServerSalesDeskApi()});

  final String id;
  final String title;
  final SalesDeskApi api;

  @override
  Widget build(BuildContext context) => _DeskPage(
        title: title,
        load: () => api.template(id),
        body: (context, p, reload) => [
          Text([p.text('code'), p.text('type'), p.text('folder')].join(' · '), style: _muted(context)),
          const SizedBox(height: AppSpacing.sm),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: SelectableText(p.text('body'), key: const ValueKey('template-body')),
            ),
          ),
        ],
      );
}

/// ⭐ নম্বরের পাশে "নাম · পয়েন্ট · শাখা" — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম: *"গ্রাহকের নামের পাশে পয়েন্ট দিতে হবে অবশ্যই, সব
/// জায়গায়, আর শাখার নামও দিতে হবে"*)। ⓘ পুরনো সার্ভার পয়েন্ট-শাখা পাঠায় না — তখন নামটাই, আগের মতো।
String _customerOf(DeskRow r) => withPoint(r.text('customer'), r.payload['customer_point'], r.payload['customer_branch']);
