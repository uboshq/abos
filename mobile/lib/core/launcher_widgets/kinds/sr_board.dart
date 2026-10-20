import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ১৪ · আজ সবচেয়ে বেশি বিক্রি — বিক্রয়কর্মী (৪×২; নকশা ১৯ অক্টোবর ২০২৬) — মালিক আর ব্যবস্থাপকের; চাপলে SR-ভিত্তিক
/// বিক্রির রিপোর্ট।
///
/// <p>সারাংশ (`widgets.sr_board`): `branch_label`, `rows[]` (`rank` · `name` · `branch` · `amount`), `link`; SR পান কেবল
/// `self` (`rank` · `amount` · `of`)।
///  - সারি: "1 Md. Kawser · SL-Super Group", ডানে টাকা;
///  - ⛔ `self` থাকলে কেবল "আজ আপনি 3 নম্বরে" আর নিজের টাকা — `rows` এলেও (সার্ভারের ভুলেও) অন্য কারো অঙ্ক কখনো নয়।
class SrBoardWidget extends LauncherWidgetKind {
  const SrBoardWidget();

  @override
  String get key => 'sr_board';

  @override
  String get title => 'বিক্রয়কর্মীর তালিকা';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final self = SummaryReader.map(summary['self']);
    final link = SummaryReader.link(summary['link']);
    if (self != null) {
      final rank = SummaryReader.integer(self['rank']);
      final of = SummaryReader.integer(self['of']);
      return WidgetDoc(
        title: 'আজ আমার বিক্রি',
        asOf: asOf,
        link: link,
        rows: [
          WidgetRow(
            title: WidgetText(rank == null ? 'আজ আপনার বিক্রি' : 'আজ আপনি $rank নম্বরে'),
            second: rank != null && of != null ? WidgetText('$of জনের মধ্যে') : null,
            amount: SummaryReader.money(self['amount']),
          ),
        ],
      );
    }

    final rows = <WidgetRow>[];
    for (final r in SummaryReader.rows(summary['rows'])) {
      final name = SummaryReader.text(r['name']);
      if (name == null) continue;
      final rank = SummaryReader.integer(r['rank']);
      final branch = SummaryReader.text(r['branch']);
      rows.add(WidgetRow(
        title: WidgetText([if (rank != null) '$rank', [name, if (branch != null) branch].join(' · ')].join(' ')),
        amount: SummaryReader.money(r['amount']),
        link: SummaryReader.link(r['link']),
      ));
    }
    final branch = SummaryReader.text(summary['branch_label']);

    return WidgetDoc(
      // ⓘ ভিত্তিতে মাথার ডান কোণের ঘর নেই — "শাখা: সব" তাই মাথার লেখায়
      title: 'আজ সবচেয়ে বেশি বিক্রি — বিক্রয়কর্মী${branch == null ? '' : ' · শাখা: $branch'}',
      asOf: asOf,
      link: link,
      rows: rows,
      empty: rows.isEmpty ? 'আজ এখনো কোনো বিক্রি নেই' : null,
    );
  }
}
