import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';

/// ⭐ আজকের চালান (৪×২) — আজকের চালান কোথায়: গেট পাস বাকি, পথে, পৌঁছেছে, ফেরত (মালিকের উইজেট-নকশা ৬, ২০ অক্টোবর ২০২৬;
/// সার্ভারের [[ChallansTodaySummary]], চাবি `challans_today`)।
///
/// <p>চার ঘর চার সারি, প্রতিটা চাপলে ওয়েবের ডেলিভারির পাতায় সেই ধাপের চালানগুলো (`/sales/deliveries?stage=…`)। ফেরত ০-এর
/// বেশি হলে লাল। শেষ সারি সবশেষ চালান: "শেষ: নাম · পয়েন্ট · শাখা", তার নিচে নম্বর আর গাড়ি, চাপলে সেই চালান। কোণে মোট।
///
/// <p>ⓘ "গেট পাস বাকি" ঘরের লিংক `allocated` ধাপে — চালান নিশ্চিত হলেই এই ধাপ, আর গেট পাস জন্মায় রওনায়; তোলা আর প্যাক
/// ধাপের চালানও এই ঘরে গোনা, ওয়েবের পাতায় সেগুলো নিজের ট্যাবে।
class ChallansTodayWidget extends LauncherWidgetKind {
  const ChallansTodayWidget();

  /// ঘর → (লেখা, ওয়েবের ডেলিভারির পাতার ধাপ)
  static const boxes = <String, (String, String)>{
    'gate_pending': ('গেট পাস বাকি', 'allocated'),
    'moving': ('পথে আছে', 'dispatched'),
    'delivered': ('পৌঁছেছে', 'delivered'),
    'returned': ('ফেরত এসেছে', 'failed'),
  };

  static String stageLink(String stage) => '/sales/deliveries?stage=$stage';

  @override
  String get key => 'challans_today';

  @override
  String get title => 'আজকের চালান';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    int n(Object? v) => num.tryParse('${v ?? 0}')?.toInt() ?? 0;
    final total = n(summary['total']);
    final latest = (summary['latest'] as Map?)?.cast<String, dynamic>();

    final rows = <WidgetRow>[
      for (final MapEntry(key: box, value: (label, stage)) in boxes.entries)
        WidgetRow(
          title: WidgetText(label),
          amount: WidgetText('${n(summary[box])}',
              tone: box == 'returned' && n(summary[box]) > 0 ? WidgetTone.bad : WidgetTone.plain),
          link: stageLink(stage),
        ),
      if (latest != null)
        WidgetRow(
          title: WidgetText('শেষ: ${latest['customer'] ?? '—'}'),
          second: WidgetText([
            '${latest['no'] ?? '—'}',
            if (latest['vehicle'] != null && '${latest['vehicle']}'.isNotEmpty) 'গাড়ি ${latest['vehicle']}',
          ].join(' · '), tone: WidgetTone.muted),
          link: latest['link'] == null ? null : '${latest['link']}',
        ),
    ];

    return WidgetDoc(
      title: 'আজকের চালান',
      asOf: asOf,
      link: '${summary['link'] ?? stageLink('dispatched')}',
      corner: WidgetText('মোট $total'),
      rows: total == 0 ? const [] : rows,
      empty: total == 0 ? 'আজ এখনো কোনো চালান নেই' : null,
    );
  }
}
