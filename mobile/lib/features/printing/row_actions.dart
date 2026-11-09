import 'package:flutter/material.dart';

import 'document_actions_sheet.dart';
import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ তালিকার সারিতে "অ্যাকশন ▾" — ওয়েবের তালিকার একই মেনু ([[x-ui.row-actions]]; মালিক, ৮ নভেম্বর ২০২৬: *"অ্যাপস এর যে লিস্টগুলো
/// আছে তাতেও অ্যাকশন বাটন দিতে হবে"*)। চাপলে নিচ থেকে শিট: মাথায় নম্বর আর অবস্থা, তারপর তিন ভাগ — দেখা · ছাপা ও পাঠানো · কাজ।
/// ⓘ যা করা যায় না তা লুকানো নয়, ধূসর আর নিচে কারণ (ওয়েবের একই নিয়ম: "নিশ্চিত হওয়া কাগজ বদলানো যায় না …")।
class RowAction {
  const RowAction({required this.key, required this.label, required this.icon, this.onTap, this.why, this.danger = false});

  /// টেস্টের হাতল — `row-action-<key>`
  final String key;
  final String label;
  final IconData icon;

  /// `null` = করা যায় না; তখন [why] কারণ বলে
  final VoidCallback? onTap;
  final String? why;
  final bool danger;
}

/// সারির ডানে ছোট বোতাম — "অ্যাকশন ▾"
class RowActionsButton extends StatelessWidget {
  const RowActionsButton({
    super.key,
    required this.number,
    required this.state,
    required this.onView,
    this.paperType,
    this.paperId,
    this.paperTitle,
    this.work = const [],
  });

  final String number;
  final String? state;
  final VoidCallback onView;

  /// ছাপা ও পাঠানো — সার্ভারের নিজের PDF ([[DocumentActionsSheet]]); ধরন না থাকলে ভাগটাই নেই
  final String? paperType;
  final String? paperId;
  final String? paperTitle;

  /// কাগজের নিজের কাজ (সম্পাদনা, টাকা নিন, বাতিল …)
  final List<RowAction> work;

  @override
  Widget build(BuildContext context) => OutlinedButton(
        key: ValueKey('row-actions-$number'),
        style: OutlinedButton.styleFrom(
          // ⛔ চাপার জায়গা ৪৮×৪৮ (নকশার নিয়ম ০.৪.৩৮ §২) — আগে compact ৪০ উঁচু ছিল; ডিও তালিকার নকশা-দাবি ধরল (৯ নভেম্বর ২০২৬)
          //   ⓘ পাশে আগের মতোই সরু (horizontal -2) — চওড়া হলে ৩২০dp ২০০%-এ নম্বর আর অঙ্ক দুই লাইনে ভাঙত
          visualDensity: const VisualDensity(horizontal: -2, vertical: 0),
          padding: const EdgeInsets.symmetric(horizontal: 10),
          minimumSize: const Size(48, 48),
          tapTargetSize: MaterialTapTargetSize.padded,
        ),
        onPressed: () => showRowActions(context, button: this),
        child: Text('${context.l10n.rowActionsButton} ▾', style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
      );
}

Future<void> showRowActions(BuildContext context, {required RowActionsButton button}) {
  final words = context.l10n;
  final hasPaper = button.paperType != null && button.paperId != null;
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (sheet) {
      final palette = sheet.palette;
      Widget head(String text) => Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, 2),
            child: Text(text, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: palette.onSurfaceMuted)),
          );
      Widget item(RowAction a) => ListTile(
            key: ValueKey('row-action-${a.key}'),
            dense: true,
            enabled: a.onTap != null,
            leading: Icon(a.icon, color: a.onTap == null ? palette.onSurfaceMuted : (a.danger ? palette.danger : null)),
            title: Text(a.label, style: TextStyle(color: a.onTap == null ? palette.onSurfaceMuted : (a.danger ? palette.danger : null))),
            subtitle: a.onTap == null && a.why != null ? Text(a.why!, key: ValueKey('row-action-why-${a.key}')) : null,
            onTap: a.onTap == null
                ? null
                : () {
                    Navigator.of(sheet).pop();
                    a.onTap!();
                  },
          );
      return SafeArea(
        child: SingleChildScrollView(
          key: const ValueKey('row-actions-sheet'),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.xs),
                child: Row(children: [
                  Expanded(child: Text(button.number, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700))),
                  if (button.state != null) Text(button.state!, style: TextStyle(fontSize: 12, color: palette.onSurfaceMuted)),
                ]),
              ),
              const Divider(height: 1),
              head(words.rowActionsLook),
              item(RowAction(key: 'view', label: words.rowActionsView, icon: Icons.visibility_outlined, onTap: button.onView)),
              if (hasPaper) ...[
                head(words.rowActionsPaper),
                item(RowAction(
                  key: 'print',
                  label: words.rowActionsPrintSend,
                  icon: Icons.print_outlined,
                  onTap: () => DocumentActionsSheet.show(context,
                      type: button.paperType!, id: button.paperId!, title: button.paperTitle ?? button.number, fileStem: button.number),
                )),
              ],
              if (button.work.isNotEmpty) ...[
                head(words.rowActionsWork),
                for (final a in button.work) item(a),
              ],
              const SizedBox(height: AppSpacing.sm),
            ],
          ),
        ),
      );
    },
  );
}
