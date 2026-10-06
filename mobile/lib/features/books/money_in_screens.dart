import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/books/books_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_spacing.dart';
import 'books_widgets.dart';

/// ⭐ টাকা আদায় (Payment received) — মালিক, ৬ অক্টোবর ২০২৬।
///
/// <p>আদায়ের তালিকা: তারিখের পরিসর, গ্রাহক বা দোকান বা নম্বর খুঁজে, পদ্ধতি (নগদ · ব্যাংক · MFS · চেক), মাথায় মোট;
/// একটা চাপলে বিস্তারিত — কোন বিলে কত বসল। কেবল পড়া। SR-এর ফোনে কেবল তাঁর নিজের ডিলারের — সার্ভারের দেয়াল।
class MoneyInListScreen extends StatefulWidget {
  const MoneyInListScreen(
      {super.key, this.api = const ServerBooksApi(), this.today});

  final BooksApi api;
  final DateTime? today;

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
            fallback: 'আদায়ের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে আদায়ের তালিকা এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    return Scaffold(
      appBar: AppBar(title: const Text('টাকা আদায়')),
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
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (page != null)
              TotalStrip(
                  label: 'মোট আদায়',
                  value: Money.taka(page.total),
                  count: page.count),
            if (page != null && _rows.isEmpty && !_busy)
              const Padding(
                padding: EdgeInsets.all(AppSpacing.lg),
                child: Center(child: Text('এই সময়ে কোনো আদায় নেই।')),
              ),
            for (final row in _rows)
              Card(
                child: ListTile(
                  key: ValueKey('money-in-${row.no}'),
                  title: Text(row.customer.isEmpty ? '—' : row.customer),
                  subtitle: Text(
                      '${row.no} · ${dayOf(row.date)} · ${MoneyInRow.methodLabel(row.method)}'),
                  trailing: Text(Money.taka(row.amount),
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                          builder: (_) =>
                              MoneyInScreen(id: row.id, api: widget.api))),
                ),
              ),
            if (page?.nextPage != null)
              MoreButton(busy: _busy, onPressed: () => _load(more: true)),
          ],
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
    widget.api.moneyInDetail(widget.id).then((d) {
      if (mounted) setState(() => _detail = d);
    }).catchError((Object e) {
      if (mounted) {
        setState(
            () => _error = errorMessageFor(e, fallback: 'আদায়টা আনা গেল না।'));
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final d = _detail;
    return Scaffold(
      appBar: AppBar(title: Text(d?.row.no ?? 'আদায়')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_error != null) BooksError(_error!),
          if (d == null && _error == null) const LinearProgressIndicator(),
          if (d != null) ...[
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(children: [
                  FactRow('গ্রাহক', d.row.customer),
                  FactRow('তারিখ', dayOf(d.row.date)),
                  FactRow('টাকা', Money.taka(d.row.amount)),
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
