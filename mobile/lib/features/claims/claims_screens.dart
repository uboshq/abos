import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/hr/claims_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../books/books_widgets.dart';

/// ⭐ খরচের দাবি আর অগ্রিম — কর্মীর নিজের (টাকা-আসা-যাওয়ার পরিকল্পনা ১৩, ৭ অক্টোবর ২০২৬; সার্ভার f727b846)।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস, কোনো টেবিল নয়। পাঠানো মানে টাকা পাওয়া নয় — সই আর ক্যাশিয়ারের পরে।
Color stateColour(String status) => switch (status) {
      'paid' => AppColors.success,
      'rejected' => AppColors.danger,
      _ => AppColors.pending,
    };

class ClaimListScreen extends StatefulWidget {
  const ClaimListScreen({super.key, this.api = const ServerClaimsApi(), this.picker});

  final ClaimsApi api;

  /// পরীক্ষায় ক্যামেরা ছাড়া একটা পথ দিতে
  final Future<String?> Function()? picker;

  @override
  State<ClaimListScreen> createState() => _ClaimListScreenState();
}

class _ClaimListScreenState extends State<ClaimListScreen> {
  ClaimsPage? _page;
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
      final page = await widget.api.mine();
      if (mounted) setState(() => _page = page);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'দাবির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে খরচের দাবি এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    return Scaffold(
      appBar: AppBar(title: const Text('খরচের দাবি ও অগ্রিম')),
      floatingActionButton: FloatingActionButton.extended(
        key: const ValueKey('claim-new'),
        onPressed: () async {
          final sent = await Navigator.of(context).push<Claim>(MaterialPageRoute(
              builder: (_) => NewClaimScreen(api: widget.api, picker: widget.picker)));
          if (sent != null && mounted) await _load();
        },
        icon: const Icon(Icons.add),
        label: const Text('নতুন দাবি'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (page?.openAdvance != null)
              TotalStrip(
                  key: const ValueKey('claim-open-advance'),
                  label: 'হাতে খোলা অগ্রিম',
                  value: Money.taka(page!.openAdvance!)),
            if (page != null && page.claims.isEmpty)
              const Padding(
                padding: EdgeInsets.all(AppSpacing.lg),
                child: Text('এখনো কোনো দাবি নেই।', textAlign: TextAlign.center),
              ),
            for (final c in page?.claims ?? const <Claim>[])
              Card(
                child: ListTile(
                  key: ValueKey('claim-${c.id}'),
                  title: Text('${c.number} · ${c.kindLabel}'),
                  subtitle: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(Money.taka(c.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(c.stateLabel, style: TextStyle(color: stateColour(c.status))),
                      if (c.reason.isNotEmpty) Text(c.reason, maxLines: 1, overflow: TextOverflow.ellipsis),
                    ],
                  ),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(
                      builder: (_) => ClaimScreen(id: c.id, api: widget.api))),
                ),
              ),
            const SizedBox(height: 80),
          ],
        ),
      ),
    );
  }
}

class ClaimScreen extends StatefulWidget {
  const ClaimScreen({super.key, required this.id, this.api = const ServerClaimsApi()});

  final String id;
  final ClaimsApi api;

  @override
  State<ClaimScreen> createState() => _ClaimScreenState();
}

class _ClaimScreenState extends State<ClaimScreen> {
  Claim? _claim;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.api.one(widget.id).then((c) {
      if (mounted) setState(() => _claim = c);
    }, onError: (Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'দাবিটা আনা গেল না।'));
    });
  }

  @override
  Widget build(BuildContext context) {
    final c = _claim;
    return Scaffold(
      appBar: AppBar(title: Text(c?.number ?? 'দাবি')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_error != null) BooksError(_error!),
          if (c == null && _error == null) const LinearProgressIndicator(),
          if (c != null) ...[
            FactRow('ধরন', c.kindLabel),
            FactRow('অবস্থা', c.stateLabel, colour: stateColour(c.status)),
            FactRow('টাকা', Money.taka(c.amount)),
            if (c.head != null) FactRow('খরচের খাত', c.head!),
            if (c.spentOn != null) FactRow('খরচের তারিখ', dayOf(c.spentOn)),
            if (!c.isAdvance && c.fromAdvance > 0) FactRow('অগ্রিম থেকে মিটেছে', Money.taka(c.fromAdvance)),
            if (!c.isAdvance && (c.status == 'approved' || c.status == 'paid'))
              FactRow('নগদে পাবেন', Money.taka(c.cash)),
            FactRow('কারণ', c.reason),
            if (c.submittedAt != null) FactRow('পাঠানো', dayOf(c.submittedAt)),
            if (c.decidedAt != null) FactRow(c.status == 'rejected' ? 'প্রত্যাখ্যান' : 'অনুমোদন', dayOf(c.decidedAt)),
            if (c.paidAt != null) FactRow('টাকা দেওয়া', dayOf(c.paidAt)),
          ],
        ],
      ),
    );
  }
}

