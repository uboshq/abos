import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/orders/quotation_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

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
  static const _tabs = {
    null: 'সব',
    'draft': 'খসড়া',
    'approved': 'অনুমোদিত',
    'sent': 'পাঠানো',
    'accepted': 'গৃহীত',
    'converted': 'আদেশ হয়েছে',
  };

  String? _status;
  final List<Quotation> _rows = [];
  int? _next;
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
      final (rows, next) = await widget.api.list(status: _status, page: more ? (_next ?? 1) : 1);
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
            fallback: 'উদ্ধৃতি আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে উদ্ধৃতির দরজা এখনো আসেনি — অফিসে জানান।'));
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
      appBar: AppBar(title: const Text('উদ্ধৃতি')),
      floatingActionButton: widget.canWrite
          ? FloatingActionButton.extended(
              key: const Key('quotation-new'),
              onPressed: _new,
              icon: const Icon(Icons.add),
              label: const Text('নতুন উদ্ধৃতি'),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Wrap(spacing: AppSpacing.xs, children: [
              for (final tab in _tabs.entries)
                ChoiceChip(
                  key: Key('quotation-tab-${tab.key ?? 'all'}'),
                  label: Text(tab.value),
                  selected: _status == tab.key,
                  onSelected: (_) {
                    setState(() => _status = tab.key);
                    _load();
                  },
                ),
            ]),
            const SizedBox(height: AppSpacing.sm),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) EmptyState(icon: Icons.cloud_off_outlined, title: 'আনা গেল না', message: _error),
            if (_loaded && _rows.isEmpty && _error == null)
              const EmptyState(icon: Icons.request_quote_outlined, title: 'কোনো উদ্ধৃতি নেই'),
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
                    ],
                  ),
                  trailing: const Icon(Icons.chevron_right),
                ),
              ),
            if (_next != null)
              OutlinedButton(
                onPressed: _busy ? null : () => _load(more: true),
                child: const Text('আরও উদ্ধৃতি'),
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
    final product = await _pick<ProductRecord>('পণ্য বাছুন', _products, (p) => p.name);
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
      setState(() => _error = customer == null ? 'দোকান বাছুন।' : 'অন্তত একটা পণ্য দিন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final made = await widget.api.create(
        customerId: customer.id,
        lines: [for (final l in _lines) QuotedLine(l.$1.id, l.$2, l.$3)],
        submit: submit,
        note: _note.text,
      );
      if (mounted) Navigator.of(context).pop(made);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'উদ্ধৃতি রাখা গেল না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('নতুন উদ্ধৃতি')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Card(
            child: ListTile(
              key: const Key('quotation-customer'),
              leading: const Icon(Icons.storefront_outlined),
              title: Text(_customer?.name ?? 'দোকান বাছুন'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                final picked = await _pick<CustomerRecord>('দোকান বাছুন', _customers, (c) => c.name);
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
                    Text('পরিমাণ: ${line.$2}'),
                    Text('দর: ${line.$3 == null ? 'পণ্যের দাম' : Money.taka(line.$3)}'),
                  ],
                ),
                trailing: IconButton(
                  icon: const Icon(Icons.close),
                  tooltip: 'বাদ দিন',
                  onPressed: () => setState(() => _lines.remove(line)),
                ),
              ),
            ),
          OutlinedButton.icon(
            key: const Key('quotation-add-line'),
            onPressed: _addLine,
            icon: const Icon(Icons.add),
            label: const Text('পণ্য যোগ করুন'),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(controller: _note, decoration: const InputDecoration(labelText: 'মন্তব্য (ঐচ্ছিক)')),
          const SizedBox(height: AppSpacing.md),
          if (_error != null) _ErrorCard(_error!),
          if (_busy) const LinearProgressIndicator(),
          FilledButton(
            key: const Key('quotation-submit'),
            onPressed: _busy ? null : () => _save(submit: true),
            child: const Text('জমা দিন'),
          ),
          const SizedBox(height: AppSpacing.xs),
          OutlinedButton(
            key: const Key('quotation-keep-draft'),
            onPressed: _busy ? null : () => _save(submit: false),
            child: const Text('খসড়া রাখুন'),
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
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'কাজটা হলো না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _act(String action, String doneText, {String? note}) =>
      _run(() => widget.api.act(widget.id, action, note: note), doneText);

  @override
  Widget build(BuildContext context) {
    final q = _q;
    return Scaffold(
      appBar: AppBar(title: Text(q?.no ?? 'উদ্ধৃতি')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) _ErrorCard(_error!),
          if (_done != null)
            Card(
              color: AppColors.successSurface,
              child: Padding(padding: const EdgeInsets.all(AppSpacing.md), child: Text(_done!)),
            ),
          if (q != null) ...[
            Text(q.no, style: Theme.of(context).textTheme.titleLarge),
            Text('অবস্থা: ${q.statusLabel}', key: const Key('quotation-status')),
            if (q.customer != null) Text('দোকান: ${q.customer}'),
            if (q.date != null) Text('তারিখ: ${q.date}'),
            if (q.validUntil != null) Text('মেয়াদ: ${q.validUntil}'),
            Text('মোট: ${Money.taka(q.total)}'),
            if (q.orderNo != null) Text('আদেশ: ${q.orderNo}', key: const Key('quotation-order')),
            const SizedBox(height: AppSpacing.md),
            for (final line in q.lines)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(line.product, style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text('পরিমাণ: ${line.qty == line.qty.roundToDouble() ? line.qty.toInt() : line.qty}'),
                      Text('দর: ${Money.taka(line.rate)}'),
                      Text(Money.taka(line.amount)),
                    ],
                  ),
                ),
              ),
            if (q.may('submit'))
              FilledButton(
                key: const Key('quotation-do-submit'),
                onPressed: _busy ? null : () => _act('submit', 'জমা হলো।'),
                child: const Text('জমা দিন'),
              ),
            if (q.may('send'))
              FilledButton(
                key: const Key('quotation-do-send'),
                onPressed: _busy ? null : () => _act('send', 'দোকানিকে পাঠানো হলো।'),
                child: const Text('দোকানিকে পাঠানো হলো'),
              ),
            if (q.may('answer')) ...[
              FilledButton(
                key: const Key('quotation-do-accept'),
                onPressed: _busy ? null : () => _act('accept', 'দোকানি রাজি — এখন আদেশ বানানো যায়।'),
                child: const Text('দোকানি রাজি'),
              ),
              const SizedBox(height: AppSpacing.sm),
              TextField(
                key: const Key('quotation-reason'),
                controller: _reason,
                decoration: const InputDecoration(labelText: 'রাজি না হওয়ার কারণ'),
              ),
              OutlinedButton(
                key: const Key('quotation-do-reject'),
                style: OutlinedButton.styleFrom(foregroundColor: AppColors.danger),
                onPressed: _busy
                    ? null
                    : () {
                        if (_reason.text.trim().isEmpty) {
                          setState(() => _error = 'রাজি না হওয়ার কারণ লিখুন।');
                          return;
                        }
                        _act('reject', 'দোকানি রাজি নন — লেখা হলো।', note: _reason.text);
                      },
                child: const Text('দোকানি রাজি নন'),
              ),
            ],
            if (q.may('convert'))
              FilledButton(
                key: const Key('quotation-do-convert'),
                onPressed: _busy ? null : () => _act('convert', 'বিক্রয় আদেশ হলো।'),
                child: const Text('আদেশ বানান'),
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
            decoration: const InputDecoration(labelText: 'পরিমাণ'),
          ),
          TextField(
            key: const Key('quotation-rate'),
            controller: _rate,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'দর'),
          ),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('বাদ')),
          FilledButton(
            onPressed: () => Navigator.pop(context, (int.tryParse(_qty.text.trim()) ?? 0, double.tryParse(_rate.text.trim()))),
            child: const Text('ঠিক আছে'),
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
        color: AppColors.dangerSurface,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Text(text, style: const TextStyle(color: AppColors.danger)),
        ),
      );
}
