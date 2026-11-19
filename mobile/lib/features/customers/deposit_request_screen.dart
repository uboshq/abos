import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/orders/deposit_request_api.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/theme/expressive.dart';
import '../../core/theme/look_widgets.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/permissions/camera_shot.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../scan/slip_scanner_screen.dart';

/// দোকানির জমার বিজ্ঞপ্তি, ব্যাংক স্লিপের ছবিসহ (0.4.3; নাম ৭ অক্টোবর ২০২৬)।
///
/// <p>⭐ মালিক, ১ অক্টোবর ২০২৬: *"customer payment dile bank slip soho ekta request paTanor bebosta app e
/// thakbe"*। SR স্লিপের ছবি তোলেন, পাঠান; হিসাবরক্ষক মিলিয়ে গ্রহণ করলে তবেই বকেয়া কমে — তাই পর্দা "অপেক্ষায়"
/// বলে, "জমা হয়েছে" কখনো নয়। নিচে এই দোকানের আগের বিজ্ঞপ্তিগুলো, অবস্থাসহ।
///
/// <p>⭐ 0.4.58 (মালিক, ১৬ ও ২০ নভেম্বর ২০২৬ "ok", জমার নমুনা): ≡-এর দ্রুত কাজ আর নিচের "জমা" থেকে সরাসরি — দোকান তখন
/// পাতার ভেতরে বাছা ([customerId] null); "কে জমা দিলেন"; স্লিপ ক্যামেরা **আর** গ্যালারি/WhatsApp থেকে, তারপর স্ক্যানার (কোনা,
/// পরিষ্কার/ধূসর/সাদা-কালো, KB); পাঠানোর পরে "পাঠানো" আর সার্ভারের মতো-দেখতে-স্লিপের সতর্কতা (কাজ আটকায় না)।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস, কোনো টেবিল নয়।
class DepositRequestScreen extends StatefulWidget {
  const DepositRequestScreen({
    super.key,
    this.customerId,
    this.api = const ServerDepositRequestApi(),
    this.picker,
    this.galleryPicker,
    this.scanner,
    this.customers,
  });

  /// দোকানের পাতা থেকে খুললে সেই দোকান; দ্রুত কাজ বা "জমা" থেকে খুললে null — পাতায় বাছা হয়
  final String? customerId;
  final DepositRequestApi api;

  /// পরীক্ষায় ক্যামেরা ছাড়া একটা পথ দিতে
  final Future<String?> Function()? picker;

  /// পরীক্ষায় গ্যালারি ছাড়া একটা পথ দিতে
  final Future<String?> Function()? galleryPicker;

  /// স্ক্যানার — না দিলে আসল পর্দা ([openSlipScanner]); পরীক্ষার পথে (ক্যামেরা/গ্যালারি নকল) স্ক্যানার নেই, যদি না দেওয়া হয়
  final Future<SlipScanOutcome?> Function(BuildContext context, String path)? scanner;

  /// দোকানের তালিকা — না দিলে ফোনে সিঙ্ক হওয়া সব দোকান ([CustomerRecord.all])
  final List<CustomerRecord> Function()? customers;

  @override
  State<DepositRequestScreen> createState() => _DepositRequestScreenState();
}

class _DepositRequestScreenState extends State<DepositRequestScreen> {
  final _amount = TextEditingController();
  final _reference = TextEditingController();
  final _note = TextEditingController();
  final _depositor = TextEditingController();
  /// ⭐ এই কাজের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]], অডিট ফোন ⚠️১২)
  final _once = OnceKey();

  String _method = 'bank';
  DateTime _date = DateTime.now();
  int? _bank;
  String? _slip;

  /// বাছা দোকান — দোকানের পাতা থেকে এলে শুরু থেকেই
  String? _customerId;
  String? _customerLabel;

  List<BankChoice> _banks = const [];
  List<DepositRequestRow> _history = const [];

  /// ⭐ কোন বিলের বিপরীতে — ঐচ্ছিক (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬); বিল ধরে অঙ্কের ঘর
  List<OpenBill> _bills = const [];
  final Map<String, TextEditingController> _shares = {};
  bool _busy = false;
  String? _error;
  String? _done;

  /// ⭐ পাঠানোর পরের দুই খবর — "পাঠানো হয়েছে — ৳ …" আর মতো-দেখতে-স্লিপ (সার্ভারের `lookalike_of`)
  String? _sentAmount;
  DepositRequestRow? _sentRow;

  @override
  void initState() {
    super.initState();
    _customerId = widget.customerId;
    // ⓘ লেখার সঙ্গে পর্দা আবার আঁকে — "পিছনে"-র পাহারা লেখা দেখতে পায় ([[UnsavedGuard]])
    for (final c in [_amount, _reference, _note, _depositor]) {
      c.addListener(_redraw);
    }
    _load();
  }

