import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ১১ · আজকের আদায় (২×২; নকশা ১৯ অক্টোবর ২০২৬) — চাপলে আজকের রসিদ।
///
/// <p>সারাংশ (`widgets.collections_today`): `total`, `cash`, `bank`, `mfs`, `link`।
///  - বড় অঙ্ক: মোট আদায়;
///  - সারি: নগদ · ব্যাংক · bKash / MFS — ⛔ যে ঘর সারাংশে নেই, তার সারি নেই ("৳0.00" নয়)।
class CollectionsTodayWidget extends LauncherWidgetKind {
  const CollectionsTodayWidget();

  @override
  String get key => 'collections_today';

  @override
  String get title => 'আজকের আদায়';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x2, WidgetSize.s2x1];

  static const _channels = [('cash', 'নগদ'), ('bank', 'ব্যাংক'), ('mfs', 'bKash / MFS')];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final total = SummaryReader.money(summary['total']);
    return WidgetDoc(
      title: title,
      asOf: asOf,
      link: SummaryReader.link(summary['link']),
      hero: total == null ? null : WidgetHero(total),
      rows: [
        for (final (field, label) in _channels)
          if (SummaryReader.money(summary[field]) case final amount?)
            WidgetRow(title: WidgetText(label), amount: amount),
      ],
    );
  }
}
