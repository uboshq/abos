import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/books/books_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import 'books_widgets.dart';

/// ⭐ প্রিন্সিপালের তালিকা — মালিক, ৬ অক্টোবর ২০২৬।
///
/// <p>VENDOR ধরনের সরবরাহকারী, সংক্ষিপ্ত নামে; প্রতিটায় জের কথায় ("দিতে হবে: …" / "পাব: …" — ওয়েবের সরবরাহকারীর
/// পাতার একই সংখ্যা, মাথায় বাছা শাখায়) আর শেষ ক্রয়ের দিন; চাপলে তার খাতা, নতুন আগে। কেবল পড়া।
class PrincipalListScreen extends StatefulWidget {
  const PrincipalListScreen({super.key, this.api = const ServerBooksApi()});

  final BooksApi api;

  @override
  State<PrincipalListScreen> createState() => _PrincipalListScreenState();
}

class _PrincipalListScreenState extends State<PrincipalListScreen> {
  final _search = TextEditingController();
  final List<PrincipalRow> _rows = [];
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
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (rows, next) = await widget.api
          .principals(q: _search.text.trim(), page: more ? (_next ?? 1) : 1);
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
            fallback:
                'প্রিন্সিপালের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent:
                'সার্ভারে প্রিন্সিপালের তালিকা এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('প্রিন্সিপাল')),
        body: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              TextField(
                key: const ValueKey('principal-search'),
                controller: _search,
                textInputAction: TextInputAction.search,
                decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search),
                    hintText: 'নাম বা কোড খুঁজুন'),
                onSubmitted: (_) => _load(),
              ),
              if (_busy) const LinearProgressIndicator(),
              if (_error != null) BooksError(_error!),
              if (_loaded && _rows.isEmpty && !_busy)
                const Padding(
                  padding: EdgeInsets.all(AppSpacing.lg),
                  child: Center(child: Text('কোনো প্রিন্সিপাল নেই।')),
                ),
              for (final p in _rows)
                Card(
                  child: ListTile(
                    key: ValueKey('principal-${p.id}'),
                    title: Text(p.name,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(p.lastPurchaseOn == null
                        ? 'এখনো কোনো ক্রয় নেই'
                        : 'শেষ ক্রয়: ${dayOf(p.lastPurchaseOn)}'),
                    trailing: Text(p.balanceLabel,
                        style: TextStyle(
                            fontWeight: FontWeight.w700,
                            color: p.balance > 0
                                ? AppColors.danger
                                : AppColors.success)),
                    onTap: () => Navigator.of(context).push(
                        MaterialPageRoute<void>(
                            builder: (_) => PrincipalLedgerScreen(
                                id: p.id, api: widget.api))),
                  ),
                ),
              if (_next != null)
                MoreButton(busy: _busy, onPressed: () => _load(more: true)),
            ],
          ),
        ),
      );
}

/// একজন প্রিন্সিপালের খাতা — মাথায় জের, নিচে সারি (নতুন আগে), প্রতিটার পরে চলমান জের
class PrincipalLedgerScreen extends StatefulWidget {
  const PrincipalLedgerScreen(
      {super.key, required this.id, this.api = const ServerBooksApi()});

  final String id;
  final BooksApi api;

  @override
  State<PrincipalLedgerScreen> createState() => _PrincipalLedgerScreenState();
}

class _PrincipalLedgerScreenState extends State<PrincipalLedgerScreen> {
  PrincipalRow? _principal;
  final List<LedgerLine> _entries = [];
  int? _next;
  bool _busy = false;
  String? _error;

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
      final ledger =
          await widget.api.principal(widget.id, page: more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        _principal = ledger.principal;
        if (!more) _entries.clear();
        _entries.addAll(ledger.entries);
        _next = ledger.nextPage;
      });
    } catch (e) {
      if (mounted) {
        setState(
            () => _error = errorMessageFor(e, fallback: 'খাতা আনা গেল না।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _principal;
    return Scaffold(
      appBar: AppBar(title: Text(p?.name ?? 'প্রিন্সিপাল')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) BooksError(_error!),
          if (p != null)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(children: [
                  if (p.fullName.isNotEmpty && p.fullName != p.name)
                    FactRow('পুরো নাম', p.fullName),
                  if (p.phone.isNotEmpty) FactRow('ফোন', p.phone),
                  FactRow('জের', p.balanceLabel,
                      colour:
                          p.balance > 0 ? AppColors.danger : AppColors.success),
                  FactRow('শেষ ক্রয়',
                      p.lastPurchaseOn == null ? '—' : dayOf(p.lastPurchaseOn)),
                ]),
              ),
            ),
          if (p != null && _entries.isEmpty && !_busy)
            const Padding(
              padding: EdgeInsets.all(AppSpacing.lg),
              child: Center(child: Text('খাতায় কোনো লেনদেন নেই।')),
            ),
          for (final e in _entries)
            Card(
              child: ListTile(
                title: Text(
                    [dayOf(e.date), if (e.no.isNotEmpty) e.no].join(' · ')),
                subtitle: e.narration.isEmpty
                    ? null
                    : Text(e.narration,
                        maxLines: 2, overflow: TextOverflow.ellipsis),
                trailing: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                        e.credit > 0
                            ? '+${Money.taka(e.credit)}'
                            : '−${Money.taka(e.debit)}',
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text(PrincipalRow.balanceWords(e.balance),
                        style: const TextStyle(
                            fontSize: 12, color: AppColors.onSurfaceMuted)),
                  ],
                ),
              ),
            ),
          if (_next != null)
            MoreButton(busy: _busy, onPressed: () => _load(more: true)),
        ],
      ),
    );
  }
}
