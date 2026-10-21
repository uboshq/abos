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

  /// Payment method / money account kind: cash
  ///
  /// In bn, this message translates to:
  /// **'নগদ'**
  String get booksMethodCash;

  /// Payment method / money account kind: bank
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক'**
  String get booksMethodBank;

  /// Payment method: cheque
  ///
  /// In bn, this message translates to:
  /// **'চেক'**
  String get booksMethodCheque;

  /// Payment method: anything else
  ///
  /// In bn, this message translates to:
  /// **'অন্য'**
  String get booksMethodOther;

  /// Money account kind: mobile banking (bKash, Nagad)
  ///
  /// In bn, this message translates to:
  /// **'মোবাইল ব্যাংকিং'**
  String get booksMethodMobileBanking;

  /// Money receipt state: confirmed, in the ledger (fe's word, 21 Oct 2026)
  ///
  /// In bn, this message translates to:
  /// **'পোস্ট হয়েছে · খাতায় উঠেছে'**
  String get booksReceiptPosted;

  /// Money receipt state: draft, waiting for approval or confirmation
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায় — অনুমোদন বা নিশ্চিত বাকি'**
  String get booksReceiptPending;

  /// Money receipt state: cancelled
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get booksReceiptCancelled;

  /// Principal balance: zero
  ///
  /// In bn, this message translates to:
  /// **'জের নেই'**
  String get booksBalanceNone;

  /// Principal balance: we owe them this much
  ///
  /// In bn, this message translates to:
  /// **'দিতে হবে: {amount}'**
  String booksBalanceToPay(String amount);

  /// Principal balance: they owe us this much
  ///
  /// In bn, this message translates to:
  /// **'পাব: {amount}'**
  String booksBalanceToReceive(String amount);

  /// Date range chip: today
  ///
  /// In bn, this message translates to:
  /// **'আজ'**
  String get booksRangeToday;

  /// Date range chip: this month so far
  ///
  /// In bn, this message translates to:
  /// **'এ মাস'**
  String get booksRangeMonth;

  /// Total strip: how many rows the total covers
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{· {count} টি}}'**
  String booksTotalCount(int count);

  /// Button under a list: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও দেখুন'**
  String get booksMore;

  /// Take a collection: the money account list did not load
  ///
  /// In bn, this message translates to:
  /// **'টাকার খাতের তালিকা আনা গেল না। নেট দেখে আবার খুলুন।'**
  String get booksCollectAccountsFailed;

  /// Take a collection: error, no customer chosen
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক বাছুন।'**
  String get booksCollectPickCustomerError;

  /// Take a collection: error, the amount typed is not a valid number
  ///
  /// In bn, this message translates to:
  /// **'অঙ্কটা ঠিক নয় — শূন্যের বেশি, দুই দশমিক পর্যন্ত।'**
  String get booksCollectAmountWrong;

  /// Take a collection: error, no money account chosen
  ///
  /// In bn, this message translates to:
  /// **'টাকার খাত বাছুন।'**
  String get booksCollectPickAccountError;

  /// Take a collection: the server refused without a reason
  ///
  /// In bn, this message translates to:
  /// **'আদায়টা বসেনি। আবার চেষ্টা করুন।'**
  String get booksCollectNotLanded;

  /// Take a collection: the network dropped after sending
  ///
  /// In bn, this message translates to:
  /// **'আদায়ের উত্তর আসেনি — সার্ভারে হয়তো পৌঁছেছে — কিছু না বদলে আবার চাপুন; আগেরটা বসে থাকলে সেটাই দেখাবে, দুবার বসবে না।'**
  String get booksCollectMayHaveLanded;

  /// Take a collection: no network before sending
  ///
  /// In bn, this message translates to:
  /// **'আদায় দিতে নেট লাগবে। নেট চালু করে আবার চাপুন — ফোনে কিছু জমা থাকেনি।'**
  String get booksCollectNeedsNet;

  /// Take a collection: sending failed
  ///
  /// In bn, this message translates to:
  /// **'আদায় জমা করা গেল না। আবার চেষ্টা করুন।'**
  String get booksCollectSendFailed;

  /// Take a collection: screen title
  ///
  /// In bn, this message translates to:
  /// **'টাকা আদায় নিন'**
  String get booksCollectTitle;

  /// Take a collection: the customer tile before one is chosen
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক বাছুন'**
  String get booksCollectPickCustomer;

  /// Take a collection: amount field label
  ///
  /// In bn, this message translates to:
  /// **'অঙ্ক (টাকা)'**
  String get booksCollectAmountLabel;

  /// Take a collection: money account dropdown label
  ///
  /// In bn, this message translates to:
  /// **'কোন খাতে'**
  String get booksCollectAccountLabel;

  /// Take a collection: instrument field label (bank or mobile wallet name)
  ///
  /// In bn, this message translates to:
  /// **'মাধ্যম'**
  String get booksCollectInstrumentLabel;

  /// Take a collection: instrument field hint
  ///
  /// In bn, this message translates to:
  /// **'যেমন bKash, Nagad, ব্যাংক জমা'**
  String get booksCollectInstrumentHint;

  /// Take a collection: transaction number field label
  ///
  /// In bn, this message translates to:
  /// **'লেনদেন-নম্বর (TrxID)'**
  String get booksCollectTrxLabel;

  /// Take a collection: date row
  ///
  /// In bn, this message translates to:
  /// **'তারিখ'**
  String get booksCollectDate;

  /// Take a collection: remarks field label
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য (ঐচ্ছিক)'**
  String get booksCollectRemarks;

  /// Take a collection: switch, confirm the receipt at once
  ///
  /// In bn, this message translates to:
  /// **'এখনই নিশ্চিত'**
  String get booksCollectConfirmNow;

  /// Take a collection: hint under the confirm switch
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন লাগলে আদায়টা অপেক্ষায় থাকবে, ওয়েবের মতো'**
  String get booksCollectConfirmHint;

  /// Take a collection: send button, no amount yet
  ///
  /// In bn, this message translates to:
  /// **'আদায় জমা দিন'**
  String get booksCollectSend;

  /// Take a collection: send button with the amount
  ///
  /// In bn, this message translates to:
  /// **'আদায় জমা দিন — {amount}'**
  String booksCollectSendAmount(String amount);

  /// Take a collection: customer search field label
  ///
  /// In bn, this message translates to:
  /// **'নাম, কোড, ফোন বা পয়েন্ট'**
  String get booksCollectSearchLabel;

  /// Take a collection: customer search found nothing
  ///
  /// In bn, this message translates to:
  /// **'কোনো গ্রাহক মেলেনি।'**
  String get booksCollectNoCustomer;

  /// Collections list: loading failed
  ///
  /// In bn, this message translates to:
  /// **'আদায়ের তালিকা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।'**
  String get booksMoneyInListFailed;

  /// Collections list: the server is too old for this page
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে আদায়ের তালিকা এখনো আসেনি — অফিসে জানান।'**
  String get booksMoneyInListAbsent;

  /// Collections: screen title, and a receipt's title before its number loads
  ///
  /// In bn, this message translates to:
  /// **'আদায়'**
  String get booksMoneyInTitle;

  /// Screen bar: the module name 'Sales' above the title
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়'**
  String get booksModuleSales;

  /// Collections list: the floating button to take a new collection
  ///
  /// In bn, this message translates to:
  /// **'নতুন আদায়'**
  String get booksMoneyInNew;

  /// Collections list: search field hint
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক, দোকান বা নম্বর খুঁজুন'**
  String get booksMoneyInSearchHint;

  /// Collections list: method chip, every method
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get booksMoneyInAllMethods;

  /// Collections list: empty state title
  ///
  /// In bn, this message translates to:
  /// **'এই সময়ে কোনো আদায় নেই'**
  String get booksMoneyInEmptyTitle;

  /// Collections list: empty state message
  ///
  /// In bn, this message translates to:
  /// **'তারিখ বা পদ্ধতি বদলে দেখুন।'**
  String get booksMoneyInEmptyHint;

  /// Collections list: empty state button for office staff
  ///
  /// In bn, this message translates to:
  /// **'আদায় নিন'**
  String get booksMoneyInEmptyCollect;

  /// Books screens: button that loads the list again
  ///
  /// In bn, this message translates to:
  /// **'নতুন করে দেখুন'**
  String get booksRefresh;

  /// Collections list: card title when the range is today
  ///
  /// In bn, this message translates to:
  /// **'আজকের আদায়'**
  String get booksMoneyInToday;

  /// Collections list: card of shops that owe money
  ///
  /// In bn, this message translates to:
  /// **'দোকানের বকেয়া'**
  String get booksShopDuesTitle;

  /// Shop dues card: how many shops are not shown
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{আরও {count}টি দোকান}}'**
  String booksShopDuesMore(int count);

  /// Shop dues card: total line over every shop
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{মোট · {count}টি দোকান}}'**
  String booksShopDuesTotal(int count);

  /// Money receipt: loading failed
  ///
  /// In bn, this message translates to:
  /// **'আদায়টা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।'**
  String get booksMoneyInFailed;

  /// Tooltip: print or share this paper
  ///
  /// In bn, this message translates to:
  /// **'ছাপুন / পাঠান'**
  String get booksPrintSend;

  /// Print sheet title for a money receipt
  ///
  /// In bn, this message translates to:
  /// **'টাকার রসিদ · {no}'**
  String booksMoneyInPrintTitle(String no);

  /// Fact row label: customer
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক'**
  String get booksFactCustomer;

  /// Fact row label: date
  ///
  /// In bn, this message translates to:
  /// **'তারিখ'**
  String get booksFactDate;

  /// Fact row label: the money amount
  ///
  /// In bn, this message translates to:
  /// **'টাকা'**
  String get booksFactAmount;

  /// Fact row label: the paper's state
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get booksFactStatus;

  /// Fact row label: payment method
  ///
  /// In bn, this message translates to:
  /// **'পদ্ধতি'**
  String get booksFactMethod;

  /// Fact row label: money account
  ///
  /// In bn, this message translates to:
  /// **'খাত'**
  String get booksFactAccount;

  /// Fact row label: instrument (cheque or transaction) number
  ///
  /// In bn, this message translates to:
  /// **'নম্বর'**
  String get booksFactNumber;

  /// Fact row label: date on the cheque or slip
  ///
  /// In bn, this message translates to:
  /// **'কাগজের তারিখ'**
  String get booksFactInstrumentDate;

  /// Fact row label: narration
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ'**
  String get booksFactNarration;

  /// Fact row label: who entered it
  ///
  /// In bn, this message translates to:
  /// **'লিখেছেন'**
  String get booksFactWrittenBy;

  /// Money receipt: heading of the bill-by-bill split
  ///
  /// In bn, this message translates to:
  /// **'কোন বিলে কত'**
  String get booksMoneyInLines;

  /// Money receipt: a split line not tied to any bill
  ///
  /// In bn, this message translates to:
  /// **'অগ্রিম'**
  String get booksMoneyInAdvance;

  /// Principal commission: screen title
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালের কমিশন'**
  String get booksPcTitle;

  /// Screen bar: the module name 'Supplier' above the title
  ///
  /// In bn, this message translates to:
  /// **'সরবরাহকারী'**
  String get booksModuleSupplier;

  /// Principal commission: error title
  ///
  /// In bn, this message translates to:
  /// **'তথ্য আনা গেল না'**
  String get booksPcLoadFailedTitle;

  /// Principal commission: loading failed
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালের কমিশন আনা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।'**
  String get booksPcLoadFailed;

  /// Principal commission: the server is too old or the supplier module is off
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে এই পাতা এখনো নেই, বা সরবরাহকারীর অংশ বন্ধ। সার্ভার হালনাগাদ হলে দেখা যাবে।'**
  String get booksPcAbsent;

  /// Principal commission: empty state title
  ///
  /// In bn, this message translates to:
  /// **'এই চক্রে কোনো প্রিন্সিপালের কমিশন নেই'**
  String get booksPcEmptyTitle;

  /// Principal commission: empty state message
  ///
  /// In bn, this message translates to:
  /// **'কমিশনের নিয়ম দেওয়া প্রিন্সিপাল থাকলে এখানে দেখা যাবে।'**
  String get booksPcEmptyHint;

  /// Principal commission: bottom bar label; side is the net direction word (to pay / to receive)
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{সর্বমোট · {side} · {count} জন}}'**
  String booksPcGrandTotal(int count, String side);

  /// Principal commission: tooltip, go one month back
  ///
  /// In bn, this message translates to:
  /// **'আগের মাস'**
  String get booksPcPrevMonth;

  /// Principal commission: the month bar when no month is chosen
  ///
  /// In bn, this message translates to:
  /// **'চলতি চক্র'**
  String get booksPcCurrentCycle;

  /// Principal commission: tooltip, go one month forward
  ///
  /// In bn, this message translates to:
  /// **'পরের মাস'**
  String get booksPcNextMonth;

  /// Principal commission: button back to the current cycle
  ///
  /// In bn, this message translates to:
  /// **'চলতি'**
  String get booksPcCurrent;

  /// Principal commission totals card: total money in
  ///
  /// In bn, this message translates to:
  /// **'মোট এসেছে'**
  String get booksPcTotalInflow;

  /// Principal commission totals card: commission
  ///
  /// In bn, this message translates to:
  /// **'কমিশন'**
  String get booksPcTotalCommission;

  /// Principal commission totals card: money sent
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো'**
  String get booksPcTotalSent;

  /// Principal commission row: money in
  ///
  /// In bn, this message translates to:
  /// **'এসেছে {amount}'**
  String booksPcInflowLine(String amount);

  /// Principal commission row: commission
  ///
  /// In bn, this message translates to:
  /// **'কমিশন {amount}'**
  String booksPcCommissionLine(String amount);

  /// Principal commission row: commission with its basis rate
  ///
  /// In bn, this message translates to:
  /// **'কমিশন ({rate}) {amount}'**
  String booksPcCommissionRateLine(String rate, String amount);

  /// Principal commission row: money sent
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো {amount}'**
  String booksPcSentLine(String amount);

  /// Principal commission screen reader: an amount the server did not give
  ///
  /// In bn, this message translates to:
  /// **'জানা নেই'**
  String get booksPcUnknown;

  /// Principal commission row: the cycle's dates
  ///
  /// In bn, this message translates to:
  /// **'চক্র: {cycle}'**
  String booksPcCycle(String cycle);

  /// Principals list: loading failed
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get booksPrincipalListFailed;

  /// Principals list: the server is too old for this page
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে প্রিন্সিপালের তালিকা এখনো আসেনি — অফিসে জানান।'**
  String get booksPrincipalListAbsent;

  /// Principals: screen title, and a ledger's title before its name loads
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপাল'**
  String get booksPrincipalTitle;

  /// Principals list: search field hint
  ///
  /// In bn, this message translates to:
  /// **'নাম বা কোড খুঁজুন'**
  String get booksPrincipalSearchHint;

  /// Principals list: total strip, what we owe all principals
  ///
  /// In bn, this message translates to:
  /// **'মোট দেনা'**
  String get booksPrincipalTotalDue;

  /// Principals list: empty
  ///
  /// In bn, this message translates to:
  /// **'কোনো প্রিন্সিপাল নেই।'**
  String get booksPrincipalNone;

  /// Principals list row: never bought from
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো ক্রয় নেই'**
  String get booksPrincipalNoPurchase;

  /// Principals list row: date of the last purchase
  ///
  /// In bn, this message translates to:
  /// **'শেষ ক্রয়: {date}'**
  String booksPrincipalLastPurchaseOn(String date);

  /// Principal ledger: loading failed
  ///
  /// In bn, this message translates to:
  /// **'খাতা আনা গেল না।'**
  String get booksLedgerFailed;

  /// Fact row label: full name
  ///
  /// In bn, this message translates to:
  /// **'পুরো নাম'**
  String get booksFactFullName;

  /// Fact row label: phone
  ///
  /// In bn, this message translates to:
  /// **'ফোন'**
  String get booksFactPhone;

  /// Fact row label: running balance
  ///
  /// In bn, this message translates to:
  /// **'জের'**
  String get booksFactBalance;

  /// Fact row label: date of the last purchase
  ///
  /// In bn, this message translates to:
  /// **'শেষ ক্রয়'**
  String get booksFactLastPurchase;

  /// Principal ledger: empty
  ///
  /// In bn, this message translates to:
  /// **'খাতায় কোনো লেনদেন নেই।'**
  String get booksLedgerEmpty;

  /// Purchases list: loading failed
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়ের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get booksPurchaseListFailed;

  /// Purchases list: the server is too old for this page
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ক্রয়ের তালিকা এখনো আসেনি — অফিসে জানান।'**
  String get booksPurchaseListAbsent;

  /// Purchases list: the principal picker did not load
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালের তালিকা আনা গেল না।'**
  String get booksPurchasePrincipalsFailed;

  /// Purchases list: principal filter, every principal
  ///
  /// In bn, this message translates to:
  /// **'সব প্রিন্সিপাল'**
  String get booksPurchaseAllPrincipals;

  /// Purchases list: screen title
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়'**
  String get booksPurchaseTitle;

  /// Purchase paper kind: purchase bill
  ///
  /// In bn, this message translates to:
  /// **'ক্রয় বিল'**
  String get booksPurchaseBill;

  /// Purchase paper kind: goods received
  ///
  /// In bn, this message translates to:
  /// **'মাল গ্রহণ'**
  String get booksPurchaseGoodsReceipt;

  /// Purchases list: total strip for bills
  ///
  /// In bn, this message translates to:
  /// **'মোট ক্রয়'**
  String get booksPurchaseTotalBills;

  /// Purchases list: total strip for goods receipts
  ///
  /// In bn, this message translates to:
  /// **'মোট গ্রহণ'**
  String get booksPurchaseTotalReceipts;

  /// Purchases list: empty
  ///
  /// In bn, this message translates to:
  /// **'এই সময়ে কিছু নেই।'**
  String get booksPurchaseEmpty;

  /// Purchases list row: paid and outstanding on the bill
  ///
  /// In bn, this message translates to:
  /// **'পরিশোধিত {paid} · বাকি {due}'**
  String booksPurchasePaidDue(String paid, String due);

  /// Purchase paper: loading failed
  ///
  /// In bn, this message translates to:
  /// **'কাগজটা আনা গেল না।'**
  String get booksPurchaseFailed;

  /// Print sheet title for a purchase paper: kind word and number
  ///
  /// In bn, this message translates to:
  /// **'{kind} · {no}'**
  String booksPurchasePrintTitle(String kind, String no);

  /// Fact row label: principal (supplier)
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপাল'**
  String get booksFactPrincipal;

  /// Fact row label: the supplier's own paper number
  ///
  /// In bn, this message translates to:
  /// **'তাদের কাগজের নম্বর'**
  String get booksFactSupplierNo;

  /// Fact row label: total
  ///
  /// In bn, this message translates to:
  /// **'মোট'**
  String get booksFactTotal;

  /// Fact row label: paid
  ///
  /// In bn, this message translates to:
  /// **'পরিশোধিত'**
  String get booksFactPaid;

  /// Fact row label: still owed
  ///
  /// In bn, this message translates to:
  /// **'বাকি'**
  String get booksFactDue;

  /// Purchase paper: heading of the product lines
  ///
  /// In bn, this message translates to:
  /// **'পণ্য'**
  String get booksPurchaseProducts;

  /// Purchase line: quantity
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ {qty}'**
  String booksPurchaseQty(String qty);

  /// Purchase line: free quantity
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি {qty}'**
  String booksPurchaseFree(String qty);

  /// Purchase line: lot/batch
  ///
  /// In bn, this message translates to:
  /// **'লট {lot}'**
  String booksPurchaseLot(String lot);

  /// Purchase line: buying rate
  ///
  /// In bn, this message translates to:
  /// **'দর {amount}'**
  String booksPurchaseRate(String amount);

  /// Customers: screen title, and a customer screen's title when the customer is missing
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক'**
  String get customersTitle;

  /// Customer detail: the customer dropped out of the local catalogue
  ///
  /// In bn, this message translates to:
  /// **'এই গ্রাহক আর এই ফোনে নেই — সিঙ্ক করে দেখুন।'**
  String get customersDetailGone;

  /// Screen reader tap hint / button: open the customer's ledger
  ///
  /// In bn, this message translates to:
  /// **'খাতা খুলুন'**
  String get customersOpenLedgerHint;

  /// Customer detail: heading of the shop's synced orders
  ///
  /// In bn, this message translates to:
  /// **'এই দোকানের অর্ডার ({count})'**
  String customersDetailOrders(int count);

  /// Customer detail: no synced orders
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনে এই দোকানের কোনো অর্ডার সিঙ্ক হয়নি।'**
  String get customersDetailNoOrders;

  /// Customer detail: an order without a document number
  ///
  /// In bn, this message translates to:
  /// **'নম্বর নেই'**
  String get customersDetailNoNumber;

  /// Customer detail: warning, inactive customer
  ///
  /// In bn, this message translates to:
  /// **'⚠️ এই গ্রাহক নিষ্ক্রিয়'**
  String get customersDetailInactive;

  /// Customer detail due card: credit limit amount
  ///
  /// In bn, this message translates to:
  /// **'সীমা {amount}'**
  String customersDetailLimit(String amount);

  /// Customer detail due card: credit days
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{{days} দিনের শর্ত}}'**
  String customersDetailCreditDays(int days);

  /// Customer detail due card: when the due figure last synced
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক {stamp}'**
  String customersDetailSynced(String stamp);

  /// Customer detail: dial the shop
  ///
  /// In bn, this message translates to:
  /// **'কল করুন'**
  String get customersDetailCall;

  /// Customer detail: open the address in maps
  ///
  /// In bn, this message translates to:
  /// **'ঠিকানা'**
  String get customersDetailAddress;

  /// Customer detail: open the shop's ledger
  ///
  /// In bn, this message translates to:
  /// **'খাতা দেখুন'**
  String get customersDetailLedger;

  /// Customer detail: open the credit page
  ///
  /// In bn, this message translates to:
  /// **'বাকির অবস্থা'**
  String get customersDetailCredit;

  /// Deposit notification: button and screen title
  ///
  /// In bn, this message translates to:
  /// **'জমার বিজ্ঞপ্তি'**
  String get customersDepositNotice;

  /// Customer detail: start a new order
  ///
  /// In bn, this message translates to:
  /// **'নতুন অর্ডার'**
  String get customersDetailNewOrder;

  /// Customer ledger: loading failed
  ///
  /// In bn, this message translates to:
  /// **'খাতা আনা গেল না।'**
  String get customersLedgerFailed;

  /// Customer ledger: empty
  ///
  /// In bn, this message translates to:
  /// **'এই দোকানের খাতায় এখনো কোনো লেনদেন নেই।'**
  String get customersLedgerEmpty;

  /// Customer ledger: load older rows
  ///
  /// In bn, this message translates to:
  /// **'আরও আগের লেনদেন'**
  String get customersLedgerMore;

  /// Customer ledger: title before the shop's name loads
  ///
  /// In bn, this message translates to:
  /// **'খাতা'**
  String get customersLedgerTitle;

  /// Customer ledger header: the shop owes this much
  ///
  /// In bn, this message translates to:
  /// **'বাকি আছে'**
  String get customersLedgerHasDue;

  /// Customer ledger header: the shop has paid ahead
  ///
  /// In bn, this message translates to:
  /// **'আগাম জমা আছে'**
  String get customersLedgerHasAdvance;

  /// Customer ledger header: nothing owed either way
  ///
  /// In bn, this message translates to:
  /// **'হিসাব মিলে আছে'**
  String get customersLedgerSettled;

  /// Customer ledger header: the shop's code
  ///
  /// In bn, this message translates to:
  /// **'কোড {code}'**
  String customersLedgerCode(String code);

  /// Customer ledger statement: opening balance
  ///
  /// In bn, this message translates to:
  /// **'শুরুর জের'**
  String get customersLedgerOpening;

  /// Customer ledger statement: total debits (goods taken)
  ///
  /// In bn, this message translates to:
  /// **'মোট মাল নিয়েছেন'**
  String get customersLedgerGoodsTotal;

  /// Customer ledger statement: total credits (money paid)
  ///
  /// In bn, this message translates to:
  /// **'মোট টাকা দিয়েছেন'**
  String get customersLedgerPaidTotal;

  /// Customer ledger statement: closing balance
  ///
  /// In bn, this message translates to:
  /// **'শেষের জের'**
  String get customersLedgerClosing;

  /// Screen reader: a ledger row where goods were taken
  ///
  /// In bn, this message translates to:
  /// **'মাল {amount}'**
  String customersLedgerSpokenGoods(String amount);

  /// Screen reader: a ledger row where money was paid
  ///
  /// In bn, this message translates to:
  /// **'জমা {amount}'**
  String customersLedgerSpokenPaid(String amount);

  /// Customer ledger row title: goods taken (due went up)
  ///
  /// In bn, this message translates to:
  /// **'মাল নিয়েছেন'**
  String get customersLedgerTookGoods;

  /// Customer ledger row title: money paid (due went down)
  ///
  /// In bn, this message translates to:
  /// **'টাকা দিয়েছেন'**
  String get customersLedgerPaidMoney;

  /// Customer ledger row: balance after the row is zero
  ///
  /// In bn, this message translates to:
  /// **'মিলে গেছে'**
  String get customersLedgerRowSettled;

  /// Customer ledger row: balance after the row, shop owes
  ///
  /// In bn, this message translates to:
  /// **'বাকি {amount}'**
  String customersLedgerRowDue(String amount);

  /// Customer ledger row: balance after the row, shop is in credit
  ///
  /// In bn, this message translates to:
  /// **'জমা {amount}'**
  String customersLedgerRowCredit(String amount);

  /// Customer ledger: month heading for rows without a date
  ///
  /// In bn, this message translates to:
  /// **'তারিখ নেই'**
  String get customersLedgerNoDate;

  /// Customers two-pane: nothing chosen yet
  ///
  /// In bn, this message translates to:
  /// **'বাঁয়ের তালিকা থেকে একজন গ্রাহক বাছুন'**
  String get customersPickFromList;

  /// Customers / due list: search field hint
  ///
  /// In bn, this message translates to:
  /// **'নাম বা মোবাইল দিয়ে খুঁজুন'**
  String get customersSearchHint;

  /// Customers list: total strip of what the shown customers owe
  ///
  /// In bn, this message translates to:
  /// **'মোট বকেয়া'**
  String get customersTotalDue;

  /// Customers list: empty catalogue
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো গ্রাহক সিঙ্ক হয়নি'**
  String get customersNoneSynced;

  /// Customers list: the pull failed
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহকের তালিকা আনা যায়নি'**
  String get customersListFailed;

  /// Customers list: hint under the empty or failed state
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার চেষ্টা করুন।'**
  String get customersPullToRetry;

  /// Customers / due list: search found nothing
  ///
  /// In bn, this message translates to:
  /// **'কোনো মিল পাওয়া যায়নি'**
  String get customersNoMatch;

  /// Customers / due list: search found nothing while filters are on
  ///
  /// In bn, this message translates to:
  /// **'ফিল্টার মুছে আবার দেখুন।'**
  String get customersClearFilters;

  /// Customers list row: credit days
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{{days} দিন}}'**
  String customersCreditDaysShort(int days);

  /// Due list: screen title
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া তালিকা'**
  String get customersDueTitle;

  /// Due list: loading failed
  ///
  /// In bn, this message translates to:
  /// **'বকেয়ার তালিকা আনা যায়নি'**
  String get customersDueListFailed;

  /// Due list: empty
  ///
  /// In bn, this message translates to:
  /// **'কারো কাছে বকেয়া নেই'**
  String get customersDueNone;

  /// Due list: hint under the empty state
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক হয়নি মনে হলে নতুন করে দেখুন।'**
  String get customersDueNoneHint;

  /// Due list: button that loads again
  ///
  /// In bn, this message translates to:
  /// **'নতুন করে দেখুন'**
  String get customersRefresh;

  /// Due list row: second line when the customer has no code
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া'**
  String get customersDueWord;

  /// Due list row: second line with the customer's code
  ///
  /// In bn, this message translates to:
  /// **'{code} · বকেয়া'**
  String customersDueWithCode(String code);

  /// Deposit notification: loading failed
  ///
  /// In bn, this message translates to:
  /// **'তথ্য আনা যায়নি।'**
  String get customersDepositLoadFailed;

  /// Deposit notification: the server is too old
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে এই সুবিধা এখনো আসেনি — অফিসে জানান।'**
  String get customersDepositAbsent;

  /// Deposit notification: error, no amount
  ///
  /// In bn, this message translates to:
  /// **'টাকার অঙ্ক লিখুন।'**
  String get customersDepositNeedAmount;

  /// Deposit notification: error, bank deposit without a slip photo
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংকে জমায় স্লিপের ছবি দিতেই হবে।'**
  String get customersDepositNeedSlip;

  /// Deposit notification: error, a bill share is not a number
  ///
  /// In bn, this message translates to:
  /// **'বিল {no}-এর ভাগ পড়া গেল না — কেবল সংখ্যা লিখুন।'**
  String customersDepositShareUnreadable(String no);

  /// Deposit notification: error, a bill share is more than the bill's due
  ///
  /// In bn, this message translates to:
  /// **'বিল {no}-এর বকেয়া {amount} — তার বেশি এই বিলে দেখানো যায় না।'**
  String customersDepositShareOverDue(String no, String amount);

  /// Deposit notification: error, the shares add up to more than the deposit
  ///
  /// In bn, this message translates to:
  /// **'বিলগুলোতে মোট {shared}, অথচ জমা {amount} — বিলের ভাগ জমার চেয়ে বেশি হতে পারে না।'**
  String customersDepositSharesOverAmount(String shared, String amount);

  /// Deposit notification: sent
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো হয়েছে — হিসাবরক্ষক মিলিয়ে দেখবেন। ততক্ষণ বকেয়া কমবে না।'**
  String get customersDepositSent;

  /// Deposit notification: sending failed
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো যায়নি। আবার চেষ্টা করুন।'**
  String get customersDepositSendFailed;

  /// Deposit notification: line under the customer's name
  ///
  /// In bn, this message translates to:
  /// **'হিসাবরক্ষক স্লিপ মিলিয়ে গ্রহণ করলে তবেই বকেয়া কমবে।'**
  String get customersDepositIntro;

  /// Deposit notification: amount field label
  ///
  /// In bn, this message translates to:
  /// **'টাকা'**
  String get customersDepositAmount;

  /// Deposit notification: date row
  ///
  /// In bn, this message translates to:
  /// **'জমার তারিখ'**
  String get customersDepositDate;

  /// Deposit notification: method, bank
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক'**
  String get customersDepositBank;

  /// Deposit notification: method, mobile wallet (bKash or Nagad)
  ///
  /// In bn, this message translates to:
  /// **'বিকাশ/নগদ'**
  String get customersDepositMfs;

  /// Deposit notification: method, cash
  ///
  /// In bn, this message translates to:
  /// **'নগদ'**
  String get customersDepositCash;

  /// Deposit notification: bank account dropdown
  ///
  /// In bn, this message translates to:
  /// **'কোন ব্যাংকে'**
  String get customersDepositWhichBank;

  /// Deposit notification: reference field label
  ///
  /// In bn, this message translates to:
  /// **'স্লিপ / লেনদেন নম্বর'**
  String get customersDepositReference;

  /// Deposit notification: heading of the bill shares
  ///
  /// In bn, this message translates to:
  /// **'কোন বিলের বিপরীতে (ঐচ্ছিক)'**
  String get customersDepositAgainstBills;

  /// Deposit notification: explanation of bill shares
  ///
  /// In bn, this message translates to:
  /// **'বিল বাছলে গ্রহণের সময় টাকা সেই বিলগুলোতে মিলবে; না বাছলে মোট টাকা দোকানের খাতায় বসবে।'**
  String get customersDepositBillsHint;

  /// Deposit notification: one bill and its due
  ///
  /// In bn, this message translates to:
  /// **'{no} · বকেয়া {amount}'**
  String customersDepositBillDue(String no, String amount);

  /// Deposit notification: hint of a bill share field
  ///
  /// In bn, this message translates to:
  /// **'এই বিলে'**
  String get customersDepositOnThisBill;

  /// Deposit notification: note field label
  ///
  /// In bn, this message translates to:
  /// **'আর কিছু বলার'**
  String get customersDepositNote;

  /// Deposit notification: camera button
  ///
  /// In bn, this message translates to:
  /// **'স্লিপের ছবি তুলুন'**
  String get customersDepositTakeSlip;

  /// Deposit notification: camera button after a photo
  ///
  /// In bn, this message translates to:
  /// **'ছবি নেওয়া হয়েছে — আবার তুলুন'**
  String get customersDepositSlipTaken;

  /// Deposit notification: send button
  ///
  /// In bn, this message translates to:
  /// **'পাঠান'**
  String get customersDepositSend;

  /// Deposit notification: heading of earlier notifications
  ///
  /// In bn, this message translates to:
  /// **'আগের বিজ্ঞপ্তি ({count})'**
  String customersDepositHistory(int count);

  /// Deposit history row: date
  ///
  /// In bn, this message translates to:
  /// **'তারিখ {date}'**
  String customersDepositHistoryDate(String date);

  /// Deposit history row: who sent it
  ///
  /// In bn, this message translates to:
  /// **'পাঠালেন {name}'**
  String customersDepositHistoryBy(String name);

  /// Deposit history row: slip or transaction number
  ///
  /// In bn, this message translates to:
  /// **'নম্বর {reference}'**
  String customersDepositHistoryRef(String reference);

  /// Deposit history row: share on one bill
  ///
  /// In bn, this message translates to:
  /// **'বিল {no} · {amount}'**
  String customersDepositHistoryBill(String no, String amount);

  /// Deposit history row: why it was refused
  ///
  /// In bn, this message translates to:
  /// **'কারণ: {reason}'**
  String customersDepositHistoryReason(String reason);

  /// Deposit history row: refused without a reason
  ///
  /// In bn, this message translates to:
  /// **'জানানো হয়নি'**
  String get customersDepositReasonNotGiven;
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
