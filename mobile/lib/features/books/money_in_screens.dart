import 'package:flutter/material.dart';

import '../../core/navigation/recent_papers.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/books/books_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/row_icon.dart';
import '../../core/widgets/state_views.dart';
import '../../core/widgets/totals_bar.dart';
import '../printing/document_actions_sheet.dart';
import 'books_widgets.dart';
import 'collect_screen.dart';

/// ⭐ টাকা আদায় (Payment received) — মালিক, ৬ অক্টোবর ২০২৬।
///
/// <p>আদায়ের তালিকা: তারিখের পরিসর, গ্রাহক বা দোকান বা নম্বর খুঁজে, পদ্ধতি (নগদ · ব্যাংক · MFS · চেক), মাথায় মোট;
/// একটা চাপলে বিস্তারিত — কোন বিলে কত বসল। কেবল পড়া। SR-এর ফোনে কেবল তাঁর নিজের ডিলারের — সার্ভারের দেয়াল।
class MoneyInListScreen extends StatefulWidget {
  const MoneyInListScreen(
      {super.key,
      this.api = const ServerBooksApi(),
      this.today,
      this.canCollect = false});

  final BooksApi api;
  final DateTime? today;

  /// ⭐ অফিসের লোক — "নতুন আদায়" বোতাম (`/me`-র `mayCollect`, ৭ অক্টোবর ২০২৬)
  final bool canCollect;

  @override
  State<MoneyInListScreen> createState() => _MoneyInListScreenState();
}

class _MoneyInListScreenState extends State<MoneyInListScreen> {
  late DateTime _to;
  late DateTime _from;
  String? _method;
  final _search = TextEditingController();

  final List<MoneyInRow> _rows = [];
  MoneyInPage? _page;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _to = DateTime(now.year, now.month, now.day);
    _from = DateTime(now.year, now.month);
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.moneyIn(
        from: isoOf(_from),
        to: isoOf(_to),
        q: _search.text.trim(),
        method: _method,
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
            fallback: 'আদায়ের তালিকা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে আদায়ের তালিকা এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    // ⭐ এই পাতা = এ পর্যন্ত আনা সারি; সর্বমোট = সার্ভারের গোটা ছাঁকনির `count` আর `total` (নকশা ৩, পাতা ৩)
    final shownSum = _rows.fold<double>(0, (sum, r) => sum + r.amount);
    return Scaffold(
      appBar: AppBar(title: const Text('টাকা আদায়')),
      floatingActionButton: widget.canCollect
          ? FloatingActionButton.extended(
              key: const ValueKey('money-in-new'),
              onPressed: _collect,
              icon: const Icon(Icons.add),
              label: const Text('নতুন আদায়'),
            )
          : null,
      bottomNavigationBar: page == null || _rows.isEmpty
          ? null
          : TotalsBar(pageCount: _rows.length, pageSum: shownSum, allCount: page.count, allSum: page.total),
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
            TextField(
              key: const ValueKey('money-in-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'গ্রাহক, দোকান বা নম্বর খুঁজুন'),
              onSubmitted: (_) => _load(),
            ),
            const SizedBox(height: AppSpacing.xs),
            Wrap(spacing: AppSpacing.xs, children: [
              ChoiceChip(
                key: const ValueKey('method-all'),
                label: const Text('সব'),
                selected: _method == null,
                onSelected: (_) {
                  setState(() => _method = null);
                  _load();
                },
              ),
              for (final m in MoneyInRow.methods)
                ChoiceChip(
                  key: ValueKey('method-$m'),
                  label: Text(MoneyInRow.methodLabel(m)),
                  selected: _method == m,
                  onSelected: (_) {
                    setState(() => _method = m);
                    _load();
                  },
                ),
            ]),
            // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): প্রথম আনায় খসড়া সারি, পরে উপরে সরু দাগ; ভুলে "আবার চেষ্টা করুন"; খালিতে কাজের বোতাম
            if (_busy && page == null) const LoadingSkeleton(),
            if (_busy && page != null) const LinearProgressIndicator(),
            if (_error != null)
              ErrorState(key: const ValueKey('money-in-error'), message: _error!, onRetry: _busy ? null : _load),
            if (page != null && _rows.isEmpty && !_busy && _error == null)
              EmptyState(
                key: const ValueKey('money-in-empty'),
                icon: Icons.savings_outlined,
                title: 'এই সময়ে কোনো আদায় নেই',
                message: 'তারিখ বা পদ্ধতি বদলে দেখুন।',
                // ⓘ অফিসের লোক সোজা আদায় নিতে পারেন; বাকিরা নতুন করে আনেন
                action: widget.canCollect
                    ? FilledButton.icon(
                        key: const ValueKey('money-in-empty-collect'),
                        style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
                        onPressed: _collect,
                        icon: const Icon(Icons.add),
                        label: const Text('আদায় নিন'))
                    : OutlinedButton.icon(
                        key: const ValueKey('money-in-empty-refresh'),
                        style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48)),
                        onPressed: _load,
                        icon: const Icon(Icons.refresh),
                        label: const Text('নতুন করে দেখুন')),
              ),
            for (final row in _rows)
              DesignListRow(
                key: ValueKey('money-in-${row.no}'),
                icon: Icons.south_west,
                tone: AppColors.success,
                name: row.customer.isEmpty ? '—' : row.customer,
                // ⓘ দ্বিতীয় লাইনে নম্বর · তারিখ · পদ্ধতি — তালিকা কেবল পাকা আদায়ের (সার্ভার POSTED ছাঁকে), তাই আলাদা অবস্থার চিপ নেই
                second: '${row.no} · ${dayOf(row.date)} · ${MoneyInRow.methodLabel(row.method)}',
                amount: row.amount,
                onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
                    builder: (_) => MoneyInScreen(id: row.id, api: widget.api))),
              ),
            if (page?.nextPage != null)
              MoreButton(busy: _busy, onPressed: () => _load(more: true)),
          ],
        ),
      ),
    );
  }

  Future<void> _collect() async {
    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => CollectScreen(books: widget.api)));
    if (mounted) await _load();
  }
}

