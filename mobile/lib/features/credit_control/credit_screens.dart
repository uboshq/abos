import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';

/// ⭐ বাকি নিয়ন্ত্রণ, ফোনে — মালিক, ১৩ অক্টোবর ২০২৬: *"app e baki module & menu"*।
///
/// <p>উপরে ড্যাশবোর্ডের সংখ্যা (কত বাকি ব্যবহৃত, কত মেয়াদ পেরোনো, কে বন্ধ, কয়টা অনুরোধ সইয়ের অপেক্ষায়), নিচে চার তালিকা —
/// বেশি ঝুঁকি, মেয়াদ পেরোনো বিল, সীমা পার, সব গ্রাহক। গ্রাহকে চাপলে তাঁর বাকির পাতা ([[CustomerCreditScreen]])।
/// প্রতিটা অঙ্ক সার্ভারের ([[CreditControlApiController]]) — ওয়েবের ড্যাশবোর্ড, তালিকা আর দেয়ালের একই হিসাব।
class CreditDeskScreen extends StatefulWidget {
  const CreditDeskScreen({super.key, this.api = const ServerCreditControlApi()});

  final CreditControlApi api;

  @override
  State<CreditDeskScreen> createState() => _CreditDeskScreenState();
}

class _CreditDeskScreenState extends State<CreditDeskScreen> {
  static const Map<String, String> _lists = {
    'high-risk': 'বেশি ঝুঁকি',
    'overdue-bills': 'মেয়াদ পেরোনো বিল',
    'over-limit': 'সীমা পার',
    'positions': 'সব গ্রাহক',
  };

  String _list = 'high-risk';
  CreditDashboardFigures? _dash;
  CreditListPage? _page;
  bool _busy = false;
  String? _error;
  final _search = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load({bool listOnly = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final dash = listOnly ? _dash : await widget.api.dashboard();
      final page = await widget.api.list(_list, q: _search.text.trim());
      if (!mounted) return;
      setState(() {
        _dash = dash;
        _page = page;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'বাকির হিসাব আনা গেল না।',
            whenAbsent: 'বাকি নিয়ন্ত্রণ এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _pick(String list) {
    if (list == _list) return;
    setState(() {
      _list = list;
      _page = null;
    });
    _load(listOnly: true);
  }

  @override
  Widget build(BuildContext context) {
    final dash = _dash;
    final page = _page;

    return Scaffold(
      appBar: AppBar(title: const Text('বাকি নিয়ন্ত্রণ')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy && dash == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (dash != null) _DashboardCard(dash: dash),
            const SizedBox(height: AppSpacing.sm),
            Wrap(
              spacing: AppSpacing.xs,
              children: [
                for (final l in _lists.entries)
                  ChoiceChip(
                    key: ValueKey('credit-list-${l.key}'),
                    label: Text(l.value),
                    selected: _list == l.key,
                    onSelected: (_) => _pick(l.key),
                  ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: TextField(
                key: const ValueKey('credit-search'),
                controller: _search,
                textInputAction: TextInputAction.search,
                decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search),
                  hintText: 'গ্রাহকের নাম বা কোড',
                  isDense: true,
                ),
                onSubmitted: (_) => _load(listOnly: true),
              ),
            ),
            if (page != null && page.rows.isEmpty)
              EmptyState(
                icon: Icons.verified_outlined,
                title: _list == 'high-risk'
                    ? 'বেশি ঝুঁকির কোনো গ্রাহক নেই'
                    : _list == 'overdue-bills'
                        ? 'মেয়াদ পেরোনো কোনো বিল নেই'
                        : _list == 'over-limit'
                            ? 'কেউ সীমা পার করেননি'
                            : 'কোনো গ্রাহক নেই',
              ),
            if (page != null)
              for (final row in page.rows) _ListRow(list: _list, row: row),
            if (page != null && page.lastPage > 1)
              Padding(
                padding: const EdgeInsets.all(AppSpacing.sm),
                child: Text('প্রথম ${page.rows.length}টা দেখানো হলো, মোট ${page.totalRows}টা — খুঁজে নিন।',
                    style: const TextStyle(fontSize: 12, color: AppColors.onSurfaceMuted)),
              ),
          ],
        ),
      ),
    );
  }
}

class _DashboardCard extends StatelessWidget {
  const _DashboardCard({required this.dash});

