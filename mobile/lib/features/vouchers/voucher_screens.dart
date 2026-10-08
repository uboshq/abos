import 'package:flutter/material.dart';

import '../../core/accounts/voucher_api.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/records/money.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../books/books_widgets.dart';
import '../printing/document_actions_sheet.dart';

/// ⭐ ফোনে অফিসের লোকের ভাউচার — আদায়, পরিশোধ, খরচ (বাকিতেও), জাবেদা, কন্ট্রা (মালিক, ৭ অক্টোবর ২০২৬; সার্ভার 2bd0f620)।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস। নিয়ম সব সার্ভারের — এখানে কেবল যা না থাকলে পাঠানোই অর্থহীন। ⛔ কেবল নেট থাকলে।
Color voucherStateColour(String state) => switch (state) {
      'posted' => AppColors.success,
      'cancelled' => AppColors.danger,
      _ => AppColors.pending,
    };

class VoucherListScreen extends StatefulWidget {
  const VoucherListScreen({super.key, this.api = const ServerVoucherApi(), this.newChangeId = _engineChangeId});

  static String _engineChangeId() => SyncEngine.instance.newChangeId();

  final VoucherApi api;
  final String Function() newChangeId;

  @override
  State<VoucherListScreen> createState() => _VoucherListScreenState();
}

class _VoucherListScreenState extends State<VoucherListScreen> {
  String? _type;
  bool _awaiting = false;
  final List<VoucherRow> _rows = [];
  VoucherListPage? _page;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api.list(type: _type, awaiting: _awaiting, page: more ? (_page?.nextPage ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(page.rows);
        _page = page;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'ভাউচারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ফোনের ভাউচার এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _new() async {
    final type = await showModalBottomSheet<String>(
      context: context,
      builder: (_) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          for (final t in VoucherTypes.all)
            ListTile(
              key: ValueKey('voucher-type-$t'),
              title: Text(VoucherTypes.label(t)),
              onTap: () => Navigator.of(context).pop(t),
            ),
        ]),
      ),
    );
    if (type == null || !mounted) return;
    final landed = await Navigator.of(context).push<String>(MaterialPageRoute(
        builder: (_) => VoucherFormScreen(type: type, api: widget.api, newChangeId: widget.newChangeId)));
    if (!mounted) return;
    await _load();
    if (landed != null && mounted) {
      await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => VoucherScreen(id: landed, api: widget.api)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('ভাউচার')),
      floatingActionButton: FloatingActionButton.extended(
        key: const ValueKey('voucher-new'),
        onPressed: _new,
        icon: const Icon(Icons.add),
        label: const Text('নতুন ভাউচার'),
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
              ChoiceChip(
                  label: const Text('সব'),
                  selected: _type == null,
                  onSelected: (_) {
                    setState(() => _type = null);
                    _load();
                  }),
              for (final t in VoucherTypes.all)
                ChoiceChip(
                    key: ValueKey('voucher-filter-$t'),
                    label: Text(VoucherTypes.label(t).replaceAll(' ভাউচার', '')),
                    selected: _type == t,
                    onSelected: (_) {
                      setState(() => _type = t);
                      _load();
                    }),
              FilterChip(
                  key: const ValueKey('voucher-awaiting'),
                  label: const Text('সইয়ের অপেক্ষায়'),
                  selected: _awaiting,
                  onSelected: (v) {
                    setState(() => _awaiting = v);
                    _load();
                  }),
            ]),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (_page != null && _rows.isEmpty && !_busy)
              const Padding(padding: EdgeInsets.all(AppSpacing.lg), child: Center(child: Text('কোনো ভাউচার নেই।'))),
            for (final v in _rows)
              Card(
                child: ListTile(
                  key: ValueKey('voucher-${v.id}'),
                  title: Text('${v.no} · ${v.kindLabel}'),
                  subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(Money.taka(v.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text(v.stateWords, style: TextStyle(color: voucherStateColour(v.state))),
                    if (v.party != null) Text('${v.partyWords}: ${v.party!}'),
                    if (v.date != null) Text(dayOf(v.date)),
                  ]),
                  onTap: () async {
                    await Navigator.of(context)
                        .push(MaterialPageRoute<void>(builder: (_) => VoucherScreen(id: v.id, api: widget.api)));
                    if (mounted) await _load();
                  },
                ),
              ),
            if (_page?.nextPage != null) MoreButton(busy: _busy, onPressed: () => _load(more: true)),
            const SizedBox(height: 80),
          ],
        ),
      ),
    );
  }
}

