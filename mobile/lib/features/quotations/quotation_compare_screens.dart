import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/orders/quotation_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/search_debouncer.dart';
import '../books/books_widgets.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ উদ্ধৃতির তুলনা আর সংস্করণ — ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
///
/// <p>⛔ হিসাব সার্ভারের ([[QuotationCompareApiController]] → ওয়েবের [[QuotationComparison]]): কোন ঘর ভিত্তি থেকে আলাদা,
/// কোন সারি নতুন বা নেই — সবই উত্তরে লেখা; ফোন কেবল আঁকে। প্রথম কলামটা ভিত্তি।

class CompareHead {
  const CompareHead({required this.id, required this.no, this.revisionNo = 0, this.customer, this.statusLabel = '', this.total = '0'});

  final String id;
  final String no;
  final int revisionNo;
  final String? customer;
  final String statusLabel;
  final String total;

  factory CompareHead.fromJson(Map<dynamic, dynamic> j) => CompareHead(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        revisionNo: (j['revision_no'] as num?)?.toInt() ?? 0,
        // ⭐ নাম · পয়েন্ট · শাখা — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম); পুরনো সার্ভারে নামটাই
        customer: withPointOrNull(j['customer']?.toString(), j['customer_point'], j['customer_branch']),
        statusLabel: j['status_label']?.toString() ?? '',
        total: j['total']?.toString() ?? '0',
      );
}

class CompareRow {
  const CompareRow(this.label, this.values, this.changed);

  final String label;
  final List<String?> values;
  final List<bool> changed;
}

class Comparison {
  const Comparison({required this.quotations, this.head = const [], this.lines = const []});

  final List<CompareHead> quotations;
  final List<CompareRow> head;

  /// প্রতিটা পণ্যের সারি — প্রতিটা উদ্ধৃতির জন্য "পরিমাণ × দর = অঙ্ক", নেই হলে null
  final List<CompareRow> lines;

  bool get compared => head.isNotEmpty;

  factory Comparison.fromJson(Map<dynamic, dynamic> j) {
    List<Map> maps(Object? raw) => raw is List ? raw.whereType<Map>().toList() : const [];
    final c = j['comparison'] is Map ? j['comparison'] as Map : null;
    return Comparison(
      quotations: [for (final q in maps(j['quotations'])) CompareHead.fromJson(q)],
      head: [
        for (final r in maps(c?['head']))
          CompareRow(r['label']?.toString() ?? '', [for (final v in (r['values'] as List? ?? const [])) v?.toString()],
              [for (final v in (r['changed'] as List? ?? const [])) v == true]),
      ],
      lines: [
        for (final r in maps(c?['lines']))
          CompareRow(
            r['label']?.toString() ?? '',
            [
              for (final cell in (r['cells'] as List? ?? const []))
                cell is Map && cell['fields'] is Map
                    ? '${cell['fields']['qty']}${cell['unit'] == null ? '' : ' ${cell['unit']}'} × ${cell['fields']['rate']} = ${cell['fields']['amount']}'
                    : null,
            ],
            [
              for (final cell in (r['cells'] as List? ?? const []))
                cell is Map &&
                    ((cell['added'] == true) || (cell['changed'] is Map && (cell['changed'] as Map).values.any((v) => v == true))),
            ],
          ),
      ],
    );
  }
}

class RevisionFamily {
  const RevisionFamily(this.root, this.latest, this.revisions);

  final CompareHead root;
  final CompareHead latest;
  final int revisions;
}

abstract class QuotationCompareApi {
  Future<Comparison> compare({List<String> ids = const [], String? family});
  Future<(List<RevisionFamily>, int?)> revisions({String q = '', int page = 1});
}

class ServerQuotationCompareApi implements QuotationCompareApi {
  const ServerQuotationCompareApi();