  final CreditDashboardFigures dash;

  @override
  Widget build(BuildContext context) => Card(
        key: const ValueKey('credit-dashboard'),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('ব্যবহৃত বাকি', style: TextStyle(color: AppColors.onSurfaceMuted)),
              Text(Money.taka(dash.exposure),
                  key: const ValueKey('credit-exposure'),
                  style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
              if (!dash.on)
                const Text('এই প্রতিষ্ঠানে সীমার যাচাই বন্ধ — অঙ্কগুলো কেবল দেখার জন্য।',
                    style: TextStyle(fontSize: 12, color: AppColors.warning)),
              const SizedBox(height: AppSpacing.xs),
              creditPair('মোট সীমা', Money.taka(dash.limit)),
              creditPair('অবশিষ্ট সীমা', Money.taka(dash.available)),
              creditPair('সীমার ব্যবহার', dash.usedPercent == null ? '—' : '${dash.usedPercent}%'),
              creditPair('মেয়াদ পেরোনো পাওনা', Money.taka(dash.overdue), danger: dash.overdueValue > 0.004),
              creditPair('সীমা পার গ্রাহক', '${dash.overLimit} জন', danger: dash.overLimit > 0),
              creditPair('মেয়াদ পেরোনো বাকিতে বন্ধ', '${dash.overdueStopped} জন', danger: dash.overdueStopped > 0),
              creditPair('বাকি বন্ধ গ্রাহক', '${dash.blocked} জন', danger: dash.blocked > 0),
              creditPair('সীমা বাড়ানোর অনুরোধ (সইয়ের অপেক্ষায়)', '${dash.pendingRequests}টা'),
            ],
          ),
        ),
      );
}

Widget creditPair(String label, String value, {bool danger = false}) => Padding(
      padding: const EdgeInsets.only(top: 3),
      child: Row(
        children: [
          Expanded(child: Text(label, style: const TextStyle(color: AppColors.onSurfaceMuted))),
          Text(value,
              style: TextStyle(fontWeight: FontWeight.w600, color: danger ? AppColors.danger : AppColors.onSurface)),
        ],
      ),
    );

class _ListRow extends StatelessWidget {
  const _ListRow({required this.list, required this.row});

  final String list;
  final CreditListRow row;

  @override
  Widget build(BuildContext context) {
    final lines = <String>[
      if (row.code.isNotEmpty) row.code,
      if (list == 'overdue-bills') ...[
        if ((row.fields['document_no'] ?? '').isNotEmpty) 'বিল ${row.fields['document_no']}',
        'মেয়াদ পেরিয়েছে ${Money.plain(row.fields['days_overdue'])} দিন',
      ],
      if (list == 'high-risk') ...[
        'ঝুঁকি: ${row.fields['level'] ?? ''}',
        if ((row.fields['reasons'] ?? '').isNotEmpty) row.fields['reasons']!,
      ],
      if (list == 'over-limit' || list == 'positions') ...[
        'সীমা ${Money.taka(row.fields['credit_limit'])} · অবশিষ্ট ${Money.taka(row.fields['available'])}',
        if ((row.fields['state'] ?? '').isNotEmpty) row.fields['state']!,
      ],
    ];
    final amount = list == 'overdue-bills' ? row.fields['unpaid'] : row.fields['exposure'];

    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: ListTile(
        key: ValueKey('credit-row-${row.customer}-${row.fields['document_no'] ?? ''}'),
        title: Text(row.name, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(lines.join('\n'), maxLines: 4, overflow: TextOverflow.ellipsis),
        isThreeLine: lines.length > 1,
        trailing: amount == null
            ? null
            : Text(Money.taka(amount), style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.danger)),
        onTap: row.customer.isEmpty ? null : () => context.push('/home/credit/${row.customer}'),
      ),
    );
  }
}

