import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_bn.dart';
import 'app_localizations_en.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppL10n
/// returned by `AppL10n.of(context)`.
///
/// Applications need to include `AppL10n.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppL10n.localizationsDelegates,
///   supportedLocales: AppL10n.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppL10n.supportedLocales
/// property.
abstract class AppL10n {
  AppL10n(String locale)
      : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppL10n of(BuildContext context) {
    return Localizations.of<AppL10n>(context, AppL10n)!;
  }

  static const LocalizationsDelegate<AppL10n> delegate = _AppL10nDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
    delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
  ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('bn'),
    Locale('en')
  ];

  /// Button: open a paper or a page
  ///
  /// In bn, this message translates to:
  /// **'খুলুন'**
  String get commonOpen;

  /// Button: cancel and close a dialog
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get commonCancel;

  /// Button: save what was written
  ///
  /// In bn, this message translates to:
  /// **'রাখুন'**
  String get commonSave;

  /// Button: try the failed thing again
  ///
  /// In bn, this message translates to:
  /// **'আবার চেষ্টা করুন'**
  String get commonRetry;

  /// How many papers (plural example). Bengali uses 'other' for every number; English uses one/other.
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, =0{কোনো কাগজ নেই} other{{count}টা কাগজ}}'**
  String commonPaperCount(int count);

  /// A paper's state word (select example); the server's state code picks the word
  ///
  /// In bn, this message translates to:
  /// **'{state, select, draft{খসড়া} posted{পোস্ট হয়েছে} cancelled{বাতিল} other{অজানা}}'**
  String commonPaperState(String state);

  /// Screen reader: the bell, nothing new
  ///
  /// In bn, this message translates to:
  /// **'বিজ্ঞপ্তি'**
  String get topBarBell;

  /// Screen reader: the bell with unread notifications; count is already written in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'বিজ্ঞপ্তি, {count}টা না-পড়া'**
  String topBarBellUnread(String count);

  /// Screen reader: the bell when there is news but no count
  ///
  /// In bn, this message translates to:
  /// **'বিজ্ঞপ্তি, নতুন খবর আছে'**
  String get topBarBellNews;

  /// Screen reader: the dim area behind the avatar menu closes it
  ///
  /// In bn, this message translates to:
  /// **'মেনু বন্ধ'**
  String get avatarMenuClose;

  /// Avatar menu row: switch to another company
  ///
  /// In bn, this message translates to:
  /// **'কোম্পানি বদল'**
  String get avatarMenuSwitchCompany;

  /// Avatar menu: heading of the light/dark choice
  ///
  /// In bn, this message translates to:
  /// **'রং'**
  String get avatarMenuColour;

  /// Theme choice: follow the phone's light/dark setting
  ///
  /// In bn, this message translates to:
  /// **'ফোনের মতো'**
  String get avatarMenuThemePhone;

  /// Theme choice: light
  ///
  /// In bn, this message translates to:
  /// **'আলো'**
  String get avatarMenuThemeLight;

  /// Theme choice: dark
  ///
  /// In bn, this message translates to:
  /// **'অন্ধকার'**
  String get avatarMenuThemeDark;

  /// Avatar menu: heading of the language choice
  ///
  /// In bn, this message translates to:
  /// **'ভাষা'**
  String get avatarMenuLanguage;

  /// Language choice: follow the phone's language (Bangla phone: Bangla, else English)
  ///
  /// In bn, this message translates to:
  /// **'ফোনের মতো'**
  String get avatarMenuLanguagePhone;

  /// Avatar menu row: the app lock's PIN and fingerprint
  ///
  /// In bn, this message translates to:
  /// **'পিন ও আঙুলের ছাপ'**
  String get avatarMenuPinAndFingerprint;

  /// Screen reader hint on the lock row: after how long the app locks
  ///
  /// In bn, this message translates to:
  /// **'তালার সময়: {delay}'**
  String avatarMenuLockAfter(String delay);

  /// Avatar menu row: the phones this person is signed in on
  ///
  /// In bn, this message translates to:
  /// **'আমার ফোনগুলো'**
  String get avatarMenuMyPhones;

  /// Avatar menu row: settings, with the app's version
  ///
  /// In bn, this message translates to:
  /// **'সেটিংস · সংস্করণ {version}'**
  String avatarMenuSettingsVersion(String version);

  /// Avatar menu row: sign out
  ///
  /// In bn, this message translates to:
  /// **'বের হওয়া'**
  String get avatarMenuSignOut;

  /// Coming-soon screen: bar title when none is given, and the empty-state title
  ///
  /// In bn, this message translates to:
  /// **'শীঘ্রই আসছে'**
  String get salesDeskComingSoonTitle;

  /// Coming-soon screen: the message under the title
  ///
  /// In bn, this message translates to:
  /// **'এই পর্দাটা এখনো তৈরি হচ্ছে। তৈরি হলে এখানেই খুলবে।'**
  String get salesDeskComingSoonMessage;

  /// Today's deliveries: the list could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get deliveriesTodayLoadFailed;

  /// Today's deliveries: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে আজকের ডেলিভারি এখনো আসেনি — অফিসে জানান।'**
  String get deliveriesTodayNotOnServer;

  /// Today's deliveries: the next page could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'পরের পাতা আনা গেল না। আবার চেষ্টা করুন।'**
  String get deliveriesTodayMoreFailed;

  /// Today's deliveries: notice after a partial hand-over; no is the challan number
  ///
  /// In bn, this message translates to:
  /// **'{no}: আংশিক পৌঁছেছে — কম আর ভাঙা মাল ফেরতের কাগজে উঠেছে।'**
  String deliveriesTodayPartlyDelivered(String no);

  /// Today's deliveries: notice after a full hand-over; no is the challan number, receiver the person's name
  ///
  /// In bn, this message translates to:
  /// **'{no}: পৌঁছেছে — {receiver} বুঝে নিলেন।'**
  String deliveriesTodayDelivered(String no, String receiver);

  /// Today's deliveries: the hand-over could not be saved
  ///
  /// In bn, this message translates to:
  /// **'পৌঁছানো লেখা গেল না। আবার চেষ্টা করুন।'**
  String get deliveriesTodaySaveFailed;

  /// Today's deliveries: screen title
  ///
  /// In bn, this message translates to:
  /// **'আজকের ডেলিভারি'**
  String get deliveriesTodayTitle;

  /// Today's deliveries: total strip label (all pages)
  ///
  /// In bn, this message translates to:
  /// **'পথে মোট চালান'**
  String get deliveriesTodayTotalLabel;

  /// Today's deliveries: total strip value, the number of challans
  ///
  /// In bn, this message translates to:
  /// **'{count} টি'**
  String deliveriesTodayTotalValue(String count);

  /// Today's deliveries: nothing on the road
  ///
  /// In bn, this message translates to:
  /// **'পথে কোনো চালান নেই।'**
  String get deliveriesTodayEmpty;

  /// Today's deliveries: the vehicle on a card
  ///
  /// In bn, this message translates to:
  /// **'গাড়ি {vehicle}'**
  String deliveriesTodayVehicle(String vehicle);

  /// Today's deliveries: the driver on a card
  ///
  /// In bn, this message translates to:
  /// **'চালক {driver}'**
  String deliveriesTodayDriver(String driver);

  /// Today's deliveries: button that records the hand-over to the buyer
  ///
  /// In bn, this message translates to:
  /// **'বুঝিয়ে দিন'**
  String get deliveriesTodayHandOver;

  /// Today's deliveries: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও দেখুন'**
  String get deliveriesTodayMore;

  /// My route: the weekday on the day button (mon … sat, other = Sunday)
  ///
  /// In bn, this message translates to:
  /// **'{day, select, mon{সোমবার} tue{মঙ্গলবার} wed{বুধবার} thu{বৃহস্পতিবার} fri{শুক্রবার} sat{শনিবার} other{রবিবার}}'**
  String routeMyWeekday(String day);

  /// My route: the route could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'রুট আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get routeMyLoadFailed;

  /// My route: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে রুটের দরজা এখনো আসেনি — অফিসে জানান।'**
  String get routeMyNotOnServer;

  /// My route: date picker heading
  ///
  /// In bn, this message translates to:
  /// **'কোন দিনের রুট'**
  String get routeMyPickDay;

  /// My route: screen title
  ///
  /// In bn, this message translates to:
  /// **'আজকের রুট'**
  String get routeMyTitle;

  /// My route: error card title
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get routeMyErrorTitle;

  /// My route: empty state title
  ///
  /// In bn, this message translates to:
  /// **'এই দিনে কোনো রুট নেই'**
  String get routeMyNoRouteTitle;

  /// My route: empty state message
  ///
  /// In bn, this message translates to:
  /// **'সাপ্তাহিক ছকে এই দিনে আপনার নামে রুট বসানো নেই — অফিসে জানান।'**
  String get routeMyNoRouteMessage;

  /// My route: a shop's sales this month; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'এ মাসে বিক্রি: {amount}'**
  String routeMyMonthSales(String amount);

  /// My route: a shop's collections this month; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'এ মাসে আদায়: {amount}'**
  String routeMyMonthCollections(String amount);

  /// My route: what the shop owes; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া: {amount}'**
  String routeMyOutstanding(String amount);

  /// My route: load the next page of shops
  ///
  /// In bn, this message translates to:
  /// **'আরও দোকান'**
  String get routeMyMoreShops;

  /// Business dashboards: the list could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'ড্যাশবোর্ডের তালিকা আনা গেল না।'**
  String get salesDashboardsListLoadFailed;

  /// Business dashboards: screen title
  ///
  /// In bn, this message translates to:
  /// **'ব্যবসার ড্যাশবোর্ড'**
  String get salesDashboardsTitle;

  /// Dashboards: the module name in the bar crumbs
  ///
  /// In bn, this message translates to:
  /// **'ড্যাশবোর্ড'**
  String get salesDashboardsModule;

  /// Business dashboards: empty state title
  ///
  /// In bn, this message translates to:
  /// **'আপনার জন্য কোনো ড্যাশবোর্ড নেই'**
  String get salesDashboardsNoneTitle;

  /// Business dashboards: empty state message
  ///
  /// In bn, this message translates to:
  /// **'কোনো মডিউলের ড্যাশবোর্ড দেখার অনুমতি পেলে এখানে আসবে।'**
  String get salesDashboardsNoneMessage;

  /// Dashboards: a figure this person has no key (permission) to see, short form
  ///
  /// In bn, this message translates to:
  /// **'চাবি নেই'**
  String get salesDashboardsNoKey;

  /// Module dashboard: could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'ড্যাশবোর্ড আনা গেল না।'**
  String get salesDashboardsLoadFailed;

  /// Module dashboard: empty state title
  ///
  /// In bn, this message translates to:
  /// **'এই ড্যাশবোর্ডে এখনো কিছু নেই'**
  String get salesDashboardsEmptyTitle;

  /// Module dashboard: empty state message
  ///
  /// In bn, this message translates to:
  /// **'কাজ শুরু হলে বা অনুমতি পেলে সংখ্যাগুলো এখানে আসবে।'**
  String get salesDashboardsEmptyMessage;

  /// Module dashboard: button that opens the module reports
  ///
  /// In bn, this message translates to:
  /// **'এই মডিউলের রিপোর্ট'**
  String get salesDashboardsReports;

  /// Module dashboard: the reports screen title; name is the dashboard name
  ///
  /// In bn, this message translates to:
  /// **'{name} — রিপোর্ট'**
  String salesDashboardsReportsTitle(String name);

  /// Sales overview: could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়ের হালচাল আনা গেল না।'**
  String get salesDashboardsOverviewLoadFailed;

  /// Sales overview: screen title
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়ের হালচাল'**
  String get salesDashboardsOverviewTitle;

  /// Sales overview: the module name in the bar crumbs
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়'**
  String get salesDashboardsSalesModule;

  /// Sales overview: every figure is hidden from this person
  ///
  /// In bn, this message translates to:
  /// **'এই পর্দার কোনো সংখ্যা দেখার অনুমতি আপনার নেই।'**
  String get salesDashboardsOverviewNoAccess;

  /// Dashboards: a hidden figure on a stat card
  ///
  /// In bn, this message translates to:
  /// **'চাবি নেই — দেখার অনুমতি লাগে'**
  String get salesDashboardsNoKeyLong;

  /// Dashboards: empty-state button that loads again
  ///
  /// In bn, this message translates to:
  /// **'নতুন করে দেখুন'**
  String get salesDashboardsRefresh;

  /// Dashboards: the total row under a short list
  ///
  /// In bn, this message translates to:
  /// **'সর্বমোট'**
  String get salesDashboardsGrandTotal;
}

class _AppL10nDelegate extends LocalizationsDelegate<AppL10n> {
  const _AppL10nDelegate();

  @override
  Future<AppL10n> load(Locale locale) {
    return SynchronousFuture<AppL10n>(lookupAppL10n(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['bn', 'en'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppL10nDelegate old) => false;
}

AppL10n lookupAppL10n(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'bn':
      return AppL10nBn();
    case 'en':
      return AppL10nEn();
  }

  throw FlutterError(
      'AppL10n.delegate failed to load unsupported locale "$locale". This is likely '
      'an issue with the localizations generation tool. Please file an issue '
      'on GitHub with a reproducible sample app and the gen-l10n configuration '
      'that was used.');
}
