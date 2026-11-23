import 'package:flutter/material.dart';
import '../../core/widgets/totals_bar.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/auth/session_profile.dart';
import '../../core/locale/l10n.dart';
import '../../core/menu/me_api.dart';
import '../../core/navigation/paper_kinds.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/search_debouncer.dart';
import '../../core/widgets/shown_rows.dart';
import '../books/books_widgets.dart';
import '../printing/document_actions_sheet.dart';

/// ⭐ ক্রয়, বিক্রয়ের বাকি কাগজ আর স্থানান্তর — ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e
/// zate bujazay sei vabe koro, direct webpage app e bujajayna"*; fe-র ধাপ ৪)।
///
/// <p>সতেরো রকম কাগজ, পর্দা দুইটা: তালিকা ([PaperListScreen]) — উপরে তারিখ, শাখা, অবস্থা আর খোঁজা, তারপর সর্বমোট আর এই পাতার মোট;
/// আর পাতা ([PaperScreen]) — ঘর, সারি, যোগফল, সই আর ছাপা। ⛔ সংখ্যা ফোনে কষা হয় না, ছাঁকনিও নয়: সার্ভার
/// (`GET /papers/{key}`, [[PhonePaperApiController]]) ওয়েবের তালিকার দেয়াল আর চাবি মেনে যা পাঠায়, পর্দা কেবল তাই আঁকে।
/// ⓘ কেবল দেখা — লেখার ফর্ম এখন নয় (fe, ১৭ অক্টোবর ২০২৬)।
class PaperListScreen extends StatefulWidget {
  const PaperListScreen({
    super.key,
    required this.paperKey,
    this.title,
    this.api = const ServerPaperApi(),
    this.branches = _myBranches,
    this.today,
  });

  /// ⓘ নাগালের শাখা — `/me` থেকে; না পেলে শাখার ছাঁকনিই দেখায় না
  static Future<List<OrgRef>> _myBranches() async {
    try {
      return (await MeApi.fetch()).profile.branches;
    } catch (_) {
      return const [];
    }
  }

  final String paperKey;

  /// মেনুর নিজের নাম — না দিলে কাগজের বাংলা নাম
  final String? title;
  final PaperApi api;
  final Future<List<OrgRef>> Function() branches;

  /// পরীক্ষার জন্য — না দিলে আজ
  final DateTime? today;

  @override
  State<PaperListScreen> createState() => _PaperListScreenState();
}

