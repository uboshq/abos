import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'money_kinds_support.dart';

/// ২০ · ব্যাংক মেলানো বাকি — না-মেলানো সারি আর শেষ মেলানোর দিন; হিসাবরক্ষক আর মালিক (নকশা ১৯ অক্টোবর, পাতা ২; cb,
/// ২০ অক্টোবর ২০২৬)। চাপলে মেলানোর পাতা।
class BankReconcileWidget extends LauncherWidgetKind {
  const BankReconcileWidget();

  @override
  String get key => 'bank_reconcile';

  @override
  String get title => 'ব্যাংক মেলানো বাকি';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x1, WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> s, {required DateTime asOf}) {
    final open = (s['open'] as num?)?.toInt() ?? 0;
    final last = banglaDay(s['lastOn']);

    return WidgetDoc(
      title: 'ব্যাংক মেলানো বাকি',
      asOf: asOf,
      link: s['link'] as String?,
      hero: open == 0
          ? null
          : WidgetHero(
              WidgetText('না-মেলানো সারি: ${banglaNumber(open)}', tone: WidgetTone.bad),
              caption: WidgetText(last == null ? 'এখনো কখনো মেলানো হয়নি' : 'শেষ মেলানো: $last'),
            ),
      empty: last == null ? 'সব মেলানো' : 'সব মেলানো · শেষ মেলানো: $last',
    );
  }
}
