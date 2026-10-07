import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/orders/deposit_request_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// দোকানির জমার বিজ্ঞপ্তি, ব্যাংক স্লিপের ছবিসহ (0.4.3; নাম ৭ অক্টোবর ২০২৬)।
///
/// <p>⭐ মালিক, ১ অক্টোবর ২০২৬: *"customer payment dile bank slip soho ekta request paTanor bebosta app e
/// thakbe"*। SR স্লিপের ছবি তোলেন, পাঠান; হিসাবরক্ষক মিলিয়ে গ্রহণ করলে তবেই বকেয়া কমে — তাই পর্দা "অপেক্ষায়"
/// বলে, "জমা হয়েছে" কখনো নয়। নিচে এই দোকানের আগের বিজ্ঞপ্তিগুলো, অবস্থাসহ।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস, কোনো টেবিল নয়।
class DepositRequestScreen extends StatefulWidget {
  const DepositRequestScreen({
    super.key,
    required this.customerId,
    this.api = const ServerDepositRequestApi(),
    this.picker,
  });

  final String customerId;
  final DepositRequestApi api;

  /// পরীক্ষায় ক্যামেরা ছাড়া একটা পথ দিতে
  final Future<String?> Function()? picker;

  @override
  State<DepositRequestScreen> createState() => _DepositRequestScreenState();
}

class _DepositRequestScreenState extends State<DepositRequestScreen> {
  final _amount = TextEditingController();
  final _reference = TextEditingController();
  final _note = TextEditingController();
  /// ⭐ এই কাজের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]], অডিট ফোন ⚠️১২)
  final _once = OnceKey();

  String _method = 'bank';
  DateTime _date = DateTime.now();
  int? _bank;
  String? _slip;

  List<BankChoice> _banks = const [];
  List<DepositRequestRow> _history = const [];

