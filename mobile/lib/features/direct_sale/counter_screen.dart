import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/orders/direct_sale_api.dart';
import '../../core/orders/sales_return_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/status/paper_state.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/confirm_overview_sheet.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/state_views.dart';
import '../books/books_widgets.dart' show dayOf;
import '../../core/widgets/design_list_row.dart';
import '../printing/document_actions_sheet.dart';
import 'return_screen.dart';
import '../../core/widgets/unsaved_guard.dart';

/// সরাসরি বিক্রয়ের কাউন্টার — ফোনে (0.4.9, মালিক ৪ অক্টোবর ২০২৬: *"direct sales er counter banaw app e"*)।
///
/// <p>⭐ মালিকের কাউন্টারের নিয়ম ([[owner-direct-sale-screen-requirements]]):
/// কার্টের সারি কেবল পড়ার — বদলাতে "সম্পাদনা" চাপলে সারিটা উপরের ঘরে ফেরে, বোতাম "হালনাগাদ করুন";
/// এক পণ্য-লট একবারই; শূন্য দর কার্টে ঢোকে না; অনুমোদন ও ঋণসীমার বার্তা পপ-আপে; "খসড়া রাখুন" ধূসর।
/// <p>⛔ সব দেয়াল সার্ভারের (ওয়েবের একই দরজা) — ফোন কেবল জিজ্ঞেস করে আর উত্তরটা সত্যি করে দেখায়।

class CounterScreen extends StatefulWidget {
  const CounterScreen(
      {super.key,
      this.api = const ServerDirectSaleApi(),
      this.returnApi = const ServerSalesReturnApi(),
      this.customers,
      this.products,
      this.openDrafts = false});

  final DirectSaleApi api;

  /// ⭐ মেনুর "খসড়ার তালিকা" (`/sales/direct/drafts`) — কাউন্টার খুলেই রাখা খসড়ার তালিকা (মালিক, ১৭ অক্টোবর ২০২৬)
  final bool openDrafts;

  /// ⭐ "ফেরত" বোতামের পর্দা — ওয়েবের ফেরতের একই সেবা ([[ReturnScreen]])
  final SalesReturnApi returnApi;

  /// পরীক্ষার জন্য — না দিলে ফোনে জমা তালিকা
  final List<CustomerRecord>? customers;
  final List<ProductRecord>? products;

  @override
  State<CounterScreen> createState() => _CounterScreenState();
}

class _CounterScreenState extends State<CounterScreen> {
  /// ⛔ এই বিক্রির চাবি — সময় পেরোনোর পরে আবার চাপলে একই বিক্রি, দ্বিতীয় বিল নয় ([[OnceKey]]; ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
  final _once = OnceKey();
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

  /// দরটা কোথা থেকে — সার্ভারের কথায় ("দামের তালিকা: …"); ফোনের জমা দাম হলে null
  String? _rateNote;

  /// মানুষ নিজে দর লিখেছেন — তখন ক্রেতার দাম আর বসে না (পণ্য বদলালে আবার না-লেখা)
  bool _rateTyped = false;

  // ⭐ ওয়েবের ৮ বোতামের অবস্থা — মালিক, ৪ অক্টোবর ২০২৬
  List<CounterDeposit> _deposits = const [];
  CounterDelivery _delivery = const CounterDelivery();
  String? _resumeId;
  String? _resumeNo;
  String? _lastInvoiceId;
  String? _lastInvoiceNo;
  final _note = TextEditingController();

  // ⓘ পপ-আপের ঘর পর্দার নিজের — বন্ধ হওয়ার অ্যানিমেশন চলাকালীনও ঘরগুলো পড়া হয়, তাই পপ-আপ বন্ধের সাথে সাথে মোছা যায় না
  final _moneyAmount = TextEditingController();
  final _moneyReference = TextEditingController();
  final _carrierName = TextEditingController();
  final _fareText = TextEditingController();
  final _fareReference = TextEditingController();
  final _shipTo = TextEditingController();
  final _voidReason = TextEditingController();

  bool _busy = false;
  String? _error;

  /// কাউন্টার খোলার (গুদাম, শর্ত, লট) ভুল — আলাদা, যাতে "আবার চেষ্টা করুন" কেবল খোলাটাই আবার চালায় (নকশা ৩, পাতা ৮)
  String? _setupError;

  late final List<CustomerRecord> _customers =
      widget.customers ?? CustomerRecord.all();
  late final List<ProductRecord> _products =
      (widget.products ?? ProductRecord.all())
          .where((p) => p.isActive)
          .toList();

  @override
  void initState() {
    super.initState();
    _loadSetup().then((_) {
      if (mounted && widget.openDrafts) _openDraft();
    });
  }

