import 'dart:async';

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/orders/order_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/records/product_record.dart';
import '../../core/records/sales_order_record.dart';
import '../../core/sync_engine/reference_cache.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import 'order_prefill.dart';
import 'order_sheets.dart';

/// The combined order screen — 0.4.3, the owner's approved samples of
/// 1 October 2026: every product on one list, a quantity on each line, the
/// free goods filled in by the server, and a look at the shop's standing
/// before the order goes.
///
/// <p>Still the same offline order underneath (docs/Contract §০): this
/// writes only a `SalesOrder` CREATE to the queue, in the shape
/// `SalesOrderSync::apply()` reads ([SalesOrderDraft]). Free goods are not
/// sent — they are applied at the bill and the challan.
///
/// <p>⛔ <b>No stock figure anywhere on this screen</b> — the owner's rule
/// for the SR's phone.
///
/// <p>⛔ <b>পাঠান never blocks the order.</b> The credit wall is at the DO,
/// the challan and the bill; here the phone only says what will happen.
class NewOrderScreen extends StatefulWidget {
  const NewOrderScreen({super.key, this.prefill, this.api, this.memory, this.offerDelay, this.enqueue});

  /// Set when this screen was opened from "নতুন করে লিখুন" on a rejected
  /// order — see [OrderPrefill]'s own doc comment for why this starts the
  /// form rather than resubmitting anything automatically.
  final OrderPrefill? prefill;

  /// Seams — the real ones need a server and the reference cache.
  final OrderApi? api;
  final OrderMemory? memory;

  /// How long after a quantity changes the free goods are asked for.
  final Duration? offerDelay;

  /// Where a finished order goes — the offline queue unless a test says.
  final Future<void> Function(Map<String, dynamic> payload)? enqueue;

  @override
  State<NewOrderScreen> createState() => _NewOrderScreenState();
}

class _NewOrderScreenState extends State<NewOrderScreen> {
  CustomerRecord? _customer;

  /// productId → pieces to sell. A line with nothing in it is not here.
  final Map<String, int> _qty = {};

  /// productId → free pieces, as the server last said (offline: the last
  /// answer, or nothing).
  final Map<String, double> _free = {};

  /// productId → the offer sentence the server sent.
  final Map<String, String> _offer = {};

  final _searchController = TextEditingController();
  final _noteController = TextEditingController();
  String _query = '';
  OrderFilter _filter = const OrderFilter();
  bool _submitting = false;
  Timer? _offerTimer;
  String? _catalogueOffersFor;

  late final List<ProductRecord> _catalogue =
      ProductRecord.all().where((p) => p.isActive).toList(growable: false);

  OrderApi get _api => widget.api ?? const ServerOrderApi();
  OrderMemory get _memory => widget.memory ?? const OrderMemory();

  @override
  void initState() {
    super.initState();
    final prefill = widget.prefill;
    if (prefill != null) {
      // Best-effort: a product or the customer itself may since have dropped
      // out of the local cache — silently skipping a line that no longer
      // resolves beats a crash on a screen whose whole point is recovering
      // from an earlier failure.
      _customer = CustomerRecord.byId(prefill.customerId);
      for (final (productId, quantity) in prefill.items) {
        if (ProductRecord.byId(productId) == null || quantity <= 0) continue;
        _qty[productId] = quantity;
      }
    }
    if (_customer != null) {
      _loadCatalogueOffers();
      if (_qty.isNotEmpty) _scheduleOffers();
    }
  }

  @override
  void dispose() {
    _offerTimer?.cancel();
    _searchController.dispose();
    _noteController.dispose();
    super.dispose();
  }

  ProductRecord? _product(String id) {
    for (final p in _catalogue) {
      if (p.id == id) return p;
    }
    return ProductRecord.byId(id);
  }

  int get _pieces => _qty.values.fold(0, (sum, q) => sum + q);

  double get _freePieces =>
      _qty.keys.fold<double>(0, (sum, id) => sum + (_free[id] ?? 0));

  double get _total => _qty.entries
      .fold<double>(0, (sum, e) => sum + (_product(e.key)?.salePrice ?? 0) * e.value);

