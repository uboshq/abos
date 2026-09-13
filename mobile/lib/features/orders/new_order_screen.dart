import 'package:flutter/material.dart';

import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/records/product_record.dart';
import '../../core/records/sales_order_record.dart';
import '../../core/records/stock_record.dart';
import '../../core/sync_engine/reference_cache.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import 'order_prefill.dart';

/// Takes an order with no signal required — see docs/Contract, §০ (owner's
/// decision ১): this writes only a `SalesOrder` CREATE to the offline queue.
/// It never assigns a number, moves stock, or passes a credit check; all four
/// of those are what the contract says a phone cannot know offline, and all
/// four are decided by the server at the moment this queued change is pushed.
///
/// <p><b>The payload is now the shape `SalesOrderSync::apply()` actually
/// reads</b> — see [SalesOrderDraft], which is where that shape lives and
/// where the story of the `items`/`lines` mismatch that would have had every
/// order on this screen refused is written down.
///
/// <p><b>What a rep is shown before promising anything</b>: the shop's
/// outstanding due, and the sellable quantity of each product where this role
/// receives stock at all. Both are cached figures, both can be stale, and
/// neither is used to block the order — that check is the server's at sync,
/// and a phone that refuses on its own stale copy would refuse sales that are
/// perfectly good.
class NewOrderScreen extends StatefulWidget {
  const NewOrderScreen({super.key, this.prefill});

  /// Set when this screen was opened from "নতুন করে লিখুন" on a rejected
  /// order — see [OrderPrefill]'s own doc comment for why this starts the
  /// form rather than resubmitting anything automatically.
  final OrderPrefill? prefill;

  @override
  State<NewOrderScreen> createState() => _NewOrderScreenState();
}

