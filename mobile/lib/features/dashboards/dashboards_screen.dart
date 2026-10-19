import 'package:flutter/material.dart';

import '../books/books_widgets.dart';
import '../reports/reports_screen.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/dashboard/dashboard_api.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// ⭐ "ব্যবসার ড্যাশবোর্ড" — every module dashboard this person may open, each with its first figure, then the whole
/// dashboard one tap away (owner, 4 Oct 2026: *"egulo nadile bujbo kikore kihocche"*).
///
/// <p>The list is the server's: a module the company switched off on the phone, or one whose key this person lacks,
/// never appears — the same rule as the web's home.
class DashboardsScreen extends StatefulWidget {
  const DashboardsScreen({super.key, this.loadList, this.openModule});

  final Future<List<DashboardEntry>> Function()? loadList;
  final Future<ModuleDashboard> Function(String code)? openModule;

  @override
  State<DashboardsScreen> createState() => _DashboardsScreenState();
}

class _DashboardsScreenState extends State<DashboardsScreen> {
  List<DashboardEntry>? _entries;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final entries = await (widget.loadList ?? DashboardApi.list)();
      if (mounted) setState(() => _entries = entries);
    } catch (error) {
      if (mounted) {
        setState(() => _error = errorMessageFor(error,
            fallback: 'ড্যাশবোর্ডের তালিকা আনা গেল না।'));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final entries = _entries;

    return Scaffold(
      appBar: AppBar(title: const Text('ব্যবসার ড্যাশবোর্ড')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_error != null)
              EmptyState(
                  icon: Icons.cloud_off_outlined,
                  title: 'আনা গেল না',
                  message: _error)
            else if (entries == null)
              const Padding(
                  padding: EdgeInsets.all(AppSpacing.xl),
                  child: Center(child: CircularProgressIndicator()))
            else if (entries.isEmpty)
              const EmptyState(
                  icon: Icons.dashboard_outlined,
                  title: 'আপনার জন্য কোনো ড্যাশবোর্ড নেই')
            else
              for (final entry in entries)
                Card(
                  key: Key('dashboard-${entry.module}'),
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: ListTile(
                    title: Text(entry.name,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: entry.stat == null
                        ? null
                        : Text(
                            '${entry.stat!.label}: ${entry.stat!.hidden ? 'চাবি নেই' : (entry.stat!.value ?? '—')}'),
                    // ⓘ তালিকার সারি অন্তত ৭২dp, রং থিমের ভূমিকা থেকে (নকশার নিয়ম ০.৪.৩৮ §১, §২)
                    minTileHeight: 72,
                    trailing: Icon(Icons.chevron_right,
                        color: context.palette.onSurfaceMuted),
                    onTap: () =>
                        Navigator.of(context).push(MaterialPageRoute<void>(
                      builder: (_) => ModuleDashboardScreen(
                          module: entry.module,
                          name: entry.name,
                          open: widget.openModule),
                    )),
                  ),
                ),
          ],
        ),
      ),
    );
  }
}

/// One module's dashboard: its figures, its charts and its short lists — in that order, the web's order.
///
/// <p>⚠️ Lists are drawn one field per line, never as a table (owner, 1 Oct 2026: his screen cuts the right side off).
class ModuleDashboardScreen extends StatefulWidget {
  const ModuleDashboardScreen(
      {super.key, required this.module, required this.name, this.open});

  final String module;
  final String name;
  final Future<ModuleDashboard> Function(String code)? open;

  @override
  State<ModuleDashboardScreen> createState() => _ModuleDashboardScreenState();
}

