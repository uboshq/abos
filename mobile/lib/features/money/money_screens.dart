import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/paged_list.dart';
import '../books/books_widgets.dart';

/// ⭐ টাকার হাতবদল, নগদ গোনা, হাতধারের দেওয়া-নেওয়া আর অর্থের চার পাতা — ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬; fe)।
///
/// <p>⛔ নিয়ম সব সার্ভারের ([[MoneyPhoneController]], [[HandLoanPhoneController]], [[FinanceFiguresApiController]]) — ওয়েবের একই
/// সেবা: হস্তান্তর পথের টাকার খাত দিয়ে যায়, নেন কেবল প্রাপক; গোনা খাতা নাড়ায় না, মানেন অন্যজন; সই লাগলে "সইয়ের অপেক্ষায়"
/// (২০২)। সংখ্যা ফোনে কষা নয়। ⭐ প্রতিটা লেখা একবারই — [OnceKey]।

String _today() {
  final now = DateTime.now();
  return '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
}

class MoneyChoice {
  const MoneyChoice(this.id, this.name, {this.note});

  final String id;
  final String name;
  final String? note;
}

/// সার্ভারের উত্তর — বসেছে, না সইয়ের অপেক্ষায়
class MoneyResult {
  const MoneyResult(this.status, this.notice, {this.id});

  final String status;
  final String notice;
  final String? id;

  bool get done => status == 'done';
}

void _tell(BuildContext context, MoneyResult r) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      key: const ValueKey('money-result'),
      backgroundColor: r.done ? AppColors.success : AppColors.pending,
      content: Text(r.notice.isEmpty ? (r.done ? 'বসেছে।' : 'সইয়ের অপেক্ষায়।') : r.notice),
    ));

List<Map> _maps(Object? raw) => raw is List ? raw.whereType<Map>().toList() : const [];

class TransferRow {
  const TransferRow({required this.id, required this.no, this.date = '', this.from, this.to, this.amount = '0', this.statusLabel = '', this.status = '', this.mine = false});

  final String id;
  final String no;
  final String date;
  final String? from;
  final String? to;
  final String amount;
  final String status;
  final String statusLabel;

  /// আমার নেওয়ার অপেক্ষায়
  final bool mine;

  factory TransferRow.fromJson(Map j) => TransferRow(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        date: j['date']?.toString() ?? '',
        from: j['from']?.toString(),
        to: j['to']?.toString(),
        amount: j['amount']?.toString() ?? '0',
        status: j['status']?.toString() ?? '',
        statusLabel: j['status_label']?.toString() ?? '',
        mine: j['mine_to_receive'] == true,
      );
}

class CountRow {
  const CountRow({required this.id, required this.no, this.date = '', this.till, this.counted = '', this.expected = '', this.difference = '', this.statusLabel = '', this.canApprove = false});

  final String id;
  final String no;
  final String date;
  final String? till;
  final String counted;
  final String expected;
  final String difference;
  final String statusLabel;
  final bool canApprove;

  factory CountRow.fromJson(Map j) => CountRow(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        date: j['date']?.toString() ?? '',
        till: j['till']?.toString(),
        counted: j['counted']?.toString() ?? '',
        expected: j['expected']?.toString() ?? '',
        difference: j['difference']?.toString() ?? '',
        statusLabel: j['status_label']?.toString() ?? '',
        canApprove: j['can_approve'] == true,
      );
}

abstract class MoneyApi {
  Future<Map<String, dynamic>> get(String path, {Map<String, Object?>? query});
  Future<MoneyResult> post(String path, Map<String, Object?> body);
}

class ServerMoneyApi implements MoneyApi {
  const ServerMoneyApi();

  @override
  Future<Map<String, dynamic>> get(String path, {Map<String, Object?>? query}) async =>
      (await ApiClient.dio.get<Map<String, dynamic>>(path, queryParameters: query)).data ?? const {};

  @override
  Future<MoneyResult> post(String path, Map<String, Object?> body) async {
    final data = (await ApiClient.dio.post<Map<String, dynamic>>(path, data: body)).data ?? const {};
    return MoneyResult(data['status']?.toString() ?? 'done', data['notice']?.toString() ?? '', id: data['id']?.toString());
  }
}

