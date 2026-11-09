import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ "প্রত্যাখ্যান" চাপলে নিচ থেকে ওঠা শিট — মালিকের নমুনা "অনুমোদনের নতুন ইনবক্স" v4, ছবি ৫ ("ok", ৮ নভেম্বর ২০২৬)।
///
/// <p>এক চাপে কারণ বাছা (সার্ভারের [[ApprovalDecision::REASONS]] — রিপোর্টে গোনা হয়), সাথে লেখা — লেখা বাধ্যতামূলক, কারণ যিনি
/// চেয়েছিলেন তার কাছে যায়। ⓘ নিচে মনে করানো: প্রত্যাখ্যান ফেরানো যায় না; শুধু ঠিক করাতে চাইলে "ফেরত পাঠান"। "হ্যাঁ"-র পরে
/// আলাদা শেষ প্রশ্ন আসে ([[confirmFinalReject]]) — আগের নিয়ম অক্ষত।
///
/// <p>ফেরত: (কারণের কোড, লেখা); না বললে `null`। ⛔ কোড না বেছে বা লেখা ছাড়া "প্রত্যাখ্যান করুন" চাপা যায় না।
class ApprovalRejectSheet extends StatefulWidget {
  const ApprovalRejectSheet({super.key, required this.what});

  final String what;

  /// সার্ভারের কারণের কোড, পর্দার ক্রমে — `unstated` (পুরনো সারি) এখানে নেই
  static const codes = ['price', 'document', 'credit', 'budget', 'policy', 'other'];

  static Future<(String, String)?> show(BuildContext context, {required String what}) => showModalBottomSheet<(String, String)>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => ApprovalRejectSheet(what: what),
      );

  @override
  State<ApprovalRejectSheet> createState() => _ApprovalRejectSheetState();
}

class _ApprovalRejectSheetState extends State<ApprovalRejectSheet> {
  final _note = TextEditingController();
  String? _code;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  String _label(BuildContext context, String code) {
    final words = context.l10n;
    return switch (code) {
      'price' => words.rejectReasonPrice,
      'document' => words.rejectReasonDocument,
      'credit' => words.rejectReasonCredit,
      'budget' => words.rejectReasonBudget,
      'policy' => words.rejectReasonPolicy,
      _ => words.rejectReasonOther,
    };
  }

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final palette = context.palette;
    final note = TextStyle(fontSize: 12.5, color: palette.onSurfaceMuted);
    final ready = _code != null && _note.text.trim().isNotEmpty;

    return Padding(
      key: const ValueKey('reject-sheet'),
      padding: EdgeInsets.fromLTRB(18, 0, 18, 18 + MediaQuery.viewInsetsOf(context).bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(words.rejectSheetTitle, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700, color: palette.danger)),
            const SizedBox(height: AppSpacing.xs),
            Text(widget.what, style: const TextStyle(fontSize: 13.5)),
            const SizedBox(height: AppSpacing.xs),
            Text(words.rejectSheetHint, style: note),
            const SizedBox(height: AppSpacing.sm),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final code in ApprovalRejectSheet.codes)
                  ChoiceChip(
                    key: ValueKey('reject-reason-$code'),
                    label: Text(_label(context, code)),
                    selected: _code == code,
                    selectedColor: palette.dangerSurface,
                    labelStyle: TextStyle(color: _code == code ? palette.danger : palette.onSurface, fontWeight: _code == code ? FontWeight.w600 : FontWeight.w400),
                    onSelected: (_) => setState(() => _code = code),
                  ),
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            TextField(
              key: const ValueKey('reject-sheet-note'),
              controller: _note,
              maxLines: 2,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(hintText: words.rejectSheetNote, border: const OutlineInputBorder()),
            ),
            const SizedBox(height: AppSpacing.sm),
            Text(words.rejectSheetFinal, style: note),
            const SizedBox(height: AppSpacing.md),
            Row(children: [
              Expanded(
                child: OutlinedButton(
                  key: const ValueKey('reject-sheet-back'),
                  onPressed: () => Navigator.of(context).pop(),
                  child: Text(words.approveSheetBack, textAlign: TextAlign.center),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              Expanded(
                flex: 3,
                child: FilledButton(
                  key: const ValueKey('reject-sheet-yes'),
                  onPressed: ready ? () => Navigator.of(context).pop((_code!, _note.text.trim())) : null,
                  style: FilledButton.styleFrom(backgroundColor: palette.danger, foregroundColor: Colors.white), // dark-ok: white on the danger-filled button
                  child: Text(words.rejectSheetYes, textAlign: TextAlign.center),
                ),
              ),
            ]),
          ],
        ),
      ),
    );
  }
}
