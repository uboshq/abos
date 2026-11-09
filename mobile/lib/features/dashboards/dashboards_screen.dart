import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../books/books_widgets.dart';
import '../reports/reports_screen.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/dashboard/dashboard_api.dart';
import '../../core/navigation/nav_catalog.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/web_inside/web_inside.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/row_icon.dart';
import '../../core/widgets/state_views.dart';

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
            fallback: context.l10n.salesDashboardsListLoadFailed));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final entries = _entries;

    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.salesDashboardsTitle, module: context.l10n.salesDashboardsModule),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            // ⭐ তিন অবস্থা — ভুলে "আবার চেষ্টা করুন", লোডে ধূসর খসড়া, খালিতে কাজের বোতাম (নকশা ৩, পাতা ৮–১০; a5, ২০ অক্টোবর ২০২৬)
            if (_error != null)
              ErrorState(message: _error!, onRetry: _load)
            else if (entries == null)
              const LoadingSkeleton()
            else if (entries.isEmpty)
              EmptyState(
                  icon: Icons.dashboard_outlined,
                  title: context.l10n.salesDashboardsNoneTitle,
                  message: context.l10n.salesDashboardsNoneMessage,
                  action: _RefreshButton(onPressed: _load))
            else
              for (final entry in entries)
                Card(
                  key: Key('dashboard-${entry.module}'),
                  margin: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: ListTile(
                    // ⓘ নকশার সারি: বাঁয়ে মডিউলের আইকন ৪০dp, নাম 16 w600, নিচে মলিন 13 (নকশা ৩, পাতা ৩)
                    leading: RowIcon(moduleIcon(entry.module), module: entry.module),
                    title: Text(entry.name,
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                    subtitle: entry.stat == null
                        ? null
                        : Text(
                            '${entry.stat!.label}: ${entry.stat!.hidden ? context.l10n.salesDashboardsNoKey : (entry.stat!.value ?? '—')}',
                            style: TextStyle(
                                fontSize: 13,
                                color: Theme.of(context).colorScheme.onSurfaceVariant)),
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
            errorMessageFor(error, fallback: context.l10n.salesDashboardsLoadFailed));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _dashboard;

    return Scaffold(
      appBar: AbosScreenBar(title: d?.title.isNotEmpty == true ? d!.title : widget.name, module: context.l10n.salesDashboardsModule),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০)
            if (_error != null)
              ErrorState(message: _error!, onRetry: _load)
            else if (d == null)
              const LoadingSkeleton()
            else if (d.stats.isEmpty && d.panels.isEmpty && d.listings.isEmpty)
              EmptyState(
                  icon: Icons.insights_outlined,
                  title: context.l10n.salesDashboardsEmptyTitle,
                  message: context.l10n.salesDashboardsEmptyMessage,
                  action: _RefreshButton(onPressed: _load))
            else ...[
              // ⭐ ড্যা-৭ — ওয়েবের নতুন সাজ (মালিক, ৮ নভেম্বর ২০২৬, নমুনা "ok"): রঙিন মাথা, চার রঙিন কার্ড, "নজরে রাখুন",
              // প্রথম চার্ট, "আরও অঙ্ক"। ⓘ সার্ভার রং না পাঠালে (পুরনো সার্ভার) আগের সাজ, হুবহু।
              if (d.hasNewLook) ...[
                NewLookHead(dashboard: d, name: widget.name),
                const SizedBox(height: AppSpacing.sm),
                NewLookCards(dashboard: d),
                NewLookWatch(watch: d.watch),
                for (final panel in d.panels.take(1)) _PanelCard(panel: panel),
                if (d.stats.length > 4) NewLookMore(stats: d.stats.skip(4).toList()),
                for (final panel in d.panels.skip(1)) _PanelCard(panel: panel),
              ] else ...[
                if (d.subtitle.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Text(d.subtitle,
                        style: TextStyle(color: context.palette.onSurfaceMuted)),
                  ),
                for (final stat in d.stats) _StatCard(stat: stat),
                for (final panel in d.panels) _PanelCard(panel: panel),
              ],
              for (final listing in d.listings)
                _ListingCard(listing: listing, module: widget.module),
              // ⭐ এই মডিউলের রিপোর্ট — তারিখ আর PDF সহ (মালিক, ৪ অক্টোবর ২০২৬: "Sales Account er gulo dorkar")
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm),
                child: OutlinedButton.icon(
                  key: const Key('dashboard-reports'),
                  icon: const Icon(Icons.assessment_outlined, size: 18),
                  label: Text(context.l10n.salesDashboardsReports),
                  onPressed: () =>
                      Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => ReportsScreen(
                      module: widget.module,
                      title: context.l10n.salesDashboardsReportsTitle(d.title.isNotEmpty ? d.title : widget.name),
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
            errorMessageFor(error, fallback: context.l10n.salesDashboardsOverviewLoadFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _dashboard;

    return Scaffold(
      appBar: AbosScreenBar(title: d?.title.isNotEmpty == true ? d!.title : context.l10n.salesDashboardsOverviewTitle, module: context.l10n.salesDashboardsSalesModule),
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
            // ⓘ আগের সংখ্যা থাকলে সেটাই দেখায়, উপরে সরু দাগ — প্রথমবার ধূসর খসড়া (নকশা ৩, পাতা ১০)
            if (_busy && d != null) const LinearProgressIndicator(),
            if (_error != null)
              ErrorState(message: _error!, onRetry: _load)
            else if (d == null)
              const LoadingSkeleton()
            else ...[
              if (d.periodRefused != null)
                Padding(
                  key: const Key('overview-refused'),
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Text(d.periodRefused!,
                      style: TextStyle(color: context.palette.warning)),
                ),
              if (d.subtitle.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                  child: Text(d.subtitle,
                      style: TextStyle(color: context.palette.onSurfaceMuted)),
                ),
              if (d.stats.isEmpty && d.panels.isEmpty && d.listings.isEmpty)
                EmptyState(
                    icon: Icons.lock_outline,
                    title: context.l10n.salesDashboardsOverviewNoAccess,
                    action: _RefreshButton(onPressed: _load)),
              for (final stat in d.stats) _StatCard(stat: stat),
              for (final panel in d.panels) _PanelCard(panel: panel),
              for (final listing in d.listings)
                _ListingCard(listing: listing, module: 'sales'),
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
  // ভুল থিমের error (আলোতে context.palette.danger-ই); ভালো/সাবধান অবস্থার রং, আগের মতো
  Color _tone(BuildContext context) => switch (stat.tone) {
        'good' => context.palette.success,
        'warn' => context.palette.warning,
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
                  ? context.l10n.salesDashboardsNoKeyLong
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
                  TextSpan(text: '■ ', style: TextStyle(color: context.palette.warning)),
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
                    context.palette.warning),
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

/// ⭐ মডিউলের আইকন — সব মডিউলের পাতার একই আইকন ([NavCatalog]); অচেনা মডিউলে ড্যাশবোর্ডের আইকন
IconData moduleIcon(String code) =>
    NavCatalog.moduleDef(code)?.icon ?? Icons.insights_outlined;

/// খালি পর্দার কাজের বোতাম — "নতুন করে দেখুন", ৪৮dp (নকশা ৩, পাতা ৯)
class _RefreshButton extends StatelessWidget {
  const _RefreshButton({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
        key: const ValueKey('empty-refresh'),
        style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
        onPressed: onPressed,
        icon: const Icon(Icons.refresh),
        label: Text(context.l10n.salesDashboardsRefresh),
      );
}

/// সার্ভারের টাকার লেখা — "1,23,456.00" বা "-850.00"; এমন ঘর টাকা, ডানে বসে
final _moneyCell = RegExp(r'^-?[\d,]+\.\d{2}$');

/// ⓘ সার্ভারের কলামের চাবি (`Listing::columns[].key`) — কোনটা নাম, কোনটা নম্বর, কোনটা পয়েন্ট/শাখা
const _customerKeys = {'customer', 'party', 'shop', 'dealer'};
const _supplierKeys = {'supplier', 'principal'};
const _nameKeys = {..._customerKeys, ..._supplierKeys, 'name', 'product', 'account', 'item'};
const _numberKeys = {'no', 'document_no', 'number', 'voucher_no', 'invoice_no'};
const _pointKeys = {'point', 'customer_point'};
const _branchKeys = {'branch', 'customer_branch'};

/// ⭐ ড্যাশবোর্ডের ছোট তালিকা, নকশার সারিতে (নকশা ৩, পাতা ৩): বাঁয়ে মডিউলের আইকন ৪০dp · নাম 16 w600 (গ্রাহক হলে
/// "নাম · পয়েন্ট · শাখা", [CustomerLine]) · ডানে টাকা [Money.taka] 15 w700 · নিচে "নম্বর · বাকি ঘর" 13 মলিন।
///
/// <p>⚠️ টেবিল নয়, প্রতিটা ঘর এক লাইনে (মালিক, ১ অক্টোবর ২০২৬: তাঁর পর্দায় ডান দিক কাটে)।
/// ⓘ সার্ভার `total` পাঠালে (কলামের চাবি → লেখা, `Listing::$total`) নিচে "সর্বমোট" সারি — সার্ভারের অঙ্কই, ফোন যোগ করে না।
class _ListingCard extends StatelessWidget {
  const _ListingCard({required this.listing, required this.module});

  final Map<String, dynamic> listing;
  final String module;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final columns = ((listing['columns'] as List?) ?? const [])
        .whereType<Map>()
        .map((c) => (key: (c['key'] ?? '').toString(), label: (c['label'] ?? '').toString()))
        .toList();
    final rows =
        ((listing['rows'] as List?) ?? const []).whereType<List>().toList();
    final total = listing['total'] is Map
        ? (listing['total'] as Map).map((k, v) => MapEntry(k.toString(), (v ?? '').toString()))
        : null;

    String cell(List row, int i) =>
        i < 0 || i >= row.length ? '' : (row[i] ?? '').toString().trim();
    int keyed(Set<String> keys) => columns.indexWhere((c) => keys.contains(c.key));

    // ⓘ টাকার ঘর: যে কলামের প্রতিটা ভরা ঘর টাকার লেখা (শতাংশ নয়)
    var moneyIdx = -1;
    for (var i = 0; i < columns.length && rows.isNotEmpty; i++) {
      if (columns[i].key == 'percent') continue;
      final filled = rows.map((r) => cell(r, i)).where((v) => v.isNotEmpty && v != '—');
      if (filled.isNotEmpty && filled.every(_moneyCell.hasMatch)) {
        moneyIdx = i;
        break;
      }
    }
    final numberIdx = keyed(_numberKeys);
    final pointIdx = keyed(_pointKeys);
    final branchIdx = keyed(_branchKeys);
    var nameIdx = keyed(_nameKeys);
    if (nameIdx < 0) {
      nameIdx = Iterable<int>.generate(columns.length).firstWhere(
          (i) => i != moneyIdx && i != numberIdx && i != pointIdx && i != branchIdx,
          orElse: () => -1);
    }
    final nameKey = nameIdx < 0 ? '' : columns[nameIdx].key;
    final merged = {
      nameIdx, moneyIdx, numberIdx,
      // ⓘ পয়েন্ট আর শাখা নামের লাইনে জোড়া — গ্রাহক বা সরবরাহকারীর নাম থাকলেই
      if (_customerKeys.contains(nameKey)) pointIdx,
      if (_customerKeys.contains(nameKey) || _supplierKeys.contains(nameKey)) branchIdx,
    };

    Widget nameLine(List row) {
      final name = cell(row, nameIdx);
      const style = TextStyle(fontSize: 16, fontWeight: FontWeight.w600);
      // ⭐ গ্রাহক কখনো খালি নামে নয় — "নাম · পয়েন্ট · শাখা"; সরবরাহকারী "নাম · শাখা" (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম)
      if (_customerKeys.contains(nameKey)) {
        return CustomerLine(withPoint(name, cell(row, pointIdx), cell(row, branchIdx)), style: style);
      }
      if (_supplierKeys.contains(nameKey)) {
        return CustomerLine(withPoint(name, null, cell(row, branchIdx)), style: style);
      }
      return Text(name, style: style);
    }

    String secondLine(String Function(int i) value) => [
          if (numberIdx >= 0 && value(numberIdx).isNotEmpty) value(numberIdx),
          for (var i = 0; i < columns.length; i++)
            if (!merged.contains(i) && value(i).isNotEmpty) '${columns[i].label}: ${value(i)}',
        ].join(' · ');

    Widget money(String text, {Key? key}) => Text(
          _moneyCell.hasMatch(text) ? Money.taka(barValue(text)) : text,
          key: key,
          textAlign: TextAlign.end,
          style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: cs.onSurface),
        );

    Widget row(List r, int index) {
      final second = secondLine((i) => cell(r, i));
      final amount = cell(r, moneyIdx);
      return Container(
        key: ValueKey('listing-row-$index'),
        width: double.infinity,
        // ⓘ সারি অন্তত ৭২dp, লেখা বড় হলে নিজের মাপে বাড়ে (নকশার নিয়ম §২)
        constraints: const BoxConstraints(minHeight: 72),
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
        decoration: BoxDecoration(border: Border(bottom: BorderSide(color: cs.outlineVariant))),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          RowIcon(moduleIcon(module), module: module),
          const SizedBox(width: AppSpacing.md),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              nameLine(r),
              if (second.isNotEmpty)
                Text(second, style: TextStyle(fontSize: 13, color: cs.onSurfaceVariant)),
            ]),
          ),
          if (moneyIdx >= 0 && amount.isNotEmpty) ...[
            const SizedBox(width: AppSpacing.sm),
            money(amount),
          ],
        ]),
      );
    }

    Widget totalRow(Map<String, String> t) {
      final moneyKey = moneyIdx < 0 ? null : columns[moneyIdx].key;
      final rest = [
        for (final c in columns)
          if (c.key != moneyKey && (t[c.key] ?? '').isNotEmpty) '${c.label}: ${t[c.key]}',
      ].join(' · ');
      return Container(
        key: const ValueKey('listing-total'),
        constraints: const BoxConstraints(minHeight: 48),
        padding: const EdgeInsets.only(top: AppSpacing.sm),
        decoration: BoxDecoration(border: Border(top: BorderSide(color: cs.outline, width: 2))),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(context.l10n.salesDashboardsGrandTotal, style:
 TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: cs.onSurface)),
              if (rest.isNotEmpty) Text(rest, style: TextStyle(fontSize: 13, color: cs.onSurfaceVariant)),
            ]),
          ),
          if (moneyKey != null && (t[moneyKey] ?? '').isNotEmpty) ...[
            const SizedBox(width: AppSpacing.sm),
            money(t[moneyKey]!, key: const ValueKey('listing-total-money')),
          ],
        ]),
      );
    }

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
            else ...[
              for (var i = 0; i < rows.length; i++) row(rows[i], i),
              if (total != null && total.isNotEmpty) totalRow(total),
            ],
          ],
        ),
      ),
    );
  }
}


