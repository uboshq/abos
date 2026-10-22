import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/navigation/top_bar/abos_top_bar.dart' show bnDigits;
import '../../core/orders/paper_total.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/fab_clearance.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/paged_list.dart';
import '../books/books_widgets.dart';
import '../../core/widgets/unsaved_guard.dart';

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
      backgroundColor: r.done ? context.palette.success : context.palette.pending,
      content: Text(r.notice.isEmpty ? (r.done ? context.l10n.moneyResultDone : context.l10n.moneyResultPending) : r.notice),
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
            fallback: context.l10n.moneyLoadFailed(widget.title), whenAbsent: context.l10n.moneyPageMissing));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    return Scaffold(
      appBar: AbosScreenBar(title: widget.title),
      floatingActionButton: widget.floating?.call(_reload),
      body: RefreshIndicator(
        onRefresh: _reload,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: FabClearance.pad(context, const EdgeInsets.all(AppSpacing.md), own: widget.floating != null),
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
        title: context.l10n.moneyCustodyTitle,
        load: () => api.get('/accounts/custody'),
        build: (context, d, _) => [
          TotalStrip(label: context.l10n.moneyCustodyOnRoad, value: Money.taka(d['on_the_road'] ?? '0')),
          if (_maps(d['rows']).isEmpty) EmptyState(icon: Icons.account_balance_wallet_outlined, title: context.l10n.moneyCustodyEmpty, message: ''),
          for (final r in _maps(d['rows']))
            Card(
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: ListTile(
                title: Text(r['name']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text([r['kind'], r['holder']].whereType<Object>().map((x) => x.toString()).where((x) => x.isNotEmpty).join(' · ')),
                trailing: Text(r['balance']?.toString() ?? '', style: const TextStyle(fontWeight: FontWeight.w700)),
              ),
            ),
          if (_maps(d['transfers']).isNotEmpty) SectionTitle(context.l10n.moneyCustodyInTransit),
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
        title: context.l10n.moneyTransferListTitle,
        load: () => api.get('/accounts/transfers'),
        floating: (reload) => FloatingActionButton.extended(
          key: const ValueKey('transfer-new'),
          onPressed: () async {
            await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => SendTransferScreen(api: api)));
            await reload();
          },
          icon: const Icon(Icons.send_outlined),
          label: Text(context.l10n.moneyTransferSend),
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
          // ⭐ গোটা তালিকার মোট — সার্ভারের, বাতিল বাদ; পুরনো সার্ভার না পাঠালে পট্টি নেই (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
          final total = PaperTotal.fromJson(d);
          return [
            if (total != null && (rows.isNotEmpty || awaiting.isNotEmpty)) TotalStrip(label: context.l10n.moneyTransferTotal, value: Money.taka(total.sum), count: total.count),
            if (awaiting.isNotEmpty) ...[SectionTitle(context.l10n.moneyTransferAwaitingMe), for (final t in awaiting) row(t)],
            if (rows.isEmpty && awaiting.isEmpty)
              EmptyState(icon: Icons.swap_horiz, title: context.l10n.moneyTransferEmpty, message: context.l10n.moneyPullToRefresh),
            if (rows.isNotEmpty) ...[SectionTitle(context.l10n.moneyTransferAll), for (final t in rows) row(t)],
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
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.moneyActionFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<String?> _askReason() async {
    final c = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(context.l10n.moneyTransferCancelWhy),
        content: TextField(key: const ValueKey('transfer-cancel-reason'), controller: c, autofocus: true),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(context.l10n.moneyTransferKeep)),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(context.l10n.moneyTransferCancelConfirm)),
        ],
      ),
    );
    final text = c.text.trim();
    c.dispose();
    return ok == true && text.isNotEmpty ? text : null;
  }

  @override
  Widget build(BuildContext context) => _Loaded(
        title: context.l10n.moneyTransferTitle,
        load: () => widget.api.get('/accounts/transfers/${Uri.encodeComponent(widget.id)}'),
        build: (context, d, reload) {
          final t = TransferRow.fromJson(d);
          return [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(t.no, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                  FactRow(context.l10n.moneyFactStatus, t.statusLabel),
                  FactRow(context.l10n.moneyFactDate, t.date),
                  FactRow(context.l10n.moneyTransferFrom, t.from ?? ''),
                  FactRow(context.l10n.moneyTransferTo, t.to ?? ''),
                  FactRow(context.l10n.moneyFactAmount, Money.taka(t.amount)),
                  if (d['giver'] != null) FactRow(context.l10n.moneyTransferGiver, d['giver'].toString()),
                  if (d['receiver'] != null) FactRow(context.l10n.moneyTransferReceiver, d['receiver'].toString()),
                  if (d['narration'] != null) FactRow(context.l10n.moneyTransferNarration, d['narration'].toString()),
                  if (d['cancel_reason'] != null) FactRow(context.l10n.moneyTransferCancelReason, d['cancel_reason'].toString()),
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
                      label: Text(context.l10n.moneyTransferReceived),
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
                    label: Text(context.l10n.moneyTransferCancel),
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
    _amount.addListener(_typed);
    _note.addListener(_typed);
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
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.moneyTillsLoadFailed));
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
      setState(() => _error = context.l10n.moneyTransferMissing);
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
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.moneyTransferSendFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  /// লেখা আছে: গন্তব্য, যিনি নেবেন, অঙ্ক, বিবরণ, বা কয়েকটা টিলের মধ্যে একটা বাছা
  bool get _transferDirty =>
      !_busy &&
      (_to != null ||
          _receiver != null ||
          _amount.text.trim().isNotEmpty ||
          _note.text.trim().isNotEmpty ||
          (_tills.length > 1 && _from != null));

  // ⭐ গন্তব্য বা অঙ্ক লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা হস্তান্তর এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _transferDirty, child: _screen(context));

  Widget _screen(BuildContext context) {
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
      appBar: AbosScreenBar(title: context.l10n.moneySendTitle),
      body: ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
        if (!_loaded && _error == null) const LinearProgressIndicator(),
        pick('transfer-from', context.l10n.moneyTransferFromTill, _from, _tills, (v) => setState(() => _from = v)),
        gap,
        pick('transfer-to', context.l10n.moneyTransferToLabel, _to, _destinations.where((d) => d.id != 'till:$_from').toList(), (v) => setState(() => _to = v)),
        gap,
        pick('transfer-receiver', context.l10n.moneyTransferReceiverLabel, _receiver, _people, (v) => setState(() => _receiver = v)),
        gap,
        TextField(key: const ValueKey('transfer-amount'), controller: _amount, keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: context.l10n.moneyFactAmount, isDense: true)),
        gap,
        TextField(key: const ValueKey('transfer-note'), controller: _note, decoration: InputDecoration(labelText: context.l10n.moneyTransferNoteLabel, isDense: true)),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
        const SizedBox(height: AppSpacing.md),
        FilledButton.icon(key: const ValueKey('transfer-send'), onPressed: _busy ? null : _send, icon: const Icon(Icons.send), label: Text(context.l10n.moneyTransferSend)),
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
        title: context.l10n.moneyCashCountTitle,
        load: () => api.get('/accounts/cash-counts'),
        floating: (reload) => FloatingActionButton.extended(
          key: const ValueKey('count-new'),
          onPressed: () async {
            await Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => NewCashCountScreen(api: api)));
            await reload();
          },
          icon: const Icon(Icons.calculate_outlined),
          label: Text(context.l10n.moneyCashCountNew),
        ),
        build: (context, d, reload) {
          final rows = _maps(d['rows']).map(CountRow.fromJson).toList();
          // ⭐ কেবল গোনা — গোনা এক মুহূর্তের ছবি, টাকা যোগ হয় না; ⛔ পুরনো সার্ভার না পাঠালে পট্টি নেই (পূর্ণ নিরীক্ষা §৯ ⚠️৭, ১৮ অক্টোবর ২০২৬)
          final count = (d['count'] as num?)?.toInt();
          return [
            if (count != null && rows.isNotEmpty) TotalStrip(label: context.l10n.moneyCashCountTotal, value: context.l10n.moneyCashCountTotalValue(count)),
            if (rows.isEmpty) EmptyState(icon: Icons.calculate_outlined, title: context.l10n.moneyCashCountEmpty, message: context.l10n.moneyPullToRefresh),
            for (final c in rows)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: ListTile(
                  key: ValueKey('count-${c.id}'),
                  title: Text(c.no, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('${c.till ?? ''}\n${[c.date, c.statusLabel, context.l10n.moneyCashCountDifference(c.difference)].join(' · ')}'),
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
        title: context.l10n.moneyCashCountTitle,
        load: () => widget.api.get('/accounts/cash-counts/${Uri.encodeComponent(widget.id)}'),
        build: (context, d, reload) {
          final c = CountRow.fromJson(d);
          return [
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(c.no, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                  FactRow(context.l10n.moneyFactStatus, c.statusLabel),
                  FactRow(context.l10n.moneyFactTill, c.till ?? ''),
                  FactRow(context.l10n.moneyFactDate, c.date),
                  FactRow(context.l10n.moneyFactCounted, c.counted),
                  FactRow(context.l10n.moneyFactExpected, c.expected),
                  FactRow(context.l10n.moneyFactDifference, c.difference),
                  for (final n in _maps(d['notes']).where((n) => (n['qty'] as num? ?? 0) > 0)) FactRow(context.l10n.moneyCashCountNoteRow('${n['note']}'), context.l10n.moneyCashCountNoteQty('${n['qty']}')),
                  if (d['counted_by'] != null) FactRow(context.l10n.moneyCashCountBy, d['counted_by'].toString()),
                  if (d['approved_by'] != null) FactRow(context.l10n.moneyCashCountApprovedBy, d['approved_by'].toString()),
                  if (d['adjustment'] != null) FactRow(context.l10n.moneyCashCountAdjustment, d['adjustment'].toString()),
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
                          if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.moneyCashCountApproveFailed));
                        } finally {
                          if (mounted) setState(() => _busy = false);
                        }
                      },
                icon: const Icon(Icons.verified_outlined),
                label: Text(context.l10n.moneyCashCountApprove),
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
          _notes[(n as num).toInt()] = TextEditingController()..addListener(_typed);
        }
      });
    }).catchError((Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.moneyTillsLoadFailed));
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
      setState(() => _error = context.l10n.moneyCashCountPickTill);
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
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.moneyCashCountSaveFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  /// লেখা আছে: কোনো নোটের সংখ্যা, বা কয়েকটা টিলের মধ্যে একটা বাছা
  bool get _countDirty =>
      !_busy && (_notes.values.any((c) => c.text.trim().isNotEmpty) || (_tills.length > 1 && _till != null));

  // ⭐ নোট গোনা লেখা থাকলে "পিছনে" জিজ্ঞেস করে — গোনা নগদ এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _countDirty, child: _screen(context));

  Widget _screen(BuildContext context) {
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.moneyCashCountNewTitle),
      body: ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
        DropdownButtonFormField<String>(
          key: const ValueKey('count-till'),
          initialValue: _till,
          isExpanded: true,
          decoration: InputDecoration(labelText: context.l10n.moneyCashCountTillLabel, isDense: true),
          items: [for (final t in _tills) DropdownMenuItem(value: t.id, child: Text(context.l10n.moneyCashCountTillOption(t.name, t.note ?? '')))],
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
              decoration: InputDecoration(labelText: context.l10n.moneyCashCountNoteLabel('${e.key}'), isDense: true),
            ),
          ),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
        const SizedBox(height: AppSpacing.md),
        FilledButton.icon(key: const ValueKey('count-send'), onPressed: _busy ? null : _send, icon: const Icon(Icons.check), label: Text(context.l10n.moneyCashCountSave)),
        Padding(
          padding: const EdgeInsets.only(top: AppSpacing.sm),
          child: Text(context.l10n.moneyCashCountHint, style: TextStyle(color: context.palette.onSurfaceMuted)),
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
    _amount.addListener(_typed);
    _note.addListener(_typed);
    widget.api.get('/finance/hand-loans/money-accounts').then((d) {
      if (mounted) setState(() => _accounts = [for (final a in _maps(d['rows'])) MoneyChoice(a['id'].toString(), a['name'].toString())]);
    }).catchError((Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.moneyAccountsLoadFailed));
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
      setState(() => _error = context.l10n.moneyHandLoanMissing);
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
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.moneyHandLoanSaveFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  /// লেখা আছে: খাত, অঙ্ক বা নোট
  bool get _loanDirty => !_busy && (_account != null || _amount.text.trim().isNotEmpty || _note.text.trim().isNotEmpty);

  // ⭐ খাত বা অঙ্ক লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা হাতধার এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _loanDirty, child: _screen(context));

  Widget _screen(BuildContext context) {
    const gap = SizedBox(height: AppSpacing.sm);
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.moneyHandLoanTitle(widget.name)),
      body: ListView(padding: const EdgeInsets.all(AppSpacing.md), children: [
        SegmentedButton<String>(
          key: const ValueKey('handloan-direction'),
          segments: [
            ButtonSegment(value: 'out', label: Text(context.l10n.moneyHandLoanOut), icon: const Icon(Icons.north_east)),
            ButtonSegment(value: 'in', label: Text(context.l10n.moneyHandLoanIn), icon: const Icon(Icons.south_west)),
          ],
          selected: {_direction},
          onSelectionChanged: (s) => setState(() => _direction = s.first),
        ),
        gap,
        DropdownButtonFormField<String>(
          key: const ValueKey('handloan-account'),
          initialValue: _account,
          isExpanded: true,
          decoration: InputDecoration(labelText: _direction == 'out' ? context.l10n.moneyHandLoanFromAccount : context.l10n.moneyHandLoanToAccount, isDense: true),
          items: [for (final a in _accounts) DropdownMenuItem(value: a.id, child: Text(a.name))],
          onChanged: (v) => setState(() => _account = v),
        ),
        gap,
        TextField(key: const ValueKey('handloan-amount'), controller: _amount, keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: context.l10n.moneyFactAmount, isDense: true)),
        gap,
        TextField(key: const ValueKey('handloan-note'), controller: _note, decoration: InputDecoration(labelText: context.l10n.moneyHandLoanNoteLabel, isDense: true)),
        if (_error != null) Padding(padding: const EdgeInsets.only(top: AppSpacing.sm), child: BooksError(_error!)),
        const SizedBox(height: AppSpacing.md),
        FilledButton.icon(key: const ValueKey('handloan-send'), onPressed: _busy ? null : _send, icon: const Icon(Icons.check), label: Text(context.l10n.moneyHandLoanSave)),
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
        title: context.l10n.moneyCfoTitle,
        load: () => api.get('/finance/cfo'),
        build: (context, d, _) {
          final liquidity = d['liquidity'] is Map ? d['liquidity'] as Map : const {};
          final budget = d['budget'] is Map ? d['budget'] as Map : null;
          final purchase = d['purchase_budget'] is Map ? d['purchase_budget'] as Map : null;
          // ⓘ নতুন সার্ভার ক্রয়কে আলাদা পাঠায় — তখন "budget" কেবল খরচের; পুরনো সার্ভারে দুটো একসাথে, নাম আগের মতো
          final expenseOnly = d.containsKey('purchase_budget');
          return [
            TotalStrip(label: context.l10n.moneyCfoIn30Days(bnDigits(30, Localizations.localeOf(context))), value: Money.taka(d['in_30_days'])),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(children: [
                  for (final f in _maps(d['figures'])) FactRow(f['label'].toString(), f['value'].toString()),
                  if (liquidity.isNotEmpty)
                    FactRow(liquidity['label']?.toString() ?? '', liquidity['rule']?.toString() ?? '',
                        colour: liquidity['code'] == 'good' ? context.palette.success : context.palette.danger),
                ]),
              ),
            ),
            if (budget != null) ...[
              SectionTitle(expenseOnly ? context.l10n.moneyBudgetExpenseMonth : context.l10n.moneyBudgetMonth),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(children: [
                    FactRow(context.l10n.moneyBudgetLabel, budget['budget'].toString()),
                    FactRow(context.l10n.moneyBudgetSpent, budget['actual'].toString()),
                    if (budget['used_pct'] != null) FactRow(context.l10n.moneyBudgetUsed, '${budget['used_pct']}%'),
                  ]),
                ),
              ),
            ],
            if (purchase != null) ...PurchaseBudgetCard.parts(context.l10n, purchase),
          ];
        },
      );
}