  @override
  void dispose() {
    _amount.dispose();
    _reference.dispose();
    _note.dispose();
    _depositor.dispose();
    for (final c in _shares.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final banks = await widget.api.banks();
      final customer = _customerId;
      final history = customer == null ? const <DepositRequestRow>[] : await widget.api.forCustomer(customer);
      // ⓘ পুরনো সার্ভারে দরজাটা নেই — তখন বিলের ঘর থাকে না, বাকি পর্দা আগের মতো
      final bills = customer == null
          ? const <OpenBill>[]
          : await widget.api.openBills(customer).catchError((_) => const <OpenBill>[]);
      if (!mounted || customer != _customerId) return;
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
            fallback: AppLocale.l10n.customersDepositLoadFailed,
            whenAbsent: AppLocale.l10n.customersDepositAbsent));
      }
    }
  }

  /// ⭐ দোকান বাছা — দ্রুত কাজ বা "জমা" থেকে খুললে (নমুনার "দোকান / ডিলার ⌄")
  Future<void> _pickCustomer() async {
    final all = (widget.customers ?? CustomerRecord.all)();
    final picked = await showModalBottomSheet<CustomerRecord>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _ShopPicker(items: all),
    );
    if (picked == null || !mounted || picked.id == _customerId) return;
    setState(() {
      _customerId = picked.id;
      _customerLabel = picked.label;
      _history = const [];
      _bills = const [];
      for (final c in _shares.values) {
        c.dispose();
      }
      _shares.clear();
      _error = null;
    });
    await _load();
  }

  /// ⭐ স্লিপ — ক্যামেরা বা গ্যালারি/WhatsApp, তারপর স্ক্যানার; "আবার তুলুন" চাপলে একই পথে আবার
  Future<void> _pickSlip({required bool camera}) async {
    while (mounted) {
      final injected = camera ? widget.picker : widget.galleryPicker;
      final path = injected != null
          ? await injected()
          : camera
              ? (await takeCameraShot(context, imageQuality: 90, maxWidth: 2400))?.path
              : (await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 2400, imageQuality: 92))?.path;
      if (path == null || !mounted) return;
      // ⓘ পরীক্ষার নকল ছবিতে স্ক্যানার নেই, যদি না পরীক্ষা নিজে দেয়
      final scan = widget.scanner ?? (injected == null ? openSlipScanner : null);
      if (scan == null) {
        setState(() => _slip = path);
        return;
      }
      final outcome = await scan(context, path);
      if (!mounted || outcome == null) return;
      if (outcome.retake) continue;
      setState(() => _slip = outcome.path);
      return;
    }
  }

  Future<void> _send() async {
    final customer = _customerId;
    if (customer == null) {
      setState(() => _error = context.l10n.customersDepositNeedShop);
      return;
    }
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
      _sentRow = null;
      _sentAmount = null;
    });
    try {
      final row = await _once.send(() => widget.api.send(
        customerId: customer,
        date: _date,
        amount: amount,
        method: _method,
        bankAccountId: _method == 'bank' ? _bank : null,
        reference: _method == 'cash' ? null : _reference.text.trim(),
        note: _note.text.trim(),
        slipPath: _slip,
        bills: shares,
        depositor: _depositor.text.trim(),
      ));
      if (!mounted) return;
      setState(() {
        _done = context.l10n.customersDepositSent;
        _sentAmount = Money.taka(Money.typed(amount) ?? 0);
        _sentRow = row;
        _amount.clear();
        _reference.clear();
        _note.clear();
        _depositor.clear();
        for (final c in _shares.values) {
          c.clear();
        }
        _slip = null;
      });
      await _load();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: AppLocale.l10n.customersDepositSendFailed));
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

  /// লেখা আছে যা এখনো পাঠানো হয়নি — অঙ্ক, নম্বর, কথা, জমাকারী, বিলের ভাগ বা স্লিপের ছবি
  bool get _unsent =>
      _slip != null ||
      [_amount, _reference, _note, _depositor, ..._shares.values].any((c) => c.text.trim().isNotEmpty);

  // ⭐ অঙ্ক বা স্লিপ বা বিলের ভাগ লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা বিজ্ঞপ্তি এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: !_busy && _unsent, child: _screen(context));

  /// ⭐ মতো-দেখতে-স্লিপের সতর্কতা — আগের খবরটা ইতিহাসে থাকলে তার তারিখ আর অঙ্কসহ
  String _lookalikeText(String of) {
    final earlier = _history.where((r) => r.id == of).firstOrNull;
    if (earlier == null) return context.l10n.customersDepositLookalikeUnknown;
    // ⓘ নমুনার মতো "12-11-2026" — ইংরেজি অঙ্কে
    final day = DateTime.tryParse(earlier.date ?? '');
    final date = day == null
        ? (earlier.date ?? '')
        : '${day.day.toString().padLeft(2, '0')}-${day.month.toString().padLeft(2, '0')}-${day.year}';
    return context.l10n.customersDepositLookalikeOf(date, Money.taka(earlier.amount));
  }

  Widget _screen(BuildContext context) {
    final customer = _customerId == null ? null : CustomerRecord.byId(_customerId!);
    final label = customer?.label ?? _customerLabel;
    final look = (Theme.of(context), AppLooks.instance.look.value).$2;
    final fromShortcut = widget.customerId == null;
    final l = context.l10n;
    final lookalike = _sentRow?.lookalikeOf;
    return Scaffold(
      appBar: AbosScreenBar(title: fromShortcut ? l.lookQuickDeposit : l.customersDepositNotice),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (fromShortcut)
            // ⭐ দোকান বাছা — নমুনার প্রথম ঘর
            Card(
              key: const ValueKey('deposit-shop'),
              child: ListTile(
                minTileHeight: 64,
                leading: const Icon(Icons.storefront_outlined),
                title: Text(l.customersDepositShop, style: TextStyle(fontSize: 12.5, color: context.palette.onSurfaceMuted)),
                subtitle: Text(label ?? l.customersDepositPickShop,
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                trailing: const Icon(Icons.expand_more),
                onTap: _busy ? null : _pickCustomer,
              ),
            )
          else if (label != null)
            Text(label, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          Text(l.customersDepositIntro, style: TextStyle(color: context.palette.onSurfaceMuted)),
          const SizedBox(height: AppSpacing.md),
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) _note2(_error!, context.palette.danger, context.palette.dangerSurface),
          if (_done != null)
            _note2(
              _sentAmount == null ? _done! : '${l.customersDepositSentTitle(_sentAmount!)}\n$_done',
              context.palette.success,
              context.palette.successSurface,
              key: const ValueKey('deposit-sent'),
            ),
          if (_done != null && lookalike != null)
            _note2('${l.customersDepositLookalikeTitle}\n${_lookalikeText(lookalike)}', context.palette.warning,
                context.palette.warningSurface,
                key: const ValueKey('deposit-lookalike')),
          LookSurface(
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              TextField(
                controller: _amount,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                // ⓘ Material 3-এ মোটা-চওড়া অঙ্ক (নমুনার `.fld.amt`)
                style: look == AppLook.expressive ? Expressive.bigNumber.copyWith(fontSize: 26) : null,
                decoration: InputDecoration(labelText: l.customersDepositAmount),
              ),
              const SizedBox(height: AppSpacing.sm),
              ListTile(
                contentPadding: EdgeInsets.zero,
                title: Text(l.customersDepositDate),
                subtitle: Text('${_date.day}/${_date.month}/${_date.year}'),
                trailing: const Icon(Icons.calendar_today_outlined),
                onTap: _pickDate,
              ),
              SegmentedButton<String>(
                segments: [
                  ButtonSegment(value: 'bank', label: Text(l.customersDepositBank)),
                  ButtonSegment(value: 'mfs', label: Text(l.customersDepositMfs)),
                  ButtonSegment(value: 'cash', label: Text(l.customersDepositCash)),
                ],
                selected: {_method},
                onSelectionChanged: (s) => setState(() => _method = s.first),
              ),
              const SizedBox(height: AppSpacing.sm),
              if (_method == 'bank')
                DropdownButtonFormField<int>(
                  initialValue: _bank,
                  isExpanded: true,
                  decoration: InputDecoration(labelText: l.customersDepositWhichBank),
                  items: [
                    for (final b in _banks) DropdownMenuItem(value: b.id, child: Text(b.name, overflow: TextOverflow.ellipsis)),
                  ],
                  onChanged: (v) => setState(() => _bank = v),
                ),
              if (_method != 'cash')
                TextField(
                  controller: _reference,
                  maxLength: 64,
                  decoration: InputDecoration(labelText: l.customersDepositReference),
                ),
              // ⭐ কে জমা দিলেন — ডিলার নিজে না হলে তাঁর লোক (ওয়েবের একই ঘর, সার্ভারের `depositor`)
              TextField(
                key: const ValueKey('deposit-depositor'),
                controller: _depositor,
                maxLength: 120,
                textCapitalization: TextCapitalization.words,
                decoration: InputDecoration(labelText: l.customersDepositDepositor, hintText: l.customersDepositDepositorHint),
              ),
              if (_bills.isNotEmpty) ...[
                const SizedBox(height: AppSpacing.sm),
                Text(l.customersDepositAgainstBills, style: const TextStyle(fontWeight: FontWeight.w700)),
                Text(l.customersDepositBillsHint, style: TextStyle(color: context.palette.onSurfaceMuted)),
                // ⓘ এক লাইনে এক বিল — মালিকের নিয়ম; টেবিল নয়
                for (final b in _bills)
                  Row(
                    children: [
                      Expanded(child: Text(l.customersDepositBillDue(b.no, Money.taka(b.due)))),
                      SizedBox(
                        width: 120,
                        child: TextField(
                          key: ValueKey('bill-share-${b.id}'),
                          controller: _shares[b.id],
                          keyboardType: const TextInputType.numberWithOptions(decimal: true),
                          textAlign: TextAlign.end,
                          decoration: InputDecoration(hintText: l.customersDepositOnThisBill),
                        ),
                      ),
                    ],
                  ),
              ],
              TextField(controller: _note, decoration: InputDecoration(labelText: l.customersDepositNote)),
            ]),
          ),
          const SizedBox(height: AppSpacing.md),
          if (_method != 'cash') ...[
            // ⭐ স্লিপের ছবি — ক্যামেরা আর গ্যালারি/WhatsApp (নমুনার জোড়া বোতাম)
            Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  key: const ValueKey('deposit-slip-camera'),
                  onPressed: _busy ? null : () => _pickSlip(camera: true),
                  icon: const Icon(Icons.photo_camera_outlined),
                  label: Text(l.customersDepositCamera),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                child: OutlinedButton.icon(
                  key: const ValueKey('deposit-slip-gallery'),
                  onPressed: _busy ? null : () => _pickSlip(camera: false),
                  icon: const Icon(Icons.photo_library_outlined),
                  label: Text(l.customersDepositGallery, textAlign: TextAlign.center),
                ),
              ),
            ]),
            if (_slip != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.xs),
                child: Row(key: const ValueKey('deposit-slip-taken'), children: [
                  Icon(Icons.check_circle, color: context.palette.success, size: 20),
                  const SizedBox(width: AppSpacing.xs),
                  Expanded(child: Text(l.customersDepositSlipTaken, style: TextStyle(color: context.palette.success))),
                ]),
              ),
          ],
          const SizedBox(height: AppSpacing.sm),
          FilledButton.icon(
            onPressed: _busy ? null : _send,
            icon: const Icon(Icons.send_outlined),
            label: Text(l.customersDepositSend),
          ),
          const Divider(height: AppSpacing.xl),
          Text(l.customersDepositHistory(_history.length), style: const TextStyle(fontWeight: FontWeight.w700)),
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
                    if (row.date != null) Text(l.customersDepositHistoryDate(row.date!)),
                    if (row.depositor != null) Text(l.customersDepositHistoryDepositor(row.depositor!)),
                    if (row.submittedBy != null) Text(l.customersDepositHistoryBy(row.submittedBy!)),
                    if (row.reference != null) Text(l.customersDepositHistoryRef(row.reference!)),
                    for (final (no, amount) in row.bills) Text(l.customersDepositHistoryBill(no, Money.taka(amount))),
                    if (row.status == 'rejected' || (row.reason ?? '').isNotEmpty)
                      Text(l.customersDepositHistoryReason((row.reason ?? '').isEmpty ? l.customersDepositReasonNotGiven : row.reason!),
                          style: row.status == 'rejected' ? TextStyle(color: context.palette.danger) : null),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _note2(String text, Color ink, Color bg, {Key? key}) => Card(
        key: key,
        color: bg,
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.sm),
          child: Text(text, style: TextStyle(color: ink)),
        ),
      );
}