/// ⭐ ড্যা-৭ — ওয়েবের `--color-pal-*` বারো রং, হুবহু (tokens.css; সাদা লেখা ৪.৫ঃ১)। নাম না চিনলে মডিউলের নিজের রং।
const Map<String, Color> dashboardPalette = {
  'blue': Color(0xFF1D4ED8),
  'green': Color(0xFF15803D),
  'orange': Color(0xFFC2410C),
  'purple': Color(0xFF6D28D9),
  'teal': Color(0xFF0F766E),
  'red': Color(0xFFB91C1C),
  'yellow': Color(0xFFA16207),
  'pink': Color(0xFFBE185D),
  'sky': Color(0xFF0369A1),
  'indigo': Color(0xFF4338CA),
  'lime': Color(0xFF4D7C0F),
  'amber': Color(0xFFB45309),
};

Color paletteColour(String? name, Color fallback) => dashboardPalette[name] ?? fallback;

/// ওয়েবের পূর্ণ ঠিকানা থেকে অ্যাপের পথ — নিজের পর্দা থাকলে সেটা, নইলে ভেতরের ওয়েব-জানালা ([[NativeForWeb]])
void openDashboardLink(BuildContext context, String? href) {
  if (href == null || href.isEmpty) return;
  final uri = Uri.tryParse(href);
  if (uri == null) return;
  final path = uri.path.startsWith('/') ? uri.path.substring(1) : uri.path;
  final web = uri.hasQuery ? '$path?${uri.query}' : path;
  context.push('/home/${WebPaths.navPathFor(web)}');
}