  Map<String, int> get _last =>
      _customer == null ? const {} : _memory.lastFor(_customer!.id);

  // ── the list ──────────────────────────────────────────────────────────

  List<ProductRecord> _visible(OrderFilter filter) {
    final last = _last;
    final rows = _catalogue.where((p) {
      if (!p.matches(_query)) return false;
      if (filter.offerOnly && !_offer.containsKey(p.id)) return false;
      if (filter.boughtBefore && !last.containsKey(p.id)) return false;
      return true;
    }).toList();
    switch (filter.sort) {
      case OrderSort.name:
        rows.sort((a, b) => a.name.compareTo(b.name));
      case OrderSort.price:
        rows.sort((a, b) => (a.salePrice ?? double.infinity).compareTo(b.salePrice ?? double.infinity));
      case OrderSort.catalogue:
        break;
    }
    return rows;
  }

  // ── the server's free goods ──────────────────────────────────────────

  /// Which products carry an offer at all, for the chips and "অফার আছে" —
  /// asked once per shop, a piece of each. Quietly nothing when offline.
  Future<void> _loadCatalogueOffers() async {
    final customer = _customer;
    if (customer == null || _catalogueOffersFor == customer.id) return;
    _catalogueOffersFor = customer.id;
    const chunk = 100;
    for (var i = 0; i < _catalogue.length; i += chunk) {
      final asks = [
        for (final p in _catalogue.skip(i).take(chunk))
          OfferAsk(productId: p.id, qty: 1, rate: p.salePriceRaw),
      ];
      try {
        final answer = await _api.offers(customer.id, asks);
        if (!mounted || _customer?.id != customer.id) return;
        setState(() {
          for (final line in answer) {
            if (line.offer != null) _offer[line.productId] = line.offer!;
          }
        });
      } catch (_) {
        return;
      }
    }
  }

  void _scheduleOffers() {
    _offerTimer?.cancel();
    _offerTimer = Timer(widget.offerDelay ?? const Duration(milliseconds: 400), _askOffers);
  }

  Future<void> _askOffers() async {
    final customer = _customer;
    if (customer == null || _qty.isEmpty) return;
    final asked = Map<String, int>.of(_qty);
    try {
      final answer = await _api.offers(customer.id, [
        for (final e in asked.entries)
          OfferAsk(productId: e.key, qty: e.value, rate: _product(e.key)?.salePriceRaw),
      ]);
      if (!mounted || _customer?.id != customer.id) return;
      setState(() {
        for (final line in answer) {
          // A line changed again while the answer travelled is asked anew.
          if (_qty[line.productId] != asked[line.productId]) continue;
          _free[line.productId] = line.freeQty;
          if (line.offer != null) _offer[line.productId] = line.offer!;
        }
      });
    } catch (_) {
      // Offline or refused: the last known free quantity stays, or none.
    }
  }

  void _setQty(String productId, int qty) {
    setState(() {
      if (qty <= 0) {
        _qty.remove(productId);
        _free.remove(productId);
      } else {
        _qty[productId] = qty;
      }
    });
    _scheduleOffers();
  }

  Future<void> _openKeypad(ProductRecord product) async {
    final carton = product.carton;
    final qty = await showQtyKeypad(
      context,
      productName: product.name,
      current: _qty[product.id] ?? 0,
      rate: product.salePrice,
      offer: _offer[product.id],
      cartonName: carton?.name.isEmpty ?? true ? null : carton!.name,
      cartonFactor: carton?.factor,
      lastQty: _last[product.id],
    );
    if (qty != null) _setQty(product.id, qty);
  }

  // ── the header's three buttons ──────────────────────────────────────

