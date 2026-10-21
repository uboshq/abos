import 'dart:async';

import 'package:flutter/material.dart';

import '../printing/document_actions_sheet.dart';
import 'final_reject_confirm.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/records/approval_record.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';
import '../../core/widgets/number_with_party.dart';
import '../../core/widgets/day_part.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/locale/l10n.dart';

/// What is waiting for this person's decision — docs/Contract §৫.
///
/// <p><b>Why this screen is the first thing on a phone that is not sync.</b>
/// Everything else in this app helps someone in a shop. This one helps
/// someone who is *not at their desk*, which is the only place the rest of
/// ABOS can be used. A bill waits for an owner who is travelling, and the
/// waiting is the cost.
///
/// <p><b>Online only, deliberately.</b> An approval moves a document forward —
/// stock shifts, money is released — and two phones out of coverage would
/// each approve the same bill once. Same reasoning as the owner's decision
/// that only orders may be written offline.
class ApprovalInboxScreen extends StatefulWidget {
  const ApprovalInboxScreen({
    super.key,
    this.loadPending,
    this.approve,
    this.reject,
    this.sendBack,
    this.loadSheet,
    this.loadMore,
  });

  /// Seams, so this screen can be driven in a test without a server.
  ///
  /// <p>Not ceremony: the entire class of bug found on 12 September was
  /// screens that were never once run against a payload. A screen that cannot
  /// be run in a test is a screen whose first run is on somebody's phone.
  final Future<ApprovalPage> Function()? loadPending;
  final Future<void> Function(String id, String? remarks)? approve;
  final Future<void> Function(String id, String remarks)? reject;

  /// সংশোধনে ফেরত — না দিলে সার্ভার ([[ApprovalsApi.sendBack]])
  final Future<void> Function(String id, String remarks)? sendBack;

  /// সইয়ের আগে বিস্তারিত — না দিলে সার্ভার ([[ApprovalsApi.sheet]])
  final Future<ApprovalSheet> Function(String id)? loadSheet;

  /// পরের পাতা — না দিলে সার্ভার ([[ApprovalsApi.pending]] `cursor`)
  final Future<ApprovalPage> Function(String cursor)? loadMore;

  @override
  State<ApprovalInboxScreen> createState() => _ApprovalInboxScreenState();
}

class _ApprovalInboxScreenState extends State<ApprovalInboxScreen> {
  List<ApprovalRecord>? _rows;
  // ⛔ পরের পাতার চাবি — আগে কখনো পড়া হত না, তাই ৫০টার পরের কাগজ (সার্ভার পুরনোটা আগে দেয়: সবচেয়ে নতুনগুলোই) ফোনে
  // কোনোদিন আসত না (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
  String? _next;
  String? _error;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<ApprovalPage> _fetch() =>
      (widget.loadPending ?? ApprovalsApi.pending)();

