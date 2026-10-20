import '../../records/money.dart';
import '../kit/widget_doc.dart';

/// cb-র ছয় উইজেটের (টাকা হাতে, বাকির সীমা, চেক, খরচ, লাভ, ব্যাংক মেলানো) ছোট সাধারণ সাহায্য — ২০ অক্টোবর ২০২৬।
///
/// <p>⓪ সারাংশের টাকা সার্ভারের bcmath স্ট্রিং ("1234.5000"); এখানে কেবল সাজানো — হিসাব নয়।

/// টাকা — ইংরেজি অঙ্ক, লাখের কমা, সবসময় গোপন-যোগ্য ([WidgetText.money])
WidgetText takaText(Object? value, {WidgetTone tone = WidgetTone.plain}) =>
    WidgetText.money(Money.taka(value ?? '0'), tone: tone);

/// সার্ভারের টাকা-স্ট্রিং শূন্যের বেশি কি না — পড়া না গেলে না
bool isPositive(Object? value) => (Money.value(value) ?? 0) > 0;

/// সার্ভারের টাকা-স্ট্রিং শূন্যের কম কি না
bool isNegative(Object? value) => (Money.value(value) ?? 0) < 0;

const _months = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
const _digits = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

/// বাংলা সংখ্যা — তারিখ আর গোনার জন্য (টাকা নয়: টাকা ইংরেজি অঙ্কে, মালিকের সিদ্ধান্ত)
String banglaNumber(Object value) => value.toString().split('').map((c) {
      final d = int.tryParse(c);
      return d == null ? c : _digits[d];
    }).join();

/// "2026-10-15" → "১৫ অক্টোবর"; পড়া না গেলে null
String? banglaDay(Object? iso) {
  final date = iso == null ? null : DateTime.tryParse(iso.toString());
  if (date == null) return null;
  return '${banglaNumber(date.day)} ${_months[date.month - 1]}';
}

/// "2026-10" → "অক্টোবর"; পড়া না গেলে null
String? banglaMonth(Object? ym) {
  final date = ym == null ? null : DateTime.tryParse('$ym-01');
  return date == null ? null : _months[date.month - 1];
}

/// সারির তালিকা — সার্ভার সর্বোচ্চ ৮টা পাঠায়, তবু এখানেও ৮-এ থামে (চুক্তি: মাপের সীমা ছাড়িয়ে সারি নয়)
List<Map<String, dynamic>> rowsOf(Map<String, dynamic> summary, {int max = 8}) => [
      for (final r in (summary['rows'] as List? ?? const []).take(max))
        if (r is Map) Map<String, dynamic>.from(r),
    ];

/// "আরও ৪টা" — সার্ভারের মোট গোনা দেখানো সারির চেয়ে বেশি হলে
String? moreOf(int total, int shown) => total > shown ? 'আরও ${banglaNumber(total - shown)}টা' : null;