/// ⭐ এক গ্রাহকের বাকির পাতা — সীমা, অস্থায়ী সীমা, ব্যবহৃত, অবশিষ্ট, ঝুঁকির মাত্রা কারণসহ, খোলা বিল, অনুরোধ; আর চাবি থাকলে
/// "সীমা বাড়ানোর অনুরোধ" ও (মালিকের পর্দার একই নিয়মে) "বাকি বন্ধ তুলুন"।
class CustomerCreditScreen extends StatefulWidget {
  const CustomerCreditScreen({super.key, required this.customerId, this.api = const ServerCreditControlApi()});

  final String customerId;
  final CreditControlApi api;

  @override
  State<CustomerCreditScreen> createState() => _CustomerCreditScreenState();
}

class _CustomerCreditScreenState extends State<CustomerCreditScreen> {
  CustomerCredit? _data;
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
      final data = await widget.api.customer(widget.customerId);
      if (!mounted) return;
      setState(() => _data = data);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'গ্রাহকের বাকির পাতা আনা গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _request(CustomerCredit data) async {
    final message = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (context) => LimitRequestSheet(api: widget.api, customer: data),
    );
    if (message == null || !mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    await _load();
  }

  Future<void> _lift(CustomerCredit data) async {
    final message = await showDialog<String>(
      context: context,
      builder: (context) => LiftBlockDialog(api: widget.api, customerId: data.id),
    );
    if (message == null || !mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final d = _data;
    final p = d?.position;

    return Scaffold(
      appBar: AppBar(title: Text(d?.name ?? 'বাকির পাতা')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy && d == null) const LinearProgressIndicator(),
            if (_error != null) BooksError(_error!),
            if (d != null && d.block != null)
              Card(
                key: const ValueKey('credit-blocked'),
                color: AppColors.dangerSurface,
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('বাকি বন্ধ', style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.danger)),
                      Text(d.block!.reason),
                      if (d.block!.bouncedCheque)
                        const Text('চেক ফেরতের বন্ধ — খোলেন কেবল মালিক',
                            style: TextStyle(fontSize: 12, color: AppColors.danger)),
                      if (d.mayLift)
                        Align(
                          alignment: Alignment.centerRight,
                          child: OutlinedButton.icon(
                            key: const ValueKey('credit-lift'),
                            onPressed: () => _lift(d),
                            icon: const Icon(Icons.lock_open_outlined),
                            label: const Text('বাকি বন্ধ তুলুন'),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
            if (d != null && p != null)
              Card(
                key: const ValueKey('credit-position'),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(child: Text(d.code, style: const TextStyle(color: AppColors.onSurfaceMuted))),
                          _Badge(text: CreditLabels.state[p.state] ?? p.stateLabel, danger: p.state != 'active'),
                        ],
                      ),
                      const SizedBox(height: AppSpacing.xs),
                      const Text('অবশিষ্ট সীমা', style: TextStyle(color: AppColors.onSurfaceMuted)),
                      Text(Money.taka(p.available),
                          key: const ValueKey('credit-available'),
                          style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
                      if (p.usedPercent != null)
                        Text('সীমার ${Money.plain(p.usedPercent)}% ব্যবহৃত',
                            style: const TextStyle(fontSize: 12, color: AppColors.onSurfaceMuted)),
                      if (p.stop != null && p.stop!.isNotEmpty)
                        Text(p.stop!, style: const TextStyle(color: AppColors.danger)),
                      const SizedBox(height: AppSpacing.xs),
                      creditPair('সীমা', Money.taka(p.baseLimit)),
                      if (p.tempLimit != null)
                        creditPair('অস্থায়ী সীমা', '${Money.taka(p.tempLimit)} (${dayOf(p.tempUntil)} পর্যন্ত)'),
                      creditPair('চলতি সীমা', Money.taka(p.limit)),
                      if (d.creditDays != null) creditPair('বাকির দিন', '${d.creditDays} দিন'),
                      creditPair('খাতার বকেয়া', Money.taka(p.due)),
                      creditPair('আটকে থাকা', Money.taka(p.held)),
                      creditPair('ক্লিয়ার না হওয়া চেক', Money.taka(p.uncleared)),
                      creditPair('ব্যবহৃত', Money.taka(p.exposure)),
                    ],
                  ),
                ),
              ),
            if (d != null)
              Card(
                key: const ValueKey('credit-risk'),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Expanded(
                              child: Text('ঝুঁকির মাত্রা', style: TextStyle(fontWeight: FontWeight.w700))),
                          _Badge(
                              text: CreditLabels.risk[d.risk.level] ?? d.risk.label,
                              danger: d.risk.level == 'high' || d.risk.level == 'critical'),
                        ],
                      ),
                      if (d.risk.reasons.isEmpty)
                        const Text('কোনো লক্ষণ নেই', style: TextStyle(color: AppColors.onSurfaceMuted)),
                      for (final r in d.risk.reasons) Text('• $r'),
                      const Text('মাত্রা কেবল জানায় — সীমা বা দেয়াল বদলায় না।',
                          style: TextStyle(fontSize: 11.5, color: AppColors.onSurfaceMuted)),
                    ],
                  ),
                ),
              ),
            if (d != null && d.canRequest)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
                child: FilledButton.icon(
                  key: const ValueKey('credit-request'),
                  onPressed: () => _request(d),
                  icon: const Icon(Icons.trending_up),
                  label: const Text('সীমা বাড়ানোর অনুরোধ'),
                ),
              ),
            if (d != null) ...[
              const _SectionTitle('খোলা বিল'),
              if (d.bills.isEmpty)
                const Text('কোনো খোলা বিল নেই', style: TextStyle(color: AppColors.onSurfaceMuted)),
              for (final b in d.bills)
                Card(
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    title: Text(b.no),
                    subtitle: Text(b.daysOverdue > 0
                        ? 'মেয়াদ ${dayOf(b.dueOn)} — ${b.daysOverdue} দিন পেরিয়েছে'
                        : 'মেয়াদ ${dayOf(b.dueOn)}'),
                    trailing: Text(Money.taka(b.unpaid),
                        style: TextStyle(
                            fontWeight: FontWeight.w700,
                            color: b.daysOverdue > 0 ? AppColors.danger : AppColors.onSurface)),
                  ),
                ),
              const _SectionTitle('সীমা বাড়ানোর অনুরোধ'),
              if (d.requests.isEmpty)
                const Text('কোনো অনুরোধ নেই', style: TextStyle(color: AppColors.onSurfaceMuted)),
              for (final r in d.requests)
                Card(
                  margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                  child: ListTile(
                    title: Text('${Money.taka(r.currentLimit)} → ${Money.taka(r.requestedLimit)}'),
                    subtitle: Text([
                      r.kind == 'temporary' ? 'অস্থায়ী, ${dayOf(r.tempUntil)} পর্যন্ত' : 'সাধারণ সীমা',
                      if (r.reason.isNotEmpty) r.reason,
                      [r.requestedBy, dayOf(r.requestedAt)].where((s) => s.isNotEmpty).join(' · '),
                    ].join('\n')),
                    isThreeLine: true,
                    trailing: Text(CreditLabels.request[r.status] ?? r.status,
                        style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

class _SectionTitle extends StatelessWidget {
  const _SectionTitle(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
        child: Text(text, style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.onSurfaceMuted)),
      );
}

class _Badge extends StatelessWidget {
  const _Badge({required this.text, required this.danger});

  final String text;
  final bool danger;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm, vertical: 2),
        decoration: BoxDecoration(
          color: danger ? AppColors.dangerSurface : AppColors.successSurface,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Text(text,
            style: TextStyle(fontWeight: FontWeight.w700, color: danger ? AppColors.danger : AppColors.success)),
      );
}

/// সীমা বাড়ানোর অনুরোধের ঘর — ওয়েবের বাকির পাতার একই ঘর; পাঠালে মালিকের সইয়ে যায়, সইয়ের আগে সীমা বদলায় না।
class LimitRequestSheet extends StatefulWidget {
  const LimitRequestSheet({super.key, required this.api, required this.customer});

  final CreditControlApi api;
  final CustomerCredit customer;

  @override
  State<LimitRequestSheet> createState() => _LimitRequestSheetState();
}

class _LimitRequestSheetState extends State<LimitRequestSheet> {
  String _kind = 'limit';
  DateTime? _until;
  final _amount = TextEditingController();
  final _reason = TextEditingController();
  final _once = OnceKey();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _amount.dispose();
    _reason.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final amount = double.tryParse(_amount.text.trim());
    if (amount == null || amount <= 0) {
      setState(() => _error = 'চাওয়া সীমা লিখুন।');
      return;
    }
    if (_reason.text.trim().isEmpty) {
      setState(() => _error = 'কারণ লিখুন।');
      return;
    }
    if (_kind == 'temporary' && _until == null) {
      setState(() => _error = 'অস্থায়ী সীমার শেষ দিন বাছুন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final message = await _once.send(() => widget.api.requestLimit(
            widget.customer.id,
            kind: _kind,
            amount: _amount.text.trim(),
            until: _until == null ? null : DateFormat('yyyy-MM-dd').format(_until!),
            reason: _reason.text.trim(),
          ));
      if (mounted) Navigator.of(context).pop(message);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'অনুরোধ পাঠানো গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(
            AppSpacing.md, AppSpacing.md, AppSpacing.md, MediaQuery.of(context).viewInsets.bottom + AppSpacing.md),
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('সীমা বাড়ানোর অনুরোধ — ${widget.customer.name}',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
              Text('এখনকার সীমা ${Money.taka(widget.customer.position.baseLimit)}',
                  style: const TextStyle(color: AppColors.onSurfaceMuted)),
              const SizedBox(height: AppSpacing.sm),
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(value: 'limit', label: Text('সাধারণ সীমা')),
                  ButtonSegment(value: 'temporary', label: Text('অস্থায়ী সীমা')),
                ],
                selected: {_kind},
                onSelectionChanged: (s) => setState(() => _kind = s.first),
              ),
              TextField(
                key: const ValueKey('request-amount'),
                controller: _amount,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'চাওয়া সীমা (৳)'),
              ),
              if (_kind == 'temporary')
                TextButton.icon(
                  key: const ValueKey('request-until'),
                  onPressed: () async {
                    final now = DateTime.now();
                    final picked = await showDatePicker(
                      context: context,
                      firstDate: DateTime(now.year, now.month, now.day),
                      lastDate: DateTime(now.year + 2),
                      initialDate: _until ?? DateTime(now.year, now.month, now.day).add(const Duration(days: 7)),
                    );
                    if (picked != null) setState(() => _until = picked);
                  },
                  icon: const Icon(Icons.event_outlined),
                  label: Text(_until == null
                      ? 'শেষ দিন বাছুন'
                      : 'শেষ দিন ${DateFormat('dd/MM/yyyy').format(_until!)}'),
                ),
              TextField(
                key: const ValueKey('request-reason'),
                controller: _reason,
                maxLength: 500,
                maxLines: 2,
                decoration: const InputDecoration(labelText: 'কারণ'),
              ),
              const Text('মালিকের সই পড়লে তবেই বসে; ততক্ষণ পুরনো সীমাই চলে।',
                  style: TextStyle(fontSize: 12, color: AppColors.onSurfaceMuted)),
              if (_error != null)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpacing.xs),
                  child: Text(_error!, key: const ValueKey('request-error'), style: const TextStyle(color: AppColors.danger)),
                ),
              const SizedBox(height: AppSpacing.sm),
              FilledButton(
                key: const ValueKey('request-send'),
                onPressed: _busy ? null : _send,
                child: Text(_busy ? 'পাঠানো হচ্ছে…' : 'সইয়ের জন্য পাঠান'),
              ),
            ],
          ),
        ),
      );
}