class _NewOrderScreenState extends State<NewOrderScreen> {
  CustomerRecord? _customer;
  final Map<String, SalesOrderDraftLine> _cart = {};
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
    _customer = CustomerRecord.byId(prefill.customerId);
    for (final (productId, quantity) in prefill.items) {
      final product = ProductRecord.byId(productId);
      if (product == null) continue;
      _cart[productId] =
          SalesOrderDraftLine(product: product, quantity: quantity);
    }
  }

  @override
  void dispose() {
    _noteController.dispose();
    super.dispose();
  }

  double get _total =>
      _cart.values.fold<double>(0, (sum, line) => sum + line.lineTotal);

  Future<void> _pickCustomer() async {
    final selected = await showModalBottomSheet<CustomerRecord>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _CustomerPickerSheet(items: CustomerRecord.all()),
    );
    if (selected != null) setState(() => _customer = selected);
  }

  Future<void> _addProduct() async {
    final selected = await showModalBottomSheet<ProductRecord>(
      context: context,
      isScrollControlled: true,
      builder: (context) => _ProductPickerSheet(items: ProductRecord.all()),
    );
    if (selected == null) return;
    setState(() {
      final existing = _cart[selected.id];
      if (existing != null) {
        existing.quantity += 1;
      } else {
        _cart[selected.id] = SalesOrderDraftLine(product: selected);
      }
    });
  }

  Future<void> _submit() async {
    final customer = _customer;
    if (customer == null || _cart.isEmpty) return;
    setState(() => _submitting = true);
    try {
      final draft = SalesOrderDraft(
        customerId: customer.id,
        lines: _cart.values.toList(),
        narration: _noteController.text,
        // The day the order was taken, not the day it manages to sync — see
        // SalesOrderDraft.trxDate.
        trxDate: DateTime.now(),
      );

      await SyncEngine.instance.enqueue(
        module: 'sales',
        entityType: 'SalesOrder',
        operation: 'CREATE',
        payload: draft.toPayload(),
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
                    title: Text(_customer?.name ?? 'গ্রাহক বাছুন'),
                    subtitle: _customer?.phone == null
                        ? null
                        : Text(_customer!.phone!),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: _pickCustomer,
                  ),
                ),
                if (_customer != null) _DueNotice(customerId: _customer!.id),
                const SizedBox(height: AppSpacing.md),
                Row(
                  children: [
                    const Text('পণ্য',
                        style: TextStyle(fontWeight: FontWeight.w600)),
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
                          title: Text(line.product.name),
                          subtitle: line.product.salePrice == null
                              // No price in the payload means the server sent
                              // no salePrice for this product at all. The
                              // order can still be written — the server sets
                              // the rate — but the rep must not be shown a
                              // total that pretends to include this line.
                              ? const Text('দর জানা নেই — সার্ভার বসাবে')
                              : Text(
                                  '${Money.taka(line.product.salePrice)} × ${line.quantity}'
                                  '  =  ${Money.taka(line.lineTotal)}'),
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
                  decoration:
                      const InputDecoration(labelText: 'মন্তব্য (ঐচ্ছিক)'),
                  maxLines: 2,
                ),
                const SizedBox(height: AppSpacing.lg),
                if (_total > 0)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Text('মোট (আনুমানিক): ${Money.taka(_total)}',
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                  ),
                ElevatedButton(
                  onPressed:
                      (_customer != null && _cart.isNotEmpty && !_submitting)
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

/// What the chosen shop owes, shown the moment the shop is chosen.
///
/// <p><b>It states, it does not decide.</b> `CustomerDueSync`'s own comment
/// is explicit that a zero credit limit means cash or advance rather than
/// "no sale", and that whether it blocks anything is a company switch the
/// phone is deliberately not sent. So this draws the figures and leaves the
/// judgement to the person standing in the shop and to the server at sync —
/// a phone refusing an order on its own cached copy of a limit would be
/// wrong in both directions.
class _DueNotice extends StatelessWidget {
  const _DueNotice({required this.customerId});

  final String customerId;

  @override
  Widget build(BuildContext context) {
    final due = CustomerDueRecord.forCustomer(customerId);
    // CustomerDue has its own watermark, so it can lag the Customer record by
    // a sync. Saying nothing is right here: "বকেয়া নেই" when the figure has
    // simply not arrived is the one sentence that would cost money.
    if (due == null) return const SizedBox.shrink();

    final owes = due.outstanding > 0;

    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.sm),
      child: Container(
        padding: const EdgeInsets.all(AppSpacing.sm),
        decoration: BoxDecoration(
          color: owes ? AppColors.warningSurface : AppColors.surfaceMuted,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(
          children: [
            Icon(owes ? Icons.account_balance_wallet_outlined : Icons.check,
                size: 18,
                color: owes ? AppColors.warning : AppColors.onSurfaceMuted),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    due.outstandingLabel,
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
                        if (due.creditDays > 0) '${due.creditDays} দিন',
                      ].join(' · '),
                      style: Theme.of(context).textTheme.bodySmall,
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
          title: Text(customer.name),
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

/// The product picker. Rows carry the price and, where this role receives
/// stock at all, the sellable quantity — both of which were blank before,
/// along with the name.
class _ProductPickerSheet extends StatefulWidget {
  const _ProductPickerSheet({required this.items});

  final List<ProductRecord> items;

  @override
  State<_ProductPickerSheet> createState() => _ProductPickerSheetState();
}

class _ProductPickerSheetState extends State<_ProductPickerSheet> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final filtered =
        widget.items.where((product) => product.matches(_query)).toList();

    return _PickerScaffold(
      hint: 'পণ্য বাছুন',
      onQueryChanged: (value) => setState(() => _query = value),
      itemCount: filtered.length,
      itemBuilder: (context, index) {
        final product = filtered[index];
        final stock = StockRecord.forProduct(product.id);
        return ListTile(
          title: Text(product.name),
          subtitle: Text([
            if (product.unit != null) product.unit!,
            // Absent for a salesman by design — docs/Contract §০: stock
            // records are never sent to that role, so the line is simply not
            // drawn rather than shown as zero.
            if (stock != null) 'বিক্রয়যোগ্য ${Money.plain(stock.available)}',
          ].join(' · ')),
          trailing: product.salePrice == null
              ? null
              : Text(Money.taka(product.salePrice),
                  style: const TextStyle(fontWeight: FontWeight.w600)),
          onTap: () => Navigator.of(context).pop(product),
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
