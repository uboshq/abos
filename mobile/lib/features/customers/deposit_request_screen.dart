import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/orders/deposit_request_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/permissions/camera_shot.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

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
    // ⓘ লেখার সঙ্গে পর্দা আবার আঁকে — "পিছনে"-র পাহারা লেখা দেখতে পায় ([[UnsavedGuard]])
    for (final c in [_amount, _reference, _note]) {
      c.addListener(_redraw);
    }
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
          _shares.putIfAbsent(b.id, () => TextEditingController()..addListener(_redraw));
        }
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.customersDepositLoadFailed,
            whenAbsent: context.l10n.customersDepositAbsent));
      }
    }
  }

  Future<void> _pickSlip() async {
    final path = widget.picker != null
        ? await widget.picker!()
        : (await takeCameraShot(context, imageQuality: 70, maxWidth: 1800))?.path;
    if (path != null && mounted) setState(() => _slip = path);
  }

  Future<void> _send() async {
    // ⓘ ১,২৫০ বা 1,250 — পাঠানো হয় পরিষ্কার রূপে ([[Money.typedText]])
    final amount = Money.typedText(_amount.text);
    if ((Money.typed(amount) ?? 0) <= 0) {
      setState(() => _error = context.l10n.customersDepositNeedAmount);
      return;
    }
    if (_method == 'bank' && _slip == null) {
      setState(() => _error = context.l10n.customersDepositNeedSlip);
      return;
    }
    final shares = <BillShare>[];
    var shared = 0.0;
    for (final b in _bills) {
      final text = Money.typedText(_shares[b.id]?.text ?? '');
      final value = Money.typed(text);
      // ⛔ লেখা আছে অথচ পড়া যায় না — আগে চুপচাপ বাদ পড়ত, আর বিজ্ঞপ্তি ঐ বিলের ভাগ ছাড়াই যেত (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
      if (value == null || (text.isNotEmpty && value <= 0)) {
        setState(() => _error = context.l10n.customersDepositShareUnreadable(b.no));
        return;
      }
      if (value <= 0) continue;
      if (value > b.due + 0.001) {
        setState(() => _error = context.l10n.customersDepositShareOverDue(b.no, Money.taka(b.due)));
        return;
      }
      shares.add(BillShare(b.id, text));
      shared += value;
    }
    if (shared > (Money.typed(amount) ?? 0) + 0.001) {
      setState(() => _error = context.l10n.customersDepositSharesOverAmount(Money.taka(shared), Money.taka(Money.typed(amount) ?? 0)));
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
        _done = context.l10n.customersDepositSent;
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
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.customersDepositSendFailed));
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

  void _redraw() {
    if (mounted) setState(() {});
  }

  /// লেখা আছে যা এখনো পাঠানো হয়নি — অঙ্ক, নম্বর, কথা, বিলের ভাগ বা স্লিপের ছবি
  bool get _unsent =>
      _slip != null ||
      [_amount, _reference, _note, ..._shares.values].any((c) => c.text.trim().isNotEmpty);

  // ⭐ অঙ্ক বা স্লিপ বা বিলের ভাগ লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা বিজ্ঞপ্তি এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: !_busy && _unsent, child: _screen(context));

  Widget _screen(BuildContext context) {
    final customer = CustomerRecord.byId(widget.customerId);
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.customersDepositNotice),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (customer != null)
            Text(customer.label, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          Text(context.l10n.customersDepositIntro,
              style: TextStyle(color: context.palette.onSurfaceMuted)),
          const SizedBox(height: AppSpacing.md),
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) _note2(_error!, context.palette.danger, context.palette.dangerSurface),
          if (_done != null) _note2(_done!, context.palette.success, context.palette.successSurface),
          TextField(
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: context.l10n.customersDepositAmount),
          ),
          const SizedBox(height: AppSpacing.sm),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(context.l10n.customersDepositDate),
            subtitle: Text('${_date.day}/${_date.month}/${_date.year}'),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _pickDate,
          ),
          SegmentedButton<String>(
            segments: [
              ButtonSegment(value: 'bank', label: Text(context.l10n.customersDepositBank)),
              ButtonSegment(value: 'mfs', label: Text(context.l10n.customersDepositMfs)),
              ButtonSegment(value: 'cash', label: Text(context.l10n.customersDepositCash)),
            ],
            selected: {_method},
            onSelectionChanged: (s) => setState(() => _method = s.first),
          ),
          const SizedBox(height: AppSpacing.sm),
          if (_method == 'bank')
            DropdownButtonFormField<int>(
              initialValue: _bank,
              isExpanded: true,
              decoration: InputDecoration(labelText: context.l10n.customersDepositWhichBank),
              items: [
                for (final b in _banks) DropdownMenuItem(value: b.id, child: Text(b.name, overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _bank = v),
            ),
          if (_method != 'cash')
            TextField(
              controller: _reference,
              maxLength: 64,
              decoration: InputDecoration(labelText: context.l10n.customersDepositReference),
            ),
          if (_bills.isNotEmpty) ...[
            const SizedBox(height: AppSpacing.sm),
            Text(context.l10n.customersDepositAgainstBills, style: const TextStyle(fontWeight: FontWeight.w700)),
            Text(context.l10n.customersDepositBillsHint,
                style: TextStyle(color: context.palette.onSurfaceMuted)),
            // ⓘ এক লাইনে এক বিল — মালিকের নিয়ম; টেবিল নয়
            for (final b in _bills)
              Row(
                children: [
                  Expanded(child: Text(context.l10n.customersDepositBillDue(b.no, Money.taka(b.due)))),
                  SizedBox(
                    width: 120,
                    child: TextField(
                      key: ValueKey('bill-share-${b.id}'),
                      controller: _shares[b.id],
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      textAlign: TextAlign.end,
                      decoration: InputDecoration(hintText: context.l10n.customersDepositOnThisBill),
                    ),
                  ),
                ],
              ),
          ],
          TextField(controller: _note, decoration: InputDecoration(labelText: context.l10n.customersDepositNote)),
          const SizedBox(height: AppSpacing.md),
          if (_method != 'cash')
            OutlinedButton.icon(
              onPressed: _busy ? null : _pickSlip,
              icon: Icon(_slip == null ? Icons.photo_camera_outlined : Icons.check_circle, color: _slip == null ? null : context.palette.success),
              label: Text(_slip == null ? context.l10n.customersDepositTakeSlip : context.l10n.customersDepositSlipTaken),
            ),
          const SizedBox(height: AppSpacing.sm),
          FilledButton.icon(
            onPressed: _busy ? null : _send,
            icon: const Icon(Icons.send_outlined),
            label: Text(context.l10n.customersDepositSend),
          ),
          const Divider(height: AppSpacing.xl),
          Text(context.l10n.customersDepositHistory(_history.length), style: const TextStyle(fontWeight: FontWeight.w700)),
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
                          'accepted' => context.palette.success,
                          'rejected' => context.palette.danger,
                          _ => context.palette.pending,
                        })),
                    if (row.date != null) Text(context.l10n.customersDepositHistoryDate(row.date!)),
                    if (row.submittedBy != null) Text(context.l10n.customersDepositHistoryBy(row.submittedBy!)),
                    if (row.reference != null) Text(context.l10n.customersDepositHistoryRef(row.reference!)),
                    for (final (no, amount) in row.bills) Text(context.l10n.customersDepositHistoryBill(no, Money.taka(amount))),
                    if (row.status == 'rejected' || (row.reason ?? '').isNotEmpty)
                      Text(context.l10n.customersDepositHistoryReason((row.reason ?? '').isEmpty ? context.l10n.customersDepositReasonNotGiven : row.reason!),
                          style: row.status == 'rejected' ? TextStyle(color: context.palette.danger) : null),
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
