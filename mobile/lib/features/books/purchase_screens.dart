import 'package:flutter/material.dart';

import '../../core/navigation/recent_papers.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/books/books_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../printing/document_actions_sheet.dart';
import 'books_widgets.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

/// ⭐ ক্রয়ের তালিকা — মালিক, ৬ অক্টোবর ২০২৬।
///
/// <p>ক্রয় বিল (সরাসরি ক্রয়ও বিল) আর মাল গ্রহণ: তারিখ, প্রিন্সিপাল, মোট, আর বিলে পরিশোধিত ও বাকি; ছাঁকনি তারিখ আর
/// প্রিন্সিপাল; চাপলে লাইনসহ বিস্তারিত। ⛔ কেনা দর কেবল খরচ দেখার চাবিতে — সার্ভার না পাঠালে ঘরটাই নেই। কেবল পড়া।
class PurchaseListScreen extends StatefulWidget {
  const PurchaseListScreen(
      {super.key,
      this.api = const ServerBooksApi(),
      this.today,
      this.canSeeReceipts = true});

  final BooksApi api;
  final DateTime? today;

  /// মাল গ্রহণ দেখার চাবি (`purchase.receipt.view`) — না থাকলে কেবল বিল
  final bool canSeeReceipts;

  @override
  State<PurchaseListScreen> createState() => _PurchaseListScreenState();
}

class _PurchaseListScreenState extends State<PurchaseListScreen> {
  String _kind = 'bill';
  late DateTime _to;
  late DateTime _from;
  PrincipalRow? _principal;

