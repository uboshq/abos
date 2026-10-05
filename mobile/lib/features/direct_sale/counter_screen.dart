import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/orders/direct_sale_api.dart';
import '../../core/orders/sales_return_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/product_record.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/confirm_overview_sheet.dart';
import '../printing/document_actions_sheet.dart';
import 'return_screen.dart';

/// সরাসরি বিক্রয়ের কাউন্টার — ফোনে (0.4.9, মালিক ৪ অক্টোবর ২০২৬: *"direct sales er counter banaw app e"*)।
///
/// <p>⭐ মালিকের কাউন্টারের নিয়ম ([[owner-direct-sale-screen-requirements]]):
/// কার্টের সারি কেবল পড়ার — বদলাতে "সম্পাদনা" চাপলে সারিটা উপরের ঘরে ফেরে, বোতাম "হালনাগাদ করুন";
/// এক পণ্য-লট একবারই; শূন্য দর কার্টে ঢোকে না; অনুমোদন ও ঋণসীমার বার্তা পপ-আপে; "খসড়া রাখুন" ধূসর।
/// <p>⛔ সব দেয়াল সার্ভারের (ওয়েবের একই দরজা) — ফোন কেবল জিজ্ঞেস করে আর উত্তরটা সত্যি করে দেখায়।
final _taka = NumberFormat.decimalPattern('en_IN');

class CounterScreen extends StatefulWidget {
  const CounterScreen(
      {super.key,
      this.api = const ServerDirectSaleApi(),
      this.returnApi = const ServerSalesReturnApi(),
      this.customers,
      this.products});

  final DirectSaleApi api;

  /// ⭐ "ফেরত" বোতামের পর্দা — ওয়েবের ফেরতের একই সেবা ([[ReturnScreen]])
  final SalesReturnApi returnApi;

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
  final _shipTo = TextEditingController();
  final _voidReason = TextEditingController();

  bool _busy = false;
  String? _error;

  late final List<CustomerRecord> _customers =
      widget.customers ?? CustomerRecord.all();
  late final List<ProductRecord> _products =
      (widget.products ?? ProductRecord.all())
          .where((p) => p.isActive)
          .toList();

