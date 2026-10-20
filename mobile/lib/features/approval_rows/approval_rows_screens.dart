import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/approvals/approval_rows_api.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../books/books_widgets.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ সইয়ের ভার — আমি যাঁদের দিয়েছি আর আমার কাছে যাঁদের ভার; নতুন ভার আর ফেরানো (মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage
/// app e bujajayna"*)। ⓘ নিয়ম সব সার্ভারের — নিজেকে নয়, গতকাল থেকে নয়, কেবল এই কোম্পানির মানুষ; পর্দা কেবল উত্তরটা বলে।
class DelegationScreen extends StatefulWidget {
  const DelegationScreen({super.key, this.api = const ServerApprovalRowsApi(), this.today});

  final ApprovalRowsApi api;

  /// পরীক্ষার জন্য — না দিলে আজ
  final DateTime? today;

  @override
  State<DelegationScreen> createState() => _DelegationScreenState();
}

class _DelegationScreenState extends State<DelegationScreen> {
  DelegationSetup? _setup;
  String? _error;
  bool _busy = false;

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
      final setup = await widget.api.delegations();
      if (mounted) setState(() => _setup = setup);
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'ভারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ফোনের ভার দেওয়া এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _revoke(Delegation d) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('ভার ফেরাবেন?'),
        content: Text('${d.person} আর আপনার হয়ে সই দিতে পারবেন না।'),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('না')),
          FilledButton(
              key: const ValueKey('delegation-revoke-yes'),
              onPressed: () => Navigator.of(context).pop(true),
              child: const Text('ফেরান')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await widget.api.revoke(d.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('ভার ফেরানো হলো।')));
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(errorMessageFor(e, fallback: 'ভার ফেরানো গেল না।'))));
      }
    }
  }

  Future<void> _new() async {
    final setup = _setup;
    if (setup == null) return;
    final made = await Navigator.of(context).push<bool>(MaterialPageRoute(
        builder: (_) => NewDelegationScreen(setup: setup, api: widget.api, today: widget.today)));
    if (made == true && mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final s = _setup;

    return Scaffold(
      appBar: const AbosScreenBar(title: 'সইয়ের ভার'),
      floatingActionButton: s == null
          ? null
          : FloatingActionButton.extended(
              key: const ValueKey('delegation-new'),
              onPressed: _new,
              icon: const Icon(Icons.add),
              label: const Text('নতুন ভার'),
            ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: 'আনা গেল না', message: _error)
            else if (s != null) ...[
              _Heading('আমার কাছে যাঁদের ভার', s.held.length),
              if (s.held.isEmpty)
                Padding(
                    padding: const EdgeInsets.only(bottom: AppSpacing.sm),
                    child: Text('এখন কেউ আপনাকে ভার দেননি।', style: TextStyle(color: context.palette.onSurfaceMuted))),
              for (final d in s.held) _DelegationCard(d: d),
              const SizedBox(height: AppSpacing.sm),
              _Heading('আমি যাঁদের দিয়েছি', s.given.length),
              if (s.given.isEmpty && s.held.isEmpty)
                const EmptyState(
                    icon: Icons.handshake_outlined,
                    title: 'কোনো ভার নেই',
                    message: 'ছুটিতে গেলে "নতুন ভার" চেপে কাউকে আপনার হয়ে সই দিতে দিন।')
              else if (s.given.isEmpty)
                Text('আপনি কাউকে ভার দেননি।', style: TextStyle(color: context.palette.onSurfaceMuted)),
              for (final d in s.given)
                _DelegationCard(d: d, onRevoke: d.revoked ? null : () => _revoke(d)),
            ],
            const SizedBox(height: 80),
          ],
        ),
      ),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.label, this.count);

  final String label;
  final int count;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: Text('$label · $count টি', style: const TextStyle(fontWeight: FontWeight.w700)),
      );
}

class _DelegationCard extends StatelessWidget {
  const _DelegationCard({required this.d, this.onRevoke});

  final Delegation d;
  final VoidCallback? onRevoke;

  @override
  Widget build(BuildContext context) {
    final state = d.revoked ? 'ফেরানো' : (d.active ? 'চালু' : 'সামনে বা শেষ');

    return Card(
      key: ValueKey('delegation-${d.id}'),
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: ListTile(
        title: Text(d.person, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${d.startsOn} থেকে ${d.endsOn} · $state',
              style: TextStyle(color: d.active ? context.palette.success : context.palette.onSurfaceMuted)),
          Text(d.modules.isEmpty ? 'সব কাজে' : d.modules.join(', ')),
          if (d.reason.isNotEmpty) Text(d.reason, style: const TextStyle(fontSize: 13)),
        ]),
        trailing: onRevoke == null
            ? null
            : TextButton(key: ValueKey('delegation-revoke-${d.id}'), onPressed: onRevoke, child: const Text('ফেরান')),
      ),
    );
  }
}

/// নতুন ভার — কাকে, কবে থেকে কবে, কোন কাজে, কেন।
class NewDelegationScreen extends StatefulWidget {
  const NewDelegationScreen({super.key, required this.setup, required this.api, this.today});