/// ⭐ নকশা ৩-এর তালিকার সারি (নকশার নিয়ম ০.৪.৩৮ §৪, a4, ১৯ অক্টোবর ২০২৬):
///
/// ```
/// [RowIcon 40dp]  নাম · পয়েন্ট · শাখা (16 w600)        ৳48,500.00 (15 w700)
///                 নম্বর · তারিখ/ধরন (13, onSurfaceVariant)      [চিপ]
/// ```
/// পুরো সারিতে চাপলে খোলে; সারি অন্তত ৭২dp; বড় অক্ষরে (২০০%) নিজের মাপে বাড়ে — ডানের টাকা সারির ৪৫%-এর বেশি নেয় না,
/// লম্বা হলে মোড়ে, ছাপিয়ে ভাঙে না। রং কেবল `colorScheme` থেকে।
/// ⚠️ a5-এর আদায়, বকেয়া, কাউন্টারের খসড়া আর ফেরতের বিল একই সারি ব্যবহার করে — a4 `core/widgets`-এ তুললে এখান থেকে সরবে।
class DesignListRow extends StatelessWidget {
  const DesignListRow({
    super.key,
    required this.icon,
    this.tone,
    required this.name,
    required this.second,
    required this.amount,
    this.amountColor,
    this.pill,
    this.onTap,
  });

  final IconData icon;
  final Color? tone;

  /// গ্রাহক হলে "নাম · পয়েন্ট · শাখা" (`CustomerRecord.label` বা সার্ভারের `withPoint` লেখা)
  final String name;

