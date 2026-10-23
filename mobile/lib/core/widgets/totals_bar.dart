import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../locale/l10n.dart';
import '../records/money.dart';
import '../theme/app_spacing.dart';

/// ⭐ মোটের সময়কাল — প্রতিটা মোটের পাশে কোন তারিখের মোট, পর্দার **চালু** তারিখের ছাঁকনি থেকে (fe-র মোটের নিরীক্ষা, ২৪ অক্টোবর
/// ২০২৬; ০.৪.৪৮): "আজ", "এ মাস", বাছা পরিসর "০১/১০/২০২৬ – ১৫/১০/২০২৬", বা তারিখের ছাঁকনি না থাকলে "সব তারিখ"।
/// ⛔ নিজে কোনো তারিখ বানায় না — পর্দা যা দিয়ে সার্ভারকে চায়, ঠিক সেটাই দেয়।
class TotalsPeriod {
  /// পর্দার তারিখের পরিসর — [today] পরীক্ষার জন্য, না দিলে আজ
  const TotalsPeriod.range(DateTime this.from, DateTime this.to, {this.today});

  /// তালিকায় তারিখের ছাঁকনি নেই — মোট সব তারিখের
  const TotalsPeriod.allDates()
      : from = null,
        to = null,
        today = null;

  final DateTime? from;
  final DateTime? to;
  final DateTime? today;

  String label(BuildContext context) {
    final l = context.l10n;
    final start = from, end = to;
    if (start == null || end == null) return l.totalsPeriodAllDates;
    final now = today ?? DateTime.now();
    final day = DateTime(now.year, now.month, now.day);
    DateTime dayOf(DateTime d) => DateTime(d.year, d.month, d.day);
    final a = dayOf(start), b = dayOf(end);
    if (a == day && b == day) return l.booksRangeToday;
    if (a == DateTime(now.year, now.month) && b == day) return l.booksRangeMonth;
    final f = DateFormat('dd/MM/yyyy');
    return a == b ? f.format(a) : l.totalsPeriodRange(f.format(a), f.format(b));
  }
}

/// মোটের পাশের সময়কালের ছোট লেখা — [TotalsBar] আর তালিকার মাথার মোটের পট্টি দুটোই এটা আঁকে, যাতে সব পর্দায় এক রূপ
class TotalsPeriodLabel extends StatelessWidget {
  const TotalsPeriodLabel(this.period, {super.key = const ValueKey('totals-period')});

  final TotalsPeriod period;

  @override
  Widget build(BuildContext context) {
    final text = period.label(context);
    final colour = Theme.of(context).colorScheme.onSurfaceVariant;
    return Semantics(
      label: context.l10n.totalsPeriodSpoken(text),
      excludeSemantics: true,
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(Icons.event_outlined, size: 14, color: colour),
        const SizedBox(width: 4),
        Flexible(child: Text(text, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w500, color: colour))),
      ]),
    );
  }
}

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
    this.period,
  }) : grandLabel = null;

  /// ⭐ কেবল সর্বমোটের এক লাইন — পাতা ভাগ নেই এমন তালিকার জন্য, যার মোট সার্ভার কষে দেয় (যেমন প্রিন্সিপালের কমিশন; cb,
  /// ২০ অক্টোবর ২০২৬)। [allSum] সার্ভারের দশমিক-লেখাও হতে পারে ("6130.2500") — ⛔ অ্যাপ যোগ করে না, কেবল সাজায়।
  /// [label] পুরো বাঁয়ের লেখা — "সর্বমোট · দেনা · 3 জন"।
  const TotalsBar.grand({super.key, required String label, required this.allSum, this.period})
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

  /// ⭐ মোটের সময়কাল — পট্টির মাথায় ([TotalsPeriodLabel]); null কেবল যে তালিকা তারিখের নয় (যেমন আজকের বকেয়া)
  final TotalsPeriod? period;

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
            if (period case final p?) Align(alignment: AlignmentDirectional.centerStart, child: TotalsPeriodLabel(p)),
            if (grandLabel case final label?)
              line(label, allSum, true, const ValueKey('totals-all'))
            else ...[
              line(context.l10n.totalsThisPage('$pageCount'), pageSum, false, const ValueKey('totals-page')),
              line(context.l10n.totalsAll('$allCount'), allSum, true, const ValueKey('totals-all')),
            ],
          ]),
        ),
      ),
    );
  }
}
