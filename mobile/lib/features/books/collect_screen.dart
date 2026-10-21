import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/books/books_api.dart';
import '../../core/books/collection_entry.dart';
import '../../core/records/customer_record.dart';
import '../../core/records/money.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/customer_line.dart';
import 'money_in_screens.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/locale/l10n.dart';

/// ⭐ টাকা আদায় নিন — অফিসের লোকের ফোনে (মালিক, ৭ অক্টোবর ২০২৬: "এখন অ্যাপে পেমেন্ট অপশন চালু করো … এটা কেবল
/// অফিসের লোকদের জন্য")।
///
/// <p>গ্রাহক (দেখার শাখার, পয়েন্ট আর বকেয়াসহ), অঙ্ক, টাকার খাত (নগদ · ব্যাংক · মোবাইল ব্যাংকিং), লেনদেন-নম্বর, তারিখ,
/// মন্তব্য, আর "এখনই নিশ্চিত"। নিয়ম সব সার্ভারের — ওয়েবের আদায়-ফর্মের একই; এখানে কেবল যা না থাকলে পাঠানোই অর্থহীন।
/// ⛔ কেবল নেট থাকলে ("নেট না থাকলে শুধু অর্ডার") — সারিতে নয়; একই কাজ আবার পাঠালে একই চাবি, তাই একটাই বসে।
/// বসে গেলে নম্বরসহ রসিদ খোলে ([[MoneyInScreen]])।
class CollectScreen extends StatefulWidget {
  const CollectScreen({
    super.key,
    this.api = const ServerCollectionEntryApi(),
    this.books = const ServerBooksApi(),
    this.customers,
    this.today,
    this.newChangeId = _engineChangeId,
  });

  static String _engineChangeId() => SyncEngine.instance.newChangeId();

  final CollectionEntryApi api;

  /// পরীক্ষার পথ — একই কাজে একই চাবি কি না দেখতে
  final String Function() newChangeId;
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

  /// একই কাজের চাবি — নেট গেলে বা উত্তর হারালে আবার চাপলে একই; ফর্ম বদলালে বা সার্ভার ফেরালে নতুন
  String? _changeId;