  final DelegationSetup setup;
  final ApprovalRowsApi api;
  final DateTime? today;

  @override
  State<NewDelegationScreen> createState() => _NewDelegationScreenState();
}

class _NewDelegationScreenState extends State<NewDelegationScreen> {
  final _once = OnceKey();
  final _reason = TextEditingController();
  String? _to;
  late DateTime _from;
  late DateTime _until;
  final Set<String> _modules = {};
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final now = widget.today ?? DateTime.now();
    _from = DateTime(now.year, now.month, now.day);
    _until = _from.add(const Duration(days: 6));
    _reason.addListener(_typed);
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  bool get _written => _to != null || _modules.isNotEmpty || _reason.text.trim().isNotEmpty;

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _pick(bool start) async {
    final first = widget.today ?? DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: start ? _from : _until,
      firstDate: DateTime(first.year, first.month, first.day),
      lastDate: DateTime(first.year + 2),
    );
    if (picked == null) return;
    setState(() {
      if (start) {
        _from = picked;
        if (_until.isBefore(_from)) _until = _from;
      } else {
        _until = picked;
      }
    });
  }

  Future<void> _save() async {
    if (_to == null) {
      setState(() => _error = 'কাকে ভার দেবেন, বাছুন।');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await _once.send(() => widget.api.grant(
            to: _to!,
            startsOn: isoOf(_from),
            endsOn: isoOf(_until),
            modules: _modules.toList(),
            reason: _reason.text,
          ));
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('ভার দেওয়া হলো।')));
      Navigator.of(context).pop(true);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'ভার দেওয়া গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⭐ কাকে, কোন কাজ বা কারণ বাছা থাকলে "পিছনে" জিজ্ঞেস করে — নতুন ভারের লেখা এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _written && !_busy, child: _screen(context));

  Widget _screen(BuildContext context) {
    return Scaffold(
      appBar: const AbosScreenBar(title: 'নতুন ভার'),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          DropdownButtonFormField<String>(
            key: const ValueKey('delegation-to'),
            initialValue: _to,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'কার কাছে'),
            items: [
              for (final (id, name) in widget.setup.people) DropdownMenuItem(value: id, child: Text(name)),
            ],
            onChanged: (v) => setState(() => _to = v),
          ),
          const SizedBox(height: AppSpacing.sm),
          Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                key: const ValueKey('delegation-from'),
                icon: const Icon(Icons.event, size: 18),
                label: Text('শুরু ${dayOf(isoOf(_from))}'),
                onPressed: () => _pick(true),
              ),
            ),
            const SizedBox(width: AppSpacing.sm),
            Expanded(
              child: OutlinedButton.icon(
                key: const ValueKey('delegation-until'),
                icon: const Icon(Icons.event, size: 18),
                label: Text('শেষ ${dayOf(isoOf(_until))}'),
                onPressed: () => _pick(false),
              ),
            ),
          ]),
          const SizedBox(height: AppSpacing.sm),
          Text('কোন কাজে (কিছু না বাছলে সব কাজে)', style: TextStyle(color: context.palette.onSurfaceMuted)),
          Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
            for (final (code, label) in widget.setup.modules)
              FilterChip(
                key: ValueKey('delegation-module-$code'),
                label: Text(label),
                selected: _modules.contains(code),
                onSelected: (on) => setState(() => on ? _modules.add(code) : _modules.remove(code)),
              ),
          ]),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('delegation-reason'),
            controller: _reason,
            maxLength: 255,
            decoration: const InputDecoration(labelText: 'কারণ (ঐচ্ছিক)', hintText: 'যেমন: ছুটিতে'),
          ),
          if (_error != null)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: Text(_error!, style: TextStyle(color: context.palette.danger)),
            ),
          FilledButton(
            key: const ValueKey('delegation-save'),
            onPressed: _busy ? null : _save,
            child: Text(_busy ? 'দেওয়া হচ্ছে…' : 'ভার দিন'),
          ),
        ],
      ),
    );
  }
}

/// কোন সারি — দলিলের অনুমোদনের সারি, না সই কেন্দ্র
enum PaperQueueKind { documents, signatures }

/// ⭐ দলিলের অনুমোদনের সারি, বা সই কেন্দ্র — ঘর বেছে সারি; আমার সইয়ের অপেক্ষার সারিতে সই/ফেরত, ফোনের অনুমোদন-দরজায়।
class PaperQueueScreen extends StatefulWidget {
  const PaperQueueScreen({
    super.key,
    required this.kind,
    this.api = const ServerApprovalRowsApi(),
    this.approve,
    this.reject,
  });

  final PaperQueueKind kind;
  final ApprovalRowsApi api;

  /// পরীক্ষার জন্য — না দিলে ফোনের অনুমোদন-দরজা
  final Future<void> Function(String id)? approve;
  final Future<void> Function(String id, String reason)? reject;

  @override
  State<PaperQueueScreen> createState() => _PaperQueueScreenState();
}

class _PaperQueueScreenState extends State<PaperQueueScreen> {
  late String _tab = _tabs.keys.first;
  final List<PaperQueueRow> _rows = [];
  PaperQueuePage? _page;
  String? _error;
  bool _busy = false;

