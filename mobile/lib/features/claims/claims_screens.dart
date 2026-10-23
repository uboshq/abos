import 'package:flutter/material.dart';

import '../../core/navigation/recent_papers.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/api_client/once_key.dart';
import '../../core/hr/claims_api.dart';
import '../../core/records/money.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/status/paper_state.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../home/nav_bottom_bar.dart' show ActionBottomBar, BarFab;
import '../books/books_widgets.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../../core/permissions/camera_shot.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

/// ⭐ খরচের দাবি আর অগ্রিম — কর্মীর নিজের (টাকা-আসা-যাওয়ার পরিকল্পনা ১৩, ৭ অক্টোবর ২০২৬; সার্ভার f727b846)।
///
/// <p>⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস, কোনো টেবিল নয়। পাঠানো মানে টাকা পাওয়া নয় — সই আর ক্যাশিয়ারের পরে।
Color stateColour(BuildContext context, String status) => switch (status) {
      'paid' => context.palette.success,
      'rejected' => context.palette.danger,
      _ => context.palette.pending,
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
            fallback: context.l10n.claimsLoadFailed,
            whenAbsent: context.l10n.claimsServerMissing));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final page = _page;
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.claimsListTitle),
      bottomNavigationBar: ActionBottomBar(
          action: BarFab(
        key: const ValueKey('claim-new'),
        onPressed: () async {
          final sent = await Navigator.of(context).push<Claim>(MaterialPageRoute(
              builder: (_) => NewClaimScreen(api: widget.api, picker: widget.picker)));
          if (sent != null && mounted) await _load();
        },
        label: context.l10n.claimsNew,
      )),
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
                  label: context.l10n.claimsOpenAdvance,
                  value: Money.taka(page!.openAdvance!)),
            if (page != null && page.claims.isEmpty)
              Padding(
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Text(context.l10n.claimsEmpty, textAlign: TextAlign.center),
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
                      // ⭐ অবস্থা — অ্যাপের এক চিপ (নকশা ৩, অংশ ৫); দাবির ধরনে "অনুমোদিত · টাকার অপেক্ষায়"
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 2),
                        child: AppStatusChip.forServer(c.status,
                            key: ValueKey('claim-state-${c.id}'),
                            fallbackLabel: c.stateLabel,
                            fallbackColour: stateColour(context, c.status),
                            kind: PaperKind.claim,
                            level: c.approvalLevel,
                            levels: c.approvalLevels,
                            dense: true),
                      ),
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
      // ⭐ খোলা কাগজ "সাম্প্রতিক"-এ (মালিক, ১২ অক্টোবর ২০২৬)
      RecentPapers.note(kind: 'claim', id: widget.id, number: c.number);
    }, onError: (Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.claimsOneFailed));
    });
  }

  @override
  Widget build(BuildContext context) {
    final c = _claim;
    return Scaffold(
      appBar: AbosScreenBar(title: c?.number ?? context.l10n.claimsOneTitle),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          if (_error != null) BooksError(_error!),
          if (c == null && _error == null) const LinearProgressIndicator(),
          if (c != null) ...[
            FactRow(context.l10n.claimsKind, c.kindLabel),
            PaperStateFact(
                chip: AppStatusChip.forServer(c.status,
                    key: const ValueKey('claim-state'),
                    fallbackLabel: c.stateLabel,
                    fallbackColour: stateColour(context, c.status),
                    kind: PaperKind.claim,
                    level: c.approvalLevel,
                    levels: c.approvalLevels)),
            FactRow(context.l10n.claimsAmount, Money.taka(c.amount)),
            if (c.head != null) FactRow(context.l10n.claimsHead, c.head!),
            if (c.spentOn != null) FactRow(context.l10n.claimsSpentOn, dayOf(c.spentOn)),
            if (!c.isAdvance && c.fromAdvance > 0) FactRow(context.l10n.claimsFromAdvance, Money.taka(c.fromAdvance)),
            if (!c.isAdvance && (c.status == 'approved' || c.status == 'paid'))
              FactRow(context.l10n.claimsInCash, Money.taka(c.cash)),
            FactRow(context.l10n.claimsReason, c.reason),
            if (c.submittedAt != null) FactRow(context.l10n.claimsSentOn, dayOf(c.submittedAt)),
            if (c.decidedAt != null) FactRow(c.status == 'rejected' ? context.l10n.claimsRejectedOn : context.l10n.claimsApprovedOn, dayOf(c.decidedAt)),
            if (c.paidAt != null) FactRow(context.l10n.claimsPaidOn, dayOf(c.paidAt)),
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
  /// ⭐ এই কাজের চাবি — দুবার চাপলে বা উত্তর হারালে একবারই বসে ([[OnceKey]], অডিট ফোন ⚠️১২)
  final _once = OnceKey();

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
    _amount.addListener(_typed);
    _reason.addListener(_typed);
    widget.api.heads().then((h) {
      if (mounted) setState(() => _heads = h);
    }, onError: (Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.claimsHeadsFailed));
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
        : (await takeCameraShot(context, imageQuality: 70, maxWidth: 1800))?.path;
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
        ? context.l10n.claimsAmountShape
        : _kind == 'expense' && _head == null
            ? context.l10n.claimsChooseHead
            : reason.isEmpty
                ? context.l10n.claimsWriteReason
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
      final claim = await _once.send(() => widget.api.send(ClaimDraft(
        kind: _kind,
        amount: amount,
        reason: reason,
        headId: _head,
        spentOn: _spentOn,
        receiptPath: _receipt,
      )));
      if (mounted) Navigator.of(context).pop(claim);
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.claimsSendFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  bool get _dirty =>
      !_busy && (_amount.text.trim().isNotEmpty || _reason.text.trim().isNotEmpty || _head != null || _receipt != null);

  // ⭐ টাকা, কারণ, খাত বা রসিদ লেখা থাকলে "পিছনে" জিজ্ঞেস করে — লেখা দাবি এক চাপে হারাত (মোবাইলের নিরীক্ষা, ১৯ অক্টোবর ২০২৬; [[UnsavedGuard]])
  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _dirty, child: _screen(context));

  Widget _screen(BuildContext context) {
    final expense = _kind == 'expense';
    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.claimsNew),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Text(context.l10n.claimsHowItWorks,
              style: TextStyle(color: context.palette.onSurfaceMuted)),
          const SizedBox(height: AppSpacing.sm),
          if (_busy) const LinearProgressIndicator(),
          if (_error != null)
            Card(
              key: const ValueKey('claim-error'),
              color: context.palette.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.sm),
                child: Text(_error!, style: TextStyle(color: context.palette.danger)),
              ),
            ),
          SegmentedButton<String>(
            key: const ValueKey('claim-kind'),
            segments: [
              ButtonSegment(value: 'expense', label: Text(context.l10n.claimsKindSpent)),
              ButtonSegment(value: 'advance', label: Text(context.l10n.claimsKindAdvanceWanted)),
            ],
            selected: {_kind},
            onSelectionChanged: (s) => setState(() => _kind = s.first),
          ),
          const SizedBox(height: AppSpacing.sm),
          TextField(
            key: const ValueKey('claim-amount'),
            controller: _amount,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            decoration: InputDecoration(labelText: context.l10n.claimsAmount),
          ),
          if (expense) ...[
            DropdownButtonFormField<int>(
              key: const ValueKey('claim-head'),
              initialValue: _head,
              isExpanded: true,
              decoration: InputDecoration(labelText: context.l10n.claimsHead),
              items: [
                for (final h in _heads)
                  DropdownMenuItem(value: h.id, child: Text(h.name, overflow: TextOverflow.ellipsis)),
              ],
              onChanged: (v) => setState(() => _head = v),
            ),
            ListTile(
              contentPadding: EdgeInsets.zero,
              title: Text(context.l10n.claimsSpentOn),
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
            decoration: InputDecoration(labelText: context.l10n.claimsReason),
          ),
          if (expense)
            OutlinedButton.icon(
              key: const ValueKey('claim-receipt'),
              onPressed: _busy ? null : _pickReceipt,
              icon: Icon(_receipt == null ? Icons.photo_camera_outlined : Icons.check_circle,
                  color: _receipt == null ? null : context.palette.success),
              label: Text(_receipt == null ? context.l10n.claimsReceiptTake : context.l10n.claimsReceiptTaken),
            ),
          const SizedBox(height: AppSpacing.md),
          FilledButton.icon(
            key: const ValueKey('claim-send'),
            onPressed: _busy ? null : _send,
            icon: const Icon(Icons.send_outlined),
            label: Text(context.l10n.claimsSend),
          ),
        ],
      ),
    );
  }
}
