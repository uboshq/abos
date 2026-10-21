import 'package:flutter/widgets.dart';

import '../../l10n/app_localizations.dart';

/// ⭐ অ্যাপের ভাষা — বাংলা (মূল) আর ইংরেজি (পূর্ণ নিরীক্ষা §৯ ⚠️৫, ১৮ অক্টোবর ২০২৬; ভাষার সুইচ 0.4.43)।
///
/// <p>⛔ আগে `flutter_localizations` ছিলই না: তারিখ বাছাইয়ের পর্দা (১০টা পর্দায়), "OK/CANCEL", কপি-পেস্টের মেনু, "Back" টিপ আর
/// মাসের নাম সব ইংরেজিতে আসত। ⓘ [MaterialApp] আর পরীক্ষা দুইটাই এখান থেকে পড়ে, তাই পরীক্ষা যা দেখে অ্যাপও তাই।
///
/// <p>⭐ [current] — এই মুহূর্তের ভাষা, উইজেট-গাছের বাইরের কোডের জন্য (ApiClient-এর `Accept-Language`, সময়ের লেখা, উইজেটের
/// নথি)। [LocaleChoices] প্রতিটা বাছাইয়ে আর শুরুর পড়ায় এটা বসায়; ⛔ নিজে হাতে বসানো নয়।
class AppLocale {
  const AppLocale._();

  /// মূল ভাষা — বাংলা; সুইচ চালু না হওয়া পর্যন্ত অ্যাপ সবসময় এটাই
  static const Locale locale = Locale('bn');
  static const Locale english = Locale('en');
  static const List<Locale> supported = [Locale('bn'), Locale('en')];

  /// AppL10n-এর নিজেরটা, সাথে Material/Cupertino/Widgets-এর বাংলা-ইংরেজি
  static const List<LocalizationsDelegate<dynamic>> delegates = AppL10n.localizationsDelegates;

  /// এখনকার ভাষা — [LocaleChoices] বসায়
  static Locale current = locale;

  /// ইংরেজি কি না — না হলে বাংলা
  static bool isEnglish([Locale? of]) => (of ?? current).languageCode == 'en';

  /// উইজেট-গাছের বাইরের লেখা (ApiClient, পটভূমির উইজেট, সাহায্যকারী ফাংশন) — [current] ভাষায়
  static AppL10n get l10n => lookupAppL10n(current);

  /// সার্ভারকে পাঠানোর ছোট কোড: "bn" বা "en"
  static String get code => isEnglish() ? 'en' : 'bn';
}
