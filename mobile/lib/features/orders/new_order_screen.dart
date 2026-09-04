import 'package:flutter/material.dart';

import '../../core/sync_engine/reference_cache.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import 'order_prefill.dart';

/// Takes an order with no signal required — see docs/Contract, §০ (owner's
/// decision ১): this writes only a `SalesOrder` CREATE to the offline queue.
/// It never touches a number, a stock figure, a credit limit or a price
/// rule — those four are exactly what the contract says a phone cannot know
/// offline, and all four are checked by the server at the moment this queued
/// change is actually pushed, not here.
///
/// <p><b>The payload shape below is this screen's own best reading of the
/// contract, not a field list confirmed against a live `/sync/sales/push`
/// response yet</b> — the coordinating session (`nexus-25`) has not sent a
/// SalesOrder payload schema. `customerId`/`items`/`note` are the plainest
/// shape an order can take; if the real handler wants different keys, only
/// [_submit]'s payload map changes, not the screen around it.
class NewOrderScreen extends StatefulWidget {
  const NewOrderScreen({super.key, this.prefill});

  /// Set when this screen was opened from "নতুন করে লিখুন" on a rejected
  /// order — see [OrderPrefill]'s own doc comment for why this starts the
  /// form rather than resubmitting anything automatically.
  final OrderPrefill? prefill;

  @override
  State<NewOrderScreen> createState() => _NewOrderScreenState();
}

class _CartLine {
  _CartLine({required this.product});

  final Map<String, dynamic> product;
  int quantity = 1;

  String get productId =>
      (product['id'] ?? product['publicId'] ?? '').toString();
  String get name => (product['name'] ?? 'নাম নেই').toString();
  num? get salesPrice =>
      (product['salesPrice'] ?? product['price']) as num?;
}

class _NewOrderScreenState extends State<NewOrderScreen> {
  Map<String, dynamic>? _customer;
  final Map<String, _CartLine> _cart = {};
  final _noteController = TextEditingController();
  bool _submitting = false;

  @override
  void initState() {
    super.initState();
    final prefill = widget.prefill;
    if (prefill == null) return;
    // Best-effort: a product or the customer itself may since have dropped
    // out of the local cache (a catalogue re-sync, a customer deactivated) —
    // silently skipping a line that no longer resolves is preferable to a
    // crash on a screen whose whole point is recovering from an earlier
    // failure.
    _customer = ReferenceCache.instance.get('Customer', prefill.customerId);
    for (final (productId, quantity) in prefill.items) {
      final product = ReferenceCache.instance.get('Product', productId);
      if (product == null) continue;
      _cart[productId] = _CartLine(product: product)..quantity = quantity;
    }
  }

  @override
  void dispose() {
    _noteController.dispose();
    super.dispose();
  }

  num get _total => _cart.values.fold<num>(
      0, (sum, line) => sum + (line.salesPrice ?? 0) * line.quantity);

