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

  /// সময়কালের লেখার রূপ (রং ছাড়া) — [TotalsBar.widthsIn] একই রূপে মাপে
  static const style = TextStyle(fontSize: 12, fontWeight: FontWeight.w500);

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
        Flexible(child: Text(text, style: style.copyWith(color: colour))),
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
    this.rejectedCount = 0,
    this.rejectedSum = 0,
  }) : grandLabel = null;

  /// ⭐ কেবল সর্বমোটের এক লাইন — পাতা ভাগ নেই এমন তালিকার জন্য, যার মোট সার্ভার কষে দেয় (যেমন প্রিন্সিপালের কমিশন; cb,
  /// ২০ অক্টোবর ২০২৬)। [allSum] সার্ভারের দশমিক-লেখাও হতে পারে ("6130.2500") — ⛔ অ্যাপ যোগ করে না, কেবল সাজায়।
  /// [label] পুরো বাঁয়ের লেখা — "সর্বমোট · দেনা · 3 জন"।
  const TotalsBar.grand({super.key, required String label, required this.allSum, this.period})
      : grandLabel = label,
        pageCount = 0,
        pageSum = 0,
        allCount = 0,
        rejectedCount = 0,
        rejectedSum = 0;

  final int pageCount;
  final num pageSum;
  final int allCount;

  /// সংখ্যা বা সার্ভারের দশমিক-লেখা — [Money.taka] দুটোই নেয়
  final Object allSum;

  /// [TotalsBar.grand]-এর লেখা; null হলে দুই লাইন ("এই পাতা" আর "সর্বমোট")
  final String? grandLabel;

  /// ⭐ মোটের সময়কাল — প্রথম লাইনের শুরুতে ([TotalsPeriodLabel]), নিজের লাইন নয়; null কেবল যে তালিকা তারিখের নয় (যেমন আজকের বকেয়া)
  final TotalsPeriod? period;

  /// ⭐ দেখানো প্রত্যাখ্যাত সারি — মোটে নেই, তাই নিজের আলাদা লাইনে ("প্রত্যাখ্যাত, মোটে নেই · ২টি  ৳…"); fe-র নিয়ম, ২৪ অক্টোবর
  /// ২০২৬: মোটে কেবল পাকা কাগজ, বাদ পড়াগুলো দরকারে আলাদা লাইনে। ০ হলে লাইনটাই নেই।
  final int rejectedCount;
  final num rejectedSum;

  static TextStyle _labelStyle(bool grand) =>
      TextStyle(fontSize: grand ? 15 : 13, fontWeight: grand ? FontWeight.w700 : FontWeight.w500);

  static TextStyle _amountStyle(bool grand) => TextStyle(fontSize: grand ? 17 : 14, fontWeight: FontWeight.w700);

  /// লেখা আর টাকার মাঝে অন্তত এতটা ফাঁক
  static const double _gap = AppSpacing.sm;

  /// পট্টির লাইনগুলো — (লেখা, টাকা, সর্বমোট কি না, চাবি, মাথার সময়কাল)
  List<(String, Object, bool, Key, TotalsPeriod?)> _lines(BuildContext context) => [
        if (grandLabel case final label?)
          (label, allSum, true, const ValueKey('totals-all'), period)
        else ...[
          (context.l10n.totalsThisPage('$pageCount'), pageSum, false, const ValueKey('totals-page'), period),
          if (rejectedCount > 0)
            (context.l10n.totalsRejectedLeftOut('$rejectedCount'), rejectedSum, false, const ValueKey('totals-rejected'), null),
          (context.l10n.totalsAll('$allCount'), allSum, true, const ValueKey('totals-all'), null),
        ],
      ];

  /// ⭐ নিচের বারে বোতামের পাশে বসলে পট্টির কতটা চওড়া লাগে ([context] পট্টির ভেতরের লেখার রূপ দেয় — বারের Material-এর
  /// নিচে): [oneLine] — প্রতিটা লাইন এক লাইনেই (লেখা আর টাকা পাশাপাশি); [narrowest] — সবচেয়ে চওড়া একটানা টুকরো (একটা টাকা
  /// বা সময়কাল), এর কমে টাকা ভাঙত। দুটোতেই পট্টির বাঁয়ের ফাঁক ধরা।
  ({double oneLine, double narrowest}) widthsIn(BuildContext context) {
    final base = DefaultTextStyle.of(context).style;
    final scaler = MediaQuery.textScalerOf(context);
    final direction = Directionality.of(context);
    double measure(String text, TextStyle style) {
      final painter = TextPainter(
          text: TextSpan(text: text, style: base.merge(style)), textDirection: direction, textScaler: scaler, maxLines: 1)
        ..layout();
      final w = painter.width;
      painter.dispose();
      return w;
    }

    var oneLine = 0.0, narrowest = 0.0;
    for (final (label, sum, grand, _, lead) in _lines(context)) {
      final amount = measure(Money.taka(sum), _amountStyle(grand));
      final text = measure(label, _labelStyle(grand));
      var head = text;
      if (lead != null) {
        final chip = 14 + 4 + measure(lead.label(context), TotalsPeriodLabel.style);
        head += AppSpacing.sm + chip;
        narrowest = narrowest < chip ? chip : narrowest;
      }
      final words = label.split(' ').map((w) => measure(w, _labelStyle(grand)));
      for (final w in [amount, ...words]) {
        if (w > narrowest) narrowest = w;
      }
      final whole = head + _gap + amount;
      if (whole > oneLine) oneLine = whole;
    }
    // ⓘ মাপের গোলমালের জন্য ২dp বেশি
    return (oneLine: oneLine + AppSpacing.md + 2, narrowest: narrowest + AppSpacing.md + 2);
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    // ⭐ নিচের বারের ভেতরে ([TotalsBarHost]) — বোতাম পাশে, পট বার আঁকে; পট্টি কেবল লাইনগুলো
    final hosted = TotalsBarHost.hosts(context);
    Widget line(String label, Object sum, bool grand, Key key, {TotalsPeriod? lead}) {
      final text = Text(label, style: _labelStyle(grand).copyWith(color: grand ? cs.onSurface : cs.onSurfaceVariant));
      return Padding(
          key: key,
          padding: const EdgeInsets.symmetric(vertical: 2),
          // ⓘ পাশাপাশি না ধরলে (২০০% অক্ষর, ছোট ফোন) টাকা নিচের লাইনে ডানে নামে — কাটা পড়ে না, উপচেও পড়ে না
          child: OverflowBar(
            alignment: MainAxisAlignment.spaceBetween,
            overflowAlignment: OverflowBarAlignment.end,
            spacing: hosted ? _gap : 0,
            children: [
              // ⭐ সময়কাল প্রথম লাইনের শুরুতে, ছোট চিপের মতো — ⛔ নিজের লাইন নয়: পট্টি এক লাইন উঁচু হলে "＋ নতুন" বোতাম ওপরে উঠে
              // পাতার মোট ঢাকত (সমন্বয়ক, ২৪ অক্টোবর ২০২৬; totals_bar_keeps_its_height_test)
              if (lead == null)
                text
              else
                Wrap(spacing: AppSpacing.sm, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  TotalsPeriodLabel(lead),
                  text,
                ]),
              // ⭐ টাকা কখনো মাঝখানে ভাঙে না (softWrap: false) — জায়গা না হলে বার বোতাম সরিয়ে পুরো চওড়া দেয় ([ActionBottomBar])
              Text(Money.taka(sum), softWrap: false, style: _amountStyle(grand).copyWith(color: cs.onSurface)),
            ],
          ),
        );
    }

    final lines = Column(mainAxisSize: MainAxisSize.min, children: [
      for (final (label, sum, grand, key, lead) in _lines(context)) line(label, sum, grand, key, lead: lead),
    ]);
    if (hosted) {
      // ⓘ বারের ভেতরে: বাঁয়ে বারের ফাঁক, ডানে বোতামের দিকে ফাঁক বার নিজে রাখে
      return Padding(
        key: const ValueKey('totals-bar'),
        padding: const EdgeInsetsDirectional.fromSTEB(AppSpacing.md, AppSpacing.sm, 0, AppSpacing.sm),
        child: lines,
      );
    }
    return Material(
      key: const ValueKey('totals-bar'),
      color: cs.surfaceContainer,
      child: SafeArea(
        top: false,
        child: Container(
          decoration: BoxDecoration(border: Border(top: BorderSide(color: cs.outlineVariant))),
          padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.sm),
          child: lines,
        ),
      ),
    );
  }
}

/// ⭐ [TotalsBar]-কে নিচের বারের ভেতরে বসায় ([ActionBottomBar], বোতাম পাশে) — তখন পট্টি নিজের পট, দাগ আর SafeArea আঁকে না,
/// সেগুলো বারের
class TotalsBarHost extends InheritedWidget {
  const TotalsBarHost({super.key, required super.child});

  static bool hosts(BuildContext context) => context.dependOnInheritedWidgetOfExactType<TotalsBarHost>() != null;

  @override
  bool updateShouldNotify(TotalsBarHost oldWidget) => false;
}
