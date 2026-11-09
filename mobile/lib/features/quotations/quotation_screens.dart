import 'package:flutter/material.dart';

import '../../core/widgets/totals_bar.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/locale/l10n.dart';
import '../../core/orders/quotation_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../home/nav_bottom_bar.dart' show ActionBottomBar, BarFab;
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';
import '../printing/row_actions.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ উদ্ধৃতি (কোটেশন) — তালিকা, নতুন উদ্ধৃতি, আর একটা উদ্ধৃতির পাতা: জমা, পাঠানো, দোকানির উত্তর, আদেশে রূপান্তর
/// (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)। এক লাইনে এক তথ্য (মালিকের নিয়ম); কোন বোতাম দেখাবে সার্ভার বলে।
class QuotationListScreen extends StatefulWidget {
  const QuotationListScreen({super.key, this.api = const ServerQuotationApi(), this.canWrite = true});

  final QuotationApi api;

  /// `sales.quotation.create` — না থাকলে "নতুন উদ্ধৃতি" নেই।
  final bool canWrite;

  @override
  State<QuotationListScreen> createState() => _QuotationListScreenState();
}

class _QuotationListScreenState extends State<QuotationListScreen> {
  static const _tabs = [null, 'draft', 'approved', 'sent', 'accepted', 'converted'];

  String? _status;
  final List<Quotation> _rows = [];
  int? _next;
  PaperTotal? _total;
  bool _busy = false;
  bool _loaded = false;
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
      final (rows, next, total) = await widget.api.list(status: _status, page: more ? (_next ?? 1) : 1);
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
        setState(() => _error = errorMessageFor(e, fallback: context.l10n.quotationsListLoadFailed, whenAbsent: context.l10n.quotationsListNotOnServer));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(Quotation q) async {
    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => QuotationScreen(id: q.id, api: widget.api)));
    _load();
  }

  Future<void> _new() async {
    final made = await Navigator.of(context).push<Quotation>(MaterialPageRoute(builder: (_) => NewQuotationScreen(api: widget.api)));
    if (made != null && mounted) await _open(made);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.quotationsListTitle),
      bottomNavigationBar: ActionBottomBar.maybe(
          action: widget.canWrite
              ? BarFab(
                  key: const Key('quotation-new'),
                  onPressed: _new,
                  label: context.l10n.quotationsListNew,
                )
              : null),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Wrap(spacing: AppSpacing.xs, children: [
              for (final tab in _tabs)
                ChoiceChip(
                  key: Key('quotation-tab-${tab ?? 'all'}'),
                  label: Text(context.l10n.quotationsListTab(tab ?? 'all')),
                  selected: _status == tab,
                  onSelected: (_) {
                    setState(() => _status = tab);
                    _load();
                  },
                ),
            ]),
            const SizedBox(height: AppSpacing.sm),
            // ⭐ ছাঁকা তালিকার মোট — সার্ভারের, সব পাতার; বাতিল, ফেরত আর পুরনো সংস্করণ বাদ (মালিক, ১৭ অক্টোবর ২০২৬)
            if (_total != null && _rows.isNotEmpty)
              TotalStrip(
                  label: context.l10n.quotationsListTotal,
                  value: Money.taka(_total!.sum),
                  count: _total!.count,
                  // ⓘ দরপত্রের তালিকায় তারিখের ছাঁকনি নেই — সব তারিখ
                  period: const TotalsPeriod.allDates()),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) EmptyState(icon: Icons.cloud_off_outlined, title: context.l10n.quotationsListErrorTitle, message: _error),
            if (_loaded && _rows.isEmpty && _error == null) EmptyState(icon: Icons.request_quote_outlined, title: context.l10n.quotationsListEmpty),
            for (final q in _rows)
              Card(
                child: ListTile(
                  key: Key('quotation-${q.id}'),
                  onTap: () => _open(q),
                  title: Text(q.no),
                  subtitle: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (q.customer != null) Text(q.customer!),
                      Text(q.statusLabel, style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(Money.taka(q.total)),
                      // ⭐ ওয়েবের মতো "অ্যাকশন ▾" (মালিক, ৯ নভেম্বর ২০২৬: "Abos er sokol list") — দেখা; ⓘ সার্ভারে উদ্ধৃতির ছাপা কাগজ নেই,
                      //   তাই ছাপা ও পাঠানো ভাগটাই নেই
                      Align(
                        alignment: AlignmentDirectional.centerEnd,
                        child: RowActionsButton(number: q.no, state: q.statusLabel.isEmpty ? null : q.statusLabel, onView: () => _open(q)),
                      ),
                    ],
                  ),
                ),
              ),
            if (_next != null)
              OutlinedButton(
                onPressed: _busy ? null : () => _load(more: true),
                child: Text(context.l10n.quotationsListMore),
              ),
          ],
        ),
      ),
    );
  }
}