/// রঙিন মাথা — নাম, পাশে বিবরণ, নিচে দ্রুত কাজের বোতাম
class NewLookHead extends StatelessWidget {
  const NewLookHead({super.key, required this.dashboard, required this.name});

  final ModuleDashboard dashboard;
  final String name;

  @override
  Widget build(BuildContext context) {
    final head = dashboard.headColours;
    final own = AppColors.moduleColor(dashboard.payload['module']?.toString() ?? '');
    final a = paletteColour(head.isNotEmpty ? head[0] : null, own);
    final b = paletteColour(head.length > 1 ? head[1] : null, a);

    return Container(
      key: const Key('dashboard-head'),
      padding: const EdgeInsets.all(AppSpacing.md),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(14),
        gradient: LinearGradient(colors: [a, b]),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(dashboard.title.isNotEmpty ? dashboard.title : name,
              style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w700)), // dark-ok: white on the module colour head (web ড্যা-৭)
          if (dashboard.subtitle.isNotEmpty)
            Text(dashboard.subtitle, style: const TextStyle(color: Colors.white70, fontSize: 13)), // dark-ok: on the module colour head
          if (dashboard.tiles.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final tile in dashboard.tiles)
                  ActionChip(
                    key: Key('dashboard-tile-${tile.label}'),
                    label: Text(tile.label, style: TextStyle(color: a, fontWeight: FontWeight.w600)),
                    backgroundColor: Colors.white, // dark-ok: white quick-button chip on the coloured head, text in the module colour
                    side: BorderSide.none,
                    onPressed: () => openDashboardLink(context, tile.href),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

/// উপরের চার কার্ড — ওয়েবের রঙে, দুই সারিতে; চাপলে পেছনের তালিকা
class NewLookCards extends StatelessWidget {
  const NewLookCards({super.key, required this.dashboard});

  final ModuleDashboard dashboard;

  @override
  Widget build(BuildContext context) {
    final top = dashboard.stats.take(4).toList();
    final colours = dashboard.cardColours;

    return GridView.count(
      key: const Key('dashboard-cards'),
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 8,
      crossAxisSpacing: 8,
      childAspectRatio: 1.55,
      children: [
        for (var i = 0; i < top.length; i++)
          Material(
            color: paletteColour(i < colours.length ? colours[i] : null, AppColors.primary),
            borderRadius: BorderRadius.circular(12),
            child: InkWell(
              borderRadius: BorderRadius.circular(12),
              onTap: top[i].href == null ? null : () => openDashboardLink(context, top[i].href),
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Text(top[i].label, maxLines: 1, overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: Colors.white70, fontSize: 12)), // dark-ok: on the coloured figure card
                    Text(top[i].hidden ? context.l10n.salesDashboardsNoKey : (top[i].value ?? ''),
                        maxLines: 1, overflow: TextOverflow.ellipsis,
                        style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w700)), // dark-ok: on the coloured figure card
                    if (top[i].hint.isNotEmpty)
                      Text(top[i].hint, maxLines: 1, overflow: TextOverflow.ellipsis,
                          style: const TextStyle(color: Colors.white70, fontSize: 11)), // dark-ok: on the coloured figure card
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// "নজরে রাখুন" — লাল এখনই, হলুদ নজর; কিছু না থাকলে সবুজ "সব ঠিক আছে"
class NewLookWatch extends StatelessWidget {
  const NewLookWatch({super.key, required this.watch});

  final List<DashboardWatch> watch;

  @override
  Widget build(BuildContext context) {
    Color surface(String tone) => switch (tone) {
          'bad' => context.palette.dangerSurface,
          'warn' => context.palette.warningSurface,
          _ => context.palette.surface,
        };

    return Card(
      key: const Key('dashboard-watch'),
      margin: const EdgeInsets.only(top: AppSpacing.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
            child: Text(context.l10n.dashboardWatchTitle, style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
          if (watch.isEmpty)
            Container(
              color: context.palette.successSurface,
              padding: const EdgeInsets.all(12),
              child: Text(context.l10n.dashboardWatchAllClear,
                  style: TextStyle(color: context.palette.success, fontWeight: FontWeight.w600)),
            ),
          for (final row in watch)
            InkWell(
              onTap: row.href == null ? null : () => openDashboardLink(context, row.href),
              child: Container(
                color: surface(row.tone),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                child: Row(
                  children: [
                    Expanded(child: Text(row.label)),
                    Text(row.value, style: const TextStyle(fontWeight: FontWeight.w700)),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// "আরও অঙ্ক" — উপরের চারটার বাইরের সব অঙ্ক, একটাও হারায় না (ওয়েবের পরিকল্পনা §৪.৫)
class NewLookMore extends StatelessWidget {
  const NewLookMore({super.key, required this.stats});

  final List<DashboardStat> stats;

  @override
  Widget build(BuildContext context) {
    return Card(
      key: const Key('dashboard-more'),
      margin: const EdgeInsets.only(top: AppSpacing.sm),
      child: ExpansionTile(
        title: Text(context.l10n.dashboardMoreFigures, style: const TextStyle(fontWeight: FontWeight.w700)),
        children: [for (final stat in stats) _StatCard(stat: stat)],
      ),
    );
  }
}
