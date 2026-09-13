import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/records/approval_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

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
  });

  /// Seams, so this screen can be driven in a test without a server.
  ///
  /// <p>Not ceremony: the entire class of bug found on 12 September was
  /// screens that were never once run against a payload. A screen that cannot
  /// be run in a test is a screen whose first run is on somebody's phone.
  final Future<ApprovalPage> Function()? loadPending;
  final Future<void> Function(String id, String? remarks)? approve;
  final Future<void> Function(String id, String remarks)? reject;

  @override
  State<ApprovalInboxScreen> createState() => _ApprovalInboxScreenState();
}

class _ApprovalInboxScreenState extends State<ApprovalInboxScreen> {
  List<ApprovalRecord>? _rows;
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
      setState(() => _rows = page.rows);
    } catch (error) {
      if (!mounted) return;
      // An empty inbox and an unreachable server are opposite facts, and a
      // screen that shows "কিছু অপেক্ষা করছে না" for both would tell someone
      // their work is done when it is merely invisible.
      setState(() => _error = errorMessageFor(error,
          fallback: 'তালিকা আনা গেল না। আবার চেষ্টা করুন।'));
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
    required bool approved,
    String? remarks,
  }) async {
    setState(() => _busy = true);
    try {
      if (approved) {
        await (widget.approve ??
            (String id, String? note) =>
                ApprovalsApi.approve(id, remarks: note))(approval.id, remarks);
      } else {
        await (widget.reject ??
            (String id, String note) =>
                ApprovalsApi.reject(id, remarks: note))(approval.id, remarks!);
      }
      if (!mounted) return;
      _drop(approval.id);
      _say(approved ? 'অনুমোদন হয়েছে।' : 'প্রত্যাখ্যান করা হয়েছে।');
    } catch (error) {
      if (!mounted) return;
      switch (approvalFailureOf(error)) {
        case ApprovalFailure.alreadyDecided:
          // Not an error. Two people can be looking at the same inbox, and
          // the second to tap is seeing that a colleague got there first.
          _drop(approval.id);
          _say('এটি ইতিমধ্যে নিষ্পত্তি হয়ে গেছে।');
        case ApprovalFailure.notYours:
          _say('এই সিদ্ধান্তটি আপনার নয়।');
        case ApprovalFailure.network:
          _say('সংযোগ নেই। অনুমোদনের জন্য ইন্টারনেট লাগবে।');
        case ApprovalFailure.other:
          _say(errorMessageFor(error, fallback: 'কাজটি সম্পন্ন হয়নি।'));
      }
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
        title: 'অনুমোদন করবেন?',
        subtitle: _describe(approval),
        confirmLabel: 'অনুমোদন',
        // Saying yes needs no explanation; saying no does. The asymmetry is
        // the server's own (ApprovalEngine.approve takes ?string remarks,
        // reject takes string), kept rather than smoothed over.
        remarksRequired: false,
      ),
    );
    if (remarks == null || !mounted) return;
    await _decide(approval, approved: true, remarks: remarks);
  }

  Future<void> _confirmReject(ApprovalRecord approval) async {
    final remarks = await showDialog<String>(
      context: context,
      builder: (context) => _RemarksDialog(
        title: 'প্রত্যাখ্যান করবেন?',
        subtitle: _describe(approval),
        confirmLabel: 'প্রত্যাখ্যান',
        destructive: true,
        remarksRequired: true,
      ),
    );
    if (remarks == null || !mounted) return;
    await _decide(approval, approved: false, remarks: remarks);
  }

  String _describe(ApprovalRecord approval) => [
        approval.documentTypeLabel,
        if (approval.documentNo != null) approval.documentNo!,
      ].join(' · ');

  @override
  Widget build(BuildContext context) {
    final rows = _rows;

    return Scaffold(
      appBar: AppBar(title: const Text('অনুমোদন')),
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
                      title: 'তালিকা আনা গেল না',
                      message: _error,
                    )
                  else if (rows == null)
                    const SizedBox(height: 200)
                  else if (rows.isEmpty)
                    const EmptyState(
                      icon: Icons.inbox_outlined,
                      title: 'কিছু অপেক্ষা করছে না',
                      // Not an error screen — an empty inbox is good news.
                      message: 'আপনার সিদ্ধান্তের জন্য কোনো নথি নেই।',
                    )
                  else
                    ...rows.map((approval) => _ApprovalCard(
                          approval: approval,
                          onApprove: _busy ? null : () => _confirmApprove(approval),
                          onReject: _busy ? null : () => _confirmReject(approval),
                        )),
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
  });

  final ApprovalRecord approval;
  final VoidCallback? onApprove;
  final VoidCallback? onReject;

  @override
  Widget build(BuildContext context) {
    final requestedAt = approval.requestedAt;

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
                      if (approval.documentNo != null)
                        Text(approval.documentNo!,
                            style: Theme.of(context).textTheme.bodySmall),
                    ],
                  ),
                ),
                // Absent rather than zero when the server sent no amount —
                // not every approvable document is about money.
                if (approval.amount != null)
                  Text(Money.taka(approval.amount),
                      style: const TextStyle(
                          fontWeight: FontWeight.w700, fontSize: 16)),
              ],
            ),
            const SizedBox(height: AppSpacing.xs),
            Text(
              [
                if (approval.requesterName != null) approval.requesterName!,
                if (requestedAt != null)
                  DateFormat('dd/MM/yyyy hh:mm a').format(requestedAt),
                if (approval.currentLevel > 0) 'ধাপ ${approval.currentLevel}',
              ].join(' · '),
              style: Theme.of(context).textTheme.bodySmall,
            ),
            if (approval.summary != null) ...[
              const SizedBox(height: AppSpacing.xs),
              Text(approval.summary!),
            ],
            const SizedBox(height: AppSpacing.sm),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: onReject,
                  style: TextButton.styleFrom(foregroundColor: AppColors.danger),
                  child: const Text('প্রত্যাখ্যান'),
                ),
                const SizedBox(width: AppSpacing.sm),
                FilledButton(
                  onPressed: onApprove,
                  child: const Text('অনুমোদন'),
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
    final ready =
        !widget.remarksRequired || _controller.text.trim().isNotEmpty;

    return AlertDialog(
      title: Text(widget.title),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(widget.subtitle,
              style: Theme.of(context).textTheme.bodySmall),
          const SizedBox(height: AppSpacing.md),
          TextField(
            controller: _controller,
            autofocus: true,
            maxLines: 2,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(
              labelText: widget.remarksRequired
                  ? 'কারণ (বাধ্যতামূলক)'
                  : 'মন্তব্য (ঐচ্ছিক)',
            ),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('বাতিল'),
        ),
        FilledButton(
          onPressed:
              ready ? () => Navigator.of(context).pop(_controller.text) : null,
          style: widget.destructive
              ? FilledButton.styleFrom(backgroundColor: AppColors.danger)
              : null,
          child: Text(widget.confirmLabel),
        ),
      ],
    );
  }
}
