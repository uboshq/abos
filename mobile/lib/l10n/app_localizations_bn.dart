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
  String get salesDeskComingSoonTitle => 'শীঘ্রই আসছে';

  @override
  String get salesDeskComingSoonMessage =>
      'এই পর্দাটা এখনো তৈরি হচ্ছে। তৈরি হলে এখানেই খুলবে।';

  @override
  String get deliveriesTodayLoadFailed =>
      'ডেলিভারির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get deliveriesTodayNotOnServer =>
      'সার্ভারে আজকের ডেলিভারি এখনো আসেনি — অফিসে জানান।';

  @override
  String get deliveriesTodayMoreFailed =>
      'পরের পাতা আনা গেল না। আবার চেষ্টা করুন।';

  @override
  String deliveriesTodayPartlyDelivered(String no) {
    return '$no: আংশিক পৌঁছেছে — কম আর ভাঙা মাল ফেরতের কাগজে উঠেছে।';
  }

  @override
  String deliveriesTodayDelivered(String no, String receiver) {
    return '$no: পৌঁছেছে — $receiver বুঝে নিলেন।';
  }

  @override
  String get deliveriesTodaySaveFailed =>
      'পৌঁছানো লেখা গেল না। আবার চেষ্টা করুন।';

  @override
  String get deliveriesTodayTitle => 'আজকের ডেলিভারি';

  @override
  String get deliveriesTodayTotalLabel => 'পথে মোট চালান';

  @override
  String deliveriesTodayTotalValue(String count) {
    return '$count টি';
  }

  @override
  String get deliveriesTodayEmpty => 'পথে কোনো চালান নেই।';

  @override
  String deliveriesTodayVehicle(String vehicle) {
    return 'গাড়ি $vehicle';
  }

  @override
  String deliveriesTodayDriver(String driver) {
    return 'চালক $driver';
  }

  @override
  String get deliveriesTodayHandOver => 'বুঝিয়ে দিন';

  @override
  String get deliveriesTodayMore => 'আরও দেখুন';

  @override
  String routeMyWeekday(String day) {
    String _temp0 = intl.Intl.selectLogic(
      day,
      {
        'mon': 'সোমবার',
        'tue': 'মঙ্গলবার',
        'wed': 'বুধবার',
        'thu': 'বৃহস্পতিবার',
        'fri': 'শুক্রবার',
        'sat': 'শনিবার',
        'other': 'রবিবার',
      },
    );
    return '$_temp0';
  }

  @override
  String get routeMyLoadFailed => 'রুট আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get routeMyNotOnServer =>
      'সার্ভারে রুটের দরজা এখনো আসেনি — অফিসে জানান।';

  @override
  String get routeMyPickDay => 'কোন দিনের রুট';

  @override
  String get routeMyTitle => 'আজকের রুট';

  @override
  String get routeMyErrorTitle => 'আনা গেল না';

  @override
  String get routeMyNoRouteTitle => 'এই দিনে কোনো রুট নেই';

  @override
  String get routeMyNoRouteMessage =>
      'সাপ্তাহিক ছকে এই দিনে আপনার নামে রুট বসানো নেই — অফিসে জানান।';

  @override
  String routeMyMonthSales(String amount) {
    return 'এ মাসে বিক্রি: $amount';
  }

  @override
  String routeMyMonthCollections(String amount) {
    return 'এ মাসে আদায়: $amount';
  }

  @override
  String routeMyOutstanding(String amount) {
    return 'বকেয়া: $amount';
  }

  @override
  String get routeMyMoreShops => 'আরও দোকান';

  @override
  String get salesDashboardsListLoadFailed => 'ড্যাশবোর্ডের তালিকা আনা গেল না।';

  @override
  String get salesDashboardsTitle => 'ব্যবসার ড্যাশবোর্ড';

  @override
  String get salesDashboardsModule => 'ড্যাশবোর্ড';

  @override
  String get salesDashboardsNoneTitle => 'আপনার জন্য কোনো ড্যাশবোর্ড নেই';

  @override
  String get salesDashboardsNoneMessage =>
      'কোনো মডিউলের ড্যাশবোর্ড দেখার অনুমতি পেলে এখানে আসবে।';

  @override
  String get salesDashboardsNoKey => 'চাবি নেই';

  @override
  String get salesDashboardsLoadFailed => 'ড্যাশবোর্ড আনা গেল না।';

  @override
  String get salesDashboardsEmptyTitle => 'এই ড্যাশবোর্ডে এখনো কিছু নেই';

  @override
  String get salesDashboardsEmptyMessage =>
      'কাজ শুরু হলে বা অনুমতি পেলে সংখ্যাগুলো এখানে আসবে।';

  @override
  String get salesDashboardsReports => 'এই মডিউলের রিপোর্ট';

  @override
  String salesDashboardsReportsTitle(String name) {
    return '$name — রিপোর্ট';
  }

  @override
  String get salesDashboardsOverviewLoadFailed =>
      'বিক্রয়ের হালচাল আনা গেল না।';

  @override
  String get salesDashboardsOverviewTitle => 'বিক্রয়ের হালচাল';

  @override
  String get salesDashboardsSalesModule => 'বিক্রয়';

  @override
  String get salesDashboardsOverviewNoAccess =>
      'এই পর্দার কোনো সংখ্যা দেখার অনুমতি আপনার নেই।';

  @override
  String get salesDashboardsNoKeyLong => 'চাবি নেই — দেখার অনুমতি লাগে';

  @override
  String get salesDashboardsRefresh => 'নতুন করে দেখুন';

  @override
  String get salesDashboardsGrandTotal => 'সর্বমোট';
}