/// একটা পাতা যা একবার আনে আর টানলে আবার — ভুল হলে সার্ভারের বাংলা কথা
class _Loaded extends StatefulWidget {
  const _Loaded({required this.title, required this.load, required this.build, this.floating});

  final String title;
  final Future<Map<String, dynamic>> Function() load;
  final List<Widget> Function(BuildContext context, Map<String, dynamic> data, Future<void> Function() reload) build;
  final Widget Function(Future<void> Function() reload)? floating;

  @override
  State<_Loaded> createState() => _LoadedState();
}

class _LoadedState extends State<_Loaded> {
  Map<String, dynamic>? _data;
  String? _error;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  Future<void> _reload() async {
    try {
      final data = await widget.load();
      if (mounted) {
        setState(() {
          _data = data;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: '${widget.title} আনা গেল না।', whenAbsent: 'এই পাতা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      floatingActionButton: widget.floating?.call(_reload),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (data == null && _error == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (data != null) ...widget.build(context, data, _reload),
          ],
        ),
      ),
    );
  }
}

// ── কার হাতে কত ──────────────────────────────────────────────────────────────────────────────────

class CustodyScreen extends StatelessWidget {
  const CustodyScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'টাকা কার হাতে',
        load: () => api.get('/accounts/custody'),
        build: (context, d, _) => [
          TotalStrip(label: 'পথের টাকা', value: Money.taka(d['on_the_road'] ?? '0')),
          if (_maps(d['rows']).isEmpty) const EmptyState(icon: Icons.account_balance_wallet_outlined, title: 'কোনো টাকার খাত নেই', message: ''),
          for (final r in _maps(d['rows']))
            Card(
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: ListTile(
                title: Text(r['name']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text([r['kind'], r['holder']].whereType<Object>().map((x) => x.toString()).where((x) => x.isNotEmpty).join(' · ')),
                trailing: Text(r['balance']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
            ),
          if (_maps(d['transfers']).isNotEmpty) const SectionTitle('পথে আছে'),
          for (final t in _maps(d['transfers']).map(TransferRow.fromJson))
            Card(child: ListTile(title: Text(t.no), subtitle: Text('${t.from ?? ''} → ${t.to ?? ''}'), trailing: Text(Money.taka(t.amount)))),
        ],
      );
}

// ── হস্তান্তর ───────────────────────────────────────────────────────────────────────────────────────

class TransferListScreen extends StatelessWidget {
  const TransferListScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'টাকা হস্তান্তর',
        load: () => api.get('/accounts/transfers'),
        floating: (reload) => FloatingActionButton.extended(
          key: const ValueKey('transfer-new'),
          onPressed: () async {
            await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => SendTransferScreen(api: api)));
            await reload();
          },
          icon: const Icon(Icons.send_outlined),
          label: const Text('পাঠান'),
        ),
        build: (context, d, reload) {
          Widget row(TransferRow t) => Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  key: ValueKey('transfer-${t.id}'),
                  title: Text(t.no, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('${t.from ?? ''} → ${t.to ?? ''}\n${[t.date, t.statusLabel].where((s) => s.isNotEmpty).join(' · ')}'),
                  isThreeLine: true,
                  trailing: Text(Money.taka(t.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () async {
                    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => TransferScreen(id: t.id, api: api)));
                    await reload();
                  },
                ),
              );
          final awaiting = _maps(d['awaiting']).map(TransferRow.fromJson).toList();
          final rows = _maps(d['rows']).map(TransferRow.fromJson).toList();
          return [
            if (awaiting.isNotEmpty) ...[const SectionTitle('আমার নেওয়ার অপেক্ষায়'), for (final t in awaiting) row(t)],
            if (rows.isEmpty && awaiting.isEmpty)
              const EmptyState(icon: Icons.swap_horiz, title: 'কোনো হস্তান্তর নেই', message: 'নিচে টেনে আবার দেখুন।'),
            if (rows.isNotEmpty) ...[const SectionTitle('সব'), for (final t in rows) row(t)],
          ];
        },
      );
}

