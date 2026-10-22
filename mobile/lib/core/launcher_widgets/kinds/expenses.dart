import '../../locale/app_locale.dart';
import '../../records/money.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'money_kinds_support.dart';

/// ১৬ · এই মাসের খরচ — খাত ধরে (নকশা ১৯ অক্টোবর, পাতা ২; cb, ২০ অক্টোবর ২০২৬)।
///
/// <p>⛔ কর্মীর খরচের খাত সারাংশেই আসে না, মোটেও নয় (দলের নিয়ম; a4, ২০ অক্টোবর): "বাকি সব"-এ মেশালে আগের মাসের সাথে
/// মিলিয়ে আন্দাজ করা যেত। তাই মাথার নিচে "কর্মীর খরচ বাদে" — মোটটা লাভক্ষতির খরচের চেয়ে কম কেন, তা যেন ভুল না বোঝা হয়।
class ExpensesWidget extends LauncherWidgetKind {
  const ExpensesWidget();

  @override
  String get key => 'expenses';

  @override
  String get title => AppLocale.l10n.launcherWidgetExpensesTitle;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x2, WidgetSize.s4x2];

  @override
  WidgetDoc build(Map<String, dynamic> s, {required DateTime asOf}) {
    final rows = rowsOf(s, max: 5);
    double amountOf(Map<String, dynamic> r) => Money.value(r['amount'])?.toDouble() ?? 0;
    final largest = rows.fold<double>(0, (m, r) => amountOf(r) > m ? amountOf(r) : m);
    final month = banglaMonth(s['month']);
    final l = AppLocale.l10n;

    return WidgetDoc(
      title: month == null ? l.launcherWidgetExpensesTitle : l.launcherWidgetExpensesMonth(month),
      asOf: asOf,
      link: s['link'] as String?,
      hero: WidgetHero(takaText(s['total']), caption: WidgetText(l.launcherWidgetNoStaffExpenses)),
      bars: [
        for (final r in rows)
          WidgetBar(
            label: WidgetText('${r['head']}'),
            fraction: largest <= 0 ? 0 : amountOf(r) / largest,
            value: takaText(r['amount']),
          ),
      ],
      empty: l.launcherWidgetExpensesEmpty,
    );
  }
}