class _PaperListScreenState extends State<PaperListScreen> {
  late DateTime _from;
  late DateTime _to;
  final _search = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (_) => _load());
  final List<PaperRow> _rows = [];
  PaperPage? _page;
  List<OrgRef> _branches = const [];
  OrgRef? _branch;
  String? _status;
  bool _busy = false;
  String? _error;

  String get _title {
    final given = widget.title?.trim() ?? '';
    return given.isNotEmpty ? given : PaperKinds.titleOf(widget.paperKey);
  }

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _to = DateTime(now.year, now.month, now.day);
    _from = DateTime(now.year, now.month);
    widget.branches().then((list) {
      if (mounted) setState(() => _branches = list);
    });
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
      final dated = _page?.filters.dated ?? true;
      final page = await widget.api.list(
        widget.paperKey,
        from: dated ? isoOf(_from) : null,
        to: dated ? isoOf(_to) : null,
        q: _search.text.trim(),
        status: _status,
        branch: _branch?.publicId,
        page: more ? (_page?.nextPage ?? 1) : 1,
      );
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(page.rows);
        _page = page;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.papersListFailed(_title),
            whenAbsent: context.l10n.papersListAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    final filters = page?.filters;
    final w = context.l10n;
    final shown = ShownRows.of(_rows, (r) => r.total);

    return Scaffold(
      appBar: AbosScreenBar(title: _title),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (filters?.dated ?? true)
              DateRangeBar(
                from: _from,
                to: _to,
                today: widget.today,
                onChanged: (from, to) {
                  setState(() {
                    _from = from;
                    _to = to;
                  });
                  _load();
                },
              ),
            // ⓘ শাখা — কাগজটা শাখার হলে, আর নাগালে একাধিক থাকলে তবেই
            if ((filters?.branches ?? false) && _branches.length > 1)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.xs),
                child: Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
                  ChoiceChip(
                      key: const ValueKey('paper-branch-all'),
                      label: Text(w.papersAllBranches),
                      selected: _branch == null,
                      onSelected: (_) {
                        setState(() => _branch = null);
                        _load();
                      }),
                  for (final b in _branches)
                    ChoiceChip(
                        key: ValueKey('paper-branch-${b.publicId}'),
                        label: Text(b.name),
                        selected: _branch?.publicId == b.publicId,
                        onSelected: (_) {
                          setState(() => _branch = b);
                          _load();
                        }),
                ]),
              ),
            // ⓘ অবস্থা — সার্ভারের বলা চিপগুলোই; "সব" মানে ওয়েবের তালিকার নিজের ডিফল্ট
            if (filters != null && filters.statuses.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.xs),
                child: Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
                  ChoiceChip(
                      key: const ValueKey('paper-status-all'),
                      label: Text(w.papersAll),
                      selected: _status == null,
                      onSelected: (_) {
                        setState(() => _status = null);
                        _load();
                      }),
                  for (final s in filters.statuses)
                    ChoiceChip(
                        key: ValueKey('paper-status-${s.code}'),
                        label: Text(s.label),
                        selected: _status == s.code,
                        onSelected: (_) {
                          setState(() => _status = s.code);
                          _load();
                        }),
                ]),
              ),
            if (filters?.searchable ?? true)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: TextField(
                  key: const ValueKey('paper-search'),
                  controller: _search,
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: w.papersSearchHint, isDense: true),
                  onChanged: _typed.onChanged,
                  onSubmitted: _typed.onSubmitted,
                ),
              ),
            if (_busy && page == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            // ⭐ সর্বমোট — ছাঁকা তালিকার সব পাতা মিলে, সার্ভারের গোনা; নিচে এই পাতার মোট = দেখানো সব সারি ([ShownRows])।
            // ⛔ আগে সার্ভারের `page_sum` — কেবল শেষ খেপের সারি: "আরও দেখুন"-এর পর ৪২টা সারি দেখিয়ে শেষ ১২টার যোগ বলত।
            // ⓘ সার্ভার `page_sum` পাঠায় কেবল টাকা দেখার চাবিতে — তখনই লাইনটা, আর যোগ সারির নিজের টাকা থেকে
            if (page != null && page.sum != null && _rows.isNotEmpty) ...[
              TotalStrip(
                  label: w.papersGrandTotal,
                  value: Money.taka(page.sum),
                  count: page.count,
                  // ⓘ তারিখের কাগজে বাছা পরিসর, তারিখহীন কাগজে সব তারিখ — সার্ভার যেমন ছেঁকেছে
                  period: page.filters.dated ? TotalsPeriod.range(_from, _to, today: widget.today) : const TotalsPeriod.allDates()),
              if (page.pageSum != null)
                Padding(
                  key: const ValueKey('paper-page-sum'),
                  padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: FactRow(w.papersPageTotal(shown.count), Money.taka(shown.sum)),
                ),
            ],
            if (page != null && page.sum == null && _rows.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Text(w.papersCount(page.count), style: TextStyle(color: context.palette.onSurfaceMuted)),
              ),
            if (page != null && _rows.isEmpty)
              EmptyState(
                icon: Icons.description_outlined,
                title: w.papersNone(_title),
                message: (filters?.dated ?? true) ? w.papersNoneDated : w.papersNoneUndated,
              ),
            for (final r in _rows)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  key: ValueKey('paper-${r.id}'),
                  title: Text(r.no, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text([
                    if (r.party != null && r.party!.isNotEmpty) r.party!,
                    if (r.note != null && r.note!.isNotEmpty) r.note!,
                    [r.dateLabel ?? '', r.statusLabel, r.branch ?? ''].where((s) => s.isNotEmpty).join(' · '),
                  ].join('\n')),
                  isThreeLine: true,
                  trailing: r.total == null
                      ? const Icon(Icons.chevron_right)
                      : Text(Money.taka(r.total), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/home/${PaperKinds.pathOf(widget.paperKey, r.id)}'),
                ),
              ),
            if (page?.nextPage != null)
              Center(
                child: TextButton.icon(
                  key: const ValueKey('paper-more'),
                  onPressed: _busy ? null : () => _load(more: true),
                  icon: const Icon(Icons.expand_more),
                  label: Text(w.papersMore),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// একটা কাগজ — ঘর, সারি, যোগফল, সই, ছাপা
class PaperScreen extends StatefulWidget {
  const PaperScreen({super.key, required this.paperKey, required this.id, this.api = const ServerPaperApi()});

  final String paperKey;
  final String id;
  final PaperApi api;

  @override
  State<PaperScreen> createState() => _PaperScreenState();
}

class _PaperScreenState extends State<PaperScreen> {
  PaperDetail? _paper;
  String? _error;

  String get _title => PaperKinds.titleOf(widget.paperKey);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final paper = await widget.api.one(widget.paperKey, widget.id);
      if (!mounted) return;
      setState(() {
        _paper = paper;
        _error = null;
      });
      // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ
      RecentPapers.note(kind: widget.paperKey, id: widget.id, number: paper.row.no, party: paper.row.party);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.papersOneFailed(_title)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _paper;
    final w = context.l10n;

    return Scaffold(
      appBar: AbosScreenBar(
        title: p == null ? _title : '$_title · ${p.row.no}',
        actions: [
          if (p != null && p.printType != null)
            IconButton(
              key: const ValueKey('paper-print'),
              tooltip: w.papersPrint,
              icon: const Icon(Icons.print_outlined),
              onPressed: () => DocumentActionsSheet.show(context,
                  type: p.printType!, id: p.row.id, title: '$_title · ${p.row.no}', fileStem: p.row.no),
            ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (p == null && _error == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (p != null) ...[
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (p.row.party != null && p.row.party!.isNotEmpty)
                        Text(p.row.party!, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                      const SizedBox(height: AppSpacing.xs),
                      if (p.row.statusLabel.isNotEmpty) FactRow(w.papersStatus, p.row.statusLabel),
                      if (p.row.branch != null && p.row.branch!.isNotEmpty) FactRow(w.papersBranch, p.row.branch!),
                      for (final f in p.facts) FactRow(f.label, f.value),
                    ],
                  ),
                ),
              ),
              if (p.rows.isNotEmpty) ...[
                Padding(
                  padding: const EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
                  child: Text(w.papersLines, style: TextStyle(fontWeight: FontWeight.w700, color: context.palette.onSurfaceMuted)),
                ),
                for (final (i, row) in p.rows.indexed) _line(p, row, i),
              ],
              if (p.totals.isNotEmpty)
                Card(
                  key: const ValueKey('paper-totals'),
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(children: [
                      for (final t in p.totals.entries) FactRow(w.papersTotalOf(p.labelOf(t.key)).trim(), t.value),
                    ]),
                  ),
                ),
              if (p.signatures.isNotEmpty) ...[
                Padding(
                  padding: const EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
                  child: Text(w.papersSignatures, style: TextStyle(fontWeight: FontWeight.w700, color: context.palette.onSurfaceMuted)),
                ),
                for (final s in p.signatures) _signature(context, s),
              ],
            ],
          ],
        ),
      ),
    );
  }

  /// একটা সারি — প্রথম ঘর নাম, বাকিগুলো "ঘর মান"; শেষের সংখ্যার ঘর ডানে
  Widget _line(PaperDetail p, Map<String, String?> row, int index) {
    final columns = p.columns;
    if (columns.isEmpty) return const SizedBox.shrink();
    final head = columns.first;
    final tail = columns.length > 1 && columns.last.numeric ? columns.last : null;
    final middle = columns.skip(1).where((c) => c != tail);

    return Card(
      key: ValueKey('paper-line-$index'),
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: ListTile(
        title: Text(row[head.key] ?? ''),
        subtitle: Text(middle
            .where((c) => (row[c.key] ?? '').trim().isNotEmpty)
            .map((c) => '${c.label} ${row[c.key]}')
            .join(' · ')),
        trailing: tail == null || (row[tail.key] ?? '').isEmpty
            ? null
            : Text(row[tail.key]!, style: const TextStyle(fontWeight: FontWeight.w600)),
      ),
    );
  }

  Widget _signature(BuildContext context, PaperSignature s) {
    final colour = switch (s.status) {
      'approved' => context.palette.success,
      'rejected' || 'cancelled' => context.palette.danger,
      _ => context.palette.pending,
    };

    final w = context.l10n;
    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(s.statusLabel, style: TextStyle(fontWeight: FontWeight.w700, color: colour)),
            if (s.requestedBy != null) Text(w.papersRequestedBy(s.requestedBy!) + (s.requestedAt == null ? '' : ' · ${s.requestedAt}')),
            if (s.steps.isNotEmpty) Text(w.papersSteps(s.steps.join(' → '))),
            for (final d in s.decisions)
              Text([d.decisionLabel, if (d.by != null) d.by!, if (d.at != null) d.at!].join(' · ') +
                  (d.remarks == null || d.remarks!.isEmpty ? '' : '\n“${d.remarks}”')),
            // ⓘ সই দেওয়া অনুমোদনের পাতা থেকেই — দ্বিতীয় দরজা নয়
            if (s.canSign)
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton.icon(
                  key: const ValueKey('paper-sign'),
                  onPressed: () => context.push('/home/approvals'),
                  icon: const Icon(Icons.draw_outlined),
                  label: Text(w.papersSign),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

// ── উত্তর ─────────────────────────────────────────────────────────────────────────────────────

class PaperStatus {
  const PaperStatus(this.code, this.label);

  final String code;
  final String label;
}

class PaperFilters {
  const PaperFilters({this.dated = true, this.branches = false, this.searchable = true, this.statuses = const []});

  final bool dated;
  final bool branches;
  final bool searchable;
  final List<PaperStatus> statuses;

  factory PaperFilters.fromJson(Object? raw) {
    final j = raw is Map ? raw : const {};
    return PaperFilters(
      dated: j['dated'] != false,
      branches: j['branches'] == true,
      searchable: j['searchable'] != false,
      statuses: [
        for (final s in (j['statuses'] is List ? j['statuses'] as List : const []).whereType<Map>())
          PaperStatus(s['code']?.toString() ?? '', s['label']?.toString() ?? ''),
      ],
    );
  }
}

class PaperRow {
  const PaperRow({
    required this.id,
    required this.no,
    this.dateLabel,
    this.status = '',
    this.statusLabel = '',
    this.branch,
    this.party,
    this.note,
    this.total,
  });

  final String id;
  final String no;
  final String? dateLabel;
  final String status;
  final String statusLabel;
  final String? branch;
  final String? party;
  final String? note;

  /// টাকা নেই (বা দেখার চাবি নেই) তো null
  final String? total;

  factory PaperRow.fromJson(Map<dynamic, dynamic> j) => PaperRow(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        dateLabel: j['date_label']?.toString() ?? (j['date'] == null ? null : dayOf(j['date']?.toString())),
        status: j['status']?.toString() ?? '',
        statusLabel: j['status_label']?.toString() ?? '',
        branch: j['branch']?.toString(),
        party: j['party']?.toString(),
        note: j['note']?.toString(),
        total: j['total']?.toString(),
      );
}

class PaperPage {
  const PaperPage({
    required this.rows,
    this.filters = const PaperFilters(),
    this.count = 0,
    this.sum,
    this.pageSum,
    this.nextPage,
  });

  final List<PaperRow> rows;
  final PaperFilters filters;
  final int count;

  /// ছাঁকা তালিকার সর্বমোট — টাকা না থাকলে null
  final String? sum;

  /// এই পাতার সারিগুলোর মোট — সার্ভারের গোনা
  final String? pageSum;
  final int? nextPage;

  factory PaperPage.fromJson(Map<dynamic, dynamic> body) => PaperPage(
        rows: [
          for (final row in (body['rows'] is List ? body['rows'] as List : const []).whereType<Map>()) PaperRow.fromJson(row),
        ],
        filters: PaperFilters.fromJson(body['filters']),
        count: (body['count'] as num?)?.toInt() ?? 0,
        sum: body['sum']?.toString(),
        pageSum: body['page_sum']?.toString(),
        nextPage: (body['next_page'] as num?)?.toInt(),
      );
}

class PaperFact {
  const PaperFact(this.label, this.value);

  final String label;
  final String value;
}

class PaperColumn {
  const PaperColumn(this.key, this.label, {this.numeric = false});

  final String key;
  final String label;
  final bool numeric;
}

class PaperDecision {
  const PaperDecision({required this.decisionLabel, this.by, this.at, this.remarks});

  final String decisionLabel;
  final String? by;
  final String? at;
  final String? remarks;
}

class PaperSignature {
  const PaperSignature({
    required this.status,
    required this.statusLabel,
    this.steps = const [],
    this.requestedBy,
    this.requestedAt,
    this.decisions = const [],
    this.canSign = false,
  });

  final String status;
  final String statusLabel;
  final List<String> steps;
  final String? requestedBy;
  final String? requestedAt;
  final List<PaperDecision> decisions;
  final bool canSign;
}

class PaperDetail {
  const PaperDetail({
    required this.row,
    this.facts = const [],
    this.columns = const [],
    this.rows = const [],
    this.totals = const {},
    this.signatures = const [],
    this.printType,
  });

  final PaperRow row;
  final List<PaperFact> facts;
  final List<PaperColumn> columns;
  final List<Map<String, String?>> rows;

  /// কলামের চাবি → যোগফল (লেখা)
  final Map<String, String> totals;
  final List<PaperSignature> signatures;

  /// ছাপার ধরন ([DocumentActionsSheet]) — ওয়েবে ছাপা না থাকলে null
  final String? printType;

  String labelOf(String columnKey) => columns.where((c) => c.key == columnKey).map((c) => c.label).firstOrNull ?? '';

  factory PaperDetail.fromJson(Map<dynamic, dynamic> j) {
    List<Map> maps(Object? raw) => raw is List ? raw.whereType<Map>().toList() : const [];
    final totals = j['totals'];
    return PaperDetail(
      row: PaperRow.fromJson(j),
      facts: [for (final f in maps(j['facts'])) PaperFact(f['label']?.toString() ?? '', f['value']?.toString() ?? '')],
      columns: [
        for (final c in maps(j['columns']))
          PaperColumn(c['key']?.toString() ?? '', c['label']?.toString() ?? '', numeric: c['numeric'] == true),
      ],
      rows: [
        for (final r in maps(j['rows'])) {for (final e in r.entries) e.key.toString(): e.value?.toString()},
      ],
      totals: totals is Map ? {for (final e in totals.entries) e.key.toString(): e.value?.toString() ?? ''} : const {},
      signatures: [
        for (final s in maps(j['signatures']))
          PaperSignature(
            status: s['status']?.toString() ?? '',
            statusLabel: s['status_label']?.toString() ?? '',
            steps: [for (final x in (s['steps'] is List ? s['steps'] as List : const [])) x.toString()],
            requestedBy: s['requested_by']?.toString(),
            requestedAt: s['requested_at']?.toString(),
            decisions: [
              for (final d in maps(s['decisions']))
                PaperDecision(
                  decisionLabel: d['decision_label']?.toString() ?? '',
                  by: d['by']?.toString(),
                  at: d['at']?.toString(),
                  remarks: d['remarks']?.toString(),
                ),
            ],
            canSign: s['can_sign'] == true,
          ),
      ],
      printType: j['print_type']?.toString(),
    );
  }
}

abstract class PaperApi {
  Future<PaperPage> list(String key,
      {String? from, String? to, String q = '', String? status, String? branch, int page = 1});

  Future<PaperDetail> one(String key, String id);
}

class ServerPaperApi implements PaperApi {
  const ServerPaperApi();

  @override
  Future<PaperPage> list(String key,
      {String? from, String? to, String q = '', String? status, String? branch, int page = 1}) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/papers/$key', queryParameters: {
      if (from != null) 'from': from,
      if (to != null) 'to': to,
      if (q.isNotEmpty) 'q': q,
      if (status != null) 'status': status,
      if (branch != null) 'branch': branch,
      'page': page,
    });
    return PaperPage.fromJson(r.data ?? const <String, dynamic>{});
  }

  @override
  Future<PaperDetail> one(String key, String id) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/papers/$key/${Uri.encodeComponent(id)}');
    return PaperDetail.fromJson(r.data ?? const <String, dynamic>{});
  }
}