class VoucherScreen extends StatefulWidget {
  const VoucherScreen({super.key, required this.id, this.api = const ServerVoucherApi()});

  final String id;
  final VoucherApi api;

  @override
  State<VoucherScreen> createState() => _VoucherScreenState();
}

class _VoucherScreenState extends State<VoucherScreen> {
  VoucherPage? _page;
  String? _error;
  String? _notice;
  bool _busy = false;
  final _trx = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _trx.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final page = await widget.api.one(widget.id);
      if (mounted) setState(() => _page = page);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'ভাউচারটা আনা গেল না।'));
    }
  }

  Future<void> _post() async {
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      final answer = await widget.api.post(widget.id, instrumentNo: _trx.text);
      if (!mounted) return;
      setState(() {
        if (answer.posted) {
          _notice = answer.message;
        } else {
          _error = answer.message;
        }
        if (answer.page != null) _page = answer.page;
      });
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'পাকা করা গেল না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  bool get _needsTrx {
    final i = _page?.instrument;
    return (i == 'mfs' || i == 'transfer') && (_page?.instrumentNo ?? '').isEmpty;
  }

  @override
  Widget build(BuildContext context) {
    final p = _page;
    return Scaffold(
      appBar: AppBar(title: Text(p?.row.no ?? 'ভাউচার'), actions: [
        if (p != null)
          IconButton(
            key: const ValueKey('voucher-print'),
            tooltip: 'ছাপা বা শেয়ার',
            icon: const Icon(Icons.print_outlined),
            onPressed: () => DocumentActionsSheet.show(context, type: 'Voucher', id: p.row.id, title: '${p.row.kindLabel} ${p.row.no}'),
          ),
      ]),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy) const LinearProgressIndicator(),
          if (_error != null) BooksError(_error!),
          if (_notice != null)
            Card(
                color: AppColors.successSurface,
                child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.sm),
                    child: Text(_notice!, key: const ValueKey('voucher-notice'), style: const TextStyle(color: AppColors.success)))),
          if (p == null && _error == null) const LinearProgressIndicator(),
          if (p != null) ...[
            FactRow('ধরন', p.row.kindLabel),
            FactRow('অবস্থা', p.row.stateWords, colour: voucherStateColour(p.row.state)),
            FactRow('টাকা', Money.taka(p.row.amount)),
            if (p.row.date != null) FactRow('তারিখ', dayOf(p.row.date)),
            if (p.row.party != null) FactRow(p.row.partyWords, p.row.party!),
            if (p.moneyAccount != null) FactRow(p.moneyLabel ?? 'টাকার খাত', p.moneyAccount!),
            if ((p.instrumentNo ?? '').isNotEmpty) FactRow('লেনদেন নম্বর', p.instrumentNo!),
            if (p.row.narration.isNotEmpty) FactRow('বিবরণ', p.row.narration),
            if (p.writtenBy.isNotEmpty) FactRow('লেখক', p.writtenBy),
            const Divider(),
            for (final l in p.lines)
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(l.account),
                // ⓘ সারির পক্ষ আর বিবরণ এক লাইনে
                subtitle: l.narration.isEmpty && l.party == null
                    ? null
                    : Text([if (l.party != null) l.party!, if (l.narration.isNotEmpty) l.narration].join(' · ')),
                trailing: Text(l.debit > 0 ? 'ডেবিট ${Money.taka(l.debit)}' : 'ক্রেডিট ${Money.taka(l.credit)}'),
              ),
            if (p.awaitsAnotherHand)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: Text('নিজের লেখা ভাউচার নিজে পাকা হয় না — অন্য কেউ খুলে "পাকা করুন" চাপবেন।',
                    key: ValueKey('voucher-another-hand'), style: TextStyle(color: AppColors.pending)),
              ),
            if (p.canPost) ...[
              if (_needsTrx)
                TextField(
                  key: const ValueKey('voucher-trx'),
                  controller: _trx,
                  decoration: const InputDecoration(labelText: 'লেনদেন নম্বর (TrxID)'),
                ),
              const SizedBox(height: AppSpacing.sm),
              FilledButton.icon(
                key: const ValueKey('voucher-post'),
                onPressed: _busy ? null : _post,
                icon: const Icon(Icons.check_circle_outline),
                label: const Text('পাকা করুন'),
              ),
            ],
          ],
        ],
      ),
    );
  }
}