  /// ফেরতের কারণ — পর্দার সাথে বাঁচে; ডায়ালগ বন্ধ হওয়ার মাঝপথে মুছলে ফ্লাটার ভেঙে পড়ত
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Map<String, String> get _tabs => widget.kind == PaperQueueKind.documents
      ? const {'to_sign': 'আমার সইয়ের অপেক্ষায়', 'mine': 'আমার পাঠানো'}
      : const {'to_sign': 'আমার সইয়ের অপেক্ষায়', 'asked': 'আমার চাওয়া', 'signed': 'সইয়ের ইতিহাস'};

  String get _title => widget.kind == PaperQueueKind.documents ? 'দলিলের অনুমোদন' : 'সই কেন্দ্র';

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
      final page = more ? (_page?.nextPage ?? 1) : 1;
      final got = widget.kind == PaperQueueKind.documents
          ? await widget.api.documentQueue(mine: _tab == 'mine', page: page)
          : await widget.api.signatures(tab: _tab, page: page);
      if (!mounted) return;
      setState(() {
        if (!more) _rows.clear();
        _rows.addAll(got.rows);
        _page = got;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে ফোনের $_title এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sign(PaperQueueRow row) async {
    try {
      await (widget.approve ?? (id) => ApprovalsApi.approve(id))(row.id);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('সই দেওয়া হলো।')));
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessageFor(e, fallback: 'সই দেওয়া গেল না।'))));
      }
    }
  }

  Future<void> _refuse(PaperQueueRow row) async {
    _reason.clear();
    final text = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('কেন ফেরত দিচ্ছেন?'),
        content: TextField(key: const ValueKey('queue-reason'), controller: _reason, autofocus: true, maxLength: 255),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('বাদ দিন')),
          FilledButton(
              key: const ValueKey('queue-reason-send'),
              onPressed: () => Navigator.of(context).pop(_reason.text.trim()),
              child: const Text('ফেরত দিন')),
        ],
      ),
    );
    if (text == null || !mounted) return;
    if (text.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('ফেরতের কারণ লিখতে হবে।')));
      return;
    }
    try {
      await (widget.reject ?? (id, r) => ApprovalsApi.reject(id, remarks: r))(row.id, text);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('ফেরত দেওয়া হলো।')));
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(errorMessageFor(e, fallback: 'ফেরত দেওয়া গেল না।'))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final signed = _tab == 'signed';

    return Scaffold(
      appBar: AbosScreenBar(title: _title),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            Wrap(spacing: AppSpacing.xs, children: [
              for (final t in _tabs.entries)
                ChoiceChip(
                  key: ValueKey('queue-tab-${t.key}'),
                  label: Text(t.value),
                  selected: _tab == t.key,
                  onSelected: (_) {
                    setState(() => _tab = t.key);
                    _load();
                  },
                ),
            ]),
            const SizedBox(height: AppSpacing.sm),
            if (_page != null && _rows.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Text('মোট ${_page!.count} টি',
                    key: const ValueKey('queue-count'), style: const TextStyle(fontWeight: FontWeight.w600)),
              ),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: 'আনা গেল না', message: _error)
            else if (_page != null && _rows.isEmpty && !_busy)
              EmptyState(icon: Icons.task_alt_outlined, title: signed ? 'এখনো কোনো সই নেই' : 'কিছু অপেক্ষায় নেই'),
            for (final row in _rows)
              Card(
                margin: const EdgeInsets.only(bottom: AppSpacing.xs),
                child: Padding(
                  padding: const EdgeInsets.all(AppSpacing.sm),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text([if (row.documentNo.isNotEmpty) row.documentNo, row.name].join(' · '),
                        style: const TextStyle(fontWeight: FontWeight.w600)),
                    if (signed) ...[
                      Text('সই: ${row.signer} · ${row.signedAt}'),
                      Text([if (row.version.isNotEmpty) row.version, if (row.hash.isNotEmpty) 'ছাপ ${row.hash}…'].join(' · '),
                          style: TextStyle(fontSize: 12, color: context.palette.onSurfaceMuted)),
                    ] else ...[
                      Text('চেয়েছেন: ${row.requester}'),
                      if (row.dueAt.isNotEmpty)
                        Text('শেষ সময়: ${row.dueAt}',
                            style: TextStyle(color: row.overdue ? context.palette.danger : context.palette.onSurfaceMuted)),
                      if (row.canDecide)
                        Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                          TextButton(
                              key: ValueKey('queue-refuse-${row.id}'),
                              onPressed: () => _refuse(row),
                              child: const Text('ফেরত')),
                          const SizedBox(width: AppSpacing.xs),
                          FilledButton(
                              key: ValueKey('queue-sign-${row.id}'), onPressed: () => _sign(row), child: const Text('সই দিন')),
                        ]),
                    ],
                  ]),
                ),
              ),
            if (_page?.nextPage != null)
              OutlinedButton(
                  key: const ValueKey('queue-more'),
                  onPressed: _busy ? null : () => _load(more: true),
                  child: const Text('আরও')),
          ],
        ),
      ),
    );
  }
}