class TransferScreen extends StatefulWidget {
  const TransferScreen({super.key, required this.id, this.api = const ServerMoneyApi()});

  final String id;
  final MoneyApi api;

  @override
  State<TransferScreen> createState() => _TransferScreenState();
}

class _TransferScreenState extends State<TransferScreen> {
  final _confirmOnce = OnceKey();
  final _cancelOnce = OnceKey();
  bool _busy = false;
  String? _error;

  Future<void> _act(OnceKey once, String path, Map<String, Object?> body, Future<void> Function() reload) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await once.send(() => widget.api.post(path, body));
      if (!mounted) return;
      _tell(context, r);
      await reload();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'করা গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<String?> _askReason() async {
    final c = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('কেন বাতিল?'),
        content: TextField(key: const ValueKey('transfer-cancel-reason'), controller: c, autofocus: true),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('থাক')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('বাতিল করুন')),
        ],
      ),
    );
    final text = c.text.trim();
    c.dispose();
    return ok == true && text.isNotEmpty ? text : null;
  }

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'হস্তান্তর',
        load: () => widget.api.get('/accounts/transfers/${Uri.encodeComponent(widget.id)}'),
        build: (context, d, reload) {
          final t = TransferRow.fromJson(d);
          return [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(t.no, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                  FactRow('অবস্থা', t.statusLabel),
                  FactRow('তারিখ', t.date),
                  FactRow('কোথা থেকে', t.from ?? ''),
                  FactRow('কোথায়', t.to ?? ''),
                  FactRow('অঙ্ক', Money.taka(t.amount)),
                  if (d['giver'] != null) FactRow('দিলেন', d['giver'].toString()),
                  if (d['receiver'] != null) FactRow('নেবেন', d['receiver'].toString()),
                  if (d['narration'] != null) FactRow('বিবরণ', d['narration'].toString()),
                  if (d['cancel_reason'] != null) FactRow('বাতিলের কারণ', d['cancel_reason'].toString()),
                ]),
              ),
            ),
            if (_error != null) BooksError(_error!),
            if (t.status == 'draft')
              Row(children: [
                if (t.mine)
                  Expanded(
                    child: FilledButton.icon(
                      key: const ValueKey('transfer-confirm'),
                      onPressed: _busy ? null : () => _act(_confirmOnce, '/accounts/transfers/${widget.id}/confirm', const {}, reload),
                      icon: const Icon(Icons.call_received),
                      label: const Text('টাকা পেলাম'),
                    ),
                  ),
                if (t.mine) const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: OutlinedButton.icon(
                    key: const ValueKey('transfer-cancel'),
                    onPressed: _busy
                        ? null
                        : () async {
                            final reason = await _askReason();
                            if (reason != null) await _act(_cancelOnce, '/accounts/transfers/${widget.id}/cancel', {'cancel_reason': reason}, reload);
                          },
                    icon: const Icon(Icons.cancel_outlined),
                    label: const Text('বাতিল'),
                  ),
                ),
              ]),
          ];
        },
      );
}

class SendTransferScreen extends StatefulWidget {
  const SendTransferScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  State<SendTransferScreen> createState() => _SendTransferScreenState();
}