class _ModuleDashboardScreenState extends State<ModuleDashboardScreen> {
  ModuleDashboard? _dashboard;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final dashboard =
          await (widget.open ?? DashboardApi.module)(widget.module);
      if (mounted) setState(() => _dashboard = dashboard);
    } catch (error) {
      if (mounted) {
        setState(() => _error =
            errorMessageFor(error, fallback: 'ড্যাশবোর্ড আনা গেল না।'));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _dashboard;

    return Scaffold(
      appBar: AppBar(
          title: Text(d?.title.isNotEmpty == true ? d!.title : widget.name)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_error != null)
              EmptyState(
                  icon: Icons.cloud_off_outlined,
                  title: 'আনা গেল না',
                  message: _error)
            else if (d == null)
              const Padding(
                  padding: EdgeInsets.all(AppSpacing.xl),
                  child: Center(child: CircularProgressIndicator()))
            else ...[
              if (d.subtitle.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Text(d.subtitle,
                      style: TextStyle(color: context.palette.onSurfaceMuted)),
                ),
              for (final stat in d.stats) _StatCard(stat: stat),
              for (final panel in d.panels) _PanelCard(panel: panel),
              for (final listing in d.listings) _ListingCard(listing: listing),
              // ⭐ এই মডিউলের রিপোর্ট — তারিখ আর PDF সহ (মালিক, ৪ অক্টোবর ২০২৬: "Sales Account er gulo dorkar")
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm),
                child: OutlinedButton.icon(
                  key: const Key('dashboard-reports'),
                  icon: const Icon(Icons.assessment_outlined, size: 18),
                  label: const Text('এই মডিউলের রিপোর্ট'),
                  onPressed: () =>
                      Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => ReportsScreen(
                      module: widget.module,
                      title:
                          '${d.title.isNotEmpty ? d.title : widget.name} — রিপোর্ট',
                    ),
                  )),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// ⭐ "বিক্রয়ের হালচাল" — ওয়েবের পাতার কার্ড, চার্ট আর তালিকা, ফোনের নিজের ঢঙে, তারিখ বেছে (মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta
/// mobile app style e zate bujazay"*)। ⓘ শুরু আজ থেকে — ওয়েবের পাতার মতোই।
class SalesOverviewScreen extends StatefulWidget {
  const SalesOverviewScreen({super.key, this.open, this.today});

  final Future<ModuleDashboard> Function(String from, String to)? open;

  /// পরীক্ষার জন্য — না দিলে আজ
  final DateTime? today;

  @override
  State<SalesOverviewScreen> createState() => _SalesOverviewScreenState();
}

class _SalesOverviewScreenState extends State<SalesOverviewScreen> {
  late DateTime _from;
  late DateTime _to;
  ModuleDashboard? _dashboard;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _from = _to = DateTime(now.year, now.month, now.day);
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final from = isoOf(_from), to = isoOf(_to);
      final dashboard = await (widget.open ??
          (f, t) => DashboardApi.salesOverview(from: f, to: t))(from, to);
      if (mounted) setState(() => _dashboard = dashboard);
    } catch (error) {
      if (mounted) {
        setState(() => _error =
            errorMessageFor(error, fallback: 'বিক্রয়ের হালচাল আনা গেল না।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _dashboard;

    return Scaffold(
      appBar: AppBar(
          title: Text(d?.title.isNotEmpty == true ? d!.title : 'বিক্রয়ের হালচাল')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
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
            const SizedBox(height: AppSpacing.sm),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(
                  icon: Icons.cloud_off_outlined,
                  title: 'আনা গেল না',
                  message: _error)
            else if (d != null) ...[
              if (d.periodRefused != null)
                Padding(
                  key: const Key('overview-refused'),
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Text(d.periodRefused!,
                      style: const TextStyle(color: AppColors.warning)),
                ),
              if (d.subtitle.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Text(d.subtitle,
                      style: TextStyle(color: context.palette.onSurfaceMuted)),
                ),
              if (d.stats.isEmpty && d.panels.isEmpty && d.listings.isEmpty)
                const EmptyState(
                    icon: Icons.lock_outline,
                    title: 'এই পর্দার কোনো সংখ্যা দেখার অনুমতি আপনার নেই।'),
              for (final stat in d.stats) _StatCard(stat: stat),
              for (final panel in d.panels) _PanelCard(panel: panel),
              for (final listing in d.listings) _ListingCard(listing: listing),
            ],
          ],
        ),
      ),
    );
  }
}

class _StatCard extends StatelessWidget {
  const _StatCard({required this.stat});

  final DashboardStat stat;

  // ⭐ সাধারণ লেখা context.palette থেকে (আলোতে হুবহু আগের রং, অন্ধকারে ঠিক — নকশার নিয়ম ০.৪.৩৮ §১, a4 c5e7fbbd6);
  // ভুল থিমের error (আলোতে AppColors.danger-ই); ভালো/সাবধান অবস্থার রং, আগের মতো
  Color _tone(BuildContext context) => switch (stat.tone) {
        'good' => AppColors.success,
        'warn' => AppColors.warning,
        'bad' => Theme.of(context).colorScheme.error,
        _ => context.palette.onSurface,
      };

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(stat.label, style: TextStyle(color: context.palette.onSurfaceMuted)),
            const SizedBox(height: AppSpacing.xs),
            Text(
              stat.hidden
                  ? 'চাবি নেই — দেখার অনুমতি লাগে'
                  : (stat.value ?? '—'),
              style: TextStyle(
                fontSize: stat.hidden ? 14 : 22,
                fontWeight: FontWeight.w800,
                color: stat.hidden ? context.palette.onSurfaceMuted : _tone(context),
              ),
            ),
            if (stat.previous != null && stat.previousLabel != null)
              Text('${stat.previousLabel}: ${stat.previous}',
                  style: Theme.of(context).textTheme.bodySmall),
            if (stat.hint.isNotEmpty)
              Text(stat.hint, style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
      ),
    );
  }
}

