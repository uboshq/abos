import 'dart:async';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../printing/document_actions_sheet.dart';
import 'approval_approve_sheet.dart';
import 'approval_attachments.dart';
import 'approval_inbox_screen.dart' show inboxModuleColor;
import 'approval_reject_sheet.dart';
import 'final_reject_confirm.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/approval_record.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/day_part.dart';

/// ⭐ কাগজের পাতা আর সিদ্ধান্ত — পরিকল্পনার পাতা ৬, ছবি ২ (অনুমোদন ভাগ ৩, দফা ২১; ৩০ অক্টোবর ২০২৬)।
///
/// <p>মাথায় "‹ ইনবক্স · ১/৭" আর কাজ · নম্বর; তারপর সারাংশ (পক্ষ, অঙ্ক, কে চেয়েছেন, শেষ সময় রঙে, অঙ্ক কথায়, ধাপ), কাগজের
/// লাইন আর মোট ("পুরো কাগজ ›"), যাত্রাপথ, সংযুক্তি; নিচে স্থির তিন বোতাম — প্রত্যাখ্যান (লাল) · সংশোধনে ফেরত · অনুমোদন।
/// ⓘ সিদ্ধান্তের পরে তালিকার পরের কাগজ নিজে খোলে (ওয়েবের দফা ১); শেষটা হলে ইনবক্সে ফেরা।
///
/// <p>⛔ আগের নিয়মগুলো সব এখানে, কার্ড থেকে সরে এসে: প্রত্যাখ্যান আর ফেরতে কারণ বাধ্যতামূলক, প্রত্যাখ্যানের আগে আলাদা শেষ
/// প্রশ্ন, অনুমোদনের প্রশ্নে অঙ্ক, কেউ আগে সিদ্ধান্ত দিলে সারিটা চুপচাপ সরে (৪০৯), আর কার্ড খোলার পরে অঙ্ক বদলালে সই পড়ে না —
/// ইনবক্স আবার আসে (৪১২)।
class ApprovalPaperScreen extends StatefulWidget {
  const ApprovalPaperScreen({
    super.key,
    required this.rows,
    required this.start,
    required this.onDecided,
    required this.onAmountChanged,
    this.loadSheet,
    this.approve,
    this.reject,
    this.sendBack,
    this.loadAttachment,
    this.askApproveFirst = false,
    this.deviceCheck,
    this.rejectWithCode,
  });

  /// প্রত্যাখ্যান, কারণের কোডসহ — না দিলে [reject] (পুরনো টেস্টের ফাঁক), তাও না দিলে সার্ভার
  final Future<void> Function(String id, String remarks, String code)? rejectWithCode;

  /// বড় অঙ্কে ফোনের তালা — টেস্টের ফাঁক; না দিলে আঙুলের ছাপ বা PIN ([[ApprovalApproveSheet]])
  final Future<bool?> Function()? deviceCheck;

  /// ⭐ ইনবক্সের কার্ডের "✓ অনুমোদন দিন" — পাতা খুলেই অনুমোদনের প্রশ্ন, অঙ্কসহ (নমুনা v4 দফা ৯); প্রশ্নটা পাতার নিজের, তাই অঙ্ক
  /// মেলানো (৪১২) আর আগে-কেউ-দিয়েছেন (৪০৯) একই পথে
  final bool askApproveFirst;

  /// ইনবক্সের তালিকা, ক্রমে — আগের/পরের আর সিদ্ধান্তের পরের কাগজ এখান থেকে
  final List<ApprovalRecord> rows;
  final int start;

  /// সিদ্ধান্ত হলো (বা অন্য কেউ আগেই দিয়েছেন) — ইনবক্স সারিটা সরায়
  final ValueChanged<String> onDecided;

  /// অঙ্ক বদলেছে — ইনবক্স আবার আনে
  final Future<void> Function() onAmountChanged;