/// বাকি বন্ধ তোলা — কারণ বাধ্যতামূলক; চেক ফেরতের বন্ধ সার্ভার কেবল মালিককে খুলতে দেয়
class LiftBlockDialog extends StatefulWidget {
  const LiftBlockDialog({super.key, required this.api, required this.customerId});

  final CreditControlApi api;
  final String customerId;

  @override
  State<LiftBlockDialog> createState() => _LiftBlockDialogState();
}

class _LiftBlockDialogState extends State<LiftBlockDialog> {
  final _reason = TextEditingController();
  final _once = OnceKey();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (_reason.text.trim().isEmpty) {
      setState(() => _error = 'কারণ লিখুন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final message = await _once.send(() => widget.api.liftBlock(widget.customerId, _reason.text.trim()));
      if (mounted) Navigator.of(context).pop(message);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'বাকি বন্ধ তোলা গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('বাকি বন্ধ তুলুন'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              key: const ValueKey('lift-reason'),
              controller: _reason,
              maxLength: 500,
              decoration: const InputDecoration(labelText: 'কারণ'),
            ),
            if (_error != null) Text(_error!, style: const TextStyle(color: AppColors.danger)),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('থাক')),
          FilledButton(
            key: const ValueKey('lift-send'),
            onPressed: _busy ? null : _send,
            child: const Text('তুলুন'),
          ),
        ],
      );
}