  Future<void> _pickCustomer() async {
    final selected = await showModalBottomSheet<CustomerRecord>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _CustomerPickerSheet(items: CustomerRecord.all()),
    );
    if (selected == null || selected.id == _customer?.id) return;
    setState(() {
      _customer = selected;
      _free.clear();
      _offer.clear();
      _catalogueOffersFor = null;
    });
    _loadCatalogueOffers();
    _scheduleOffers();
  }

  /// A Bluetooth or built-in scanner types the code into this box; it can
  /// also be typed by hand. Camera scanning needs a scanner library this
  /// build does not carry yet.
  Future<void> _scan() async {
    final code = await showDialog<String>(context: context, builder: (_) => const _BarcodeDialog());
    if (code == null || code.trim().isEmpty || !mounted) return;
    ProductRecord? found;
    for (final p in _catalogue) {
      if (p.hasBarcode(code)) {
        found = p;
        break;
      }
    }
    if (found == null) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('এই বারকোডে কোনো পণ্য নেই')));
      return;
    }
    await _openKeypad(found);
  }

  Future<void> _openFilter() async {
    final chosen = await showOrderFilter(
      context,
      current: _filter,
      countFor: (filter) => _visible(filter).length,
    );
    if (chosen != null) setState(() => _filter = chosen);
  }

  // ── পাঠান ─────────────────────────────────────────────────────────────

  Future<void> _send() async {
    if (_qty.isEmpty || _submitting) return;
    if (_customer == null) {
      await _pickCustomer();
      if (_customer == null || !mounted) return;
    }
    final customer = _customer!;

    final lines = [
      for (final e in _qty.entries)
        if (_product(e.key) case final product?)
          ConfirmLine(
            name: product.name,
            qty: e.value,
            free: _free[e.key] ?? 0,
            taka: (product.salePrice ?? 0) * e.value,
          ),
    ];

    // Asked now, answered inside the sheet. ignore(): a refusal must not
    // surface as an uncaught error before the sheet is there to show it.
    final standing = _api.standing(customer.id, _total)..ignore();

    final choice = await showOrderConfirm(
      context,
      lines: lines,
      standing: standing,
      note: _noteController,
    );
    if (choice == ConfirmChoice.send) await _queue(customer);
  }

  Future<void> _queue(CustomerRecord customer) async {
    setState(() => _submitting = true);
    try {
      final lines = [
        for (final e in _qty.entries)
          if (_product(e.key) case final product?)
            SalesOrderDraftLine(product: product, quantity: e.value),
      ];
      final draft = SalesOrderDraft(
        customerId: customer.id,
        lines: lines,
        narration: _noteController.text,
        // The day the order was taken, not the day it manages to sync.
        trxDate: DateTime.now(),
      );
      await (widget.enqueue ??
          (payload) => SyncEngine.instance.enqueue(
                module: 'sales',
                entityType: 'SalesOrder',
                operation: 'CREATE',
                payload: payload,
              ))(draft.toPayload());
      await _memory.remember(customer.id, Map.of(_qty));
      // Only now — the new order is genuinely queued. markResolved, not
      // dismissRejected: the rejected row is replaced by this retry.
      final supersedes = widget.prefill?.supersedesRejectedKey;
      if (supersedes != null) {
        await SyncEngine.instance.markResolved(supersedes);
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('অর্ডার লেখা হয়েছে। নম্বর সিঙ্কের পর আসবে — এখনই বলা যাবে না।'),
      ));
      setState(() {
        _qty.clear();
        _free.clear();
        _noteController.clear();
      });
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  // ── drawing ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final noProducts = ReferenceCache.instance.countOf('Product') == 0;
    final customer = _customer;

    return Scaffold(
      backgroundColor: OrderPalette.page,
      appBar: AppBar(
        backgroundColor: OrderPalette.page,
        foregroundColor: OrderPalette.ink,
        elevation: 0,
        scrolledUnderElevation: 0,
        titleSpacing: 0,
        title: InkWell(
          key: const ValueKey('order-customer'),
          onTap: _pickCustomer,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text('নতুন অর্ডার', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
              Text(
                customer == null
                    ? 'গ্রাহক বাছুন'
                    : [customer.label, if (customer.address != null) customer.address!].join(' · '),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 15,
                  color: customer == null ? OrderPalette.brand : OrderPalette.muted,
                  fontWeight: customer == null ? FontWeight.w700 : FontWeight.w400,
                ),
              ),
            ],
          ),
        ),
      ),
      body: noProducts
          ? const EmptyState(
              icon: Icons.inventory_2_outlined,
              title: 'পণ্যের তালিকা এখনো সিঙ্ক হয়নি',
              message: 'পণ্যের পর্দায় গিয়ে একবার নিচে টানুন, তারপর ফিরে আসুন।',
            )
          : Column(
              children: [
                _searchRow(),
                if (_filter.activeCount > 0) _chipRow(),
                Expanded(child: _list()),
                _bottomBar(),
              ],
            ),
    );
  }

  Widget _iconBox({required Widget child, required Color fill, required String label, required VoidCallback onTap, Key? key}) =>
      Semantics(
        button: true,
        label: label,
        child: Material(
          color: fill,
          borderRadius: BorderRadius.circular(16),
          child: InkWell(
            key: key,
            borderRadius: BorderRadius.circular(16),
            onTap: onTap,
            child: SizedBox(width: 48, height: 48, child: Center(child: child)),
          ),
        ),
      );

  Widget _searchRow() {
    final active = _filter.activeCount;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 6),
      child: Row(
        children: [
          Expanded(
            child: SizedBox(
              height: 48,
              child: TextField(
                key: const ValueKey('order-search'),
                controller: _searchController,
                onChanged: (value) => setState(() => _query = value),
                style: const TextStyle(fontSize: 16),
                decoration: InputDecoration(
                  hintText: 'নাম বা কোড লিখুন',
                  prefixIcon: const Icon(Icons.search, color: OrderPalette.muted),
                  filled: true,
                  fillColor: OrderPalette.field,
                  contentPadding: EdgeInsets.zero,
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(16), borderSide: BorderSide.none),
                ),
              ),
            ),
          ),
          const SizedBox(width: 8),
          _iconBox(
            key: const ValueKey('order-barcode'),
            label: 'বারকোড স্ক্যান',
            fill: OrderPalette.field,
            onTap: _scan,
            child: const Icon(Icons.qr_code_scanner, color: OrderPalette.ink),
          ),
          const SizedBox(width: 8),
          _iconBox(
            key: const ValueKey('order-filter'),
            label: 'ফিল্টার',
            fill: OrderPalette.brand,
            onTap: _openFilter,
            child: Badge(
              isLabelVisible: active > 0,
              backgroundColor: OrderPalette.badge,
              label: Text(bn(active), key: const ValueKey('order-filter-badge')),
              child: const Icon(Icons.tune, color: Colors.white),
            ),
          ),
        ],
      ),
    );
  }

  Widget _chipRow() {
    Widget chip(String label, OrderFilter without) => Padding(
          padding: const EdgeInsets.only(right: 6),
          child: InputChip(
            label: Text(label, style: const TextStyle(fontWeight: FontWeight.w600, color: OrderPalette.onPill)),
            backgroundColor: OrderPalette.pill,
            side: BorderSide.none,
            shape: const StadiumBorder(),
            deleteIconColor: OrderPalette.onPill,
            deleteButtonTooltipMessage: 'সরান',
            onDeleted: () => setState(() => _filter = without),
          ),
        );
    return SizedBox(
      height: 44,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        children: [
          if (_filter.offerOnly) chip('অফার আছে', _filter.copyWith(offerOnly: false)),
          if (_filter.boughtBefore) chip('এই দোকান আগে নিয়েছে', _filter.copyWith(boughtBefore: false)),
          if (_filter.sort != OrderSort.catalogue)
            chip(OrderFilter.sortLabel(_filter.sort), _filter.copyWith(sort: OrderSort.catalogue)),
        ],
      ),
    );
  }

  Widget _list() {
    final rows = _visible(_filter);
    if (rows.isEmpty) {
      return const EmptyState(icon: Icons.search_off, title: 'কোনো মিল পাওয়া যায়নি');
    }
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 12),
      itemCount: rows.length,
      separatorBuilder: (_, __) => const SizedBox(height: 10),
      itemBuilder: (context, index) => _row(rows[index]),
    );
  }

  Widget _row(ProductRecord product) {
    final qty = _qty[product.id] ?? 0;
    final free = _free[product.id] ?? 0;
    final rate = product.salePrice;
    final offer = _offer[product.id];

    return Container(
      key: ValueKey('order-row-${product.id}'),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(color: OrderPalette.row, borderRadius: BorderRadius.circular(22)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Text(product.name,
                    style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
              ),
              const SizedBox(width: 8),
              Text(
                [
                  if (qty > 0) '✓ ঝুড়িতে আছে',
                  rate == null ? 'দর জানা নেই' : bnTaka(rate),
                ].join(' · '),
                style: TextStyle(
                  fontSize: qty > 0 ? 13 : 14,
                  fontWeight: qty > 0 ? FontWeight.w700 : FontWeight.w400,
                  color: qty > 0 ? OrderPalette.inCart : OrderPalette.muted,
                ),
              ),
            ],
          ),
          if (offer != null) ...[
            const SizedBox(height: 8),
            OfferChip(offer),
          ],
          const SizedBox(height: 8),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                flex: 3,
                child: Column(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(4),
                      decoration: BoxDecoration(color: OrderPalette.pill, borderRadius: BorderRadius.circular(999)),
                      child: Row(
                        children: [
                          _round('−', 'কমান', () => _setQty(product.id, (_qty[product.id] ?? 0) - 1),
                              key: ValueKey('order-minus-${product.id}')),
                          Expanded(
                            child: InkWell(
                              key: ValueKey('order-qty-${product.id}'),
                              onTap: () => _openKeypad(product),
                              child: SizedBox(
                                height: 38,
                                child: Center(
                                  child: Text(bnQty(qty),
                                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                                ),
                              ),
                            ),
                          ),
                          _round('+', 'বাড়ান', () => _setQty(product.id, (_qty[product.id] ?? 0) + 1),
                              key: ValueKey('order-plus-${product.id}')),
                        ],
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      qty > 0 && rate != null ? 'বিক্রি · ${bnTaka(rate * qty)}' : 'বিক্রি',
                      style: const TextStyle(fontSize: 11, color: OrderPalette.muted),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                flex: 2,
                child: Column(
                  children: [
                    Semantics(
                      label: 'ফ্রি',
                      readOnly: true,
                      child: Container(
                        key: ValueKey('order-free-${product.id}'),
                        height: 46,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: free > 0 ? OrderPalette.free : Colors.white,
                          borderRadius: BorderRadius.circular(16),
                          border: Border.all(
                              color: free > 0 ? OrderPalette.freeBorder : const Color(0xFFD0D5DD), width: 1.5),
                        ),
                        child: Text(bnQty(free),
                            style: TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w800,
                              color: free > 0 ? OrderPalette.onFree : const Color(0xFF98A2B3),
                            )),
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(free > 0 ? 'ফ্রি · নিজে বসেছে' : 'ফ্রি',
                        style: const TextStyle(fontSize: 11, color: OrderPalette.muted)),
                  ],
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _round(String glyph, String label, VoidCallback onTap, {Key? key}) => Semantics(
        button: true,
        label: label,
        child: Material(
          color: Colors.white,
          shape: const CircleBorder(),
          child: InkWell(
            key: key,
            customBorder: const CircleBorder(),
            onTap: onTap,
            child: SizedBox(
              width: 38,
              height: 38,
              child: Center(
                child: Text(glyph, style: const TextStyle(fontSize: 20, color: OrderPalette.onPill)),
              ),
            ),
          ),
        ),
      );

  Widget _bottomBar() => SafeArea(
        top: false,
        child: Container(
          margin: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          padding: const EdgeInsets.fromLTRB(18, 12, 14, 12),
          decoration: BoxDecoration(color: OrderPalette.ink, borderRadius: BorderRadius.circular(20)),
          child: Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('বিক্রি ${bnQty(_pieces)} পিস · ফ্রি ${bnQty(_freePieces)}',
                        key: const ValueKey('order-bar-pieces'),
                        style: const TextStyle(fontSize: 13, color: Colors.white70)),
                    Text(bnTaka(_total),
                        key: const ValueKey('order-bar-total'),
                        style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: Colors.white)),
                  ],
                ),
              ),
              SizedBox(
                height: 52,
                child: FilledButton.icon(
                  key: const ValueKey('order-send'),
                  style: FilledButton.styleFrom(
                    backgroundColor: OrderPalette.brand,
                    disabledBackgroundColor: const Color(0xFF4A4D55),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                    textStyle: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17),
                  ),
                  onPressed: _qty.isEmpty || _submitting ? null : _send,
                  icon: _submitting
                      ? const SizedBox(
                          width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.send),
                  label: const Text('পাঠান'),
                ),
              ),
            ],
          ),
        ),
      );
}

