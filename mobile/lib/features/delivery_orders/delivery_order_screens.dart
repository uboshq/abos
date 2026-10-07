import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/orders/delivery_order_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// ডেলিভারি অর্ডার (DO) — তালিকা, নতুন DO, আর একটা DO-র পাতা (0.4.8, মালিকের বিক্রয়-ধারা §২ক-খ)।
///
/// <p>ⓘ মালিকের নিয়ম: এক লাইনে এক জিনিস, টেবিল নয়। দাম পণ্যের — ফোনে দামের ঘর নেই। সুপারভাইজার এই পাতাতেই
/// পরিমাণ কমিয়ে সই দেন; সইটা অনুমোদন-বাক্সের একই দরজায় যায়।
final _taka = NumberFormat.decimalPattern('en_IN');

String _qty(double v) => v == v.roundToDouble() ? v.toInt().toString() : v.toString();

/// ⭐ কাগজের নাম — কোম্পানি বিক্রয় আদেশে চলে গেলে একই পর্দা "আদেশ" বলে (DO+SO মেশানো, ধাপ ১০)।
String _paper(DeliveryOrderApi api) => api.forOrders ? 'আদেশ' : 'DO';

String _title(DeliveryOrderApi api) => api.forOrders ? 'বিক্রয় আদেশ' : 'ডেলিভারি অর্ডার';

class DeliveryOrderListScreen extends StatefulWidget {
  const DeliveryOrderListScreen({super.key, this.api = const ServerDeliveryOrderApi(), this.canWrite = true});

  final DeliveryOrderApi api;

  /// `sales.do.create` (আদেশে `sales.order.create`) — না থাকলে "নতুন" বোতাম নেই।
  final bool canWrite;

  @override
  State<DeliveryOrderListScreen> createState() => _DeliveryOrderListScreenState();
}

