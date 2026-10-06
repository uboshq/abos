import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/books/books_api.dart';
import '../../core/books/collection_entry.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import 'money_in_screens.dart';

/// ⭐ টাকা আদায় নিন — অফিসের লোকের ফোনে (মালিক, ৭ অক্টোবর ২০২৬: "এখন অ্যাপে পেমেন্ট অপশন চালু করো … এটা কেবল
/// অফিসের লোকদের জন্য")।
///
/// <p>গ্রাহক (দেখার শাখার, পয়েন্ট আর বকেয়াসহ), অঙ্ক, টাকার খাত (নগদ · ব্যাংক · মোবাইল ব্যাংকিং), লেনদেন-নম্বর, তারিখ,
/// মন্তব্য, আর "এখনই নিশ্চিত"। নিয়ম সব সার্ভারের — ওয়েবের আদায়-ফর্মের একই; এখানে কেবল যা না থাকলে পাঠানোই অর্থহীন।
/// নেট না থাকলে ফোনে জমা থাকে, পরে যায়; বসে গেলে নম্বরসহ রসিদ খোলে ([[MoneyInScreen]])।
class CollectScreen extends StatefulWidget {
  const CollectScreen({
    super.key,
    this.api = const ServerCollectionEntryApi(),
    this.books = const ServerBooksApi(),
    this.customers,
    this.today,
  });

  final CollectionEntryApi api;
  final BooksApi books;

  /// পরীক্ষার জন্য — নইলে ফোনের ক্যাশের গ্রাহক
  final List<CustomerRecord>? customers;
  final DateTime? today;

  @override
  State<CollectScreen> createState() => _CollectScreenState();
}

class _CollectScreenState extends State<CollectScreen> {
  static final _amountShape = RegExp(r'^\d{1,14}(\.\d{1,2})?$');

  final _amount = TextEditingController();
  final _instrument = TextEditingController();
  final _instrumentNo = TextEditingController();
  final _narration = TextEditingController();

  List<CollectionAccount> _accounts = const [];
  CollectionAccount? _account;
  CustomerRecord? _customer;
  late DateTime _date;
  bool _confirm = true;
  bool _busy = false;
  String? _error;
  String? _notice;

  DateTime get _today {
    final now = widget.today ?? DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _date = _today;
    _loadAccounts();
  }

  @override
  void dispose() {
    _amount.dispose();
    _instrument.dispose();
    _instrumentNo.dispose();
    _narration.dispose();
    super.dispose();
  }