  @override
  void dispose() {
    for (final c in [
      _qty,
      _rate,
      _discount,
      _free,
      _note,
      _moneyAmount,
      _moneyReference,
      _carrierName,
      _fareText,
      _fareReference,
      _shipTo,
      _voidReason
    ]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _loadSetup([String? warehouseId]) async {
    setState(() {
      _busy = true;
      _setupError = null;
    });
    try {
      final setup = await widget.api.setup(warehouseId: warehouseId);
      if (!mounted) return;
      setState(() {
        _setup = setup;
        _warehouseId = setup.warehouseId;
        if (setup.paymentTerms.isNotEmpty &&
            !setup.paymentTerms.any((t) => t.id == _term)) {
          _term = setup.paymentTerms.first.id;
        }
      });
    } catch (e) {
      if (mounted) {
        setState(() => _setupError = errorMessageFor(e,
            fallback: context.l10n.counterLoadFailed,
            whenAbsent: context.l10n.counterAbsent));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<CounterLot> get _lotsOfProduct =>
      _product == null ? const [] : (_setup?.lots[_product!.id] ?? const []);

  bool get _tracked =>
      _product != null && (_setup?.lots.containsKey(_product!.id) ?? false);

  /// সার্ভার গুদাম ঠিক করতে পারেনি — তখন লটের তালিকা ফাঁকা, আর লট-ধরা পণ্য লট ছাড়াই কার্টে ঢুকে পরে সার্ভারে আটকাত
  bool get _noWarehouse => _setup != null && _warehouseId == null;

  Future<T?> _pick<T>(String title, List<T> items, String Function(T) label, {bool customers = false}) =>
      showModalBottomSheet<T>(
        context: context,
        isScrollControlled: true,
        builder: (_) => _PickSheet<T>(title: title, items: items, label: label, customers: customers),
      );

  Future<void> _askFree() async {
    final product = _product;
    final qty = _typedQty(_qty.text);
    // ⓘ পরিমাণ মুছলে আগের ফ্রিও যায় — পুরনো অঙ্ক বসে থাকত
    if (product != null && qty <= 0 && _editingKey == null) setState(() => _free.clear());
    if (product == null || qty <= 0) return;
    final lot = _lot?.id;
    try {
      final allowed = await widget.api.freeAllowed(
          productId: product.id,
          warehouseId: _warehouseId,
          qty: qty,
          lotId: lot);
      if (!mounted) return;
      // ⛔ উত্তর আসতে আসতে পণ্য, লট বা পরিমাণ বদলেছে — পুরনো প্রশ্নের উত্তর ফেলে দেওয়া; আগে ১০০-র (১০ ফ্রি) উত্তর শেষে এসে
      // ১০-এর বিক্রিতে ১০ ফ্রি বসাত (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
      if (_product?.id != product.id || _lot?.id != lot || _typedQty(_qty.text) != qty) return;
      setState(() {
        _freeAllowed = allowed;
        // ⓘ স্কিমের ফ্রি নিজে বসে (মালিক, ১ অক্টোবর: "free qty own auto box")
        if (allowed != null && _editingKey == null) _free.text = '$allowed';
      });
    } catch (_) {
      if (mounted) setState(() => _freeAllowed = null);
    }
  }

  /// ⭐ ক্রেতার দাম — ক্রেতা বাছা থাকলে সার্ভারের দামের তালিকার দর (ওয়েবের কাউন্টারের একই উৎস, `?customer=`);
  /// ⓘ নেট না থাকলে বা পুরনো সার্ভারে ফোনের জমা দাম যেমন ছিল তেমনই থাকে। ⛔ ততক্ষণে পণ্য বদলালে বা হাতে দর
  /// লিখলে উত্তরটা ফেলে দেওয়া হয় — মানুষের লেখা দর সার্ভারের দর মুছবে না।
  Future<void> _customerRate(ProductRecord product) async {
    final customer = _customer;
    if (customer == null || _rateTyped) return;
    try {
      final price = await widget.api.price(product.id,
          warehouseId: _warehouseId, customerId: customer.id);
      final rate = double.tryParse(price.rate) ?? 0;
      if (!mounted ||
          _product?.id != product.id ||
          _customer?.id != customer.id ||
          _rateTyped ||
          rate <= 0) {
        return;
      }
      setState(() {
        _rate.text = Money.rate(rate);
        _rateNote = price.priceLabel.isEmpty ? null : price.priceLabel;
      });
    } catch (_) {
      // ⓘ অফলাইন — আগের দাম থাকে
    }
  }

  void _clearEntry() {
    _product = null;
    _lot = null;
    _editingKey = null;
    _freeAllowed = null;
    _rateNote = null;
    _rateTyped = false;
    for (final c in [_qty, _rate, _discount, _free]) {
      c.clear();
    }
  }

  /// মানুষের লেখা পরিমাণ — বাংলা অঙ্ক আর কমাসহ ([[Money.typed]]); খালি ০, পড়া না গেলে −১ (তখন "পরিমাণ দিন")
  double _typedQty(String text) => Money.typed(text) ?? -1;

  /// ⛔ এককটা ভগ্নাংশ না নিলে পূর্ণ সংখ্যা, নিলে তিন দশমিক পর্যন্ত (পূর্ণ নিরীক্ষা §৯ ⚠️১০) — বাধা থাকলে কারণ, নইলে null
  String? _fractionRefused(ProductRecord? product, double qty) {
    if (qty <= 0 || qty == qty.roundToDouble()) return null;
    if (product == null || !product.allowsFraction) {
      return context.l10n.counterNoFraction(product?.unit ?? context.l10n.counterThisUnit);
    }
    if ((qty * 1000 - (qty * 1000).roundToDouble()).abs() > 1e-6) {
      return context.l10n.counterQtyDecimals('3');
    }
    return null;
  }

  void _addOrUpdate() {
    final product = _product;
    final qty = _typedQty(_qty.text);
    final rate = double.tryParse(_rate.text.trim()) ?? 0;
    final discount = double.tryParse(_discount.text.trim()) ?? 0;
    final free = _typedQty(_free.text);

    String? why;
    if (_noWarehouse) {
      why = context.l10n.counterNoWarehouseLot;
    } else if (product == null) {
      why = context.l10n.counterPickProduct;
    } else if (_tracked && _lot == null) {
      why = context.l10n.counterPickLot;
    } else if (qty <= 0) {
      why = context.l10n.counterNeedQty;
    } else if (_fractionRefused(product, qty) ?? _fractionRefused(product, free) case final refused?) {
      why = refused;
    } else if (rate <= 0) {
      why = context.l10n.counterNeedRate; // ⓘ শূন্য দর কার্টে ঢোকে না (মালিক)
    } else if (discount < 0 || discount > 100) {
      why = context.l10n.counterDiscountRange('0', '100');
    } else if (_freeAllowed != null && free > _freeAllowed!) {
      why = context.l10n.counterFreeMax('$_freeAllowed');
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
      _qty.text = CounterLine.wire(line.qty);
      _rate.text = Money.rate(line.rate);
      _discount.text =
          line.discountPercent > 0 ? line.discountPercent.toString() : '';
      _free.text = line.freeQty > 0 ? CounterLine.wire(line.freeQty) : '';
      _editingKey = line.key;
    });
  }

  Future<void> _sell({required bool draft, bool repeat = false}) async {
    final customer = _customer;
    if (_noWarehouse) {
      setState(
          () => _error = context.l10n.counterNoWarehouseSale);
      return;
    }
    if (customer == null || _cart.isEmpty) {
      setState(() => _error =
          customer == null ? context.l10n.counterPickBuyer : context.l10n.counterCartEmpty);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await _once.send(() => widget.api.sell(
        customerId: customer.id,
        warehouseId: _warehouseId,
        paymentTerm: _term,
        lines: List.of(_cart),
        draft: draft,
        extras: repeat ? _extras.repeating() : _extras,
      ));
      if (!mounted) return;
      setState(() => _busy =
          false); // ⓘ পপ-আপের আগেই — নইলে "চলছে" দাগ পপ-আপের পেছনে ঘুরতেই থাকে
      // ⭐ পাকা বিক্রির পরে "বিলটা দেখুন" — বিলের পাতায় (মালিক, ১৩ অক্টোবর ২০২৬: বিক্রির পরে বিলটা ফোনে কোথাও ছিল না)
      final billId = result.status == 'done' ? result.invoiceId : null;
      final open = await _popup(
        switch (result.status) {
          'done' => context.l10n.counterSold,
          'parked' => context.l10n.counterDraftKept,
          _ => context.l10n.counterAwaitingSignature,
        },
        result.notice,
        action: billId == null ? null : context.l10n.counterOpenBill,
      );
      if (mounted) {
        setState(() {
          if (result.invoiceId != null) {
            _lastInvoiceId = result.invoiceId;
            _lastInvoiceNo = result.invoiceNo;
          }
          _startOver();
        });
      }
      if (open == true && billId != null && mounted) {
        await context.push('/home/${RecentPapers.path('invoice', billId)}');
      }
    } catch (e) {
      // ⓘ সার্ভারের দেয়াল (ঋণসীমা, লট, দর …) — পপ-আপে, মালিকের নিয়ম
      if (mounted) setState(() => _busy = false);

      // ⭐ একই বিল দুবার — সার্ভার একবার থামায়; মানুষ টিক দিয়ে "আবার করুন" চাপলে তবেই জেনেশুনে পাঠানো
      final duplicate = duplicateWarning(e);
      if (duplicate != null && !repeat && mounted) {
        if (await _askRepeat(duplicate) == true && mounted) {
          await _sell(draft: draft, repeat: true);
        }
        return;
      }

      if (mounted) {
        await _popup(
            context.l10n.counterSaleFailed,
            errorMessageFor(e,
                fallback: context.l10n.counterSaleFailedRetry));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// "নিশ্চিত করুন" — আগে সার্ভারের সারাংশ ([[ConfirmOverview]]), তারপর মানুষের সিদ্ধান্ত
  Future<void> _review() async {
    final customer = _customer;
    if (_noWarehouse) {
      setState(
          () => _error = context.l10n.counterNoWarehouseSale);
      return;
    }
    if (customer == null || _cart.isEmpty) {
      setState(() => _error =
          customer == null ? context.l10n.counterPickBuyer : context.l10n.counterCartEmpty);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    ConfirmOverviewData? data;
    try {
      data = await widget.api.overview(
        customerId: customer.id,
        warehouseId: _warehouseId,
        paymentTerm: _term,
        lines: List.of(_cart),
        extras: _extras,
      );
    } catch (e) {
      if (mounted) setState(() => _busy = false);
      if (mounted) {
        await _popup(
            context.l10n.counterOverviewFailed,
            errorMessageFor(e,
                fallback: context.l10n.counterCheckNet));
      }
      return;
    }
    if (!mounted) return;
    setState(() => _busy = false);
    final choice = await showConfirmOverview(context, data);
    if (!mounted) return;
    switch (choice) {
      case OverviewChoice.confirm:
        await _sell(draft: false);
      case OverviewChoice.draft:
        await _sell(draft: true);
      case OverviewChoice.back || null:
        break; // ⓘ কার্টে ফেরা — কিছুই বদলায় না
    }
  }

  CounterExtras get _extras => CounterExtras(
      deposits: _deposits,
      delivery: _delivery,
      resumeId: _resumeId,
      note: _note.text);

  void _startOver() {
    _cart.clear();
    _note.clear();
    _deposits = const [];
    _delivery = const CounterDelivery();
    _resumeId = null;
    _resumeNo = null;
    _clearEntry();
  }

  // ── ⭐ ৮ বোতাম — প্রতিটার নিজের পপ-আপ (ওয়েবের কাউন্টারের হুবহু ক্রম) ──────────

  /// টাকা নেওয়া — এক বিলে কয়েক রকম টাকা; পদ্ধতি বাছলে তার খাত নিজে বসে
  Future<void> _takeMoney() async {
    final setup = _setup;
    if (setup == null) return;
    final rows = List<CounterDeposit>.of(_deposits);
    CounterMethod? method = setup.depositMethods.firstOrNull;
    String? account = method?.accountId ?? setup.moneyAccounts.firstOrNull?.id;
    final amount = _moneyAmount..clear();
    final reference = _moneyReference..clear();
    String? moneyError;
    final saved = await showModalBottomSheet<List<CounterDeposit>>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => StatefulBuilder(
        builder: (sheet, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(
              AppSpacing.md,
              AppSpacing.md,
              AppSpacing.md,
              MediaQuery.of(sheet).viewInsets.bottom + AppSpacing.md),
          child: ListView(
            key: const ValueKey('counter-money-sheet'),
            shrinkWrap: true,
            children: [
              Text(context.l10n.counterMoneyTitle, style: Theme.of(sheet).textTheme.titleMedium),
              for (final d in rows)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text(Money.taka(d.amount)),
                  subtitle: Text([
                    setup.moneyAccounts
                            .where((a) => a.id == d.accountId)
                            .firstOrNull
                            ?.label ??
                        '',
                    if (d.reference != null) context.l10n.counterMoneyReference('${d.reference}'),
                  ].join('\n')),
                  trailing: IconButton(
                    icon: const Icon(Icons.close),
                    tooltip: context.l10n.counterRemove,
                    onPressed: () => setSheet(() => rows.remove(d)),
                  ),
                ),
              if (setup.depositMethods.isNotEmpty)
                DropdownButtonFormField<String>(
                  initialValue: method?.id,
                  decoration: InputDecoration(labelText: context.l10n.counterMoneyMethod),
                  items: [
                    for (final m in setup.depositMethods)
                      DropdownMenuItem(value: m.id, child: Text(m.label))
                  ],
                  onChanged: (v) => setSheet(() {
                    method = setup.depositMethods
                        .where((m) => m.id == v)
                        .firstOrNull;
                    if (method?.accountId != null &&
                        method!.accountId!.isNotEmpty) {
                      account = method!.accountId;
                    }
                  }),
                ),
              DropdownButtonFormField<String>(
                key: ValueKey('counter-money-account-$account'),
                initialValue: account,
                decoration: InputDecoration(labelText: context.l10n.counterMoneyAccount),
                items: [
                  for (final a in setup.moneyAccounts)
                    DropdownMenuItem(value: a.id, child: Text(a.label))
                ],
                onChanged: (v) => setSheet(() => account = v),
              ),
              TextField(
                key: const ValueKey('counter-money-amount'),
                controller: amount,
                keyboardType:
                    const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: context.l10n.counterMoneyAmount, errorText: moneyError),
              ),
              if (method?.needsReference ?? false)
                TextField(
                    controller: reference,
                    decoration: InputDecoration(
                        labelText: context.l10n.counterMoneyReferenceLabel)),
              const SizedBox(height: AppSpacing.sm),
              OutlinedButton(
                key: const ValueKey('counter-money-add'),
                onPressed: () {
                  final value = Money.typed(amount.text);
                  if (value == null || (amount.text.trim().isNotEmpty && value <= 0)) {
                    setSheet(() => moneyError = _unreadableAmount);
                    return;
                  }
                  if (value <= 0 || account == null) return;
                  moneyError = null;
                  setSheet(() {
                    rows.add(CounterDeposit(
                        accountId: account!,
                        amount: value,
                        methodId: method?.id,
                        reference: reference.text));
                    amount.clear();
                    reference.clear();
                  });
                },
                child: Text(context.l10n.counterMoneyAddAnother),
              ),
              Text(
                  context.l10n.counterMoneyTotal(Money.taka(rows.fold<double>(0, (s, d) => s + d.amount)))),
              FilledButton(
                key: const ValueKey('counter-money-save'),
                onPressed: () {
                  final value = Money.typed(amount.text);
                  // ⛔ লেখা আছে অথচ পড়া যায় না — শিট খোলা থাকে, বার্তাসহ; আগে চুপচাপ বাদ পড়ত আর বিল পুরো বাকিতে যেত
                  if (value == null || (amount.text.trim().isNotEmpty && value <= 0)) {
                    setSheet(() => moneyError = _unreadableAmount);
                    return;
                  }
                  if (value > 0 && account != null) {
                    rows.add(CounterDeposit(
                        accountId: account!,
                        amount: value,
                        methodId: method?.id,
                        reference: reference.text));
                  }
                  Navigator.pop(sheet, rows);
                },
                child: Text(context.l10n.counterOk),
              ),
            ],
          ),
        ),
      ),
    );
    if (saved != null && mounted) setState(() => _deposits = saved);
  }

  static String get _unreadableAmount => AppLocale.l10n.counterUnreadableAmount;

  /// বাছা খাত নগদ নয় (ব্যাংক বা MFS) — তখন TrxID আর "কে দিলেন" লাগে
  static bool _isNonCash(CounterSetup? setup, String? accountId) {
    if (setup == null || accountId == null) return false;
    for (final a in setup.moneyAccounts) {
      if (a.id == accountId) return a.kind != 'cash';
    }
    return false;
  }

  /// গাড়ি ও ভাড়া — কার গাড়ি, বাহক, ভাড়া কত, কে দেবে
  Future<void> _vehicleAndFare() async {
    final setup = _setup;
    var owner = _delivery.vehicleOwner;
    var carrier = _delivery.carrierId;
    var paidBy = _delivery.farePaidBy;
    var when = _delivery.fareWhen ?? 'now';
    var account = _delivery.fareAccount;
    var payer = _delivery.farePayer;
    final reference = _fareReference..text = _delivery.fareReference ?? '';
    final name = _carrierName..text = _delivery.carrierName ?? '';
    final fare = _fareText
      ..text = _delivery.fare > 0 ? _delivery.fare.toStringAsFixed(2) : '';
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => StatefulBuilder(
        builder: (sheet, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(
              AppSpacing.md,
              AppSpacing.md,
              AppSpacing.md,
              MediaQuery.of(sheet).viewInsets.bottom + AppSpacing.md),
          child: ListView(
            key: const ValueKey('counter-vehicle-sheet'),
            shrinkWrap: true,
            children: [
              Text(context.l10n.counterVehicleTitle,
                  style: Theme.of(sheet).textTheme.titleMedium),
              DropdownButtonFormField<String>(
                key: const ValueKey('counter-vehicle-owner'),
                initialValue: owner,
                decoration: InputDecoration(labelText: context.l10n.counterVehicleOwner),
                items: [
                  DropdownMenuItem(value: 'own', child: Text(context.l10n.counterVehicleOwn)),
                  DropdownMenuItem(value: 'hired', child: Text(context.l10n.counterVehicleHired)),
                  DropdownMenuItem(
                      value: 'customer', child: Text(context.l10n.counterVehicleCustomer)),
                  DropdownMenuItem(
                      value: 'none', child: Text(context.l10n.counterVehicleNone)),
                ],
                onChanged: (v) => setSheet(() => owner = v),
              ),
              if (setup != null &&
                  setup.carriers.isNotEmpty &&
                  owner == 'hired')
                DropdownButtonFormField<String>(
                  initialValue: carrier,
                  decoration:
                      InputDecoration(labelText: context.l10n.counterCarrier),
                  items: [
                    for (final c in setup.carriers)
                      DropdownMenuItem(value: c.id, child: Text(c.label))
                  ],
                  onChanged: (v) => setSheet(() => carrier = v),
                ),
              if (owner == 'hired')
                TextField(
                    controller: name,
                    decoration: InputDecoration(
                        labelText: context.l10n.counterCarrierName)),
              if (owner != 'none' && owner != 'customer')
                TextField(
                  key: const ValueKey('counter-fare'),
                  controller: fare,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(labelText: context.l10n.counterFare),
                ),
              DropdownButtonFormField<String>(
                key: const ValueKey('counter-fare-paid-by'),
                initialValue: paidBy,
                decoration: InputDecoration(labelText: context.l10n.counterFarePaidBy),
                items: [
                  DropdownMenuItem(
                      value: 'us', child: Text(context.l10n.counterFareUs)),
                  DropdownMenuItem(
                      value: 'us_add_to_bill',
                      child: Text(context.l10n.counterFareUsAddToBill)),
                  DropdownMenuItem(
                      value: 'customer', child: Text(context.l10n.counterFareCustomer)),
                  DropdownMenuItem(value: 'none', child: Text(context.l10n.counterFareNone)),
                ],
                onChanged: (v) => setSheet(() => paidBy = v),
              ),
              /*
               * ⭐ ভাড়া আমরা দিলে — কখন, কোন খাত থেকে, TrxID আর কে দিলেন (ওয়েবের কাউন্টারের একই নিয়ম; a5, সার্ভার 272141b9)।
               * ⓘ নগদে TrxID আর "কে দিলেন" নেই — সার্ভার লগইন করা মানুষকেই ধরে; পরে দিলে বাহক লাগে।
               */
              if (paidBy == 'us' || paidBy == 'us_add_to_bill') ...[
                const SizedBox(height: AppSpacing.sm),
                SegmentedButton<String>(
                  key: const ValueKey('counter-fare-when'),
                  segments: [
                    ButtonSegment(value: 'now', label: Text(context.l10n.counterFareNow)),
                    ButtonSegment(value: 'later', label: Text(context.l10n.counterFareLater)),
                  ],
                  selected: {when},
                  onSelectionChanged: (s) => setSheet(() => when = s.first),
                ),
                if (when == 'now' && setup != null) ...[
                  DropdownButtonFormField<String>(
                    key: const ValueKey('counter-fare-account'),
                    initialValue: account,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: context.l10n.counterFareAccount),
                    items: [
                      for (final a in setup.moneyAccounts)
                        DropdownMenuItem(value: a.id, child: Text(a.label, overflow: TextOverflow.ellipsis)),
                    ],
                    onChanged: (v) => setSheet(() => account = v),
                  ),
                  if (_isNonCash(setup, account)) ...[
                    TextField(
                      key: const ValueKey('counter-fare-reference'),
                      controller: reference,
                      decoration: InputDecoration(labelText: context.l10n.counterFareTrxId),
                    ),
                    if (setup.farePayers.isNotEmpty)
                      DropdownButtonFormField<String>(
                        key: const ValueKey('counter-fare-payer'),
                        initialValue: payer,
                        isExpanded: true,
                        decoration: InputDecoration(labelText: context.l10n.counterFarePayer),
                        items: [
                          for (final p in setup.farePayers)
                            DropdownMenuItem(value: p.id, child: Text(p.label, overflow: TextOverflow.ellipsis)),
                        ],
                        onChanged: (v) => setSheet(() => payer = v),
                      ),
                  ],
                ],
                if (when == 'later' && setup != null && setup.carriers.isNotEmpty && owner != 'hired')
                  DropdownButtonFormField<String>(
                    key: const ValueKey('counter-fare-carrier'),
                    initialValue: carrier,
                    isExpanded: true,
                    decoration: InputDecoration(labelText: context.l10n.counterFareCarrierLater),
                    items: [
                      for (final c in setup.carriers) DropdownMenuItem(value: c.id, child: Text(c.label))
                    ],
                    onChanged: (v) => setSheet(() => carrier = v),
                  ),
              ],
              const SizedBox(height: AppSpacing.sm),
              FilledButton(
                key: const ValueKey('counter-vehicle-save'),
                onPressed: () => Navigator.pop(sheet, true),
                child: Text(context.l10n.counterOk),
              ),
            ],
          ),
        ),
      ),
    );
    if (saved == true && mounted) {
      setState(() => _delivery = CounterDelivery(
            mode: _delivery.mode,
            shipTo: _delivery.shipTo,
            shipDate: _delivery.shipDate,
            vehicleOwner: owner,
            // ⓘ ভাড়া পরে দিলে বাহক লাগে — নিজের গাড়ি হলেও (ওয়েবের একই)
            carrierId: (owner == 'hired' || ((paidBy == 'us' || paidBy == 'us_add_to_bill') && when == 'later')) ? carrier : null,
            carrierName: owner == 'hired' ? name.text : null,
            fare: (owner == 'none' || owner == 'customer')
                ? 0
                : (double.tryParse(fare.text.trim()) ?? 0),
            farePaidBy: paidBy,
            fareWhen: when,
            fareAccount: when == 'now' ? account : null,
            fareReference: when == 'now' && _isNonCash(setup, account) ? reference.text.trim() : null,
            farePayer: when == 'now' && _isNonCash(setup, account) ? payer : null,
          ));
    }
  }

  /// ডেলিভারি — এখনই নেবে · পরে পাঠানো হবে (ঠিকানা আর তারিখ) · পরে নিয়ে যাবে
  Future<void> _deliveryMode() async {
    var mode = _delivery.mode ?? 'take_now';
    var date = _delivery.shipDate;
    final address = _shipTo..text = _delivery.shipTo ?? '';
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (sheet) => StatefulBuilder(
        builder: (sheet, setSheet) => Padding(
          padding: EdgeInsets.fromLTRB(
              AppSpacing.md,
              AppSpacing.md,
              AppSpacing.md,
              MediaQuery.of(sheet).viewInsets.bottom + AppSpacing.md),
          child: ListView(
            key: const ValueKey('counter-delivery-sheet'),
            shrinkWrap: true,
            children: [
              Text(context.l10n.counterDeliveryTitle,
                  style: Theme.of(sheet).textTheme.titleMedium),
              for (final option in [
                ('take_now', context.l10n.counterDeliveryTakeNow),
                ('send_later', context.l10n.counterDeliverySendLater),
                ('pickup_later', context.l10n.counterDeliveryPickupLater),
              ])
                ListTile(
                  key: ValueKey('counter-delivery-${option.$1}'),
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(
                      mode == option.$1
                          ? Icons.radio_button_checked
                          : Icons.radio_button_off,
                      color: Theme.of(sheet).colorScheme.primary),
                  title: Text(option.$2),
                  onTap: () => setSheet(() => mode = option.$1),
                ),
              if (mode == 'send_later') ...[
                TextField(
                  key: const ValueKey('counter-ship-to'),
                  controller: address,
                  decoration:
                      InputDecoration(labelText: context.l10n.counterShipTo),
                ),
                ListTile(
                  key: const ValueKey('counter-ship-date'),
                  contentPadding: EdgeInsets.zero,
                  title: Text(date == null
                      ? context.l10n.counterShipDatePick
                      : context.l10n.counterShipDate('$date')),
                  trailing: const Icon(Icons.calendar_today_outlined),
                  onTap: () async {
                    final now = DateTime.now();
                    final picked = await showDatePicker(
                        context: sheet,
                        firstDate: now,
                        lastDate: now.add(const Duration(days: 90)),
                        initialDate: now);
                    if (picked != null) {
                      setSheet(() => date =
                          '${picked.year}-${picked.month.toString().padLeft(2, '0')}-${picked.day.toString().padLeft(2, '0')}');
                    }
                  },
                ),
              ],
              const SizedBox(height: AppSpacing.sm),
              FilledButton(
                key: const ValueKey('counter-delivery-save'),
                onPressed: () => Navigator.pop(sheet, true),
                child: Text(context.l10n.counterOk),
              ),
            ],
          ),
        ),
      ),
    );
    if (saved == true && mounted) {
      setState(() => _delivery = _delivery.copyWith(
            mode: mode,
            shipTo: mode == 'send_later' ? address.text.trim() : null,
            shipDate: mode == 'send_later' ? date : null,
          ));
    }
  }

  /// খসড়া খুলুন — রাখা খসড়ার তালিকা; বাছলে ক্রেতা আর সারি কার্টে, পাঠালে এই খসড়াটাই পাকা হয়
  ///
  /// <p>⭐ তালিকা নিচের পাতায় নিজেই আনে — খসড়া সারি, ভুলে "আবার চেষ্টা করুন", খালিতে "নতুন বিল লিখুন" (নকশা ৩, পাতা ৮–১০);
  /// আগে আনা শেষ না হওয়া পর্যন্ত কিছুই দেখাত না, আর ভুল বা খালি হলে কেবল একটা পপ-আপ।
  Future<void> _openDraft() async {
    final picked = await showModalBottomSheet<CounterDraftSummary>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _DraftsSheet(load: widget.api.drafts),
    );
    if (picked == null || !mounted) return;
    try {
      final draft = await widget.api.openDraft(picked.id);
      if (!mounted) return;
      // ⭐ ভগ্নাংশের খসড়াও ফোনেই খোলে — কার্ট এখন দশমিক নেয় (পূর্ণ নিরীক্ষা §৯ ⚠️১০); আগে "ওয়েবে খুলুন" বলে ফিরিয়ে দিত
      setState(() {
        _startOver();
        _customer =
            _customers.where((c) => c.id == draft.customerId).firstOrNull ??
                CustomerRecord(
                    {'id': draft.customerId, 'nameBn': draft.customerName});
        _cart.addAll(draft.lines);
        _resumeId = draft.id;
        _resumeNo = draft.no;
      });
    } catch (e) {
      if (mounted) {
        await _popup(context.l10n.counterDraftOpenFailed,
            errorMessageFor(e, fallback: context.l10n.counterTryAgain));
      }
    }
  }

  /// ⭐ দাম দেখুন — পণ্য বাছলে দর, বিক্রয়যোগ্য মজুদ আর লট; "বিলে তুলুন" চাপলে উপরের ঘরে বসে (ওয়েবের F3)
  Future<void> _priceCheck() async {
    final picked = await _pick<ProductRecord>(
        context.l10n.counterPriceCheckPick, _products, (p) => p.name);
    if (picked == null || !mounted) return;
    CounterPrice price;
    try {
      price = await widget.api.price(picked.id,
          warehouseId: _warehouseId, customerId: _customer?.id);
    } catch (e) {
      if (mounted) {
        await _popup(context.l10n.counterPriceFailed,
            errorMessageFor(e, fallback: context.l10n.counterTryAgain));
      }
      return;
    }
    if (!mounted) return;
    final toBill = await showModalBottomSheet<bool>(
      context: context,
      builder: (sheet) => Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          key: const ValueKey('counter-price-sheet'),
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
                price.code.isEmpty
                    ? price.name
                    : '${price.name} · ${price.code}',
                style: Theme.of(sheet).textTheme.titleMedium),
            Text(context.l10n.counterPriceRate(Money.taka(price.rate), price.unit)),
            Text(price.available == null
                ? context.l10n.counterPriceStockHidden
                : context.l10n.counterPriceStock('${price.available}', price.unit)),
            for (final lot in price.lots)
              Text([
                context.l10n.counterPriceLot(lot.no),
                if (lot.expiry.isNotEmpty) context.l10n.counterPriceExpiry(lot.expiry),
                if (lot.qty.isNotEmpty) context.l10n.counterPriceHave(lot.qty)
              ].join(' · ')),
            const SizedBox(height: AppSpacing.sm),
            Row(children: [
              TextButton(
                  onPressed: () => Navigator.pop(sheet, false),
                  child: Text(context.l10n.counterBack)),
              const Spacer(),
              FilledButton(
                key: const ValueKey('counter-price-to-bill'),
                onPressed: () => Navigator.pop(sheet, true),
                child: Text(context.l10n.counterPriceToBill),
              ),
            ]),
          ],
        ),
      ),
    );
    if (toBill == true && mounted) {
      setState(() {
        _clearEntry();
        _product = picked;
        _lot = _lotsOfProduct.firstOrNull;
        final rate = double.tryParse(price.rate) ?? 0;
        _rate.text = rate > 0 ? Money.rate(rate) : '';
      });
      _askFree();
    }
  }

  /// আবার ছাপা — এই কাউন্টারের শেষ বিল: দেখা, ছাপা, পাঠানো
  Future<void> _reprint() async {
    final id = _lastInvoiceId;
    if (id == null) {
      await _popup(context.l10n.counterReprint, context.l10n.counterReprintNone);
      return;
    }
    await DocumentActionsSheet.show(context,
        type: 'SalesInvoice',
        id: id,
        title: context.l10n.counterBillTitle(_lastInvoiceNo ?? ''),
        fileStem: _lastInvoiceNo);
  }

  /// বিল বাতিল — কারণসহ; খোলা খসড়া হলে খসড়াটাই বাতিল, নইলে না-জমা বিলটা অডিটে লেখা হয়
  Future<void> _voidBill() async {
    if (_cart.isEmpty && _resumeId == null) {
      await _popup(context.l10n.counterVoidBill, context.l10n.counterVoidNothing);
      return;
    }
    final reason = _voidReason..clear();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        key: const ValueKey('counter-void-dialog'),
        title: Text(context.l10n.counterVoidBill),
        // ⓘ কারণ ওয়েবের পপ-আপের একই তালিকা থেকে; তালিকা না এলে (পুরনো সার্ভার) নিজে লেখা
        content: (_setup?.voidReasons.isNotEmpty ?? false)
            ? StatefulBuilder(
                builder: (_, setDialog) => DropdownButtonFormField<String>(
                  key: const ValueKey('counter-void-reason-pick'),
                  initialValue: reason.text.isEmpty ? null : reason.text,
                  decoration: InputDecoration(
                      labelText: context.l10n.counterVoidReason),
                  items: [
                    for (final r in _setup!.voidReasons)
                      DropdownMenuItem(value: r, child: Text(r))
                  ],
                  onChanged: (v) => setDialog(() => reason.text = v ?? ''),
                ),
              )
            : TextField(
                key: const ValueKey('counter-void-reason'),
                controller: reason,
                autofocus: true,
                decoration:
                    InputDecoration(labelText: context.l10n.counterVoidReason),
              ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(dialog, false),
              child: Text(context.l10n.counterBack)),
          FilledButton(
            key: const ValueKey('counter-void-confirm'),
            style: FilledButton.styleFrom(
                backgroundColor: Theme.of(dialog).colorScheme.error,
                foregroundColor: Theme.of(dialog).colorScheme.onError),
            onPressed: () {
              if (reason.text.trim().isNotEmpty) Navigator.pop(dialog, true);
            },
            child: Text(context.l10n.counterVoidConfirm),
          ),
        ],
      ),
    );
    final why = reason.text.trim();
    if (ok != true || !mounted) return;
    try {
      await widget.api.voidBill(
          reason: why,
          customerId: _customer?.id,
          resumeId: _resumeId,
          lines: _cart.length,
          total: _total);
      if (!mounted) return;
      setState(_startOver);
      await _popup(context.l10n.counterVoided, context.l10n.counterVoidedNote);
    } catch (e) {
      if (mounted) {
        await _popup(
            context.l10n.counterVoidFailed, errorMessageFor(e, fallback: context.l10n.counterTryAgain));
      }
    }
  }

  Widget _button(
          String key, String label, IconData icon, VoidCallback? onPressed,
          {bool marked = false, Color? colour}) =>
      Expanded(
        child: Padding(
          padding: const EdgeInsets.all(2),
          child: OutlinedButton(
            key: ValueKey(key),
            style: OutlinedButton.styleFrom(
              padding: const EdgeInsets.symmetric(
                  vertical: AppSpacing.sm, horizontal: 2),
              // ⓘ বাছা বোতাম সবুজ পটে নিজের গাঢ় সবুজ লেখায় — জোড়া রং, অন্ধকার মোডেও পড়া যায় (নকশার নিয়ম ০.৪.৩৮ §১)
              backgroundColor: marked ? context.palette.successSurface : null,
              foregroundColor: marked ? context.palette.success : colour,
            ),
            onPressed: onPressed,
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Icon(icon, size: 20),
              Text(label,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 11.5)),
            ]),
          ),
        ),
      );

  /// ⭐ "একই বিল আগেই হয়েছে" — সার্ভারের বার্তা, আর একটা টিক; টিক না দিলে "আবার করুন" চাপা যায় না (ওয়েবের একই ঘর)
  Future<bool?> _askRepeat(String warning) {
    var ticked = false;
    return showDialog<bool>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialog) => AlertDialog(
          key: const ValueKey('counter-repeat'),
          title: Text(context.l10n.counterRepeatTitle),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(warning),
              CheckboxListTile(
                key: const ValueKey('counter-repeat-tick'),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: ticked,
                title: Text(context.l10n.counterRepeatTick),
                onChanged: (v) => setDialog(() => ticked = v ?? false),
              ),
            ],
          ),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(dialogContext, false),
                child: Text(context.l10n.counterBack)),
            FilledButton(
                key: const ValueKey('counter-repeat-go'),
                onPressed:
                    ticked ? () => Navigator.pop(dialogContext, true) : null,
                child: Text(context.l10n.counterRepeatGo)),
          ],
        ),
      ),
    );
  }

  /// সার্ভারের কথা পপ-আপে; [action] দিলে দ্বিতীয় বোতাম — চাপলে true
  Future<bool?> _popup(String title, String text, {String? action}) => showDialog<bool>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          key: const ValueKey('counter-popup'),
          title: Text(title),
          content: Text(text),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(dialogContext),
                child: Text(context.l10n.counterOk)),
            if (action != null)
              FilledButton(
                  key: const ValueKey('counter-open-bill'),
                  onPressed: () => Navigator.pop(dialogContext, true),
                  child: Text(action)),
          ],
        ),
      );

  double get _total => _cart.fold(0, (sum, l) => sum + l.total);

  // ⭐ কার্টে মাল থাকলে "পিছনে" জিজ্ঞেস করে — কাউন্টারে লেখা বিল এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _cart.isNotEmpty && !_busy, child: _screen(context));

  Widget _screen(BuildContext context) {
    final setup = _setup;
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.counterTitle, module: context.l10n.ordersModuleSales),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          // ⭐ তিন অবস্থা (নকশা ৩, পাতা ৮–১০): কাউন্টার খোলার সময় খসড়া সারি, পরে উপরে সরু দাগ; খোলা না গেলে "আবার চেষ্টা করুন"
          if (_busy && setup == null && _setupError == null) const LoadingSkeleton(rows: 3),
          if (_busy && setup != null) const LinearProgressIndicator(),
          if (_setupError != null)
            ErrorState(
                key: const ValueKey('counter-setup-error'),
                message: _setupError!,
                onRetry: _busy ? null : () => _loadSetup(_warehouseId)),
          // ⭐ ভুলের পট আর লেখা জোড়া রং — লাল পটে নিজের লাল লেখা, দুই মোডেই পড়া যায়; হলুদ পটে নিজের গাঢ় হলুদ লেখা — থিমের লেখা
          // অন্ধকার মোডে হালকা পটে হারাত (নকশার নিয়ম ০.৪.৩৮ §১, a4, ১৯ অক্টোবর ২০২৬)
          if (_error != null)
            Card(
              color: context.palette.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_error!,
                    key: const ValueKey('counter-error'),
                    style: TextStyle(
                        color: context.palette.danger)),
              ),
            ),
          if (_resumeNo != null)
            Card(
              color: context.palette.warningSurface,
              child: ListTile(
                key: const ValueKey('counter-resume-banner'),
                textColor: context.palette.warning,
                title: Text(context.l10n.counterResumeBanner('$_resumeNo')),
                subtitle: Text(context.l10n.counterResumeNote),
              ),
            ),
          Card(
            child: ListTile(
              key: const ValueKey('counter-customer'),
              leading: const Icon(Icons.storefront_outlined),
              // ⭐ বাছা ক্রেতা "নাম · পয়েন্ট · শাখা" (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম) — খালি নাম কখনো নয়
              title: _customer == null
                  ? Text(context.l10n.counterPickBuyerTitle)
                  : CustomerLine(_customer!.label,
                      key: const ValueKey('counter-customer-line'),
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                final picked = await _pick<CustomerRecord>(
                    context.l10n.counterPickBuyerTitle, _customers, (c) => c.label, customers: true);
                if (picked == null) return;
                setState(() => _customer = picked);
                // ⓘ পণ্য আগে বাছা থাকলে (নতুন সারি) — দরটা এই ক্রেতার তালিকা থেকে
                final product = _product;
                if (product != null && _editingKey == null) {
                  _customerRate(product);
                }
              },
            ),
          ),
          // ⭐ দাম দেখুন — ক্রেতার ঘরের নিচে, ডান দিকে (মালিকের ছবি, ৪ অক্টোবর ২০২৬; ওয়েবের F3)
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              key: const ValueKey('counter-price'),
              onPressed: _busy ? null : _priceCheck,
              icon: const Icon(Icons.price_check, size: 18),
              label: Text(context.l10n.counterPriceCheck),
            ),
          ),
          // ⭐ গুদাম নেই — খালি লটের বদলে স্পষ্ট কথা (৬ অক্টোবর ২০২৬: UB-র AVA TRADE-এ গুদাম না পেয়ে লট আসছিল না)
          if (_noWarehouse)
            Card(
              key: const ValueKey('counter-no-warehouse'),
              color: context.palette.warningSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(
                    setup!.warehouses.isEmpty
                        ? context.l10n.counterNoWarehouseBranch
                        : context.l10n.counterWarehouseNotSet,
                    style: TextStyle(color: context.palette.warning)),
              ),
            ),
          if (setup != null &&
              (setup.warehouses.length > 1 ||
                  (_warehouseId == null && setup.warehouses.isNotEmpty)))
            DropdownButtonFormField<String>(
              initialValue: _warehouseId,
              decoration: InputDecoration(labelText: context.l10n.counterWarehouse),
              items: [
                for (final w in setup.warehouses)
                  DropdownMenuItem(value: w.id, child: Text(w.label))
              ],
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
              decoration: InputDecoration(labelText: context.l10n.counterTerm),
              items: [
                for (final t in setup.paymentTerms)
                  DropdownMenuItem(value: t.id, child: Text(t.label))
              ],
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
                    title: Text(_product?.name ?? context.l10n.counterPickProductTitle),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () async {
                      final picked = await _pick<ProductRecord>(
                          context.l10n.counterPickProductTitle, _products, (p) => p.name);
                      if (picked == null) return;
                      setState(() {
                        _product = picked;
                        _lot = _lotsOfProduct
                            .firstOrNull; // ⓘ FEFO-র প্রথমটা আগে থেকে — বদলানো যায়
                        _rate.text = (picked.salePrice ?? 0) > 0
                            ? Money.rate(picked.salePrice!)
                            : '';
                        _rateNote = null;
                        _rateTyped = false;
                      });
                      _askFree();
                      _customerRate(picked);
                    },
                  ),
                  if (_tracked)
                    DropdownButtonFormField<String>(
                      key: const ValueKey('counter-lot'),
                      initialValue: _lot?.id,
                      decoration: InputDecoration(labelText: context.l10n.counterLot),
                      items: [
                        for (final l in _lotsOfProduct)
                          DropdownMenuItem(
                              value: l.id,
                              child: Text(
                                  [l.no, context.l10n.counterPriceExpiry(l.expiry), if (l.qty.isNotEmpty) context.l10n.counterPriceHave(l.qty)].join(' · '))),
                      ],
                      onChanged: (v) {
                        setState(() => _lot =
                            _lotsOfProduct.where((l) => l.id == v).firstOrNull);
                        _askFree();
                      },
                    ),
                  TextField(
                    key: const ValueKey('counter-qty'),
                    controller: _qty,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: InputDecoration(labelText: context.l10n.counterQty),
                    onChanged: (_) => _askFree(),
                  ),
                  TextField(
                    key: const ValueKey('counter-rate'),
                    controller: _rate,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    decoration:
                        InputDecoration(labelText: context.l10n.counterRate, helperText: _rateNote),
                    // ⓘ হাতে লেখা দর — সার্ভারের দামের নোট আর খাটে না
                    onChanged: (_) => setState(() {
                      _rateTyped = true;
                      _rateNote = null;
                    }),
                  ),
                  TextField(
                    key: const ValueKey('counter-discount'),
                    controller: _discount,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    decoration: InputDecoration(
                        labelText: context.l10n.counterDiscountLabel),
                  ),
                  TextField(
                    key: const ValueKey('counter-free'),
                    controller: _free,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: InputDecoration(
                      labelText: _freeAllowed == null
                          ? context.l10n.counterFree
                          : context.l10n.counterFreeMaxLabel('$_freeAllowed'),
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  FilledButton.tonal(
                    key: const ValueKey('counter-add'),
                    onPressed: _addOrUpdate,
                    child: Text(_editingKey == null
                        ? context.l10n.counterAddToCart
                        : context.l10n.counterUpdate),
                  ),
                ],
              ),
            ),
          ),

          // ── কার্ট — কেবল পড়ার ─────────────────────────────────────
          for (final line in _cart)
            Card(
              // ⓘ তালিকার সারি অন্তত ৭২dp (নকশার নিয়ম ০.৪.৩৮ §২)
              child: ListTile(
                minTileHeight: 72,
                title: Text(line.productName),
                subtitle: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (line.lotNo != null) Text(context.l10n.counterLotNo('${line.lotNo}')),
                    Text('${CounterLine.wire(line.qty)} × ${Money.taka(line.rate)}'),
                    if (line.discountPercent > 0)
                      Text(context.l10n.counterLineDiscount('${line.discountPercent}')),
                    if (line.freeQty > 0) Text(context.l10n.counterLineFree(CounterLine.wire(line.freeQty))),
                    Text(Money.taka(line.total)),
                  ],
                ),
                trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                  IconButton(
                    key: ValueKey('counter-edit-${line.key}'),
                    icon: const Icon(Icons.edit_outlined),
                    tooltip: context.l10n.counterEdit,
                    onPressed: () => _edit(line),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close),
                    tooltip: context.l10n.counterRemove,
                    onPressed: () => setState(() => _cart.remove(line)),
                  ),
                ]),
              ),
            ),
          if (_cart.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: Text(context.l10n.counterRunningTotal(Money.taka(_total)),
                  key: const ValueKey('counter-total'),
                  style: Theme.of(context).textTheme.titleMedium),
            ),

          // ⓘ কী কী বাছা আছে — এক লাইনে একটা (মালিক, ১ অক্টোবর: ডান দিক কেটে যায়)
          if (_deposits.isNotEmpty)
            Text(
                context.l10n.counterMoneyTaken(Money.taka(_deposits.fold<double>(0, (s, d) => s + d.amount))),
                key: const ValueKey('counter-money-summary')),
          if (_delivery.mode != null)
            Text(context.l10n.counterDeliverySummary(switch (_delivery.mode) {
              'send_later' =>
                context.l10n.counterDeliverySummarySend(_delivery.shipTo ?? '', _delivery.shipDate ?? ''),
              'pickup_later' => context.l10n.counterDeliverySummaryPickup,
              _ => context.l10n.counterDeliverySummaryNow,
            })),
          if (_delivery.fare > 0)
            Text(context.l10n.counterFareSummary(Money.taka(_delivery.fare))),
          TextField(
              controller: _note,
              decoration: InputDecoration(labelText: context.l10n.counterNoteLabel)),
          const SizedBox(height: AppSpacing.md),

          // ── ⭐ ওয়েবের কাউন্টারের ৮ বোতাম, মালিকের ক্রমে (৪ অক্টোবর ২০২৬) ─────────────
          Row(children: [
            _button('counter-money', context.l10n.counterMoneyTitle, Icons.payments_outlined,
                _busy || setup == null ? null : _takeMoney,
                marked: _deposits.isNotEmpty),
            _button('counter-vehicle', context.l10n.counterVehicleTitle,
                Icons.local_shipping_outlined, _busy ? null : _vehicleAndFare,
                marked: _delivery.vehicleOwner != null),
            _button('counter-delivery', context.l10n.counterDelivery, Icons.inventory_2_outlined,
                _busy ? null : _deliveryMode,
                marked: _delivery.mode != null),
            _button(
                'counter-return',
                context.l10n.counterReturnButton,
                Icons.undo,
                _busy
                    ? null
                    : () => Navigator.of(context).push(MaterialPageRoute<void>(
                          builder: (_) => ReturnScreen(
                              customerId: _customer?.id, api: widget.returnApi),
                        ))),
          ]),
          Row(children: [
            _button('counter-draft', context.l10n.counterKeepDraft, Icons.save_outlined,
                _busy ? null : () => _sell(draft: true),
                colour: context.palette.onSurfaceMuted),
            _button('counter-open-draft', context.l10n.counterOpenDraft,
                Icons.folder_open_outlined, _busy ? null : _openDraft),
            _button('counter-reprint', context.l10n.counterReprint, Icons.print_outlined,
                _busy ? null : _reprint),
            _button('counter-void', context.l10n.counterVoidBill, Icons.delete_outline,
                _busy ? null : _voidBill,
                colour: Theme.of(context).colorScheme.error),
          ]),
          const SizedBox(height: AppSpacing.xs),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              key: const ValueKey('counter-confirm'),
              // ⭐ আগে সারাংশ — মালিক, ৪ অক্টোবর ২০২৬; সারাংশ থেকেই "নিশ্চিত" বা "খসড়া"
              onPressed: _busy ? null : _review,
              child: Text(_resumeNo == null
                  ? context.l10n.counterConfirm
                  : context.l10n.counterConfirmDraft('$_resumeNo')),
            ),
          ),
        ],
      ),
    );
  }
}

