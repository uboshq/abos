import 'package:flutter/material.dart';
import 'package:local_auth/local_auth.dart';
import 'package:local_auth_android/local_auth_android.dart';

import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/records/approval_record.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ "অনুমোদন দিন" চাপলে নিচ থেকে ওঠা শিট — মালিকের নমুনা "অনুমোদনের নতুন ইনবক্স" v4, ছবি ৪ ("ok", ৮ নভেম্বর ২০২৬)।
///
/// <p>সবুজ বাক্সে কাজ · নম্বর · পক্ষ আর বড় করে অঙ্ক, নিচে "যে অঙ্ক দেখছেন, ঠিক সেটাই অনুমোদন হবে" (সার্ভার অঙ্ক মেলায় — ৪১২);
/// ইচ্ছা হলে মন্তব্য; শেষ ধাপ হলে "অনুমোদন দিলেই খাতায় বসবে"; আর অঙ্ক সীমার ([confirmAbove], ডিফল্ট ৳১ লাখ) বেশি হলে আঙুলের
/// ছাপ বা ফোনের PIN — খোলা ফোন হাতে পেলেও কেউ বড় অঙ্কে অনুমোদন দিতে পারে না (SAP Fiori, Workday-র একই নিয়ম)।
///
/// <p>⛔ ফোনে কোনো তালাই বসানো না থাকলে বড় অঙ্কে "হ্যাঁ" কাজ করে না — যাচাই করা যায় না, তাই বন্ধ (fail-closed), কারণসহ।
class ApprovalApproveSheet extends StatefulWidget {
  const ApprovalApproveSheet({
    super.key,
    required this.paper,
    required this.lastStep,
    required this.confirmAbove,
    this.deviceCheck,
  });

  final ApprovalRecord paper;
  final bool lastStep;
  final double confirmAbove;

  /// ফোনের তালা দিয়ে নিশ্চিত — `null` ফেরত মানে ফোনে তালা নেই; না দিলে [LocalAuthentication]
  final Future<bool?> Function()? deviceCheck;

  /// মন্তব্য ফেরত দেয় (ফাঁকা হতে পারে); না বললে `null`
  static Future<String?> show(
    BuildContext context, {
    required ApprovalRecord paper,
    required bool lastStep,
    double confirmAbove = 100000,
    Future<bool?> Function()? deviceCheck,
  }) =>
      showModalBottomSheet<String>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => ApprovalApproveSheet(paper: paper, lastStep: lastStep, confirmAbove: confirmAbove, deviceCheck: deviceCheck),
      );

  @override
  State<ApprovalApproveSheet> createState() => _ApprovalApproveSheetState();
}

class _ApprovalApproveSheetState extends State<ApprovalApproveSheet> {
  final _remarks = TextEditingController();
  String? _refusal;
  bool _checking = false;

  bool get _needsDevice => (widget.paper.amount ?? 0) > widget.confirmAbove;

  @override
  void dispose() {
    _remarks.dispose();
    super.dispose();
  }

  static Future<bool?> _device() async {
    final auth = LocalAuthentication();
    if (!await auth.isDeviceSupported()) return null;
    return auth.authenticate(
      localizedReason: AppLocale.l10n.approveSheetBioReason,
      authMessages: [AndroidAuthMessages(signInTitle: AppLocale.l10n.approveSheetBioReason, cancelButton: AppLocale.l10n.approveSheetBack)],
    );
  }

  String _limit() {
    final lakh = widget.confirmAbove / 100000;
    if (widget.confirmAbove >= 100000) {
      return context.l10n.approvalsLakh(lakh == lakh.roundToDouble() ? lakh.toStringAsFixed(0) : lakh.toStringAsFixed(2));
    }
    return Money.taka(widget.confirmAbove);
  }

  Future<void> _yes() async {
    if (_needsDevice) {
      setState(() {
        _checking = true;
        _refusal = null;
      });
      bool? passed;
      try {
        passed = await (widget.deviceCheck ?? _device)();
      } catch (_) {
        passed = false;
      }
      if (!mounted) return;
      setState(() => _checking = false);
      if (passed != true) {
        setState(() => _refusal = passed == null ? context.l10n.approveSheetNoLock : context.l10n.approveSheetBioFailed);
        return;
      }
    }
    if (mounted) Navigator.of(context).pop(_remarks.text);
  }

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    final paper = widget.paper;
    final what = [paper.documentTypeLabel, if (paper.documentNo != null) paper.documentNo!, if (paper.party != null) paper.party!].join(' · ');
    final note = TextStyle(fontSize: 12.5, color: palette.onSurfaceMuted);

    return Padding(
      key: const ValueKey('approve-sheet'),
      padding: EdgeInsets.fromLTRB(18, 0, 18, 18 + MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(words.approveSheetTitle, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: AppSpacing.sm),
            Container(
              key: const ValueKey('approve-sheet-amount'),
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: palette.successSurface,
                border: Border.all(color: palette.success),
                borderRadius: BorderRadius.circular(14),
              ),
              child: Column(children: [
                Text(what, textAlign: TextAlign.center, style: const TextStyle(fontSize: 13.5)),
                if (paper.amountText != null)
                  Text(paper.amountText!, style: TextStyle(fontSize: 28, fontWeight: FontWeight.w700, color: palette.success)),
                Text(words.approveSheetExact, textAlign: TextAlign.center, style: note),
              ]),
            ),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              key: const ValueKey('approve-sheet-remarks'),
              controller: _remarks,
              maxLines: 2,
              decoration: InputDecoration(hintText: words.approveSheetRemarks, border: const OutlineInputBorder()),
            ),
            if (_needsDevice) ...[
              const SizedBox(height: AppSpacing.sm),
              Container(
                key: const ValueKey('approve-sheet-bio'),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                decoration: BoxDecoration(color: palette.surfaceMuted, borderRadius: BorderRadius.circular(12)),
                child: Row(children: [
                  const Icon(Icons.fingerprint, size: 28),
                  const SizedBox(width: 10),
                  Expanded(child: Text(words.approveSheetBio(_limit()), style: const TextStyle(fontSize: 13))),
                ]),
              ),
            ],
            if (widget.lastStep) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(words.approveSheetLast, key: const ValueKey('approve-sheet-last'), style: note),
            ],
            if (_refusal != null) ...[
              const SizedBox(height: AppSpacing.sm),
              Text(_refusal!, key: const ValueKey('approve-sheet-refusal'), style: TextStyle(color: palette.danger, fontWeight: FontWeight.w600)),
            ],
            const SizedBox(height: AppSpacing.md),
            Row(children: [
              Expanded(
                child: OutlinedButton(
                  key: const ValueKey('approve-sheet-back'),
                  onPressed: () => Navigator.of(context).pop(),
                  child: Text(words.approveSheetBack, textAlign: TextAlign.center),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                flex: 3,
                child: FilledButton(
                  key: const ValueKey('approve-sheet-yes'),
                  onPressed: _checking ? null : _yes,
                  style: FilledButton.styleFrom(backgroundColor: const Color(0xFF15803D), foregroundColor: Colors.white),
                  child: Text(words.approveSheetYes, textAlign: TextAlign.center),
                ),
              ),
            ]),
          ],
        ),
      ),
    );
  }
}
