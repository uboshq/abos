import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/widgets/day_part.dart';
import 'package:abos_mobile/features/finance/finance_screens.dart' show monthWords;

/// ⭐ সময়, মাস আর ঘণ্টার কথা মানুষের ভাষায় (0.4.43): বাংলায় আজকের মতো হুবহু, ইংরেজিতে ইংরেজির নিয়মে; অঙ্ক দুই ভাষাতেই ইংরেজি।
void main() {
  tearDown(() => AppLocale.current = AppLocale.locale);
  const en = Locale('en');
  const bn = Locale('bn');

  test('a stamp in English reads "20/10/2026 10:00 AM"; in Bangla as before', () {
    expect(stampText(DateTime(2026, 10, 20, 10), en), '20/10/2026 10:00 AM');
    expect(stampText(DateTime(2026, 10, 20, 0, 30), en), '20/10/2026 12:30 AM');
    expect(stampText(DateTime(2026, 10, 20, 12, 5), en), '20/10/2026 12:05 PM');
    expect(stampText(DateTime(2026, 10, 20, 19, 45), en), '20/10/2026 7:45 PM');
    expect(stampText(DateTime(2026, 10, 20, 10), bn), '20/10/2026 সকাল 10:00');
    expect(clockText(DateTime(2026, 10, 20, 13, 5), bn), banglaClock(DateTime(2026, 10, 20, 13, 5)));
  });

  test('without a locale they follow AppLocale.current', () {
    expect(stampText(DateTime(2026, 10, 20, 10)), '20/10/2026 সকাল 10:00');
    AppLocale.current = AppLocale.english;
    expect(stampText(DateTime(2026, 10, 20, 10)), '20/10/2026 10:00 AM');
    expect(monthWords('2026-09-01'), 'September 2026');
  });

  test('month and year in the language, the year in English digits', () {
    expect(monthWords('2026-09-01', bn), 'সেপ্টেম্বর 2026');
    expect(monthWords('2026-09-01', en), 'September 2026');
    expect(monthText(DateTime(2026, 1), en), 'January 2026');
    expect(monthWords(null, en), '—');
  });

  test('the bell speaks the language: "Notifications, 3 unread"', () {
    expect(bellLabel(3, locale: en), 'Notifications, 3 unread');
    expect(bellLabel(0, news: true, locale: en), 'Notifications, something new');
    expect(bellLabel(null, locale: en), 'Notifications');
    expect(bellLabel(3, locale: bn), 'বিজ্ঞপ্তি, ৩টা না-পড়া');
    expect(bellLabel(3), 'বিজ্ঞপ্তি, ৩টা না-পড়া', reason: 'বাংলা আজকের মতো হুবহু');
    expect(bellLabel(0, news: true), 'বিজ্ঞপ্তি, নতুন খবর আছে');
    expect(bnDigits(42, en), '42');
    expect(bnDigits(42), '৪২');
  });
}