class _DeliveryOrderListScreenState extends State<DeliveryOrderListScreen> {
  bool _awaitingMe = false;
  List<DeliveryOrder>? _rows;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final rows = await widget.api.list(awaitingMe: _awaitingMe);
      if (mounted) setState(() => _rows = rows);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ${_title(widget.api)} এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(DeliveryOrder order) async {
    await Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => DeliveryOrderScreen(id: order.id, api: widget.api),
    ));
    _load();
  }

  Future<void> _new() async {
    final made = await Navigator.of(context).push<DeliveryOrder>(MaterialPageRoute(
      builder: (_) => NewDeliveryOrderScreen(api: widget.api),
    ));
    if (made != null && mounted) {
      await _open(made);
    }
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows ?? const <DeliveryOrder>[];
    return Scaffold(
      appBar: AppBar(title: Text(_title(widget.api))),
      floatingActionButton: widget.canWrite
          ? FloatingActionButton.extended(
              key: const ValueKey('do-new'),
              onPressed: _new,
              icon: const Icon(Icons.add),
              label: Text('নতুন ${_paper(widget.api)}'),
            )
          : null,
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Wrap(spacing: AppSpacing.xs, children: [
              ChoiceChip(
                label: const Text('সব'),
                selected: !_awaitingMe,
                onSelected: (_) {
                  setState(() => _awaitingMe = false);
                  _load();
                },
              ),
              ChoiceChip(
                key: const ValueKey('do-awaiting-me'),
                label: const Text('আমার সইয়ের অপেক্ষায়'),
                selected: _awaitingMe,
                onSelected: (_) {
                  setState(() => _awaitingMe = true);
                  _load();
                },
              ),
            ]),
            const SizedBox(height: AppSpacing.sm),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) _ErrorCard(_error!),
            if (_rows != null && rows.isEmpty && !_busy)
              Padding(
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Text('কোনো ${_paper(widget.api)} নেই।', textAlign: TextAlign.center),
              ),
            for (final order in rows)
              Card(
                child: ListTile(
                  onTap: () => _open(order),
                  title: Text(order.no),
                  subtitle: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      if (order.customer != null) Text(order.customer!),
                      // ⭐ ওয়েবের চিপ — ব্যাক অর্ডার, সীমায় আটকে, চালান আর বিল (সমন্বয়ক, ৬ অক্টোবর ২০২৬)
                      if (order.chips.isEmpty) Text(order.statusLabel) else OrderChips(order.chips),
                      Text('৳ ${_taka.format(order.total)}'),
                    ],
                  ),
                  trailing: order.awaitingMe ? const Icon(Icons.draw_outlined, color: AppColors.warning) : null,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// নতুন DO — ডিলার, তারপর এক লাইনে এক পণ্য আর পরিমাণ।
class NewDeliveryOrderScreen extends StatefulWidget {
  const NewDeliveryOrderScreen({super.key, this.api = const ServerDeliveryOrderApi(), this.customers, this.products});

  final DeliveryOrderApi api;

  /// পরীক্ষার জন্য — না দিলে ফোনে জমা তালিকা ([[CustomerRecord.all]], [[ProductRecord.all]])।
  final List<CustomerRecord>? customers;
  final List<ProductRecord>? products;

  @override
  State<NewDeliveryOrderScreen> createState() => _NewDeliveryOrderScreenState();
}

class _NewDeliveryOrderScreenState extends State<NewDeliveryOrderScreen> {
  CustomerRecord? _customer;
  final List<(ProductRecord, int)> _lines = [];
  final _note = TextEditingController();
  /// ⭐ এই কাজের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]], অডিট ফোন ⚠️১২)
  final _once = OnceKey();
  bool _busy = false;
  String? _error;

  late final List<CustomerRecord> _customers = widget.customers ?? CustomerRecord.all();
  late final List<ProductRecord> _products =
      (widget.products ?? ProductRecord.all()).where((p) => p.isActive).toList();

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
    final qty = await _askQty(product.name, 1);
    if (qty == null || qty <= 0) return;
    setState(() {
      _lines.removeWhere((l) => l.$1.id == product.id); // ⓘ এক পণ্য একবার — মালিকের নিয়ম
      _lines.add((product, qty));
    });
  }

  Future<int?> _askQty(String title, int initial) =>
      showDialog<int>(context: context, builder: (_) => _QtyDialog(title: title, initial: initial));

  Future<void> _save({required bool submit}) async {
    final customer = _customer;
    if (customer == null || _lines.isEmpty) {
      setState(() => _error = customer == null ? 'ডিলার বাছুন।' : 'অন্তত একটা পণ্য দিন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final made = await _once.send(() => widget.api.create(
        customerId: customer.id,
        lines: [for (final l in _lines) WantedLine(l.$1.id, l.$2)],
        submit: submit,
        note: _note.text,
      ));
      if (mounted) Navigator.of(context).pop(made);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: '${_paper(widget.api)} রাখা গেল না। আবার চেষ্টা করুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('নতুন ${_paper(widget.api)}')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Card(
            child: ListTile(
              key: const ValueKey('do-customer'),
              leading: const Icon(Icons.storefront_outlined),
              title: Text(_customer?.name ?? 'ডিলার বাছুন'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                final picked = await _pick<CustomerRecord>('ডিলার বাছুন', _customers, (c) => c.name);
                if (picked != null) setState(() => _customer = picked);
              },
            ),
          ),
          for (final line in _lines)
            Card(
              child: ListTile(
                title: Text(line.$1.name),
                subtitle: Text('পরিমাণ: ${line.$2}'),
                trailing: IconButton(
                  icon: const Icon(Icons.close),
                  tooltip: 'বাদ দিন',
                  onPressed: () => setState(() => _lines.remove(line)),
                ),
                onTap: () async {
                  final qty = await _askQty(line.$1.name, line.$2);
                  if (qty != null && qty > 0) {
                    setState(() => _lines[_lines.indexOf(line)] = (line.$1, qty));
                  }
                },
              ),
            ),
          OutlinedButton.icon(
            key: const ValueKey('do-add-line'),
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
            key: const ValueKey('do-submit'),
            onPressed: _busy ? null : () => _save(submit: true),
            child: const Text('জমা দিন'),
          ),
          const SizedBox(height: AppSpacing.xs),
          OutlinedButton(
            key: const ValueKey('do-keep-draft'),
            onPressed: _busy ? null : () => _save(submit: false),
            child: const Text('খসড়া রাখুন'),
          ),
        ],
      ),
    );
  }
}

/// একটা DO — মাথা, লাইন, আর যার হাতে যে কাজ।
class DeliveryOrderScreen extends StatefulWidget {
  const DeliveryOrderScreen({super.key, required this.id, this.api = const ServerDeliveryOrderApi()});

  final String id;
  final DeliveryOrderApi api;

  @override
  State<DeliveryOrderScreen> createState() => _DeliveryOrderScreenState();
}