  @override
  void initState() {
    super.initState();
    _loadSetup();
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
      _shipTo,
      _voidReason
    ]) {
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
        if (setup.paymentTerms.isNotEmpty &&
            !setup.paymentTerms.any((t) => t.id == _term)) {
          _term = setup.paymentTerms.first.id;
        }
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback:
                'কাউন্টার খোলা গেল না। নেট আছে কি না দেখে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ফোনের কাউন্টার এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<CounterLot> get _lotsOfProduct =>
      _product == null ? const [] : (_setup?.lots[_product!.id] ?? const []);

  bool get _tracked =>
      _product != null && (_setup?.lots.containsKey(_product!.id) ?? false);

  Future<T?> _pick<T>(String title, List<T> items, String Function(T) label) =>
      showModalBottomSheet<T>(
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
          productId: product.id,
          warehouseId: _warehouseId,
          qty: qty,
          lotId: _lot?.id);
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
      _discount.text =
          line.discountPercent > 0 ? line.discountPercent.toString() : '';
      _free.text = line.freeQty > 0 ? '${line.freeQty}' : '';
      _editingKey = line.key;
    });
  }

  Future<void> _sell({required bool draft}) async {
    final customer = _customer;
    if (customer == null || _cart.isEmpty) {
      setState(() => _error =
          customer == null ? 'ক্রেতা বাছুন।' : 'কার্টে অন্তত একটা পণ্য দিন।');
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
        extras: _extras,
      );
      if (!mounted) return;
      setState(() => _busy =
          false); // ⓘ পপ-আপের আগেই — নইলে "চলছে" দাগ পপ-আপের পেছনে ঘুরতেই থাকে
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
          if (result.invoiceId != null) {
            _lastInvoiceId = result.invoiceId;
            _lastInvoiceNo = result.invoiceNo;
          }
          _startOver();
        });
      }
    } catch (e) {
      // ⓘ সার্ভারের দেয়াল (ঋণসীমা, লট, দর …) — পপ-আপে, মালিকের নিয়ম
      if (mounted) setState(() => _busy = false);
      if (mounted) {
        await _popup(
            'বিক্রি হলো না',
            errorMessageFor(e,
                fallback: 'বিক্রি করা গেল না। আবার চেষ্টা করুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// "নিশ্চিত করুন" — আগে সার্ভারের সারাংশ ([[ConfirmOverview]]), তারপর মানুষের সিদ্ধান্ত
  Future<void> _review() async {
    final customer = _customer;
    if (customer == null || _cart.isEmpty) {
      setState(() => _error =
          customer == null ? 'ক্রেতা বাছুন।' : 'কার্টে অন্তত একটা পণ্য দিন।');
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
            'সারাংশ আনা গেল না',
            errorMessageFor(e,
                fallback: 'নেট আছে কি না দেখে আবার চেষ্টা করুন।'));
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
              Text('টাকা নেওয়া', style: Theme.of(sheet).textTheme.titleMedium),
              for (final d in rows)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('৳ ${_taka.format(d.amount)}'),
                  subtitle: Text([
                    setup.moneyAccounts
                            .where((a) => a.id == d.accountId)
                            .firstOrNull
                            ?.label ??
                        '',
                    if (d.reference != null) 'রেফারেন্স: ${d.reference}',
                  ].join('\n')),
                  trailing: IconButton(
                    icon: const Icon(Icons.close),
                    tooltip: 'বাদ দিন',
                    onPressed: () => setSheet(() => rows.remove(d)),
                  ),
                ),
              if (setup.depositMethods.isNotEmpty)
                DropdownButtonFormField<String>(
                  initialValue: method?.id,
                  decoration: const InputDecoration(labelText: 'কীভাবে'),
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
                decoration: const InputDecoration(labelText: 'কোন খাতে'),
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
                decoration: const InputDecoration(labelText: 'টাকা'),
              ),
              if (method?.needsReference ?? false)
                TextField(
                    controller: reference,
                    decoration: const InputDecoration(
                        labelText: 'রেফারেন্স / লেনদেন নম্বর')),
              const SizedBox(height: AppSpacing.sm),
              OutlinedButton(
                key: const ValueKey('counter-money-add'),
                onPressed: () {
                  final value = double.tryParse(amount.text.trim()) ?? 0;
                  if (value <= 0 || account == null) return;
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
                child: const Text('আরেকটা টাকা যোগ করুন'),
              ),
              Text(
                  'মোট নেওয়া: ৳ ${_taka.format(rows.fold<double>(0, (s, d) => s + d.amount))}'),
              FilledButton(
                key: const ValueKey('counter-money-save'),
                onPressed: () {
                  final value = double.tryParse(amount.text.trim()) ?? 0;
                  if (value > 0 && account != null) {
                    rows.add(CounterDeposit(
                        accountId: account!,
                        amount: value,
                        methodId: method?.id,
                        reference: reference.text));
                  }
                  Navigator.pop(sheet, rows);
                },
                child: const Text('ঠিক আছে'),
              ),
            ],
          ),
        ),
      ),
    );
    if (saved != null && mounted) setState(() => _deposits = saved);
  }

  /// গাড়ি ও ভাড়া — কার গাড়ি, বাহক, ভাড়া কত, কে দেবে
  Future<void> _vehicleAndFare() async {
    final setup = _setup;
    var owner = _delivery.vehicleOwner;
    var carrier = _delivery.carrierId;
    var paidBy = _delivery.farePaidBy;
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
              Text('গাড়ি ও ভাড়া',
                  style: Theme.of(sheet).textTheme.titleMedium),
              DropdownButtonFormField<String>(
                key: const ValueKey('counter-vehicle-owner'),
                initialValue: owner,
                decoration: const InputDecoration(labelText: 'কার গাড়ি'),
                items: const [
                  DropdownMenuItem(value: 'own', child: Text('আমাদের গাড়ি')),
                  DropdownMenuItem(value: 'hired', child: Text('ভাড়ার গাড়ি')),
                  DropdownMenuItem(
                      value: 'customer', child: Text('ক্রেতার গাড়ি')),
                  DropdownMenuItem(
                      value: 'none', child: Text('গাড়ি লাগবে না')),
                ],
                onChanged: (v) => setSheet(() => owner = v),
              ),
              if (setup != null &&
                  setup.carriers.isNotEmpty &&
                  owner == 'hired')
                DropdownButtonFormField<String>(
                  initialValue: carrier,
                  decoration:
                      const InputDecoration(labelText: 'বাহক (পরিবহনকারী)'),
                  items: [
                    for (final c in setup.carriers)
                      DropdownMenuItem(value: c.id, child: Text(c.label))
                  ],
                  onChanged: (v) => setSheet(() => carrier = v),
                ),
              if (owner == 'hired')
                TextField(
                    controller: name,
                    decoration: const InputDecoration(
                        labelText: 'বাহকের নাম (তালিকায় না থাকলে)')),
              if (owner != 'none' && owner != 'customer')
                TextField(
                  key: const ValueKey('counter-fare'),
                  controller: fare,
                  keyboardType:
                      const TextInputType.numberWithOptions(decimal: true),
                  decoration: const InputDecoration(labelText: 'ভাড়া'),
                ),
              DropdownButtonFormField<String>(
                key: const ValueKey('counter-fare-paid-by'),
                initialValue: paidBy,
                decoration: const InputDecoration(labelText: 'ভাড়া কে দেবে'),
                items: const [
                  DropdownMenuItem(
                      value: 'us', child: Text('আমরা — আমাদের খরচ')),
                  DropdownMenuItem(
                      value: 'us_add_to_bill',
                      child: Text('আমরা দেব, বিলে যোগ হবে')),
                  DropdownMenuItem(
                      value: 'customer', child: Text('ক্রেতা চালককে দেবেন')),
                  DropdownMenuItem(value: 'none', child: Text('ভাড়া নেই')),
                ],
                onChanged: (v) => setSheet(() => paidBy = v),
              ),
              const SizedBox(height: AppSpacing.sm),
              FilledButton(
                key: const ValueKey('counter-vehicle-save'),
                onPressed: () => Navigator.pop(sheet, true),
                child: const Text('ঠিক আছে'),
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
            carrierId: owner == 'hired' ? carrier : null,
            carrierName: owner == 'hired' ? name.text : null,
            fare: (owner == 'none' || owner == 'customer')
                ? 0
                : (double.tryParse(fare.text.trim()) ?? 0),
            farePaidBy: paidBy,
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
              Text('মাল কীভাবে যাবে',
                  style: Theme.of(sheet).textTheme.titleMedium),
              for (final option in const [
                ('take_now', 'ক্রেতা এখনই নিয়ে যাবেন'),
                ('send_later', 'পরে পাঠানো হবে'),
                ('pickup_later', 'ক্রেতা পরে এসে নেবেন'),
              ])
                ListTile(
                  key: ValueKey('counter-delivery-${option.$1}'),
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(
                      mode == option.$1
                          ? Icons.radio_button_checked
                          : Icons.radio_button_off,
                      color: AppColors.primary),
                  title: Text(option.$2),
                  onTap: () => setSheet(() => mode = option.$1),
                ),
              if (mode == 'send_later') ...[
                TextField(
                  key: const ValueKey('counter-ship-to'),
                  controller: address,
                  decoration:
                      const InputDecoration(labelText: 'কোথায় পাঠানো হবে'),
                ),
                ListTile(
                  key: const ValueKey('counter-ship-date'),
                  contentPadding: EdgeInsets.zero,
                  title: Text(date == null
                      ? 'কবে পাঠানো হবে — তারিখ বাছুন'
                      : 'পাঠানোর তারিখ: $date'),
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
                child: const Text('ঠিক আছে'),
              ),
            ],
          ),
        ),
      ),
    );
    if (saved == true && mounted) {
      setState(() => _delivery = CounterDelivery(
            mode: mode,
            shipTo: mode == 'send_later' ? address.text.trim() : null,
            shipDate: mode == 'send_later' ? date : null,
            vehicleOwner: _delivery.vehicleOwner,
            carrierId: _delivery.carrierId,
            carrierName: _delivery.carrierName,
            fare: _delivery.fare,
            farePaidBy: _delivery.farePaidBy,
          ));
    }
  }

  /// খসড়া খুলুন — রাখা খসড়ার তালিকা; বাছলে ক্রেতা আর সারি কার্টে, পাঠালে এই খসড়াটাই পাকা হয়
  Future<void> _openDraft() async {
    List<CounterDraftSummary> list;
    try {
      list = await widget.api.drafts();
    } catch (e) {
      if (mounted) {
        await _popup(
            'খসড়া আনা গেল না',
            errorMessageFor(e,
                fallback: 'নেট আছে কি না দেখে আবার চেষ্টা করুন।'));
      }
      return;
    }
    if (!mounted) return;
    if (list.isEmpty) {
      await _popup('খসড়া নেই', 'রাখা কোনো খসড়া নেই।');
      return;
    }
    final picked = await _pick<CounterDraftSummary>('খসড়া খুলুন', list,
        (d) => '${d.no} · ${d.customer} · ৳ ${d.total} · ${d.lines}টি পণ্য');
    if (picked == null || !mounted) return;
    try {
      final draft = await widget.api.openDraft(picked.id);
      if (!mounted) return;
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
        await _popup('খসড়া খোলা গেল না',
            errorMessageFor(e, fallback: 'আবার চেষ্টা করুন।'));
      }
    }
  }

  /// আবার ছাপা — এই কাউন্টারের শেষ বিল: দেখা, ছাপা, পাঠানো
  Future<void> _reprint() async {
    final id = _lastInvoiceId;
    if (id == null) {
      await _popup('আবার ছাপা',
          'এই কাউন্টারে এখনো কোনো বিল হয়নি। পুরনো বিল ছাপতে বিলের তালিকা থেকে খুলুন।');
      return;
    }
    await DocumentActionsSheet.show(context,
        type: 'SalesInvoice',
        id: id,
        title: 'বিল · ${_lastInvoiceNo ?? ''}',
        fileStem: _lastInvoiceNo);
  }

  /// বিল বাতিল — কারণসহ; খোলা খসড়া হলে খসড়াটাই বাতিল, নইলে না-জমা বিলটা অডিটে লেখা হয়
  Future<void> _voidBill() async {
    if (_cart.isEmpty && _resumeId == null) {
      await _popup('বিল বাতিল', 'বাতিল করার মতো কিছু নেই।');
      return;
    }
    final reason = _voidReason..clear();
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        key: const ValueKey('counter-void-dialog'),
        title: const Text('বিল বাতিল'),
        content: TextField(
          key: const ValueKey('counter-void-reason'),
          controller: reason,
          autofocus: true,
          decoration:
              const InputDecoration(labelText: 'কেন বাতিল (বাধ্যতামূলক)'),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(dialog, false),
              child: const Text('ফিরে যান')),
          FilledButton(
            key: const ValueKey('counter-void-confirm'),
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
            onPressed: () {
              if (reason.text.trim().isNotEmpty) Navigator.pop(dialog, true);
            },
            child: const Text('বাতিল করুন'),
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
      await _popup('বিল বাতিল হলো', 'কারণসহ খাতায় লেখা থাকল।');
    } catch (e) {
      if (mounted) {
        await _popup(
            'বাতিল হলো না', errorMessageFor(e, fallback: 'আবার চেষ্টা করুন।'));
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
              backgroundColor: marked ? AppColors.successSurface : null,
              foregroundColor: colour,
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

  Future<void> _popup(String title, String text) => showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          key: const ValueKey('counter-popup'),
          title: Text(title),
          content: Text(text),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(dialogContext),
                child: const Text('ঠিক আছে'))
          ],
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
                child: Text(_error!,
                    key: const ValueKey('counter-error'),
                    style: const TextStyle(color: AppColors.danger)),
              ),
            ),
          if (_resumeNo != null)
            Card(
              color: AppColors.warningSurface,
              child: ListTile(
                key: const ValueKey('counter-resume-banner'),
                title: Text('খসড়া $_resumeNo খোলা আছে'),
                subtitle: const Text(
                    'নিশ্চিত করলে এই খসড়াটাই পাকা হবে, নতুন বিল নয়।'),
              ),
            ),
          Card(
            child: ListTile(
              key: const ValueKey('counter-customer'),
              leading: const Icon(Icons.storefront_outlined),
              title: Text(_customer?.name ?? 'ক্রেতা বাছুন'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () async {
                final picked = await _pick<CustomerRecord>(
                    'ক্রেতা বাছুন', _customers, (c) => c.name);
                if (picked != null) setState(() => _customer = picked);
              },
            ),
          ),
          if (setup != null && setup.warehouses.length > 1)
            DropdownButtonFormField<String>(
              initialValue: _warehouseId,
              decoration: const InputDecoration(labelText: 'গুদাম'),
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
              decoration: const InputDecoration(labelText: 'শর্ত'),
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
                    title: Text(_product?.name ?? 'পণ্য বাছুন'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () async {
                      final picked = await _pick<ProductRecord>(
                          'পণ্য বাছুন', _products, (p) => p.name);
                      if (picked == null) return;
                      setState(() {
                        _product = picked;
                        _lot = _lotsOfProduct
                            .firstOrNull; // ⓘ FEFO-র প্রথমটা আগে থেকে — বদলানো যায়
                        _rate.text = (picked.salePrice ?? 0) > 0
                            ? picked.salePrice!.toStringAsFixed(2)
                            : '';
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
                          DropdownMenuItem(
                              value: l.id,
                              child: Text(
                                  '${l.no} · মেয়াদ ${l.expiry} · আছে ${l.qty}')),
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
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'পরিমাণ'),
                    onChanged: (_) => _askFree(),
                  ),
                  TextField(
                    key: const ValueKey('counter-rate'),
                    controller: _rate,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(labelText: 'দর'),
                  ),
                  TextField(
                    key: const ValueKey('counter-discount'),
                    controller: _discount,
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(
                        labelText: 'ছাড় % (দিলে মালিকের সই লাগবে)'),
                  ),
                  TextField(
                    key: const ValueKey('counter-free'),
                    controller: _free,
                    keyboardType: TextInputType.number,
                    decoration: InputDecoration(
                      labelText: _freeAllowed == null
                          ? 'ফ্রি'
                          : 'ফ্রি (স্কিমে সর্বোচ্চ $_freeAllowed)',
                    ),
                  ),
                  const SizedBox(height: AppSpacing.sm),
                  FilledButton.tonal(
                    key: const ValueKey('counter-add'),
                    onPressed: _addOrUpdate,
                    child: Text(_editingKey == null
                        ? 'কার্টে যোগ করুন'
                        : 'হালনাগাদ করুন'),
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
                    if (line.discountPercent > 0)
                      Text('ছাড়: ${line.discountPercent}%'),
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
                  key: const ValueKey('counter-total'),
                  style: Theme.of(context).textTheme.titleMedium),
            ),

          // ⓘ কী কী বাছা আছে — এক লাইনে একটা (মালিক, ১ অক্টোবর: ডান দিক কেটে যায়)
          if (_deposits.isNotEmpty)
            Text(
                'নেওয়া টাকা: ৳ ${_taka.format(_deposits.fold<double>(0, (s, d) => s + d.amount))}',
                key: const ValueKey('counter-money-summary')),
          if (_delivery.mode != null)
            Text('ডেলিভারি: ${switch (_delivery.mode) {
              'send_later' =>
                'পরে পাঠানো — ${_delivery.shipTo ?? ''} · ${_delivery.shipDate ?? ''}',
              'pickup_later' => 'ক্রেতা পরে নেবেন',
              _ => 'এখনই নিয়ে যাবেন',
            }}'),
          if (_delivery.fare > 0)
            Text('ভাড়া: ৳ ${_taka.format(_delivery.fare)}'),
          TextField(
              controller: _note,
              decoration: const InputDecoration(labelText: 'বিবরণ (ঐচ্ছিক)')),
          const SizedBox(height: AppSpacing.md),

          // ── ⭐ ওয়েবের কাউন্টারের ৮ বোতাম, মালিকের ক্রমে (৪ অক্টোবর ২০২৬) ─────────────
          Row(children: [
            _button('counter-money', 'টাকা নেওয়া', Icons.payments_outlined,
                _busy || setup == null ? null : _takeMoney,
                marked: _deposits.isNotEmpty),
            _button('counter-vehicle', 'গাড়ি ও ভাড়া',
                Icons.local_shipping_outlined, _busy ? null : _vehicleAndFare,
                marked: _delivery.vehicleOwner != null),
            _button('counter-delivery', 'ডেলিভারি', Icons.inventory_2_outlined,
                _busy ? null : _deliveryMode,
                marked: _delivery.mode != null),
            _button(
                'counter-return',
                'ফেরত',
                Icons.undo,
                _busy
                    ? null
                    : () => Navigator.of(context).push(MaterialPageRoute<void>(
                          builder: (_) => ReturnScreen(
                              customerId: _customer?.id, api: widget.returnApi),
                        ))),
          ]),
          Row(children: [
            _button('counter-draft', 'খসড়া রাখুন', Icons.save_outlined,
                _busy ? null : () => _sell(draft: true),
                colour: Colors.black54),
            _button('counter-open-draft', 'খসড়া খুলুন',
                Icons.folder_open_outlined, _busy ? null : _openDraft),
            _button('counter-reprint', 'আবার ছাপা', Icons.print_outlined,
                _busy ? null : _reprint),
            _button('counter-void', 'বিল বাতিল', Icons.delete_outline,
                _busy ? null : _voidBill,
                colour: AppColors.danger),
          ]),
          const SizedBox(height: AppSpacing.xs),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              key: const ValueKey('counter-confirm'),
              // ⭐ আগে সারাংশ — মালিক, ৪ অক্টোবর ২০২৬; সারাংশ থেকেই "নিশ্চিত" বা "খসড়া"
              onPressed: _busy ? null : _review,
              child: Text(_resumeNo == null
                  ? 'নিশ্চিত করুন'
                  : 'নিশ্চিত করুন — খসড়া $_resumeNo'),
            ),
          ),
        ],
      ),
    );
  }
}

class _PickSheet<T> extends StatefulWidget {
  const _PickSheet(
      {required this.title, required this.items, required this.label});

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
    final shown = q.isEmpty
        ? widget.items
        : widget.items
            .where((i) => widget.label(i).toLowerCase().contains(q))
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
