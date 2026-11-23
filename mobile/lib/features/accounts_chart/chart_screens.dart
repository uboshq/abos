import 'package:flutter/material.dart';

import '../../core/accounts/account_ledger_api.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/locale/l10n.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/search_debouncer.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ হিসাবের ছক, ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay"*।
///
/// <p>প্রতিটা খাত তার কোড, নাম আর জের (Dr/Cr) নিয়ে, গাছের ধাপে একটু ভেতরে সরে; চাপলে খাতের খাতা। ⓘ জের ওয়েবের ছকের একই সংখ্যা।
class ChartOfAccountsScreen extends StatefulWidget {
  const ChartOfAccountsScreen({super.key, this.api = const ServerAccountLedgerApi()});

  final AccountLedgerApi api;

  @override
  State<ChartOfAccountsScreen> createState() => _ChartOfAccountsScreenState();
}

class _ChartOfAccountsScreenState extends State<ChartOfAccountsScreen> {
  final _search = TextEditingController();
  late final _typed = SearchDebouncer(onSearch: (_) => _load());
  List<ChartAccount>? _rows;
  String? _error;
  bool _busy = false;

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
      final rows = await widget.api.chart(q: _search.text);
      if (mounted) setState(() => _rows = rows);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.accountsChartLoadFailed, whenAbsent: context.l10n.accountsChartNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows ?? const <ChartAccount>[];
    final searching = _search.text.trim().isNotEmpty;

    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.accountsChartTitle),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            TextField(
              key: const ValueKey('chart-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: context.l10n.accountsChartSearchHint),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
            const SizedBox(height: AppSpacing.sm),
            if (_rows != null && rows.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Text(context.l10n.accountsChartCount(rows.length),
                    key: const ValueKey('chart-count'),
                    style: TextStyle(fontWeight: FontWeight.w600, color: context.palette.onSurfaceMuted)),
              ),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: context.l10n.accountsChartFetchFailed, message: _error)
            else if (_rows != null && rows.isEmpty)
              EmptyState(icon: Icons.search_off, title: context.l10n.accountsChartNoMatch),
            for (final a in rows)
              Card(
                margin: EdgeInsets.only(bottom: AppSpacing.xs, left: searching ? 0 : (a.depth * 12.0).clamp(0, 48)),
                child: ListTile(
                  key: ValueKey('chart-${a.id}'),
                  dense: true,
                  leading: Icon(a.isGroup ? Icons.folder_outlined : Icons.description_outlined,
                      color: a.isGroup ? AppColors.primary : context.palette.onSurfaceMuted),
                  title: Text('${a.code} · ${a.name}',
                      style: TextStyle(fontWeight: a.isGroup ? FontWeight.w700 : FontWeight.w500)),
                  trailing: Text(a.balance, style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => AccountLedgerScreen(id: a.id, name: '${a.code} · ${a.name}', api: widget.api),
                  )),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// ⭐ একটা খাতের খাতা — তারিখ, কাগজ, বিবরণ, ডেবিট, ক্রেডিট আর চলমান জের; উপরে সব পাতার ডেবিট, ক্রেডিট আর শেষ জের।
///
/// <p>⚠️ এক সারি এক কার্ডে, টেবিলে নয় — ফোনের পর্দা ডান দিক কেটে দেয় (মালিক, ১ অক্টোবর ২০২৬)।
class AccountLedgerScreen extends StatefulWidget {
  const AccountLedgerScreen({super.key, required this.id, required this.name, this.api = const ServerAccountLedgerApi()});

  final String id;
  final String name;
  final AccountLedgerApi api;

  @override
  State<AccountLedgerScreen> createState() => _AccountLedgerScreenState();
}

class _AccountLedgerScreenState extends State<AccountLedgerScreen> {
  AccountLedgerPage? _page;
  final List<LedgerLine> _rows = [];
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.ledger(widget.id, page: more ? (_page?.nextPage ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(page.entries);
        _page = page;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: context.l10n.accountsChartLedgerLoadFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _page;

    return Scaffold(
      appBar: AbosScreenBar(title: widget.name),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (p != null) _LedgerTotals(page: p),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) EmptyState(icon: Icons.cloud_off_outlined, title: context.l10n.accountsChartFetchFailed, message: _error),
            if (p != null && p.isGroup)
              Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(context.l10n.accountsChartGroupAccount,
                    style: TextStyle(color: context.palette.onSurfaceMuted)),
              )
            else if (p != null && _rows.isEmpty && !_busy)
              EmptyState(icon: Icons.receipt_long_outlined, title: context.l10n.accountsChartNoEntries),
            for (final line in _rows) _LedgerCard(line: line),
            if (p?.nextPage != null)
              OutlinedButton(
                key: const ValueKey('ledger-more'),
                onPressed: _busy ? null : () => _load(more: true),
                child: Text(context.l10n.accountsChartMoreEntries),
              ),
          ],
        ),
      ),
    );
  }
}

class _LedgerTotals extends StatelessWidget {
  const _LedgerTotals({required this.page});

  final AccountLedgerPage page;

  @override
  Widget build(BuildContext context) {
    Widget cell(String label, String value, {bool strong = false}) => Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: TextStyle(fontSize: 12, color: context.palette.onSurfaceMuted)),
            Text(value, style: TextStyle(fontWeight: strong ? FontWeight.w800 : FontWeight.w700, fontSize: strong ? 15 : 14)),
          ]),
        );

    return Card(
      key: const ValueKey('ledger-totals'),
      color: context.palette.successSurface,
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(context.l10n.accountsChartLedgerTotal(page.count), style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: AppSpacing.xs),
          Row(children: [
            cell(context.l10n.accountsChartDebit, page.totalDebit),
            cell(context.l10n.accountsChartCredit, page.totalCredit),
            cell(context.l10n.accountsChartClosing, page.closing, strong: true),
          ]),
        ]),
      ),
    );
  }
}

class _LedgerCard extends StatelessWidget {
  const _LedgerCard({required this.line});

  final LedgerLine line;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.sm),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(
              child: Text([line.date, if (line.documentNo.isNotEmpty) line.documentNo].join(' · '),
                  style: const TextStyle(fontWeight: FontWeight.w600)),
            ),
            Text(line.balance, style: const TextStyle(fontWeight: FontWeight.w700)),
          ]),
          if (line.narration.isNotEmpty)
            Text(line.narration, style: TextStyle(fontSize: 13, color: context.palette.onSurfaceMuted)),
          Row(children: [
            if (line.debit.isNotEmpty) Text(context.l10n.accountsChartDebitLine(line.debit), style: const TextStyle(fontSize: 13)),
            if (line.debit.isNotEmpty && line.credit.isNotEmpty) const SizedBox(width: AppSpacing.md),
            if (line.credit.isNotEmpty) Text(context.l10n.accountsChartCreditLine(line.credit), style: const TextStyle(fontSize: 13)),
          ]),
        ]),
      ),
    );
  }
}