class NewClaimScreen extends StatefulWidget {
  const NewClaimScreen({super.key, this.api = const ServerClaimsApi(), this.picker, this.today});

  final ClaimsApi api;
  final Future<String?> Function()? picker;
  final DateTime? today;

  @override
  State<NewClaimScreen> createState() => _NewClaimScreenState();
}

class _NewClaimScreenState extends State<NewClaimScreen> {
  static final _amountShape = RegExp(r'^\d{1,14}(\.\d{1,2})?$');

  final _amount = TextEditingController();
  final _reason = TextEditingController();

  String _kind = 'expense';
  int? _head;
  late DateTime _spentOn;
  String? _receipt;

  List<ClaimHead> _heads = const [];
  bool _busy = false;
  String? _error;

  DateTime get _today {
    final now = widget.today ?? DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _spentOn = _today;
    widget.api.heads().then((h) {
      if (mounted) setState(() => _heads = h);
    }, onError: (Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'খরচের খাতের তালিকা আনা গেল না।'));
    });
  }

  @override
  void dispose() {
    _amount.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _pickReceipt() async {
    final path = widget.picker != null
        ? await widget.picker!()
        : (await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 70, maxWidth: 1800))?.path;
    if (path != null && mounted) setState(() => _receipt = path);
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _spentOn,
      firstDate: _today.subtract(const Duration(days: 365)),
      lastDate: _today,
    );
    if (picked != null) setState(() => _spentOn = picked);
  }

  Future<void> _send() async {
    final amount = _amount.text.trim();
    final reason = _reason.text.trim();
    final problem = !_amountShape.hasMatch(amount) || (double.tryParse(amount) ?? 0) <= 0
        ? 'টাকার অঙ্ক ঠিকভাবে লিখুন — যেমন ৫০০ বা ৭৫০.৫০।'
        : _kind == 'expense' && _head == null
            ? 'কোন খাতের খরচ, বাছুন।'
            : reason.isEmpty
                ? 'কারণ লিখুন।'
                : null;
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final claim = await widget.api.send(ClaimDraft(
        kind: _kind,
        amount: amount,
        reason: reason,
        headId: _head,
        spentOn: _spentOn,
        receiptPath: _receipt,
      ));
      if (mounted) Navigator.of(context).pop(claim);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'পাঠানো যায়নি। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final expense = _kind == 'expense';
    return Scaffold(
      appBar: AppBar(title: const Text('নতুন দাবি')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          const Text('সই আর ক্যাশিয়ারের পরে টাকা পাবেন। খরচ আগে মেটে হাতের অগ্রিম থেকে।',
              style: TextStyle(color: AppColors.onSurfaceMuted)),
          const SizedBox(height: AppSpacing.sm),
          if (_busy) const LinearProgressIndicator(),
          if (_error != null)
            Card(
              key: const ValueKey('claim-error'),
              color: AppColors.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.sm),
                child: Text(_error!, style: const TextStyle(color: AppColors.danger)),
              ),
            ),
          SegmentedButton<String>(
            key: const ValueKey('claim-kind'),
            segments: const [
              ButtonSegment(value: 'expense', label: Text('খরচ হয়ে গেছে')),
              ButtonSegment(value: 'advance', label: Text('অগ্রিম চাই')),
            ],
            selected: {_kind},
            onSelectionChanged: (s) => setState(() => _kind = s.first),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('claim-amount'),
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'টাকা'),
          ),
          if (expense) ...[
            DropdownButtonFormField<int>(
              key: const ValueKey('claim-head'),
              initialValue: _head,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'খরচের খাত'),
              items: [
                for (final h in _heads)
                  DropdownMenuItem(value: h.id, child: Text(h.name, overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _head = v),
            ),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: const Text('খরচের তারিখ'),
              subtitle: Text('${_spentOn.day}/${_spentOn.month}/${_spentOn.year}'),
              trailing: const Icon(Icons.calendar_today_outlined),
              onTap: _pickDate,
            ),
          ],
          TextField(
            key: const ValueKey('claim-reason'),
            controller: _reason,
            maxLength: 500,
            maxLines: 3,
            minLines: 1,
            decoration: const InputDecoration(labelText: 'কারণ'),
          ),
          if (expense)
            OutlinedButton.icon(
              key: const ValueKey('claim-receipt'),
              onPressed: _busy ? null : _pickReceipt,
              icon: Icon(_receipt == null ? Icons.photo_camera_outlined : Icons.check_circle,
                  color: _receipt == null ? null : AppColors.success),
              label: Text(_receipt == null ? 'রসিদের ছবি তুলুন' : 'ছবি নেওয়া হয়েছে — আবার তুলুন'),
            ),
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            key: const ValueKey('claim-send'),
            onPressed: _busy ? null : _send,
            icon: const Icon(Icons.send_outlined),
            label: const Text('পাঠান'),
          ),
        ],
      ),
    );
  }
}
