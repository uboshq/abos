import '../../locale/app_locale.dart';
import '../../records/money.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'money_kinds_support.dart';

/// ১২ · বাকির সীমার ঝুঁকি — সীমার ৯০%-এর বেশি ব্যবহার করা গ্রাহক (নকশা ১৯ অক্টোবর, পাতা ২; cb, ২০ অক্টোবর ২০২৬)।
///
/// <p>⓪ গ্রাহকের নাম সার্ভার থেকে "নাম · পয়েন্ট · শাখা"; বিক্রয়কর্মী কেবল নিজের ডিলার (সার্ভারের ছাঁকনি)। সীমা পেরোলে লাল,
/// ৯০%-এর উপরে কমলা। চাপলে বাকির সীমার পাতা, সারিতে চাপলে সেই গ্রাহক।
class CreditLimitsWidget extends LauncherWidgetKind {
  const CreditLimitsWidget();

  @override
  String get key => 'credit_limits';

  @override
  String get title => AppLocale.l10n.launcherWidgetCreditLimitsTitle;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x2, WidgetSize.s4x2];

  @override
  WidgetDoc build(Map<String, dynamic> s, {required DateTime asOf}) {
    final rows = rowsOf(s);
    final count = (s['count'] as num?)?.toInt() ?? rows.length;
    final l = AppLocale.l10n;

    return WidgetDoc(
      title: l.launcherWidgetCreditLimitsTitle,
      asOf: asOf,
      link: s['link'] as String?,
      hero: count == 0
          ? null
          : WidgetHero(
              WidgetText(banglaNumber(count), tone: WidgetTone.bad),
              caption: WidgetText(l.launcherWidgetCreditLimitsCaption(banglaNumber(90))),
            ),
      rows: [
        for (final r in rows)
          WidgetRow(
            title: WidgetText('${r['name']}'),
            second: WidgetText.money('${Money.taka(r['due'] ?? '0')} / ${Money.taka(r['limit'] ?? '0')}'),
            chip: WidgetText('${banglaNumber(r['percent'] ?? '')}%',
                tone: ((r['percent'] as num?) ?? 0) >= 100 ? WidgetTone.bad : WidgetTone.warn),
            link: r['link'] as String?,
          ),
      ],
      empty: l.launcherWidgetCreditLimitsEmpty,
      more: moreOf(count, rows.length),
    );
  }
}