class CashForecastScreen extends StatelessWidget {
  const CashForecastScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: context.l10n.moneyForecastTitle,
        load: () => api.get('/finance/cash-forecast'),
        build: (context, d, _) => [
          TotalStrip(label: context.l10n.moneyForecastToday, value: Money.taka(d['opening'])),
          for (final r in _maps(d['rows']))
            Card(
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Column(children: [
                  FactRow(context.l10n.moneyForecastPeriod('${r['when']}', '${r['until']}'), r['closing'].toString()),
                  FactRow(context.l10n.moneyForecastReceivables, r['receivables'].toString()),
                  FactRow(context.l10n.moneyForecastLoansIn, r['loans_in'].toString()),
                  FactRow(context.l10n.moneyForecastPayables, r['payables'].toString()),
                  FactRow(context.l10n.moneyForecastLoansOut, r['loans_out'].toString()),
                  FactRow(context.l10n.moneyForecastNet, r['net'].toString()),
                ]),
              ),
            ),
          if (d['note'] != null)
            Padding(padding: const EdgeInsets.all(AppSpacing.sm), child: Text(d['note'].toString(), style: TextStyle(color: context.palette.onSurfaceMuted))),
        ],
      );
}

class IncomeScreen extends StatelessWidget {
  const IncomeScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: context.l10n.moneyIncomeTitle,
        load: () => api.get('/finance/income'),
        build: (context, d, _) => [
          TotalStrip(label: context.l10n.moneyIncomeTotal, value: Money.taka(d['total'])),
          FactRow(context.l10n.moneyIncomeSales, d['sales']?.toString() ?? ''),
          FactRow(context.l10n.moneyIncomeOther, d['other']?.toString() ?? ''),
          SectionTitle(context.l10n.moneyIncomeByHead),
          if (_maps(d['rows']).isEmpty) EmptyState(icon: Icons.trending_up, title: context.l10n.moneyIncomeEmpty, message: ''),
          for (final r in _maps(d['rows']))
            Card(
              margin: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: ListTile(title: Text(r['head'].toString()), subtitle: Text(context.l10n.moneyIncomeBefore('${r['before']}')), trailing: Text(r['now'].toString())),
            ),
        ],
      );
}

