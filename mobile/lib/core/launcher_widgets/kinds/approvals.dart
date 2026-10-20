import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ৩ · আপনার সইয়ের অপেক্ষায় (৪×২; নকশা ১৯ অক্টোবর ২০২৬) — সারি চাপলে সেই কাগজের সইয়ের পাতা, অ্যাপের ভিতরে।
/// ⛔ সই উইজেটে নয়।
///
/// <p>সারাংশ (`widgets.approvals`): `count`, `rows[]` (`party.label` · `kind_label` · `number` · `amount` · `link`), `link`।
///  - মাথার কোণে: কতটা কাগজ অপেক্ষায় (`count`) — "7"; বড় অঙ্ক নেই;
///  - সারি: পক্ষ (নাম · পয়েন্ট · শাখা, সার্ভারের লেখা), নিচে "বিক্রি ফেরত SR-0212", ডানে টাকা;
///  - খালি: "কোনো সই বাকি নেই"; `count` সারির চেয়ে বেশি: "আরও Nটা"।
class ApprovalsWidget extends LauncherWidgetKind {
  const ApprovalsWidget();

  @override
  String get key => 'approvals';

  @override
  String get title => 'সইয়ের অপেক্ষা';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2, WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final rows = <WidgetRow>[];
    for (final r in SummaryReader.rows(summary['rows'])) {
      final paper = [SummaryReader.text(r['kind_label']), SummaryReader.text(r['number'])].whereType<String>().join(' ');
      final party = SummaryReader.partyLabel(r['party']);
      final head = party ?? (paper.isEmpty ? null : paper);
      if (head == null) continue;
      rows.add(WidgetRow(
        title: WidgetText(head),
        second: party != null && paper.isNotEmpty ? WidgetText(paper) : null,
        amount: SummaryReader.money(r['amount']),
        link: SummaryReader.link(r['link']),
      ));
    }
    final count = SummaryReader.integer(summary['count']);

    return WidgetDoc(
      title: 'আপনার সইয়ের অপেক্ষায়',
      asOf: asOf,
      link: SummaryReader.link(summary['link']),
      // নকশা: মাথার কোণে কতটা ("7"); বড় অঙ্ক নেই, তালিকা মাথার ঠিক নিচে
      corner: count == null || count == 0 ? null : WidgetText('$count'),
      rows: rows,
      empty: rows.isEmpty && (count ?? 0) == 0 ? 'কোনো সই বাকি নেই' : null,
      more: SummaryReader.more(count, rows.length),
    );
  }
}
