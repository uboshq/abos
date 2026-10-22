import '../../locale/app_locale.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'money_kinds_support.dart';

/// ১৩ · চেকের দিন — আজ (বা আগে) তারিখের হাতে থাকা চেক, যা আজ ব্যাংকে জমা দিতে হবে, আর সদ্য ফেরত আসা চেক (নকশা ১৯ অক্টোবর,
/// পাতা ২; cb, ২০ অক্টোবর ২০২৬)।
///
/// <p>⓪ ফেরত চেক লাল — গ্রাহকের খাতায় আবার বাকি। নাম "নাম · পয়েন্ট · শাখা", সার্ভার থেকে। চাপলে চেকের খাতা।
class ChequesWidget extends LauncherWidgetKind {
  const ChequesWidget();

  @override
  String get key => 'cheques';

  @override
  String get title => AppLocale.l10n.launcherWidgetChequesName;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2, WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> s, {required DateTime asOf}) {
    final rows = rowsOf(s);
    final count = (s['count'] as num?)?.toInt() ?? rows.length;
    final l = AppLocale.l10n;

    return WidgetDoc(
      title: l.launcherWidgetChequesTitle,
      asOf: asOf,
      link: s['link'] as String?,
      hero: isPositive(s['total']) ? WidgetHero(takaText(s['total']), caption: WidgetText(l.launcherWidgetChequesTotal)) : null,
      rows: [for (final r in rows) _row(r)],
      empty: l.launcherWidgetChequesEmpty,
      more: moreOf(count, rows.length),
    );
  }

  static WidgetRow _row(Map<String, dynamic> r) {
    final l = AppLocale.l10n;
    final cheque = l.launcherWidgetChequesCheque('${r['bank'] ?? ''}', '${r['no'] ?? ''}').trim();

    if (r['state'] == 'bounced') {
      return WidgetRow(
        title: WidgetText(l.launcherWidgetChequesBounced('${r['name']}'), tone: WidgetTone.bad),
        second: WidgetText(l.launcherWidgetChequesDueAgain(cheque)),
        amount: takaText(r['amount'], tone: WidgetTone.bad),
        link: r['link'] as String?,
      );
    }

    final day = banglaDay(r['date']);

    return WidgetRow(
      title: WidgetText('${r['name']}'),
      second: WidgetText(day == null ? cheque : l.launcherWidgetChequesDated(cheque, day)),
      amount: takaText(r['amount']),
      link: r['link'] as String?,
    );
  }
}