class _SendTransferScreenState extends State<SendTransferScreen> {
  final _once = OnceKey();
  final _amount = TextEditingController();
  final _note = TextEditingController();
  List<MoneyChoice> _tills = const [];
  List<MoneyChoice> _destinations = const [];
  List<MoneyChoice> _people = const [];
  String? _from;
  String? _to;
  String? _receiver;
  bool _loaded = false;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.api.get('/accounts/money/setup').then((d) {
      if (!mounted) return;
      setState(() {
        _tills = [for (final t in _maps(d['tills'])) MoneyChoice(t['id'].toString(), t['name'].toString(), note: t['balance']?.toString())];
        _destinations = [for (final t in _maps(d['destinations'])) MoneyChoice(t['id'].toString(), t['name'].toString())];
        _people = [for (final t in _maps(d['people'])) MoneyChoice(t['id'].toString(), t['name'].toString())];
        if (_tills.length == 1) _from = _tills.first.id;
        _loaded = true;
      });
    }).catchError((Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'টিলের তালিকা আনা গেল না।'));
    });
  }

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (_from == null || _to == null || _amount.text.trim().isEmpty) {
      setState(() => _error = 'কোথা থেকে, কোথায় আর কত — লাগবেই।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await _once.send(() => widget.api.post('/accounts/transfers', {
            'trx_date': _today(),
            'from_till': _from,
            'destination': _to,
            'amount': _amount.text.trim(),
            if (_receiver != null) 'received_by': _receiver,
            if (_note.text.trim().isNotEmpty) 'narration': _note.text.trim(),
          }));
      if (!mounted) return;
      _tell(context, r);
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'হস্তান্তর পাঠানো গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    const gap = SizedBox(height: AppSpacing.sm);
    DropdownButtonFormField<String> pick(String key, String label, String? value, List<MoneyChoice> from, ValueChanged<String?> on) =>
        DropdownButtonFormField<String>(
          key: ValueKey(key),
          initialValue: value,
          isExpanded: true,
          decoration: InputDecoration(labelText: label, isDense: true),
          items: [for (final c in from) DropdownMenuItem(value: c.id, child: Text(c.note == null ? c.name : '${c.name} · ${c.note}'))],
          onChanged: on,
        );

    return Scaffold(
      appBar: AppBar(title: const Text('টাকা পাঠান')),
      body: ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
        if (!_loaded && _error == null) const LinearProgressIndicator(),
        pick('transfer-from', 'কোন টিল থেকে', _from, _tills, (v) => setState(() => _from = v)),
        gap,
        pick('transfer-to', 'কোথায় — টিল বা ব্যাংক', _to, _destinations.where((d) => d.id != 'till:$_from').toList(), (v) => setState(() => _to = v)),
        gap,
        pick('transfer-receiver', 'কে নেবেন (ঐচ্ছিক)', _receiver, _people, (v) => setState(() => _receiver = v)),
        gap,
        TextField(key: const ValueKey('transfer-amount'), controller: _amount, keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'অঙ্ক', isDense: true)),
        gap,
        TextField(key: const ValueKey('transfer-note'), controller: _note, decoration: const InputDecoration(labelText: 'বিবরণ (ঐচ্ছিক)', isDense: true)),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
        const SizedBox(height: AppSpacing.md),
        FilledButton.icon(key: const ValueKey('transfer-send'), onPressed: _busy ? null : _send, icon: const Icon(Icons.send), label: const Text('পাঠান')),
      ]),
    );
  }
}

// ── নগদ গোনা ─────────────────────────────────────────────────────────────────────────────────────

class CashCountListScreen extends StatelessWidget {
  const CashCountListScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'নগদ গোনা',
        load: () => api.get('/accounts/cash-counts'),
        floating: (reload) => FloatingActionButton.extended(
          key: const ValueKey('count-new'),
          onPressed: () async {
            await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => NewCashCountScreen(api: api)));
            await reload();
          },
          icon: const Icon(Icons.calculate_outlined),
          label: const Text('গুনুন'),
        ),
        build: (context, d, reload) {
          final rows = _maps(d['rows']).map(CountRow.fromJson).toList();
          return [
            if (rows.isEmpty) const EmptyState(icon: Icons.calculate_outlined, title: 'কোনো গোনা নেই', message: 'নিচে টেনে আবার দেখুন।'),
            for (final c in rows)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  key: ValueKey('count-${c.id}'),
                  title: Text(c.no, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('${c.till ?? ''}\n${[c.date, c.statusLabel, 'পার্থক্য ${c.difference}'].join(' · ')}'),
                  isThreeLine: true,
                  trailing: Text(c.counted, style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () async {
                    await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => CashCountScreen(id: c.id, api: api)));
                    await reload();
                  },
                ),
              ),
          ];
        },
      );
}

class CashCountScreen extends StatefulWidget {
  const CashCountScreen({super.key, required this.id, this.api = const ServerMoneyApi()});