class CreditLabels {
  const CreditLabels._();

  static const Map<String, String> state = {
    'active': 'চালু',
    'over_limit': 'সীমা পার',
    'overdue': 'মেয়াদ পেরোনো বাকিতে বন্ধ',
    'blocked': 'বাকি বন্ধ',
    'unchecked': 'সীমার যাচাই বন্ধ',
  };

  static const Map<String, String> risk = {
    'low': 'কম',
    'medium': 'মাঝারি',
    'high': 'বেশি',
    'critical': 'চরম',
  };

  static const Map<String, String> request = {
    'pending': 'সইয়ের অপেক্ষায়',
    'approved': 'সই পড়েছে — বসেছে',
    'rejected': 'না',
    'cancelled': 'ফিরিয়ে নেওয়া',
    'expired': 'মেয়াদ পেরিয়েছে',
    'void': 'মালিকের সই নেই — বসেনি',
  };
}

class CreditDashboardFigures {
  const CreditDashboardFigures({
    this.on = true,
    required this.limit,
    required this.exposure,
    required this.available,
    required this.overdue,
    this.usedPercent,
    this.overLimit = 0,
    this.overdueStopped = 0,
    this.blocked = 0,
    this.pendingRequests = 0,
  });

  final bool on;
  final String limit;
  final String exposure;
  final String available;
  final String overdue;
  final String? usedPercent;
  final int overLimit;
  final int overdueStopped;
  final int blocked;
  final int pendingRequests;