  final Future<ApprovalSheet> Function(String id)? loadSheet;
  final Future<void> Function(String id, String? remarks)? approve;
  final Future<void> Function(String id, String remarks)? reject;
  final Future<void> Function(String id, String remarks)? sendBack;
  final Future<List<int>> Function(String id, int file)? loadAttachment;

  @override
  State<ApprovalPaperScreen> createState() => _ApprovalPaperScreenState();
}

class _ApprovalPaperScreenState extends State<ApprovalPaperScreen> {
  late List<ApprovalRecord> _rows = List.of(widget.rows);
  late int _at = widget.start.clamp(0, _rows.length - 1);
  late Future<ApprovalSheet> _sheet = _fetch();
  bool _busy = false;

  ApprovalRecord get _paper => _rows[_at];

  @override
  void initState() {
    super.initState();
    if (widget.askApproveFirst) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _confirmApprove();
      });
    }
  }

  Future<ApprovalSheet> _fetch() => (widget.loadSheet ?? ApprovalsApi.sheet)(_rows[_at].id);

  void _go(int to) => setState(() {
        _at = to;
        _sheet = _fetch();
      });

  void _say(String message) => ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(message)));

  /// ⓘ সারিটা তালিকা থেকে সরিয়ে পরেরটা খোলা — শেষটা হলে আগেরটা, কিছু না থাকলে ইনবক্সে ফেরা
  void _leave(String id) {
    widget.onDecided(id);
    final rest = _rows.where((r) => r.id != id).toList();
    if (rest.isEmpty) {
      Navigator.of(context).pop();
      return;
    }
    setState(() {
      _rows = rest;
      _at = _at.clamp(0, rest.length - 1);
      _sheet = _fetch();
    });
  }

  Future<void> _decide(_Decision decision, String? remarks, {String? code}) async {
    final paper = _paper;
    setState(() => _busy = true);
    try {
      switch (decision) {
        case _Decision.approve:
          await (widget.approve ??
              (String id, String? note) => ApprovalsApi.approve(id, remarks: note, seenAmount: paper.rawAmount))(paper.id, remarks);
        // ⭐ সংশোধনে ফেরত — send-back; প্রত্যাখ্যান নয় (০.৪.৪২-এর ভুল, ২১ অক্টোবর ২০২৬)
        case _Decision.sendBack:
          await (widget.sendBack ?? (String id, String note) => ApprovalsApi.sendBack(id, remarks: note))(paper.id, remarks!);
        case _Decision.reject:
          final coded = widget.rejectWithCode;
          if (coded != null) {
            await coded(paper.id, remarks!, code ?? 'other');
          } else if (widget.reject != null) {
            await widget.reject!(paper.id, remarks!);
          } else {
            await ApprovalsApi.reject(paper.id, remarks: remarks!, reasonCode: code);
          }
      }
      if (!mounted) return;
      _say(switch (decision) {
        _Decision.approve => context.l10n.approvalsInboxSigned,
        _Decision.sendBack => context.l10n.approvalsInboxSentBack,
        _Decision.reject => context.l10n.approvalsInboxRejected,
      });
      _leave(paper.id);
    } catch (error) {
      if (!mounted) return;
      switch (approvalFailureOf(error)) {
        case ApprovalFailure.alreadyDecided:
          // Not an error. Two people can be looking at the same inbox, and
          // the second to tap is seeing that a colleague got there first.
          _say(context.l10n.approvalsInboxAlreadyDecided);
          _leave(paper.id);
        case ApprovalFailure.notYours:
          _say(context.l10n.approvalsInboxNotYours);
        case ApprovalFailure.amountChanged:
          // ⛔ কাগজ খোলার পরে অঙ্ক বদলেছে — সই পড়েনি; ইনবক্স আবার, নতুন অঙ্ক দেখে আবার সই
          _say(context.l10n.approvalsInboxAmountChanged);
          unawaited(widget.onAmountChanged());
          Navigator.of(context).pop();
        case ApprovalFailure.network:
          _say(context.l10n.approvalsInboxOffline);
        case ApprovalFailure.other:
          _say(errorMessageFor(error, fallback: context.l10n.approvalsInboxActionFailed));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // ⓘ সইয়ের প্রশ্নে অঙ্কও — কোন অঙ্কে সই, তা দেখে
  String _describe(ApprovalRecord approval) => [
        approval.documentTypeLabel,
        if (approval.documentNo != null) approval.documentNo!,
        if (approval.amountText != null) approval.amountText!,
      ].join(' · ');

  /// ⭐ নমুনা v4, ছবি ৪ — নিচ থেকে ওঠা শিট: অঙ্ক সবুজ বাক্সে, ইচ্ছা হলে মন্তব্য, শেষ ধাপের কথা, বড় অঙ্কে ফোনের তালা।
  /// ⓘ "হ্যাঁ" বলতে কারণ লাগে না, "না" বলতে লাগে — সার্ভারেরই অসমতা (ApprovalEngine.approve-এ ?string, reject-এ string)
  Future<void> _confirmApprove() async {
    final paper = _paper;
    final at = paper.stepAt, of = paper.stepOf;
    final remarks = await ApprovalApproveSheet.show(
      context,
      paper: paper,
      lastStep: at != null && of != null && at >= of,
      deviceCheck: widget.deviceCheck,
    );
    if (remarks == null || !mounted) return;
    await _decide(_Decision.approve, remarks.trim().isEmpty ? null : remarks);
  }

  /// ⭐ সংশোধনে ফেরত — কারণ বাধ্যতামূলক, কাগজ লেখকের কাছে ফেরে
  Future<void> _confirmSendBack() async {
    final words = context.l10n;
    final remarks = await showDialog<String>(
      context: context,
      builder: (_) => _RemarksDialog(
        title: words.approvalsInboxSendBackTitle,
        subtitle: _describe(_paper),
        confirmLabel: words.approvalsInboxSendBackButton,
        remarksRequired: true,
      ),
    );
    if (remarks == null || !mounted) return;
    await _decide(_Decision.sendBack, remarks);
  }

  /// ⭐ প্রত্যাখ্যান — নমুনা v4, ছবি ৫: নিচের শিটে এক চাপে কারণ আর বাধ্যতামূলক লেখা, তারপর আলাদা শেষ প্রশ্ন ("চূড়ান্ত — আর
  /// ফেরানো যাবে না"); না বললে কিছুই যায় না
  Future<void> _confirmReject() async {
    final what = _describe(_paper);
    final picked = await ApprovalRejectSheet.show(context, what: what);
    if (picked == null || !mounted) return;
    if (!await confirmFinalReject(context, what: what) || !mounted) return;
    await _decide(_Decision.reject, picked.$2, code: picked.$1);
  }

  /// ⓘ এক কাগজের সংযুক্তির বাইট — একবার আনা, তারপর মনে রাখা (ছোট ছবি আর পুরো পর্দা একই বাইট); ফোনে কোথাও লেখা হয় না
  final Map<String, Future<Uint8List>> _files = {};

  Future<Uint8List> _bytesOf(int file) {
    final paper = _paper.id;
    return _files.putIfAbsent('$paper:$file',
        () async => Uint8List.fromList(await (widget.loadAttachment ?? ApprovalsApi.attachment)(paper, file)));
  }

  /// ⭐ নমুনা v4, ছবি ৩ — অ্যাপের ভেতরে পুরো পর্দায়, পাশে সরালে পরের ফাইল ([[AttachmentViewer]])
  Future<void> _openAttachment(List<(int, String, String)> files, int index) =>
      AttachmentViewer.open(context, files: files, start: index, load: _bytesOf);

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    final paper = _paper;
    final title = [paper.documentTypeLabel, if (paper.documentNo != null) paper.documentNo!].join(' · ');

    return Scaffold(
      // ⓘ হোমের একই নীল বার — মালিকের নকশা ৩ (২০ অক্টোবর ২০২৬: "প্রতিটা পাতায়, সবসময়"); পাতার নাম কাজ · নম্বর, পাশে আগের/পরের।
      // "ইনবক্স · ১/৭" (পাতা ৬, ছবি ২) বারের ঠিক নিচে, পাতার মাথায় — দুই নিয়মই থাকে
      appBar: AbosScreenBar(
        title: title,
        actions: [
          IconButton(
            key: const ValueKey('approval-paper-prev'),
            tooltip: MaterialLocalizations.of(context).previousPageTooltip,
            onPressed: _at > 0 && !_busy ? () => _go(_at - 1) : null,
            icon: const Icon(Icons.chevron_left),
          ),
          IconButton(
            key: const ValueKey('approval-paper-next'),
            tooltip: MaterialLocalizations.of(context).nextPageTooltip,
            onPressed: _at < _rows.length - 1 && !_busy ? () => _go(_at + 1) : null,
            icon: const Icon(Icons.chevron_right),
          ),
        ],
      ),
      body: FutureBuilder<ApprovalSheet>(
        future: _sheet,
        builder: (context, snap) {
          final sheet = snap.data;
          return ListView(
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              if (_busy || snap.connectionState != ConnectionState.done) const LinearProgressIndicator(),
              // ⭐ নমুনা v4, ছবি ২ — বিভাগের রঙে মাথা; তারপর "কেন", "কার, কে চেয়েছেন", লাইন, যাত্রাপথ, সংযুক্তি
              _PaperHead(
                paper: paper,
                sheet: sheet,
                position: words.approvalsBackToInbox(_at + 1, _rows.length),
                onBack: () => Navigator.of(context).maybePop(),
                onNext: _at < _rows.length - 1 && !_busy ? () => _go(_at + 1) : null,
              ),
              if (snap.hasError)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpacing.sm),
                  child: Text(errorMessageFor(snap.error!, fallback: words.approvalsInboxDetailsFailed),
                      style: TextStyle(color: palette.danger)),
                ),
              if (sheet != null) ...[
                if (sheet.whyRule != null || sheet.whyReason != null) _Why(sheet: sheet),
                _Summary(paper: paper, sheet: sheet),
                _Lines(sheet: sheet, paper: paper),
                if (sheet.journey.isNotEmpty) _Journey(sheet: sheet, paper: paper),
                if (sheet.attachments.isNotEmpty)
                  _Box(
                    key: const ValueKey('approval-attachments'),
                    title: words.approvalsAttachments,
                    action: Text(words.attachmentThumbsHint, style: TextStyle(fontSize: 12, color: palette.onSurfaceMuted)),
                    // ⭐ নমুনা v4, ছবি ২ — প্রতিটা সংযুক্তির ছোট ছবি, চাপলে অ্যাপেই খোলে
                    child: AttachmentThumbs(
                      files: sheet.attachments,
                      load: _bytesOf,
                      onOpen: (i) => _openAttachment(sheet.attachments, i),
                    ),
                  ),
              ],
            ],
          );
        },
      ),
      // ⭐ নিচে স্থির তিন বোতাম — পাতা ৬, ছবি ২; কাগজ যত লম্বাই হোক, সিদ্ধান্ত খুঁজতে নিচে নামতে হয় না।
      // ⓘ সরু ফোনে লেখা দুই লাইনে ভাঙে, কাটে না (৩৬০ চওড়ায় "সংশোধনে ফেরত" ডানে উপচে যেত)
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(AppSpacing.sm, AppSpacing.sm, AppSpacing.sm, AppSpacing.sm),
          child: Row(
            children: [
              Expanded(
                flex: 10,
                child: OutlinedButton(
                  key: ValueKey('approval-reject-${paper.id}'),
                  onPressed: _busy ? null : _confirmReject,
                  style: OutlinedButton.styleFrom(
                    foregroundColor: palette.danger,
                    side: BorderSide(color: palette.danger),
                    padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
                  ),
                  child: Text(words.approvalsInboxRejectButton, textAlign: TextAlign.center, maxLines: 2),
                ),
              ),
              // ⭐ "ফেরত পাঠান" কেবল যেখানে সার্ভার অনুমতি দেয় (`canSendBack`; নমুনা v4 দফা ১০ — আগে সবখানে দেখাত)
              if (paper.canSendBack) ...[
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  flex: 10,
                  child: OutlinedButton(
                    key: ValueKey('approval-send-back-${paper.id}'),
                    onPressed: _busy ? null : _confirmSendBack,
                    style: OutlinedButton.styleFrom(
                      foregroundColor: palette.warning,
                      side: BorderSide(color: palette.warning),
                      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
                    ),
                    child: Text(words.approvalsInboxSendBackButton, textAlign: TextAlign.center, maxLines: 2),
                  ),
                ),
              ],
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                flex: 14,
                child: FilledButton(
                  key: ValueKey('approval-approve-${paper.id}'),
                  onPressed: _busy ? null : _confirmApprove,
                  // ⭐ নমুনা v4: সবুজ, অন্য দুইটার চেয়ে চওড়া
                  style: FilledButton.styleFrom(
                    backgroundColor: const Color(0xFF15803D),
                    foregroundColor: const Color(0xFFFFFFFF), // dark-ok: white on the fixed green approve button
                    padding: const EdgeInsets.symmetric(horizontal: AppSpacing.xs),
                  ),
                  child: Text(words.approvalsInboxSignButton, textAlign: TextAlign.center, maxLines: 2),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Box extends StatelessWidget {
  const _Box({super.key, required this.child, this.title, this.action});

  final String? title;
  final Widget? action;
  final Widget child;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(top: AppSpacing.sm),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (title != null)
                Row(children: [
                  Expanded(child: Text(title!, style: const TextStyle(fontWeight: FontWeight.w700))),
                  if (action != null) action!,
                ]),
              if (title != null) const SizedBox(height: AppSpacing.sm),
              child,
            ],
          ),
        ),
      );
}