/// The barcode box — owns its controller, so the field outlives the
/// dialog's closing animation.
class _BarcodeDialog extends StatefulWidget {
  const _BarcodeDialog();

  @override
  State<_BarcodeDialog> createState() => _BarcodeDialogState();
}

class _BarcodeDialogState extends State<_BarcodeDialog> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('বারকোড'),
        content: TextField(
          key: const ValueKey('barcode-field'),
          controller: _controller,
          autofocus: true,
          decoration: const InputDecoration(hintText: 'স্ক্যান করুন বা লিখুন'),
          onSubmitted: (value) => Navigator.of(context).pop(value),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('বাতিল')),
          FilledButton(
              onPressed: () => Navigator.of(context).pop(_controller.text), child: const Text('খুঁজুন')),
        ],
      );
}

/// What the chosen shop owes, shown the moment the shop is chosen.
///
/// <p>Public so credit_headroom_test.dart can pump it on its own: reaching it
/// through the order screen means seeding a catalogue and driving a picker,
/// and the arithmetic below is worth testing without that in the way.
///
/// <p><b>It states, it does not decide.</b> `CustomerDueSync`'s own comment
/// is explicit that a zero credit limit means cash or advance rather than
/// "no sale", and that whether it blocks anything is a company switch the
/// phone is deliberately not sent. So this draws the figures and leaves the
/// judgement to the person standing in the shop and to the server at sync —
/// a phone refusing an order on its own cached copy of a limit would be
/// wrong in both directions.
class DueNotice extends StatelessWidget {
  const DueNotice(
      {super.key, required this.customerId, required this.orderTotal});