class _DeliveryOrderScreenState extends State<DeliveryOrderScreen> {
  DeliveryOrder? _order;
  final Map<int, TextEditingController> _boxes = {};
  final _reason = TextEditingController();
  bool _busy = false;
  String? _error;
  String? _done;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _boxes.values) {
      c.dispose();
    }
    _reason.dispose();
    super.dispose();
  }

  void _take(DeliveryOrder order) {
    _order = order;
    for (final line in order.lines) {
      (_boxes[line.id] ??= TextEditingController()).text = _qty(line.finalQty);
    }
  }

  Future<void> _run(Future<DeliveryOrder?> Function() work, String doneText) async {
    setState(() {
      _busy = true;
      _error = null;
      _done = null;
    });
    try {
      final next = await work();
      final fresh = next ?? await widget.api.show(widget.id);
      if (mounted) {
        setState(() {
          _take(fresh);
          _done = doneText;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'কাজটা হলো না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _load() async {
    setState(() => _busy = true);
    try {
      final order = await widget.api.show(widget.id);
      if (mounted) setState(() => _take(order));
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: '${_paper(widget.api)} আনা গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Map<int, int> _wanted() => {
        for (final e in _boxes.entries) e.key: int.tryParse(e.value.text.trim()) ?? -1,
      };

  @override
  Widget build(BuildContext context) {
    final order = _order;
    return Scaffold(
      appBar: AppBar(title: Text(order?.no ?? _title(widget.api))),
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
          if (order != null) ...[
            Text(order.no, style: Theme.of(context).textTheme.titleLarge),
            Text('অবস্থা: ${order.statusLabel}', key: const ValueKey('do-status')),
            if (order.customer != null) Text('ডিলার: ${order.customer}'),
            if (order.date != null) Text('তারিখ: ${order.date}'),
            Text('মোট: ৳ ${_taka.format(order.total)}'),
            // ⓘ বিক্রয় আদেশ বাকির সীমায় আটকে — লেখক দেখেন কেন থেমে আছে; টাকা এলে সার্ভার নিজেই আবার দেখে
            if (order.creditShort != null)
              Text('বাকির সীমায় আটকে — কম ৳ ${_taka.format(order.creditShort)}',
                  key: const ValueKey('do-credit-short'), style: const TextStyle(color: AppColors.danger)),
            if (order.chips.isNotEmpty) OrderChips(order.chips),
            // ⭐ মজুদ এখন — ধরা হয়নি: আছে, চাই, কম (ওয়েবের আদেশের পাতার একই উৎস)
            if (order.stockNow.isNotEmpty) _StockNowCard(order.stockNow),
            const SizedBox(height: AppSpacing.md),
            for (final line in order.lines)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(line.product, style: const TextStyle(fontWeight: FontWeight.w600)),
                      Text('চাওয়া: ${_qty(line.qty)}'),
                      if (order.awaitingMe)
                        TextField(
                          key: ValueKey('do-line-${line.id}'),
                          controller: _boxes[line.id],
                          keyboardType: TextInputType.number,
                          decoration: const InputDecoration(labelText: 'অনুমোদিত পরিমাণ'),
                        )
                      else if (line.approvedQty != null)
                        Text('অনুমোদিত: ${_qty(line.approvedQty!)}'),
                      Text('৳ ${_taka.format(line.lineTotal)}'),
                    ],
                  ),
                ),
              ),
            if (order.editable)
              FilledButton(
                key: const ValueKey('do-send'),
                onPressed: _busy ? null : () => _run(() => widget.api.submit(order.id), '${_paper(widget.api)} জমা হলো — সুপারভাইজার দেখবেন।'),
                child: const Text('জমা দিন'),
              ),
            if (order.awaitingMe && order.approvalId != null) ...[
              OutlinedButton(
                key: const ValueKey('do-save-qty'),
                onPressed: _busy ? null : () => _run(() => widget.api.setQuantities(order.id, _wanted()), 'অনুমোদিত পরিমাণ রাখা হলো।'),
                child: const Text('পরিমাণ রাখুন'),
              ),
              const SizedBox(height: AppSpacing.xs),
              FilledButton(
                key: const ValueKey('do-approve'),
                onPressed: _busy
                    ? null
                    : () => _run(() async {
                          // ⓘ আগে ঘরের পরিমাণ রাখা, তারপর সই — যা দেখছেন তাতেই সই
                          await widget.api.setQuantities(order.id, _wanted());
                          await widget.api.approve(order.approvalId!);
                          return null;
                        }, 'অনুমোদন দেওয়া হলো।'),
                child: const Text('অনুমোদন দিন'),
              ),
              const SizedBox(height: AppSpacing.sm),
              TextField(
                key: const ValueKey('do-reason'),
                controller: _reason,
                decoration: const InputDecoration(labelText: 'ফেরতের কারণ'),
              ),
              OutlinedButton(
                key: const ValueKey('do-reject'),
                style: OutlinedButton.styleFrom(foregroundColor: AppColors.danger),
                onPressed: _busy
                    ? null
                    : () {
                        if (_reason.text.trim().isEmpty) {
                          setState(() => _error = 'ফেরতের কারণ লিখুন।');
                          return;
                        }
                        _run(() async {
                          await widget.api.reject(order.approvalId!, _reason.text);
                          return null;
                        }, '${_paper(widget.api)} ফেরত পাঠানো হলো।');
                      },
                child: const Text('ফেরত পাঠান'),
              ),
            ],
          ],
        ],
      ),
    );
  }
}