  @override
  Future<Comparison> compare({List<String> ids = const [], String? family}) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/sales/quotations/compare',
        queryParameters: {if (family != null) 'family': family, if (family == null) 'ids[]': ids});
    return Comparison.fromJson(r.data ?? const {});
  }

  @override
  Future<(List<RevisionFamily>, int?)> revisions({String q = '', int page = 1}) async {
    final r = await ApiClient.dio
        .get<Map<String, dynamic>>('/sales/quotations/revisions', queryParameters: {if (q.isNotEmpty) 'q': q, 'page': page});
    final body = r.data ?? const {};
    return (
      [
        for (final row in (body['rows'] is List ? body['rows'] as List : const []).whereType<Map>())
          RevisionFamily(CompareHead.fromJson(row), CompareHead.fromJson(row['latest'] is Map ? row['latest'] as Map : const {}),
              (row['revisions'] as num?)?.toInt() ?? 0),
      ],
      (body['next_page'] as num?)?.toInt(),
    );
  }
}

/// ⭐ তুলনা — উদ্ধৃতি বেছে (দুই থেকে ছয়টা) পাশাপাশি; বা একটা পরিবারের সব সংস্করণ
class QuotationCompareScreen extends StatefulWidget {
  const QuotationCompareScreen(
      {super.key, this.family, this.api = const ServerQuotationCompareApi(), this.quotations = const ServerQuotationApi()});

  /// একটা উদ্ধৃতির public_id — দিলে তার সব সংস্করণ, বাছাই ছাড়া
  final String? family;
  final QuotationCompareApi api;
  final QuotationApi quotations;

  static const int max = 6;

  @override
  State<QuotationCompareScreen> createState() => _QuotationCompareScreenState();
}

class _QuotationCompareScreenState extends State<QuotationCompareScreen> {
  List<Quotation>? _choices;
  final List<String> _picked = [];
  Comparison? _result;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    if (widget.family != null) {
      _compare();
    } else {
      _loadChoices();
    }
  }

  Future<void> _loadChoices() async {
    try {
      final (rows, _, _) = await widget.quotations.list();
      if (mounted) setState(() => _choices = rows);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.quotationsCompareListLoadFailed));
    }
  }

  Future<void> _compare() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await widget.api.compare(ids: _picked, family: widget.family);
      if (mounted) setState(() => _result = result);
    } catch (e) {
      if (mounted) {
        setState(() => _error =
            errorMessageFor(e, fallback: context.l10n.quotationsCompareLoadFailed, whenAbsent: context.l10n.quotationsCompareNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final result = _result;

    return Scaffold(
      appBar: AbosScreenBar(
        title: context.l10n.quotationsCompareTitle,
        actions: [
          if (result != null && widget.family == null)
            IconButton(
              key: const ValueKey('compare-again'),
              tooltip: context.l10n.quotationsCompareRepick,
              icon: const Icon(Icons.checklist),
              onPressed: () => setState(() => _result = null),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => widget.family != null || _result != null ? _compare() : _loadChoices(),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (result == null && widget.family == null) ..._picker(),
            if (result != null && !result.compared)
              EmptyState(icon: Icons.compare_arrows, title: context.l10n.quotationsCompareNeedTwoTitle, message: context.l10n.quotationsCompareNeedTwoMessage),
            if (result != null && result.compared) _table(result),
          ],
        ),
      ),
    );
  }

  List<Widget> _picker() {
    final choices = _choices;
    if (choices == null) return [if (_error == null) const LinearProgressIndicator()];
    if (choices.isEmpty) {
      return [EmptyState(icon: Icons.request_quote_outlined, title: context.l10n.quotationsCompareNoChoices, message: '')];
    }
    return [
      Text(context.l10n.quotationsComparePickHint('${QuotationCompareScreen.max}'),
          style: TextStyle(color: context.palette.onSurfaceMuted)),
      for (final q in choices)
        CheckboxListTile(
          key: ValueKey('compare-pick-${q.id}'),
          value: _picked.contains(q.id),
          title: Text(q.no),
          subtitle: Text([q.customer ?? '', q.statusLabel, Money.taka(q.total.toString())].where((s) => s.isNotEmpty).join(' · ')),
          onChanged: (on) => setState(() {
            if (on == true && _picked.length < QuotationCompareScreen.max) {
              _picked.add(q.id);
            } else {
              _picked.remove(q.id);
            }
          }),
        ),
      FilledButton.icon(
        key: const ValueKey('compare-go'),
        onPressed: _picked.length < 2 || _busy ? null : _compare,
        icon: const Icon(Icons.compare_arrows),
        label: Text(context.l10n.quotationsCompareGo(_picked.length)),
      ),
    ];
  }

  Widget _table(Comparison c) {
    Widget cell(String? text, {bool changed = false, bool bold = false}) => Container(
          width: 150,
          padding: const EdgeInsets.all(6),
          color: changed ? context.palette.pending.withValues(alpha: 0.18) : null,
          child: Text(text ?? '—', style: TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.w400)),
        );
    Widget row(String label, List<Widget> cells) => Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(
              width: 130,
              child:
                  Padding(padding: const EdgeInsets.all(6), child: Text(label, style: TextStyle(color: context.palette.onSurfaceMuted)))),
          ...cells,
        ]);

    return SingleChildScrollView(
      key: const ValueKey('compare-table'),
      scrollDirection: Axis.horizontal,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        row('', [for (final q in c.quotations) cell('${q.no}${q.revisionNo > 0 ? context.l10n.quotationsCompareRevision('${q.revisionNo}') : ''}', bold: true)]),
        for (final r in c.head)
          row(r.label, [for (final (i, v) in r.values.indexed) cell(v, changed: r.changed.elementAtOrNull(i) ?? false)]),
        const Divider(),
        for (final r in c.lines)
          row(r.label, [for (final (i, v) in r.values.indexed) cell(v, changed: r.changed.elementAtOrNull(i) ?? false)]),
      ]),
    );
  }
}