  final String customerId;

  /// What is in the cart right now. The figures above it are about the past;
  /// this is the one that makes them a decision.
  final double orderTotal;

  @override
  Widget build(BuildContext context) {
    final due = CustomerDueRecord.forCustomer(customerId);
    // CustomerDue has its own watermark, so it can lag the Customer record by
    // a sync. Saying nothing is right here: "বকেয়া নেই" when the figure has
    // simply not arrived is the one sentence that would cost money.
    if (due == null) return const SizedBox.shrink();

    final owes = due.outstanding > 0;

    // ⚠️ The arithmetic nobody can do standing in a shop, across two screens,
    // while a shopkeeper waits. "বকেয়া ৳45,000 · সীমা ৳50,000" and a cart of
    // ৳12,000 are three numbers a rep has to hold and subtract correctly to
    // know they are about to write an order the office will refuse — and the
    // refusal reaches them hours later, after the goods were promised.
    //
    // Still stating, not deciding, exactly as this class's doc comment says:
    // a sentence about what the numbers add up to, not a blocked button. A
    // zero credit limit means cash or advance rather than "no sale", and
    // whether a limit blocks anything is a company switch the phone is
    // deliberately not sent.
    final headroom = due.creditLimit - due.outstanding;
    final overBy = orderTotal - headroom;
    final willCross = due.hasCreditLimit && orderTotal > 0 && overBy > 0;

    // The figure's age, shown only when something is being concluded from it.
    // CustomerDue has its own watermark and can lag Customer by a sync, so a
    // due that arrived last Tuesday looks exactly like one from this morning
    // — tolerable while it is only being displayed, not while it is the basis
    // of a sentence telling somebody they are over a limit.
    final syncedAt = CustomerDueRecord.syncedAt(customerId);

    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.sm),
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.sm),
        decoration: BoxDecoration(
          color: willCross
              ? AppColors.dangerSurface
              : owes
                  ? AppColors.warningSurface
                  : AppColors.surfaceMuted,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(
          children: [
            Icon(
                willCross
                    ? Icons.report_problem_outlined
                    : owes
                        ? Icons.account_balance_wallet_outlined
                        : Icons.check,
                size: 18,
                color: willCross
                    ? AppColors.danger
                    : owes
                        ? AppColors.warning
                        : AppColors.onSurfaceMuted),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    // ⓘ গোটা কোম্পানির — পাশের সীমার হিসাব এটা দিয়েই (সীমা পরম)
                    due.wholeOutstandingLabel,
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 13,
                      color:
                          owes ? AppColors.warning : AppColors.onSurfaceMuted,
                    ),
                  ),
                  if (due.hasCreditLimit || due.creditDays > 0)
                    Text(
                      [
                        if (due.hasCreditLimit)
                          'সীমা ${Money.taka(due.creditLimit)}',
                        if (due.hasCreditLimit && headroom > 0)
                          'বাকি ${Money.taka(headroom)}',
                        if (due.creditDays > 0) '${due.creditDays} দিন',
                      ].join(' · '),
                      style: Theme.of(context).textTheme.bodySmall,
                    ),
                  if (willCross)
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpacing.xs),
                      child: Text(
                        headroom <= 0
                            // Already at or past the limit before this order
                            // adds anything — a different sentence, because
                            // "over by" against a negative headroom reads as
                            // though the cart caused it.
                            ? 'সীমা আগেই পেরিয়ে আছে — এই অর্ডারে '
                                '${Money.taka(orderTotal)} যোগ হবে।'
                            : 'এই অর্ডারে সীমা ${Money.taka(overBy)} '
                                'ছাড়িয়ে যাবে।',
                        style: const TextStyle(
                          fontSize: 12.5,
                          fontWeight: FontWeight.w700,
                          color: AppColors.danger,
                        ),
                      ),
                    ),
                  if (willCross && syncedAt != null)
                    Text(
                      'বকেয়ার তথ্য সিঙ্ক ${DateFormat('dd/MM/yyyy hh:mm a').format(syncedAt)}',
                      style: const TextStyle(
                          fontSize: 11, color: AppColors.onSurfaceMuted),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The shop picker. Rows carry the due, for the same reason the list screen
/// does: which shop to sell to on credit is decided here.
class _CustomerPickerSheet extends StatefulWidget {
  const _CustomerPickerSheet({required this.items});

  final List<CustomerRecord> items;

  @override
  State<_CustomerPickerSheet> createState() => _CustomerPickerSheetState();
}

class _CustomerPickerSheetState extends State<_CustomerPickerSheet> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final filtered =
        widget.items.where((customer) => customer.matches(_query)).toList();

    return _PickerScaffold(
      hint: 'গ্রাহক বাছুন',
      onQueryChanged: (value) => setState(() => _query = value),
      itemCount: filtered.length,
      itemBuilder: (context, index) {
        final customer = filtered[index];
        final due = CustomerDueRecord.forCustomer(customer.id);
        return ListTile(
          title: Text(customer.label),
          subtitle: customer.phone == null ? null : Text(customer.phone!),
          trailing: due == null
              ? null
              : Text(
                  due.outstandingLabel,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: due.outstanding > 0
                        ? AppColors.danger
                        : AppColors.onSurfaceMuted,
                  ),
                ),
          onTap: () => Navigator.of(context).pop(customer),
        );
      },
    );
  }
}

/// The search box and list both pickers share.
class _PickerScaffold extends StatelessWidget {
  const _PickerScaffold({
    required this.hint,
    required this.onQueryChanged,
    required this.itemCount,
    required this.itemBuilder,
  });

  final String hint;
  final ValueChanged<String> onQueryChanged;
  final int itemCount;
  final Widget? Function(BuildContext, int) itemBuilder;

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.7,
      expand: false,
      builder: (context, scrollController) => Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              autofocus: true,
              decoration: InputDecoration(
                hintText: hint,
                prefixIcon: const Icon(Icons.search),
              ),
              onChanged: onQueryChanged,
            ),
          ),
          Expanded(
            child: itemCount == 0
                ? const EmptyState(
                    icon: Icons.search_off, title: 'কোনো মিল পাওয়া যায়নি')
                : ListView.builder(
                    controller: scrollController,
                    itemCount: itemCount,
                    itemBuilder: itemBuilder,
                  ),
          ),
        ],
      ),
    );
  }
}
