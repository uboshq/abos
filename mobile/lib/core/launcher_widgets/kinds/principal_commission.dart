import '../../locale/app_locale.dart';
import '../../records/money.dart';
import '../../records/principal_commission.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ ২২ · প্রিন্সিপালের কমিশন (৪×২ মাঝারি, ৪×৬ বড়) — মালিকের আদেশ "app e dekhate hobe" (fe-র মাধ্যমে; a4-এর নকশার
/// নিয়ম ৪; ২০ অক্টোবর ২০২৬)।
///
/// <p>সারাংশ (`widgets.principal_commission`, চুক্তি §৩): `rows[]` (চলতি চক্রের সব প্রিন্সিপাল), `total`, `link`
/// (`/home/principal-commission`)।
///  - প্রতিটা প্রিন্সিপাল এক সারি: নাম ("নাম · পয়েন্ট · শাখা", সার্ভারের লেখা), নিচে দিক ("দেনা · দিতে হবে" /
///    "পাওনা · কোম্পানির কাছে পাব"), ডানে জের — ⛔ বিয়োগ চিহ্ন ছাড়া, টাকা [WidgetText.money] (তালাবন্ধ ফোনে বা মালিকের
///    সুইচে লুকায়, ভিত্তির নিয়ম);
///  - নিচে "মোট" সারি আর মাথার কোণে "মোট ৳…" — ⛔ সার্ভারের bcmath মোট, অ্যাপ যোগ করে না;
///  - চাপলে অ্যাপের পর্দা; পুরনো সার্ভারে (৪০৪) বা চাবি না থাকলে উইজেট চুপচাপ বন্ধ — ভিত্তি ([WidgetHub]) নিজেই করে।
class PrincipalCommissionWidget extends LauncherWidgetKind {
  const PrincipalCommissionWidget();

  /// ভিত্তির সারির সীমা ৮ — সাতজন প্রিন্সিপাল, অষ্টমটা "মোট"
  static const int principalsShown = 7;

  static const String screenPath = '/home/principal-commission';

  @override
  String get key => 'principal_commission';

  @override
  String get title => AppLocale.l10n.launcherWidgetCommissionName;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2, WidgetSize.s4x6];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final all = rowsOf(summary['rows']);
    final link = SummaryReader.link(summary['link']) ?? screenPath;
    final rows = <WidgetRow>[
      for (final r in all.take(principalsShown))
        WidgetRow(
          title: WidgetText(r.label),
          second: WidgetText(r.side.sentence, tone: WidgetTone.muted),
          amount: r.balance == null
              ? null
              : WidgetText.money(Money.taka(unsigned(r.balance)), tone: _tone(r.side)),
          link: link,
        ),
    ];
    final total = summary['total'] is Map ? PrincipalCommissionTotal.fromJson(summary['total']) : null;
    final totalText = total?.balance == null ? null : Money.taka(unsigned(total!.balance));
    if (rows.isNotEmpty && totalText != null) {
      rows.add(WidgetRow(
        title: WidgetText(AppLocale.l10n.launcherWidgetCommissionTotal(total!.side.word)),
        amount: WidgetText.money(totalText, tone: _tone(total.side)),
        link: link,
      ));
    }
    return WidgetDoc(
      title: title,
      asOf: asOf,
      link: link,
      corner: totalText == null ? null : WidgetText.money(AppLocale.l10n.launcherWidgetTotal(totalText)),
      rows: rows,
      more: SummaryReader.more(all.length, rows.isEmpty ? 0 : rows.length - (totalText == null ? 0 : 1)),
      empty: all.isEmpty ? AppLocale.l10n.launcherWidgetCommissionEmpty : null,
    );
  }

  static WidgetTone _tone(BalanceSide side) => switch (side) {
        BalanceSide.pay => WidgetTone.bad,
        BalanceSide.receive => WidgetTone.good,
        BalanceSide.settled => WidgetTone.muted,
      };
}
