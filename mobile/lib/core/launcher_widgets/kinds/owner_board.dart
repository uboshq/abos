import '../../locale/app_locale.dart';
import '../../records/money.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ মালিকের পূর্ণ-পর্দা উইজেট (৪×৬) — এক নজরে পুরো ব্যবসা (নকশা: মালিকের-পূর্ণ-পর্দা-উইজেট-২০-অক্টোবর.pdf; মালিকের আদেশ,
/// ২০ অক্টোবর ২০২৬)।
///
/// <p>সার্ভারের `owner_board` সারাংশ ([[OwnerBoardSummary]]): `today` — ফোনের "আজ কেমন গেল" দরজার হুবহু ঘর (বিক্রি, আদায়,
/// হাতে মোট, বকেয়া, সই); `parts` — দলের অন্য উইজেটের সারাংশ যা মালিক পান (লক্ষ্য, লাভ, খরচ, চেক, কম মজুদ, চালান, নোটিশ)।
/// যে ভাগ নেই (চাবি নেই বা মডিউল বন্ধ) তার সারিই নেই — "শূন্য" দেখানো নয়।
///
/// <p>বিন্যাস (আঁকিয়ে মাপ বুঝে কাটে): মাথায় কোম্পানি আর শাখা; বড় অঙ্ক আজকের বিক্রি (সার্ভারের নাম — "নিট বিক্রি", পুরনোয় "আজ বিল হয়েছে"); সারিতে আদায়, হাতে মোট, বকেয়া, লাভ, খরচ;
/// দাগে মাসের লক্ষ্য; বোতামে পাঁচ সংকেত (সই, চেক, কম মজুদ, পথের চালান, নোটিশ) — প্রতিটা চাপলে সেই তালিকা।
class OwnerBoardWidget extends LauncherWidgetKind {
  const OwnerBoardWidget();

  @override
  String get key => 'owner_board';

  @override
  String get title => AppLocale.l10n.launcherWidgetOwnerName;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x6, WidgetSize.s4x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final today = (summary['today'] as Map?)?.cast<String, dynamic>() ?? const {};
    final parts = (summary['parts'] as Map?)?.cast<String, dynamic>() ?? const {};
    Map<String, dynamic>? part(String k) => (parts[k] as Map?)?.cast<String, dynamic>();
    Map<String, dynamic>? day(String k) => (today[k] as Map?)?.cast<String, dynamic>();
    num n(Object? v) => num.tryParse('${v ?? 0}') ?? 0;
    final l = AppLocale.l10n;

    final company = '${summary['company'] ?? 'ABOS'}';
    final branch = summary['branch'] == null ? l.launcherWidgetOwnerAllBranches : '${summary['branch']}';

    final sales = day('sales');
    final rows = <WidgetRow>[
      if (day('collections') case final c?)
        WidgetRow(
          title: WidgetText(l.launcherWidgetCollectionsToday),
          second: WidgetText(l.launcherWidgetOwnerReceipts('${n(c['count']).toInt()}')),
          amount: WidgetText.money(Money.taka(n(c['amount']))),
          link: '/sales/collections',
        ),
      if (day('money') case final m?)
        WidgetRow(
          title: WidgetText(l.launcherWidgetCashBankMfs),
          second: WidgetText.money(l.launcherWidgetOwnerCashAmount(Money.taka(n(m['cash'])))),
          amount: WidgetText.money(Money.taka(n(m['amount']))),
          link: '/accounts',
        ),
      if (day('dues') case final d?)
        WidgetRow(
          title: WidgetText(l.launcherWidgetOwnerDues),
          second: WidgetText(l.launcherWidgetOwnerShops('${n(d['shops']).toInt()}')),
          amount: WidgetText.money(Money.taka(n(d['amount'])), tone: WidgetTone.bad),
          link: '/customers',
        ),
      if (part('profit') case final p?)
        WidgetRow(
          title: WidgetText(l.launcherWidgetOwnerProfit),
          second: WidgetText(l.launcherWidgetOwnerMargin('${p['margin'] ?? '—'}')),
          amount: WidgetText.money(Money.taka(n(p['gross'])), tone: WidgetTone.good),
          link: '/reports',
        ),
      if (part('expenses') case final e?)
        WidgetRow(
          title: WidgetText(l.launcherWidgetOwnerExpenses),
          second: WidgetText(l.launcherWidgetNoStaffExpenses),
          amount: WidgetText.money(Money.taka(n(e['total']))),
          link: '/accounts',
        ),
    ];

    final bars = <WidgetBar>[
      if (part('target') case final t? when n(t['target']) > 0)
        WidgetBar(
          label: WidgetText(l.launcherWidgetOwnerTarget(_monthOf(t['month']), '${n(t['days_left']).toInt()}')),
          fraction: n(t['achieved']) / n(t['target']),
          value: WidgetText('${(n(t['achieved']) * 100 / n(t['target'])).round()}%'),
          tone: WidgetTone.good,
        ),
    ];

    // ⓘ সারাংশের নিজের গোনা (`count`) আগে — সারি কেবল প্রথম কয়েকটা (মজুদ সর্বোচ্চ ৩, ec-র ধরা); না থাকলে সারি গোনা
    int count(String k, String field) => switch (part(k)?['count'] ?? part(k)?[field]) {
          final List<dynamic> l => l.length,
          final num v => v.toInt(),
          _ => 0,
        };

    final actions = <WidgetAction>[
      if (day('approvals') case final a?)
        WidgetAction(label: l.launcherWidgetOwnerToSign('${n(a['pending']).toInt()}'), icon: 'approve', link: '/home/approvals'),
      if (part('cheques') != null) WidgetAction(label: l.launcherWidgetOwnerCheques('${count('cheques', 'rows')}'), icon: 'voucher', link: '${part('cheques')!['link'] ?? '/finance'}'),
      if (part('stock_alerts') != null)
        WidgetAction(label: l.launcherWidgetOwnerLowStock('${count('stock_alerts', 'rows')}'), icon: 'scan', link: '${part('stock_alerts')!['link'] ?? '/inventory'}'),
      if (part('challans_today') != null)
        WidgetAction(label: l.launcherWidgetOwnerChallansMoving('${count('challans_today', 'moving')}'), icon: 'order', link: '${part('challans_today')!['link'] ?? '/sales'}'),
      if (part('notices') != null) WidgetAction(label: l.launcherWidgetOwnerNotices('${count('notices', 'unread')}'), icon: 'notice', link: '/notices'),
    ];

    return WidgetDoc(
      title: '$company · $branch',
      asOf: asOf,
      link: '${summary['link'] ?? '/owner'}',
      hero: sales == null
          ? null
          : WidgetHero(
              WidgetText.money(Money.taka(n(sales['amount']))),
              caption: WidgetText(l.launcherWidgetOwnerBills('${sales['label'] ?? l.launcherWidgetBilledToday}', '${n(sales['count']).toInt()}')),
            ),
      rows: rows,
      bars: bars,
      actions: actions,
      empty: rows.isEmpty && sales == null ? l.launcherWidgetOwnerEmpty : null,
    );
  }

  static String _monthOf(Object? ym) {
    final m = int.tryParse('$ym'.split('-').elementAtOrNull(1) ?? '');
    return m == null || m < 1 || m > 12 ? AppLocale.l10n.launcherWidgetOwnerMonth : SummaryReader.monthWord(m);
  }
}