  double get overdueValue => Money.valueOrZero(overdue);

  factory CreditDashboardFigures.fromJson(Map<String, dynamic> body) {
    final t = body['totals'] is Map ? body['totals'] as Map : const {};
    int count(String k) => Money.valueOrZero(t[k]).toInt();
    return CreditDashboardFigures(
      on: body['on'] != false,
      limit: t['limit']?.toString() ?? '0',
      exposure: t['exposure']?.toString() ?? '0',
      available: t['available']?.toString() ?? '0',
      overdue: t['overdue']?.toString() ?? '0',
      usedPercent: t['used_percent']?.toString(),
      overLimit: count('over_limit'),
      overdueStopped: count('overdue_stopped'),
      blocked: count('blocked'),
      pendingRequests: count('pending_requests'),
    );
  }
}

class CreditListRow {
  const CreditListRow({required this.customer, required this.name, this.code = '', this.fields = const {}});

  /// গ্রাহকের public_id — খালি হলে সারি খোলে না
  final String customer;
  final String name;
  final String code;
  final Map<String, String?> fields;
}

class CreditListPage {
  const CreditListPage({required this.rows, this.lastPage = 1, this.totalRows = 0});

  final List<CreditListRow> rows;
  final int lastPage;
  final int totalRows;

  factory CreditListPage.fromJson(Map<String, dynamic> body) => CreditListPage(
        rows: [
          for (final r in (body['rows'] is List ? body['rows'] as List : const []).whereType<Map>())
            CreditListRow(
              customer: r['customer']?.toString() ?? '',
              name: r['name']?.toString() ?? '',
              code: r['code']?.toString() ?? '',
              fields: {
                for (final e in r.entries)
                  if (!const {'customer', 'name', 'code'}.contains(e.key)) e.key.toString(): e.value?.toString(),
              },
            ),
        ],
        lastPage: (body['last_page'] as num?)?.toInt() ?? 1,
        totalRows: (body['total_rows'] as num?)?.toInt() ?? 0,
      );
}