/// ⭐ সংস্করণ — যেসব উদ্ধৃতির সংস্করণ হয়েছে, প্রতিটার সর্বশেষটা; চাপলে পুরো পরিবারের তুলনা
class QuotationRevisionsScreen extends StatefulWidget {
  const QuotationRevisionsScreen({super.key, this.api = const ServerQuotationCompareApi()});

  final QuotationCompareApi api;

  @override
  State<QuotationRevisionsScreen> createState() => _QuotationRevisionsScreenState();
}

class _QuotationRevisionsScreenState extends State<QuotationRevisionsScreen> {
  final _search = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (_) => _load());
  final List<RevisionFamily> _rows = [];
  int? _next;
  bool _loaded = false;
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

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (rows, next) = await widget.api.revisions(q: _search.text.trim(), page: more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(rows);
        _next = next;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.quotationsRevisionsLoadFailed, whenAbsent: context.l10n.quotationsRevisionsNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.quotationsRevisionsTitle),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            TextField(
              key: const ValueKey('revisions-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: context.l10n.quotationsRevisionsSearch, isDense: true),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
            const SizedBox(height: AppSpacing.sm),
            if (_busy && !_loaded) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (_loaded && _rows.isEmpty)
              EmptyState(icon: Icons.history, title: context.l10n.quotationsRevisionsEmptyTitle, message: context.l10n.quotationsRevisionsEmptyMessage),
            for (final f in _rows)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  key: ValueKey('revision-${f.root.id}'),
                  title: Text('${f.root.no} → ${f.latest.no}', style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(
                      [f.root.customer ?? '', context.l10n.quotationsRevisionsCount(f.revisions), f.latest.statusLabel].where((s) => s.isNotEmpty).join(' · ')),
                  trailing: Text(Money.taka(f.latest.total), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => Navigator.of(context)
                      .push(MaterialPageRoute<void>(builder: (_) => QuotationCompareScreen(family: f.root.id, api: widget.api))),
                ),
              ),
            if (_next != null)
              Center(
                  child: TextButton.icon(
                      onPressed: _busy ? null : () => _load(more: true), icon: const Icon(Icons.expand_more), label: Text(context.l10n.quotationsRevisionsMore))),

          ],
        ),
      ),
    );
  }
}
