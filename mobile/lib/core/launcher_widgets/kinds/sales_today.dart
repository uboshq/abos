import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ১ · আজকের বিক্রি (২×২; নকশা ১৯ অক্টোবর ২০২৬) — চাপলে আজকের বিলের তালিকা।
///
/// <p>সারাংশ (`widgets.sales_today`): `total`, `invoices`, `collections`, `change_percent` (গতকাল ০ হলে null), `link`।
/// `yesterday` পড়া হয় না — তুলনা সার্ভারের `change_percent`-এ।
///  - মাথার কোণে কিছু নয় (সময় আঁকিয়ের); বড় অঙ্ক: মোট বিক্রি;
///  - নিচে: "▲ গতকালের চেয়ে 12% বেশি" (সবুজ) / "▼ … কম" (লাল) / `change_percent` না থাকলে কিছু নয়;
///  - এক সারি: "বিল 38টা · আদায়" আর ডানে আদায়ের টাকা (টাকা গোপন-যোগ্য, বিলের সংখ্যা নয়)।
class SalesTodayWidget extends LauncherWidgetKind {
  const SalesTodayWidget();

  @override
  String get key => 'sales_today';

  @override
  // ⓘ অঙ্কটা ফেরত বাদ না দেওয়া মোট বিল — তাই "আজ বিল হয়েছে" (fe, ২০ অক্টোবর ২০২৬; IFRS 15-এ বিক্রি মানে ফেরত বাদে নিট)
  String get title => 'আজ বিল হয়েছে';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x2, WidgetSize.s2x1];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final total = SummaryReader.money(summary['total']);
    final invoices = SummaryReader.integer(summary['invoices']);
    final collected = SummaryReader.money(summary['collections']);
    final lineTitle = [
      if (invoices != null) 'বিল $invoicesটা',
      if (collected != null) 'আদায়',
    ].join(' · ');

    return WidgetDoc(
      // ⭐ নাম সার্ভারের (`label`, এখন "নিট বিক্রি" — VAT আর ফেরত বাদে; fe, ২১ অক্টোবর ২০২৬)। পুরনো সার্ভার label পাঠায় না —
      //    তখন অঙ্কটা মোট বিল, তাই "আজ বিল হয়েছে" ([[title]])
      title: SummaryReader.text(summary['label']) ?? title,
      asOf: asOf,
      link: SummaryReader.link(summary['link']),
      // ⓘ কোণ ফাঁকা: নকশার "১০:৪২" আঁকিয়ে নিজেই asOf থেকে লেখে (পুরনো হলে ধূসর) — কোণ দিলে ২×২-এ সময়টা সরে যেত
      hero: total == null ? null : WidgetHero(total, caption: _change(summary['change_percent'])),
      rows: [
        if (lineTitle.isNotEmpty) WidgetRow(title: WidgetText(lineTitle), amount: collected),
      ],
    );
  }

  /// গতকালের তুলনা — শতাংশ গোটা সংখ্যায়; ⛔ না থাকলে (গতকাল ০) কোনো লাইন নয়, "0%" নয়
  static WidgetText? _change(Object? value) {
    final raw = SummaryReader.decimal(value);
    if (raw == null) return null;
    final pct = double.parse(raw);
    if (pct == 0) return const WidgetText('গতকালের সমান', tone: WidgetTone.muted);
    final rounded = pct.abs().round();
    final shown = rounded == 0 ? '<1' : '$rounded';
    return pct > 0
        ? WidgetText('▲ গতকালের চেয়ে $shown% বেশি', tone: WidgetTone.good)
        : WidgetText('▼ গতকালের চেয়ে $shown% কম', tone: WidgetTone.bad);
  }
}