/// পরিমাণের ছোট ঘর — নিজের controller নিজে রাখে, ডায়ালগ পুরো বন্ধ হলে তবেই ছাড়ে।
class _QtyDialog extends StatefulWidget {
  const _QtyDialog({required this.title, required this.initial});

  final String title;
  final int initial;

  @override
  State<_QtyDialog> createState() => _QtyDialogState();
}

class _QtyDialogState extends State<_QtyDialog> {
  late final _controller = TextEditingController(text: '${widget.initial}');

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: Text(widget.title),
        content: TextField(
          key: const ValueKey('do-qty'),
          controller: _controller,
          autofocus: true,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'পরিমাণ'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('বাদ')),
          FilledButton(
            onPressed: () => Navigator.pop(context, int.tryParse(_controller.text.trim())),
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
              key: const ValueKey('pick-search'),
              autofocus: false,
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

/// ওয়েবের চিপের মতো — ছোট গোল ব্যাজ, রং সার্ভারের নামে
class OrderChips extends StatelessWidget {
  const OrderChips(this.chips, {super.key});

  final List<OrderChip> chips;

  static (Color, Color) colours(String tone) => switch (tone) {
        'success' => (AppColors.successSurface, AppColors.success),
        'warning' => (AppColors.warningSurface, AppColors.warning),
        'danger' => (AppColors.dangerSurface, AppColors.danger),
        'info' || 'pending' => (AppColors.pendingSurface, AppColors.pending),
        _ => (AppColors.surfaceMuted, AppColors.onSurfaceMuted),
      };

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
        child: Wrap(
          spacing: AppSpacing.xs,
          runSpacing: AppSpacing.xs,
          children: [
            for (final c in chips)
              Container(
                key: ValueKey('order-chip-${c.key}'),
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm, vertical: 2),
                decoration: BoxDecoration(
                    color: colours(c.tone).$1, borderRadius: BorderRadius.circular(12)),
                child: Text(c.label,
                    style: TextStyle(fontSize: 12, color: colours(c.tone).$2, fontWeight: FontWeight.w600)),
              ),
          ],
        ),
      );
}

class _StockNowCard extends StatelessWidget {
  const _StockNowCard(this.rows);

  final List<OrderStockNow> rows;

  @override
  Widget build(BuildContext context) => Card(
        key: const ValueKey('order-stock-now'),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('মজুদ এখন — ধরা হয়নি', style: TextStyle(fontWeight: FontWeight.w700)),
              const Text('মাল আটকায় ডিপোর চালান নিশ্চিত হলে; এখানে কেবল কত আছে আর কত চাই।',
                  style: TextStyle(color: AppColors.onSurfaceMuted)),
              for (final r in rows) ...[
                const SizedBox(height: AppSpacing.xs),
                Text(r.product, style: const TextStyle(fontWeight: FontWeight.w600)),
                Text(r.have != null ? 'আছে ${_qty(r.have!)} · চাই ${_qty(r.want)}' : 'চাই ${_qty(r.want)}'),
                if (!r.enough)
                  Text(r.short != null ? 'কম ${_qty(r.short!)}' : 'মজুদে কুলোয় না',
                      style: const TextStyle(color: AppColors.danger, fontWeight: FontWeight.w600)),
              ],
            ],
          ),
        ),
      );
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
