// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Bengali Bangla (`bn`).
class AppL10nBn extends AppL10n {
  AppL10nBn([String locale = 'bn']) : super(locale);

  @override
  String get commonOpen => 'খুলুন';

  @override
  String get commonCancel => 'বাতিল';

  @override
  String get commonSave => 'রাখুন';

  @override
  String get commonRetry => 'আবার চেষ্টা করুন';

  @override
  String commonPaperCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা কাগজ',
      zero: 'কোনো কাগজ নেই',
    );
    return '$_temp0';
  }

  @override
  String commonPaperState(String state) {
    String _temp0 = intl.Intl.selectLogic(
      state,
      {
        'draft': 'খসড়া',
        'posted': 'পোস্ট হয়েছে',
        'cancelled': 'বাতিল',
        'other': 'অজানা',
      },
    );
    return '$_temp0';
  }

  @override
  String get topBarBell => 'বিজ্ঞপ্তি';

  @override
  String topBarBellUnread(String count) {
    return 'বিজ্ঞপ্তি, $countটা না-পড়া';
  }

  @override
  String get topBarBellNews => 'বিজ্ঞপ্তি, নতুন খবর আছে';

  @override
  String get avatarMenuClose => 'মেনু বন্ধ';

  @override
  String get avatarMenuSwitchCompany => 'কোম্পানি বদল';

  @override
  String get avatarMenuColour => 'রং';

  @override
  String get avatarMenuThemePhone => 'ফোনের মতো';

  @override
  String get avatarMenuThemeLight => 'আলো';

  @override
  String get avatarMenuThemeDark => 'অন্ধকার';

  @override
  String get avatarMenuLanguage => 'ভাষা';

  @override
  String get avatarMenuLanguagePhone => 'ফোনের মতো';

  @override
  String get avatarMenuPinAndFingerprint => 'পিন ও আঙুলের ছাপ';

  @override
  String avatarMenuLockAfter(String delay) {
    return 'তালার সময়: $delay';
  }

  @override
  String get avatarMenuMyPhones => 'আমার ফোনগুলো';

  @override
  String avatarMenuSettingsVersion(String version) {
    return 'সেটিংস · সংস্করণ $version';
  }

  @override
  String get avatarMenuSignOut => 'বের হওয়া';

  @override
  String get homeStockValue => 'মজুদের মূল্য';

  @override
  String get homeStockValueBasis => 'খরচ-দামে';

  @override
  String get homeStockValueOpenHint => 'মজুদের পাতা খুলুন';

  @override
  String get homeStockPageTitle => 'মজুদ';
}
