import 'package:flutter/material.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/approvals/approvals_api.dart';
import '../../core/locale/l10n.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ একসাথে অনুমোদন — নিচ থেকে ওঠা পপ-আপ, পরিকল্পনার পাতা ৬, ছবি ৩ (অনুমোদন ভাগ ৩, দফা ২১; Material 3 আর Apple-এর নিয়ম)।
///
/// <p>কোনগুলো যাবে আর তাদের মোট, কোনগুলো বাদ পড়ছে আর কেন (ওয়েবের একই লেখা), মন্তব্য (ঐচ্ছিক), তারপর "২টায় অনুমোদন দিন"।
/// ⓘ তালিকাটা সার্ভারের আগে-দেখা ([[ApprovalsApi.bulkPreview]]) — ফোন নিজে কিছু বাদ দেয় না; অনুমোদনেও সার্ভার আবার দেখে।
/// ⛔ যাওয়ার মতো একটাও না থাকলে বোতাম চাপা যায় না, আর কারণটা লেখা থাকে।
class ApprovalBulkSheet extends StatefulWidget {
  const ApprovalBulkSheet({super.key, required this.ids, required this.preview, required this.approve});

  final List<String> ids;
  final Future<BulkPreview> Function(List<String> ids) preview;
  final Future<BulkResult> Function(List<String> ids, String? remarks) approve;

  /// অনুমোদন হলে ফল, ফিরে গেলে null
  static Future<BulkResult?> show(
    BuildContext context, {
    required List<String> ids,
    required Future<BulkPreview> Function(List<String> ids) preview,
    required Future<BulkResult> Function(List<String> ids, String? remarks) approve,
  }) =>
      showModalBottomSheet<BulkResult>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => ApprovalBulkSheet(ids: ids, preview: preview, approve: approve),
      );

  @override
  State<ApprovalBulkSheet> createState() => _ApprovalBulkSheetState();
}

class _ApprovalBulkSheetState extends State<ApprovalBulkSheet> {
  late final Future<BulkPreview> _preview = widget.preview(widget.ids);
  final _remarks = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _remarks.dispose();
    super.dispose();
  }

  Future<void> _go(BulkPreview preview) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await widget.approve(preview.sign.map((l) => l.id).toList(), _remarks.text);
      if (mounted) Navigator.of(context).pop(result);
    } catch (error) {
      if (mounted) setState(() => _error = errorMessageFor(error, fallback: context.l10n.approvalsInboxActionFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    final small = Theme.of(context).textTheme.bodySmall;

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.md + MediaQuery.viewInsetsOf(context).bottom),
        child: FutureBuilder<BulkPreview>(
          future: _preview,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Padding(padding: EdgeInsets.all(AppSpacing.lg), child: LinearProgressIndicator());
            }
            if (snap.hasError) {
              return Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(errorMessageFor(snap.error!, fallback: words.approvalsInboxActionFailed), style: TextStyle(color: palette.danger)),
              );
            }
            final preview = snap.data!;
            final go = preview.sign.length;

            String line(BulkLine l) => [l.work, if (l.documentNo != null) l.documentNo!].join(' · ');

            return SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(go == 0 ? words.approvalsBulkNone : words.approvalsBulkTitle(go),
                      key: const ValueKey('approvals-bulk-title'), style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: AppSpacing.sm),
                  for (final l in preview.sign)
                    Padding(
                      key: ValueKey('approvals-bulk-go-${l.id}'),
                      padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                      child: Row(children: [
                        Expanded(child: Text(line(l))),
                        Text(Money.taka(l.amount ?? '0')),
                      ]),
                    ),
                  if (go > 0) ...[
                    const Divider(),
                    Row(children: [
                      Expanded(child: Text(words.approvalsBulkTotal(go), style: const TextStyle(fontWeight: FontWeight.w700))),
                      Text(Money.taka(preview.total ?? '0'), key: const ValueKey('approvals-bulk-total'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    ]),
                  ],
                  // ⭐ কোনগুলো বাদ আর কেন — চোখের সামনে, যাতে কেউ ভাবেন না বাছাই করা সবগুলো গেছে
                  if (preview.skip.isNotEmpty) ...[
                    const SizedBox(height: AppSpacing.sm),
                    Container(
                      key: const ValueKey('approvals-bulk-skip'),
                      padding: const EdgeInsets.all(AppSpacing.sm),
                      decoration: BoxDecoration(
                        color: palette.warningSurface,
                        border: Border(left: BorderSide(color: palette.warning, width: 3)),
                      ),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(words.approvalsBulkSkipped(widget.ids.length, preview.skip.length), style: small),
                        for (final l in preview.skip)
                          Text('${line(l)} — ${l.reason ?? ''}', key: ValueKey('approvals-bulk-skip-${l.id}'), style: small),
                      ]),
                    ),
                  ],
                  const SizedBox(height: AppSpacing.sm),
                  TextField(
                    controller: _remarks,
                    maxLines: 2,
                    decoration: InputDecoration(labelText: words.approvalsInboxRemarksOptional, border: const OutlineInputBorder()),
                  ),
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpacing.sm),
                      child: Text(_error!, style: TextStyle(color: palette.danger)),
                    ),
                  const SizedBox(height: AppSpacing.md),
                  Row(children: [
                    Expanded(
                      child: OutlinedButton(
                        key: const ValueKey('approvals-bulk-back'),
                        onPressed: _busy ? null : () => Navigator.of(context).pop(),
                        child: Text(words.approvalsGoBack),
                      ),
                    ),
                    const SizedBox(width: AppSpacing.sm),
                    Expanded(
                      child: FilledButton(
                        key: const ValueKey('approvals-bulk-confirm'),
                        onPressed: go == 0 || _busy ? null : () => _go(preview),
                        child: Text(words.approvalsBulkConfirm(go)),
                      ),
                    ),
                  ]),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}
