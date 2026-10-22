import '../../locale/app_locale.dart';
import '../../navigation/top_bar/abos_top_bar.dart' as top_bar;
import '../../records/money.dart';
import '../../widgets/day_part.dart' show banglaMonths, englishMonths;
import '../kit/widget_doc.dart';

/// ⭐ a5-এর ছয় উইজেটের সারাংশ পড়ার ছোট সাহায্য (২০ অক্টোবর ২০২৬) — ভিত্তির (kit) বাইরে, কেবল পড়া।
///
/// <p>⛔ নিয়ম: সারাংশে যে ঘর নেই, তার কিছুই আঁকা হয় না — "৳0.00" নয়, ফাঁকা সারিও নয়। ভুল ধরনের ঘর (সংখ্যার জায়গায়
/// তালিকা) মানে না-থাকা; ফেলে দেওয়া হয় না, ছুড়েও দেওয়া হয় না (throw নয়)। অচেনা বা বাড়তি ঘর পড়াই হয় না।
class SummaryReader {
  const SummaryReader._();

  /// একটা লেখা, বা null — ফাঁকা লেখাও null
  static String? text(Object? value) {
    if (value == null || value is Map || value is List) return null;
    final t = value.toString().trim();
    return t.isEmpty ? null : t;
  }

  /// গোটা সংখ্যা ("7" বা 7), বা null
  static int? integer(Object? value) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    final t = text(value);
    return t == null ? null : int.tryParse(t);
  }

  /// সার্ভারের দশমিক-লেখা ("482350.0000"), পড়া গেলে; নইলে null
  static String? decimal(Object? value) {
    final t = text(value);
    if (t == null || double.tryParse(t) == null) return null;
    return t;
  }

  /// টাকা — সবসময় [WidgetText.money] আর [Money.taka]; ঘর না থাকলে null (কখনো "৳0.00" নয়)
  static WidgetText? money(Object? value, {WidgetTone tone = WidgetTone.plain}) {
    final d = decimal(value);
    return d == null ? null : WidgetText.money(Money.taka(d), tone: tone);
  }

  static Map<String, dynamic>? map(Object? value) =>
      value is Map ? value.map((k, v) => MapEntry(k.toString(), v)) : null;

  /// সারির তালিকা — কেবল Map সারি; ভিত্তির সীমা মেনে সর্বোচ্চ [max]টা
  static List<Map<String, dynamic>> rows(Object? value, {int max = 8}) {
    if (value is! List) return const [];
    return [
      for (final r in value)
        if (map(r) case final m?) m,
    ].take(max).toList();
  }

  /// সারির পথ — সার্ভার যা দিয়েছে, কেবল এই সার্ভারের ভিতরের পথ ("/…"); বাইরের ঠিকানা ("https://…", "//…") নয়
  static String? link(Object? value) {
    final t = text(value);
    if (t == null || !t.startsWith('/') || t.startsWith('//')) return null;
    return t;
  }

  /// পক্ষের নাম — সার্ভারের তৈরি `label` ("নাম · পয়েন্ট · শাখা"); না থাকলে যা আছে তা জুড়ে
  static String? partyLabel(Object? value) {
    final p = map(value);
    if (p == null) return text(value);
    final label = text(p['label']);
    if (label != null) return label;
    final parts = [text(p['name']), text(p['point']), text(p['branch'])].whereType<String>().toList();
    return parts.isEmpty ? null : parts.join(' · ');
  }

  /// "আরও Nটা" — মোট গোনা সারির চেয়ে বেশি হলে
  static String? more(Object? count, int shown) {
    final n = integer(count);
    if (n == null || n <= shown) return null;
    return AppLocale.l10n.launcherWidgetMore('${n - shown}');
  }

  /// অঙ্ক মানুষের ভাষায় — বাংলায় বাংলা অঙ্ক, ইংরেজিতে যেমন আছে ([AppLocale.current]; a4-এর [top_bar.bnDigits])
  static String bnDigits(int n) => top_bar.bnDigits(n);

  /// মাসের নাম মানুষের ভাষায় — ১ = জানুয়ারি / January
  static String monthWord(int month) => (AppLocale.isEnglish() ? englishMonths : banglaMonths)[month - 1];

  /// "৩১ অক্টোবর"
  static String bnDate(DateTime day) => '${bnDigits(day.day)} ${monthWord(day.month)}';

  /// "2026-10-22" → তারিখ (দিনটুকুই), পড়া না গেলে null
  static DateTime? day(Object? value) {
    final t = text(value);
    if (t == null) return null;
    final m = RegExp(r'^(\d{4})-(\d{2})-(\d{2})').firstMatch(t);
    if (m == null) return null;
    final y = int.parse(m[1]!), mo = int.parse(m[2]!), d = int.parse(m[3]!);
    if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
    return DateTime.utc(y, mo, d);
  }

  /// সার্ভারের হিসাবের দিন, ঢাকার ঘড়িতে (UTC+6) — ফোনের নিজের সময়-অঞ্চল নয়, তাই খাঁটি
  static DateTime dhakaDay(DateTime asOf) {
    final local = asOf.toUtc().add(const Duration(hours: 6));
    return DateTime.utc(local.year, local.month, local.day);
  }
}