class CreditPosition {
  const CreditPosition({
    required this.limit,
    required this.baseLimit,
    this.tempLimit,
    this.tempUntil,
    required this.due,
    required this.held,
    required this.uncleared,
    required this.exposure,
    required this.available,
    this.usedPercent,
    required this.state,
    this.stateLabel = '',
    this.stop,
  });

  final String limit;
  final String baseLimit;
  final String? tempLimit;
  final String? tempUntil;
  final String due;
  final String held;
  final String uncleared;
  final String exposure;
  final String available;
  final String? usedPercent;
  final String state;
  final String stateLabel;
  final String? stop;
}

class CreditRiskRating {
  const CreditRiskRating({required this.level, this.label = '', this.score = 0, this.reasons = const []});

  final String level;
  final String label;
  final int score;
  final List<String> reasons;
}

class CreditBlock {
  const CreditBlock({required this.reason, this.bouncedCheque = false});

  final String reason;
  final bool bouncedCheque;
}

class CreditBill {
  const CreditBill({required this.no, required this.unpaid, this.dueOn, this.daysOverdue = 0});

  final String no;
  final String unpaid;
  final String? dueOn;
  final int daysOverdue;
}

class CreditRequestRow {
  const CreditRequestRow({
    required this.kind,
    required this.currentLimit,
    required this.requestedLimit,
    this.tempUntil,
    this.reason = '',
    required this.status,
    this.requestedBy = '',
    this.requestedAt,
  });

  final String kind;
  final String currentLimit;
  final String requestedLimit;
  final String? tempUntil;
  final String reason;
  final String status;
  final String requestedBy;
  final String? requestedAt;
}

class CustomerCredit {
  const CustomerCredit({
    required this.id,
    required this.name,
    this.code = '',
    this.creditDays,
    required this.position,
    required this.risk,
    this.block,
    this.bills = const [],
    this.requests = const [],
    this.canRequest = false,
    this.mayLift = false,
  });

  final String id;
  final String name;
  final String code;
  final int? creditDays;
  final CreditPosition position;
  final CreditRiskRating risk;
  final CreditBlock? block;
  final List<CreditBill> bills;
  final List<CreditRequestRow> requests;
  final bool canRequest;
  final bool mayLift;

