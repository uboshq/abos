import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/orders/direct_sale_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// সরাসরি বিক্রয়ের কাউন্টার — ফোনে (0.4.9, মালিক ৪ অক্টোবর ২০২৬: *"direct sales er counter banaw app e"*)।
///
/// <p>⭐ মালিকের কাউন্টারের নিয়ম ([[owner-direct-sale-screen-requirements]]):
/// কার্টের সারি কেবল পড়ার — বদলাতে "সম্পাদনা" চাপলে সারিটা উপরের ঘরে ফেরে, বোতাম "হালনাগাদ করুন";
/// এক পণ্য-লট একবারই; শূন্য দর কার্টে ঢোকে না; অনুমোদন ও ঋণসীমার বার্তা পপ-আপে; "খসড়া রাখুন" ধূসর।
/// <p>⛔ সব দেয়াল সার্ভারের (ওয়েবের একই দরজা) — ফোন কেবল জিজ্ঞেস করে আর উত্তরটা সত্যি করে দেখায়।
final _taka = NumberFormat.decimalPattern('en_IN');

class CounterScreen extends StatefulWidget {
  const CounterScreen({super.key, this.api = const ServerDirectSaleApi(), this.customers, this.products});

  final DirectSaleApi api;

  /// পরীক্ষার জন্য — না দিলে ফোনে জমা তালিকা
  final List<CustomerRecord>? customers;
  final List<ProductRecord>? products;

  @override
  State<CounterScreen> createState() => _CounterScreenState();
}

class _CounterScreenState extends State<CounterScreen> {
  CounterSetup? _setup;
  String? _warehouseId;
  String _term = 'cash';
  CustomerRecord? _customer;
  final List<CounterLine> _cart = [];

  // উপরের ঘর — নতুন সারি, বা সম্পাদনার সারি
  ProductRecord? _product;
  CounterLot? _lot;
  final _qty = TextEditingController();
  final _rate = TextEditingController();
  final _discount = TextEditingController();
  final _free = TextEditingController();
  int? _freeAllowed;
  String? _editingKey;

  String? _depositAccountId;
  final _deposit = TextEditingController();
  final _note = TextEditingController();

  bool _busy = false;
  String? _error;

  late final List<CustomerRecord> _customers = widget.customers ?? CustomerRecord.all();
  late final List<ProductRecord> _products =
      (widget.products ?? ProductRecord.all()).where((p) => p.isActive).toList();

  @override
  void initState() {
    super.initState();
    _loadSetup();
  }

