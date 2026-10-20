import '../../sync_engine/sync_status.dart';
import '../kit/widget_doc.dart';
import '../kit/widget_kind.dart';

/// ⭐ ৭ · "নোটিশ" — না-পড়া নোটিশ উপরে, প্রতিটা সারিতে চাপলে সেই কাগজ (a6, ২০ অক্টোবর ২০২৬; চুক্তি
/// `E:\ABOS\app-widgets-ভিত্তি.md` §২)।
///
/// <p>সারাংশ: `{"unread": int, "rows": [{"title", "second"|null, "at": ISO, "unread": bool, "link"|null}], "more": int}`।
/// সারির নাম সার্ভার যেমন পাঠায় তেমনই — গ্রাহক থাকলে "নাম · পয়েন্ট · শাখা" সার্ভারেই বসে।
///
/// <p>⛔ সময় "40 মিনিট আগে" (উইজেটে সব সংখ্যা ইংরেজি অঙ্কে) [asOf] ধরে, ফোনের ঘড়ি নয় ([LastSyncLabel.ago]) — নথি খাঁটি থাকে।
/// ⛔ নাম বা দ্বিতীয় লাইনে "৳" থাকলে সেই লেখা [WidgetText.secret] — সার্ভারের মুক্ত লেখায় টাকা থাকতে পারে।
/// ⓘ না-পড়া সারি সাদামাটা রঙে আর "নতুন" চিপে; পড়া সারি [WidgetTone.muted]।
class NoticesWidget extends LauncherWidgetKind {
  const NoticesWidget();

  /// সারি সর্বোচ্চ ৮টা (চুক্তি §১) — বেশি এলে বাকিটা "আরও"-তে যোগ হয়
  static const int maxRows = 8;

  @override
  String get key => 'notices';

  @override
  String get title => 'নোটিশ';

  @override
  List<WidgetSize> get sizes => const [WidgetSize.s4x2, WidgetSize.s4x1, WidgetSize.s2x2];

  @override
  WidgetDoc build(Map<String, dynamic> summary, {required DateTime asOf}) {
    final all = [for (final r in (summary['rows'] as List? ?? const [])) if (r is Map) r];
    final shown = all.take(maxRows).toList();
    final more = _int(summary['more']) + (all.length - shown.length);
    final unread = _int(summary['unread']);
    return WidgetDoc(
      title: title,
      asOf: asOf,
      link: '/notifications',
      hero: unread > 0 ? WidgetHero(WidgetText('$unread'), caption: const WidgetText('না-পড়া')) : null,
      rows: [for (final r in shown) _row(r, asOf)],
      empty: all.isEmpty ? 'কোনো নোটিশ নেই' : null,
      more: more > 0 ? 'আরও $moreটা' : null,
    );
  }

  WidgetRow _row(Map r, DateTime asOf) {
    final unread = r['unread'] == true;
    final tone = unread ? WidgetTone.plain : WidgetTone.muted;
    final at = DateTime.tryParse('${r['at'] ?? ''}');
    final body = (r['second'] ?? '').toString().trim();
    final ago = at == null ? null : LastSyncLabel.ago(at, asOf, englishDigits: true);
    // ⓘ টাকার লেখা গোপন — তার ভেতরে বসলে সময়ও তালাবন্ধ ফোনে ঢাকা পড়ত, তাই তখন সময় ডানে
    final moneyBody = _hasMoney(body);
    final second = [if (body.isNotEmpty) body, if (ago != null && !moneyBody) ago].join(' · ');
    final heading = '${r['title'] ?? ''}';
    return WidgetRow(
      title: WidgetText(heading, secret: _hasMoney(heading), tone: tone),
      second: second.isEmpty ? null : WidgetText(second, secret: moneyBody, tone: WidgetTone.muted),
      amount: moneyBody && ago != null ? WidgetText(ago, tone: WidgetTone.muted) : null,
      chip: unread ? const WidgetText('নতুন', tone: WidgetTone.good) : null,
      link: r['link'] as String?,
    );
  }

  /// ⛔ নোটিশের লেখা মুক্ত — "চেক ফেরত ৳ 85,000" হতে পারে; টাকা থাকলে পুরো লেখাটাই গোপন-যোগ্য, তালাবন্ধ ফোনে ঢাকা
  static bool _hasMoney(String text) => text.contains('৳');

  static int _int(Object? v) => v is num ? v.toInt() : int.tryParse('${v ?? ''}') ?? 0;
}