  Future<void> _load() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await _fetch();
      if (!mounted) return;
      setState(() {
        _rows = page.rows;
        _next = page.nextCursor;
      });
    } catch (error) {
      if (!mounted) return;
      // An empty inbox and an unreachable server are opposite facts, and a
      // screen that shows "কিছু অপেক্ষা করছে না" for both would tell someone
      // their work is done when it is merely invisible.
      // ⚠️ And a server without the route is a third fact again. 404 here
      // cannot mean "no such approval" — this endpoint names no row — so it
      // means this build is newer than the server it is talking to, which on
      // a fleet updated by hand from a link is an ordinary Tuesday.
      setState(() => _error = errorMessageFor(error,
          fallback: context.l10n.approvalsInboxLoadFailed, whenAbsent: context.l10n.approvalsInboxServerMissing));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Takes a row off the list without a round trip.
  ///
  /// <p>Used for the decisions that succeeded *and* for the ones that failed
  /// because somebody else got there first — docs/Contract §৫ rule খ. Both
  /// mean the same thing to this list: it is no longer waiting for you.
  ///
  /// <p>Builds a new list rather than mutating the one it was handed. The
  /// first version called `removeWhere` on it and worked only because
  /// `ApprovalsApi.pending` happens to end in `.toList()`; handed an
  /// unmodifiable list it threw. A screen must not depend on a promise its
  /// caller never made — a test caught this, which is the argument for the
  /// seams in the first place.
  void _drop(String id) => setState(() {
        final rows = _rows;
        if (rows == null) return;
        _rows = rows.where((row) => row.id != id).toList();
      });

  Future<void> _decide(
    ApprovalRecord approval, {
    required _Decision decision,
    String? remarks,
  }) async {
    setState(() => _busy = true);
    try {
      switch (decision) {
        case _Decision.approve:
          await (widget.approve ??
              (String id, String? note) =>
                  ApprovalsApi.approve(id, remarks: note, seenAmount: approval.rawAmount))(approval.id, remarks);
        // ⭐ সংশোধনে ফেরত — send-back; প্রত্যাখ্যান নয় (০.৪.৪২-এর ভুল, ২১ অক্টোবর ২০২৬)
        case _Decision.sendBack:
          await (widget.sendBack ?? (String id, String note) => ApprovalsApi.sendBack(id, remarks: note))(
              approval.id, remarks!);
        case _Decision.reject:
          await (widget.reject ?? (String id, String note) => ApprovalsApi.reject(id, remarks: note))(
              approval.id, remarks!);
      }
      if (!mounted) return;
      _drop(approval.id);
      _say(switch (decision) {
        _Decision.approve => context.l10n.approvalsInboxSigned,
        _Decision.sendBack => context.l10n.approvalsInboxSentBack,
        _Decision.reject => context.l10n.approvalsInboxRejected,
      });
    } catch (error) {
      if (!mounted) return;
      switch (approvalFailureOf(error)) {
        case ApprovalFailure.alreadyDecided:
          // Not an error. Two people can be looking at the same inbox, and
          // the second to tap is seeing that a colleague got there first.
          _drop(approval.id);
          _say(context.l10n.approvalsInboxAlreadyDecided);
        case ApprovalFailure.notYours:
          _say(context.l10n.approvalsInboxNotYours);
        case ApprovalFailure.amountChanged:
          // ⛔ কার্ড খোলার পরে অঙ্ক বদলেছে — সই পড়েনি; তালিকা আবার, নতুন অঙ্ক দেখে আবার সই
          _say(context.l10n.approvalsInboxAmountChanged);
          unawaited(_load());
        case ApprovalFailure.network:
          _say(context.l10n.approvalsInboxOffline);
        case ApprovalFailure.other:
          _say(errorMessageFor(error, fallback: context.l10n.approvalsInboxActionFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _more() async {
    final cursor = _next;
    if (cursor == null) return;
    setState(() => _busy = true);
    try {
      final page = await (widget.loadMore ?? (String c) => ApprovalsApi.pending(cursor: c))(cursor);
      if (!mounted) return;
      setState(() {
        final seen = {for (final r in _rows ?? const <ApprovalRecord>[]) r.id};
        _rows = [...?_rows, ...page.rows.where((r) => !seen.contains(r.id))];
        _next = page.nextCursor;
      });
    } catch (error) {
      if (mounted) _say(errorMessageFor(error, fallback: context.l10n.approvalsInboxMoreFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _say(String message) => ScaffoldMessenger.of(context)
      .showSnackBar(SnackBar(content: Text(message)));

  Future<void> _confirmApprove(ApprovalRecord approval) async {
    final remarks = await showDialog<String>(
      context: context,
      builder: (context) => _RemarksDialog(
        title: context.l10n.approvalsInboxSignTitle,
        subtitle: _describe(approval),
        confirmLabel: context.l10n.approvalsInboxSignButton,
        // Saying yes needs no explanation; saying no does. The asymmetry is
        // the server's own (ApprovalEngine.approve takes ?string remarks,
        // reject takes string), kept rather than smoothed over.
        remarksRequired: false,
      ),
    );
    if (remarks == null || !mounted) return;
    await _decide(approval, decision: _Decision.approve, remarks: remarks);
  }

  /// ⭐ সংশোধনে ফেরত — কারণ বাধ্যতামূলক, কাগজ লেখকের কাছে ফেরে
  Future<void> _confirmSendBack(ApprovalRecord approval) async {
    final remarks = await showDialog<String>(
      context: context,
      builder: (context) => _RemarksDialog(
        title: context.l10n.approvalsInboxSendBackTitle,
        subtitle: _describe(approval),
        confirmLabel: context.l10n.approvalsInboxSendBackButton,
        remarksRequired: true,
      ),
    );
    if (remarks == null || !mounted) return;
    await _decide(approval, decision: _Decision.sendBack, remarks: remarks);
  }

  /// ⭐ প্রত্যাখ্যান — কারণ বাধ্যতামূলক, তারপর আলাদা শেষ প্রশ্ন ("চূড়ান্ত — আর ফেরানো যাবে না"); না বললে কিছুই যায় না
  Future<void> _confirmReject(ApprovalRecord approval) async {
    final remarks = await showDialog<String>(
      context: context,
      builder: (context) => _RemarksDialog(
        title: context.l10n.approvalsInboxRejectTitle,
        subtitle: _describe(approval),
        confirmLabel: context.l10n.approvalsInboxRejectButton,
        destructive: true,
        remarksRequired: true,
      ),
    );
    if (remarks == null || !mounted) return;
    if (!await confirmFinalReject(context, what: _describe(approval)) || !mounted) return;
    await _decide(approval, decision: _Decision.reject, remarks: remarks);
  }

  // ⓘ সইয়ের প্রশ্নে অঙ্কও — কোন অঙ্কে সই, তা দেখে
  String _describe(ApprovalRecord approval) => [
        approval.documentTypeLabel,
        if (approval.documentNo != null) approval.documentNo!,
        if (approval.amountText != null) approval.amountText!,
      ].join(' · ');

  @override
  Widget build(BuildContext context) {
    final rows = _rows;

    return Scaffold(
      appBar: AbosScreenBar(title: context.l10n.approvalsInboxTitle),
      body: Column(
        children: [
          if (_busy) const LinearProgressIndicator(),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _load,
              // Always a scrollable, so the pull gesture works on every state
              // — including the two that ask for it. Confirmed on a device in
              // September: an EmptyState outside a scrollable made the one
              // instruction on screen do nothing.
              child: ListView(
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  if (_error != null)
                    EmptyState(
                      icon: Icons.cloud_off_outlined,
                      title: context.l10n.approvalsInboxLoadFailedTitle,
                      message: _error,
                    )
                  else if (rows == null)
                    const SizedBox(height: 200)
                  else if (rows.isEmpty)
                    EmptyState(
                      icon: Icons.inbox_outlined,
                      title: context.l10n.approvalsInboxEmptyTitle,
                      // Not an error screen — an empty inbox is good news.
                      message: context.l10n.approvalsInboxEmptyMessage,
                    )
                  else
                    ...rows.map((approval) => _ApprovalCard(
                          approval: approval,
                          onDetails: () => _ApprovalDetails.show(context, approval,
                              (widget.loadSheet ?? ApprovalsApi.sheet)(approval.id)),
                          onApprove:
                              _busy ? null : () => _confirmApprove(approval),
                          onReject:
                              _busy ? null : () => _confirmReject(approval),
                          onSendBack: _busy ? null : () => _confirmSendBack(approval),
                        )),
                  if (_error == null && rows != null && _next != null)
                    OutlinedButton(
                      key: const ValueKey('approvals-more'),
                      onPressed: _busy ? null : _more,
                      child: Text(context.l10n.approvalsInboxShowMore),
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ApprovalCard extends StatelessWidget {
  const _ApprovalCard({
    required this.approval,
    required this.onApprove,
    required this.onReject,
    required this.onSendBack,
    required this.onDetails,
  });

  final ApprovalRecord approval;
  final VoidCallback onDetails;
  final VoidCallback? onApprove;
  final VoidCallback? onReject;
  final VoidCallback? onSendBack;

  @override
  Widget build(BuildContext context) {
    final requestedAt = approval.requestedAt;
    final hasPaper =
        approval.documentId != null && approval.documentType.isNotEmpty;

    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(approval.documentTypeLabel,
                          style: const TextStyle(fontWeight: FontWeight.w700)),
                      // ⭐ নম্বরের পাশে পক্ষ, এক লাইনে (মালিক, ১৯ অক্টোবর ২০২৬); পুরনো সার্ভারে কেবল নম্বর
                      if (approval.documentNo != null || approval.party != null)
                        NumberWithParty(
                          key: Key('approval-number-${approval.id}'),
                          number: approval.documentNo ?? '—',
                          party: approval.party,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                    ],
                  ),
                ),
                // Absent rather than zero when the server sent no amount —
                // not every approvable document is about money.
                if (approval.amount != null)
                  Text(approval.amountText!,
                      style: const TextStyle(
                          fontWeight: FontWeight.w700, fontSize: 16)),
              ],
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              [
                if (approval.requesterName != null) approval.requesterName!,
                if (requestedAt != null)
                  stampText(requestedAt),
                if (approval.currentLevel > 0) context.l10n.approvalsInboxStep(approval.currentLevel),
              ].join(' · '),
              style: Theme.of(context).textTheme.bodySmall,
            ),
            // ⓘ পক্ষ নম্বরের লাইনে উঠেছে — সারাংশে আর দুইবার নয় ([[summaryAfterParty]])
            if (summaryAfterParty(approval.summary, approval.party) case final rest?) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(rest),
            ],
            const SizedBox(height: AppSpacing.sm),
            // ⭐ বিস্তারিত — সারি, খাত, বর্ণনা, যোগফল, সই দেওয়ার আগে (মালিক, ৭ অক্টোবর ২০২৬)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: Key('approval-details-${approval.id}'),
                onPressed: onDetails,
                icon: const Icon(Icons.list_alt_outlined, size: 18),
                label: Text(context.l10n.approvalsInboxDetails),
              ),
            ),
            // ⛔ সারি নয়, মোড়ানো — ৩৬০ চওড়া ফোনে "কাগজ দেখুন · প্রত্যাখ্যান · অনুমোদন" এক সারিতে ধরত না, ডান দিকে উপচে
            // সই-বোতাম কেটে যেত (১৯ অক্টোবর ২০২৬)। ⓘ জায়গা থাকলে এক সারি, ডানে; না থাকলে
            // সিদ্ধান্তের জোড়া নিচের লাইনে, ডানে — অ্যাপের অন্য কার্ডের মোড়ানো বোতামের মতো ([[ApprovalRowsScreens]])।
            Wrap(
              alignment: WrapAlignment.end,
              spacing: AppSpacing.sm,
              crossAxisAlignment: WrapCrossAlignment.center,
              runSpacing: AppSpacing.xs,
              children: [
                // ⭐ সইয়ের আগে আসল কাগজটা — মালিক, ৪ অক্টোবর ২০২৬: *"sob vautucher cr dr note … print share korazay pdf
                // e"*। The same sheet as everywhere ([[DocumentActionsSheet]]): the server's own PDF, then print or share.
                if (hasPaper)
                  TextButton.icon(
                    key: Key('approval-paper-${approval.id}'),
                    onPressed: () => DocumentActionsSheet.show(
                      context,
                      type: approval.documentType,
                      id: approval.documentId!,
                      title: [
                        approval.documentTypeLabel,
                        if (approval.documentNo != null) approval.documentNo!
                      ].join(' · '),
                      fileStem: approval.documentNo,
                    ),
                    icon: const Icon(Icons.picture_as_pdf_outlined, size: 18),
                    label: Text(context.l10n.approvalsInboxViewPaper),
                  ),
                Wrap(
                  spacing: AppSpacing.sm,
                  runSpacing: AppSpacing.xs,
                  alignment: WrapAlignment.end,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    // ⭐ দুই আলাদা কাজ, দুই আলাদা লেখা (ওয়েবের মতো): প্রত্যাখ্যান চূড়ান্ত, সংশোধনে ফেরত লেখকের কাছে
                    TextButton(
                      key: Key('approval-reject-${approval.id}'),
                      onPressed: onReject,
                      style: TextButton.styleFrom(
                          foregroundColor: context.palette.danger),
                      child: Text(context.l10n.approvalsInboxRejectButton),
                    ),
                    OutlinedButton(
                      key: Key('approval-send-back-${approval.id}'),
                      onPressed: onSendBack,
                      child: Text(context.l10n.approvalsInboxSendBackButton),
                    ),
                    FilledButton(
                      onPressed: onApprove,
                      child: Text(context.l10n.approvalsInboxSignButton),
                    ),
                  ],
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Asks for a reason. Required for a rejection, optional for an approval.
class _RemarksDialog extends StatefulWidget {
  const _RemarksDialog({
    required this.title,
    required this.subtitle,
    required this.confirmLabel,
    required this.remarksRequired,
    this.destructive = false,
  });

  final String title;
  final String subtitle;
  final String confirmLabel;
  final bool remarksRequired;
  final bool destructive;

  @override
  State<_RemarksDialog> createState() => _RemarksDialogState();
}

class _RemarksDialogState extends State<_RemarksDialog> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // The button stays dead until a reason is typed, rather than letting
    // someone send an empty one and be refused by the server. The same rule
    // lives in ApprovalsApi.reject and in ApprovalEngine::reject's signature
    // — three places, because a refusal with no reason reaches the person who
    // asked as something they cannot act on.
    final ready = !widget.remarksRequired || _controller.text.trim().isNotEmpty;

    return AlertDialog(
      title: Text(widget.title),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(widget.subtitle, style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _controller,
            autofocus: true,
            maxLines: 2,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(
              labelText: widget.remarksRequired
                  ? context.l10n.approvalsInboxReasonRequired
                  : context.l10n.approvalsInboxRemarksOptional,
            ),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(context.l10n.commonCancel),
        ),
        FilledButton(
          onPressed:
              ready ? () => Navigator.of(context).pop(_controller.text) : null,
          style: widget.destructive
              ? FilledButton.styleFrom(backgroundColor: context.palette.danger)
              : null,
          child: Text(widget.confirmLabel),
        ),
      ],
    );
  }
}

/// ⭐ সইয়ের আগে কাগজের বিস্তারিত — কাগজ নিজে যা বলে ([[ApprovalSheet]]); এক লাইনে এক জিনিস, টেবিল নয় (মালিকের নিয়ম)।
class _ApprovalDetails extends StatelessWidget {
  const _ApprovalDetails({required this.approval, required this.sheet});

  final ApprovalRecord approval;
  final Future<ApprovalSheet> sheet;

  static Future<void> show(BuildContext context, ApprovalRecord approval, Future<ApprovalSheet> sheet) =>
      showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        builder: (_) => _ApprovalDetails(approval: approval, sheet: sheet),
      );

  @override
  Widget build(BuildContext context) => SafeArea(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxHeight: MediaQuery.of(context).size.height * 0.85),
          child: FutureBuilder<ApprovalSheet>(
            future: sheet,
            builder: (context, snap) {
              final title = [approval.documentTypeLabel, if (approval.documentNo != null) approval.documentNo!].join(' · ');
              if (snap.connectionState != ConnectionState.done) {
                return const Padding(padding: EdgeInsets.all(AppSpacing.lg), child: LinearProgressIndicator());
              }
              if (snap.hasError) {
                return Padding(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  child: Text(errorMessageFor(snap.error!, fallback: context.l10n.approvalsInboxDetailsFailed),
                      style: TextStyle(color: context.palette.danger)),
                );
              }
              final s = snap.data!;
              return ListView(
                shrinkWrap: true,
                padding: const EdgeInsets.all(AppSpacing.md),
                children: [
                  Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                  if (approval.amount != null) Text(approval.amountText!, style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: AppSpacing.sm),
                  for (final (label, value) in s.facts) Text('$label: $value'),
                  if (s.rows.isNotEmpty) ...[
                    const Divider(),
                    for (final row in s.rows) Padding(
                      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                      child: Text(s.lineOf(row)),
                    ),
                  ],
                  if (s.totalLine != null) Text(s.totalLine!, style: const TextStyle(fontWeight: FontWeight.w700)),
                  if (s.facts.isEmpty && s.rows.isEmpty)
                    Text(context.l10n.approvalsInboxNoDetails),
                ],
              );
            },
          ),
        ),
      );
}

/// ইনবক্সের তিন সিদ্ধান্ত — অনুমোদন, সংশোধনে ফেরত, প্রত্যাখ্যান (তিনটা আলাদা দরজা)
enum _Decision { approve, sendBack, reject }