/// নতুন উদ্ধৃতি — দোকান, তারপর এক লাইনে এক পণ্য, পরিমাণ আর দর (পণ্যের দাম আগে থেকে বসানো, বদলানো যায়)।
class NewQuotationScreen extends StatefulWidget {
  const NewQuotationScreen({super.key, this.api = const ServerQuotationApi(), this.customers, this.products});

  final QuotationApi api;

  /// পরীক্ষার জন্য — না দিলে ফোনে জমা তালিকা।
  final List<CustomerRecord>? customers;
  final List<ProductRecord>? products;

  @override
  State<NewQuotationScreen> createState() => _NewQuotationScreenState();
}

class _NewQuotationScreenState extends State<NewQuotationScreen> {
  CustomerRecord? _customer;
  final List<(ProductRecord, int, double?)> _lines = [];
  final _note = TextEditingController();

  /// ⭐ এই কাজের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]], অডিট ফোন ⚠️১২)
  final _once = OnceKey();
  bool _busy = false;
  String? _error;

  late final List<CustomerRecord> _customers = widget.customers ?? CustomerRecord.all();
  late final List<ProductRecord> _products = (widget.products ?? ProductRecord.all()).where((p) => p.isActive).toList();

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<T?> _pick<T>(String title, List<T> items, String Function(T) label) => showModalBottomSheet<T>(
        context: context,
        isScrollControlled: true,
        builder: (_) => _PickSheet<T>(title: title, items: items, label: label),
      );

  Future<void> _addLine() async {
    final product = await _pick<ProductRecord>(context.l10n.quotationsNewPickProduct, _products, (p) => p.name);
    if (product == null || !mounted) return;
    final answer = await showDialog<(int, double?)>(
      context: context,
      builder: (_) => _LineDialog(title: product.name, qty: 1, rate: product.salePrice),
    );
    if (answer == null || answer.$1 <= 0) return;
    setState(() {
      _lines.removeWhere((l) => l.$1.id == product.id); // ⓘ এক পণ্য একবার — মালিকের নিয়ম
      _lines.add((product, answer.$1, answer.$2));
    });
  }