/// ⭐ কাগজের মাথা — মালিকের নমুনা v4, ছবি ২ ("ok", ৮ নভেম্বর ২০২৬): বিভাগের রঙে ভরা পট্টি; "‹ ইনবক্স · ১/৭" আর "পরের ›", কাজ · নম্বর,
/// বড় করে অঙ্ক, কথায় অঙ্ক, আর ফিতায় শেষ সময় (দেরি হলে লাল) আর ধাপ · নাম
class _PaperHead extends StatelessWidget {
  const _PaperHead({required this.paper, required this.sheet, required this.position, required this.onBack, required this.onNext});

  final ApprovalRecord paper;
  final ApprovalSheet? sheet;
  final String position;
  final VoidCallback onBack;
  final VoidCallback? onNext;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final colour = inboxModuleColor(paper.moduleKey);
    final deep = Color.lerp(colour, const Color(0xFF000000), 0.28)!;
    final deadline = sheet?.deadlineText ?? paper.deadlineText;
    final tone = sheet?.deadlineTone ?? paper.deadlineTone;
    final at = sheet?.stepAt ?? paper.stepAt;
    final of = sheet?.stepOf ?? paper.stepOf;
    final title = [paper.documentTypeLabel, if (paper.documentNo != null) paper.documentNo!].join(' · ');
    const ink = Color(0xFFFFFFFF); // dark-ok: white on the module's own colour, in both modes