/// দোকান বাছার তালিকা — নাম, পয়েন্ট, শাখা, মালিক বা ফোন দিয়ে খোঁজা ([CustomerRecord.matches])
class _ShopPicker extends StatefulWidget {
  const _ShopPicker({required this.items});

  final List<CustomerRecord> items;

  @override
  State<_ShopPicker> createState() => _ShopPickerState();
}

class _ShopPickerState extends State<_ShopPicker> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final shown = widget.items.where((c) => c.isActive && c.matches(_query)).toList();
    return DraggableScrollableSheet(
      initialChildSize: 0.75,
      expand: false,
      builder: (context, scroll) => Column(children: [
        Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: TextField(
            key: const ValueKey('deposit-shop-search'),
            autofocus: true,
            decoration: InputDecoration(hintText: context.l10n.customersDepositPickShop, prefixIcon: const Icon(Icons.search)),
            onChanged: (v) => setState(() => _query = v),
          ),
        ),
        Expanded(
          child: ListView.builder(
            controller: scroll,
            itemCount: shown.length,
            itemBuilder: (context, i) => ListTile(
              key: ValueKey('deposit-shop-${shown[i].id}'),
              minTileHeight: 64,
              title: Text(shown[i].label, maxLines: 1, overflow: TextOverflow.ellipsis),
              subtitle: shown[i].phone == null ? null : Text(shown[i].phone!),
              onTap: () => Navigator.of(context).pop(shown[i]),
            ),
          ),
        ),
      ]),
    );
  }
}
