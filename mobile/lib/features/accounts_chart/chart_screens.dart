import 'package:flutter/material.dart';

import '../../core/accounts/account_ledger_api.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

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
            fallback: 'হিসাবের ছক আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ফোনের হিসাবের ছক এখনো আসেনি — অফিসে জানান।'));
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
      appBar: AppBar(title: const Text('হিসাবের ছক')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            TextField(
              key: const ValueKey('chart-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'কোড বা নাম দিয়ে খুঁজুন'),
              onSubmitted: (_) => _load(),
            ),
            const SizedBox(height: AppSpacing.sm),
            if (_rows != null && rows.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Text('মোট ${rows.length} টি হিসাব',
                    key: const ValueKey('chart-count'),
                    style: const TextStyle(fontWeight: FontWeight.w600, color: AppColors.onSurfaceMuted)),
              ),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: 'আনা গেল না', message: _error)
            else if (_rows != null && rows.isEmpty)
              const EmptyState(icon: Icons.search_off, title: 'কোনো হিসাব মেলেনি'),
            for (final a in rows)
              Card(
                margin: EdgeInsets.only(bottom: AppSpacing.xs, left: searching ? 0 : (a.depth * 12.0).clamp(0, 48)),
                child: ListTile(
                  key: ValueKey('chart-${a.id}'),
                  dense: true,
                  leading: Icon(a.isGroup ? Icons.folder_outlined : Icons.description_outlined,
                      color: a.isGroup ? AppColors.primary : AppColors.onSurfaceMuted),
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
        setState(() => _error = errorMessageFor(e, fallback: 'খাতা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _page;

    return Scaffold(
      appBar: AppBar(title: Text(widget.name)),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (p != null) _LedgerTotals(page: p),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) EmptyState(icon: Icons.cloud_off_outlined, title: 'আনা গেল না', message: _error),
            if (p != null && p.isGroup)
              const Padding(
                padding: EdgeInsets.all(AppSpacing.md),
                child: Text('এটা দলের হিসাব — এন্ট্রি এর নিচের হিসাবগুলোতে। ছকে ফিরে নিচের হিসাব খুলুন।',
                    style: TextStyle(color: AppColors.onSurfaceMuted)),
              )
            else if (p != null && _rows.isEmpty && !_busy)
              const EmptyState(icon: Icons.receipt_long_outlined, title: 'এই হিসাবে কোনো এন্ট্রি নেই'),
            for (final line in _rows) _LedgerCard(line: line),
            if (p?.nextPage != null)
              OutlinedButton(
                key: const ValueKey('ledger-more'),
                onPressed: _busy ? null : () => _load(more: true),
                child: const Text('আরও এন্ট্রি'),
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
            Text(label, style: const TextStyle(fontSize: 12, color: AppColors.onSurfaceMuted)),
            Text(value, style: TextStyle(fontWeight: strong ? FontWeight.w800 : FontWeight.w700, fontSize: strong ? 15 : 14)),
          ]),
        );

    return Card(
      key: const ValueKey('ledger-totals'),
      color: AppColors.successSurface,
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('সর্বমোট · ${page.count} টি এন্ট্রি', style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: AppSpacing.xs),
          Row(children: [
            cell('ডেবিট', page.totalDebit),
            cell('ক্রেডিট', page.totalCredit),
            cell('শেষ জের', page.closing, strong: true),
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
            Text(line.narration, style: const TextStyle(fontSize: 13, color: AppColors.onSurfaceMuted)),
          Row(children: [
            if (line.debit.isNotEmpty) Text('ডেবিট ${line.debit}', style: const TextStyle(fontSize: 13)),
            if (line.debit.isNotEmpty && line.credit.isNotEmpty) const SizedBox(width: AppSpacing.md),
            if (line.credit.isNotEmpty) Text('ক্রেডিট ${line.credit}', style: const TextStyle(fontSize: 13)),
          ]),
        ]),
      ),
    );
  }
}