    Widget pill(Key? key, String text, {bool late = false}) => Container(
          key: key,
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
          decoration: BoxDecoration(
            color: late ? const Color(0xFFC62828) : ink.withValues(alpha: 0.18),
            border: Border.all(color: late ? const Color(0xFFEF5350) : ink.withValues(alpha: 0.3)),
            borderRadius: BorderRadius.circular(999),
          ),
          child: Text(text, style: const TextStyle(color: ink, fontSize: 12, fontWeight: FontWeight.w600)),
        );

    return Container(
      key: const ValueKey('approval-paper-head'),
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
      decoration: BoxDecoration(
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [colour, deep]),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            TextButton.icon(
              key: const ValueKey('approval-paper-position'),
              style: TextButton.styleFrom(foregroundColor: ink, padding: EdgeInsets.zero, visualDensity: VisualDensity.compact),
              onPressed: onBack,
              icon: const Icon(Icons.chevron_left, size: 18),
              label: Text(position),
            ),
            const Spacer(),
            if (onNext != null)
              TextButton(
                key: const ValueKey('approval-paper-head-next'),
                style: TextButton.styleFrom(foregroundColor: ink, visualDensity: VisualDensity.compact),
                onPressed: onNext,
                child: Text('${words.approvalsNext} ›'),
              ),
          ]),
          Text(title, key: const ValueKey('approval-paper-title'), style: const TextStyle(color: ink, fontSize: 20, fontWeight: FontWeight.w700)),
          if (paper.amountText != null)
            Text(paper.amountText!, key: const ValueKey('approval-paper-amount'),
                style: const TextStyle(color: ink, fontSize: 30, fontWeight: FontWeight.w700, height: 1.15)),
          if (sheet?.amountWords != null)
            Text(sheet!.amountWords!, key: const ValueKey('approval-paper-words'), style: TextStyle(color: ink.withValues(alpha: 0.9), fontSize: 12.5)),
          if (deadline != null || (at != null && of != null)) ...[
            const SizedBox(height: 10),
            Wrap(spacing: 6, runSpacing: 6, children: [
              if (deadline != null) pill(const ValueKey('approval-paper-deadline'), '⏱ $deadline', late: tone == 'danger'),
              if (at != null && of != null)
                pill(const ValueKey('approval-paper-step'),
                    paper.stepName == null ? words.approvalsStepOf(at, of) : '${words.approvalsStepOf(at, of)} · ${paper.stepName}'),
            ]),
          ],
        ],
      ),
    );
  }
}

