import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/printing/documents_api.dart';
import '../../core/records/report_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/look_widgets.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/search_debouncer.dart';
import '../printing/document_actions_sheet.dart';
import 'report_module_name.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// Every report this person may run — docs/Contract §৯.
///
/// <p><b>One screen for thirty-three reports.</b> The server already has a
/// `ReportEngine` with a registry, typed columns and per-column permissions;
/// the phone needs to know none of that, only how to draw what it is handed.
/// A report registered on the server tomorrow appears here without a mobile
/// release — the same reasoning as `GET /sync/capabilities`.
class ReportsScreen extends StatefulWidget {
  const ReportsScreen(
      {super.key, this.loadList, this.open, this.module, this.title});

  final Future<List<ReportSummary>> Function()? loadList;

  /// ⭐ এক মডিউলের রিপোর্টই — "মজুদের রিপোর্ট" (মালিক, ৪ অক্টোবর ২০২৬); null মানে সব
  final String? module;
  final String? title;
  final Future<ReportPage> Function(
      String key, int page, Map<String, dynamic> filters)? open;

  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  List<ReportSummary>? _reports;
  String? _error;
  bool _busy = false;
  String _query = '';
  late final _typed = SearchDebouncer(onSearch: (q) => setState(() => _query = q));

  @override
  void dispose() {
    _typed.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final list = await (widget.loadList ?? ReportsApi.list)();
      final shown = widget.module == null
          ? list
          : list.where((r) => r.module == widget.module).toList();
      if (mounted) setState(() => _reports = shown);
    } catch (error) {
      if (mounted) {
        setState(() => _error = errorMessageFor(error,
            fallback: AppLocale.l10n.reportListLoadFailed,
            whenAbsent: AppLocale.l10n.reportServerTooOld));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final reports = _reports;
    final filtered = reports == null
        ? const <ReportSummary>[]
        : reports
            .where((r) =>
                _query.trim().isEmpty ||
                r.title.toLowerCase().contains(_query.toLowerCase()) ||
                r.key.toLowerCase().contains(_query.toLowerCase()))
            .toList();

    return Scaffold(
      appBar: AbosScreenBar(title: widget.title ?? context.l10n.reportTitle),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: InputDecoration(
                hintText: context.l10n.reportSearchHint,
                prefixIcon: const Icon(Icons.search),
              ),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
          ),
          if (_busy) const LinearProgressIndicator(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
                children: [
                  if (_error != null)
                    EmptyState(
                      icon: Icons.cloud_off_outlined,
                      title: context.l10n.reportListFailedTitle,
                      message: _error,
                    )
                  else if (reports == null)
                    const SizedBox(height: 200)
                  else if (reports.isEmpty)
                    EmptyState(
                      icon: Icons.bar_chart_outlined,
                      // Not an error: the server sends only what this person
                      // may run, and for some roles that is nothing.
                      title: context.l10n.reportNoneForYou,
                    )
                  else if (filtered.isEmpty)
                    EmptyState(
                        icon: Icons.search_off, title: context.l10n.reportNoMatch)
                  else
                    ...filtered.map((r) => Card(
                          margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                          child: ListTile(
                            leading: const Icon(Icons.bar_chart_outlined),
                            title: Text(r.title),
                            subtitle: r.module == null ? null : Text(reportModuleName(r.module!, context.l10n)),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => Navigator.of(context).push(
                              MaterialPageRoute<void>(
                                builder: (_) => ReportViewScreen(
                                  report: r,
                                  open: widget.open,
                                ),
                              ),
                            ),
                          ),
                        )),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One report, drawn entirely from what the server said its columns are.
class ReportViewScreen extends StatefulWidget {
  const ReportViewScreen(
      {super.key,
      required this.report,
      this.open,
      this.today,
      this.exportPdf,
      this.loadChoices,
      this.searchChoices});

  final ReportSummary report;

  /// Injected in tests. ⓘ The filters go with every call — the date range lives here, on this screen.
  final Future<ReportPage> Function(
      String key, int page, Map<String, dynamic> filters)? open;

  /// The day "today" is, injected in tests so a date range does not depend on when the test runs.
  final DateTime Function()? today;

  /// The PDF of this report with these filters — injected in tests (the real one is `GET /reports/{key}/export?format=pdf`).
  final Future<Uint8List> Function(String key, Map<String, dynamic> filters)?
      exportPdf;

  /// Injected in tests — the real one is `GET /reports/{key}/choices`.
  final Future<List<ReportChoice>> Function(String key)? loadChoices;

  /// Injected in tests — the real one is `GET /reports/{key}/choices/{filter}?q=`.
  final Future<List<(String, String)>> Function(String key, String filter, String q)? searchChoices;

  @override
  State<ReportViewScreen> createState() => _ReportViewScreenState();
}

class _ReportViewScreenState extends State<ReportViewScreen> {
  ReportPage? _page;
  String? _error;
  bool _busy = false;
  int _pageNo = 1;

  /// ⭐ তারিখ ধরে — মালিক, ৪ অক্টোবর ২০২৬: *"all ledger & report date veue print share"*। Only for a report whose
  /// definition declares `date_range` ([[ReportSummary.takesDateRange]]); the server validates it (422 on a wrong one).
  /// ⓘ Starts on the first of this month.
  DateTimeRange? _range;

  /// ⭐ তারিখ ছাড়া বাকি ছাঁকনি — হিসাব, গ্রাহক, গুদাম, শাখা, মাস… সার্ভারের ঘোষণা থেকে ([ReportChoice]; fe, ১৯ অক্টোবর ২০২৬)।
  /// ⛔ আগে ফোন কেবল তারিখ পাঠাত: খতিয়ান, গ্রাহকের খাতা, মজুদের খতিয়ান, লট-খোঁজা হিসাব বা জিনিস ছাড়াই খুলত।
  List<ReportChoice> _choices = const [];
  final Map<String, (String, String)> _picked = {};

  static final DateFormat _wire = DateFormat('yyyy-MM-dd');
  static final DateFormat _shown = DateFormat('dd/MM/yyyy');

  @override
  void initState() {
    super.initState();
    if (widget.report.takesDateRange) {
      final now = (widget.today ?? DateTime.now)();
      final day = DateTime(now.year, now.month, now.day);
      _range = DateTimeRange(start: DateTime(day.year, day.month, 1), end: day);
    }
    if (widget.report.filters.any((f) => f != 'date_range')) {
      _loadChoices();
    }
    _load();
  }

  Future<void> _loadChoices() async {
    try {
      final choices = await (widget.loadChoices ?? ReportsApi.choices)(widget.report.key);
      if (mounted) setState(() => _choices = choices.where((c) => c.drawable).toList());
    } catch (_) {
      // ⓘ ছাঁকনি না এলেও রিপোর্ট খোলে — কেবল ছাঁকনি ছাড়া, আগের মতো
    }
  }

  /// মাসের ছাঁকনির বাছাই — এই মাস থেকে পেছনে ২৪টা (`2026-10`)
  List<(String, String)> _months() {
    final now = (widget.today ?? DateTime.now)();
    return [
      for (var i = 0; i < 24; i++)
        (
          DateFormat('yyyy-MM').format(DateTime(now.year, now.month - i)),
          DateFormat('MMMM yyyy').format(DateTime(now.year, now.month - i)),
        ),
    ];
  }

  Future<void> _pick(ReportChoice choice) async {
    final options = choice.kind == 'month' ? _months() : choice.options;
    final picked = await showModalBottomSheet<(String, String)?>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _ChoiceSheet(
        title: choice.label,
        options: options,
        search: choice.capped
            ? (q) => (widget.searchChoices ?? ReportsApi.search)(widget.report.key, choice.key, q)
            : null,
      ),
    );
    if (picked == null || !mounted) return;
    setState(() {
      // ⓘ ফাঁকা মান = "সব" — ছাঁকনি তুলে নেওয়া
      if (picked.$1.isEmpty) {
        _picked.remove(choice.key);
      } else {
        _picked[choice.key] = picked;
      }
      _pageNo = 1;
    });
    _load();
  }

  Map<String, dynamic> get _filters {
    final range = _range;
    return {
      if (range != null) 'from': _wire.format(range.start),
      if (range != null) 'to': _wire.format(range.end),
      for (final e in _picked.entries) e.key: e.value.$1,
    };
  }

  Future<void> _pickRange() async {
    final now = (widget.today ?? DateTime.now)();
    final picked = await showDateRangePicker(
      context: context,
      firstDate: DateTime(2020),
      lastDate: DateTime(now.year, now.month, now.day),
      initialDateRange: _range,
      helpText: context.l10n.reportDateRangeHelp,
      saveText: context.l10n.reportDateRangeShow,
    );
    if (picked == null || !mounted) return;
    setState(() {
      _range = picked;
      _pageNo = 1;
    });
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await (widget.open ??
          (String k, int p, Map<String, dynamic> f) =>
              ReportsApi.run(k, page: p, filters: f))(
        widget.report.key,
        _pageNo,
        _filters,
      );
      if (mounted) setState(() => _page = result);
    } catch (error) {
      if (mounted) {
        // ⛔ No whenAbsent here, unlike the list above. This call names a
        // particular report, so a 404 is at least as likely to mean that key
        // is gone as that the route is — and "your app is newer than the
        // server" would then be a confident, wrong explanation.
        setState(() =>
            _error = errorMessageFor(error, fallback: AppLocale.l10n.reportLoadFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;

    return Scaffold(
      appBar: AbosScreenBar(
        title: page?.title ?? widget.report.title,
        actions: [
          // ⭐ PDF — দেখা, ছাপা, পাঠানো; এই পর্দার একই তারিখ আর ছাঁকনিতে, সব সারি (মালিক, ৪ অক্টোবর ২০২৬)
          IconButton(
            key: const Key('report-pdf'),
            tooltip: context.l10n.reportPdfTooltip,
            icon: const Icon(Icons.picture_as_pdf_outlined),
            onPressed: page == null
                ? null
                : () => showModalBottomSheet<void>(
                      context: context,
                      isScrollControlled: true,
                      builder: (_) => DocumentActionsSheet(
                        type: 'report',
                        id: widget.report.key,
                        title: page.title,
                        fileStem:
                            'abos-${widget.report.key.replaceAll('.', '-')}',
                        loadPapers: () async => const ['a4'],
                        loadPdf: (_) => (widget.exportPdf ??
                            (String k, Map<String, dynamic> f) =>
                                DocumentsApi.export(
                                    slug: k, format: 'pdf', filters: f))(
                          widget.report.key,
                          _filters,
                        ),
                      ),
                    ),
          ),
        ],
      ),
      body: Column(
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_range != null)
            Padding(
              padding: const EdgeInsets.fromLTRB(
                  AppSpacing.md, AppSpacing.sm, AppSpacing.md, 0),
              child: Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton.icon(
                  key: const Key('report-range'),
                  onPressed: _busy ? null : _pickRange,
                  icon: const Icon(Icons.date_range_outlined, size: 18),
                  label: Text(
                      '${_shown.format(_range!.start)} — ${_shown.format(_range!.end)}'),
                ),
              ),
            ),
          if (_choices.isNotEmpty)
            Padding(
              padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.xs, AppSpacing.md, 0),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Wrap(
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  children: [
                    for (final c in _choices)
                      ActionChip(
                        key: ValueKey('report-choice-${c.key}'),
                        avatar: const Icon(Icons.filter_list, size: 16),
                        label: Text(context.l10n.reportChoice(c.label, _picked[c.key]?.$2 ?? context.l10n.reportChoiceAll)),
                        onPressed: _busy ? null : () => _pick(c),
                      ),
                  ],
                ),
              ),
            ),
          if (page != null)
            Container(
              width: double.infinity,
              color: context.palette.surfaceMuted,
              padding: const EdgeInsets.symmetric(
                  horizontal: AppSpacing.md, vertical: AppSpacing.sm),
              // ⚠️ Says which slice of the report is on screen. Without it a
              // person reads one page of four hundred rows as the whole
              // report, and every conclusion after that is drawn from a
              // hundred rows they believed were all of them.
              child: Text(page.rangeSentence,
                  style: const TextStyle(fontSize: 12.5)),
            ),
          Expanded(
            // ⛔ Both empty branches sit inside the RefreshIndicator, over a
            // scrollable. Outside one, the pull gesture is not recognised at
            // all — and with no rows there is no pager either, so a failed
            // report became a dead end that could only be left by going back
            // and tapping the report again. Same shape as the bug found on
            // four list screens; see pull_to_refresh_test.dart.
            //
            // ⓘ The RefreshIndicator goes around the empty branches only, not
            // around _Table. A table scrolls horizontally on the outside, and
            // a RefreshIndicator ignores a horizontal scrollable — wrapping
            // the whole thing would have looked right in the source and done
            // nothing on a phone, which is this bug's entire habit. Rows on
            // screen have the pager instead.
            child: page != null && page.rows.isNotEmpty
                ? _Table(page: page)
                : RefreshIndicator(
                    onRefresh: _load,
                    child: ListView(
                      children: [
                        if (_error != null)
                          EmptyState(
                            icon: Icons.cloud_off_outlined,
                            title: context.l10n.reportLoadFailedTitle,
                            message: _error,
                          )
                        else if (page == null)
                          const SizedBox(height: 200)
                        else
                          EmptyState(
                            icon: Icons.inbox_outlined,
                            title: context.l10n.reportNoRowsInPeriod,
                          ),
                      ],
                    ),
                  ),
          ),
          if (page != null && (page.page > 1 || page.hasMore))
            _Pager(
              page: page,
              onPrevious: page.page > 1 && !_busy
                  ? () {
                      setState(() => _pageNo = page.page - 1);
                      _load();
                    }
                  : null,
              onNext: page.hasMore && !_busy
                  ? () {
                      setState(() => _pageNo = page.page + 1);
                      _load();
                    }
                  : null,
            ),
        ],
      ),
    );
  }
}

/// ছাঁকনির বাছাই — "সব" আগে; লিখে খোঁজা (বড় তালিকায় সার্ভারে, নইলে হাতের তালিকায়)
class _ChoiceSheet extends StatefulWidget {
  const _ChoiceSheet({required this.title, required this.options, this.search});

  final String title;
  final List<(String, String)> options;
  final Future<List<(String, String)>> Function(String q)? search;

  @override
  State<_ChoiceSheet> createState() => _ChoiceSheetState();
}

class _ChoiceSheetState extends State<_ChoiceSheet> {
  String _query = '';
  List<(String, String)>? _found;
  bool _busy = false;

  /// ⭐ সার্ভারে খোঁজা তালিকার নিয়মে ([SearchDebouncer]: ৩ অক্ষর + ৬০০ ms, Enter সাথে সাথে); হাতের তালিকা টাইপের সাথেই ছাঁকে
  late final _typed = SearchDebouncer(onSearch: _ask);

  @override
  void dispose() {
    _typed.dispose();
    super.dispose();
  }

  void _changed(String text) {
    setState(() {
      _query = text;
      // ⓘ খালি করলে হাতের তালিকা সাথে সাথে; নইলে আগের সার্ভারের ফল থাকে যতক্ষণ না নতুন খোঁজা আসে
      if (text.trim().isEmpty) _found = null;
    });
    _typed.onChanged(text);
  }

  Future<void> _ask(String q) async {
    final search = widget.search;
    if (search == null || q.isEmpty) {
      if (mounted) setState(() => _found = null);
      return;
    }
    setState(() => _busy = true);
    try {
      final found = await search(q);
      if (mounted && _query.trim() == q) setState(() => _found = found);
    } catch (_) {
      // ⓘ খোঁজা ব্যর্থ — হাতের তালিকাই থাকে
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final q = _query.trim().toLowerCase();
    final shown = _found ??
        widget.options.where((o) => q.isEmpty || o.$2.toLowerCase().contains(q)).toList();

    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.75,
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: TextField(
                key: const ValueKey('report-choice-search'),
                decoration: InputDecoration(labelText: context.l10n.reportChoiceSearch(widget.title), prefixIcon: const Icon(Icons.search)),
                onChanged: _changed,
                onSubmitted: _typed.onSubmitted,
              ),
            ),
            if (_busy) const LinearProgressIndicator(),
            Expanded(
              child: ListView(
                children: [
                  ListTile(
                    key: const ValueKey('report-choice-all'),
                    title: Text(context.l10n.reportChoiceAll),
                    onTap: () => Navigator.of(context).pop(('', context.l10n.reportChoiceAll)),
                  ),
                  for (final o in shown)
                    ListTile(
                      key: ValueKey('report-choice-option-${o.$1}'),
                      title: Text(o.$2),
                      onTap: () => Navigator.of(context).pop(o),
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

class _Table extends StatelessWidget {
  const _Table({required this.page});

  final ReportPage page;

  @override
  Widget build(BuildContext context) {
    final columns = page.columns;

    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: SingleChildScrollView(
        child: DataTable(
          columnSpacing: AppSpacing.lg,
          columns: [
            for (final c in columns)
              DataColumn(label: Text(c.label), numeric: c.isNumeric),
          ],
          rows: [
            for (final row in page.rows)
              DataRow(cells: [
                for (final c in columns) DataCell(Text(c.format(row[c.key]))),
              ]),
            // ⛔ The server's totals, placed as their own row. Never summed
            // from what is on screen — see ReportPage.totals.
            if (page.totals.isNotEmpty)
              DataRow(
                color: WidgetStatePropertyAll(
                    context.lookAccent(AppColors.primary).withValues(alpha: 0.06)),
                cells: [
                  for (var i = 0; i < columns.length; i++)
                    DataCell(Text(
                      i == 0
                          ? context.l10n.reportTotal
                          : columns[i].format(page.totals[columns[i].key]),
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    )),
                ],
              ),
          ],
        ),
      ),
    );
  }
}

class _Pager extends StatelessWidget {
  const _Pager({required this.page, this.onPrevious, this.onNext});

  final ReportPage page;
  final VoidCallback? onPrevious;
  final VoidCallback? onNext;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.md, vertical: AppSpacing.sm),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            TextButton.icon(
              onPressed: onPrevious,
              icon: const Icon(Icons.chevron_left),
              label: Text(context.l10n.reportPrevious),
            ),
            Text(context.l10n.reportPageOf('${page.page}', '${page.lastPage}'),
                style: Theme.of(context).textTheme.bodySmall),
            TextButton.icon(
              onPressed: onNext,
              icon: const Icon(Icons.chevron_right),
              label: Text(context.l10n.reportNext),
            ),
          ],
        ),
      ),
    );
  }
}
