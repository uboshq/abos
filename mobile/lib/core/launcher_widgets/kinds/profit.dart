import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'money_kinds_support.dart';

/// ১৭ · এই মাসের মোট লাভ — কেবল মালিক (নকশা ১৯ অক্টোবর, পাতা ২; cb, ২০ অক্টোবর ২০২৬)।
///
/// <p>⓪ বিক্রি (ভ্যাট আর ফেরত বাদে) − বিক্রিত মালের দাম (IAS 2, খরচের স্তর ধরে) = মোট লাভ; হার = লাভ ÷ বিক্রি। অঙ্ক ওয়েবের
/// লাভক্ষতির একই উৎস (সার্ভারের `profit`)। লোকসান হলে লাল আর লেখা "মোট লোকসান" — বিয়োগ চিহ্নে লুকিয়ে নয়।
class ProfitWidget extends LauncherWidgetKind {
  const ProfitWidget();

  @override
  String get key => 'profit';

  @override
  String get title => 'এই মাসের মোট লাভ';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x2, WidgetSize.s4x1];

  @override
  WidgetDoc build(Map<String, dynamic> s, {required DateTime asOf}) {
    final loss = isNegative(s['gross']);
    final month = banglaMonth(s['month']) ?? 'এই';

    return WidgetDoc(
      title: loss ? '$month মাসের মোট লোকসান' : '$month মাসের মোট লাভ',
      asOf: asOf,
      link: s['link'] as String?,
      hero: WidgetHero(
        takaText(loss ? _abs(s['gross']) : s['gross'], tone: loss ? WidgetTone.bad : WidgetTone.good),
        caption: WidgetText('মোট লাভের হার ${s['margin'] ?? '—'}%', tone: loss ? WidgetTone.bad : WidgetTone.plain),
      ),
      rows: [
        WidgetRow(title: const WidgetText('বিক্রি (ভ্যাট আর ফেরত বাদে)'), amount: takaText(s['sales'])),
        WidgetRow(title: const WidgetText('বিক্রিত মালের দাম'), amount: takaText(s['cogs'])),
      ],
    );
  }

  static String _abs(Object? value) {
    final text = '${value ?? '0'}';
    return text.startsWith('-') ? text.substring(1) : text;
  }
}