class BudgetScreen extends StatelessWidget {
  const BudgetScreen({super.key, this.api = const ServerMoneyApi()});

  final MoneyApi api;

  @override
  Widget build(BuildContext context) => _Loaded(
        title: context.l10n.moneyBudgetTitle,
        load: () => api.get('/finance/budget'),
        build: (context, d, _) {
          final s = d['status'] is Map ? d['status'] as Map : null;
          final purchase = d['purchase'] is Map ? d['purchase'] as Map : null;
          if (s == null && purchase == null) {
            return [EmptyState(icon: Icons.pie_chart_outline, title: context.l10n.moneyBudgetEmpty, message: context.l10n.moneyBudgetSetOnWeb)];
          }
          return [
            if (s != null) ...[
              TotalStrip(
                  label: d.containsKey('purchase') ? context.l10n.moneyBudgetExpenseMonth : context.l10n.moneyBudgetMonth, value: Money.taka(s['budget'])),
              FactRow(context.l10n.moneyBudgetSpent, s['actual'].toString()),
              if (s['used_pct'] != null) FactRow(context.l10n.moneyBudgetUsed, '${s['used_pct']}%'),
            ],
            if (purchase != null) ...PurchaseBudgetCard.parts(context.l10n, purchase),
          ];
        },
      );
}

/// ⭐ ক্রয়ের বাজেট — মজুদ (১১২০) খাতে এই মাসে কেনার বাজেট আর ক্রয়ের কাগজ থেকে কত কেনা হলো (ফেরত বাদ)। খরচ নয়, তাই খরচের
/// কার্ডে বা নিটে যোগ হয় না — আলাদা কার্ড (cb/fe, ২০ অক্টোবর ২০২৬; সার্ভারের `purchase_budget` / `purchase`:
/// `{budget, purchased, used_pct}`, অঙ্ক Money::format-এর লেখা)। মজুদের বাজেট না থাকলে সার্ভার null পাঠায় — তখন কার্ড নেই।
class PurchaseBudgetCard {
  const PurchaseBudgetCard._();

  static List<Widget> parts(AppL10n l, Map p) => [
        SectionTitle(l.moneyPurchaseBudget),
        Card(
          key: const ValueKey('purchase-budget'),
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Column(children: [
              FactRow(l.moneyBudgetLabel, p['budget'].toString()),
              FactRow(l.moneyPurchaseBought, p['purchased'].toString()),
              if (p['used_pct'] != null) FactRow(l.moneyBudgetUsed, '${p['used_pct']}%'),
            ]),
          ),
        ),
      ];
}