  Future<void> _save({required bool submit}) async {
    final customer = _customer;
    if (customer == null || _lines.isEmpty) {
      setState(() => _error = customer == null ? context.l10n.quotationsNewNeedShop : context.l10n.quotationsNewNeedLine);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final made = await _once.send(() => widget.api.create(
            customerId: customer.id,
            lines: [for (final l in _lines) QuotedLine(l.$1.id, l.$2, l.$3)],
            submit: submit,
            note: _note.text,
          ));
      if (mounted) Navigator.of(context).pop(made);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.quotationsNewSaveFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ দোকান বা পণ্য বা মন্তব্য লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা উদ্ধৃতি এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) =>
      UnsavedGuard(dirty: !_busy && (_customer != null || _lines.isNotEmpty || _note.text.trim().isNotEmpty), child: _screen(context));

  Widget _screen(BuildContext context) {
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.quotationsListNew),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Card(
            child: ListTile(
              key: const Key('quotation-customer'),
              leading: const Icon(Icons.storefront_outlined),
              title: Text(_customer?.label ?? context.l10n.quotationsNewPickShop),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                final picked = await _pick<CustomerRecord>(context.l10n.quotationsNewPickShop, _customers, (c) => c.label);
                if (picked != null) setState(() => _customer = picked);
              },
            ),
          ),
          for (final line in _lines)
            Card(
              child: ListTile(
                title: Text(line.$1.name),
                subtitle: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(context.l10n.quotationsQty('${line.$2}')),
                    Text(context.l10n.quotationsRate(line.$3 == null ? context.l10n.quotationsNewProductPrice : Money.taka(line.$3))),
                  ],
                ),
                trailing: IconButton(
                  icon: const Icon(Icons.close),
                  tooltip: context.l10n.quotationsNewRemoveLine,
                  onPressed: () => setState(() => _lines.remove(line)),
                ),
              ),
            ),
          OutlinedButton.icon(
            key: const Key('quotation-add-line'),
            onPressed: _addLine,
            icon: const Icon(Icons.add),
            label: Text(context.l10n.quotationsNewAddLine),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(controller: _note, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: context.l10n.quotationsNewNote)),
          const SizedBox(height: AppSpacing.md),
          if (_error != null) _ErrorCard(_error!),
          if (_busy) const LinearProgressIndicator(),
          FilledButton(
            key: const Key('quotation-submit'),
            onPressed: _busy ? null : () => _save(submit: true),
            child: Text(context.l10n.quotationsSubmit),
          ),
          const SizedBox(height: AppSpacing.xs),
          OutlinedButton(
            key: const Key('quotation-keep-draft'),
            onPressed: _busy ? null : () => _save(submit: false),
            child: Text(context.l10n.quotationsNewKeepDraft),
          ),
        ],
      ),
    );
  }
}

/// একটা উদ্ধৃতি — মাথা, লাইন, আর এই ধাপে খোলা কাজগুলো।
class QuotationScreen extends StatefulWidget {
  const QuotationScreen({super.key, required this.id, this.api = const ServerQuotationApi()});

  final String id;
  final QuotationApi api;

  @override
  State<QuotationScreen> createState() => _QuotationScreenState();
}

class _QuotationScreenState extends State<QuotationScreen> {
  Quotation? _q;
  final _reason = TextEditingController();
  bool _busy = false;
  String? _error;
  String? _done;

  @override
  void initState() {
    super.initState();
    _run(() => widget.api.show(widget.id), null);
  }

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _run(Future<Quotation> Function() work, String? doneText) async {
    setState(() {
      _busy = true;
      _error = null;
      _done = null;
    });
    try {
      final q = await work();
      if (mounted) {
        setState(() {
          _q = q;
          _done = doneText;
        });
        // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ (মালিক, ১২ অক্টোবর ২০২৬)
        RecentPapers.note(kind: 'quotation', id: widget.id, number: q.no, party: q.customer);
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.quotationsActFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _act(String action, String doneText, {String? note}) => _run(() => widget.api.act(widget.id, action, note: note), doneText);

  @override
  Widget build(BuildContext context) {
    final q = _q;
    return Scaffold(
      appBar: AbosScreenBar(title: q?.no ?? context.l10n.quotationsListTitle),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) _ErrorCard(_error!),
          if (_done != null)
            Card(
              color: context.palette.successSurface,
              child: Padding(padding: const EdgeInsets.all(AppSpacing.md), child: Text(_done!)),
            ),
          if (q != null) ...[
            Text(q.no, style: Theme.of(context).textTheme.titleLarge),
            Text(context.l10n.quotationsStatus(q.statusLabel), key: const Key('quotation-status')),
            if (q.customer != null) Text(context.l10n.quotationsShop('${q.customer}')),
            if (q.date != null) Text(context.l10n.quotationsDate('${q.date}')),
            if (q.validUntil != null) Text(context.l10n.quotationsValidUntil('${q.validUntil}')),
            Text(context.l10n.quotationsTotal(Money.taka(q.total))),
            if (q.orderNo != null) Text(context.l10n.quotationsOrder('${q.orderNo}'), key: const Key('quotation-order')),
            const SizedBox(height: AppSpacing.md),
            for (final line in q.lines)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(line.product, style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text(context.l10n.quotationsQty('${line.qty == line.qty.roundToDouble() ? line.qty.toInt() : line.qty}')),
                      Text(context.l10n.quotationsRate(Money.taka(line.rate))),
                      Text(Money.taka(line.amount)),
                    ],
                  ),
                ),
              ),
            if (q.may('submit'))
              FilledButton(
                key: const Key('quotation-do-submit'),
                onPressed: _busy ? null : () => _act('submit', context.l10n.quotationsSubmitted),
                child: Text(context.l10n.quotationsSubmit),
              ),
            if (q.may('send'))
              FilledButton(
                key: const Key('quotation-do-send'),
                onPressed: _busy ? null : () => _act('send', context.l10n.quotationsSentDone),
                child: Text(context.l10n.quotationsSend),
              ),
            if (q.may('answer')) ...[
              FilledButton(
                key: const Key('quotation-do-accept'),
                onPressed: _busy ? null : () => _act('accept', context.l10n.quotationsAcceptedDone),
                child: Text(context.l10n.quotationsAccept),
              ),
              const SizedBox(height: AppSpacing.sm),
              TextField(
                key: const Key('quotation-reason'),
                controller: _reason,
                decoration: InputDecoration(labelText: context.l10n.quotationsRejectReason),
              ),
              OutlinedButton(
                key: const Key('quotation-do-reject'),
                style: OutlinedButton.styleFrom(foregroundColor: context.palette.danger),
                onPressed: _busy
                    ? null
                    : () {
                        if (_reason.text.trim().isEmpty) {
                          setState(() => _error = context.l10n.quotationsRejectNeedReason);
                          return;
                        }
                        _act('reject', context.l10n.quotationsRejectedDone, note: _reason.text);
                      },
                child: Text(context.l10n.quotationsReject),
              ),
            ],
            if (q.may('convert'))
              FilledButton(
                key: const Key('quotation-do-convert'),
                onPressed: _busy ? null : () => _act('convert', context.l10n.quotationsConvertedDone),
                child: Text(context.l10n.quotationsConvert),
              ),
          ],
        ],
      ),
    );
  }
}