  /// দ্বিতীয় লাইন — কাগজের নম্বর আগে
  final String second;
  final num amount;
  final Color? amountColor;
  final Widget? pill;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Card(
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 72),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            child: LayoutBuilder(
              builder: (context, box) => Row(children: [
                RowIcon(icon, tone: tone),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      CustomerLine(name,
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: cs.onSurface)),
                      const SizedBox(height: 2),
                      Text(second, style: TextStyle(fontSize: 13, color: cs.onSurfaceVariant)),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                ConstrainedBox(
                  constraints: BoxConstraints(maxWidth: box.maxWidth * 0.45),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(Money.taka(amount),
                          textAlign: TextAlign.end,
                          style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: amountColor ?? cs.onSurface)),
                      if (pill != null) ...[const SizedBox(height: 4), pill!],
                    ],
                  ),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// একটা আদায় — কে দিল, কোন খাতে, কোন পদ্ধতিতে, কোন বিলে কত
class MoneyInScreen extends StatefulWidget {
  const MoneyInScreen(
      {super.key, required this.id, this.api = const ServerBooksApi()});

  final String id;
  final BooksApi api;

  @override
  State<MoneyInScreen> createState() => _MoneyInScreenState();
}

class _MoneyInScreenState extends State<MoneyInScreen> {
  MoneyInDetail? _detail;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// ⭐ আবার চেষ্টাও এখান থেকেই (নকশা ৩, পাতা ৮) — আগে ভুল হলে পর্দা ফেরার পথ ছাড়া কিছু দিত না
  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await widget.api.moneyInDetail(widget.id);
      if (mounted) setState(() => _detail = d);
      // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ (মালিক, ১২ অক্টোবর ২০২৬)
      RecentPapers.note(kind: 'money-in', id: widget.id, number: d.row.no, party: d.row.customer);
    } catch (e) {
      if (mounted) {
        setState(
            () => _error = errorMessageFor(e, fallback: 'আদায়টা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।'));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = _detail;
    return Scaffold(
      appBar: AppBar(title: Text(d?.row.no ?? 'আদায়'), actions: [
        // ⭐ টাকার রসিদ ছাপা — নরমাল বা থার্মাল (মালিক, ১৪ অক্টোবর ২০২৬)
        if (d != null)
          IconButton(
            key: const ValueKey('money-in-print'),
            tooltip: 'ছাপুন / পাঠান',
            icon: const Icon(Icons.print_outlined),
            onPressed: () => DocumentActionsSheet.show(context,
                type: 'Collection', id: widget.id, title: 'টাকার রসিদ · ${d.row.no}', fileStem: d.row.no),
          ),
      ]),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_error != null) ErrorState(key: const ValueKey('money-in-detail-error'), message: _error!, onRetry: _load),
          if (d == null && _error == null) const LoadingSkeleton(rows: 3),
          if (d != null) ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(children: [
                  FactRow('গ্রাহক', d.row.customer),
                  FactRow('তারিখ', dayOf(d.row.date)),
                  FactRow('টাকা', Money.taka(d.row.amount)),
                  if (d.statusLabel.isNotEmpty) FactRow('অবস্থা', d.statusLabel),
                  FactRow('পদ্ধতি', MoneyInRow.methodLabel(d.row.method)),
                  FactRow('খাত', d.row.account),
                  if (d.instrumentNo.isNotEmpty)
                    FactRow('নম্বর', d.instrumentNo),
                  if (d.instrumentDate != null)
                    FactRow('কাগজের তারিখ', dayOf(d.instrumentDate)),
                  if (d.narration.isNotEmpty) FactRow('বিবরণ', d.narration),
                  if (d.by.isNotEmpty) FactRow('লিখেছেন', d.by),
                ]),
              ),
            ),
            if (d.lines.isNotEmpty) ...[
              const Padding(
                padding:
                    EdgeInsets.only(top: AppSpacing.md, bottom: AppSpacing.xs),
                child: Text('কোন বিলে কত',
                    style: TextStyle(fontWeight: FontWeight.w700)),
              ),
              for (final (invoice, amount) in d.lines)
                Card(
                  child: ListTile(
                    title: Text(invoice.isEmpty ? 'অগ্রিম' : invoice),
                    trailing: Text(Money.taka(amount)),
                  ),
                ),
            ],
          ],
        ],
      ),
    );
  }
}
