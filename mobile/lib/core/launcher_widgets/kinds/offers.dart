import '../../locale/app_locale.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ১৮ · চলতি অফার আর স্কিম (৪×২; নকশা ১৯ অক্টোবর ২০২৬) — SR অর্ডার নেওয়ার সময় দেখেন; চাপলে অফারের পাতা।
/// মালিকের সই না পাওয়া অফার সার্ভারই পাঠায় না।
///
/// <p>সারাংশ (`widgets.offers`): `count`, `rows[]` (`title` · `product` · `scope` · `ends_on` · `link`), `link`।
///  - মাথার কোণে: "চালু 3" (`count`); বড় অঙ্ক নেই;
///  - সারি: অফারের নাম, নিচে "পণ্য · সীমা", পাশে "শেষ: ২ দিন পর" (দুই দিনের মধ্যে, কমলা) বা "শেষ: ৩১ অক্টোবর"।
class OffersWidget extends LauncherWidgetKind {
  const OffersWidget();

  @override
  String get key => 'offers';

  @override
  String get title => AppLocale.l10n.launcherWidgetOffersName;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2, WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final today = SummaryReader.dhakaDay(asOf);
    final rows = <WidgetRow>[];
    for (final r in SummaryReader.rows(summary['rows'])) {
      final name = SummaryReader.text(r['title']);
      if (name == null) continue;
      final second = [SummaryReader.text(r['product']), SummaryReader.text(r['scope'])].whereType<String>().join(' · ');
      rows.add(WidgetRow(
        title: WidgetText(name),
        second: second.isEmpty ? null : WidgetText(second),
        chip: _ends(SummaryReader.day(r['ends_on']), today),
        link: SummaryReader.link(r['link']),
      ));
    }
    final count = SummaryReader.integer(summary['count']);

    return WidgetDoc(
      title: AppLocale.l10n.launcherWidgetOffersTitle,
      asOf: asOf,
      link: SummaryReader.link(summary['link']),
      // নকশা: মাথার কোণে "চালু 3"; বড় অঙ্ক নেই
      corner: count == null || count == 0 ? null : WidgetText(AppLocale.l10n.launcherWidgetOffersActive('$count')),
      rows: rows,
      empty: rows.isEmpty && (count ?? 0) == 0 ? AppLocale.l10n.launcherWidgetOffersEmpty : null,
      more: SummaryReader.more(count, rows.length),
    );
  }

  /// শেষের দিন — দুই দিনের মধ্যে হলে কত দিন বাকি (কমলা), নইলে তারিখ; তারিখ না থাকলে কিছু নয়
  static WidgetText? _ends(DateTime? end, DateTime today) {
    if (end == null) return null;
    final days = end.difference(today).inDays;
    return switch (days) {
      0 => WidgetText(AppLocale.l10n.launcherWidgetOffersEndsToday, tone: WidgetTone.warn),
      1 => WidgetText(AppLocale.l10n.launcherWidgetOffersEndsTomorrow, tone: WidgetTone.warn),
      2 => WidgetText(AppLocale.l10n.launcherWidgetOffersEndsInDays(SummaryReader.bnDigits(2)), tone: WidgetTone.warn),
      _ => WidgetText(AppLocale.l10n.launcherWidgetOffersEndsOn(SummaryReader.bnDate(end))),
    };
  }
}
