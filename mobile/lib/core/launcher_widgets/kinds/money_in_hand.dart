import '../../locale/app_locale.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'money_kinds_support.dart';

/// ২ · টাকা হাতে — নগদ বাক্স, ব্যাংক, bKash/MFS, আর পথে থাকা টাকা (নকশা ১৯ অক্টোবর, পাতা ১; cb, ২০ অক্টোবর ২০২৬)।
///
/// <p>⓪ অঙ্ক ওয়েবের হোমের "হাতে ও ব্যাংকে মোট"-এর একই উৎস (সার্ভারের `money_in_hand`)। চাপলে নগদ আর ব্যাংক বই।
class MoneyInHandWidget extends LauncherWidgetKind {
  const MoneyInHandWidget();

  @override
  String get key => 'money_in_hand';

  @override
  String get title => AppLocale.l10n.launcherWidgetMoneyTitle;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s2x2, WidgetSize.s4x1];

  @override
  WidgetDoc build(Map<String, dynamic> s, {required DateTime asOf}) => WidgetDoc(
        title: AppLocale.l10n.launcherWidgetMoneyTitle,
        asOf: asOf,
        link: s['link'] as String?,
        hero: WidgetHero(takaText(s['total']), caption: WidgetText(AppLocale.l10n.launcherWidgetCashBankMfs)),
        rows: [
          WidgetRow(title: WidgetText(AppLocale.l10n.launcherWidgetMoneyCashBox), amount: takaText(s['cash']), link: s['cashLink'] as String?),
          WidgetRow(title: WidgetText(AppLocale.l10n.launcherWidgetBank), amount: takaText(s['bank']), link: s['bankLink'] as String?),
          WidgetRow(title: const WidgetText('bKash / MFS'), amount: takaText(s['mfs']), link: s['mfsLink'] as String?),
          // ⓘ পথে থাকা টাকা কেবল থাকলে — শূন্যের সারি জায়গা খায়
          if (isPositive(s['transit']))
            WidgetRow(title: WidgetText(AppLocale.l10n.launcherWidgetMoneyTransit), amount: takaText(s['transit'], tone: WidgetTone.muted)),
        ],
      );
}