class _PickSheet<T> extends StatefulWidget {
  const _PickSheet(
      {required this.title, required this.items, required this.label, this.customers = false});

  final String title;
  final List<T> items;
  final String Function(T) label;

  /// গ্রাহকের তালিকা — সারি [CustomerLine]: "নাম · পয়েন্ট · শাখা" এক লাইনে, লম্বা হলে "…", পুরোটা ছুঁয়ে ধরে রাখলে
  final bool customers;

  @override
  State<_PickSheet<T>> createState() => _PickSheetState<T>();
}

class _PickSheetState<T> extends State<_PickSheet<T>> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final q = _query.trim().toLowerCase();
    final shown = q.isEmpty
        ? widget.items
        : widget.items
            .where((i) => i is CustomerRecord ? i.matches(q) : widget.label(i).toLowerCase().contains(q))
            .toList();
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.8,
        child: Column(children: [
          Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: TextField(
              decoration: InputDecoration(
                  hintText: widget.title, prefixIcon: const Icon(Icons.search)),
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
          Expanded(
            child: ListView.builder(
              itemCount: shown.length,
              itemBuilder: (_, i) => ListTile(
                minTileHeight: 72,
                title: widget.customers
                    ? CustomerLine(widget.label(shown[i]), style: const TextStyle(fontWeight: FontWeight.w600))
                    : Text(widget.label(shown[i])),
                onTap: () => Navigator.of(context).pop(shown[i]),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

/// ⭐ রাখা খসড়ার তালিকা — নকশা ৩-এর সারি (ক্রেতা "নাম · পয়েন্ট · শাখা", ডানে টাকা, দ্বিতীয় লাইনে খসড়ার নম্বর · তারিখ ·
/// পণ্যের সংখ্যা, "খসড়া" চিপ) আর তিন অবস্থা: খসড়া সারি · ভুলে "আবার চেষ্টা করুন" · খালিতে "নতুন বিল লিখুন"। বাছলে সেই
/// খসড়ার সারাংশ ফেরায়, নইলে null।
class _DraftsSheet extends StatefulWidget {
  const _DraftsSheet({required this.load});

  final Future<List<CounterDraftSummary>> Function() load;

  @override
  State<_DraftsSheet> createState() => _DraftsSheetState();
}

class _DraftsSheetState extends State<_DraftsSheet> {
  List<CounterDraftSummary>? _list;
  String? _error;
  bool _busy = false;

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
      final list = await widget.load();
      if (mounted) setState(() => _list = list);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.counterDraftsLoadFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  static String _second(CounterDraftSummary d) =>
      [d.no, if (d.date != null) dayOf(d.date), AppLocale.l10n.counterDraftLines(d.lines)].join(' · ');

  @override
  Widget build(BuildContext context) {
    final list = _list;
    return SafeArea(
      child: SizedBox(
        height: MediaQuery.of(context).size.height * 0.8,
        child: ListView(
          key: const ValueKey('counter-drafts'),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Text(context.l10n.counterOpenDraft, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: AppSpacing.sm),
            if (_busy && list == null) const LoadingSkeleton(rows: 4),
            if (_error != null)
              ErrorState(key: const ValueKey('counter-drafts-error'), message: _error!, onRetry: _busy ? null : _load),
            if (list != null && list.isEmpty && _error == null)
              EmptyState(
                key: const ValueKey('counter-drafts-empty'),
                icon: Icons.edit_note,
                title: context.l10n.counterDraftsEmpty,
                message: context.l10n.counterDraftsEmptyHint,
                action: FilledButton.icon(
                    key: const ValueKey('counter-drafts-new'),
                    style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
                    onPressed: () => Navigator.of(context).maybePop(),
                    icon: const Icon(Icons.add),
                    label: Text(context.l10n.counterNewBill)),
              ),
            for (final d in list ?? const <CounterDraftSummary>[])
              DesignListRow(
                key: ValueKey('counter-draft-${d.id}'),
                icon: Icons.edit_note,
                name: d.customer.isEmpty ? '—' : d.customer,
                second: _second(d),
                amount: Money.valueOrZero(d.total),
                pill: const AppStatusChip.compact(state: PaperState.draft),
                onTap: () => Navigator.of(context).pop(d),
              ),
          ],
        ),
      ),
    );
  }
}
