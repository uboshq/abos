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
  }) : grandLabel = null;

  /// ⭐ কেবল সর্বমোটের এক লাইন — পাতা ভাগ নেই এমন তালিকার জন্য, যার মোট সার্ভার কষে দেয় (যেমন প্রিন্সিপালের কমিশন; cb,
  /// ২০ অক্টোবর ২০২৬)। [allSum] সার্ভারের দশমিক-লেখাও হতে পারে ("6130.2500") — ⛔ অ্যাপ যোগ করে না, কেবল সাজায়।
  /// [label] পুরো বাঁয়ের লেখা — "সর্বমোট · দেনা · 3 জন"।
  const TotalsBar.grand({super.key, required String label, required this.allSum})
      : grandLabel = label,
        pageCount = 0,
        pageSum = 0,
        allCount = 0;

  final int pageCount;
  final num pageSum;
  final int allCount;

  /// সংখ্যা বা সার্ভারের দশমিক-লেখা — [Money.taka] দুটোই নেয়
  final Object allSum;

  /// [TotalsBar.grand]-এর লেখা; null হলে দুই লাইন ("এই পাতা" আর "সর্বমোট")
  final String? grandLabel;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    Widget line(String label, Object sum, bool grand, Key key) => Padding(
          key: key,
          padding: const EdgeInsets.symmetric(vertical: 2),
          // ⓘ পাশাপাশি না ধরলে (২০০% অক্ষর, ছোট ফোন) টাকা নিচের লাইনে ডানে নামে — কাটা পড়ে না, উপচেও পড়ে না
          child: OverflowBar(
            alignment: MainAxisAlignment.spaceBetween,
            overflowAlignment: OverflowBarAlignment.end,
            children: [
              Text(label,
                  style: TextStyle(fontSize: grand ? 15 : 13, fontWeight: grand ? FontWeight.w700 : FontWeight.w500,
                      color: grand ? cs.onSurface : cs.onSurfaceVariant)),
              Text(Money.taka(sum),
                  style: TextStyle(fontSize: grand ? 17 : 14, fontWeight: FontWeight.w700, color: cs.onSurface)),
            ],
          ),
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
            if (grandLabel case final label?)
              line(label, allSum, true, const ValueKey('totals-all'))
            else ...[
              line('এই পাতা · $pageCountটি', pageSum, false, const ValueKey('totals-page')),
              line('সর্বমোট · $allCountটি', allSum, true, const ValueKey('totals-all')),
            ],
          ]),
        ),
      ),
    );
  }
}
