import 'dart:ui' show PathMetric;

import 'package:flutter/material.dart';

import '../theme/app_palette.dart';
import 'paper_state.dart';
import 'paper_state_icons.dart';

/// ⭐ কাগজের অবস্থার চিপ — অ্যাপের একটাই (মালিকের নকশা ৩, অংশ ৫, ২০ অক্টোবর ২০২৬; Material 3 + SAP Fiori + WCAG 2.2 AA)।
///
/// <p>আইকন, রং আর বাংলা লেখা তিনটাই একসাথে; রং আলো/অন্ধকার দেখে [PaperStateSpec] থেকে। TalkBack বাংলায় বলে
/// ("অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে")। "পোস্ট হয়নি" চাপা যায় (কমপক্ষে ৪৮dp): চাপলে বাংলায় কারণ — [reason] (সার্ভারের
/// লেখা), বা [onReasonTap] দিলে সেটাই। বড় লেখায় (২০০%) লেখা ভাঙে না, নিচের লাইনে নামে।
///
/// <p>দুই মাপ: কাগজের পাতায় পুরো লেখা ("পোস্ট হয়েছে · খাতায় উঠেছে"); তালিকার সারিতে [AppStatusChip.compact] — ছোট লেখা
/// ("পোস্ট হয়েছে", "2-এর 1"), মালিকের অনুমোদিত পুরো-পাতার নমুনার মাপে (২০ অক্টোবর ২০২৬)। TalkBack দুটোতেই পুরো বাক্য বলে।
class AppStatusChip extends StatelessWidget {
  const AppStatusChip(
      {super.key,
      required this.state,
      this.kind = PaperKind.money,
      this.level,
      this.levels,
      this.reason,
      this.onReasonTap,
      this.dense = false});

  /// তালিকার সারির ছোট চিপ — বাঁয়ে নম্বর · পক্ষ, ডানে এটা, এক লাইনে
  const AppStatusChip.compact(
      {super.key, required this.state, this.kind = PaperKind.money, this.level, this.levels, this.reason, this.onReasonTap})
      : dense = true;

  final PaperState state;

  /// কাগজের ধরন — কথা বদলায় ("মজুদে উঠেছে", "টাকার অপেক্ষায়"), অবস্থা আর রং একই
  final PaperKind kind;

  /// ছোট মাপ, ছোট লেখা
  final bool dense;

  /// সইয়ের কোন ধাপ এখন (১ থেকে) — কেবল [PaperState.waiting]-এ, ডেটা থেকে
  final int? level;

  /// মোট কত ধাপ
  final int? levels;

  /// কেন পোস্ট হয়নি — সার্ভারের লেখা; কেবল [PaperState.notPosted]-এ
  final String? reason;

  /// চাপলে নিজের কাজ (যেমন পাতা খোলা); না দিলে কারণের নিচের পাতা খোলে
  final VoidCallback? onReasonTap;

  /// সার্ভারের অবস্থা ([PaperState.fromServer]) চিনলে চিপ; না চিনলে সার্ভারের নিজের লেখা, আগের মতো — আন্দাজে
  /// অন্য অবস্থা নয়
  static Widget forServer(String state,
          {Key? key,
          required String fallbackLabel,
          Color? fallbackColour,
          int? level,
          int? levels,
          String? reason,
          PaperKind kind = PaperKind.money,
          bool dense = false}) =>
      switch (PaperState.fromServer(state)) {
        final s? =>
          AppStatusChip(key: key, state: s, kind: kind, level: level, levels: levels, reason: reason, dense: dense),
        null => Text(fallbackLabel, key: key, style: TextStyle(fontWeight: FontWeight.w600, color: fallbackColour)),
      };

