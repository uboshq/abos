import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

/// ⭐ অ্যাপের ভাষা — বাংলা (পূর্ণ নিরীক্ষা §৯ ⚠️৫, ১৮ অক্টোবর ২০২৬; মালিক কেবল বাংলা পড়েন)।
///
/// <p>⛔ আগে `flutter_localizations` ছিলই না: তারিখ বাছাইয়ের পর্দা (১০টা পর্দায়), "OK/CANCEL", কপি-পেস্টের মেনু, "Back" টিপ আর
/// মাসের নাম সব ইংরেজিতে আসত। ⓘ [MaterialApp] আর পরীক্ষা দুইটাই এখান থেকে পড়ে, তাই পরীক্ষা যা দেখে অ্যাপও তাই।
class AppLocale {
  const AppLocale._();

  static const Locale locale = Locale('bn');
  static const List<Locale> supported = [Locale('bn'), Locale('en')];
  static const List<LocalizationsDelegate<dynamic>> delegates = GlobalMaterialLocalizations.delegates;
}
