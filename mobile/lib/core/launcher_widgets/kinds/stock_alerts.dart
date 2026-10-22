import '../../locale/app_locale.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';

/// ⭐ মজুদ সতর্কতা (৪×২) — পুনঃক্রয়ের সীমার কাছে নেমে আসা পণ্য (মালিকের উইজেট-নকশা ৫, ২০ অক্টোবর ২০২৬; সার্ভারের
/// [[StockAlertsSummary]], চাবি `stock_alerts`)।
///
/// <p>প্রতিটা পণ্য একটা দাগ: সীমার কত ভাগ আছে, আর পাশে "24 / 200 কার্টন"। রং ভাগ ধরে — সিকি বা কম লাল ([WidgetTone.bad]),
/// অর্ধেক বা কম কমলা ([WidgetTone.warn]), তার বেশি নীল (আঁকিয়ের সাধারণ রং, [WidgetTone.plain])। মাথার কোণে গুদাম আর শাখা।
///
/// <p>⛔ বিক্রয়কর্মীর ফোনে এই উইজেট আসে না (মালিক, ১ অক্টোবর ২০২৬): সার্ভার চাবি `inventory.stock.view` ছাড়া উত্তরে
/// `stock_alerts` রাখেই না, আর সারাংশ না থাকলে ভিত্তি [build] ডাকে না — উইজেটও বাছার তালিকায় আসে না।
class StockAlertsWidget extends LauncherWidgetKind {
  const StockAlertsWidget();

  /// এর নিচে লাল — সীমার সিকি ভাগ বা কম
  static const redAtOrBelow = 0.25;

  /// এর নিচে কমলা — সীমার অর্ধেক বা কম
  static const amberAtOrBelow = 0.5;

  static const stockPage = '/inventory/stock?sort=available';

  @override
  String get key => 'stock_alerts';

  @override
  String get title => AppLocale.l10n.launcherWidgetStockName;

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2];

  /// সীমার ভাগ থেকে রং
  static WidgetTone toneOf(double share) => share <= redAtOrBelow
      ? WidgetTone.bad
      : share <= amberAtOrBelow
          ? WidgetTone.warn
          : WidgetTone.plain;

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final rows = [for (final r in (summary['rows'] as List?) ?? const []) (r as Map).cast<String, dynamic>()];
    final count = num.tryParse('${summary['count'] ?? rows.length}')?.toInt() ?? rows.length;

    final bars = <WidgetBar>[
      for (final r in rows)
        () {
          final share = double.tryParse('${r['share'] ?? ''}') ?? 0;
          final unit = r['unit'] == null || '${r['unit']}'.isEmpty ? '' : ' ${r['unit']}';
          final tone = toneOf(share);
          return WidgetBar(
            label: WidgetText('${r['product'] ?? '—'}'),
            fraction: share.clamp(0.0, 1.0),
            value: WidgetText('${r['available'] ?? '0'} / ${r['reorder'] ?? '—'}$unit', tone: tone),
            tone: tone,
          );
        }(),
    ];

    return WidgetDoc(
      title: AppLocale.l10n.launcherWidgetStockTitle,
      asOf: asOf,
      link: '${summary['link'] ?? stockPage}',
      corner: WidgetText(_where(summary['warehouse'], summary['branch'])),
      bars: bars,
      empty: count == 0 ? AppLocale.l10n.launcherWidgetStockEmpty : null,
      more: count > bars.length ? AppLocale.l10n.launcherWidgetMore('${count - bars.length}') : null,
    );
  }

  /// "গুদাম: প্রধান · SL-Super Group" — একটা গুদাম না হলে "সব গুদাম", শাখা থাকলে পাশে
  static String _where(Object? warehouse, Object? branch) {
    final place = warehouse == null || '$warehouse'.isEmpty
        ? AppLocale.l10n.launcherWidgetStockAllWarehouses
        : AppLocale.l10n.launcherWidgetStockWarehouse('$warehouse');
    return branch == null || '$branch'.isEmpty ? place : '$place · $branch';
  }
}
