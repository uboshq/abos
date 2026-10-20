import 'package:flutter/material.dart';

import '../../core/theme/app_palette.dart';

/// ⓘ a6-এর `AppStatusChip` (`lib/core/status/`) আসার আগের স্থান-রক্ষক — একই নাম, একই গড়ন, তাই পরে কেবল import বদলায়
/// (সমন্বয়ক, ২০ অক্টোবর ২০২৬)। সুরের নাম a6-এর `paper_states.json`-এর পাঁচ ভূমিকা।
enum AppStatusTone { neutral, critical, information, positive, negative }

/// অবস্থার চিপ — আইকন, রং আর বাংলা লেখা একসাথে (WCAG 2.2 SC 1.4.1); TalkBack পড়ে "অবস্থা: …"।
/// রং কেবল [AppPalette] থেকে, তাই অন্ধকারেও পড়া যায়। চাপা যায় না — সারিটাই চাপার জায়গা।
class AppStatusChip extends StatelessWidget {
  const AppStatusChip(this.label, this.tone, {super.key, this.icon, this.semanticsLabel});

  final String label;
  final AppStatusTone tone;
  final IconData? icon;

  /// না দিলে "অবস্থা: [label]"
  final String? semanticsLabel;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    final cs = Theme.of(context).colorScheme;
    final (Color bg, Color fg) = switch (tone) {
      AppStatusTone.neutral => (p.pendingSurface, p.pending),
      AppStatusTone.critical => (p.warningSurface, p.warning),
      AppStatusTone.information => (cs.primaryContainer, cs.onPrimaryContainer),
      AppStatusTone.positive => (p.successSurface, p.success),
      AppStatusTone.negative => (p.dangerSurface, p.danger),
    };
    return Semantics(
      label: semanticsLabel ?? 'অবস্থা: $label',
      excludeSemantics: true,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
        decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(12)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 14, color: fg), const SizedBox(width: 4)],
          Flexible(
            child: Text(label, style: TextStyle(color: fg, fontSize: 12, fontWeight: FontWeight.w600)),
          ),
        ]),
      ),
    );
  }
}