  final String id;
  final MoneyApi api;

  @override
  State<CashCountScreen> createState() => _CashCountScreenState();
}

class _CashCountScreenState extends State<CashCountScreen> {
  final _once = OnceKey();
  bool _busy = false;
  String? _error;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'নগদ গোনা',
        load: () => widget.api.get('/accounts/cash-counts/${Uri.encodeComponent(widget.id)}'),
        build: (context, d, reload) {
          final c = CountRow.fromJson(d);
          return [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(c.no, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                  FactRow('অবস্থা', c.statusLabel),
                  FactRow('টিল', c.till ?? ''),
                  FactRow('তারিখ', c.date),
                  FactRow('গোনা', c.counted),
                  FactRow('খাতায়', c.expected),
                  FactRow('পার্থক্য', c.difference),
                  for (final n in _maps(d['notes']).where((n) => (n['qty'] as num? ?? 0) > 0)) FactRow('${n['note']} টাকার নোট', '${n['qty']}টা'),
                  if (d['counted_by'] != null) FactRow('গুনেছেন', d['counted_by'].toString()),
                  if (d['approved_by'] != null) FactRow('মেনেছেন', d['approved_by'].toString()),
                  if (d['adjustment'] != null) FactRow('সমন্বয়ের ভাউচার', d['adjustment'].toString()),
                ]),
              ),
            ),
            if (_error != null) BooksError(_error!),
            if (c.canApprove)
              FilledButton.icon(
                key: const ValueKey('count-approve'),
                onPressed: _busy
                    ? null
                    : () async {
                        setState(() {
                          _busy = true;
                          _error = null;
                        });
                        try {
                          final r = await _once.send(() => widget.api.post('/accounts/cash-counts/${widget.id}/approve', const {}));
                          if (!context.mounted) return;
                          _tell(context, r);
                          await reload();
                        } catch (e) {
                          if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'মেনে নেওয়া গেল না।'));
                        } finally {
                          if (mounted) setState(() => _busy = false);
                        }
                      },
                icon: const Icon(Icons.verified_outlined),
                label: const Text('মেনে নিন'),
              ),
          ];
        },
      );
}

class NewCashCountScreen extends StatefulWidget {
  const NewCashCountScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  State<NewCashCountScreen> createState() => _NewCashCountScreenState();
}

class _NewCashCountScreenState extends State<NewCashCountScreen> {
  final _once = OnceKey();
  final Map<int, TextEditingController> _notes = {};
  List<MoneyChoice> _tills = const [];
  String? _till;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.api.get('/accounts/cash-counts/setup').then((d) {
      if (!mounted) return;
      setState(() {
        _tills = [for (final t in _maps(d['tills'])) MoneyChoice(t['id'].toString(), t['name'].toString(), note: t['balance']?.toString())];
        if (_tills.length == 1) _till = _tills.first.id;
        for (final n in (d['notes'] is List ? d['notes'] as List : const [])) {
          _notes[(n as num).toInt()] = TextEditingController();
        }
      });
    }).catchError((Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'টিলের তালিকা আনা গেল না।'));
    });
  }

  @override
  void dispose() {
    for (final c in _notes.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _send() async {
    if (_till == null) {
      setState(() => _error = 'কোন টিল — বাছুন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await _once.send(() => widget.api.post('/accounts/cash-counts', {
            'till': _till,
            'trx_date': _today(),
            'counts': {for (final e in _notes.entries) e.key.toString(): int.tryParse(e.value.text.trim()) ?? 0},
          }));
      if (!mounted) return;
      _tell(context, r);
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'গোনা বসানো গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('নগদ গুনুন')),
      body: ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
        DropdownButtonFormField<String>(
          key: const ValueKey('count-till'),
          initialValue: _till,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'কোন টিল', isDense: true),
          items: [for (final t in _tills) DropdownMenuItem(value: t.id, child: Text('${t.name} · খাতায় ${t.note ?? ''}'))],
          onChanged: (v) => setState(() => _till = v),
        ),
        const SizedBox(height: AppSpacing.sm),
        for (final e in _notes.entries)
          Padding(
            padding: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: TextField(
              key: ValueKey('count-note-${e.key}'),
              controller: e.value,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(labelText: '${e.key} টাকার নোট — কয়টা', isDense: true),
            ),
          ),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
        const SizedBox(height: AppSpacing.md),
        FilledButton.icon(key: const ValueKey('count-send'), onPressed: _busy ? null : _send, icon: const Icon(Icons.check), label: const Text('গোনা বসান')),
        const Padding(
          padding: EdgeInsets.only(top: AppSpacing.sm),
          child: Text('গোনা বসলে খাতা নড়ে না — অন্য কেউ মেনে নিলে তবেই পার্থক্য খাতায় যায়।', style: TextStyle(color: AppColors.onSurfaceMuted)),
        ),
      ]),
    );
  }
}