/// "কার, কে চেয়েছেন" — নমুনার ঘর-মান: পক্ষ, যিনি চেয়েছেন আর কখন; কারো হয়ে হলে সেটাও
class _Summary extends StatelessWidget {
  const _Summary({required this.paper, required this.sheet});

  final ApprovalRecord paper;
  final ApprovalSheet? sheet;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    final asker = sheet?.requesterName ?? paper.requesterName;
    final when = sheet?.requestedAt ?? paper.requestedAt;
    final party = paper.party ?? summaryOrNull(paper.summary);
    TableRow row(String k, String v) => TableRow(children: [
          Padding(padding: const EdgeInsets.only(bottom: 4, right: 12), child: Text(k, style: TextStyle(color: palette.onSurfaceMuted, fontSize: 13.5))),
          Padding(padding: const EdgeInsets.only(bottom: 4), child: Text(v, textAlign: TextAlign.end, style: const TextStyle(fontSize: 13.5))),
        ]);

    return _Box(
      key: const ValueKey('approval-summary'),
      title: words.approvalsWhoTitle,
      child: Table(
        columnWidths: const {0: IntrinsicColumnWidth(), 1: FlexColumnWidth()},
        children: [
          if (party != null) row(words.approvalsWhoParty, party),
          if (asker != null) row(words.approvalsWhoAsked, when == null ? asker : '$asker · ${stampText(when)}'),
          if (sheet?.standInFor != null) row(words.approvalsWhoFor, sheet!.standInFor!),
        ],
      ),
    );
  }
}

