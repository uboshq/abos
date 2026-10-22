import '../../locale/app_locale.dart';
import '../../records/money.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ৯ · "লক্ষ্য বনাম অর্জন" — এই মাসের লক্ষ্যের কত ভাগ হলো, কত দিন বাকি (a6, ২০ অক্টোবর ২০২৬; চুক্তি
/// `E:\ABOS\app-widgets-ভিত্তি.md` §২)।
///
/// <p>সারাংশ: `{"month": "YYYY-MM", "target": "decimal"|null, "achieved": "decimal", "days_left": int, "own": bool,
/// "link"}`। `own` হলে লক্ষ্যটা নিজের (SR), নইলে দলের বা কোম্পানির।
///
/// <p>⛔ লক্ষ্য না থাকলে বা শূন্য হলে ভাগ নয় — "এই মাসের লক্ষ্য বসানো নেই" (শূন্যে ভাগ = ∞%)।
/// ⛔ টাকা [WidgetText.money], ইংরেজি অঙ্কে, ছোট রূপে ("৳ 1.36 কোটি") — ২×১ ঘরে পুরো অঙ্ক ধরে না। শতাংশ টাকা নয়, তাই
/// খোলা থাকে।
class TargetWidget extends LauncherWidgetKind {
  const TargetWidget();

  @override
  String get key => 'target';

  @override
  String get title => AppLocale.l10n.launcherWidgetTargetName;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x1, WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final month = monthName(summary['month'] as String?);
    final own = summary['own'] == true;
    final l = AppLocale.l10n;
    final heading = month == null
        ? (own ? l.launcherWidgetTargetMineThisMonth : l.launcherWidgetTargetThisMonth)
        : (own ? l.launcherWidgetTargetMineMonth(month) : l.launcherWidgetTargetMonth(month));
    final link = summary['link'] as String?;
    final target = Money.value(summary['target']);
    if (target == null || target <= 0) {
      return WidgetDoc(title: heading, asOf: asOf, link: link, empty: l.launcherWidgetTargetNotSet);
    }
    final achieved = Money.valueOrZero(summary['achieved']);
    final fraction = achieved / target;
    final percent = (fraction * 100).floor();
    final tone = fraction >= 1 ? WidgetTone.good : WidgetTone.plain;
    final daysLeft = summary['days_left'] is num ? (summary['days_left'] as num).toInt() : null;
    return WidgetDoc(
      title: heading,
      asOf: asOf,
      link: link,
      hero: WidgetHero(
        WidgetText('$percent%', tone: tone),
        caption: daysLeft == null
            ? null
            : WidgetText(daysLeft > 0 ? l.launcherWidgetTargetDaysLeft('$daysLeft') : l.launcherWidgetTargetMonthOver),
      ),
      bars: [
        WidgetBar(
          label: WidgetText.money('${short(achieved)} / ${short(target)}'),
          fraction: fraction.clamp(0.0, 1.0),
          tone: tone,
        ),
      ],
    );
  }

  /// `2026-10` → "অক্টোবর"; পড়া না গেলে null
  static String? monthName(String? ym) {
    final m = RegExp(r'^\d{4}-(\d{2})$').firstMatch(ym ?? '');
    final n = m == null ? 0 : int.parse(m[1]!);
    return n >= 1 && n <= 12 ? SummaryReader.monthWord(n) : null;
  }

  /// ছোট টাকা, ইংরেজি অঙ্কে: 13600000 → "৳ 1.36 কোটি", 250000 → "৳ 2.5 লাখ", 8500 → "৳8,500.00"
  static String short(double v) {
    String two(double x) {
      final s = x.toStringAsFixed(2);
      return s.replaceFirst(RegExp(r'\.?0+$'), '');
    }

    const taka = '৳ '; // l10n-ok: the taka sign, the same in every language
    if (v.abs() >= 10000000) return taka + AppLocale.l10n.launcherWidgetTargetCrore(two(v / 10000000));
    if (v.abs() >= 100000) return taka + AppLocale.l10n.launcherWidgetTargetLakh(two(v / 100000));
    return Money.taka(v);
  }
}
