import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/books/books_api.dart';
import '../../core/orders/paper_total.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/search_debouncer.dart';
import 'books_widgets.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';

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
  late final _typed = SearchDebouncer(onSearch: (_) => _load());
  final List<PrincipalRow> _rows = [];
  int? _next;
  PaperTotal? _total;
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
    _typed.dispose();
    super.dispose();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final (rows, next, total) = await widget.api
          .principals(q: _search.text.trim(), page: more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(rows);
        _next = next;
        _total = total;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: AppLocale.l10n.booksPrincipalListFailed,
            whenAbsent: AppLocale.l10n.booksPrincipalListAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.booksPrincipalTitle),
        body: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              TextField(
                key: const ValueKey('principal-search'),
                controller: _search,
                textInputAction: TextInputAction.search,
                decoration: InputDecoration(
                    prefixIcon: const Icon(Icons.search),
                    hintText: context.l10n.booksPrincipalSearchHint),
                onChanged: _typed.onChanged,
                onSubmitted: _typed.onSubmitted,
              ),
              // ⭐ গোটা ছাঁকনির মোট দেনা — সার্ভারের, সব পাতার, ওয়েবের সরবরাহকারীর তালিকার একই যোগ; ⛔ পুরনো সার্ভারে পট্টি নেই
              // (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
              if (_total != null && _rows.isNotEmpty)
                TotalStrip(
                    label: context.l10n.booksPrincipalTotalDue,
                    value: Money.taka(_total!.sum),
                    count: _total!.count),
              if (_busy) const LinearProgressIndicator(),
              if (_error != null) BooksError(_error!),
              if (_loaded && _rows.isEmpty && !_busy)
                Padding(
                  padding: const EdgeInsets.all(AppSpacing.lg),
                  child: Center(child: Text(context.l10n.booksPrincipalNone)),
                ),
              for (final p in _rows)
                Card(
                  child: ListTile(
                    key: ValueKey('principal-${p.id}'),
                    title: CustomerLine(p.label,
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(p.lastPurchaseOn == null
                        ? context.l10n.booksPrincipalNoPurchase
                        : context.l10n.booksPrincipalLastPurchaseOn(dayOf(p.lastPurchaseOn))),
                    trailing: Text(p.balanceLabel,
                        style: TextStyle(
                            fontWeight: FontWeight.w700,
                            color: p.balance > 0
                                ? context.palette.danger
                                : context.palette.success)),
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
            () => _error = errorMessageFor(e, fallback: AppLocale.l10n.booksLedgerFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _principal;
    return Scaffold(
      appBar: AbosScreenBar(title: p?.name ?? context.l10n.booksPrincipalTitle),
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
                    FactRow(context.l10n.booksFactFullName, p.fullName),
                  if (p.phone.isNotEmpty) FactRow(context.l10n.booksFactPhone, p.phone),
                  FactRow(context.l10n.booksFactBalance, p.balanceLabel,
                      colour:
                          p.balance > 0 ? context.palette.danger : context.palette.success),
                  FactRow(context.l10n.booksFactLastPurchase,
                      p.lastPurchaseOn == null ? '—' : dayOf(p.lastPurchaseOn)),
                ]),
              ),
            ),
          if (p != null && _entries.isEmpty && !_busy)
            Padding(
              padding: const EdgeInsets.all(AppSpacing.lg),
              child: Center(child: Text(context.l10n.booksLedgerEmpty)),
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
                        style: TextStyle(
                            fontSize: 12, color: context.palette.onSurfaceMuted)),
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
