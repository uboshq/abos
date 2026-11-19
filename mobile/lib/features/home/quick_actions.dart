import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/expressive.dart';
import '../../core/theme/glass.dart';

/// একটা দ্রুত কাজ — আইকন, `/home/<path>`, নাম
@immutable
class QuickAction {
  const QuickAction({required this.icon, required this.path, required this.label});

  final IconData icon;
  final String path;
  final String label;
}

/// ⭐ দ্রুত কাজের পাতা — ≡ আর Material 3-এর ＋ (মালিকের জমার নমুনা, ২০ নভেম্বর ২০২৬ "ok"): তিন কলামের ঘর, প্রথমটা (টাকা জমার
/// খবর) ভরা রঙে। রূপ ধরে সাজ: ABOS — নমুনার নীল-হালকা ঘর; Liquid Glass — কাচের ঘর; Material 3 — আলাদা আলাদা আকারের ঘর।
/// বাছা কাজ ফেরত দেয়, না বাছলে null।
Future<QuickAction?> showQuickActions(BuildContext context, List<QuickAction> actions) {
  final look = AppLooks.instance.look.value;
  return showModalBottomSheet<QuickAction>(
    context: context,
    showDragHandle: true,
    isScrollControlled: true,
    backgroundColor: look == AppLook.glass ? const Color(0xE6161C33) : null,
    builder: (sheet) => SafeArea(
      child: SingleChildScrollView(
        key: const ValueKey('quick-actions'),
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(sheet.l10n.lookQuickActions,
              style: Theme.of(sheet).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),
          LayoutBuilder(builder: (context, box) {
            const gap = 8.0;
            final width = (box.maxWidth - 2 * gap) / 3;
            return Wrap(spacing: gap, runSpacing: gap, children: [
              for (final (i, a) in actions.indexed)
                SizedBox(
                  width: width,
                  child: _QuickTile(
                    key: ValueKey('quick-${a.path}'),
                    action: a,
                    index: i,
                    look: look,
                    onTap: () => Navigator.of(sheet).pop(a),
                  ),
                ),
            ]);
          }),
        ]),
      ),
    ),
  );
}

class _QuickTile extends StatelessWidget {
  const _QuickTile({super.key, required this.action, required this.index, required this.look, required this.onTap});

  final QuickAction action;
  final int index;
  final AppLook look;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final hot = index == 0;
    final body = ConstrainedBox(
      // ⓘ বড় অক্ষরে নাম দুই লাইনে নামে, ঘর নিজে বাড়ে — উচ্চতা বাঁধা নয়
      constraints: const BoxConstraints(minHeight: 84),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
        child: Column(mainAxisSize: MainAxisSize.min, mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(action.icon, size: 28),
          const SizedBox(height: 6),
          Text(action.label,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12.5, height: 1.25)),
        ]),
      ),
    );
    final tap = Semantics(
      button: true,
      label: action.label,
      excludeSemantics: true,
      child: InkWell(onTap: onTap, borderRadius: BorderRadius.circular(18), child: Center(child: body)),
    );
    switch (look) {
      case AppLook.glass:
        return GlassPanel(
          radius: 20,
          child: hot ? ColoredBox(color: const Color(0x4778DCFF), child: tap) : tap,
        );
      case AppLook.expressive:
        final (bg, ink, shape) = hot
            ? (Expressive.primary, Colors.white, Expressive.tiles[0].$3) // dark-ok: নমুনার বেগুনি ঘরে সাদা
            : (Expressive.tiles[index % 4].$1, Expressive.tiles[index % 4].$2, Expressive.tiles[index % 4].$3);
        return Material(
          color: bg,
          borderRadius: shape,
          child: DefaultTextStyle.merge(
              style: TextStyle(color: ink),
              child: IconTheme.merge(data: IconThemeData(color: ink), child: tap)),
        );
      case AppLook.abos:
        final bg = hot ? cs.tertiary : cs.primaryContainer;
        final ink = hot ? cs.onTertiary : cs.onPrimaryContainer;
        return Material(
          color: bg,
          borderRadius: BorderRadius.circular(14),
          child: DefaultTextStyle.merge(
              style: TextStyle(color: ink),
              child: IconTheme.merge(data: IconThemeData(color: ink), child: tap)),
        );
    }
  }
}