  final List<PurchaseRow> _rows = [];
  PurchasePage? _page;
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

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.purchases(
        kind: _kind,
        from: isoOf(_from),
        to: isoOf(_to),
        principal: _principal?.id,
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
            fallback: context.l10n.booksPurchaseListFailed,
            whenAbsent: context.l10n.booksPurchaseListAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickPrincipal() async {
    List<PrincipalRow> all;
    try {
      all = (await widget.api.principals()).$1;
    } catch (e) {
      if (mounted) {
        setState(() => _error =
            errorMessageFor(e, fallback: context.l10n.booksPurchasePrincipalsFailed));
      }
      return;
    }
    if (!mounted) return;
    final picked = await showModalBottomSheet<PrincipalRow?>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => SafeArea(
        child: ListView(
          key: const ValueKey('purchase-principal-sheet'),
          shrinkWrap: true,
          children: [
            ListTile(
                title: Text(context.l10n.booksPurchaseAllPrincipals),
                onTap: () => Navigator.pop(
                    sheet, const PrincipalRow(id: '', name: '', balance: 0))),
            for (final p in all)
              ListTile(
                  title: Text(p.name), onTap: () => Navigator.pop(sheet, p)),
          ],
        ),
      ),
    );
    if (picked == null || !mounted) return;
    setState(() => _principal = picked.id.isEmpty ? null : picked);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.booksPurchaseTitle),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (widget.canSeeReceipts)
              Wrap(spacing: AppSpacing.xs, children: [
                for (final (kind, label) in [
                  ('bill', context.l10n.booksPurchaseBill),
                  ('receipt', context.l10n.booksPurchaseGoodsReceipt)
                ])
                  ChoiceChip(
                    key: ValueKey('purchase-kind-$kind'),
                    label: Text(label),
                    selected: _kind == kind,
                    onSelected: (_) {
                      setState(() => _kind = kind);
                      _load();
                    },
                  ),
              ]),
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
            ActionChip(
              key: const ValueKey('purchase-principal'),
              avatar: const Icon(Icons.business_outlined, size: 18),
              label: Text(_principal?.name ?? context.l10n.booksPurchaseAllPrincipals),
              onPressed: _pickPrincipal,
            ),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (page != null)
              TotalStrip(
                  label: _kind == 'bill' ? context.l10n.booksPurchaseTotalBills : context.l10n.booksPurchaseTotalReceipts,
                  // ⓘ দামের চাবি ছাড়া অঙ্ক আসে না — কেবল কয়টা
                  value: page.total == null ? '—' : Money.taka(page.total),
                  count: page.count),
            if (page != null && _rows.isEmpty && !_busy)
              Padding(
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Center(child: Text(context.l10n.booksPurchaseEmpty)),
              ),
            for (final row in _rows)
              Card(
                child: ListTile(
                  key: ValueKey('purchase-${row.no}'),
                  title: Text(row.principal.isEmpty ? '—' : row.principal,
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text([
                    '${row.no} · ${dayOf(row.date)} · ${row.statusLabel}',
                    if (row.paid != null)
                      context.l10n.booksPurchasePaidDue(Money.taka(row.paid), Money.taka(row.due)),
                  ].join('\n')),
                  isThreeLine: row.paid != null,
                  trailing: row.total == null
                      ? null
                      : Text(Money.taka(row.total),
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => Navigator.of(context).push(
                      MaterialPageRoute<void>(
                          builder: (_) => PurchaseScreen(
                              kind: row.kind, id: row.id, api: widget.api))),
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

/// একটা ক্রয় বিল বা মাল গ্রহণ — লাইনসহ; কেনা দর কেবল যখন সার্ভার পাঠায়
class PurchaseScreen extends StatefulWidget {
  const PurchaseScreen(
      {super.key,
      required this.kind,
      required this.id,
      this.api = const ServerBooksApi()});

  final String kind;
  final String id;
  final BooksApi api;

  @override
  State<PurchaseScreen> createState() => _PurchaseScreenState();
}

class _PurchaseScreenState extends State<PurchaseScreen> {
  PurchaseDetail? _detail;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.api.purchase(widget.kind, widget.id).then((d) {
      if (mounted) setState(() => _detail = d);
      // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ (মালিক, ১২ অক্টোবর ২০২৬)
      RecentPapers.note(
          kind: 'purchase', id: widget.id, number: d.row.no, extra: widget.kind, party: d.row.principal);
    }).catchError((Object e) {
      if (mounted) {
        setState(
            () => _error = errorMessageFor(e, fallback: context.l10n.booksPurchaseFailed));
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final d = _detail;
    return Scaffold(
      appBar: AbosScreenBar(
          title: d?.row.no ??
              (widget.kind == 'bill' ? context.l10n.booksPurchaseBill : context.l10n.booksPurchaseGoodsReceipt),
          actions: [
            // ⭐ ছাপা — নরমাল বা থার্মাল (মালিক, ১৪ অক্টোবর ২০২৬); দাম ঢাকা-না-ঢাকা সার্ভারের কাগজই ঠিক করে
            if (d != null)
              IconButton(
                key: const ValueKey('purchase-print'),
                tooltip: context.l10n.booksPrintSend,
                icon: const Icon(Icons.print_outlined),
                onPressed: () => DocumentActionsSheet.show(context,
                    type: widget.kind == 'bill' ? 'PurchaseBill' : 'PurchaseReceipt',
                    id: widget.id,
                    title: context.l10n.booksPurchasePrintTitle(widget.kind == 'bill' ? context.l10n.booksPurchaseBill : context.l10n.booksPurchaseGoodsReceipt, d.row.no),
                    fileStem: d.row.no),
              ),
          ]),
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
                  FactRow(context.l10n.booksFactPrincipal, d.row.principal),
                  FactRow(context.l10n.booksFactDate, dayOf(d.row.date)),
                  FactRow(context.l10n.booksFactStatus, d.row.statusLabel),
                  if (d.supplierNo.isNotEmpty)
                    FactRow(context.l10n.booksFactSupplierNo, d.supplierNo),
                  if (d.row.total != null) FactRow(context.l10n.booksFactTotal, Money.taka(d.row.total)),
                  if (d.row.paid != null)
                    FactRow(context.l10n.booksFactPaid, Money.taka(d.row.paid)),
                  if (d.row.due != null)
                    FactRow(context.l10n.booksFactDue, Money.taka(d.row.due),
                        colour: (d.row.due ?? 0) > 0 ? context.palette.danger : null),
                  if (d.narration.isNotEmpty) FactRow(context.l10n.booksFactNarration, d.narration),
                ]),
              ),
            ),
            Padding(
              padding:
                  const EdgeInsets.only(top: AppSpacing.md, bottom: AppSpacing.xs),
              child:
                  Text(context.l10n.booksPurchaseProducts, style: const TextStyle(fontWeight: FontWeight.w700)),
            ),
            for (final l in d.lines)
              Card(
                child: ListTile(
                  title: Text(l.product),
                  subtitle: Text([
                    context.l10n.booksPurchaseQty(Money.plain(l.qty)),
                    if (l.free > 0) context.l10n.booksPurchaseFree(Money.plain(l.free)),
                    if (l.batch.isNotEmpty) context.l10n.booksPurchaseLot(l.batch),
                    if (l.rate != null) context.l10n.booksPurchaseRate(Money.taka(l.rate)),
                  ].join(' · ')),
                  trailing:
                      l.amount == null ? null : Text(Money.taka(l.amount)),
                ),
              ),
          ],
        ],
      ),
    );
  }
}