  factory CustomerCredit.fromJson(Map<String, dynamic> body) {
    final p = body['position'] is Map ? body['position'] as Map : const {};
    final r = body['risk'] is Map ? body['risk'] as Map : const {};
    final b = body['block'];
    List<Map> maps(Object? raw) => raw is List ? raw.whereType<Map>().toList() : const [];

    return CustomerCredit(
      id: body['id']?.toString() ?? '',
      name: body['name']?.toString() ?? '',
      code: body['code']?.toString() ?? '',
      creditDays: (body['credit_days'] as num?)?.toInt(),
      position: CreditPosition(
        limit: p['limit']?.toString() ?? '0',
        baseLimit: p['base_limit']?.toString() ?? '0',
        tempLimit: p['temp_limit']?.toString(),
        tempUntil: p['temp_until']?.toString(),
        due: p['due']?.toString() ?? '0',
        held: p['held']?.toString() ?? '0',
        uncleared: p['uncleared']?.toString() ?? '0',
        exposure: p['exposure']?.toString() ?? '0',
        available: p['available']?.toString() ?? '0',
        usedPercent: p['used_percent']?.toString(),
        state: p['state']?.toString() ?? 'active',
        stateLabel: p['state_label']?.toString() ?? '',
        stop: p['stop']?.toString(),
      ),
      risk: CreditRiskRating(
        level: r['level']?.toString() ?? 'low',
        label: r['label']?.toString() ?? '',
        score: (r['score'] as num?)?.toInt() ?? 0,
        reasons: [for (final x in (r['reasons'] is List ? r['reasons'] as List : const [])) x.toString()],
      ),
      block: b is Map
          ? CreditBlock(reason: b['reason']?.toString() ?? '', bouncedCheque: b['bounced_cheque'] == true)
          : null,
      bills: [
        for (final x in maps(body['bills']))
          CreditBill(
            no: x['no']?.toString() ?? '',
            unpaid: x['unpaid']?.toString() ?? '0',
            dueOn: x['due_on']?.toString(),
            daysOverdue: (x['days_overdue'] as num?)?.toInt() ?? 0,
          ),
      ],
      requests: [
        for (final x in maps(body['requests']))
          CreditRequestRow(
            kind: x['kind']?.toString() ?? 'limit',
            currentLimit: x['current_limit']?.toString() ?? '0',
            requestedLimit: x['requested_limit']?.toString() ?? '0',
            tempUntil: x['temp_until']?.toString(),
            reason: x['reason']?.toString() ?? '',
            status: x['status']?.toString() ?? '',
            requestedBy: x['requested_by']?.toString() ?? '',
            requestedAt: x['requested_at']?.toString(),
          ),
      ],
      canRequest: body['can_request'] == true,
      mayLift: body['may_lift'] == true,
    );
  }
}

abstract class CreditControlApi {
  Future<CreditDashboardFigures> dashboard();

  Future<CreditListPage> list(String list, {String q = '', int page = 1});

  Future<CustomerCredit> customer(String id);

  /// ফেরত — সার্ভারের বাংলা বার্তা ("অনুরোধ মালিকের সইয়ের জন্য পাঠানো হলো …")
  Future<String> requestLimit(String id,
      {required String kind, required String amount, String? until, required String reason});

  Future<String> liftBlock(String id, String reason);
}

class ServerCreditControlApi implements CreditControlApi {
  const ServerCreditControlApi();

  @override
  Future<CreditDashboardFigures> dashboard() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/credit-control/dashboard');
    return CreditDashboardFigures.fromJson(response.data ?? const <String, dynamic>{});
  }

  @override
  Future<CreditListPage> list(String list, {String q = '', int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/credit-control/lists/$list',
        queryParameters: {'page': page, if (q.isNotEmpty) 'q': q});
    return CreditListPage.fromJson(response.data ?? const <String, dynamic>{});
  }

  @override
  Future<CustomerCredit> customer(String id) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/credit-control/customers/$id');
    return CustomerCredit.fromJson(response.data ?? const <String, dynamic>{});
  }

  @override
  Future<String> requestLimit(String id,
      {required String kind, required String amount, String? until, required String reason}) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>('/credit-control/customers/$id/limit-requests',
        data: {'kind': kind, 'amount': amount, if (until != null) 'temp_until': until, 'reason': reason});
    return response.data?['message']?.toString() ?? 'অনুরোধ পাঠানো হলো।';
  }

  @override
  Future<String> liftBlock(String id, String reason) async {
    final response =
        await ApiClient.dio.post<Map<String, dynamic>>('/credit-control/customers/$id/lift-block', data: {'reason': reason});
    return response.data?['message']?.toString() ?? 'বাকি বন্ধ তোলা হলো।';
  }
}