  DateTime get _today {
    final now = widget.today ?? DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _date = _today;
    // ⓘ লেখার সঙ্গে পর্দা আবার আঁকে — "পিছনে"-র পাহারা লেখা দেখতে পায় ([[UnsavedGuard]])
    for (final c in [_amount, _instrumentNo, _narration]) {
      c.addListener(() => setState(() {}));
    }
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
            fallback: context.l10n.booksCollectAccountsFailed));
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
    if (_customer == null) return context.l10n.booksCollectPickCustomerError;
    final amount = _amount.text.trim();
    if (!_amountShape.hasMatch(amount) || double.parse(amount) <= 0) {
      return context.l10n.booksCollectAmountWrong;
    }
    if (_account == null) return context.l10n.booksCollectPickAccountError;
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
    final changeId = _changeId ??= widget.newChangeId();
    try {
      final outcome = await widget.api.send(CollectionDraft(
        customerId: _customer!.id,
        amount: _amount.text.trim(),
        date: _date,
        accountId: _account!.id,
        instrument: _instrument.text,
        instrumentNo: _instrumentNo.text,
        narration: _narration.text,
        confirm: _confirm,
      ), changeId);
      if (!mounted) return;
      final landed = outcome.landedId;
      if (landed != null) {
        // ⭐ বসে গেছে — নম্বরসহ রসিদ
        await Navigator.of(context).pushReplacement(MaterialPageRoute<void>(
          builder: (_) => MoneyInScreen(id: landed, api: widget.books),
        ));
        return;
      }
      // ⓘ সার্ভার ফেরাল — কারণসহ; ঠিক করে আবার পাঠালে নতুন চাবি (ফেরানো চাবি আবার পাঠালে আবার ফেরে)
      setState(() {
        _error = outcome.refusal ?? context.l10n.booksCollectNotLanded;
        _changeId = null;
      });
    } on NoNetworkForThis catch (e) {
      // ⓘ চাবি থাকে — নেট এলে আবার চাপলে একই কাজ, দুবার নয়
      if (mounted) {
        setState(() => _error = e.mayHaveLanded
            ? context.l10n.booksCollectMayHaveLanded
            : context.l10n.booksCollectNeedsNet);
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: context.l10n.booksCollectSendFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ গ্রাহক বা অঙ্ক বা মন্তব্য লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা আদায় এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: !_busy && (_customer != null || _amount.text.trim().isNotEmpty || _instrumentNo.text.trim().isNotEmpty || _narration.text.trim().isNotEmpty), child: _screen(context));

  Widget _screen(BuildContext context) {
    final due =
        _customer == null ? null : CustomerDueRecord.forCustomer(_customer!.id);
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.booksCollectTitle),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_error != null)
            Card(
              color: context.palette.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_error!,
                    key: const ValueKey('collect-error'),
                    style: TextStyle(color: context.palette.danger)),
              ),
            ),
          Card(
            child: ListTile(
              key: const ValueKey('collect-customer'),
              // ⭐ "নাম · পয়েন্ট · শাখা" এক লাইনে — মালিক, ১৯ অক্টোবর ২০২৬ (স্থায়ী নিয়ম)
              title: CustomerLine(_customer?.label ?? context.l10n.booksCollectPickCustomer),
              subtitle: _customer == null || due == null ? null : Text(due.outstandingLabel),
              trailing: const Icon(Icons.search),
              onTap: _busy ? null : _pickCustomer,
            ),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('collect-amount'),
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: context.l10n.booksCollectAmountLabel),
          ),
          const SizedBox(height: AppSpacing.sm),
          DropdownButtonFormField<CollectionAccount>(
            key: const ValueKey('collect-account'),
            initialValue: _account,
            // ⓘ লম্বা খাতের নাম ঘরের ভিতরেই থাকে (… দিয়ে), ওপারে যায় না (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)
            isExpanded: true,
            decoration: InputDecoration(labelText: context.l10n.booksCollectAccountLabel),
            items: [
              for (final a in _accounts)
                DropdownMenuItem(
                    value: a,
                    child: Text('${a.name} · ${a.kindLabel}',
                        maxLines: 1, overflow: TextOverflow.ellipsis)),
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
              decoration: InputDecoration(
                  labelText: context.l10n.booksCollectInstrumentLabel,
                  hintText: context.l10n.booksCollectInstrumentHint),
            ),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              key: const ValueKey('collect-instrument-no'),
              controller: _instrumentNo,
              decoration:
                  InputDecoration(labelText: context.l10n.booksCollectTrxLabel),
            ),
          ],
          const SizedBox(height: AppSpacing.sm),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text(context.l10n.booksCollectDate),
            subtitle: Text(
                '${_date.day.toString().padLeft(2, '0')}/${_date.month.toString().padLeft(2, '0')}/${_date.year}'),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _busy ? null : _pickDate,
          ),
          TextField(
            key: const ValueKey('collect-narration'),
            controller: _narration,
            maxLength: 500,
            decoration: InputDecoration(labelText: context.l10n.booksCollectRemarks),
          ),
          SwitchListTile(
            key: const ValueKey('collect-confirm'),
            contentPadding: EdgeInsets.zero,
            value: _confirm,
            onChanged: _busy ? null : (v) => setState(() => _confirm = v),
            title: Text(context.l10n.booksCollectConfirmNow),
            subtitle: Text(context.l10n.booksCollectConfirmHint),
          ),
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            key: const ValueKey('collect-send'),
            onPressed: _busy ? null : _send,
            icon: const Icon(Icons.payments_outlined),
            label: Text(_amount.text.trim().isEmpty
                ? context.l10n.booksCollectSend
                : context.l10n.booksCollectSendAmount(Money.taka(double.tryParse(_amount.text.trim()) ?? 0))),
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
            (c.pointName ?? '').toLowerCase().contains(q) ||
            (c.branchName ?? '').toLowerCase().contains(q))
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
                decoration: InputDecoration(
                    labelText: context.l10n.booksCollectSearchLabel,
                    prefixIcon: const Icon(Icons.search)),
                onChanged: (v) => setState(() => _q = v),
              ),
              Expanded(
                child: rows.isEmpty
                    ? Center(child: Text(context.l10n.booksCollectNoCustomer))
                    : ListView(
                        children: [
                          for (final c in rows)
                            ListTile(
                              key: ValueKey('collect-pick-${c.id}'),
                              title: CustomerLine(c.label),
                              subtitle: Text(CustomerDueRecord.forCustomer(c.id)
                                      ?.outstandingLabel ??
                                  ''),
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