class VoucherFormScreen extends StatefulWidget {
  const VoucherFormScreen({
    super.key,
    required this.type,
    this.api = const ServerVoucherApi(),
    this.newChangeId = VoucherListScreen._engineChangeId,
  });

  final String type;
  final VoucherApi api;
  final String Function() newChangeId;

  @override
  State<VoucherFormScreen> createState() => _VoucherFormScreenState();
}

class _JournalLine {
  VoucherAccount? account;
  final debit = TextEditingController();
  final credit = TextEditingController();
  VoucherParty? party;
}

class _VoucherFormScreenState extends State<VoucherFormScreen> {
  static final _amountShape = RegExp(r'^\d{1,14}(\.\d{1,2})?$');

  VoucherSetup? _setup;
  VoucherAccount? _from;
  VoucherAccount? _to;
  VoucherParty? _party;
  late DateTime _date;
  final _amount = TextEditingController();
  final _narration = TextEditingController();
  final _trx = TextEditingController();
  final List<_JournalLine> _lines = [_JournalLine(), _JournalLine()];
  bool _keepAsDraft = false;
  bool _busy = false;
  String? _error;
  String? _changeId;

  bool get _journal => widget.type == VoucherTypes.journal;

  @override
  void initState() {
    super.initState();
    _date = DateTime.now();
    widget.api.setup(widget.type).then((s) {
      if (mounted) {
        setState(() {
          _setup = s;
          _date = s.today;
        });
      }
    }, onError: (Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'খাতের তালিকা আনা গেল না। নেট দেখে আবার খুলুন।'));
    });
  }

  @override
  void dispose() {
    _amount.dispose();
    _narration.dispose();
    _trx.dispose();
    for (final l in _lines) {
      l.debit.dispose();
      l.credit.dispose();
    }
    super.dispose();
  }

  /// টাকার দিক — আদায়ে "কোথায়", বাকিগুলোয় "কোথা থেকে"
  VoucherAccount? get _moneySide => widget.type == VoucherTypes.receipt ? _to : _from;

  bool get _needsTrx {
    final i = _moneySide?.instrument;
    return widget.type != VoucherTypes.contra && (i == 'mfs' || i == 'transfer');
  }

  double _sum(Iterable<TextEditingController> cs) =>
      cs.fold(0, (s, c) => s + (double.tryParse(c.text.trim()) ?? 0));

  String? _problem() {
    if (_setup == null) return 'খাতের তালিকা এখনো আসেনি।';
    if (_setup!.narrationRequired && _narration.text.trim().isEmpty) return 'বিবরণ লিখুন — এই কোম্পানিতে বিবরণ বাধ্যতামূলক।';
    if (_journal) {
      final filled = _lines.where((l) => l.account != null).toList();
      if (filled.length < 2) return 'অন্তত দুটো সারিতে খাত বাছুন।';
      for (final l in filled) {
        final d = l.debit.text.trim(), c = l.credit.text.trim();
        if ((d.isNotEmpty && !_amountShape.hasMatch(d)) || (c.isNotEmpty && !_amountShape.hasMatch(c))) {
          return 'অঙ্কটা ঠিক নয় — দুই দশমিক পর্যন্ত।';
        }
        if ((double.tryParse(d) ?? 0) > 0 && (double.tryParse(c) ?? 0) > 0) return 'এক সারিতে ডেবিট আর ক্রেডিট দুটোই নয়।';
      }
      final dr = _sum(filled.map((l) => l.debit)), cr = _sum(filled.map((l) => l.credit));
      if (dr <= 0 || (dr - cr).abs() > 0.004) return 'ডেবিট আর ক্রেডিট মেলেনি।';
      return null;
    }
    if (_from == null || _to == null) return 'দুই দিকের খাত বাছুন।';
    if (_from!.id == _to!.id) return 'দুই দিকে একই খাত হয় না।';
    final a = _amount.text.trim();
    if (!_amountShape.hasMatch(a) || (double.tryParse(a) ?? 0) <= 0) return 'অঙ্কটা ঠিক নয় — শূন্যের বেশি, দুই দশমিক পর্যন্ত।';
    return null;
  }

  VoucherDraft _draft() => VoucherDraft(
        type: widget.type,
        date: _date,
        narration: _narration.text,
        fromAccountId: _from?.id,
        toAccountId: _to?.id,
        amount: _amount.text.trim(),
        party: _party?.key,
        instrument: widget.type == VoucherTypes.contra ? null : _moneySide?.instrument,
        instrumentNo: _needsTrx ? _trx.text : null,
        keepAsDraft: _keepAsDraft,
        lines: [
          for (final l in _lines.where((l) => l.account != null))
            {
              'account_id': l.account!.id,
              'debit': l.debit.text.trim().isEmpty ? '0' : l.debit.text.trim(),
              'credit': l.credit.text.trim().isEmpty ? '0' : l.credit.text.trim(),
              if (l.party != null) 'party': l.party!.key,
            },
        ],
      );

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
      final outcome = await widget.api.send(_draft(), changeId);
      if (!mounted) return;
      if (outcome.landedId != null) {
        Navigator.of(context).pop(outcome.landedId);
        return;
      }
      setState(() {
        _error = outcome.refusal ?? 'ভাউচারটা বসেনি। আবার চেষ্টা করুন।';
        _changeId = null;
      });
    } on NoNetworkForThis {
      if (mounted) setState(() => _error = 'ভাউচার লিখতে নেট লাগবে। নেট চালু করে আবার চাপুন — ফোনে কিছু জমা থাকেনি।');
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'পাঠানো গেল না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<T?> _pick<T>(String title, List<T> options, String Function(T) label, {String? key}) =>
      showModalBottomSheet<T>(
        context: context,
        isScrollControlled: true,
        builder: (_) => _PickSheet<T>(title: title, options: options, label: label, keyPrefix: key),
      );

  Future<void> _pickDate() async {
    final today = _setup?.today ?? DateTime.now();
    final picked = await showDatePicker(
        context: context, initialDate: _date, firstDate: today.subtract(const Duration(days: 365)), lastDate: today);
    if (picked != null && mounted) setState(() => _date = picked);
  }

  Widget _accountTile(String key, String label, VoucherAccount? value, List<VoucherAccount> options, ValueChanged<VoucherAccount> set) =>
      Card(
        child: ListTile(
          key: ValueKey(key),
          title: Text(value?.label ?? label),
          subtitle: value == null ? null : Text(label),
          trailing: const Icon(Icons.search),
          onTap: _busy
              ? null
              : () async {
                  final a = await _pick<VoucherAccount>(label, options, (x) => x.label, key: key);
                  if (a != null) setState(() => set(a));
                },
        ),
      );

  Widget _partyTile(String key, VoucherParty? value, ValueChanged<VoucherParty?> set) => Card(
        child: ListTile(
          key: ValueKey(key),
          title: Text(value?.label ?? 'পক্ষ (ঐচ্ছিক — বাকিতে খরচে লাগবে)'),
          trailing: value == null
              ? const Icon(Icons.search)
              : IconButton(icon: const Icon(Icons.close), onPressed: () => setState(() => set(null))),
          onTap: _busy
              ? null
              : () async {
                  final p = await _pick<VoucherParty>('পক্ষ', _setup?.parties ?? const [], (x) => x.label, key: key);
                  if (p != null) setState(() => set(p));
                },
        ),
      );

  @override
  Widget build(BuildContext context) {
    final s = _setup;
    return Scaffold(
      appBar: AppBar(title: Text(VoucherTypes.label(widget.type))),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_busy || (s == null && _error == null)) const LinearProgressIndicator(),
          if (_error != null)
            Card(
                key: const ValueKey('voucher-error'),
                color: AppColors.dangerSurface,
                child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.sm),
                    child: Text(_error!, style: const TextStyle(color: AppColors.danger)))),
          if (s?.cashHiddenReason != null)
            Text(s!.cashHiddenReason!, style: const TextStyle(color: AppColors.warning, fontSize: 12.5)),
          ListTile(
            contentPadding: EdgeInsets.zero,
            title: const Text('তারিখ'),
            subtitle: Text('${_date.day.toString().padLeft(2, '0')}/${_date.month.toString().padLeft(2, '0')}/${_date.year}'),
            trailing: const Icon(Icons.calendar_today_outlined),
            onTap: _busy ? null : _pickDate,
          ),
          if (s != null && !_journal) ...[
            _accountTile('voucher-from', s.from?.label ?? 'কোথা থেকে', _from, s.from?.accounts ?? const [], (a) => _from = a),
            _accountTile('voucher-to', s.to?.label ?? 'কোথায়', _to, s.to?.accounts ?? const [], (a) => _to = a),
            TextField(
              key: const ValueKey('voucher-amount'),
              controller: _amount,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              decoration: const InputDecoration(labelText: 'টাকা'),
            ),
            _partyTile('voucher-party', _party, (p) => _party = p),
            if (_needsTrx)
              TextField(
                key: const ValueKey('voucher-form-trx'),
                controller: _trx,
                decoration: const InputDecoration(labelText: 'লেনদেন নম্বর (TrxID) — পাকা করতে লাগবে'),
              ),
          ],
          if (s != null && _journal) ...[
            for (var i = 0; i < _lines.length; i++)
              Card(
                key: ValueKey('voucher-line-$i'),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Column(children: [
                    _accountTile('voucher-line-$i-account', 'খাত', _lines[i].account, s.accounts, (a) => _lines[i].account = a),
                    Row(children: [
                      Expanded(
                          child: TextField(
                              key: ValueKey('voucher-line-$i-debit'),
                              controller: _lines[i].debit,
                              keyboardType: const TextInputType.numberWithOptions(decimal: true),
                              decoration: const InputDecoration(labelText: 'ডেবিট'),
                              onChanged: (_) => setState(() {}))),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(
                          child: TextField(
                              key: ValueKey('voucher-line-$i-credit'),
                              controller: _lines[i].credit,
                              keyboardType: const TextInputType.numberWithOptions(decimal: true),
                              decoration: const InputDecoration(labelText: 'ক্রেডিট'),
                              onChanged: (_) => setState(() {}))),
                    ]),
                    _partyTile('voucher-line-$i-party', _lines[i].party, (p) => _lines[i].party = p),
                  ]),
                ),
              ),
            Row(children: [
              TextButton.icon(
                  key: const ValueKey('voucher-add-line'),
                  onPressed: () => setState(() => _lines.add(_JournalLine())),
                  icon: const Icon(Icons.add),
                  label: const Text('সারি যোগ')),
              const Spacer(),
              Text('ডেবিট ${Money.taka(_sum(_lines.map((l) => l.debit)))} · ক্রেডিট ${Money.taka(_sum(_lines.map((l) => l.credit)))}',
                  key: const ValueKey('voucher-totals')),
            ]),
          ],
          TextField(
            key: const ValueKey('voucher-narration'),
            controller: _narration,
            maxLength: 500,
            decoration: InputDecoration(labelText: s?.narrationRequired == true ? 'বিবরণ (বাধ্যতামূলক)' : 'বিবরণ'),
          ),
          SwitchListTile(
            key: const ValueKey('voucher-keep-draft'),
            contentPadding: EdgeInsets.zero,
            value: _keepAsDraft,
            onChanged: _busy ? null : (v) => setState(() => _keepAsDraft = v),
            title: const Text('খসড়া রাখুন'),
            subtitle: const Text('বন্ধ থাকলে সংরক্ষণেই পাকা — সই লাগলে বা নিজের লেখা হলে খসড়াই থাকবে'),
          ),
          const SizedBox(height: AppSpacing.sm),
          FilledButton.icon(
            key: const ValueKey('voucher-send'),
            onPressed: _busy || s == null ? null : _send,
            icon: const Icon(Icons.save_outlined),
            label: const Text('সংরক্ষণ'),
          ),
        ],
      ),
    );
  }
}

