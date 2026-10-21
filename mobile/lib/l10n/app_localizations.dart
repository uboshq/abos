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

  /// Quotation compare: the list to pick from could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'উদ্ধৃতির তালিকা আনা গেল না।'**
  String get quotationsCompareListLoadFailed;

  /// Quotation compare: the comparison could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'তুলনা আনা গেল না।'**
  String get quotationsCompareLoadFailed;

  /// Quotation compare: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'তুলনা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get quotationsCompareNotOnServer;

  /// Quotation compare: screen title
  ///
  /// In bn, this message translates to:
  /// **'উদ্ধৃতির তুলনা'**
  String get quotationsCompareTitle;

  /// Quotation compare: tooltip of the button that goes back to the picker
  ///
  /// In bn, this message translates to:
  /// **'আবার বাছুন'**
  String get quotationsCompareRepick;

  /// Quotation compare: only one quotation came back
  ///
  /// In bn, this message translates to:
  /// **'তুলনার জন্য অন্তত দুটো লাগে'**
  String get quotationsCompareNeedTwoTitle;

  /// Quotation compare: only one quotation came back, the hint
  ///
  /// In bn, this message translates to:
  /// **'আরেকটা উদ্ধৃতি বাছুন।'**
  String get quotationsCompareNeedTwoMessage;

  /// Quotation compare: nothing to pick from
  ///
  /// In bn, this message translates to:
  /// **'বাছার মতো কোনো উদ্ধৃতি নেই'**
  String get quotationsCompareNoChoices;

  /// Quotation compare: the picker hint; max is the most that can be picked
  ///
  /// In bn, this message translates to:
  /// **'দুই থেকে {max}টা উদ্ধৃতি বাছুন — প্রথমটা ভিত্তি'**
  String quotationsComparePickHint(String max);

  /// Quotation compare: the button, with how many are picked
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count}টা তুলনা করুন}}'**
  String quotationsCompareGo(int count);

  /// Quotation compare: after the quotation number when it is a revision
  ///
  /// In bn, this message translates to:
  /// **' (সং {revision})'**
  String quotationsCompareRevision(String revision);

  /// Quotation revisions: the list could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'সংস্করণের তালিকা আনা গেল না।'**
  String get quotationsRevisionsLoadFailed;

  /// Quotation revisions: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get quotationsRevisionsNotOnServer;

  /// Quotation revisions: screen title
  ///
  /// In bn, this message translates to:
  /// **'উদ্ধৃতির সংস্করণ'**
  String get quotationsRevisionsTitle;

  /// Quotation revisions: search hint
  ///
  /// In bn, this message translates to:
  /// **'নম্বর বা দোকানের নাম'**
  String get quotationsRevisionsSearch;

  /// Quotation revisions: empty state title
  ///
  /// In bn, this message translates to:
  /// **'কোনো উদ্ধৃতির সংস্করণ নেই'**
  String get quotationsRevisionsEmptyTitle;

  /// Quotation revisions: empty state message
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার দেখুন।'**
  String get quotationsRevisionsEmptyMessage;

  /// Quotation revisions: how many revisions a family has
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count}টা সংস্করণ}}'**
  String quotationsRevisionsCount(int count);

  /// Quotation revisions: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get quotationsRevisionsMore;

  /// Sales desk pages: the default empty title
  ///
  /// In bn, this message translates to:
  /// **'কিছু নেই'**
  String get salesDeskNothing;

  /// Sales desk pages: the page could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get salesDeskLoadFailed;

  /// Sales desk pages: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের এই পর্দা এখনো আসেনি — অফিসে জানান।'**
  String get salesDeskNotOnServer;

  /// Sales desk pages: error card title
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get salesDeskErrorTitle;

  /// Depot check: screen title
  ///
  /// In bn, this message translates to:
  /// **'ডিপোর যাচাই'**
  String get salesDeskDepotTitle;

  /// Depot check: empty title
  ///
  /// In bn, this message translates to:
  /// **'যাচাইয়ের অপেক্ষায় কিছু নেই'**
  String get salesDeskDepotEmpty;

  /// Depot check: search hint
  ///
  /// In bn, this message translates to:
  /// **'নম্বর, দোকানের নাম বা কোড'**
  String get salesDeskDepotSearch;

  /// Depot check: total strip label; DO is a delivery order
  ///
  /// In bn, this message translates to:
  /// **'যাচাইয়ের অপেক্ষায় DO'**
  String get salesDeskDepotTotal;

  /// Depot check: note that the next step is on the web
  ///
  /// In bn, this message translates to:
  /// **'ⓘ যাচাই করে বিক্রয়ে খোলা এখনো ওয়েবের কাউন্টারে।'**
  String get salesDeskDepotWebNote;

  /// Depot check: the second line of an order card; status comes from the server
  ///
  /// In bn, this message translates to:
  /// **'{date} · {lines, plural, other{{lines}টি পণ্য}} · {status}'**
  String salesDeskDepotLine(String date, int lines, String status);

  /// Depot check: heading of the held sales orders
  ///
  /// In bn, this message translates to:
  /// **'ধরে রাখা বিক্রয় আদেশ'**
  String get salesDeskDepotHeld;

  /// Print queue: screen title
  ///
  /// In bn, this message translates to:
  /// **'না ছাপা কাগজ'**
  String get salesDeskPrintTitle;

  /// Print queue: empty title
  ///
  /// In bn, this message translates to:
  /// **'সব কাগজ ছাপা হয়েছে'**
  String get salesDeskPrintEmpty;

  /// Sales desk: how many rows in all
  ///
  /// In bn, this message translates to:
  /// **'মোট {count} টি'**
  String salesDeskTotalCount(String count);

  /// Print queue: which paper; paper comes from the server
  ///
  /// In bn, this message translates to:
  /// **'কাগজ: {paper}'**
  String salesDeskPrintPaper(String paper);

  /// Print queue: why the print failed; reason comes from the server
  ///
  /// In bn, this message translates to:
  /// **'কারণ: {reason}'**
  String salesDeskPrintReason(String reason);

  /// Print queue: snack bar after marking a job printed by hand
  ///
  /// In bn, this message translates to:
  /// **'ছাপা হয়েছে বলে সরানো হলো।'**
  String get salesDeskPrintSettled;

  /// Print queue: marking printed failed
  ///
  /// In bn, this message translates to:
  /// **'সরানো গেল না।'**
  String get salesDeskPrintSettleFailed;

  /// Print queue: button that marks a job printed by hand
  ///
  /// In bn, this message translates to:
  /// **'হাতে ছাপা হলো'**
  String get salesDeskPrintSettle;

  /// Delivery performance: screen title
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারির কাজ'**
  String get salesDeskDeliveryTitle;

  /// Delivery performance: heading of the late challans, with their count
  ///
  /// In bn, this message translates to:
  /// **'দেরির চালান · {count} টি'**
  String salesDeskDeliveryLate(String count);

  /// Delivery performance: how many days late
  ///
  /// In bn, this message translates to:
  /// **'{days} দিন দেরি'**
  String salesDeskDeliveryDaysLate(String days);

  /// Delivery performance: the promised and the arrival date
  ///
  /// In bn, this message translates to:
  /// **'কথা ছিল {promised} · পৌঁছেছে {arrived}'**
  String salesDeskDeliveryPromised(String promised, String arrived);

  /// Delivery performance: the order number of a late challan
  ///
  /// In bn, this message translates to:
  /// **'আদেশ {no}'**
  String salesDeskDeliveryOrder(String no);

  /// Pipeline: screen title
  ///
  /// In bn, this message translates to:
  /// **'পাইপলাইন'**
  String get salesDeskPipelineTitle;

  /// Pipeline: empty title
  ///
  /// In bn, this message translates to:
  /// **'কোনো সম্ভাবনা নেই'**
  String get salesDeskPipelineEmpty;

  /// Pipeline: total strip of the open stages, weighted
  ///
  /// In bn, this message translates to:
  /// **'চলমান সম্ভাবনা (ওজন-করা)'**
  String get salesDeskPipelineOpen;

  /// Pipeline: a stage and how many in it
  ///
  /// In bn, this message translates to:
  /// **'{stage} · {count} টি'**
  String salesDeskPipelineStage(String stage, String count);

  /// Pipeline: a stage total and weighted total; amounts from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'মোট {total} · ওজন-করা {weighted}'**
  String salesDeskPipelineTotals(String total, String weighted);

  /// Pipeline: the expected close date of an opportunity
  ///
  /// In bn, this message translates to:
  /// **'শেষ {date}'**
  String salesDeskPipelineCloses(String date);

  /// Document templates: screen title
  ///
  /// In bn, this message translates to:
  /// **'দলিলের ছাঁচ'**
  String get salesDeskTemplatesTitle;

  /// Document templates: empty title
  ///
  /// In bn, this message translates to:
  /// **'কোনো ছাঁচ নেই'**
  String get salesDeskTemplatesEmpty;

  /// Document templates: the count, and where to edit
  ///
  /// In bn, this message translates to:
  /// **'মোট {count} টি ছাঁচ · লেখা ও বদল ওয়েবে'**
  String salesDeskTemplatesCount(String count);

  /// Document templates: a template that is switched off
  ///
  /// In bn, this message translates to:
  /// **'বন্ধ'**
  String get salesDeskTemplatesInactive;

  /// Sales papers: the name of the paper kind (invoice, or other = challan), used as a title
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, invoice{বিক্রয় বিল} other{চালান}}'**
  String salesPapersKindTitle(String kind);

  /// Sales papers: the list could not be loaded (invoice, or other = challan)
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, invoice{বিক্রয় বিলের তালিকা আনা গেল না।} other{চালানের তালিকা আনা গেল না।}}'**
  String salesPapersListLoadFailed(String kind);

  /// Sales papers: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get salesPapersNotOnServer;

  /// Sales papers: search hint
  ///
  /// In bn, this message translates to:
  /// **'নম্বর বা দোকানের নাম'**
  String get salesPapersSearch;

  /// Sales papers: total strip label (invoice, or other = challan)
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, invoice{মোট বিক্রয় বিল} other{মোট চালান}}'**
  String salesPapersTotal(String kind);

  /// Sales papers: empty list title (invoice, or other = challan)
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, invoice{এই সময়ে কোনো বিক্রয় বিল নেই} other{এই সময়ে কোনো চালান নেই}}'**
  String salesPapersEmptyTitle(String kind);

  /// Sales papers: empty list hint
  ///
  /// In bn, this message translates to:
  /// **'তারিখ বদলে বা নিচে টেনে আবার দেখুন।'**
  String get salesPapersEmptyMessage;

  /// Sales papers: what is still owed on an invoice; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'বাকি {amount}'**
  String salesPapersDueAmount(String amount);

  /// Sales papers: an invoice with nothing owed
  ///
  /// In bn, this message translates to:
  /// **'শোধ'**
  String get salesPapersPaid;

  /// Sales papers: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get salesPapersMore;

  /// Sales paper: one paper could not be loaded (invoice, or other = challan)
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, invoice{বিক্রয় বিলটা আনা গেল না।} other{চালানটা আনা গেল না।}}'**
  String salesPapersLoadFailed(String kind);

  /// Sales paper: tooltip of the print and share button
  ///
  /// In bn, this message translates to:
  /// **'ছাপা / পাঠান'**
  String get salesPapersPrint;

  /// Sales paper: the date row
  ///
  /// In bn, this message translates to:
  /// **'তারিখ'**
  String get salesPapersDate;

  /// Sales paper: the sale number row
  ///
  /// In bn, this message translates to:
  /// **'বিক্রির নম্বর'**
  String get salesPapersSaleNo;

  /// Sales paper: the date the invoice falls due
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ'**
  String get salesPapersDueOn;

  /// Sales paper: the warehouse row
  ///
  /// In bn, this message translates to:
  /// **'গুদাম'**
  String get salesPapersWarehouse;

  /// Sales paper: the vehicle row
  ///
  /// In bn, this message translates to:
  /// **'গাড়ি'**
  String get salesPapersVehicle;

  /// Sales paper: who wrote the paper
  ///
  /// In bn, this message translates to:
  /// **'লিখেছেন'**
  String get salesPapersCreatedBy;

  /// Sales paper: heading of the product lines
  ///
  /// In bn, this message translates to:
  /// **'পণ্য'**
  String get salesPapersProducts;

  /// Sales paper: free quantity on a line
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি {qty}'**
  String salesPapersFreeQty(String qty);

  /// Sales paper: discount on a line; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'ছাড় {amount}'**
  String salesPapersLineDiscount(String amount);

  /// Sales paper: the discount total row
  ///
  /// In bn, this message translates to:
  /// **'ছাড়'**
  String get salesPapersDiscount;

  /// Sales paper: the tax total row
  ///
  /// In bn, this message translates to:
  /// **'কর'**
  String get salesPapersTax;

  /// Sales paper: the total row
  ///
  /// In bn, this message translates to:
  /// **'মোট'**
  String get salesPapersGrandTotal;

  /// Sales paper: what was collected on the invoice
  ///
  /// In bn, this message translates to:
  /// **'আদায়'**
  String get salesPapersCollected;

  /// Sales paper: what is still owed on the invoice
  ///
  /// In bn, this message translates to:
  /// **'বাকি'**
  String get salesPapersDue;

  /// Sales paper: heading of the challans linked to an invoice
  ///
  /// In bn, this message translates to:
  /// **'এই বিলের চালান'**
  String get salesPapersInvoiceChallans;

  /// Sales paper: heading of the invoices linked to a challan
  ///
  /// In bn, this message translates to:
  /// **'এই চালানের বিল'**
  String get salesPapersChallanInvoices;

  /// Sales papers: the phone's own word for a paper's status; only these five are asked; other is a fallback
  ///
  /// In bn, this message translates to:
  /// **'{status, select, draft{খসড়া} confirmed{নিশ্চিত} cancelled{বাতিল} closed{সম্পন্ন} awaiting_approval{অনুমোদনের অপেক্ষায়} other{অজানা}}'**
  String salesPapersStatus(String status);

  /// Quotations: the status filter chips (all, draft, approved, sent, accepted; other = converted)
  ///
  /// In bn, this message translates to:
  /// **'{tab, select, all{সব} draft{খসড়া} approved{অনুমোদিত} sent{পাঠানো} accepted{গৃহীত} other{আদেশ হয়েছে}}'**
  String quotationsListTab(String tab);

  /// Quotations: the list could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'উদ্ধৃতি আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get quotationsListLoadFailed;

  /// Quotations: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে উদ্ধৃতির দরজা এখনো আসেনি — অফিসে জানান।'**
  String get quotationsListNotOnServer;

  /// Quotations: list screen title, and a quotation page title before it loads
  ///
  /// In bn, this message translates to:
  /// **'উদ্ধৃতি'**
  String get quotationsListTitle;

  /// Quotations: the button and the screen title for a new quotation
  ///
  /// In bn, this message translates to:
  /// **'নতুন উদ্ধৃতি'**
  String get quotationsListNew;

  /// Quotations: total strip label (all pages)
  ///
  /// In bn, this message translates to:
  /// **'মোট উদ্ধৃতি'**
  String get quotationsListTotal;

  /// Quotations: error card title
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get quotationsListErrorTitle;

  /// Quotations: empty list title
  ///
  /// In bn, this message translates to:
  /// **'কোনো উদ্ধৃতি নেই'**
  String get quotationsListEmpty;

  /// Quotations: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও উদ্ধৃতি'**
  String get quotationsListMore;

  /// New quotation: product picker title
  ///
  /// In bn, this message translates to:
  /// **'পণ্য বাছুন'**
  String get quotationsNewPickProduct;

  /// New quotation: saved without a shop
  ///
  /// In bn, this message translates to:
  /// **'দোকান বাছুন।'**
  String get quotationsNewNeedShop;

  /// New quotation: saved without a product
  ///
  /// In bn, this message translates to:
  /// **'অন্তত একটা পণ্য দিন।'**
  String get quotationsNewNeedLine;

  /// New quotation: saving failed
  ///
  /// In bn, this message translates to:
  /// **'উদ্ধৃতি রাখা গেল না। আবার চেষ্টা করুন।'**
  String get quotationsNewSaveFailed;

  /// New quotation: the shop row before a shop is picked, and the shop picker title
  ///
  /// In bn, this message translates to:
  /// **'দোকান বাছুন'**
  String get quotationsNewPickShop;

  /// Quotation: the quantity on a line
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ: {qty}'**
  String quotationsQty(String qty);

  /// Quotation: the rate on a line; rate is an amount from Money.taka or the product-price words
  ///
  /// In bn, this message translates to:
  /// **'দর: {rate}'**
  String quotationsRate(String rate);

  /// New quotation: no rate typed, the product's own price is used
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের দাম'**
  String get quotationsNewProductPrice;

  /// New quotation: tooltip of the button that removes a line
  ///
  /// In bn, this message translates to:
  /// **'বাদ দিন'**
  String get quotationsNewRemoveLine;

  /// New quotation: add a line
  ///
  /// In bn, this message translates to:
  /// **'পণ্য যোগ করুন'**
  String get quotationsNewAddLine;

  /// New quotation: the remarks field
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য (ঐচ্ছিক)'**
  String get quotationsNewNote;

  /// Quotation: the submit button
  ///
  /// In bn, this message translates to:
  /// **'জমা দিন'**
  String get quotationsSubmit;

  /// New quotation: save without submitting
  ///
  /// In bn, this message translates to:
  /// **'খসড়া রাখুন'**
  String get quotationsNewKeepDraft;

  /// Quotation page: an action failed
  ///
  /// In bn, this message translates to:
  /// **'কাজটা হলো না। আবার চেষ্টা করুন।'**
  String get quotationsActFailed;

  /// Quotation page: the status; status is the server's label
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা: {status}'**
  String quotationsStatus(String status);

  /// Quotation page: the shop
  ///
  /// In bn, this message translates to:
  /// **'দোকান: {shop}'**
  String quotationsShop(String shop);

  /// Quotation page: the date
  ///
  /// In bn, this message translates to:
  /// **'তারিখ: {date}'**
  String quotationsDate(String date);

  /// Quotation page: the date the quotation is valid until
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ: {date}'**
  String quotationsValidUntil(String date);

  /// Quotation page: the total; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'মোট: {amount}'**
  String quotationsTotal(String amount);

  /// Quotation page: the order made from it
  ///
  /// In bn, this message translates to:
  /// **'আদেশ: {no}'**
  String quotationsOrder(String no);

  /// Quotation page: notice after submitting
  ///
  /// In bn, this message translates to:
  /// **'জমা হলো।'**
  String get quotationsSubmitted;

  /// Quotation page: notice after sending
  ///
  /// In bn, this message translates to:
  /// **'দোকানিকে পাঠানো হলো।'**
  String get quotationsSentDone;

  /// Quotation page: button that records it was sent to the shopkeeper
  ///
  /// In bn, this message translates to:
  /// **'দোকানিকে পাঠানো হলো'**
  String get quotationsSend;

  /// Quotation page: notice after the shopkeeper accepted
  ///
  /// In bn, this message translates to:
  /// **'দোকানি রাজি — এখন আদেশ বানানো যায়।'**
  String get quotationsAcceptedDone;

  /// Quotation page: button that records the shopkeeper accepted
  ///
  /// In bn, this message translates to:
  /// **'দোকানি রাজি'**
  String get quotationsAccept;

  /// Quotation page: field for why the shopkeeper refused
  ///
  /// In bn, this message translates to:
  /// **'রাজি না হওয়ার কারণ'**
  String get quotationsRejectReason;

  /// Quotation page: refused without a reason
  ///
  /// In bn, this message translates to:
  /// **'রাজি না হওয়ার কারণ লিখুন।'**
  String get quotationsRejectNeedReason;

  /// Quotation page: notice after the shopkeeper refused
  ///
  /// In bn, this message translates to:
  /// **'দোকানি রাজি নন — লেখা হলো।'**
  String get quotationsRejectedDone;

  /// Quotation page: button that records the shopkeeper refused
  ///
  /// In bn, this message translates to:
  /// **'দোকানি রাজি নন'**
  String get quotationsReject;

  /// Quotation page: notice after converting to a sales order
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় আদেশ হলো।'**
  String get quotationsConvertedDone;

  /// Quotation page: button that converts it to a sales order
  ///
  /// In bn, this message translates to:
  /// **'আদেশ বানান'**
  String get quotationsConvert;

  /// Quotation line dialog: the quantity field
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ'**
  String get quotationsLineQty;

  /// Quotation line dialog: the rate field
  ///
  /// In bn, this message translates to:
  /// **'দর'**
  String get quotationsLineRate;

  /// Quotation line dialog: close without adding
  ///
  /// In bn, this message translates to:
  /// **'বাদ'**
  String get quotationsLineCancel;

  /// Quotation line dialog: add the line
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get quotationsLineOk;

  /// Delivery orders: the short paper name (order when the company works in sales orders, other = DO)
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{আদেশ} other{DO}}'**
  String deliveryOrdersPaper(String kind);

  /// Delivery orders: the screen title (order = sales orders, other = delivery orders)
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{বিক্রয় আদেশ} other{ডেলিভারি অর্ডার}}'**
  String deliveryOrdersTitle(String kind);

  /// Delivery orders: the list could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get deliveryOrdersListLoadFailed;

  /// Delivery orders: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{সার্ভারে বিক্রয় আদেশ এখনো আসেনি — অফিসে জানান।} other{সার্ভারে ডেলিভারি অর্ডার এখনো আসেনি — অফিসে জানান।}}'**
  String deliveryOrdersNotOnServer(String kind);

  /// Delivery orders: the module name in the bar crumbs
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়'**
  String get deliveryOrdersModule;

  /// Delivery orders: the new button and the new screen title
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{নতুন আদেশ} other{নতুন DO}}'**
  String deliveryOrdersNew(String kind);

  /// Delivery orders: filter chip, every paper
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get deliveryOrdersFilterAll;

  /// Delivery orders: filter chip, papers waiting for my signature
  ///
  /// In bn, this message translates to:
  /// **'আমার সইয়ের অপেক্ষায়'**
  String get deliveryOrdersFilterMine;

  /// Delivery orders: empty list title
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{কোনো আদেশ নেই।} other{কোনো DO নেই।}}'**
  String deliveryOrdersEmpty(String kind);

  /// Delivery orders: empty list when filtered to my signature
  ///
  /// In bn, this message translates to:
  /// **'এখন আপনার সইয়ের অপেক্ষায় কিছু নেই।'**
  String get deliveryOrdersEmptyMine;

  /// Delivery orders: a paper without a customer
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক নেই'**
  String get deliveryOrdersNoCustomer;

  /// New DO: product picker title
  ///
  /// In bn, this message translates to:
  /// **'পণ্য বাছুন'**
  String get deliveryOrdersPickProduct;

  /// New DO: saved without a dealer
  ///
  /// In bn, this message translates to:
  /// **'ডিলার বাছুন।'**
  String get deliveryOrdersNeedDealer;

  /// New DO: saved without a product
  ///
  /// In bn, this message translates to:
  /// **'অন্তত একটা পণ্য দিন।'**
  String get deliveryOrdersNeedLine;

  /// New DO: saving failed
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{আদেশ রাখা গেল না। আবার চেষ্টা করুন।} other{DO রাখা গেল না। আবার চেষ্টা করুন।}}'**
  String deliveryOrdersSaveFailed(String kind);

  /// New DO: the dealer row before one is picked, and the dealer picker title
  ///
  /// In bn, this message translates to:
  /// **'ডিলার বাছুন'**
  String get deliveryOrdersPickDealer;

  /// New DO: the quantity on a line
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ: {qty}'**
  String deliveryOrdersQty(String qty);

  /// New DO: tooltip of the button that removes a line
  ///
  /// In bn, this message translates to:
  /// **'বাদ দিন'**
  String get deliveryOrdersRemoveLine;

  /// New DO: add a line
  ///
  /// In bn, this message translates to:
  /// **'পণ্য যোগ করুন'**
  String get deliveryOrdersAddLine;

  /// New DO: the remarks field
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য (ঐচ্ছিক)'**
  String get deliveryOrdersNote;

  /// Delivery orders: the submit button
  ///
  /// In bn, this message translates to:
  /// **'জমা দিন'**
  String get deliveryOrdersSubmit;

  /// New DO: save without submitting
  ///
  /// In bn, this message translates to:
  /// **'খসড়া রাখুন'**
  String get deliveryOrdersKeepDraft;

  /// DO page: an action failed
  ///
  /// In bn, this message translates to:
  /// **'কাজটা হলো না। আবার চেষ্টা করুন।'**
  String get deliveryOrdersActFailed;

  /// DO page: the paper could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{আদেশ আনা গেল না।} other{DO আনা গেল না।}}'**
  String deliveryOrdersLoadFailed(String kind);

  /// DO page: tooltip of the print and share button
  ///
  /// In bn, this message translates to:
  /// **'ছাপুন / পাঠান'**
  String get deliveryOrdersPrint;

  /// DO page: the print sheet title
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় আদেশ · {no}'**
  String deliveryOrdersPrintTitle(String no);

  /// DO page: the status; status is the server's label
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা: {status}'**
  String deliveryOrdersStatus(String status);

  /// DO page: the total; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'মোট: {amount}'**
  String deliveryOrdersTotal(String amount);

  /// DO page: the order waits on the credit limit; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'বাকির সীমায় আটকে — কম {amount}'**
  String deliveryOrdersCreditShort(String amount);

  /// DO page: the quantity asked on a line
  ///
  /// In bn, this message translates to:
  /// **'চাওয়া: {qty}'**
  String deliveryOrdersAsked(String qty);

  /// DO page: the field where the supervisor sets the approved quantity
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদিত পরিমাণ'**
  String get deliveryOrdersApprovedQtyField;

  /// DO page: the approved quantity on a line
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদিত: {qty}'**
  String deliveryOrdersApprovedQty(String qty);

  /// DO page: notice after submitting
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{আদেশ জমা হলো — সুপারভাইজার দেখবেন।} other{DO জমা হলো — সুপারভাইজার দেখবেন।}}'**
  String deliveryOrdersSubmitted(String kind);

  /// DO page: notice after saving the approved quantities
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদিত পরিমাণ রাখা হলো।'**
  String get deliveryOrdersQtySaved;

  /// DO page: button that saves the approved quantities
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ রাখুন'**
  String get deliveryOrdersSaveQty;

  /// DO page: notice after the supervisor approved
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দেওয়া হলো।'**
  String get deliveryOrdersApproved;

  /// DO page: the supervisor approve button
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দিন'**
  String get deliveryOrdersApprove;

  /// DO page: the field for why it is sent back
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের কারণ'**
  String get deliveryOrdersRejectReason;

  /// DO page: sent back without a reason
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের কারণ লিখুন।'**
  String get deliveryOrdersRejectNeedReason;

  /// DO page: notice after sending it back
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{আদেশ ফেরত পাঠানো হলো।} other{DO ফেরত পাঠানো হলো।}}'**
  String deliveryOrdersRejected(String kind);

  /// DO page: the send-back button
  ///
  /// In bn, this message translates to:
  /// **'ফেরত পাঠান'**
  String get deliveryOrdersReject;

  /// DO quantity dialog: the field
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ'**
  String get deliveryOrdersDialogQty;

  /// DO quantity dialog: close without a change
  ///
  /// In bn, this message translates to:
  /// **'বাদ'**
  String get deliveryOrdersDialogCancel;

  /// DO quantity dialog: keep the quantity
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get deliveryOrdersDialogOk;

  /// DO page: heading of the stock card
  ///
  /// In bn, this message translates to:
  /// **'মজুদ এখন — ধরা হয়নি'**
  String get deliveryOrdersStockTitle;

  /// DO page: the note under the stock heading
  ///
  /// In bn, this message translates to:
  /// **'মাল আটকায় ডিপোর চালান নিশ্চিত হলে; এখানে কেবল কত আছে আর কত চাই।'**
  String get deliveryOrdersStockNote;

  /// DO page: stock on hand and wanted for a product
  ///
  /// In bn, this message translates to:
  /// **'আছে {have} · চাই {want}'**
  String deliveryOrdersStockHaveWant(String have, String want);

  /// DO page: wanted for a product, stock unknown
  ///
  /// In bn, this message translates to:
  /// **'চাই {want}'**
  String deliveryOrdersStockWant(String want);

  /// DO page: how much stock is short
  ///
  /// In bn, this message translates to:
  /// **'কম {qty}'**
  String deliveryOrdersStockShort(String qty);

  /// DO page: stock is short, amount unknown
  ///
  /// In bn, this message translates to:
  /// **'মজুদে কুলোয় না'**
  String get deliveryOrdersStockNotEnough;

  /// Sales plan pages: the page could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get salesPlanLoadFailed;

  /// Sales plan pages: the server does not have this door yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের এই পর্দা এখনো আসেনি — অফিসে জানান।'**
  String get salesPlanNotOnServer;

  /// Month bar: tooltip of the previous-month button
  ///
  /// In bn, this message translates to:
  /// **'আগের মাস'**
  String get salesPlanPreviousMonth;

  /// Month bar: tooltip of the next-month button
  ///
  /// In bn, this message translates to:
  /// **'পরের মাস'**
  String get salesPlanNextMonth;

  /// Sales plan: a count of people
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} জন}}'**
  String salesPlanPeople(int count);

  /// Sales targets: screen title
  ///
  /// In bn, this message translates to:
  /// **'লক্ষ্যমাত্রা'**
  String get salesPlanTargetsTitle;

  /// Sales targets: empty title
  ///
  /// In bn, this message translates to:
  /// **'এই মাসে কোনো বিক্রয়কর্মী নেই'**
  String get salesPlanTargetsEmpty;

  /// Sales targets: the note under the month
  ///
  /// In bn, this message translates to:
  /// **'ⓘ লক্ষ্য বসানো ওয়েবে। অর্জন নিশ্চিত বিলের, ভ্যাট আর ছাড় বাদে।'**
  String get salesPlanTargetsNote;

  /// Sales plan: the total target
  ///
  /// In bn, this message translates to:
  /// **'মোট লক্ষ্য'**
  String get salesPlanTotalTarget;

  /// Sales plan: the total achieved
  ///
  /// In bn, this message translates to:
  /// **'মোট অর্জন'**
  String get salesPlanTotalAchieved;

  /// Sales targets: the achievement percent of people who have a target
  ///
  /// In bn, this message translates to:
  /// **'লক্ষ্যধারীদের অর্জন'**
  String get salesPlanTargetHoldersAchieved;

  /// Sales plan: how many people have a target
  ///
  /// In bn, this message translates to:
  /// **'লক্ষ্য বসানো'**
  String get salesPlanTargetSet;

  /// Sales targets: a salesperson row; amounts from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'লক্ষ্য {target} · অর্জন {achieved}'**
  String salesPlanTargetAchieved(String target, String achieved);

  /// Dealer targets: screen title
  ///
  /// In bn, this message translates to:
  /// **'ডিলারের লক্ষ্য'**
  String get salesPlanDealerTargetsTitle;

  /// Dealer targets: empty title when only targets are shown
  ///
  /// In bn, this message translates to:
  /// **'এই মাসে কারো লক্ষ্য বসানো নেই'**
  String get salesPlanDealerTargetsNoneSet;

  /// Dealer targets: empty title
  ///
  /// In bn, this message translates to:
  /// **'কোনো ডিলার নেই'**
  String get salesPlanDealerTargetsEmpty;

  /// Dealer targets: search hint
  ///
  /// In bn, this message translates to:
  /// **'ডিলারের নাম বা কোড'**
  String get salesPlanDealerTargetsSearch;

  /// Dealer targets: filter chip
  ///
  /// In bn, this message translates to:
  /// **'কেবল যাঁদের লক্ষ্য আছে'**
  String get salesPlanDealerTargetsOnlySet;

  /// Dealer targets: the note under the filters
  ///
  /// In bn, this message translates to:
  /// **'ⓘ লক্ষ্য বসানো ওয়েবে। অর্জন মানে আদায়, রসিদ আর পাশ হওয়া চেক।'**
  String get salesPlanDealerTargetsNote;

  /// Sales plan: the total still owed
  ///
  /// In bn, this message translates to:
  /// **'মোট বাকি'**
  String get salesPlanTotalDue;

  /// Dealer targets: achieved on a row; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'অর্জন {amount}'**
  String salesPlanAchieved(String amount);

  /// Sales plan: still owed on a row; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'বাকি {amount}'**
  String salesPlanDue(String amount);

  /// Dealer targets: the last day of the target
  ///
  /// In bn, this message translates to:
  /// **'শেষ {date}'**
  String salesPlanEnds(String date);

  /// Route book: screen title
  ///
  /// In bn, this message translates to:
  /// **'রুটের খাতা'**
  String get salesPlanRouteBookTitle;

  /// Route book: empty title
  ///
  /// In bn, this message translates to:
  /// **'কোনো রুট নেই'**
  String get salesPlanRouteBookEmpty;

  /// Route book: search hint
  ///
  /// In bn, this message translates to:
  /// **'রুটের নাম বা কোড'**
  String get salesPlanRouteBookSearch;

  /// Route book: the total sales
  ///
  /// In bn, this message translates to:
  /// **'মোট বিক্রি'**
  String get salesPlanTotalSales;

  /// Route book: the total collections
  ///
  /// In bn, this message translates to:
  /// **'মোট আদায়'**
  String get salesPlanTotalCollections;

  /// Sales plan: how much of the target is achieved
  ///
  /// In bn, this message translates to:
  /// **'লক্ষ্যের অর্জন'**
  String get salesPlanTargetAchievement;

  /// Sales plan: the dealers row and section title
  ///
  /// In bn, this message translates to:
  /// **'ডিলার'**
  String get salesPlanDealers;

  /// Route book: dealers on no route
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{⚠ {count} জন ডিলার কোনো রুটে বসানো নেই — তাঁদের অঙ্ক এখানে নেই।}}'**
  String salesPlanUnrouted(int count);

  /// Route book: how many dealers a route has
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} জন ডিলার}}'**
  String salesPlanDealerCount(int count);

  /// Sales plan: sales and collections on a row; amounts from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি {sales} · আদায় {collections}'**
  String salesPlanSalesCollections(String sales, String collections);

  /// Route book: the target and the percent achieved
  ///
  /// In bn, this message translates to:
  /// **'লক্ষ্য {target} · {percent}'**
  String salesPlanTargetPercent(String target, String percent);

  /// Route page: empty title
  ///
  /// In bn, this message translates to:
  /// **'এই রুটে কোনো ডিলার নেই'**
  String get salesPlanRouteEmpty;

  /// Route page: the closing balance
  ///
  /// In bn, this message translates to:
  /// **'শেষ জের (বাকি)'**
  String get salesPlanClosingBalance;

  /// Route page: the opening balance
  ///
  /// In bn, this message translates to:
  /// **'আগের জের'**
  String get salesPlanOpeningBalance;

  /// Route page: sales of the month
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি'**
  String get salesPlanSales;

  /// Route page: sales returns of the month
  ///
  /// In bn, this message translates to:
  /// **'ফেরত'**
  String get salesPlanReturns;

  /// Route page: collections of the month
  ///
  /// In bn, this message translates to:
  /// **'আদায়'**
  String get salesPlanCollections;

  /// Route page: other entries of the month
  ///
  /// In bn, this message translates to:
  /// **'অন্যান্য'**
  String get salesPlanOther;

  /// Route page: the target
  ///
  /// In bn, this message translates to:
  /// **'লক্ষ্য'**
  String get salesPlanTarget;

  /// Route page: section title of the weekly visit plan
  ///
  /// In bn, this message translates to:
  /// **'সাপ্তাহিক ছক'**
  String get salesPlanWeeklyPlan;

  /// Route page: the weekly plan is empty
  ///
  /// In bn, this message translates to:
  /// **'ছকে কেউ বসানো নেই।'**
  String get salesPlanWeeklyPlanEmpty;

  /// Route page: a visit plan row that is running
  ///
  /// In bn, this message translates to:
  /// **'চালু'**
  String get salesPlanVisitActive;

  /// Route page: a visit plan row that has ended
  ///
  /// In bn, this message translates to:
  /// **'শেষ'**
  String get salesPlanVisitEnded;

  /// Sales plan: a date or amount range
  ///
  /// In bn, this message translates to:
  /// **'{from} থেকে {to}'**
  String salesPlanFromTo(String from, String to);

  /// Route page: a visit plan with no end date
  ///
  /// In bn, this message translates to:
  /// **'চলছে'**
  String get salesPlanOngoing;

  /// Route page: the note under the weekly plan
  ///
  /// In bn, this message translates to:
  /// **'ⓘ ছক আর লক্ষ্য বসানো ওয়েবে।'**
  String get salesPlanRouteNote;

  /// Schemes and commissions: the status chip for every status
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get salesPlanStatusAll;

  /// Schemes: screen title
  ///
  /// In bn, this message translates to:
  /// **'স্কিম'**
  String get salesPlanSchemesTitle;

  /// Schemes: empty title
  ///
  /// In bn, this message translates to:
  /// **'কোনো স্কিম নেই'**
  String get salesPlanSchemesEmpty;

  /// Schemes: search hint
  ///
  /// In bn, this message translates to:
  /// **'স্কিমের কোড বা নাম'**
  String get salesPlanSchemesSearch;

  /// Schemes: how many in all, and where they are changed
  ///
  /// In bn, this message translates to:
  /// **'মোট {count} টি স্কিম · বসানো ও বদল ওয়েবে'**
  String salesPlanSchemesCount(String count);

  /// Schemes: a scheme's basis, what it applies to (server labels) and its step count
  ///
  /// In bn, this message translates to:
  /// **'{basis} · {appliesTo} · {rules, plural, other{{rules}টি ধাপ}}'**
  String salesPlanSchemeLine(String basis, String appliesTo, int rules);

  /// Schemes: after the dates when the scheme runs today
  ///
  /// In bn, this message translates to:
  /// **' · আজ চলছে'**
  String get salesPlanSchemeLive;

  /// Scheme page: the code row
  ///
  /// In bn, this message translates to:
  /// **'কোড'**
  String get salesPlanSchemeCode;

  /// Scheme page: the status row
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get salesPlanSchemeStatus;

  /// Scheme page: what the scheme is counted on
  ///
  /// In bn, this message translates to:
  /// **'ভিত্তি'**
  String get salesPlanSchemeBasis;

  /// Scheme page: what the scheme applies to
  ///
  /// In bn, this message translates to:
  /// **'কীসের উপর'**
  String get salesPlanSchemeAppliesTo;

  /// Scheme page: the from – to dates of the scheme
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ'**
  String get salesPlanSchemeValidity;

  /// Scheme page: free-text notes on the scheme (not a debit or credit note)
  ///
  /// In bn, this message translates to:
  /// **'নোট'**
  String get salesPlanSchemeNotes;

  /// Scheme page: section title with the step count
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{ধাপ · {count}টি}}'**
  String salesPlanSchemeSteps(int count);

  /// Scheme page: no steps
  ///
  /// In bn, this message translates to:
  /// **'কোনো ধাপ বসানো নেই'**
  String get salesPlanSchemeNoSteps;

  /// Scheme page: a step, its role (server label) and level
  ///
  /// In bn, this message translates to:
  /// **'{role} · স্তর {level}'**
  String salesPlanSchemeRoleLevel(String role, String level);

  /// Scheme page: a slab with no upper end; from is an amount
  ///
  /// In bn, this message translates to:
  /// **'{from} থেকে উপরে'**
  String salesPlanSchemeFromUp(String from);

  /// Scheme page: the note at the end
  ///
  /// In bn, this message translates to:
  /// **'ⓘ ধাপ বসানো, চালু আর থামানো ওয়েবে।'**
  String get salesPlanSchemeNote;

  /// Commission: screen title
  ///
  /// In bn, this message translates to:
  /// **'কমিশন'**
  String get salesPlanCommissionTitle;

  /// Commission: empty title
  ///
  /// In bn, this message translates to:
  /// **'কোনো কমিশন নেই'**
  String get salesPlanCommissionEmpty;

  /// Commission: search hint
  ///
  /// In bn, this message translates to:
  /// **'নম্বর, ডিলার বা কোম্পানি'**
  String get salesPlanCommissionSearch;

  /// Commission: the grand total
  ///
  /// In bn, this message translates to:
  /// **'সর্বমোট'**
  String get salesPlanCommissionGrandTotal;

  /// Commission: what the company still owes
  ///
  /// In bn, this message translates to:
  /// **'কোম্পানির কাছে দাবি, এখনো বাকি'**
  String get salesPlanCommissionPending;

  /// Commission: a status (server label) and how many claims
  ///
  /// In bn, this message translates to:
  /// **'{label} · {count, plural, other{{count}টি}}'**
  String salesPlanCommissionByStatus(String label, int count);

  /// Commission: what the total counts
  ///
  /// In bn, this message translates to:
  /// **'ⓘ বাতিল দাবি মোটে নেই; ফেরত দেওয়া দাবি ডিপোর খরচ হিসেবে মোটে আছে'**
  String get salesPlanCommissionTotalNote;

  /// Commission: where claims are changed
  ///
  /// In bn, this message translates to:
  /// **'ⓘ নতুন দাবি, মেটানো আর বাতিল ওয়েবে।'**
  String get salesPlanCommissionWebNote;

  /// Commission: the invoice number of a claim
  ///
  /// In bn, this message translates to:
  /// **'বিল {no}'**
  String salesPlanCommissionInvoice(String no);

  /// Commission: the base amount; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'ভিত্তি {amount}'**
  String salesPlanCommissionBase(String amount);

  /// Commission: the rate
  ///
  /// In bn, this message translates to:
  /// **'হার {rate}'**
  String salesPlanCommissionRate(String rate);

  /// Commission: a fixed amount; amount from Money.taka
  ///
  /// In bn, this message translates to:
  /// **'থোক {amount}'**
  String salesPlanCommissionLump(String amount);

  /// Commission: why it was decided so; reason from the server
  ///
  /// In bn, this message translates to:
  /// **'কারণ: {reason}'**
  String salesPlanCommissionReason(String reason);
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