/// পরিমাণ আর দরের ছোট ঘর — নিজের controller নিজে রাখে।
class _LineDialog extends StatefulWidget {
  const _LineDialog({required this.title, required this.qty, this.rate});

  final String title;
  final int qty;
  final double? rate;

  @override
  State<_LineDialog> createState() => _LineDialogState();
}

class _LineDialogState extends State<_LineDialog> {
  late final _qty = TextEditingController(text: '${widget.qty}');
  late final _rate = TextEditingController(text: widget.rate?.toStringAsFixed(2) ?? '');

  @override
  void dispose() {
    _qty.dispose();
    _rate.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(widget.title),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
            key: const Key('quotation-qty'),
            controller: _qty,
            autofocus: true,
            keyboardType: TextInputType.number,
            decoration: InputDecoration(labelText: context.l10n.quotationsLineQty),
          ),
          TextField(
            key: const Key('quotation-rate'),
            controller: _rate,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: context.l10n.quotationsLineRate),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: Text(context.l10n.quotationsLineCancel)),
          FilledButton(
            onPressed: () => Navigator.pop(context, (int.tryParse(_qty.text.trim()) ?? 0, double.tryParse(_rate.text.trim()))),
            child: Text(context.l10n.quotationsLineOk),
          ),
        ],
      );
}

class _PickSheet<T> extends StatefulWidget {
  const _PickSheet({required this.title, required this.items, required this.label});

  final String title;
  final List<T> items;
  final String Function(T) label;

  @override
  State<_PickSheet<T>> createState() => _PickSheetState<T>();
}

class _PickSheetState<T> extends State<_PickSheet<T>> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final q = _query.trim().toLowerCase();
    final shown = q.isEmpty ? widget.items : widget.items.where((i) => widget.label(i).toLowerCase().contains(q)).toList();
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.8,
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: InputDecoration(hintText: widget.title, prefixIcon: const Icon(Icons.search)),
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
          Expanded(
            child: ListView.builder(
              itemCount: shown.length,
              itemBuilder: (_, i) => ListTile(
                title: Text(widget.label(shown[i])),
                onTap: () => Navigator.of(context).pop(shown[i]),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

class _ErrorCard extends StatelessWidget {
  const _ErrorCard(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Card(
        color: context.palette.dangerSurface,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Text(text, style: TextStyle(color: context.palette.danger)),
        ),
      );
}