  /// ⭐ কোন বিলের বিপরীতে — ঐচ্ছিক (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬); বিল ধরে অঙ্কের ঘর
  List<OpenBill> _bills = const [];
  final Map<String, TextEditingController> _shares = {};
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
    _amount.dispose();
    _reference.dispose();
    _note.dispose();
    for (final c in _shares.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final banks = await widget.api.banks();
      final history = await widget.api.forCustomer(widget.customerId);
      // ⓘ পুরনো সার্ভারে দরজাটা নেই — তখন বিলের ঘর থাকে না, বাকি পর্দা আগের মতো
      final bills = await widget.api.openBills(widget.customerId).catchError((_) => const <OpenBill>[]);
      if (!mounted) return;
      setState(() {
        _banks = banks;
        _history = history;
        _bills = bills;
        for (final b in bills) {
          _shares.putIfAbsent(b.id, TextEditingController.new);
        }
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'তথ্য আনা যায়নি।',
            whenAbsent: 'সার্ভারে এই সুবিধা এখনো আসেনি — অফিসে জানান।'));
      }
    }
  }

  Future<void> _pickSlip() async {
    final path = widget.picker != null
        ? await widget.picker!()
        : (await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 70, maxWidth: 1800))?.path;
    if (path != null && mounted) setState(() => _slip = path);
  }

  Future<void> _send() async {
    final amount = _amount.text.trim();
    if ((double.tryParse(amount) ?? 0) <= 0) {
      setState(() => _error = 'টাকার অঙ্ক লিখুন।');
      return;
    }
    if (_method == 'bank' && _slip == null) {
      setState(() => _error = 'ব্যাংকে জমায় স্লিপের ছবি দিতেই হবে।');
      return;
    }
    final shares = <BillShare>[];
    var shared = 0.0;
    for (final b in _bills) {
      final text = _shares[b.id]?.text.trim() ?? '';
      final value = double.tryParse(text) ?? 0;
      if (value <= 0) continue;
      if (value > b.due + 0.001) {
        setState(() => _error = 'বিল ${b.no}-এর বকেয়া ${Money.taka(b.due)} — তার বেশি এই বিলে দেখানো যায় না।');
        return;
      }
      shares.add(BillShare(b.id, text));
      shared += value;
    }
    if (shared > (double.tryParse(amount) ?? 0) + 0.001) {
      setState(() => _error = 'বিলগুলোতে মোট ${Money.taka(shared)}, অথচ জমা ${Money.taka(double.tryParse(amount) ?? 0)} — বিলের ভাগ জমার চেয়ে বেশি হতে পারে না।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _done = null;
    });
    try {
      await _once.send(() => widget.api.send(
        customerId: widget.customerId,
        date: _date,
        amount: amount,
        method: _method,
        bankAccountId: _method == 'bank' ? _bank : null,
        reference: _method == 'cash' ? null : _reference.text.trim(),
        note: _note.text.trim(),
        slipPath: _slip,
        bills: shares,
      ));
      if (!mounted) return;
      setState(() {
        _done = 'পাঠানো হয়েছে — হিসাবরক্ষক মিলিয়ে দেখবেন। ততক্ষণ বকেয়া কমবে না।';
        _amount.clear();
        _reference.clear();
        _note.clear();
        for (final c in _shares.values) {
          c.clear();
        }
        _slip = null;
      });
      await _load();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'পাঠানো যায়নি। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime.now().subtract(const Duration(days: 60)),
      lastDate: DateTime.now(),
    );
    if (picked != null) setState(() => _date = picked);
  }

  @override
  Widget build(BuildContext context) {
    final customer = CustomerRecord.byId(widget.customerId);
    return Scaffold(
      appBar: AppBar(title: const Text('জমার বিজ্ঞপ্তি')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (customer != null)
            Text(customer.name, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          const Text('হিসাবরক্ষক স্লিপ মিলিয়ে গ্রহণ করলে তবেই বকেয়া কমবে।',
              style: TextStyle(color: AppColors.onSurfaceMuted)),
          const SizedBox(height: AppSpacing.md),
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) _note2(_error!, AppColors.danger, AppColors.dangerSurface),
          if (_done != null) _note2(_done!, AppColors.success, AppColors.successSurface),
          TextField(
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'টাকা'),
          ),
          const SizedBox(height: AppSpacing.sm),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('জমার তারিখ'),
            subtitle: Text('${_date.day}/${_date.month}/${_date.year}'),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _pickDate,
          ),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'bank', label: Text('ব্যাংক')),
              ButtonSegment(value: 'mfs', label: Text('বিকাশ/নগদ')),
              ButtonSegment(value: 'cash', label: Text('নগদ')),
            ],
            selected: {_method},
            onSelectionChanged: (s) => setState(() => _method = s.first),
          ),
          const SizedBox(height: AppSpacing.sm),
          if (_method == 'bank')
            DropdownButtonFormField<int>(
              initialValue: _bank,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'কোন ব্যাংকে'),
              items: [
                for (final b in _banks) DropdownMenuItem(value: b.id, child: Text(b.name, overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _bank = v),
            ),
          if (_method != 'cash')
            TextField(
              controller: _reference,
              maxLength: 64,
              decoration: const InputDecoration(labelText: 'স্লিপ / লেনদেন নম্বর'),
            ),
          if (_bills.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            const Text('কোন বিলের বিপরীতে (ঐচ্ছিক)', style: TextStyle(fontWeight: FontWeight.w700)),
            const Text('বিল বাছলে গ্রহণের সময় টাকা সেই বিলগুলোতে মিলবে; না বাছলে মোট টাকা দোকানের খাতায় বসবে।',
                style: TextStyle(color: AppColors.onSurfaceMuted)),
            // ⓘ এক লাইনে এক বিল — মালিকের নিয়ম; টেবিল নয়
            for (final b in _bills)
              Row(
                children: [
                  Expanded(child: Text('${b.no} · বকেয়া ${Money.taka(b.due)}')),
                  SizedBox(
                    width: 120,
                    child: TextField(
                      key: ValueKey('bill-share-${b.id}'),
                      controller: _shares[b.id],
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      textAlign: TextAlign.end,
                      decoration: const InputDecoration(hintText: 'এই বিলে'),
                    ),
                  ),
                ],
              ),
          ],
          TextField(controller: _note, decoration: const InputDecoration(labelText: 'আর কিছু বলার')),
          const SizedBox(height: AppSpacing.md),
          if (_method != 'cash')
            OutlinedButton.icon(
              onPressed: _busy ? null : _pickSlip,
              icon: Icon(_slip == null ? Icons.photo_camera_outlined : Icons.check_circle, color: _slip == null ? null : AppColors.success),
              label: Text(_slip == null ? 'স্লিপের ছবি তুলুন' : 'ছবি নেওয়া হয়েছে — আবার তুলুন'),
            ),
          const SizedBox(height: AppSpacing.sm),
          FilledButton.icon(
            onPressed: _busy ? null : _send,
            icon: const Icon(Icons.send_outlined),
            label: const Text('পাঠান'),
          ),
          const Divider(height: AppSpacing.xl),
          Text('আগের বিজ্ঞপ্তি (${_history.length})', style: const TextStyle(fontWeight: FontWeight.w700)),
          for (final row in _history)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.sm),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(Money.taka(row.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text(row.statusLabel,
                        style: TextStyle(
                            color: switch (row.status) {
                          'accepted' => AppColors.success,
                          'rejected' => AppColors.danger,
                          _ => AppColors.pending,
                        })),
                    if (row.date != null) Text('তারিখ ${row.date}'),
                    if (row.reference != null) Text('নম্বর ${row.reference}'),
                    for (final (no, amount) in row.bills) Text('বিল $no · ${Money.taka(amount)}'),
                    if (row.status == 'rejected' || (row.reason ?? '').isNotEmpty)
                      Text('কারণ: ${(row.reason ?? '').isEmpty ? 'জানানো হয়নি' : row.reason}',
                          style: row.status == 'rejected' ? const TextStyle(color: AppColors.danger) : null),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _note2(String text, Color ink, Color bg) => Card(
        color: bg,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.sm),
          child: Text(text, style: TextStyle(color: ink)),
        ),
      );
}