  Future<void> _pickCustomer() async {
    final customers = ReferenceCache.instance.allOf('Customer');
    final selected = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _PickerSheet(
        title: 'গ্রাহক বাছুন',
        items: customers,
        labelOf: (c) => (c['name'] ?? '').toString(),
      ),
    );
    if (selected != null) setState(() => _customer = selected);
  }

  Future<void> _addProduct() async {
    final products = ReferenceCache.instance.allOf('Product');
    final selected = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _PickerSheet(
        title: 'পণ্য বাছুন',
        items: products,
        labelOf: (p) => (p['name'] ?? '').toString(),
      ),
    );
    if (selected == null) return;
    final id = (selected['id'] ?? selected['publicId'] ?? '').toString();
    setState(() {
      final existing = _cart[id];
      if (existing != null) {
        existing.quantity += 1;
      } else {
        _cart[id] = _CartLine(product: selected);
      }
    });
  }

  Future<void> _submit() async {
    if (_customer == null || _cart.isEmpty) return;
    setState(() => _submitting = true);
    try {
      await SyncEngine.instance.enqueue(
        module: 'sales',
        entityType: 'SalesOrder',
        operation: 'CREATE',
        payload: {
          'customerId':
              (_customer!['id'] ?? _customer!['publicId'] ?? '').toString(),
          'items': _cart.values
              .map((line) => {
                    'productId': line.productId,
                    'quantity': line.quantity,
                  })
              .toList(),
          if (_noteController.text.trim().isNotEmpty)
            'note': _noteController.text.trim(),
        },
      );
      // Only now — the new order is genuinely queued. See OrderPrefill's own
      // doc comment: someone who opened this screen from a rejected row and
      // then backed out without submitting must still see that original
      // rejection, unresolved.
      //
      // markResolved, not dismissRejected: this row is not merely dealt
      // with, it is replaced by the retry just queued above — see
      // SyncEngine.markResolved's own doc comment for why that distinction
      // is kept rather than deleting the row outright.
      final supersedes = widget.prefill?.supersedesRejectedKey;
      if (supersedes != null) {
        await SyncEngine.instance.markResolved(supersedes);
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text(
            'অর্ডার লেখা হয়েছে। নম্বর সিঙ্কের পর আসবে — এখনই বলা যাবে না।'),
      ));
      setState(() {
        _customer = null;
        _cart.clear();
        _noteController.clear();
      });
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final noProducts = ReferenceCache.instance.countOf('Product') == 0;

    return Scaffold(
      appBar: AppBar(title: const Text('নতুন অর্ডার')),
      body: noProducts
          ? const EmptyState(
              icon: Icons.inventory_2_outlined,
              title: 'পণ্যের তালিকা এখনো সিঙ্ক হয়নি',
              message: 'পণ্যের পর্দায় গিয়ে একবার নিচে টানুন, তারপর ফিরে আসুন।',
            )
          : ListView(
              padding: const EdgeInsets.all(AppSpacing.md),
              children: [
                // Honest about what will not be known until this reaches the
                // server — the contract's second owner decision, put where a
                // rep sees it before they promise a number to a shopkeeper.
                Container(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  decoration: BoxDecoration(
                    color: AppColors.pendingSurface,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Row(
                    children: [
                      Icon(Icons.info_outline,
                          size: 18, color: AppColors.pending),
                      SizedBox(width: AppSpacing.sm),
                      Expanded(
                        child: Text(
                          'অর্ডার নম্বর এখন বলা যাবে না — সিঙ্ক হওয়ার পর আসবে।',
                          style: TextStyle(
                              fontSize: 12.5, color: AppColors.pending),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.person_outline),
                    title: Text(_customer == null
                        ? 'গ্রাহক বাছুন'
                        : (_customer!['name'] ?? '').toString()),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: _pickCustomer,
                  ),
                ),
                const SizedBox(height: AppSpacing.md),
                Row(
                  children: [
                    const Text('পণ্য', style: TextStyle(fontWeight: FontWeight.w600)),
                    const Spacer(),
                    TextButton.icon(
                      onPressed: _addProduct,
                      icon: const Icon(Icons.add),
                      label: const Text('যোগ করুন'),
                    ),
                  ],
                ),
                if (_cart.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: AppSpacing.md),
                    child: Text('এখনো কোনো পণ্য যোগ করা হয়নি',
                        style: TextStyle(color: AppColors.onSurfaceMuted)),
                  )
                else
                  ..._cart.values.map((line) => Card(
                        child: ListTile(
                          title: Text(line.name),
                          subtitle: line.salesPrice != null
                              ? Text('৳${line.salesPrice} × ${line.quantity}')
                              : null,
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(
                                icon: const Icon(Icons.remove_circle_outline),
                                onPressed: () => setState(() {
                                  if (line.quantity > 1) {
                                    line.quantity -= 1;
                                  } else {
                                    _cart.remove(line.productId);
                                  }
                                }),
                              ),
                              Text('${line.quantity}'),
                              IconButton(
                                icon: const Icon(Icons.add_circle_outline),
                                onPressed: () =>
                                    setState(() => line.quantity += 1),
                              ),
                            ],
                          ),
                        ),
                      )),
                const SizedBox(height: AppSpacing.md),
                TextField(
                  controller: _noteController,
                  decoration: const InputDecoration(labelText: 'মন্তব্য (ঐচ্ছিক)'),
                  maxLines: 2,
                ),
                const SizedBox(height: AppSpacing.lg),
                if (_total > 0)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Text('মোট (আনুমানিক): ৳$_total',
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                  ),
                ElevatedButton(
                  onPressed: (_customer != null &&
                          _cart.isNotEmpty &&
                          !_submitting)
                      ? _submit
                      : null,
                  child: _submitting
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(
                              strokeWidth: 2, color: Colors.white),
                        )
                      : const Text('অর্ডার লিখুন'),
                ),
              ],
            ),
    );
  }
}

class _PickerSheet extends StatefulWidget {
  const _PickerSheet({
    required this.title,
    required this.items,
    required this.labelOf,
  });

  final String title;
  final List<Map<String, dynamic>> items;
  final String Function(Map<String, dynamic>) labelOf;

  @override
  State<_PickerSheet> createState() => _PickerSheetState();
}

class _PickerSheetState extends State<_PickerSheet> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final filtered = _query.isEmpty
        ? widget.items
        : widget.items
            .where((item) =>
                widget.labelOf(item).toLowerCase().contains(_query.toLowerCase()))
            .toList();

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
                hintText: widget.title,
                prefixIcon: const Icon(Icons.search),
              ),
              onChanged: (value) => setState(() => _query = value),
            ),
          ),
          Expanded(
            child: filtered.isEmpty
                ? const EmptyState(
                    icon: Icons.search_off, title: 'কোনো মিল পাওয়া যায়নি')
                : ListView.builder(
                    controller: scrollController,
                    itemCount: filtered.length,
                    itemBuilder: (context, index) {
                      final item = filtered[index];
                      return ListTile(
                        title: Text(widget.labelOf(item)),
                        onTap: () => Navigator.of(context).pop(item),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}
