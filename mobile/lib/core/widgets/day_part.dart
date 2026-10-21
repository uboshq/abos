import 'dart:ui' show Locale;

import 'package:intl/intl.dart';

import '../../l10n/app_localizations.dart';
import '../locale/app_locale.dart';

/// The greeting a depot actually says at this hour.
///
/// <p>Takes a [DateTime] rather than reading the clock itself, so every
/// boundary can be checked in a test without faking one — the same shape the
/// Nexus app settled on after greeting people with "শুভ সকাল" at eight in the
/// evening because nothing ever looked at the clock.
///
/// <p>Night is the one band that wraps midnight, so the small hours are
/// handled first: written as a chain of ascending checks without that, one
/// in the morning falls through to whatever comes first.
///
/// <p>"শুভ ভোর" is not a greeting anybody says, and the late one is
/// "শুভ রাত্রি" rather than "শুভ রাত" — so this is its own small map, not a
/// day-part label with শুভ in front of it.
String banglaGreeting(DateTime when) {
  // ⓘ নাম অনুযায়ীই বাংলা — লেখা ARB-এর বাংলা ভাগ থেকে
  final w = lookupAppL10n(AppLocale.locale);
  final hour = when.hour;
  if (hour < 6) return w.dayGreetingNight;
  if (hour < 12) return w.dayGreetingMorning;
  if (hour < 15) return w.dayGreetingNoon;
  if (hour < 18) return w.dayGreetingAfternoon;
  if (hour < 20) return w.dayGreetingEvening;
  return w.dayGreetingNight;
}

/// রাত · ভোর · সকাল · দুপুর · বিকাল · সন্ধ্যা — the label that goes in front
/// of a clock time.
///
/// <p>Six, not three. Bangla names these parts and a depot says them; three
/// would collapse everything before noon into "সকাল", which is how two in the
/// morning becomes morning on a widget that is refreshed all night.
String banglaDayPart(DateTime when) {
  // ⓘ নাম অনুযায়ীই বাংলা — লেখা ARB-এর বাংলা ভাগ থেকে
  final w = lookupAppL10n(AppLocale.locale);
  final hour = when.hour;
  if (hour < 4) return w.dayPartNight;
  if (hour < 6) return w.dayPartDawn;
  if (hour < 12) return w.dayPartMorning;
  if (hour < 15) return w.dayPartNoon;
  if (hour < 18) return w.dayPartAfternoon;
  if (hour < 20) return w.dayPartEvening;
  return w.dayPartNight;
}

/// "সকাল 4:12" — a twelve-hour clock time with its part of day in front.
///
/// <p>The part of day is said once, here. A caller that also put
/// [banglaDayPart] in front would print "রাত রাত 12:30".
///
/// <p>Digits as the rest of this app writes them. A widget that counted in
/// one script beside an app that counts in another would be the same number
/// read two ways.
String banglaClock(DateTime when) {
  final hour = when.hour % 12 == 0 ? 12 : when.hour % 12;
  final minute = when.minute.toString().padLeft(2, '0');
  return '${banglaDayPart(when)} $hour:$minute';
}

/// ⭐ "20/10/2026 সকাল 10:00" — তারিখ আর সময় এক লাইনে, সব পর্দায় এক নিয়মে: অঙ্ক ইংরেজিতে, শব্দ বাংলায় (fe, ২০ অক্টোবর ২০২৬:
/// "ইংরেজি AM চলবে না")। ⛔ `DateFormat('… hh:mm a')` নয় — ওটা "AM/PM" লেখে।
String banglaStamp(DateTime when) => '${DateFormat('dd/MM/yyyy').format(when)} ${banglaClock(when)}';

/// ⭐ ঘড়ির লেখা মানুষের ভাষায় (0.4.43): বাংলায় [banglaClock] ("সকাল 10:00"), ইংরেজিতে "10:00 AM" — ইংরেজিতে AM/PM-ই ঠিক।
/// [locale] না দিলে [AppLocale.current]। ⓘ অঙ্ক দুই ভাষাতেই ইংরেজি (মালিকের নিয়ম)।
String clockText(DateTime when, [Locale? locale]) {
  if (!AppLocale.isEnglish(locale)) return banglaClock(when);
  final hour = when.hour % 12 == 0 ? 12 : when.hour % 12;
  final minute = when.minute.toString().padLeft(2, '0');
  return '$hour:$minute ${when.hour < 12 ? 'AM' : 'PM'}';
}

/// ⭐ তারিখ আর সময় এক লাইনে, মানুষের ভাষায়: বাংলায় [banglaStamp] ("20/10/2026 সকাল 10:00"), ইংরেজিতে "20/10/2026 10:00 AM"
String stampText(DateTime when, [Locale? locale]) =>
    AppLocale.isEnglish(locale) ? '${DateFormat('dd/MM/yyyy').format(when)} ${clockText(when, locale)}' : banglaStamp(when);

/// বারো মাসের নাম [w]-এর ভাষায় — জানুয়ারি থেকে
List<String> monthNames(AppL10n w) => [
      w.monthJanuary, w.monthFebruary, w.monthMarch, w.monthApril, w.monthMay, w.monthJune,
      w.monthJuly, w.monthAugust, w.monthSeptember, w.monthOctober, w.monthNovember, w.monthDecember,
    ];

/// বারো মাসের বাংলা নাম — জানুয়ারি থেকে
List<String> get banglaMonths => monthNames(lookupAppL10n(AppLocale.locale));

/// বারো মাসের ইংরেজি নাম — জানুয়ারি থেকে
const List<String> englishMonths = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

/// ⭐ "সেপ্টেম্বর 2026" বা "September 2026" — মাস আর বছর মানুষের ভাষায় ([locale] না দিলে [AppLocale.current]); বছর ইংরেজি অঙ্কে
String monthText(DateTime month, [Locale? locale]) =>
    '${monthNames(lookupAppL10n(locale ?? AppLocale.current))[month.month - 1]} ${month.year}';
