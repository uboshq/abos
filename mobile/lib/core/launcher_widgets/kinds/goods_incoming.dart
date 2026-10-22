import '../../locale/app_locale.dart';
import '../../records/money.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';
import 'summary_reader.dart';

/// ⭐ মাল আসছে (৪×২) — এই সপ্তাহের ক্রয় অর্ডার আর দেরির অর্ডার (মালিকের উইজেট-নকশা ১৫, ২০ অক্টোবর ২০২৬; সার্ভারের
/// [[GoodsIncomingSummary]], চাবি `goods_incoming`)।
///
/// <p>প্রতিটা সারি: "সরবরাহকারী (সরবরাহকারী) · শাখা", নিচে "PO-0215 · আসবে কাল · 400 বস্তা আটা", ডানে অঙ্ক — চাপলে সেই
/// ক্রয় অর্ডার। দেরির অর্ডার (আসার দিন পেরিয়েছে, মাল আসেনি; সার্ভারের `late`) লাল, নামের আগে "দেরি:", নিচে "কথা ছিল …"।
///
/// <p>⛔ অঙ্ক সার্ভার পাঠায় কেবল ক্রয়মূল্য দেখার চাবিধারীকে; না পাঠালে অঙ্কের ঘরই নেই। অঙ্ক থাকলে [WidgetText.money]।
/// ⓘ "আজ"/"কাল" বাংলাদেশের দিন ধরে, [asOf] থেকে (ঘড়ি নয়) — তাই খাঁটি।
class GoodsIncomingWidget extends LauncherWidgetKind {
  const GoodsIncomingWidget();

  @override
  String get key => 'goods_incoming';

  @override
  String get title => AppLocale.l10n.launcherWidgetGoodsName;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    int n(Object? v) => num.tryParse('${v ?? 0}')?.toInt() ?? 0;
    final list = [for (final r in (summary['rows'] as List?) ?? const []) (r as Map).cast<String, dynamic>()];
    final count = summary.containsKey('count') ? n(summary['count']) : list.length;
    final late = n(summary['late']);
    final today = _dhakaDay(asOf);
    final l = AppLocale.l10n;

    final rows = <WidgetRow>[
      for (final r in list)
        () {
          final isLate = r['late'] == true;
          final tone = isLate ? WidgetTone.bad : WidgetTone.plain;
          final branch = r['supplier_branch'] == null || '${r['supplier_branch']}'.isEmpty ? '' : ' · ${r['supplier_branch']}';
          final expected = DateTime.tryParse('${r['expected'] ?? ''}');
          return WidgetRow(
            title: WidgetText(
                (isLate ? l.launcherWidgetGoodsSupplierLate : l.launcherWidgetGoodsSupplier)('${r['supplier'] ?? '—'}', branch),
                tone: tone),
            second: WidgetText([
              '${r['no'] ?? '—'}',
              if (expected != null) isLate ? l.launcherWidgetGoodsWasDue(_day(expected)) : _when(expected, today),
              if (!isLate && _goods(r) != null) _goods(r)!,
            ].join(' · '), tone: isLate ? WidgetTone.bad : WidgetTone.muted),
            amount: r['amount'] == null ? null : WidgetText.money(Money.taka(num.tryParse('${r['amount']}') ?? 0), tone: tone),
            link: r['link'] == null ? null : '${r['link']}',
          );
        }(),
    ];

    return WidgetDoc(
      title: l.launcherWidgetGoodsTitle,
      asOf: asOf,
      link: '${summary['link'] ?? '/purchase/orders'}',
      corner: WidgetText(late > 0 ? l.launcherWidgetGoodsWeekLate('$count', '$late') : l.launcherWidgetGoodsWeek('$count'), tone: late > 0 ? WidgetTone.bad : WidgetTone.plain),
      rows: rows,
      empty: count == 0 ? l.launcherWidgetGoodsEmpty : null,
      more: count > rows.length ? l.launcherWidgetMore('${count - rows.length}') : null,
    );
  }

  /// "400 বস্তা আটা" — এক একক হলে পরিমাণ, এক পণ্য হলে নামও; নইলে "3টা পণ্য"
  static String? _goods(Map<String, dynamic> r) {
    final qty = r['qty'];
    if (qty != null) {
      return [
        '$qty',
        if (r['unit'] != null && '${r['unit']}'.isNotEmpty) '${r['unit']}',
        if (r['product'] != null && '${r['product']}'.isNotEmpty) '${r['product']}',
      ].join(' ');
    }
    final products = num.tryParse('${r['products'] ?? ''}')?.toInt() ?? 0;
    return products > 0 ? AppLocale.l10n.launcherWidgetGoodsProducts('$products') : null;
  }

  /// বাংলাদেশের দিন (UTC+6, গ্রীষ্মকাল নেই)
  static DateTime _dhakaDay(DateTime at) {
    final d = at.toUtc().add(const Duration(hours: 6));
    return DateTime.utc(d.year, d.month, d.day);
  }

  static String _when(DateTime expected, DateTime today) {
    final day = DateTime.utc(expected.year, expected.month, expected.day);
    return switch (day.difference(today).inDays) {
      0 => AppLocale.l10n.launcherWidgetGoodsToday,
      1 => AppLocale.l10n.launcherWidgetGoodsTomorrow,
      _ => AppLocale.l10n.launcherWidgetGoodsOn(_day(day)),
    };
  }

  static String _day(DateTime d) => '${d.day} ${SummaryReader.monthWord(d.month)}';
}