// ── হাতধারের দেওয়া-নেওয়া ───────────────────────────────────────────────────────────────────────────

class HandLoanMoveScreen extends StatefulWidget {
  const HandLoanMoveScreen({super.key, required this.id, required this.name, this.api = const ServerMoneyApi()});

  final String id;
  final String name;
  final MoneyApi api;

  @override
  State<HandLoanMoveScreen> createState() => _HandLoanMoveScreenState();
}

class _HandLoanMoveScreenState extends State<HandLoanMoveScreen> {
  final _once = OnceKey();
  final _amount = TextEditingController();
  final _note = TextEditingController();
  List<MoneyChoice> _accounts = const [];
  String _direction = 'out';
  String? _account;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    widget.api.get('/finance/hand-loans/money-accounts').then((d) {
      if (mounted) setState(() => _accounts = [for (final a in _maps(d['rows'])) MoneyChoice(a['id'].toString(), a['name'].toString())]);
    }).catchError((Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'টাকার খাত আনা গেল না।'));
    });
  }

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (_account == null || _amount.text.trim().isEmpty) {
      setState(() => _error = 'টাকার খাত আর অঙ্ক লাগবেই।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final r = await _once.send(() => widget.api.post('/finance/hand-loans/${widget.id}/move', {
            'direction': _direction,
            'amount': _amount.text.trim(),
            'moved_on': _today(),
            'money_account': _account,
            if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
          }));
      if (!mounted) return;
      _tell(context, r);
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'হাতধার বসানো গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    const gap = SizedBox(height: AppSpacing.sm);
    return Scaffold(
      appBar: AppBar(title: Text('হাতধার · ${widget.name}')),
      body: ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
        SegmentedButton<String>(
          key: const ValueKey('handloan-direction'),
          segments: const [
            ButtonSegment(value: 'out', label: Text('টাকা দিলাম'), icon: Icon(Icons.north_east)),
            ButtonSegment(value: 'in', label: Text('ফেরত পেলাম'), icon: Icon(Icons.south_west)),
          ],
          selected: {_direction},
          onSelectionChanged: (s) => setState(() => _direction = s.first),
        ),
        gap,
        DropdownButtonFormField<String>(
          key: const ValueKey('handloan-account'),
          initialValue: _account,
          isExpanded: true,
          decoration: InputDecoration(labelText: _direction == 'out' ? 'কোন খাত থেকে' : 'কোন খাতে', isDense: true),
          items: [for (final a in _accounts) DropdownMenuItem(value: a.id, child: Text(a.name))],
          onChanged: (v) => setState(() => _account = v),
        ),
        gap,
        TextField(key: const ValueKey('handloan-amount'), controller: _amount, keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: const InputDecoration(labelText: 'অঙ্ক', isDense: true)),
        gap,
        TextField(key: const ValueKey('handloan-note'), controller: _note, decoration: const InputDecoration(labelText: 'নোট (ঐচ্ছিক)', isDense: true)),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
        const SizedBox(height: AppSpacing.md),
        FilledButton.icon(key: const ValueKey('handloan-send'), onPressed: _busy ? null : _send, icon: const Icon(Icons.check), label: const Text('বসান')),
      ]),
    );
  }
}