  Future<void> _loadAccounts() async {
    try {
      final list = await widget.api.accounts();
      if (!mounted) return;
      setState(() {
        _accounts = list;
        _account = list.isEmpty ? null : list.first;
        _instrument.text =
            _account?.kind == 'cash' ? '' : (_account?.kindLabel ?? '');
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'টাকার খাতের তালিকা আনা গেল না। নেট দেখে আবার খুলুন।'));
      }
    }
  }

  Future<void> _pickCustomer() async {
    final all = widget.customers ?? CustomerRecord.all();
    final picked = await showModalBottomSheet<CustomerRecord>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _CustomerSheet(customers: all),
    );
    if (picked != null && mounted) setState(() => _customer = picked);
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: _today.subtract(const Duration(days: 365)),
      lastDate: _today,
    );
    if (picked != null && mounted) setState(() => _date = picked);
  }

  String? _problem() {
    if (_customer == null) return 'গ্রাহক বাছুন।';
    final amount = _amount.text.trim();
    if (!_amountShape.hasMatch(amount) || double.parse(amount) <= 0) {
      return 'অঙ্কটা ঠিক নয় — শূন্যের বেশি, দুই দশমিক পর্যন্ত।';
    }
    if (_account == null) return 'টাকার খাত বাছুন।';
    return null;
  }

  Future<void> _send() async {
    final problem = _problem();
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final changeId = await widget.api.send(CollectionDraft(
        customerId: _customer!.id,
        amount: _amount.text.trim(),
        date: _date,
        accountId: _account!.id,
        instrument: _instrument.text,
        instrumentNo: _instrumentNo.text,
        narration: _narration.text,
        confirm: _confirm,
      ));
      if (!mounted) return;
      final landed = widget.api.landedId(changeId);
      if (landed != null) {
        // ⭐ বসে গেছে — নম্বরসহ রসিদ
        await Navigator.of(context).pushReplacement(MaterialPageRoute<void>(
          builder: (_) => MoneyInScreen(id: landed, api: widget.books),
        ));
        return;
      }
      setState(() {
        _notice =
            'আদায়টা ফোনে জমা হলো — নেট এলে নিজে থেকে পাঠানো হবে। সিঙ্কের অবস্থায় দেখা যাবে।';
        _customer = null;
        _amount.clear();
        _instrumentNo.clear();
        _narration.clear();
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'আদায় জমা করা গেল না। আবার চেষ্টা করুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final due =
        _customer == null ? null : CustomerDueRecord.forCustomer(_customer!.id);
    return Scaffold(
      appBar: AppBar(title: const Text('টাকা আদায় নিন')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_notice != null)
            Card(
              color: AppColors.successSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_notice!, key: const ValueKey('collect-notice')),
              ),
            ),
          if (_error != null)
            Card(
              color: AppColors.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_error!,
                    key: const ValueKey('collect-error'),
                    style: const TextStyle(color: AppColors.danger)),
              ),
            ),
          Card(
            child: ListTile(
              key: const ValueKey('collect-customer'),
              title: Text(_customer?.name ?? 'গ্রাহক বাছুন'),
              subtitle: _customer == null
                  ? null
                  : Text([
                      if (_customer!.pointName != null) _customer!.pointName!,
                      if (due != null) due.outstandingLabel,
                    ].join(' · ')),
              trailing: const Icon(Icons.search),
              onTap: _busy ? null : _pickCustomer,
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('collect-amount'),
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'অঙ্ক (টাকা)'),
          ),
          const SizedBox(height: AppSpacing.sm),
          DropdownButtonFormField<CollectionAccount>(
            key: const ValueKey('collect-account'),
            initialValue: _account,
            decoration: const InputDecoration(labelText: 'কোন খাতে'),
            items: [
              for (final a in _accounts)
                DropdownMenuItem(
                    value: a, child: Text('${a.name} · ${a.kindLabel}')),
            ],
            onChanged: _busy
                ? null
                : (a) => setState(() {
                      _account = a;
                      _instrument.text =
                          a?.kind == 'cash' ? '' : (a?.kindLabel ?? '');
                    }),
          ),
          if (_account != null && _account!.kind != 'cash') ...[
            const SizedBox(height: AppSpacing.sm),
            TextField(
              key: const ValueKey('collect-instrument'),
              controller: _instrument,
              decoration: const InputDecoration(
                  labelText: 'মাধ্যম',
                  hintText: 'যেমন bKash, Nagad, ব্যাংক জমা'),
            ),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              key: const ValueKey('collect-instrument-no'),
              controller: _instrumentNo,
              decoration:
                  const InputDecoration(labelText: 'লেনদেন-নম্বর (TrxID)'),
            ),
          ],
          const SizedBox(height: AppSpacing.sm),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('তারিখ'),
            subtitle: Text(
                '${_date.day.toString().padLeft(2, '0')}/${_date.month.toString().padLeft(2, '0')}/${_date.year}'),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _busy ? null : _pickDate,
          ),
          TextField(
            key: const ValueKey('collect-narration'),
            controller: _narration,
            maxLength: 500,
            decoration: const InputDecoration(labelText: 'মন্তব্য (ঐচ্ছিক)'),
          ),
          SwitchListTile(
            key: const ValueKey('collect-confirm'),
            contentPadding: EdgeInsets.zero,
            value: _confirm,
            onChanged: _busy ? null : (v) => setState(() => _confirm = v),
            title: const Text('এখনই নিশ্চিত'),
            subtitle: const Text(
                'অনুমোদন লাগলে আদায়টা অপেক্ষায় থাকবে, ওয়েবের মতো'),
          ),
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            key: const ValueKey('collect-send'),
            onPressed: _busy ? null : _send,
            icon: const Icon(Icons.payments_outlined),
            label: Text(_amount.text.trim().isEmpty
                ? 'আদায় জমা দিন'
                : 'আদায় জমা দিন — ${Money.taka(double.tryParse(_amount.text.trim()) ?? 0)}'),
          ),
        ],
      ),
    );
  }
}

class _CustomerSheet extends StatefulWidget {
  const _CustomerSheet({required this.customers});

  final List<CustomerRecord> customers;

  @override
  State<_CustomerSheet> createState() => _CustomerSheetState();
}

class _CustomerSheetState extends State<_CustomerSheet> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final q = _q.trim().toLowerCase();
    final rows = widget.customers
        .where((c) => c.isActive)
        .where((c) =>
            q.isEmpty ||
            c.name.toLowerCase().contains(q) ||
            (c.code ?? '').toLowerCase().contains(q) ||
            (c.phone ?? '').contains(q) ||
            (c.pointName ?? '').toLowerCase().contains(q))
        .take(100)
        .toList();
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
            left: AppSpacing.md,
            right: AppSpacing.md,
            top: AppSpacing.md,
            bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.md),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(
            children: [
              TextField(
                key: const ValueKey('collect-customer-search'),
                autofocus: true,
                decoration: const InputDecoration(
                    labelText: 'নাম, কোড, ফোন বা পয়েন্ট',
                    prefixIcon: Icon(Icons.search)),
                onChanged: (v) => setState(() => _q = v),
              ),
              Expanded(
                child: rows.isEmpty
                    ? const Center(child: Text('কোনো গ্রাহক মেলেনি।'))
                    : ListView(
                        children: [
                          for (final c in rows)
                            ListTile(
                              key: ValueKey('collect-pick-${c.id}'),
                              title: Text(c.name),
                              subtitle: Text([
                                if (c.pointName != null) c.pointName!,
                                CustomerDueRecord.forCustomer(c.id)
                                        ?.outstandingLabel ??
                                    '',
                              ].where((t) => t.isNotEmpty).join(' · ')),
                              onTap: () => Navigator.of(context).pop(c),
                            ),
                        ],
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
