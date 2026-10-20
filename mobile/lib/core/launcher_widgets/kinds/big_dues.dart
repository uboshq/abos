import '../../records/money.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ৪ · সবচেয়ে বড় বকেয়া (৪×২; নকশা ১৯ অক্টোবর ২০২৬) — চাপলে সেই গ্রাহকের খাতা। SR শুধু নিজের ডিলার (সার্ভার ছাঁকে)।
///
/// <p>সারাংশ (`widgets.big_dues`): `total`, `rows[]` (`customer.label` · `balance` · `overdue_days` · `link`), `link`।
///  - মাথার কোণে: "মোট ৳62,18,400.00" (টাকা); বড় অঙ্ক নেই;
///  - সারি: গ্রাহক (নাম · পয়েন্ট · শাখা), ডানে বাকি; বাকির দিন পার হলে নিচে "বাকির ৪৫ দিন পার" আর অঙ্ক লাল;
///  - ⛔ ঋণাত্মক বাকি = অগ্রিম: "(Cr) ৳68.43", বিয়োগ চিহ্ন ছাড়া, নিচে "অগ্রিম জমা আছে"।
class BigDuesWidget extends LauncherWidgetKind {
  const BigDuesWidget();

  @override
  String get key => 'big_dues';

  @override
  String get title => 'বড় বকেয়া';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2, WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final rows = <WidgetRow>[];
    for (final r in SummaryReader.rows(summary['rows'])) {
      final customer = SummaryReader.partyLabel(r['customer']);
      if (customer == null) continue;
      final read = SummaryReader.decimal(r['balance']);
      // "-0.0000" শূন্যই — "(Cr) ৳0.00" বা "৳-0.00" নয়
      final balance = read != null && double.parse(read) == 0 ? '0' : read;
      final advance = balance != null && balance.startsWith('-');
      final overdue = SummaryReader.integer(r['overdue_days']);
      final late = !advance && overdue != null && overdue > 0;
      rows.add(WidgetRow(
        title: WidgetText(customer),
        second: advance
            ? const WidgetText('অগ্রিম জমা আছে')
            : late
                ? WidgetText('বাকির ${SummaryReader.bnDigits(overdue)} দিন পার', tone: WidgetTone.bad)
                : null,
        amount: balance == null
            ? null
            : advance
                ? WidgetText.money('(Cr) ${Money.taka(balance.substring(1))}')
                : WidgetText.money(Money.taka(balance), tone: late ? WidgetTone.bad : WidgetTone.plain),
        link: SummaryReader.link(r['link']),
      ));
    }
    final total = SummaryReader.decimal(summary['total']);

    return WidgetDoc(
      title: 'সবচেয়ে বড় বকেয়া',
      asOf: asOf,
      link: SummaryReader.link(summary['link']),
      // নকশা: মাথার কোণে "মোট ৳…" (টাকা, গোপন-যোগ্য); বড় অঙ্ক নেই
      corner: total == null ? null : WidgetText.money('মোট ${Money.taka(total)}'),
      rows: rows,
      empty: rows.isEmpty ? 'কারো কাছে বাকি নেই' : null,
    );
  }
}