class _PickSheet<T> extends StatefulWidget {
  const _PickSheet({required this.title, required this.options, required this.label, this.keyPrefix});

  final String title;
  final List<T> options;
  final String Function(T) label;
  final String? keyPrefix;

  @override
  State<_PickSheet<T>> createState() => _PickSheetState<T>();
}

class _PickSheetState<T> extends State<_PickSheet<T>> {
  String _q = '';

  @override
  Widget build(BuildContext context) {
    final q = _q.trim().toLowerCase();
    final rows = widget.options.where((o) => q.isEmpty || widget.label(o).toLowerCase().contains(q)).take(100).toList();
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
            left: AppSpacing.md, right: AppSpacing.md, top: AppSpacing.md, bottom: MediaQuery.of(context).viewInsets.bottom + AppSpacing.md),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(children: [
            TextField(
              autofocus: true,
              decoration: InputDecoration(labelText: widget.title, prefixIcon: const Icon(Icons.search)),
              onChanged: (v) => setState(() => _q = v),
            ),
            Expanded(
              child: rows.isEmpty
                  ? const Center(child: Text('কিছু মেলেনি।'))
                  : ListView(children: [
                      for (var i = 0; i < rows.length; i++)
                        ListTile(
                          key: ValueKey('${widget.keyPrefix ?? 'pick'}-option-$i'),
                          title: Text(widget.label(rows[i])),
                          onTap: () => Navigator.of(context).pop(rows[i]),
                        ),
                    ]),
            ),
          ]),
        ),
      ),
    );
  }
}