  /// চাপলে যে পাতা খোলে — কারণ বাংলায়, বন্ধের বোতাম
  static Future<void> showReason(BuildContext context, String? reason) {
    final spec = PaperState.notPosted.spec;
    final text = (reason ?? '').trim().isEmpty ? spec.reasonMissingBn! : reason!.trim();
    return showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (sheet) {
        final c = spec.colours.of(Theme.of(sheet).brightness);
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(24, 0, 24, 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  Icon(paperStateIcon(PaperState.notPosted), color: c.foreground, size: 24),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(spec.reasonTitleBn!,
                        key: const ValueKey('paper-reason-title'), style: Theme.of(sheet).textTheme.titleMedium),
                  ),
                ]),
                const SizedBox(height: 12),
                Text(text, key: const ValueKey('paper-reason-text'), style: Theme.of(sheet).textTheme.bodyLarge),
                const SizedBox(height: 16),
                Align(
                  alignment: AlignmentDirectional.centerEnd,
                  child: FilledButton.tonal(
                    key: const ValueKey('paper-reason-close'),
                    onPressed: () => Navigator.of(sheet).pop(),
                    child: Text(spec.reasonCloseBn!),
                  ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final spec = state.spec;
    final colours = spec.colours.of(Theme.of(context).brightness);
    final base = Theme.of(context).textTheme.labelLarge ?? const TextStyle();
    final label = dense
        ? spec.shortLabel(level: level, levels: levels, kind: kind)
        : spec.label(level: level, levels: levels, kind: kind);
    // পাতায়: Material 3 চিপ (কোণ 8, উচ্চতা 32, ধার); সারিতে: নমুনার `.st` (কোণ 12, প্যাড 9/2, লেখা 12, ধার নেই)
    final radius = BorderRadius.all(Radius.circular(dense ? 12 : 8));

    Widget chip = Container(
      constraints: BoxConstraints(minHeight: dense ? 22 : 32),
      padding: dense ? const EdgeInsets.symmetric(horizontal: 9, vertical: 2) : const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
        color: colours.background,
        borderRadius: radius,
        border: spec.dashed || dense ? null : Border.all(color: colours.border),
      ),
      child: Text.rich(
        TextSpan(children: [
          WidgetSpan(
            alignment: PlaceholderAlignment.middle,
            child: Padding(
              padding: EdgeInsetsDirectional.only(end: dense ? 4 : 6),
              child: Icon(paperStateIcon(state), size: dense ? 16 : 18, color: colours.foreground),
            ),
          ),
          TextSpan(text: label),
        ]),
        softWrap: true,
        style: base.copyWith(
            fontSize: dense ? 12 : 13,
            fontWeight: dense ? FontWeight.w600 : FontWeight.w700,
            height: 1.3,
            color: colours.foreground),
      ),
    );
    if (spec.dashed) {
      chip = CustomPaint(foregroundPainter: _DashedBorder(colours.border, radius.topLeft.x), child: chip);
    }

    final speak = spec.semantics(level: level, levels: levels, kind: kind);
    if (!spec.tappable) {
      return Semantics(container: true, label: speak, excludeSemantics: true, child: chip);
    }

    void tap() => onReasonTap != null ? onReasonTap!() : showReason(context, reason);
    return Semantics(
      container: true,
      button: true,
      label: speak,
      hint: spec.semanticsHintBn,
      onTap: tap,
      excludeSemantics: true,
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          key: ValueKey('paper-state-${state.key}-tap'),
          onTap: tap,
          borderRadius: radius,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 48, minWidth: 48),
            child: Align(widthFactor: 1, heightFactor: 1, child: chip),
          ),
        ),
      ),
    );
  }
}

/// "পোস্ট হয়নি"-এর ভাঙা ধার — অন্য লালের (বাতিল) থেকে আলাদা দেখাতে, রং ছাড়াও
class _DashedBorder extends CustomPainter {
  _DashedBorder(this.colour, this.corner);

  final Color colour;
  final double corner;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = colour
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5;
    final path = Path()
      ..addRRect(RRect.fromRectAndRadius(
          Rect.fromLTWH(0.75, 0.75, size.width - 1.5, size.height - 1.5), Radius.circular(corner)));
    for (final PathMetric m in path.computeMetrics()) {
      var d = 0.0;
      while (d < m.length) {
        canvas.drawPath(m.extractPath(d, d + 4), paint);
        d += 7;
      }
    }
  }

  @override
  bool shouldRepaint(_DashedBorder old) => old.colour != colour || old.corner != corner;
}

/// কাগজের পাতার "অবস্থা" সারি — বাঁয়ে নাম, ডানে চিপ (পাতার অন্য তথ্যের সারির মতো)
class PaperStateFact extends StatelessWidget {
  const PaperStateFact({super.key, required this.chip, this.label = 'অবস্থা'});

  final String label;
  final Widget chip;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Expanded(child: Text(label, style: TextStyle(color: context.palette.onSurfaceMuted))),
            const SizedBox(width: 8),
            Flexible(child: Align(alignment: AlignmentDirectional.centerEnd, child: chip)),
          ],
        ),
      );
}
