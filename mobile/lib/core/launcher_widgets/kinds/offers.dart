import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ১৮ · চলতি অফার আর স্কিম (৪×২; নকশা ১৯ অক্টোবর ২০২৬) — SR অর্ডার নেওয়ার সময় দেখেন; চাপলে অফারের পাতা।
/// মালিকের সই না পাওয়া অফার সার্ভারই পাঠায় না।
///
/// <p>সারাংশ (`widgets.offers`): `count`, `rows[]` (`title` · `product` · `scope` · `ends_on` · `link`), `link`।
///  - বড় অঙ্ক: কতটা চালু (`count`) — নকশার কোণের "চালু 3";
///  - সারি: অফারের নাম, নিচে "পণ্য · সীমা", পাশে "শেষ: ২ দিন পর" (দুই দিনের মধ্যে, কমলা) বা "শেষ: ৩১ অক্টোবর"।
class OffersWidget extends LauncherWidgetKind {
  const OffersWidget();

  @override
  String get key => 'offers';

  @override
  String get title => 'অফার আর স্কিম';

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
      title: 'চলতি অফার আর স্কিম',
      asOf: asOf,
      link: SummaryReader.link(summary['link']),
      hero: count == null || count == 0 ? null : WidgetHero(WidgetText('$count'), caption: const WidgetText('চালু')),
      rows: rows,
      empty: rows.isEmpty && (count ?? 0) == 0 ? 'এখন কোনো চালু অফার নেই' : null,
      more: SummaryReader.more(count, rows.length),
    );
  }

  /// শেষের দিন — দুই দিনের মধ্যে হলে কত দিন বাকি (কমলা), নইলে তারিখ; তারিখ না থাকলে কিছু নয়
  static WidgetText? _ends(DateTime? end, DateTime today) {
    if (end == null) return null;
    final days = end.difference(today).inDays;
    return switch (days) {
      0 => const WidgetText('শেষ: আজ', tone: WidgetTone.warn),
      1 => const WidgetText('শেষ: কাল', tone: WidgetTone.warn),
      2 => const WidgetText('শেষ: ২ দিন পর', tone: WidgetTone.warn),
      _ => WidgetText('শেষ: ${SummaryReader.bnDate(end)}'),
    };
  }
}
