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

  /// Owner's centre: the Today screen's title
  ///
  /// In bn, this message translates to:
  /// **'মালিকের কেন্দ্র'**
  String get ownerCentreTitle;

  /// Owner's centre Today: load failed
  ///
  /// In bn, this message translates to:
  /// **'মালিকের কেন্দ্রের সংখ্যা আনা গেল না।'**
  String get ownerTodayFailed;

  /// Owner's centre Today: the server has no such endpoint (old server)
  ///
  /// In bn, this message translates to:
  /// **'মালিকের কেন্দ্র এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get ownerTodayAbsent;

  /// Owner's centre sub-page: the server has no such endpoint (old server)
  ///
  /// In bn, this message translates to:
  /// **'মালিকের কেন্দ্রের এই পাতা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।'**
  String get ownerPageAbsent;

  /// Owner's centre Today: empty state title when no company is visible
  ///
  /// In bn, this message translates to:
  /// **'দেখার মতো কোনো কোম্পানি নেই'**
  String get ownerNoCompanyTitle;

  /// Owner's centre Today: empty state message
  ///
  /// In bn, this message translates to:
  /// **'কোনো কোম্পানিতে আপনার মালিকের কেন্দ্র দেখার অনুমতি নেই।'**
  String get ownerNoCompanyMessage;

  /// Owner's centre Today: heading over the company cards
  ///
  /// In bn, this message translates to:
  /// **'কোম্পানি ও শাখা'**
  String get ownerCompaniesAndBranches;

  /// Owner's centre: alerts heading and the Alerts page title
  ///
  /// In bn, this message translates to:
  /// **'সতর্কতা'**
  String get ownerAlerts;

  /// Owner's centre: papers awaiting signature (heading and the figure's label)
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষায়'**
  String get ownerAwaitingSignature;

  /// Owner's centre Today: nothing awaits signature
  ///
  /// In bn, this message translates to:
  /// **'এখন কোনো কাগজ সইয়ের অপেক্ষায় নেই।'**
  String get ownerNothingAwaitingNow;

  /// Owner's centre: a figure misses companies the user may not see
  ///
  /// In bn, this message translates to:
  /// **'কিছু কোম্পানি বাদ — অনুমতি নেই'**
  String get ownerSomeCompaniesLeftOut;

  /// Owner's centre Today: the credit card's title
  ///
  /// In bn, this message translates to:
  /// **'বাকি'**
  String get ownerCredit;

  /// Owner's centre credit card: credit in use
  ///
  /// In bn, this message translates to:
  /// **'ব্যবহৃত বাকি'**
  String get ownerCreditExposure;

  /// Owner's centre credit card: overdue receivables
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ পেরোনো পাওনা'**
  String get ownerCreditOverdue;

  /// Owner's centre credit card: customers whose credit is blocked
  ///
  /// In bn, this message translates to:
  /// **'বাকি বন্ধ গ্রাহক'**
  String get ownerCreditBlocked;

  /// Owner's centre credit card: number of blocked customers (people)
  ///
  /// In bn, this message translates to:
  /// **'{count} জন'**
  String ownerCreditBlockedCount(int count);

  /// Owner's centre credit card: credit-limit raise requests waiting
  ///
  /// In bn, this message translates to:
  /// **'সীমা বাড়ানোর অনুরোধ'**
  String get ownerCreditRequests;

  /// Owner's centre: a plain count of things
  ///
  /// In bn, this message translates to:
  /// **'{count}টা'**
  String ownerCount(int count);

  /// Owner's centre company card subtitle; amounts already formatted
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি {sales} · আদায় {collections}'**
  String ownerCompanySummary(String sales, String collections);

  /// Owner's centre company card: rows with no branch
  ///
  /// In bn, this message translates to:
  /// **'শাখায় ভাগ হয়নি'**
  String get ownerUnsplit;

  /// Owner's centre: the user may not see this company's figure (not zero)
  ///
  /// In bn, this message translates to:
  /// **'অনুমতি নেই'**
  String get ownerNotAllowed;

  /// Owner's centre alert: nothing to alert
  ///
  /// In bn, this message translates to:
  /// **'কিছু নেই'**
  String get ownerNone;

  /// Owner's centre period chip
  ///
  /// In bn, this message translates to:
  /// **'আজ'**
  String get ownerPeriodToday;

  /// Owner's centre period chip
  ///
  /// In bn, this message translates to:
  /// **'এই সপ্তাহ'**
  String get ownerPeriodWeek;

  /// Owner's centre period chip
  ///
  /// In bn, this message translates to:
  /// **'এই মাস'**
  String get ownerPeriodMonth;

  /// Owner's centre figure
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি'**
  String get ownerFigureSales;

  /// Owner's centre figure
  ///
  /// In bn, this message translates to:
  /// **'আদায়'**
  String get ownerFigureCollections;

  /// Owner's centre figure: what customers owe
  ///
  /// In bn, this message translates to:
  /// **'পাওনা (বাকি)'**
  String get ownerFigureReceivable;

  /// Owner's centre figure
  ///
  /// In bn, this message translates to:
  /// **'সরবরাহকারীর দেনা'**
  String get ownerFigurePayable;

  /// Owner's centre figure: cash, bank and mobile money
  ///
  /// In bn, this message translates to:
  /// **'নগদ + ব্যাংক'**
  String get ownerFigureFund;

  /// Owner's centre figure: remaining goods at purchase cost (fe's word: মজুদের মূল্য, as on the web dashboard)
  ///
  /// In bn, this message translates to:
  /// **'মজুদের মূল্য'**
  String get ownerFigureStock;

  /// Owner's centre figure
  ///
  /// In bn, this message translates to:
  /// **'লাভ'**
  String get ownerFigureProfit;

  /// Owner's centre figure
  ///
  /// In bn, this message translates to:
  /// **'খরচ'**
  String get ownerFigureExpenses;

  /// Owner's centre figure
  ///
  /// In bn, this message translates to:
  /// **'নগদ প্রবাহ (নিট)'**
  String get ownerFigureCashFlow;

  /// Owner's centre alert
  ///
  /// In bn, this message translates to:
  /// **'বাকির সীমা ছাড়িয়েছে'**
  String get ownerAlertOverLimit;

  /// Owner's centre alert
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ পেরোনো বাকি'**
  String get ownerAlertOverdue;

  /// Owner's centre alert
  ///
  /// In bn, this message translates to:
  /// **'মজুদ পুনঃঅর্ডারের নিচে'**
  String get ownerAlertStockLow;

  /// Owner's centre alert
  ///
  /// In bn, this message translates to:
  /// **'মজুদ শূন্যের নিচে'**
  String get ownerAlertStockNegative;

  /// Owner's centre alert
  ///
  /// In bn, this message translates to:
  /// **'অচল মাল'**
  String get ownerAlertStockDead;

  /// Owner's centre alert: late deliveries in the last N days
  ///
  /// In bn, this message translates to:
  /// **'দেরির চালান ({days} দিন)'**
  String ownerAlertLateDelivery(int days);

  /// Owner's centre alert: failed sign-ins in the last N days
  ///
  /// In bn, this message translates to:
  /// **'ব্যর্থ লগইন ({days} দিন)'**
  String ownerAlertFailedLogin(int days);

  /// Owner's centre alert
  ///
  /// In bn, this message translates to:
  /// **'পিছনের তারিখের কাগজ (এ মাসে)'**
  String get ownerAlertBackdated;

  /// Owner's centre Alerts: load failed
  ///
  /// In bn, this message translates to:
  /// **'সতর্কতার তালিকা আনা গেল না।'**
  String get ownerAlertsFailed;

  /// Owner's centre Alerts: cache note
  ///
  /// In bn, this message translates to:
  /// **'সংখ্যাগুলো {minutes} মিনিট পর্যন্ত পুরনো হতে পারে। টেনে নামালে নতুন করে আনে।'**
  String ownerFiguresMayBeOld(int minutes);

  /// Owner's centre Alerts: heading with the number of papers
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষায় ({count}টা)'**
  String ownerAwaitingSignatureCount(int count);

  /// Owner's centre Alerts: nothing awaits signature
  ///
  /// In bn, this message translates to:
  /// **'কোনো কাগজ সইয়ের অপেক্ষায় নেই।'**
  String get ownerNothingAwaiting;

  /// Owner's centre: total over all group companies
  ///
  /// In bn, this message translates to:
  /// **'গ্রুপের মোট'**
  String get ownerGroupTotal;

  /// Owner's centre History: title
  ///
  /// In bn, this message translates to:
  /// **'আগের দিনের হিসাব'**
  String get ownerHistoryTitle;

  /// Owner's centre History: load failed
  ///
  /// In bn, this message translates to:
  /// **'আগের দিনের হিসাব আনা গেল না।'**
  String get ownerHistoryFailed;

  /// Owner's centre History: the day chip; day already formatted
  ///
  /// In bn, this message translates to:
  /// **'কোন দিন: {day}'**
  String ownerHistoryDay(String day);

  /// Owner's centre History: what the nightly record means; time is 23:55
  ///
  /// In bn, this message translates to:
  /// **'{date} — ঐ দিন রাত {time}-এ পর্দায় যা ছিল, ঠিক তাই। আজকের খাতা থেকে নতুন করে গোনা নয়।'**
  String ownerHistoryAsKept(String date, String time);

  /// Owner's centre History: empty state title
  ///
  /// In bn, this message translates to:
  /// **'ঐ দিনের রাতের হিসাব নেই'**
  String get ownerHistoryNoneTitle;

  /// Owner's centre History: empty state message
  ///
  /// In bn, this message translates to:
  /// **'হিসাব রাখা শুরু হওয়ার আগের দিন, বা সেই রাতে লেখা হয়নি।'**
  String get ownerHistoryNoneMessage;

  /// Owner's centre History: trend card title
  ///
  /// In bn, this message translates to:
  /// **'{figure} — গত {days} দিন, রাতের হিসাব থেকে'**
  String ownerHistoryTrend(String figure, int days);

  /// Owner's centre History: the second bar's label
  ///
  /// In bn, this message translates to:
  /// **'গত মাসের একই দিন'**
  String get ownerHistoryMonthBefore;

  /// Owner's centre report title
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতা ধরে লাভ'**
  String get ownerReportProfitByCustomer;

  /// Owner's centre report hint
  ///
  /// In bn, this message translates to:
  /// **'প্রতিটা বিলের সারির আসল FIFO খরচ — বিল পাকা হওয়ার সময় স্তর থেকে যা টানা হয়েছিল; ফেরত তার নিজের খরচে বাদ।'**
  String get ownerReportProfitByCustomerHint;

  /// Owner's centre Reports: title
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট'**
  String get ownerReportsTitle;

  /// Owner's centre Reports: load failed
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্টের পাতা আনা গেল না।'**
  String get ownerReportsFailed;

  /// Owner's centre Reports: link to all reports
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট সেন্টার — সব রিপোর্ট'**
  String get ownerReportsCentre;

  /// Owner's centre: heading over its own reports
  ///
  /// In bn, this message translates to:
  /// **'মালিকের কেন্দ্রের রিপোর্ট'**
  String get ownerReportsOwn;

  /// Owner's centre Reports: the user lacks the report's key
  ///
  /// In bn, this message translates to:
  /// **'এই রিপোর্ট দেখার অনুমতি নেই।'**
  String get ownerReportNotAllowed;

  /// Owner's centre Reports: note
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্টের পাতায় তারিখ বাছা, PDF আর ভাগ করা যায়; প্রতিটা নামানো রপ্তানির খাতায় লেখা থাকে।'**
  String get ownerReportsExportNote;

  /// Owner's centre Reports: heading with the number of saved views
  ///
  /// In bn, this message translates to:
  /// **'আমার সংরক্ষিত দৃশ্য ({count}টা)'**
  String ownerSavedViews(int count);

  /// Owner's centre Reports: no saved views
  ///
  /// In bn, this message translates to:
  /// **'কোনো সংরক্ষিত দৃশ্য নেই — রিপোর্টের পাতায় \"দৃশ্য\" থেকে রাখা যায়।'**
  String get ownerNoSavedViews;

  /// Owner's centre Reports: heading
  ///
  /// In bn, this message translates to:
  /// **'নির্ধারিত রিপোর্ট'**
  String get ownerScheduledReports;

  /// Owner's centre Reports: schedules are edited on the web
  ///
  /// In bn, this message translates to:
  /// **'সূচি বসানো আর বদলানো ওয়েবের পাতায়।'**
  String get ownerSchedulesOnWeb;

  /// Owner's centre Reports: no key to schedule
  ///
  /// In bn, this message translates to:
  /// **'নির্ধারিত রিপোর্ট বসানোর অনুমতি নেই।'**
  String get ownerNoScheduleKey;

  /// Owner's centre Reports: note
  ///
  /// In bn, this message translates to:
  /// **'তৈরি হওয়া রিপোর্ট অ্যাপের ভিতরেই আসে, ই-মেইলে নয়।'**
  String get ownerReportsInAppOnly;

  /// Owner's centre Sister links: title
  ///
  /// In bn, this message translates to:
  /// **'ভাই-কোম্পানির পক্ষ'**
  String get ownerLinksTitle;

  /// Owner's centre Sister links: load failed
  ///
  /// In bn, this message translates to:
  /// **'ভাই-কোম্পানির পক্ষের তালিকা আনা গেল না।'**
  String get ownerLinksFailed;

  /// Owner's centre Sister links: what a link does
  ///
  /// In bn, this message translates to:
  /// **'এক কোম্পানির কোনো ক্রেতা বা সরবরাহকারী যদি আসলে আমাদেরই আরেকটা কোম্পানি হয়, জোড়া থাকলে গ্রুপের মোট থেকে দুইয়ের মাঝের বিক্রি, পাওনা আর দেনা বাদ যায়। নাম মিলিয়ে কিছু আন্দাজ করা হয় না।'**
  String get ownerLinksExplain;

  /// Owner's centre Sister links: read-only note
  ///
  /// In bn, this message translates to:
  /// **'জোড়া দেওয়া আর তোলা ওয়েবের পাতায় — এখানে কেবল দেখা।'**
  String get ownerLinksWebOnly;

  /// Owner's centre Sister links: total strip label
  ///
  /// In bn, this message translates to:
  /// **'মোট জোড়া'**
  String get ownerLinksTotal;

  /// Owner's centre Sister links: empty title
  ///
  /// In bn, this message translates to:
  /// **'কোনো জোড়া নেই'**
  String get ownerLinksNoneTitle;

  /// Owner's centre Sister links: empty message
  ///
  /// In bn, this message translates to:
  /// **'গ্রুপের মোট থেকে কিছু বাদ যাচ্ছে না।'**
  String get ownerLinksNoneMessage;

  /// Owner's centre Sister links: row subtitle
  ///
  /// In bn, this message translates to:
  /// **'{company} → আসলে আমাদের {sister}'**
  String ownerLinksIsSister(String company, String sister);

  /// Owner's centre Sister links: party type
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতা'**
  String get ownerPartyCustomer;

  /// Owner's centre Sister links: party type
  ///
  /// In bn, this message translates to:
  /// **'সরবরাহকারী'**
  String get ownerPartySupplier;

  /// Owner's centre Analysis: title
  ///
  /// In bn, this message translates to:
  /// **'বিশ্লেষণ'**
  String get ownerAnalysisTitle;

  /// Owner's centre Analysis: load failed
  ///
  /// In bn, this message translates to:
  /// **'বিশ্লেষণের তালিকা আনা গেল না।'**
  String get ownerAnalysisFailed;

  /// Owner's centre period chip
  ///
  /// In bn, this message translates to:
  /// **'গত তিন মাস'**
  String get ownerPeriodThreeMonths;

  /// Owner's centre period chip
  ///
  /// In bn, this message translates to:
  /// **'এই বছর'**
  String get ownerPeriodYear;

  /// Owner's centre Analysis list
  ///
  /// In bn, this message translates to:
  /// **'সেরা {count} ক্রেতা'**
  String ownerListTopCustomers(int count);

  /// Owner's centre Analysis list
  ///
  /// In bn, this message translates to:
  /// **'সেরা {count} পণ্য'**
  String ownerListTopProducts(int count);

  /// Owner's centre Analysis list
  ///
  /// In bn, this message translates to:
  /// **'সেরা {count} রুট'**
  String ownerListTopAreas(int count);

  /// Owner's centre Analysis list
  ///
  /// In bn, this message translates to:
  /// **'সেরা {count} বিক্রয়কর্মী'**
  String ownerListTopSalespeople(int count);

  /// Owner's centre Analysis list
  ///
  /// In bn, this message translates to:
  /// **'সবচেয়ে কম লাভের ক্রেতা'**
  String get ownerListLosing;

  /// Owner's centre Analysis list
  ///
  /// In bn, this message translates to:
  /// **'সবচেয়ে কম মার্জিনের পণ্য'**
  String get ownerListThin;

  /// Owner's centre Analysis list
  ///
  /// In bn, this message translates to:
  /// **'অচল মাল (দাম ধরে)'**
  String get ownerListDead;

  /// Owner's centre Analysis: age-of-dues bucket
  ///
  /// In bn, this message translates to:
  /// **'চলতি বাকি'**
  String get ownerBucketCurrent;

  /// Owner's centre Analysis: age-of-dues bucket
  ///
  /// In bn, this message translates to:
  /// **'{days} দিনের বেশি'**
  String ownerBucketOver(int days);

  /// Owner's centre Analysis: all dues
  ///
  /// In bn, this message translates to:
  /// **'মোট বাকি'**
  String get ownerOutstanding;

  /// Owner's centre Analysis: heading
  ///
  /// In bn, this message translates to:
  /// **'বাকির বয়স — সব কোম্পানি মিলিয়ে'**
  String get ownerAgeing;

  /// Owner's centre: companies whose figures the user may not see
  ///
  /// In bn, this message translates to:
  /// **'দেখার অনুমতি নেই: {companies}'**
  String ownerRefused(String companies);

  /// Owner's centre Analysis: empty list
  ///
  /// In bn, this message translates to:
  /// **'এই সময়ে কিছু নেই।'**
  String get ownerNothingInPeriod;

  /// Owner's centre Analysis: no cost key
  ///
  /// In bn, this message translates to:
  /// **'খরচ দেখার অনুমতি নেই — লাভ আর মার্জিনের ঘর ঢাকা থাকবে।'**
  String get ownerNoCostKey;

  /// Owner's centre Compare: title
  ///
  /// In bn, this message translates to:
  /// **'তুলনা'**
  String get ownerCompareTitle;

  /// Owner's centre Compare: load failed
  ///
  /// In bn, this message translates to:
  /// **'তুলনার সংখ্যা আনা গেল না।'**
  String get ownerCompareFailed;

  /// Owner's centre Compare mode
  ///
  /// In bn, this message translates to:
  /// **'কোম্পানি বনাম কোম্পানি'**
  String get ownerModeCompanies;

  /// Owner's centre Compare mode
  ///
  /// In bn, this message translates to:
  /// **'শাখা বনাম শাখা'**
  String get ownerModeBranches;

  /// Owner's centre Compare: against what
  ///
  /// In bn, this message translates to:
  /// **'আগের মাসের একই দিনগুলো'**
  String get ownerAgainstPreviousMonth;

  /// Owner's centre Compare: against what
  ///
  /// In bn, this message translates to:
  /// **'গত বছরের একই সময়'**
  String get ownerAgainstLastYear;

  /// Owner's centre Compare: trend step
  ///
  /// In bn, this message translates to:
  /// **'দিন ধরে'**
  String get ownerGrainDaily;

  /// Owner's centre Compare: trend step
  ///
  /// In bn, this message translates to:
  /// **'সপ্তাহ ধরে'**
  String get ownerGrainWeekly;

  /// Owner's centre Compare: trend step
  ///
  /// In bn, this message translates to:
  /// **'মাস ধরে'**
  String get ownerGrainMonthly;

  /// Owner's centre Compare: trend step
  ///
  /// In bn, this message translates to:
  /// **'তিন মাস ধরে'**
  String get ownerGrainQuarterly;

  /// Owner's centre Compare: trend step
  ///
  /// In bn, this message translates to:
  /// **'বছর ধরে'**
  String get ownerGrainYearly;

  /// Owner's centre Compare: company chip
  ///
  /// In bn, this message translates to:
  /// **'সব কোম্পানি'**
  String get ownerAllCompanies;

  /// Owner's centre Compare: the two date ranges
  ///
  /// In bn, this message translates to:
  /// **'এখন: {now} · তখন: {was}'**
  String ownerNowThen(String now, String was);

  /// Owner's centre Compare: empty title
  ///
  /// In bn, this message translates to:
  /// **'দেখার মতো কিছু নেই'**
  String get ownerNothingToShow;

  /// Owner's centre Compare: empty message
  ///
  /// In bn, this message translates to:
  /// **'কোনো কোম্পানিতে আপনার এই পর্দা দেখার অনুমতি নেই।'**
  String get ownerScreenNotAllowed;

  /// Owner's centre Compare: note
  ///
  /// In bn, this message translates to:
  /// **'বাকি একটা জের: \"তখন\" মানে ঐ দিনের রাতে রাখা হিসাব। রাতের হিসাব না থাকলে \"—\"।'**
  String get ownerReceivableNote;

  /// Owner's centre Compare: trend heading
  ///
  /// In bn, this message translates to:
  /// **'{figure} — সময়ের সাথে'**
  String ownerTrendOf(String figure);

  /// Owner's centre Compare: trend hidden
  ///
  /// In bn, this message translates to:
  /// **'দেখার অনুমতি নেই'**
  String get ownerNotAllowedToSee;

  /// Owner's centre Compare: the second bar's label
  ///
  /// In bn, this message translates to:
  /// **'গত বছর'**
  String get ownerLastYear;

  /// Owner's centre Compare: the earlier figure
  ///
  /// In bn, this message translates to:
  /// **'তখন {value}'**
  String ownerThen(String value);

  /// Documents: confidentiality level
  ///
  /// In bn, this message translates to:
  /// **'সবার জন্য'**
  String get documentsLevelPublic;

  /// Documents: confidentiality level
  ///
  /// In bn, this message translates to:
  /// **'অভ্যন্তরীণ'**
  String get documentsLevelInternal;

  /// Documents: confidentiality level
  ///
  /// In bn, this message translates to:
  /// **'গোপন'**
  String get documentsLevelConfidential;

  /// Documents: confidentiality level
  ///
  /// In bn, this message translates to:
  /// **'অতি গোপন'**
  String get documentsLevelHighlyConfidential;

  /// Documents: confidentiality level
  ///
  /// In bn, this message translates to:
  /// **'সংরক্ষিত'**
  String get documentsLevelRestricted;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'খসড়া'**
  String get documentsStatusDraft;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'জমা হয়েছে'**
  String get documentsStatusSubmitted;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'পর্যালোচনায়'**
  String get documentsStatusUnderReview;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'বদল চাওয়া হয়েছে'**
  String get documentsStatusChangesRequested;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদিত'**
  String get documentsStatusApproved;

  /// Documents: status (fe's word: প্রত্যাখ্যাত, not বাতিল)
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যাত'**
  String get documentsStatusRejected;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'প্রকাশিত'**
  String get documentsStatusPublished;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন ছাড়া প্রকাশিত'**
  String get documentsStatusPublishedUnapproved;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদোত্তীর্ণ'**
  String get documentsStatusExpired;

  /// Documents: status
  ///
  /// In bn, this message translates to:
  /// **'আর্কাইভে'**
  String get documentsStatusArchived;

  /// Documents: signature state
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষায়'**
  String get documentsSignaturePending;

  /// Documents: signature state
  ///
  /// In bn, this message translates to:
  /// **'সই হয়েছে — চলতি ভার্সনে'**
  String get documentsSignatureSigned;

  /// Documents: signature state
  ///
  /// In bn, this message translates to:
  /// **'সই পুরনো ভার্সনে — চলতিটায় সই নেই'**
  String get documentsSignatureStale;

  /// Documents: signature state
  ///
  /// In bn, this message translates to:
  /// **'সই চাওয়া হয়নি'**
  String get documentsSignatureNone;

  /// Documents list tab
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get documentsViewAll;

  /// Documents list tab
  ///
  /// In bn, this message translates to:
  /// **'আমার'**
  String get documentsViewMine;

  /// Documents list tab
  ///
  /// In bn, this message translates to:
  /// **'আমার সাথে শেয়ার'**
  String get documentsViewShared;

  /// Documents list tab
  ///
  /// In bn, this message translates to:
  /// **'সাম্প্রতিক'**
  String get documentsViewRecent;

  /// Documents list tab: documents by expiry date
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ'**
  String get documentsViewExpiry;

  /// Documents list tab
  ///
  /// In bn, this message translates to:
  /// **'আর্কাইভ'**
  String get documentsViewArchive;

  /// Documents list tab
  ///
  /// In bn, this message translates to:
  /// **'রিসাইকেল বিন'**
  String get documentsViewRecycle;

  /// Documents list tab
  ///
  /// In bn, this message translates to:
  /// **'খোঁজ (আর্কাইভসহ)'**
  String get documentsViewSearch;

  /// Documents list: title
  ///
  /// In bn, this message translates to:
  /// **'ডকুমেন্টস'**
  String get documentsTitle;

  /// Documents list: load failed
  ///
  /// In bn, this message translates to:
  /// **'কাগজের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get documentsListFailed;

  /// Documents list: old server
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ডকুমেন্টস এখনো আসেনি — অফিসে জানান।'**
  String get documentsListAbsent;

  /// Documents list: empty
  ///
  /// In bn, this message translates to:
  /// **'এখানে দেখার মতো কোনো কাগজ নেই'**
  String get documentsListEmpty;

  /// Documents list: search hint
  ///
  /// In bn, this message translates to:
  /// **'নাম, নম্বর বা ট্যাগ'**
  String get documentsSearchHint;

  /// Documents list: number of documents in the whole list
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{মোট {count} টি কাগজ}}'**
  String documentsCount(int count);

  /// Documents list: expiry date; date already formatted
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ {date}'**
  String documentsExpires(String date);

  /// Documents list: a deleted document
  ///
  /// In bn, this message translates to:
  /// **'মোছা — ফেরানো ওয়েবে'**
  String get documentsDeleted;

  /// Document: file open failed
  ///
  /// In bn, this message translates to:
  /// **'ফাইলটা খোলা গেল না।'**
  String get documentsFileOpenFailed;

  /// Document: sign dialog title
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজে সই দেবেন?'**
  String get documentsSignAsk;

  /// Document: sign dialog field
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য (ইচ্ছা হলে)'**
  String get documentsRemarksOptional;

  /// Document: sign dialog, do not go ahead
  ///
  /// In bn, this message translates to:
  /// **'না'**
  String get documentsNo;

  /// Document: the sign button
  ///
  /// In bn, this message translates to:
  /// **'সই দিন'**
  String get documentsSign;

  /// Document: signed message
  ///
  /// In bn, this message translates to:
  /// **'সই দেওয়া হয়েছে।'**
  String get documentsSigned;

  /// Document: refuse dialog title
  ///
  /// In bn, this message translates to:
  /// **'সই দেবেন না?'**
  String get documentsRefuseAsk;

  /// Document: refuse dialog field
  ///
  /// In bn, this message translates to:
  /// **'কারণ লিখুন'**
  String get documentsReasonField;

  /// Document: refuse dialog, step back
  ///
  /// In bn, this message translates to:
  /// **'ফিরে যান'**
  String get documentsGoBack;

  /// Document: the refuse button
  ///
  /// In bn, this message translates to:
  /// **'সই দেব না'**
  String get documentsRefuse;

  /// Document: refusing needs a reason
  ///
  /// In bn, this message translates to:
  /// **'সই না দেওয়ার কারণ লিখতে হবে।'**
  String get documentsReasonNeeded;

  /// Document: refused message
  ///
  /// In bn, this message translates to:
  /// **'সই দেওয়া হয়নি — কারণসহ জানানো হয়েছে।'**
  String get documentsRefused;

  /// Document: sign or refuse failed
  ///
  /// In bn, this message translates to:
  /// **'সিদ্ধান্তটা পাঠানো গেল না। আবার চেষ্টা করুন।'**
  String get documentsDecisionFailed;

  /// Document page: title before it loads
  ///
  /// In bn, this message translates to:
  /// **'কাগজ'**
  String get documentsPaper;

  /// Document page: load failed
  ///
  /// In bn, this message translates to:
  /// **'কাগজটা আনা গেল না।'**
  String get documentsOneFailed;

  /// Document page: field
  ///
  /// In bn, this message translates to:
  /// **'নম্বর'**
  String get documentsNumber;

  /// Document page and upload: field
  ///
  /// In bn, this message translates to:
  /// **'ধরন'**
  String get documentsType;

  /// Document page and upload: field
  ///
  /// In bn, this message translates to:
  /// **'ফোল্ডার'**
  String get documentsFolder;

  /// Document page and upload: field
  ///
  /// In bn, this message translates to:
  /// **'গোপনীয়তা'**
  String get documentsLevel;

  /// Document page: field
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get documentsStatus;

  /// Document page: field
  ///
  /// In bn, this message translates to:
  /// **'তারিখ'**
  String get documentsDate;

  /// Document page: expiry date field
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ'**
  String get documentsExpiry;

  /// Document page: who owns the document
  ///
  /// In bn, this message translates to:
  /// **'মালিক'**
  String get documentsOwner;

  /// Document page: field
  ///
  /// In bn, this message translates to:
  /// **'ট্যাগ'**
  String get documentsTags;

  /// Document page: field
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ'**
  String get documentsDescription;

  /// Document page: section
  ///
  /// In bn, this message translates to:
  /// **'ফাইল'**
  String get documentsFile;

  /// Document page: no file
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজে এখনো কোনো ফাইল নেই।'**
  String get documentsNoFile;

  /// Document page: file name
  ///
  /// In bn, this message translates to:
  /// **'নাম'**
  String get documentsFileName;

  /// Document page: file version
  ///
  /// In bn, this message translates to:
  /// **'ভার্সন'**
  String get documentsVersion;

  /// Document page: open the file
  ///
  /// In bn, this message translates to:
  /// **'দেখুন'**
  String get documentsViewFile;

  /// Document page: download or share the file
  ///
  /// In bn, this message translates to:
  /// **'নামান / পাঠান'**
  String get documentsDownload;

  /// Document page: no view and no download
  ///
  /// In bn, this message translates to:
  /// **'এই ফাইলটা ফোনে দেখা যায় না, আর নামানোর অধিকার আপনার নেই।'**
  String get documentsCannotOpen;

  /// Document page: section
  ///
  /// In bn, this message translates to:
  /// **'সই'**
  String get documentsSignature;

  /// Document page: a signature on an old version; date already formatted
  ///
  /// In bn, this message translates to:
  /// **'{date} · পুরনো ভার্সনে'**
  String documentsSignedOld(String date);

  /// Document page: the user must sign
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজে আপনার সই চাওয়া হয়েছে। ফাইলটা দেখে সই দিন।'**
  String get documentsSignWanted;

  /// Upload: form load failed
  ///
  /// In bn, this message translates to:
  /// **'তোলার ফর্ম আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get documentsUploadFormFailed;

  /// Upload: old server
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোন থেকে তোলা এখনো আসেনি — অফিসে জানান।'**
  String get documentsUploadAbsent;

  /// Upload (scan): nothing picked
  ///
  /// In bn, this message translates to:
  /// **'আগে কাগজটার ছবি তুলুন।'**
  String get documentsUploadTakePhotoFirst;

  /// Upload: nothing picked
  ///
  /// In bn, this message translates to:
  /// **'আগে অন্তত একটা ছবি বা ফাইল বাছুন।'**
  String get documentsUploadPickFirst;

  /// Upload: fields missing
  ///
  /// In bn, this message translates to:
  /// **'ফোল্ডার, ধরন আর গোপনীয়তা বাছুন।'**
  String get documentsUploadPickFields;

  /// Upload: done
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} টি কাগজ তোলা হলো।}}'**
  String documentsUploaded(int count);

  /// Upload: failed
  ///
  /// In bn, this message translates to:
  /// **'কাগজ তোলা গেল না।'**
  String get documentsUploadFailed;

  /// Upload (scan): title
  ///
  /// In bn, this message translates to:
  /// **'ক্যামেরায় স্ক্যান'**
  String get documentsScanTitle;

  /// Upload: title
  ///
  /// In bn, this message translates to:
  /// **'কাগজ তোলা'**
  String get documentsUploadTitle;

  /// Upload: empty state title on failure
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get documentsLoadFailed;

  /// Upload (scan): button
  ///
  /// In bn, this message translates to:
  /// **'ছবি তুলুন'**
  String get documentsTakePhoto;

  /// Upload: button
  ///
  /// In bn, this message translates to:
  /// **'ছবি বাছুন'**
  String get documentsPickPhotos;

  /// Upload: button
  ///
  /// In bn, this message translates to:
  /// **'ফাইল বাছুন (PDF ইত্যাদি)'**
  String get documentsPickFiles;

  /// Upload: files picked
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} টি ফাইল — প্রতিটা আলাদা কাগজ হবে}}'**
  String documentsUploadFileCount(int count);

  /// Upload: note
  ///
  /// In bn, this message translates to:
  /// **'ⓘ কোন ধরনের ফাইল চলে আর কত বড়, সার্ভার ওয়েবের নিয়মেই দেখে। লেখা পড়া (OCR) ওয়েবে।'**
  String get documentsUploadRulesNote;

  /// Upload: name field
  ///
  /// In bn, this message translates to:
  /// **'নাম (ফাঁকা রাখলে ফাইলের নাম)'**
  String get documentsUploadName;

  /// Upload: branch field
  ///
  /// In bn, this message translates to:
  /// **'শাখা'**
  String get documentsBranch;

  /// Upload: no branch
  ///
  /// In bn, this message translates to:
  /// **'গোটা কোম্পানি'**
  String get documentsWholeCompany;

  /// Upload: description field
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ (ঐচ্ছিক)'**
  String get documentsDescriptionOptional;

  /// Upload: busy
  ///
  /// In bn, this message translates to:
  /// **'তোলা হচ্ছে…'**
  String get documentsUploading;

  /// Upload: the button
  ///
  /// In bn, this message translates to:
  /// **'তুলুন'**
  String get documentsUpload;

  /// Leads list: load failed
  ///
  /// In bn, this message translates to:
  /// **'লিড আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get leadsFailed;

  /// Leads list: old server
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে লিডের দরজা এখনো আসেনি — অফিসে জানান।'**
  String get leadsAbsent;

  /// Leads list: title
  ///
  /// In bn, this message translates to:
  /// **'লিড'**
  String get leadsTitle;

  /// Leads: new lead button and form title
  ///
  /// In bn, this message translates to:
  /// **'নতুন লিড'**
  String get leadsNew;

  /// Leads list: search hint
  ///
  /// In bn, this message translates to:
  /// **'নাম, ফোন বা নম্বর'**
  String get leadsSearchHint;

  /// Leads list: all statuses chip
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get leadsAll;

  /// Leads list: total strip label
  ///
  /// In bn, this message translates to:
  /// **'মোট লিড'**
  String get leadsTotal;

  /// Leads list: number of leads
  ///
  /// In bn, this message translates to:
  /// **'{count} টি'**
  String leadsCount(int count);

  /// Leads list: empty state title on failure
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get leadsLoadFailed;

  /// Leads list: empty
  ///
  /// In bn, this message translates to:
  /// **'কোনো লিড নেই'**
  String get leadsNone;

  /// Leads list: load more
  ///
  /// In bn, this message translates to:
  /// **'আরও লিড'**
  String get leadsMore;

  /// Lead form: name missing
  ///
  /// In bn, this message translates to:
  /// **'দোকানের নাম লিখুন।'**
  String get leadsNameNeeded;

  /// Lead form: source missing
  ///
  /// In bn, this message translates to:
  /// **'কীভাবে খোঁজ পেলেন, বাছুন।'**
  String get leadsSourceNeeded;

  /// Lead form: save failed
  ///
  /// In bn, this message translates to:
  /// **'লিড রাখা গেল না। আবার চেষ্টা করুন।'**
  String get leadsSaveFailed;

  /// Lead form: current status (server label)
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা: {status}'**
  String leadsStatusNow(String status);

  /// Lead form: who owns the lead
  ///
  /// In bn, this message translates to:
  /// **'মালিক: {name}'**
  String leadsOwner(String name);

  /// Lead form: converted, read only
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক হয়ে গেছেন — আর বদলানো যায় না।'**
  String get leadsConverted;

  /// Lead form: field
  ///
  /// In bn, this message translates to:
  /// **'দোকানের নাম'**
  String get leadsShopName;

  /// Lead form: field
  ///
  /// In bn, this message translates to:
  /// **'যাঁর সাথে কথা'**
  String get leadsContactPerson;

  /// Lead form: field
  ///
  /// In bn, this message translates to:
  /// **'ফোন'**
  String get leadsPhone;

  /// Lead form: field
  ///
  /// In bn, this message translates to:
  /// **'ঠিকানা'**
  String get leadsAddress;

  /// Lead form: field
  ///
  /// In bn, this message translates to:
  /// **'কীভাবে খোঁজ পেলেন'**
  String get leadsSource;

  /// Lead form: field
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get leadsStatus;

  /// Lead form: field
  ///
  /// In bn, this message translates to:
  /// **'কেন হারালাম'**
  String get leadsLostReason;

  /// Lead form: field
  ///
  /// In bn, this message translates to:
  /// **'টুকিটাকি'**
  String get leadsNotes;

  /// Paper list: load failed; title is the kind of paper
  ///
  /// In bn, this message translates to:
  /// **'{title}-এর তালিকা আনা গেল না।'**
  String papersListFailed(String title);

  /// Paper list: old server
  ///
  /// In bn, this message translates to:
  /// **'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get papersListAbsent;

  /// Paper list: branch chip
  ///
  /// In bn, this message translates to:
  /// **'সব শাখা'**
  String get papersAllBranches;

  /// Paper list: all statuses chip
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get papersAll;

  /// Paper list: search hint
  ///
  /// In bn, this message translates to:
  /// **'নম্বর বা নাম'**
  String get papersSearchHint;

  /// Paper list: total of the whole filtered list
  ///
  /// In bn, this message translates to:
  /// **'সর্বমোট'**
  String get papersGrandTotal;

  /// Paper list: total of this page
  ///
  /// In bn, this message translates to:
  /// **'এই পাতার মোট ({count}টি)'**
  String papersPageTotal(int count);

  /// Paper list: number of papers
  ///
  /// In bn, this message translates to:
  /// **'{count}টি'**
  String papersCount(int count);

  /// Paper list: empty; title is the kind of paper
  ///
  /// In bn, this message translates to:
  /// **'কোনো {title} নেই'**
  String papersNone(String title);

  /// Paper list: empty, dated list
  ///
  /// In bn, this message translates to:
  /// **'তারিখ বা ছাঁকনি বদলে, বা নিচে টেনে আবার দেখুন।'**
  String get papersNoneDated;

  /// Paper list: empty
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার দেখুন।'**
  String get papersNoneUndated;

  /// Paper list: load more
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get papersMore;

  /// Paper page: load failed; title is the kind of paper
  ///
  /// In bn, this message translates to:
  /// **'{title}-টা আনা গেল না।'**
  String papersOneFailed(String title);

  /// Paper page: print button tooltip
  ///
  /// In bn, this message translates to:
  /// **'ছাপা / পাঠান'**
  String get papersPrint;

  /// Paper page: field
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get papersStatus;

  /// Paper page: field
  ///
  /// In bn, this message translates to:
  /// **'শাখা'**
  String get papersBranch;

  /// Paper page: section
  ///
  /// In bn, this message translates to:
  /// **'সারি'**
  String get papersLines;

  /// Paper page: a column's total; column is the server's column name, may be empty
  ///
  /// In bn, this message translates to:
  /// **'মোট {column}'**
  String papersTotalOf(String column);

  /// Paper page: section
  ///
  /// In bn, this message translates to:
  /// **'সই'**
  String get papersSignatures;

  /// Paper page: who asked for the signature
  ///
  /// In bn, this message translates to:
  /// **'চেয়েছেন: {name}'**
  String papersRequestedBy(String name);

  /// Paper page: signing steps, joined with arrows
  ///
  /// In bn, this message translates to:
  /// **'ধাপ: {steps}'**
  String papersSteps(String steps);

  /// Paper page: the sign button
  ///
  /// In bn, this message translates to:
  /// **'সই দিন'**
  String get papersSign;
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