// ── অর্থের চার পাতা ─────────────────────────────────────────────────────────────────────────────────

class CfoScreen extends StatelessWidget {
  const CfoScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'অর্থ-প্রধানের পাতা',
        load: () => api.get('/finance/cfo'),
        build: (context, d, _) {
          final liquidity = d['liquidity'] is Map ? d['liquidity'] as Map : const {};
          final budget = d['budget'] is Map ? d['budget'] as Map : null;
          return [
            TotalStrip(label: '৩০ দিন পরে হাতে', value: Money.taka(d['in_30_days'])),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(children: [
                  for (final f in _maps(d['figures'])) FactRow(f['label'].toString(), f['value'].toString()),
                  if (liquidity.isNotEmpty)
                    FactRow(liquidity['label']?.toString() ?? '', liquidity['rule']?.toString() ?? '',
                        colour: liquidity['code'] == 'good' ? AppColors.success : AppColors.danger),
                ]),
              ),
            ),
            if (budget != null) ...[
              const SectionTitle('এই মাসের বাজেট'),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(children: [
                    FactRow('বাজেট', budget['budget'].toString()),
                    FactRow('খরচ হয়েছে', budget['actual'].toString()),
                    if (budget['used_pct'] != null) FactRow('ব্যবহার', '${budget['used_pct']}%'),
                  ]),
                ),
              ),
            ],
          ];
        },
      );
}

class CashForecastScreen extends StatelessWidget {
  const CashForecastScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'নগদের পূর্বাভাস',
        load: () => api.get('/finance/cash-forecast'),
        build: (context, d, _) => [
          TotalStrip(label: 'আজ হাতে', value: Money.taka(d['opening'])),
          for (final r in _maps(d['rows']))
            Card(
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(children: [
                  FactRow('${r['when']} (${r['until']} পর্যন্ত)', r['closing'].toString()),
                  FactRow('আসবে — পাওনা', r['receivables'].toString()),
                  FactRow('আসবে — ধার', r['loans_in'].toString()),
                  FactRow('যাবে — দেনা', r['payables'].toString()),
                  FactRow('যাবে — ধার শোধ', r['loans_out'].toString()),
                  FactRow('নিট', r['net'].toString()),
                ]),
              ),
            ),
          if (d['note'] != null)
            Padding(padding: const EdgeInsets.all(AppSpacing.sm), child: Text(d['note'].toString(), style: const TextStyle(color: AppColors.onSurfaceMuted))),
        ],
      );
}

class IncomeScreen extends StatelessWidget {
  const IncomeScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'আয়',
        load: () => api.get('/finance/income'),
        build: (context, d, _) => [
          TotalStrip(label: 'মোট আয় · এই মাস', value: Money.taka(d['total'])),
          FactRow('বিক্রি', d['sales']?.toString() ?? ''),
          FactRow('অন্যান্য আয়', d['other']?.toString() ?? ''),
          const SectionTitle('খাত ধরে'),
          if (_maps(d['rows']).isEmpty) const EmptyState(icon: Icons.trending_up, title: 'এই মাসে আয়ের কোনো সারি নেই', message: ''),
          for (final r in _maps(d['rows']))
            Card(
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: ListTile(title: Text(r['head'].toString()), subtitle: Text('আগের সময়ে ${r['before']}'), trailing: Text(r['now'].toString())),
            ),
        ],
      );
}

class BudgetScreen extends StatelessWidget {
  const BudgetScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: 'বাজেট',
        load: () => api.get('/finance/budget'),
        build: (context, d, _) {
          final s = d['status'] is Map ? d['status'] as Map : null;
          if (s == null) return const [EmptyState(icon: Icons.pie_chart_outline, title: 'এই মাসের কোনো বাজেট নেই', message: 'বাজেট ওয়েবে বসান।')];
          return [
            TotalStrip(label: 'এই মাসের বাজেট', value: Money.taka(s['budget'])),
            FactRow('খরচ হয়েছে', s['actual'].toString()),
            if (s['used_pct'] != null) FactRow('ব্যবহার', '${s['used_pct']}%'),
          ];
        },
      );
}