String? summaryOrNull(String? text) => text == null || text.trim().isEmpty ? null : text;

/// ⭐ "কেন আপনার অনুমোদন লাগছে" — নমুনা v4: কোন নিয়মে কাগজটা আপনার কাছে এল আর ধাপ; যিনি চেয়েছেন তার লেখা কারণ উদ্ধৃতিতে।
/// ⓘ বাঁয়ে বিভাগের রঙের দাগ (নমুনার `sec.why`)
class _Why extends StatelessWidget {
  const _Why({required this.sheet});

  final ApprovalSheet sheet;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    return Card(
      key: const ValueKey('approval-why'),
      margin: const EdgeInsets.only(top: AppSpacing.sm),
      clipBehavior: Clip.antiAlias,
      child: DecoratedBox(
        decoration: BoxDecoration(border: Border(left: BorderSide(color: Theme.of(context).colorScheme.primary, width: 4))),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(words.approvalsWhyTitle, style: TextStyle(fontWeight: FontWeight.w700, color: palette.onSurfaceMuted, fontSize: 13.5)),
              const SizedBox(height: AppSpacing.xs),
              if (sheet.whyRule != null)
                Text(
                  sheet.whyStep == null ? sheet.whyRule! : '${sheet.whyRule!} · ${sheet.whyStep!}',
                  key: const ValueKey('approval-why-rule'),
                  style: const TextStyle(fontSize: 13.5),
                ),
              if (sheet.whyReason != null)
                Padding(
                  padding: const EdgeInsets.only(top: AppSpacing.xs),
                  child: Text(words.approvalsWhyReason(sheet.whyReason!), key: const ValueKey('approval-why-reason'), style: const TextStyle(fontSize: 13.5)),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// কাগজের লাইন — এক লাইনে এক সারি: বাঁয়ে কী, ডানে অঙ্ক; নিচে মোট। ঘর আর মান (তারিখ, মাধ্যম …) তার উপরে।
class _Lines extends StatelessWidget {
  const _Lines({required this.sheet, required this.paper});

  final ApprovalSheet sheet;
  final ApprovalRecord paper;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final small = Theme.of(context).textTheme.bodySmall;
    final hasPaper = paper.documentId != null && paper.documentType.isNotEmpty;

    // ⓘ অঙ্কের কলাম একটাই হলে নাম ছাড়া ("80,000.00", পাতা ৬, ছবি ২); দুইটা হলে নামসহ — নাহলে কোনটা ডেবিট বোঝা যেত না
    final named = sheet.columns.where((c) => c.$3).length > 1;
    String wordsOf(Map<String, String?> row) => [
          for (final c in sheet.columns)
            if (!c.$3 && (row[c.$1] ?? '').trim().isNotEmpty) row[c.$1]!.trim(),
        ].join(' · ');
    String moneyOf(Map<String, String?> row) => [
          for (final c in sheet.columns)
            if (c.$3 && (row[c.$1] ?? '').trim().isNotEmpty) named ? '${c.$2} ${row[c.$1]!.trim()}' : row[c.$1]!.trim(),
        ].join(' · ');
    final total = moneyOf(sheet.totals);

    return _Box(
      key: const ValueKey('approval-lines'),
      title: words.approvalsLines,
      // ⭐ পুরো কাগজ — সার্ভারের নিজের PDF, তারপর ছাপা বা পাঠানো ([[DocumentActionsSheet]]; মালিক, ৪ অক্টোবর ২০২৬)
      action: hasPaper
          ? TextButton(
              key: ValueKey('approval-paper-${paper.id}'),
              onPressed: () => DocumentActionsSheet.show(
                context,
                type: paper.documentType,
                id: paper.documentId!,
                title: [paper.documentTypeLabel, if (paper.documentNo != null) paper.documentNo!].join(' · '),
                fileStem: paper.documentNo,
              ),
              child: Text('${words.approvalsWholePaper} ›'),
            )
          : null,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          for (final (label, value) in sheet.facts) Text('$label: $value', style: small),
          if (sheet.facts.isNotEmpty && sheet.rows.isNotEmpty) const Divider(),
          for (final row in sheet.rows)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.xs),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(child: Text(wordsOf(row))),
                const SizedBox(width: AppSpacing.sm),
                Text(moneyOf(row), textAlign: TextAlign.end),
              ]),
            ),
          if (total.isNotEmpty) ...[
            const Divider(),
            Row(key: const ValueKey('approval-lines-total'), children: [
              Expanded(child: Text(words.approvalsLinesTotal, style: const TextStyle(fontWeight: FontWeight.w700))),
              Text(total, style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
          ],
          if (sheet.facts.isEmpty && sheet.rows.isEmpty) Text(words.approvalsInboxNoDetails),
        ],
      ),
    );
  }
}

