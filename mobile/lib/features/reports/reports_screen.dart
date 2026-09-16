import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/records/report_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// Every report this person may run — docs/Contract §৯.
///
/// <p><b>One screen for thirty-three reports.</b> The server already has a
/// `ReportEngine` with a registry, typed columns and per-column permissions;
/// the phone needs to know none of that, only how to draw what it is handed.
/// A report registered on the server tomorrow appears here without a mobile
/// release — the same reasoning as `GET /sync/capabilities`.
class ReportsScreen extends StatefulWidget {
  const ReportsScreen({super.key, this.loadList, this.open});

  final Future<List<ReportSummary>> Function()? loadList;
  final Future<ReportPage> Function(String key, int page)? open;

  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  List<ReportSummary>? _reports;
  String? _error;
  bool _busy = false;
  String _query = '';

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
      if (mounted) setState(() => _reports = list);
    } catch (error) {
      if (mounted) {
        setState(() => _error =
            errorMessageFor(error, fallback: 'রিপোর্টের তালিকা আনা গেল না।'));
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
      appBar: AppBar(title: const Text('রিপোর্ট')),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: const InputDecoration(
                hintText: 'রিপোর্টের নাম দিয়ে খুঁজুন',
                prefixIcon: Icon(Icons.search),
              ),
              onChanged: (v) => setState(() => _query = v),
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
                      title: 'তালিকা আনা গেল না',
                      message: _error,
                    )
                  else if (reports == null)
                    const SizedBox(height: 200)
                  else if (reports.isEmpty)
                    const EmptyState(
                      icon: Icons.bar_chart_outlined,
                      // Not an error: the server sends only what this person
                      // may run, and for some roles that is nothing.
                      title: 'আপনার জন্য কোনো রিপোর্ট নেই',
                    )
                  else if (filtered.isEmpty)
                    const EmptyState(
                        icon: Icons.search_off, title: 'কোনো মিল পাওয়া যায়নি')
                  else
                    ...filtered.map((r) => Card(
                          margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                          child: ListTile(
                            leading: const Icon(Icons.bar_chart_outlined),
                            title: Text(r.title),
                            subtitle: r.module == null ? null : Text(r.module!),
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
  const ReportViewScreen({super.key, required this.report, this.open});

  final ReportSummary report;
  final Future<ReportPage> Function(String key, int page)? open;

  @override
  State<ReportViewScreen> createState() => _ReportViewScreenState();
}

class _ReportViewScreenState extends State<ReportViewScreen> {
  ReportPage? _page;
  String? _error;
  bool _busy = false;
  int _pageNo = 1;

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
      final result = await (widget.open ??
          (String k, int p) => ReportsApi.run(k, page: p))(
        widget.report.key,
        _pageNo,
      );
      if (mounted) setState(() => _page = result);
    } catch (error) {
      if (mounted) {
        setState(() =>
            _error = errorMessageFor(error, fallback: 'রিপোর্ট আনা গেল না।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;

    return Scaffold(
      appBar: AppBar(title: Text(page?.title ?? widget.report.title)),
      body: Column(
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (page != null)
            Container(
              width: double.infinity,
              color: AppColors.surfaceMuted,
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
            child: _error != null
                ? EmptyState(
                    icon: Icons.cloud_off_outlined,
                    title: 'রিপোর্ট আনা গেল না',
                    message: _error)
                : page == null
                    ? const SizedBox.shrink()
                    : page.rows.isEmpty
                        ? const EmptyState(
                            icon: Icons.inbox_outlined,
                            title: 'এই সময়ে কোনো সারি নেই')
                        : _Table(page: page),
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
                    AppColors.primary.withValues(alpha: 0.06)),
                cells: [
                  for (var i = 0; i < columns.length; i++)
                    DataCell(Text(
                      i == 0
                          ? 'মোট'
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
              label: const Text('আগের'),
            ),
            Text('পাতা ${page.page} / ${page.lastPage}',
                style: Theme.of(context).textTheme.bodySmall),
            TextButton.icon(
              onPressed: onNext,
              icon: const Icon(Icons.chevron_right),
              label: const Text('পরের'),
            ),
          ],
        ),
      ),
    );
  }
}