  @override
  void dispose() {
    for (final c in [_qty, _rate, _discount, _free, _deposit, _note]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _loadSetup([String? warehouseId]) async {
    setState(() => _busy = true);
    try {
      final setup = await widget.api.setup(warehouseId: warehouseId);
      if (!mounted) return;
      setState(() {
        _setup = setup;
        _warehouseId = setup.warehouseId;
        if (setup.paymentTerms.isNotEmpty && !setup.paymentTerms.any((t) => t.id == _term)) {
          _term = setup.paymentTerms.first.id;
        }
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'কাউন্টার খোলা গেল না। নেট আছে কি না দেখে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ফোনের কাউন্টার এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<CounterLot> get _lotsOfProduct => _product == null ? const [] : (_setup?.lots[_product!.id] ?? const []);

  bool get _tracked => _product != null && (_setup?.lots.containsKey(_product!.id) ?? false);

  Future<T?> _pick<T>(String title, List<T> items, String Function(T) label) => showModalBottomSheet<T>(
        context: context,
        isScrollControlled: true,
        builder: (_) => _PickSheet<T>(title: title, items: items, label: label),
      );

  Future<void> _askFree() async {
    final product = _product;
    final qty = int.tryParse(_qty.text.trim()) ?? 0;
    if (product == null || qty <= 0) return;
    try {
      final allowed = await widget.api.freeAllowed(
          productId: product.id, warehouseId: _warehouseId, qty: qty, lotId: _lot?.id);
      if (!mounted) return;
      setState(() {
        _freeAllowed = allowed;
        // ⓘ স্কিমের ফ্রি নিজে বসে (মালিক, ১ অক্টোবর: "free qty own auto box")
        if (allowed != null && _editingKey == null) _free.text = '$allowed';
      });
    } catch (_) {
      if (mounted) setState(() => _freeAllowed = null);
    }
  }

  void _clearEntry() {
    _product = null;
    _lot = null;
    _editingKey = null;
    _freeAllowed = null;
    for (final c in [_qty, _rate, _discount, _free]) {
      c.clear();
    }
  }

  void _addOrUpdate() {
    final product = _product;
    final qty = int.tryParse(_qty.text.trim()) ?? 0;
    final rate = double.tryParse(_rate.text.trim()) ?? 0;
    final discount = double.tryParse(_discount.text.trim()) ?? 0;
    final free = int.tryParse(_free.text.trim()) ?? 0;

    String? why;
    if (product == null) {
      why = 'পণ্য বাছুন।';
    } else if (_tracked && _lot == null) {
      why = 'লট বাছুন — এই পণ্য লট ধরে বিক্রি হয়।';
    } else if (qty <= 0) {
      why = 'পরিমাণ দিন।';
    } else if (rate <= 0) {
      why = 'দর ছাড়া বিক্রি হয় না।'; // ⓘ শূন্য দর কার্টে ঢোকে না (মালিক)
    } else if (discount < 0 || discount > 100) {
      why = 'ছাড় ০ থেকে ১০০%।';
    } else if (_freeAllowed != null && free > _freeAllowed!) {
      why = 'স্কিমে ফ্রি সর্বোচ্চ $_freeAllowed।';
    }
    if (why != null) {
      setState(() => _error = why);
      return;
    }

    final line = CounterLine(
      productId: product!.id,
      productName: product.name,
      lotId: _lot?.id,
      lotNo: _lot?.no,
      qty: qty,
      rate: rate,
      discountPercent: discount,
      freeQty: free,
    );
    setState(() {
      _error = null;
      final at = _cart.indexWhere((l) => l.key == (_editingKey ?? line.key));
      if (at >= 0) {
        _cart[at] = line; // ⓘ একই সারি — দ্বিতীয় সারি নয় (মালিকের নিয়ম ৬)
      } else {
        _cart.add(line);
      }
      _clearEntry();
    });
  }

  void _edit(CounterLine line) {
    setState(() {
      _product = _products.where((p) => p.id == line.productId).firstOrNull;
      _lot = _lotsOfProduct.where((l) => l.id == line.lotId).firstOrNull;
      _qty.text = '${line.qty}';
      _rate.text = line.rate.toStringAsFixed(2);
      _discount.text = line.discountPercent > 0 ? line.discountPercent.toString() : '';
      _free.text = line.freeQty > 0 ? '${line.freeQty}' : '';
      _editingKey = line.key;
    });
  }

  Future<void> _sell({required bool draft}) async {
    final customer = _customer;
    if (customer == null || _cart.isEmpty) {
      setState(() => _error = customer == null ? 'ক্রেতা বাছুন।' : 'কার্টে অন্তত একটা পণ্য দিন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await widget.api.sell(
        customerId: customer.id,
        warehouseId: _warehouseId,
        paymentTerm: _term,
        lines: List.of(_cart),
        draft: draft,
        depositAccountId: _depositAccountId,
        deposit: double.tryParse(_deposit.text.trim()) ?? 0,
        note: _note.text,
      );
      if (!mounted) return;
      setState(() => _busy = false); // ⓘ পপ-আপের আগেই — নইলে "চলছে" দাগ পপ-আপের পেছনে ঘুরতেই থাকে
      await _popup(
        switch (result.status) {
          'done' => 'বিক্রি হলো',
          'parked' => 'খসড়া রাখা হলো',
          _ => 'সইয়ের অপেক্ষায়',
        },
        result.notice,
      );
      if (mounted) {
        setState(() {
          _cart.clear();
          _deposit.clear();
          _note.clear();
          _clearEntry();
        });
      }
    } catch (e) {
      // ⓘ সার্ভারের দেয়াল (ঋণসীমা, লট, দর …) — পপ-আপে, মালিকের নিয়ম
      if (mounted) setState(() => _busy = false);
      if (mounted) await _popup('বিক্রি হলো না', errorMessageFor(e, fallback: 'বিক্রি করা গেল না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _popup(String title, String text) => showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          key: const ValueKey('counter-popup'),
          title: Text(title),
          content: Text(text),
          actions: [TextButton(onPressed: () => Navigator.pop(dialogContext), child: const Text('ঠিক আছে'))],
        ),
      );

  double get _total => _cart.fold(0, (sum, l) => sum + l.total);

  @override
  Widget build(BuildContext context) {
    final setup = _setup;
    return Scaffold(
      appBar: AppBar(title: const Text('সরাসরি বিক্রয়')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_error != null)
            Card(
              color: AppColors.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_error!, key: const ValueKey('counter-error'), style: const TextStyle(color: AppColors.danger)),
              ),
            ),
          Card(
            child: ListTile(
              key: const ValueKey('counter-customer'),
              leading: const Icon(Icons.storefront_outlined),
              title: Text(_customer?.name ?? 'ক্রেতা বাছুন'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                final picked = await _pick<CustomerRecord>('ক্রেতা বাছুন', _customers, (c) => c.name);
                if (picked != null) setState(() => _customer = picked);
              },
            ),
          ),
          if (setup != null && setup.warehouses.length > 1)
            DropdownButtonFormField<String>(
              initialValue: _warehouseId,
              decoration: const InputDecoration(labelText: 'গুদাম'),
              items: [for (final w in setup.warehouses) DropdownMenuItem(value: w.id, child: Text(w.label))],
              onChanged: (v) {
                if (v == null || v == _warehouseId) return;
                setState(() {
                  _cart.clear(); // ⓘ লট গুদামের — গুদাম বদলালে কার্ট নতুন
                  _clearEntry();
                });
                _loadSetup(v);
              },
            ),
          if (setup != null && setup.paymentTerms.isNotEmpty)
            DropdownButtonFormField<String>(
              key: const ValueKey('counter-term'),
              initialValue: _term,
              decoration: const InputDecoration(labelText: 'শর্ত'),
              items: [for (final t in setup.paymentTerms) DropdownMenuItem(value: t.id, child: Text(t.label))],
              onChanged: (v) => setState(() => _term = v ?? _term),
            ),
          const SizedBox(height: AppSpacing.md),

          // ── উপরের ঘর ─────────────────────────────────────────────
          Card(
            child: Padding(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  ListTile(
                    key: const ValueKey('counter-product'),
                    contentPadding: EdgeInsets.zero,
                    title: Text(_product?.name ?? 'পণ্য বাছুন'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () async {
                      final picked = await _pick<ProductRecord>('পণ্য বাছুন', _products, (p) => p.name);
                      if (picked == null) return;
                      setState(() {
                        _product = picked;
                        _lot = _lotsOfProduct.firstOrNull; // ⓘ FEFO-র প্রথমটা আগে থেকে — বদলানো যায়
                        _rate.text = (picked.salePrice ?? 0) > 0 ? picked.salePrice!.toStringAsFixed(2) : '';
                      });
                      _askFree();
                    },
                  ),
                  if (_tracked)
                    DropdownButtonFormField<String>(
                      key: const ValueKey('counter-lot'),
                      initialValue: _lot?.id,
                      decoration: const InputDecoration(labelText: 'লট'),
                      items: [
                        for (final l in _lotsOfProduct)
                          DropdownMenuItem(value: l.id, child: Text('${l.no} · মেয়াদ ${l.expiry} · আছে ${l.qty}')),
                      ],
                      onChanged: (v) {
                        setState(() => _lot = _lotsOfProduct.where((l) => l.id == v).firstOrNull);
                        _askFree();
                      },
                    ),
                  TextField(
                    key: const ValueKey('counter-qty'),
                    controller: _qty,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'পরিমাণ'),
                    onChanged: (_) => _askFree(),
                  ),
                  TextField(
                    key: const ValueKey('counter-rate'),
                    controller: _rate,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'দর'),
                  ),
                  TextField(
                    key: const ValueKey('counter-discount'),
                    controller: _discount,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'ছাড় % (দিলে মালিকের সই লাগবে)'),
                  ),
                  TextField(
                    key: const ValueKey('counter-free'),
                    controller: _free,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(
                      labelText: _freeAllowed == null ? 'ফ্রি' : 'ফ্রি (স্কিমে সর্বোচ্চ $_freeAllowed)',
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  FilledButton.tonal(
                    key: const ValueKey('counter-add'),
                    onPressed: _addOrUpdate,
                    child: Text(_editingKey == null ? 'কার্টে যোগ করুন' : 'হালনাগাদ করুন'),
                  ),
                ],
              ),
            ),
          ),

          // ── কার্ট — কেবল পড়ার ─────────────────────────────────────
          for (final line in _cart)
            Card(
              child: ListTile(
                title: Text(line.productName),
                subtitle: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (line.lotNo != null) Text('লট: ${line.lotNo}'),
                    Text('${line.qty} × ৳ ${_taka.format(line.rate)}'),
                    if (line.discountPercent > 0) Text('ছাড়: ${line.discountPercent}%'),
                    if (line.freeQty > 0) Text('ফ্রি: ${line.freeQty}'),
                    Text('৳ ${_taka.format(line.total)}'),
                  ],
                ),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  IconButton(
                    key: ValueKey('counter-edit-${line.key}'),
                    icon: const Icon(Icons.edit_outlined),
                    tooltip: 'সম্পাদনা',
                    onPressed: () => _edit(line),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close),
                    tooltip: 'বাদ দিন',
                    onPressed: () => setState(() => _cart.remove(line)),
                  ),
                ]),
              ),
            ),
          if (_cart.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: Text('চলতি মোট: ৳ ${_taka.format(_total)}',
                  key: const ValueKey('counter-total'), style: Theme.of(context).textTheme.titleMedium),
            ),

          // ── জমা (ঐচ্ছিক) ──────────────────────────────────────────
          if (setup != null && setup.moneyAccounts.isNotEmpty) ...[
            DropdownButtonFormField<String>(
              initialValue: _depositAccountId,
              decoration: const InputDecoration(labelText: 'জমা কোন খাতে (ঐচ্ছিক)'),
              items: [for (final a in setup.moneyAccounts) DropdownMenuItem(value: a.id, child: Text(a.label))],
              onChanged: (v) => setState(() => _depositAccountId = v),
            ),
            TextField(
              controller: _deposit,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'জমা টাকা'),
            ),
          ],
          TextField(controller: _note, decoration: const InputDecoration(labelText: 'বিবরণ (ঐচ্ছিক)')),
          const SizedBox(height: AppSpacing.md),
          FilledButton(
            key: const ValueKey('counter-confirm'),
            onPressed: _busy ? null : () => _sell(draft: false),
            child: const Text('নিশ্চিত করুন'),
          ),
          const SizedBox(height: AppSpacing.xs),
          // ⓘ ধূসর — মালিক, ২৭ সেপ্টেম্বর ২০২৬
          FilledButton(
            key: const ValueKey('counter-draft'),
            style: FilledButton.styleFrom(backgroundColor: Colors.grey.shade400, foregroundColor: Colors.black87),
            onPressed: _busy ? null : () => _sell(draft: true),
            child: const Text('খসড়া রাখুন'),
          ),
        ],
      ),
    );
  }
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