/// যাত্রাপথ — তৈরি, তারপর ধাপ ধরে: কে, কখন, কী মন্তব্যে; এখনকার ধাপ "এখন এখানে"
class _Journey extends StatelessWidget {
  const _Journey({required this.sheet, required this.paper});

  final ApprovalSheet sheet;
  final ApprovalRecord paper;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    final small = Theme.of(context).textTheme.bodySmall;
    final created = sheet.requestedAt ?? paper.requestedAt;

    Widget dot(Color color) => Padding(
          padding: const EdgeInsets.only(top: 5, right: AppSpacing.sm),
          child: Container(width: 10, height: 10, decoration: BoxDecoration(color: color, shape: BoxShape.circle)),
        );

    return _Box(
      key: const ValueKey('approval-journey'),
      title: words.approvalsJourney,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            dot(palette.success),
            Expanded(
              child: Text(words.approvalsJourneyCreated(sheet.requesterName ?? paper.requesterName ?? '—', created == null ? '' : stampText(created)),
                  style: small),
            ),
          ]),
          for (final step in sheet.journey)
            Padding(
              padding: const EdgeInsets.only(top: AppSpacing.xs),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                dot(switch (step.state) {
                  'approved' => palette.success,
                  'now' => palette.warning,
                  'waiting' => palette.border,
                  _ => palette.danger,
                }),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(
                      [
                        words.approvalsJourneyStep(step.level),
                        if (step.name != null) step.name!,
                        if (step.state == 'now') words.approvalsJourneyNow,
                        if (step.state == 'waiting') words.approvalsJourneyWaiting,
                        if (step.tookHours != null) words.approvalsJourneyTook(step.tookHours!),
                      ].join(' · '),
                      style: small?.copyWith(fontWeight: step.state == 'now' ? FontWeight.w700 : FontWeight.w500),
                    ),
                    for (final (name, label, at, remarks, onBehalf) in step.by)
                      Text(
                        [
                          onBehalf == null ? name : '$name (${words.approvalsOnBehalf(onBehalf)})',
                          label,
                          if (at != null) stampText(at),
                          if (remarks != null) '“$remarks”',
                        ].join(' · '),
                        style: small?.copyWith(color: palette.onSurfaceMuted),
                      ),
                  ]),
                ),
              ]),
            ),
        ],
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
  });

  final String title;
  final String subtitle;
  final String confirmLabel;
  final bool remarksRequired;

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
              labelText: widget.remarksRequired ? context.l10n.approvalsInboxReasonRequired : context.l10n.approvalsInboxRemarksOptional,
            ),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(context.l10n.commonCancel)),
        FilledButton(
          onPressed: ready ? () => Navigator.of(context).pop(_controller.text) : null,
          child: Text(widget.confirmLabel),
        ),
      ],
    );
  }
}

/// ইনবক্সের তিন সিদ্ধান্ত — অনুমোদন, সংশোধনে ফেরত, প্রত্যাখ্যান (তিনটা আলাদা দরজা)
enum _Decision { approve, sendBack, reject }
