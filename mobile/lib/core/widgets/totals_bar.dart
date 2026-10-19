import 'package:flutter/material.dart';

import '../records/money.dart';
import '../theme/app_spacing.dart';

/// ⭐ তালিকার নিচের স্থির পট্টি — "এই পাতা" আর "সর্বমোট" আলাদা, সংখ্যা আর টাকা দুটোই; ওয়েবের তালিকার একই নিয়ম
/// (মোবাইলের নকশা ৩, পাতা ৩; মালিকের "হ্যাঁ", ১৯ অক্টোবর ২০২৬)। টাকা [Money.taka] — ইংরেজি অঙ্ক, লাখের কমা।
///
/// <p>`Scaffold.bottomNavigationBar`-এ বসে, তাই তালিকা টানলেও সর্বমোট চোখের সামনে থাকে।
class TotalsBar extends StatelessWidget {
  const TotalsBar({
    super.key,
    required this.pageCount,
    required this.pageSum,
    required this.allCount,
    required this.allSum,
  });

  final int pageCount;
  final num pageSum;
  final int allCount;
  final num allSum;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    Widget line(String label, num sum, bool grand, Key key) => Padding(
          key: key,
          padding: const EdgeInsets.symmetric(vertical: 2),
          child: Row(children: [
            Expanded(
              child: Text(label,
                  style: TextStyle(fontSize: grand ? 15 : 13, fontWeight: grand ? FontWeight.w700 : FontWeight.w500,
                      color: grand ? cs.onSurface : cs.onSurfaceVariant)),
            ),
            Text(Money.taka(sum),
                style: TextStyle(fontSize: grand ? 17 : 14, fontWeight: FontWeight.w700, color: cs.onSurface)),
          ]),
        );
    return Material(
      key: const ValueKey('totals-bar'),
      color: cs.surfaceContainer,
      child: SafeArea(
        top: false,
        child: Container(
          decoration: BoxDecoration(border: Border(top: BorderSide(color: cs.outlineVariant))),
          padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.sm),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            line('এই পাতা · $pageCountটি', pageSum, false, const ValueKey('totals-page')),
            line('সর্বমোট · $allCountটি', allSum, true, const ValueKey('totals-all')),
          ]),
        ),
      ),
    );
  }
}