/// A chart drawn with plain bars — no chart library: the figures are the server's, the bar only shows their size.
class _PanelCard extends StatelessWidget {
  const _PanelCard({required this.panel});

  final Map<String, dynamic> panel;

  @override
  Widget build(BuildContext context) {
    final series = panel['kind'] == 'series';
    final rows =
        ((series ? panel['points'] : panel['parts']) as List? ?? const [])
            .whereType<Map>()
            .map((e) => e.cast<String, dynamic>())
            .toList();
    if (rows.isEmpty) return const SizedBox.shrink();

    final values = [
      for (final r in rows)
        ...(series
            ? [barValue(r['first']), barValue(r['second'])]
            : [barValue(r['value'])]),
    ];
    final max = values.fold<double>(0, (m, v) => v.abs() > m ? v.abs() : m);
    // ⓘ প্রথম সারির রং থিমের primary — আলো আর অন্ধকারে দুইয়েই চোখে পড়ে (নকশার নিয়ম ০.৪.৩৮ §১)
    final first = Theme.of(context).colorScheme.primary;

    Widget bar(String text, double value, Color color) => Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Row(children: [
            Expanded(
              child: FractionallySizedBox(
                alignment: Alignment.centerLeft,
                widthFactor: max == 0 ? 0 : (value.abs() / max).clamp(0.0, 1.0),
                child: Container(height: 10, color: color),
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Text(text, style: const TextStyle(fontSize: 12)),
          ]),
        );

    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text((panel['label'] ?? '').toString(),
                style: const TextStyle(fontWeight: FontWeight.w700)),
            // ⓘ চিহ্নের রং স্তম্ভের রং — আগে দুটো ■ লেখার রঙে কালো, কোনটা কোন স্তম্ভ বোঝা যেত না (মোবাইলের নকশার তুলনা, ১৯ অক্টোবর ২০২৬)
            if (series)
              Text.rich(
                key: const ValueKey('series-legend'),
                TextSpan(style: Theme.of(context).textTheme.bodySmall, children: [
                  TextSpan(text: '■ ', style: TextStyle(color: first)),
                  TextSpan(text: '${panel['firstLabel']}   '),
                  const TextSpan(text: '■ ', style: TextStyle(color: AppColors.warning)),
                  TextSpan(text: '${panel['secondLabel']}'),
                ]),
              ),
            const SizedBox(height: AppSpacing.sm),
            for (final r in rows) ...[
              Text((r['label'] ?? '').toString(),
                  style: const TextStyle(fontSize: 12.5)),
              if (series) ...[
                bar((r['first'] ?? '').toString(), barValue(r['first']),
                    first),
                bar((r['second'] ?? '').toString(), barValue(r['second']),
                    AppColors.warning),
              ] else
                bar((r['value'] ?? '').toString(), barValue(r['value']),
                    first),
              const SizedBox(height: AppSpacing.xs),
            ],
          ],
        ),
      ),
    );
  }
}

class _ListingCard extends StatelessWidget {
  const _ListingCard({required this.listing});

  final Map<String, dynamic> listing;

  @override
  Widget build(BuildContext context) {
    final columns =
        ((listing['columns'] as List?) ?? const []).whereType<Map>().toList();
    final rows =
        ((listing['rows'] as List?) ?? const []).whereType<List>().toList();

    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text((listing['label'] ?? '').toString(),
                style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: AppSpacing.xs),
            if (rows.isEmpty)
              Text((listing['empty'] ?? '').toString(),
                  style: TextStyle(color: context.palette.onSurfaceMuted))
            else
              for (final row in rows)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
                  decoration: BoxDecoration(
                      border: Border(
                          bottom: BorderSide(
                              color: context.palette.border))),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (var i = 0; i < columns.length && i < row.length; i++)
                        if (row[i].toString().isNotEmpty)
                          Text(
                            i == 0
                                ? row[i].toString()
                                : '${columns[i]['label']}: ${row[i]}',
                            style: i == 0
                                ? const TextStyle(fontWeight: FontWeight.w600)
                                : const TextStyle(fontSize: 13),
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
