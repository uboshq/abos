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

  /// Finance lists: loading failed, pull down to retry
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get financeLoadFailed;

  /// Finance lists: the server does not have this finance part yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে অর্থের এই অংশ এখনো আসেনি — অফিসে জানান।'**
  String get financeNotOnServer;

  /// Finance: a date that falls today
  ///
  /// In bn, this message translates to:
  /// **'আজ'**
  String get financeDaysToday;

  /// Finance: days remaining until a date
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{আর {days} দিন}}'**
  String financeDaysLeft(int days);

  /// Finance: days since a date passed
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{{days} দিন পেরিয়েছে}}'**
  String financeDaysOverdue(int days);

  /// Finance: status / tab - active, running
  ///
  /// In bn, this message translates to:
  /// **'চালু'**
  String get financeStatusActive;

  /// Finance deposit status: waiting for signature
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষায়'**
  String get financeStatusAwaitingSign;

  /// Finance: status / tab - ended, finished
  ///
  /// In bn, this message translates to:
  /// **'শেষ'**
  String get financeStatusEnded;

  /// Finance: status - cancelled
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get financeStatusCancelled;

  /// Finance: status / tab - closed
  ///
  /// In bn, this message translates to:
  /// **'বন্ধ'**
  String get financeStatusClosed;

  /// Finance loan status: draft
  ///
  /// In bn, this message translates to:
  /// **'খসড়া'**
  String get financeStatusDraft;

  /// Deposit movement: money placed in the deposit
  ///
  /// In bn, this message translates to:
  /// **'টাকা রাখা'**
  String get financeDepositMoveOpened;

  /// Deposit movement: profit withdrawn
  ///
  /// In bn, this message translates to:
  /// **'মুনাফা তোলা'**
  String get financeDepositMovePayout;

  /// Deposit movement: encashed early or matured
  ///
  /// In bn, this message translates to:
  /// **'ভাঙানো / মেয়াদপূর্তি'**
  String get financeDepositMoveClosed;

  /// Bank loan type: cash credit
  ///
  /// In bn, this message translates to:
  /// **'ক্যাশ ক্রেডিট (CC)'**
  String get financeLoanKindCc;

  /// Bank loan type: term loan
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদি ঋণ'**
  String get financeLoanKindTerm;

  /// Bank loan type: lease
  ///
  /// In bn, this message translates to:
  /// **'লিজ'**
  String get financeLoanKindLease;

  /// Bank loan type: bank guarantee
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক গ্যারান্টি'**
  String get financeLoanKindGuarantee;

  /// Loan instalment state: paid
  ///
  /// In bn, this message translates to:
  /// **'দেওয়া'**
  String get financeInstalmentPaid;

  /// Loan instalment state: overdue
  ///
  /// In bn, this message translates to:
  /// **'দিন পেরিয়েছে'**
  String get financeInstalmentOverdue;

  /// Loan instalment state: due today
  ///
  /// In bn, this message translates to:
  /// **'আজ দিতে হবে'**
  String get financeInstalmentToday;

  /// Loan instalment state: upcoming
  ///
  /// In bn, this message translates to:
  /// **'সামনে'**
  String get financeInstalmentUpcoming;

  /// Loan instalment state: no due date
  ///
  /// In bn, this message translates to:
  /// **'তারিখ নেই'**
  String get financeInstalmentNoDate;

  /// Hand loan repayment: all at once (lump sum)
  ///
  /// In bn, this message translates to:
  /// **'একবারে ফেরত'**
  String get financeRepaymentLump;

  /// Hand loan repayment: monthly
  ///
  /// In bn, this message translates to:
  /// **'মাসে মাসে'**
  String get financeRepaymentMonthly;

  /// Hand loan repayment: whenever the borrower can
  ///
  /// In bn, this message translates to:
  /// **'যখন পারেন'**
  String get financeRepaymentWhenever;

  /// Deposit list screen title
  ///
  /// In bn, this message translates to:
  /// **'জমা ও সঞ্চয়'**
  String get financeDepositListTitle;

  /// Deposit list: no deposits in this tab
  ///
  /// In bn, this message translates to:
  /// **'এই ট্যাবে কোনো জমা নেই'**
  String get financeDepositListEmpty;

  /// Deposit list tab: maturing soon
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ আসছে'**
  String get financeDepositTabMaturing;

  /// Deposit list row: maturity date
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ {date}'**
  String financeDepositMatures(String date);

  /// Deposit page title
  ///
  /// In bn, this message translates to:
  /// **'জমা'**
  String get financeDepositTitle;

  /// Deposit page: loading failed
  ///
  /// In bn, this message translates to:
  /// **'জমাটা আনা গেল না।'**
  String get financeDepositLoadFailed;

  /// Deposit page: principal amount label
  ///
  /// In bn, this message translates to:
  /// **'জমার অঙ্ক'**
  String get financeDepositAmount;

  /// Finance page fact label: type
  ///
  /// In bn, this message translates to:
  /// **'ধরন'**
  String get financeFactType;

  /// Finance page fact label: status
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get financeFactStatus;

  /// Deposit page: bank or institution label
  ///
  /// In bn, this message translates to:
  /// **'প্রতিষ্ঠান'**
  String get financeDepositInstitution;

  /// Deposit page: the bank's reference number label
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংকের নম্বর'**
  String get financeDepositBankRef;

  /// Deposit page: in whose name the deposit is held
  ///
  /// In bn, this message translates to:
  /// **'কার নামে'**
  String get financeDepositHeldBy;

  /// Deposit page: held in the owner's name
  ///
  /// In bn, this message translates to:
  /// **'মালিকের'**
  String get financeDepositHeldOwner;

  /// Deposit page: held in the owner's name, with the person
  ///
  /// In bn, this message translates to:
  /// **'মালিকের — {person}'**
  String financeDepositHeldOwnerPerson(String person);

  /// Deposit page: held in the business's name
  ///
  /// In bn, this message translates to:
  /// **'ব্যবসার'**
  String get financeDepositHeldBusiness;

  /// Deposit page: profit rate label
  ///
  /// In bn, this message translates to:
  /// **'মুনাফার হার'**
  String get financeDepositProfitRate;

  /// Deposit page: date opened label
  ///
  /// In bn, this message translates to:
  /// **'খোলা'**
  String get financeDepositOpened;

  /// Deposit page: maturity date label
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ'**
  String get financeDepositMaturity;

  /// Deposit page: monthly instalment label
  ///
  /// In bn, this message translates to:
  /// **'মাসের কিস্তি'**
  String get financeDepositMonthlyInstalment;

  /// Finance: a day of each month (instalment day, rent day)
  ///
  /// In bn, this message translates to:
  /// **'প্রতি মাসের {day} তারিখ'**
  String financeDayOfMonth(String day);

  /// Deposit page: date the deposit was closed
  ///
  /// In bn, this message translates to:
  /// **'শেষ হয়েছে'**
  String get financeDepositClosedOn;

  /// Deposit page: pledged label
  ///
  /// In bn, this message translates to:
  /// **'বাঁধা'**
  String get financeDepositPledged;

  /// Deposit page: pledged against a loan, cannot be encashed
  ///
  /// In bn, this message translates to:
  /// **'ঋণের বিপরীতে বাঁধা — ভাঙানো যাবে না'**
  String get financeDepositPledgedNote;

  /// Finance page: free-text note label
  ///
  /// In bn, this message translates to:
  /// **'নোট'**
  String get financeNote;

  /// Deposit page: section title for movements
  ///
  /// In bn, this message translates to:
  /// **'চলাচল'**
  String get financeDepositMovements;

  /// Bank loan list screen title
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক ঋণ ও কিস্তি'**
  String get financeLoanListTitle;

  /// Bank loan list: no loans in this tab
  ///
  /// In bn, this message translates to:
  /// **'এই ট্যাবে কোনো ঋণ নেই'**
  String get financeLoanListEmpty;

  /// Bank loan list row: sanctioned limit
  ///
  /// In bn, this message translates to:
  /// **'সীমা {amount}'**
  String financeLoanLimitLine(String amount);

  /// Bank loan list row: next instalment date, amount and state
  ///
  /// In bn, this message translates to:
  /// **'পরের কিস্তি {date} · {amount} · {state}'**
  String financeLoanNextLine(String date, String amount, String state);

  /// Bank loan page title
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক ঋণ'**
  String get financeLoanTitle;

  /// Bank loan page: loading failed
  ///
  /// In bn, this message translates to:
  /// **'ঋণটা আনা গেল না।'**
  String get financeLoanLoadFailed;

  /// Bank loan page: outstanding balance now
  ///
  /// In bn, this message translates to:
  /// **'এখনকার দেনা'**
  String get financeLoanOutstanding;

  /// Bank loan page: bank label
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক'**
  String get financeLoanBank;

  /// Bank loan page: sanctioned limit label
  ///
  /// In bn, this message translates to:
  /// **'সীমা'**
  String get financeLoanLimit;

  /// Bank loan page: limit still available
  ///
  /// In bn, this message translates to:
  /// **'সীমার বাকি'**
  String get financeLoanAvailable;

  /// Finance page: interest rate label
  ///
  /// In bn, this message translates to:
  /// **'সুদের হার'**
  String get financeInterestRate;

  /// Bank loan page: sanction letter number
  ///
  /// In bn, this message translates to:
  /// **'মঞ্জুরির নম্বর'**
  String get financeLoanSanctionNo;

  /// Bank loan page: sanction date label
  ///
  /// In bn, this message translates to:
  /// **'মঞ্জুরি'**
  String get financeLoanSanctioned;

  /// Bank loan page: renewal date label
  ///
  /// In bn, this message translates to:
  /// **'নবায়ন'**
  String get financeLoanRenewal;

  /// Finance: instalment (deposit movement, loan instalments label)
  ///
  /// In bn, this message translates to:
  /// **'কিস্তি'**
  String get financeInstalment;

  /// Bank loan page: instalments paid, left and total
  ///
  /// In bn, this message translates to:
  /// **'{paid} দেওয়া · {left} বাকি · মোট {total}'**
  String financeLoanInstalmentCounts(String paid, String left, String total);

  /// Bank loan page: instalment amount label
  ///
  /// In bn, this message translates to:
  /// **'কিস্তির অঙ্ক'**
  String get financeLoanInstalmentAmount;

  /// Bank loan page: overdue instalments label
  ///
  /// In bn, this message translates to:
  /// **'দিন পেরোনো কিস্তি'**
  String get financeLoanOverdueInstalments;

  /// Bank loan page: number of overdue instalments
  ///
  /// In bn, this message translates to:
  /// **'{count}টি'**
  String financeLoanOverdueCount(String count);

  /// Bank loan page: next instalment card title
  ///
  /// In bn, this message translates to:
  /// **'পরের কিস্তি'**
  String get financeLoanNextInstalment;

  /// Bank loan page: section title for upcoming instalments
  ///
  /// In bn, this message translates to:
  /// **'সামনের কিস্তি'**
  String get financeLoanSchedule;

  /// Bank loan schedule row: instalment number and due date
  ///
  /// In bn, this message translates to:
  /// **'{no} নম্বর কিস্তি · {date}'**
  String financeLoanScheduleTitle(String no, String date);

  /// Bank loan schedule row: principal and interest parts
  ///
  /// In bn, this message translates to:
  /// **'আসল {principal} · সুদ {interest}'**
  String financeLoanScheduleSplit(String principal, String interest);

  /// Rent list and rent page title
  ///
  /// In bn, this message translates to:
  /// **'ভাড়া'**
  String get financeRentTitle;

  /// Rent list: no rent agreements here
  ///
  /// In bn, this message translates to:
  /// **'এখানে কোনো ভাড়ার চুক্তি নেই'**
  String get financeRentListEmpty;

  /// Rent list tab: premises we rent (we pay rent)
  ///
  /// In bn, this message translates to:
  /// **'আমরা ভাড়া দিই'**
  String get financeRentSidePay;

  /// Rent list tab: premises we let out (we receive rent)
  ///
  /// In bn, this message translates to:
  /// **'আমরা ভাড়া পাই'**
  String get financeRentSideLet;

  /// Rent list row: next rent date
  ///
  /// In bn, this message translates to:
  /// **'পরের ভাড়া {date}'**
  String financeRentNextLine(String date);

  /// Rent: the tenant owes us this amount
  ///
  /// In bn, this message translates to:
  /// **'ভাড়াটের কাছে পাওনা {amount}'**
  String financeRentTenantOwes(String amount);

  /// Rent: months of rent we have not paid, and the amount
  ///
  /// In bn, this message translates to:
  /// **'{months, plural, other{{months} মাসের ভাড়া বাকি · {amount}}}'**
  String financeRentMonthsDue(int months, String amount);

  /// Rent page: loading failed
  ///
  /// In bn, this message translates to:
  /// **'ভাড়ার চুক্তিটা আনা গেল না।'**
  String get financeRentLoadFailed;

  /// Rent page: monthly rent we receive
  ///
  /// In bn, this message translates to:
  /// **'মাসের ভাড়া (আমরা পাই)'**
  String get financeRentMonthlyWeLet;

  /// Rent page: monthly rent we pay
  ///
  /// In bn, this message translates to:
  /// **'মাসের ভাড়া (আমরা দিই)'**
  String get financeRentMonthlyWePay;

  /// Rent page: tenant label
  ///
  /// In bn, this message translates to:
  /// **'ভাড়াটে'**
  String get financeRentTenant;

  /// Rent page: landlord label
  ///
  /// In bn, this message translates to:
  /// **'বাড়িওয়ালা'**
  String get financeRentLandlord;

  /// Rent page: phone label
  ///
  /// In bn, this message translates to:
  /// **'ফোন'**
  String get financeRentPhone;

  /// Rent page: premises / place label
  ///
  /// In bn, this message translates to:
  /// **'জায়গা'**
  String get financeRentPlace;

  /// Rent page: agreement number label
  ///
  /// In bn, this message translates to:
  /// **'চুক্তির নম্বর'**
  String get financeRentAgreementNo;

  /// Rent page: rent day of the month label
  ///
  /// In bn, this message translates to:
  /// **'ভাড়ার দিন'**
  String get financeRentDay;

  /// Rent page: next rent date label
  ///
  /// In bn, this message translates to:
  /// **'পরের ভাড়া'**
  String get financeRentNext;

  /// Rent page: agreement start date label
  ///
  /// In bn, this message translates to:
  /// **'শুরু'**
  String get financeRentStarts;

  /// Rent page: agreement end date label
  ///
  /// In bn, this message translates to:
  /// **'শেষ'**
  String get financeRentEnds;

  /// Rent page: security deposit label
  ///
  /// In bn, this message translates to:
  /// **'জামানত'**
  String get financeRentSecurity;

  /// Rent page: security deposit we still hold
  ///
  /// In bn, this message translates to:
  /// **'হাতে থাকা জামানত'**
  String get financeRentSecurityHeld;

  /// Rent page: security deposit remaining
  ///
  /// In bn, this message translates to:
  /// **'জামানতের বাকি'**
  String get financeRentSecurityLeft;

  /// Rent page: the oldest unpaid month
  ///
  /// In bn, this message translates to:
  /// **'সবচেয়ে পুরনো বাকি মাস'**
  String get financeRentOldestUnpaid;

  /// Rent page: section title for unpaid months
  ///
  /// In bn, this message translates to:
  /// **'বাকি মাস'**
  String get financeRentUnpaidMonths;

  /// Hand loan (informal loan) list screen title
  ///
  /// In bn, this message translates to:
  /// **'হাতধার'**
  String get financeHandLoanListTitle;

  /// Hand loan list: no open hand loans
  ///
  /// In bn, this message translates to:
  /// **'কোনো খোলা হাতধার নেই'**
  String get financeHandLoanListEmpty;

  /// Hand loan list total: others owe us
  ///
  /// In bn, this message translates to:
  /// **'আমরা পাব'**
  String get financeHandLoanToUs;

  /// Hand loan list total: we owe others
  ///
  /// In bn, this message translates to:
  /// **'আমরা দেব'**
  String get financeHandLoanWeOwe;

  /// Hand loan list row: repayment date
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের দিন {date}'**
  String financeHandLoanDueLine(String date);

  /// Hand loan side: the person owes us
  ///
  /// In bn, this message translates to:
  /// **'তিনি আমাদের দেবেন'**
  String get financeHandLoanTheyOwe;

  /// Hand loan side: we owe the person
  ///
  /// In bn, this message translates to:
  /// **'আমরা তাঁকে দেব'**
  String get financeHandLoanWeOweThem;

  /// Hand loan side: nothing owed either way
  ///
  /// In bn, this message translates to:
  /// **'মিটে গেছে'**
  String get financeHandLoanSettled;

  /// Hand loan page title (one loan)
  ///
  /// In bn, this message translates to:
  /// **'হাতধার'**
  String get financeHandLoanTitle;

  /// Hand loan page: loading failed
  ///
  /// In bn, this message translates to:
  /// **'হাতধারটা আনা গেল না।'**
  String get financeHandLoanLoadFailed;

  /// Hand loan page: mobile number label
  ///
  /// In bn, this message translates to:
  /// **'মোবাইল'**
  String get financeHandLoanMobile;

  /// Hand loan page: principal label
  ///
  /// In bn, this message translates to:
  /// **'আসল'**
  String get financeHandLoanPrincipal;

  /// Hand loan page: how it is repaid
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের ধরন'**
  String get financeHandLoanRepaymentType;

  /// Hand loan page: repayment date label
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের দিন'**
  String get financeHandLoanDueDate;

  /// Hand loan page status: settled
  ///
  /// In bn, this message translates to:
  /// **'চুকে গেছে'**
  String get financeHandLoanStatusSettled;

  /// Hand loan page: button to record money given or paid back
  ///
  /// In bn, this message translates to:
  /// **'টাকা দেওয়া / ফেরত'**
  String get financeHandLoanMoveButton;

  /// Hand loan page: section title for transactions
  ///
  /// In bn, this message translates to:
  /// **'লেনদেন'**
  String get financeHandLoanTransactions;

  /// Hand loan transaction: money given
  ///
  /// In bn, this message translates to:
  /// **'টাকা দেওয়া'**
  String get financeHandLoanGiven;

  /// Hand loan transaction: money received
  ///
  /// In bn, this message translates to:
  /// **'টাকা পাওয়া'**
  String get financeHandLoanReceived;

  /// Hand loan transaction: promised return date
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের কথা {date}'**
  String financeHandLoanReturnLine(String date);

  /// Chart of accounts: loading failed, pull down to retry
  ///
  /// In bn, this message translates to:
  /// **'হিসাবের ছক আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get accountsChartLoadFailed;

  /// Chart of accounts: the server lacks the phone chart yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের হিসাবের ছক এখনো আসেনি — অফিসে জানান।'**
  String get accountsChartNotOnServer;

  /// Chart of accounts screen title
  ///
  /// In bn, this message translates to:
  /// **'হিসাবের ছক'**
  String get accountsChartTitle;

  /// Chart of accounts: search box hint
  ///
  /// In bn, this message translates to:
  /// **'কোড বা নাম দিয়ে খুঁজুন'**
  String get accountsChartSearchHint;

  /// Chart of accounts: number of accounts shown
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{মোট {count} টি হিসাব}}'**
  String accountsChartCount(int count);

  /// Chart of accounts / ledger: error card title
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get accountsChartFetchFailed;

  /// Chart of accounts: search found nothing
  ///
  /// In bn, this message translates to:
  /// **'কোনো হিসাব মেলেনি'**
  String get accountsChartNoMatch;

  /// Account ledger: loading failed, pull down to retry
  ///
  /// In bn, this message translates to:
  /// **'খাতা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get accountsChartLedgerLoadFailed;

  /// Account ledger: this is a group account, open a child account instead
  ///
  /// In bn, this message translates to:
  /// **'এটা দলের হিসাব — এন্ট্রি এর নিচের হিসাবগুলোতে। ছকে ফিরে নিচের হিসাব খুলুন।'**
  String get accountsChartGroupAccount;

  /// Account ledger: no entries
  ///
  /// In bn, this message translates to:
  /// **'এই হিসাবে কোনো এন্ট্রি নেই'**
  String get accountsChartNoEntries;

  /// Account ledger: load more entries button
  ///
  /// In bn, this message translates to:
  /// **'আরও এন্ট্রি'**
  String get accountsChartMoreEntries;

  /// Account ledger totals card heading with the entry count
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{সর্বমোট · {count} টি এন্ট্রি}}'**
  String accountsChartLedgerTotal(int count);

  /// Account ledger: debit total label
  ///
  /// In bn, this message translates to:
  /// **'ডেবিট'**
  String get accountsChartDebit;

  /// Account ledger: credit total label
  ///
  /// In bn, this message translates to:
  /// **'ক্রেডিট'**
  String get accountsChartCredit;

  /// Account ledger: closing balance label
  ///
  /// In bn, this message translates to:
  /// **'শেষ জের'**
  String get accountsChartClosing;

  /// Account ledger entry: debit amount
  ///
  /// In bn, this message translates to:
  /// **'ডেবিট {amount}'**
  String accountsChartDebitLine(String amount);

  /// Account ledger entry: credit amount
  ///
  /// In bn, this message translates to:
  /// **'ক্রেডিট {amount}'**
  String accountsChartCreditLine(String amount);

  /// Voucher list: the word 'voucher' after a voucher type name, removed to make short filter chips; keep the leading space
  ///
  /// In bn, this message translates to:
  /// **' ভাউচার'**
  String get vouchersKindSuffix;

  /// Voucher list: loading failed, pull down to retry
  ///
  /// In bn, this message translates to:
  /// **'ভাউচারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get vouchersListLoadFailed;

  /// Voucher list: the server lacks phone vouchers yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের ভাউচার এখনো আসেনি — অফিসে জানান।'**
  String get vouchersListNotOnServer;

  /// Voucher list screen title; also the voucher page title before it loads
  ///
  /// In bn, this message translates to:
  /// **'ভাউচার'**
  String get vouchersListTitle;

  /// Voucher list title when opened as 'all vouchers' from the menu
  ///
  /// In bn, this message translates to:
  /// **'সব ভাউচার'**
  String get vouchersListAllTitle;

  /// Voucher list: new voucher button
  ///
  /// In bn, this message translates to:
  /// **'নতুন ভাউচার'**
  String get vouchersListNew;

  /// Voucher list filter: waiting for signature
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষায়'**
  String get vouchersListAwaitingSign;

  /// Voucher list filter: all branches
  ///
  /// In bn, this message translates to:
  /// **'সব শাখা'**
  String get vouchersListAllBranches;

  /// Voucher list total strip: total for the chosen voucher type
  ///
  /// In bn, this message translates to:
  /// **'মোট · {kind}'**
  String vouchersListTotal(String kind);

  /// Voucher list: no vouchers
  ///
  /// In bn, this message translates to:
  /// **'কোনো ভাউচার নেই।'**
  String get vouchersListEmpty;

  /// Voucher list row: who entered the voucher
  ///
  /// In bn, this message translates to:
  /// **'তুলেছেন: {name}'**
  String vouchersListWrittenBy(String name);

  /// Voucher page: loading failed
  ///
  /// In bn, this message translates to:
  /// **'ভাউচারটা আনা গেল না।'**
  String get vouchersPageLoadFailed;

  /// Voucher page: posting failed, try again
  ///
  /// In bn, this message translates to:
  /// **'পাকা করা গেল না। আবার চেষ্টা করুন।'**
  String get vouchersPagePostFailed;

  /// Voucher page: print or share button tooltip
  ///
  /// In bn, this message translates to:
  /// **'ছাপা বা শেয়ার'**
  String get vouchersPagePrint;

  /// Voucher page: voucher type label
  ///
  /// In bn, this message translates to:
  /// **'ধরন'**
  String get vouchersPageType;

  /// Voucher page and form: amount label
  ///
  /// In bn, this message translates to:
  /// **'টাকা'**
  String get vouchersAmount;

  /// Voucher page and form: date label
  ///
  /// In bn, this message translates to:
  /// **'তারিখ'**
  String get vouchersDate;

  /// Voucher page: mobile banking / transfer transaction number label
  ///
  /// In bn, this message translates to:
  /// **'লেনদেন নম্বর'**
  String get vouchersTrxNo;

  /// Voucher page and form: narration label
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ'**
  String get vouchersNarration;

  /// Voucher page: who wrote the voucher
  ///
  /// In bn, this message translates to:
  /// **'লেখক'**
  String get vouchersPageWriter;

  /// Voucher line: debit amount
  ///
  /// In bn, this message translates to:
  /// **'ডেবিট {amount}'**
  String vouchersDebitLine(String amount);

  /// Voucher line: credit amount
  ///
  /// In bn, this message translates to:
  /// **'ক্রেডিট {amount}'**
  String vouchersCreditLine(String amount);

  /// Voucher page: the writer cannot post their own voucher; someone else must press Post
  ///
  /// In bn, this message translates to:
  /// **'নিজের লেখা ভাউচার নিজে পাকা হয় না — অন্য কেউ খুলে \"পাকা করুন\" চাপবেন।'**
  String get vouchersPageAnotherHand;

  /// Voucher page: transaction ID field label
  ///
  /// In bn, this message translates to:
  /// **'লেনদেন নম্বর (TrxID)'**
  String get vouchersPageTrxLabel;

  /// Voucher page: post button
  ///
  /// In bn, this message translates to:
  /// **'পাকা করুন'**
  String get vouchersPagePost;

  /// Voucher form: account list failed to load; check internet and open again
  ///
  /// In bn, this message translates to:
  /// **'খাতের তালিকা আনা গেল না। নেট দেখে আবার খুলুন।'**
  String get vouchersFormSetupFailed;

  /// Voucher form: account list not loaded yet
  ///
  /// In bn, this message translates to:
  /// **'খাতের তালিকা এখনো আসেনি।'**
  String get vouchersFormSetupMissing;

  /// Voucher form: narration is required in this company
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ লিখুন — এই কোম্পানিতে বিবরণ বাধ্যতামূলক।'**
  String get vouchersFormNarrationRequired;

  /// Journal form: choose accounts on at least two lines
  ///
  /// In bn, this message translates to:
  /// **'অন্তত দুটো সারিতে খাত বাছুন।'**
  String get vouchersFormTwoLines;

  /// Journal form: amount not valid, up to two decimals
  ///
  /// In bn, this message translates to:
  /// **'অঙ্কটা ঠিক নয় — দুই দশমিক পর্যন্ত।'**
  String get vouchersFormAmountShape;

  /// Journal form: one line cannot have both debit and credit
  ///
  /// In bn, this message translates to:
  /// **'এক সারিতে ডেবিট আর ক্রেডিট দুটোই নয়।'**
  String get vouchersFormBothSides;

  /// Journal form: debit and credit totals differ
  ///
  /// In bn, this message translates to:
  /// **'ডেবিট আর ক্রেডিট মেলেনি।'**
  String get vouchersFormUnbalanced;

  /// Voucher form: choose both accounts
  ///
  /// In bn, this message translates to:
  /// **'দুই দিকের খাত বাছুন।'**
  String get vouchersFormPickAccounts;

  /// Voucher form: both sides cannot be the same account
  ///
  /// In bn, this message translates to:
  /// **'দুই দিকে একই খাত হয় না।'**
  String get vouchersFormSameAccount;

  /// Voucher form: amount must be above zero, up to two decimals
  ///
  /// In bn, this message translates to:
  /// **'অঙ্কটা ঠিক নয় — শূন্যের বেশি, দুই দশমিক পর্যন্ত।'**
  String get vouchersFormAmountInvalid;

  /// Voucher form: the server did not save the voucher
  ///
  /// In bn, this message translates to:
  /// **'ভাউচারটা বসেনি। আবার চেষ্টা করুন।'**
  String get vouchersFormNotSaved;

  /// Voucher form: no reply, it may have reached the server; tap again unchanged, it will not be saved twice
  ///
  /// In bn, this message translates to:
  /// **'ভাউচারের উত্তর আসেনি — সার্ভারে হয়তো পৌঁছেছে — কিছু না বদলে আবার চাপুন; আগেরটা বসে থাকলে সেটাই দেখাবে, দুবার বসবে না।'**
  String get vouchersFormMayHaveLanded;

  /// Voucher form: needs internet; nothing was kept on the phone
  ///
  /// In bn, this message translates to:
  /// **'ভাউচার লিখতে নেট লাগবে। নেট চালু করে আবার চাপুন — ফোনে কিছু জমা থাকেনি।'**
  String get vouchersFormNeedsNet;

  /// Voucher form: sending failed
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো গেল না। আবার চেষ্টা করুন।'**
  String get vouchersFormSendFailed;

  /// Voucher form: party picker, optional but needed for an expense on credit
  ///
  /// In bn, this message translates to:
  /// **'পক্ষ (ঐচ্ছিক — বাকিতে খরচে লাগবে)'**
  String get vouchersFormPartyOptional;

  /// Voucher form: clear the chosen party (tooltip)
  ///
  /// In bn, this message translates to:
  /// **'মুছুন'**
  String get vouchersFormClear;

  /// Voucher form: party picker sheet title
  ///
  /// In bn, this message translates to:
  /// **'পক্ষ'**
  String get vouchersFormParty;

  /// Voucher form: account the money comes from
  ///
  /// In bn, this message translates to:
  /// **'কোথা থেকে'**
  String get vouchersFormFrom;

  /// Voucher form: account the money goes to
  ///
  /// In bn, this message translates to:
  /// **'কোথায়'**
  String get vouchersFormTo;

  /// Voucher form: transaction ID field, needed before posting
  ///
  /// In bn, this message translates to:
  /// **'লেনদেন নম্বর (TrxID) — পাকা করতে লাগবে'**
  String get vouchersFormTrxLabel;

  /// Journal form line: account picker
  ///
  /// In bn, this message translates to:
  /// **'খাত'**
  String get vouchersFormAccount;

  /// Journal form line: debit field
  ///
  /// In bn, this message translates to:
  /// **'ডেবিট'**
  String get vouchersFormDebit;

  /// Journal form line: credit field
  ///
  /// In bn, this message translates to:
  /// **'ক্রেডিট'**
  String get vouchersFormCredit;

  /// Journal form: add a line button
  ///
  /// In bn, this message translates to:
  /// **'সারি যোগ'**
  String get vouchersFormAddLine;

  /// Journal form: debit and credit totals
  ///
  /// In bn, this message translates to:
  /// **'ডেবিট {debit} · ক্রেডিট {credit}'**
  String vouchersFormTotals(String debit, String credit);

  /// Voucher form: narration field label when required
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ (বাধ্যতামূলক)'**
  String get vouchersFormNarrationMust;

  /// Voucher form: keep as draft switch
  ///
  /// In bn, this message translates to:
  /// **'খসড়া রাখুন'**
  String get vouchersFormKeepDraft;

  /// Voucher form: when off, saving posts it; it stays a draft if signatures are needed or you wrote it
  ///
  /// In bn, this message translates to:
  /// **'বন্ধ থাকলে সংরক্ষণেই পাকা — সই লাগলে বা নিজের লেখা হলে খসড়াই থাকবে'**
  String get vouchersFormKeepDraftHint;

  /// Voucher form: save button
  ///
  /// In bn, this message translates to:
  /// **'সংরক্ষণ'**
  String get vouchersFormSave;

  /// Voucher form picker: nothing matches the search
  ///
  /// In bn, this message translates to:
  /// **'কিছু মেলেনি।'**
  String get vouchersPickNothing;

  /// Money screens: snack bar when the server saved the entry and sent no message of its own
  ///
  /// In bn, this message translates to:
  /// **'বসেছে।'**
  String get moneyResultDone;

  /// Money screens: snack bar when the entry waits for a signature and the server sent no message
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষায়।'**
  String get moneyResultPending;

  /// Money screens: error when a page could not be loaded; title is the page title
  ///
  /// In bn, this message translates to:
  /// **'{title} আনা গেল না।'**
  String moneyLoadFailed(String title);

  /// Money screens: error when the server does not have this page yet (app newer than server)
  ///
  /// In bn, this message translates to:
  /// **'এই পাতা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get moneyPageMissing;

  /// Custody screen title: which person/account holds how much money
  ///
  /// In bn, this message translates to:
  /// **'টাকা কার হাতে'**
  String get moneyCustodyTitle;

  /// Custody screen: total strip label, money handed over but not yet received
  ///
  /// In bn, this message translates to:
  /// **'পথের টাকা'**
  String get moneyCustodyOnRoad;

  /// Custody screen: empty state, no cash/bank accounts
  ///
  /// In bn, this message translates to:
  /// **'কোনো টাকার খাত নেই'**
  String get moneyCustodyEmpty;

  /// Custody screen: section title for handovers still in transit
  ///
  /// In bn, this message translates to:
  /// **'পথে আছে'**
  String get moneyCustodyInTransit;

  /// Money handover list: screen title (handing cash from one till/person to another)
  ///
  /// In bn, this message translates to:
  /// **'টাকা হস্তান্তর'**
  String get moneyTransferListTitle;

  /// Money handover: button that sends a new handover
  ///
  /// In bn, this message translates to:
  /// **'পাঠান'**
  String get moneyTransferSend;

  /// Money handover list: total strip label, total amount handed over
  ///
  /// In bn, this message translates to:
  /// **'মোট হাতবদল'**
  String get moneyTransferTotal;

  /// Money handover list: section of handovers waiting for me to confirm receipt
  ///
  /// In bn, this message translates to:
  /// **'আমার নেওয়ার অপেক্ষায়'**
  String get moneyTransferAwaitingMe;

  /// Money handover list: empty state title
  ///
  /// In bn, this message translates to:
  /// **'কোনো হস্তান্তর নেই'**
  String get moneyTransferEmpty;

  /// Money screens: empty state hint, pull down to refresh
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার দেখুন।'**
  String get moneyPullToRefresh;

  /// Money handover list: section title for all handovers
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get moneyTransferAll;

  /// Money handover: generic error when confirming or cancelling failed
  ///
  /// In bn, this message translates to:
  /// **'করা গেল না।'**
  String get moneyActionFailed;

  /// Money handover: dialog title asking the reason to cancel
  ///
  /// In bn, this message translates to:
  /// **'কেন বাতিল?'**
  String get moneyTransferCancelWhy;

  /// Money handover: dialog button that closes without cancelling
  ///
  /// In bn, this message translates to:
  /// **'থাক'**
  String get moneyTransferKeep;

  /// Money handover: dialog button that cancels the handover
  ///
  /// In bn, this message translates to:
  /// **'বাতিল করুন'**
  String get moneyTransferCancelConfirm;

  /// Money handover detail: screen title
  ///
  /// In bn, this message translates to:
  /// **'হস্তান্তর'**
  String get moneyTransferTitle;

  /// Money screens: fact row label, status
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get moneyFactStatus;

  /// Money screens: fact row label, date
  ///
  /// In bn, this message translates to:
  /// **'তারিখ'**
  String get moneyFactDate;

  /// Money handover detail: source till
  ///
  /// In bn, this message translates to:
  /// **'কোথা থেকে'**
  String get moneyTransferFrom;

  /// Money handover detail: destination till or bank
  ///
  /// In bn, this message translates to:
  /// **'কোথায়'**
  String get moneyTransferTo;

  /// Money screens: amount label (fact row and input field)
  ///
  /// In bn, this message translates to:
  /// **'অঙ্ক'**
  String get moneyFactAmount;

  /// Money handover detail: person who handed the money over
  ///
  /// In bn, this message translates to:
  /// **'দিলেন'**
  String get moneyTransferGiver;

  /// Money handover detail: person who is to receive the money
  ///
  /// In bn, this message translates to:
  /// **'নেবেন'**
  String get moneyTransferReceiver;

  /// Money handover detail: description / narration
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ'**
  String get moneyTransferNarration;

  /// Money handover detail: reason the handover was cancelled
  ///
  /// In bn, this message translates to:
  /// **'বাতিলের কারণ'**
  String get moneyTransferCancelReason;

  /// Money handover detail: button, receiver confirms the money arrived
  ///
  /// In bn, this message translates to:
  /// **'টাকা পেলাম'**
  String get moneyTransferReceived;

  /// Money handover detail: button that cancels the handover
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get moneyTransferCancel;

  /// Money screens: error when the list of cash tills could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'টিলের তালিকা আনা গেল না।'**
  String get moneyTillsLoadFailed;

  /// Send handover: validation, source, destination and amount are required
  ///
  /// In bn, this message translates to:
  /// **'কোথা থেকে, কোথায় আর কত — লাগবেই।'**
  String get moneyTransferMissing;

  /// Send handover: error when sending failed
  ///
  /// In bn, this message translates to:
  /// **'হস্তান্তর পাঠানো গেল না।'**
  String get moneyTransferSendFailed;

  /// Send handover: screen title
  ///
  /// In bn, this message translates to:
  /// **'টাকা পাঠান'**
  String get moneySendTitle;

  /// Send handover: field label, source cash till
  ///
  /// In bn, this message translates to:
  /// **'কোন টিল থেকে'**
  String get moneyTransferFromTill;

  /// Send handover: field label, destination till or bank
  ///
  /// In bn, this message translates to:
  /// **'কোথায় — টিল বা ব্যাংক'**
  String get moneyTransferToLabel;

  /// Send handover: optional field, who will receive
  ///
  /// In bn, this message translates to:
  /// **'কে নেবেন (ঐচ্ছিক)'**
  String get moneyTransferReceiverLabel;

  /// Send handover: optional description field
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ (ঐচ্ছিক)'**
  String get moneyTransferNoteLabel;

  /// Cash count list and detail: screen title
  ///
  /// In bn, this message translates to:
  /// **'নগদ গোনা'**
  String get moneyCashCountTitle;

  /// Cash count list: button to start a new count
  ///
  /// In bn, this message translates to:
  /// **'গুনুন'**
  String get moneyCashCountNew;

  /// Cash count list: total strip label, number of counts
  ///
  /// In bn, this message translates to:
  /// **'মোট গোনা'**
  String get moneyCashCountTotal;

  /// Cash count list: number of counts in the list
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} টি}}'**
  String moneyCashCountTotalValue(int count);

  /// Cash count list: empty state title
  ///
  /// In bn, this message translates to:
  /// **'কোনো গোনা নেই'**
  String get moneyCashCountEmpty;

  /// Cash count list row: difference between counted and book amount
  ///
  /// In bn, this message translates to:
  /// **'পার্থক্য {difference}'**
  String moneyCashCountDifference(String difference);

  /// Cash count detail: cash till
  ///
  /// In bn, this message translates to:
  /// **'টিল'**
  String get moneyFactTill;

  /// Cash count detail: amount counted
  ///
  /// In bn, this message translates to:
  /// **'গোনা'**
  String get moneyFactCounted;

  /// Cash count detail: amount in the books
  ///
  /// In bn, this message translates to:
  /// **'খাতায়'**
  String get moneyFactExpected;

  /// Cash count detail: difference
  ///
  /// In bn, this message translates to:
  /// **'পার্থক্য'**
  String get moneyFactDifference;

  /// Cash count detail: banknote denomination row, e.g. 500-taka notes
  ///
  /// In bn, this message translates to:
  /// **'{note} টাকার নোট'**
  String moneyCashCountNoteRow(String note);

  /// Cash count detail: how many banknotes of that denomination
  ///
  /// In bn, this message translates to:
  /// **'{qty}টা'**
  String moneyCashCountNoteQty(String qty);

  /// Cash count detail: person who counted
  ///
  /// In bn, this message translates to:
  /// **'গুনেছেন'**
  String get moneyCashCountBy;

  /// Cash count detail: person who accepted the count
  ///
  /// In bn, this message translates to:
  /// **'মেনেছেন'**
  String get moneyCashCountApprovedBy;

  /// Cash count detail: adjustment voucher posted for the difference
  ///
  /// In bn, this message translates to:
  /// **'সমন্বয়ের ভাউচার'**
  String get moneyCashCountAdjustment;

  /// Cash count detail: error when accepting failed
  ///
  /// In bn, this message translates to:
  /// **'মেনে নেওয়া গেল না।'**
  String get moneyCashCountApproveFailed;

  /// Cash count detail: button, accept the count
  ///
  /// In bn, this message translates to:
  /// **'মেনে নিন'**
  String get moneyCashCountApprove;

  /// New cash count: validation, choose a till
  ///
  /// In bn, this message translates to:
  /// **'কোন টিল — বাছুন।'**
  String get moneyCashCountPickTill;

  /// New cash count: error when saving failed
  ///
  /// In bn, this message translates to:
  /// **'গোনা বসানো গেল না।'**
  String get moneyCashCountSaveFailed;

  /// New cash count: screen title
  ///
  /// In bn, this message translates to:
  /// **'নগদ গুনুন'**
  String get moneyCashCountNewTitle;

  /// New cash count: till field label
  ///
  /// In bn, this message translates to:
  /// **'কোন টিল'**
  String get moneyCashCountTillLabel;

  /// New cash count: till choice with its book balance
  ///
  /// In bn, this message translates to:
  /// **'{name} · খাতায় {balance}'**
  String moneyCashCountTillOption(String name, String balance);

  /// New cash count: field asking how many banknotes of this denomination
  ///
  /// In bn, this message translates to:
  /// **'{note} টাকার নোট — কয়টা'**
  String moneyCashCountNoteLabel(String note);

  /// New cash count: button that saves the count
  ///
  /// In bn, this message translates to:
  /// **'গোনা বসান'**
  String get moneyCashCountSave;

  /// New cash count: hint, saving does not touch the books until someone else accepts
  ///
  /// In bn, this message translates to:
  /// **'গোনা বসলে খাতা নড়ে না — অন্য কেউ মেনে নিলে তবেই পার্থক্য খাতায় যায়।'**
  String get moneyCashCountHint;

  /// Hand loan: error when money accounts could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'টাকার খাত আনা গেল না।'**
  String get moneyAccountsLoadFailed;

  /// Hand loan: validation, money account and amount are required
  ///
  /// In bn, this message translates to:
  /// **'টাকার খাত আর অঙ্ক লাগবেই।'**
  String get moneyHandLoanMissing;

  /// Hand loan: error when saving failed
  ///
  /// In bn, this message translates to:
  /// **'হাতধার বসানো গেল না।'**
  String get moneyHandLoanSaveFailed;

  /// Hand loan move: screen title with the person's name
  ///
  /// In bn, this message translates to:
  /// **'হাতধার · {name}'**
  String moneyHandLoanTitle(String name);

  /// Hand loan: segment, I gave money (lent)
  ///
  /// In bn, this message translates to:
  /// **'টাকা দিলাম'**
  String get moneyHandLoanOut;

  /// Hand loan: segment, I got the money back (repaid)
  ///
  /// In bn, this message translates to:
  /// **'ফেরত পেলাম'**
  String get moneyHandLoanIn;

  /// Hand loan: field, account the money is paid from
  ///
  /// In bn, this message translates to:
  /// **'কোন খাত থেকে'**
  String get moneyHandLoanFromAccount;

  /// Hand loan: field, account the money is received into
  ///
  /// In bn, this message translates to:
  /// **'কোন খাতে'**
  String get moneyHandLoanToAccount;

  /// Hand loan: optional free-text note field
  ///
  /// In bn, this message translates to:
  /// **'নোট (ঐচ্ছিক)'**
  String get moneyHandLoanNoteLabel;

  /// Hand loan: button that saves
  ///
  /// In bn, this message translates to:
  /// **'বসান'**
  String get moneyHandLoanSave;

  /// CFO screen title
  ///
  /// In bn, this message translates to:
  /// **'অর্থ-প্রধানের পাতা'**
  String get moneyCfoTitle;

  /// CFO screen: cash expected in hand after this many days (days already in the reader's digits)
  ///
  /// In bn, this message translates to:
  /// **'{days} দিন পরে হাতে'**
  String moneyCfoIn30Days(String days);

  /// Budget: this month's expense budget
  ///
  /// In bn, this message translates to:
  /// **'এই মাসের খরচের বাজেট'**
  String get moneyBudgetExpenseMonth;

  /// Budget: this month's budget (older server, expense and purchase together)
  ///
  /// In bn, this message translates to:
  /// **'এই মাসের বাজেট'**
  String get moneyBudgetMonth;

  /// Budget card: budget amount row
  ///
  /// In bn, this message translates to:
  /// **'বাজেট'**
  String get moneyBudgetLabel;

  /// Budget card: amount spent
  ///
  /// In bn, this message translates to:
  /// **'খরচ হয়েছে'**
  String get moneyBudgetSpent;

  /// Budget card: percentage used
  ///
  /// In bn, this message translates to:
  /// **'ব্যবহার'**
  String get moneyBudgetUsed;

  /// Cash forecast screen title
  ///
  /// In bn, this message translates to:
  /// **'নগদের পূর্বাভাস'**
  String get moneyForecastTitle;

  /// Cash forecast: cash in hand today
  ///
  /// In bn, this message translates to:
  /// **'আজ হাতে'**
  String get moneyForecastToday;

  /// Cash forecast: period name with its end date
  ///
  /// In bn, this message translates to:
  /// **'{when} ({until} পর্যন্ত)'**
  String moneyForecastPeriod(String when, String until);

  /// Cash forecast: money coming in from receivables
  ///
  /// In bn, this message translates to:
  /// **'আসবে — পাওনা'**
  String get moneyForecastReceivables;

  /// Cash forecast: money coming in from loans
  ///
  /// In bn, this message translates to:
  /// **'আসবে — ধার'**
  String get moneyForecastLoansIn;

  /// Cash forecast: money going out to payables
  ///
  /// In bn, this message translates to:
  /// **'যাবে — দেনা'**
  String get moneyForecastPayables;

  /// Cash forecast: money going out as loan repayment
  ///
  /// In bn, this message translates to:
  /// **'যাবে — ধার শোধ'**
  String get moneyForecastLoansOut;

  /// Cash forecast: net
  ///
  /// In bn, this message translates to:
  /// **'নিট'**
  String get moneyForecastNet;

  /// Income screen title
  ///
  /// In bn, this message translates to:
  /// **'আয়'**
  String get moneyIncomeTitle;

  /// Income screen: total income this month
  ///
  /// In bn, this message translates to:
  /// **'মোট আয় · এই মাস'**
  String get moneyIncomeTotal;

  /// Income screen: sales income
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি'**
  String get moneyIncomeSales;

  /// Income screen: other income
  ///
  /// In bn, this message translates to:
  /// **'অন্যান্য আয়'**
  String get moneyIncomeOther;

  /// Income screen: section title, income by account head
  ///
  /// In bn, this message translates to:
  /// **'খাত ধরে'**
  String get moneyIncomeByHead;

  /// Income screen: empty state
  ///
  /// In bn, this message translates to:
  /// **'এই মাসে আয়ের কোনো সারি নেই'**
  String get moneyIncomeEmpty;

  /// Income screen row: the amount in the previous period
  ///
  /// In bn, this message translates to:
  /// **'আগের সময়ে {amount}'**
  String moneyIncomeBefore(String amount);

  /// Budget screen title
  ///
  /// In bn, this message translates to:
  /// **'বাজেট'**
  String get moneyBudgetTitle;

  /// Budget screen: empty state
  ///
  /// In bn, this message translates to:
  /// **'এই মাসের কোনো বাজেট নেই'**
  String get moneyBudgetEmpty;

  /// Budget screen: empty state hint, set the budget on the web
  ///
  /// In bn, this message translates to:
  /// **'বাজেট ওয়েবে বসান।'**
  String get moneyBudgetSetOnWeb;

  /// Purchase budget card: section title
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়ের বাজেট'**
  String get moneyPurchaseBudget;

  /// Purchase budget card: amount purchased
  ///
  /// In bn, this message translates to:
  /// **'কেনা হয়েছে'**
  String get moneyPurchaseBought;

  /// Credit control: list chip, high-risk customers
  ///
  /// In bn, this message translates to:
  /// **'বেশি ঝুঁকি'**
  String get creditListHighRisk;

  /// Credit control: list chip, overdue bills
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ পেরোনো বিল'**
  String get creditListOverdueBills;

  /// Credit control: over the credit limit (list chip and customer state badge)
  ///
  /// In bn, this message translates to:
  /// **'সীমা পার'**
  String get creditOverLimit;

  /// Credit control: list chip, all customers
  ///
  /// In bn, this message translates to:
  /// **'সব গ্রাহক'**
  String get creditListAll;

  /// Credit control: error when the figures could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'বাকির হিসাব আনা গেল না।'**
  String get creditDeskLoadFailed;

  /// Credit control: error when the server does not have credit control yet
  ///
  /// In bn, this message translates to:
  /// **'বাকি নিয়ন্ত্রণ এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get creditDeskMissing;

  /// Credit control screen title
  ///
  /// In bn, this message translates to:
  /// **'বাকি নিয়ন্ত্রণ'**
  String get creditDeskTitle;

  /// Credit control: search field hint
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহকের নাম বা কোড'**
  String get creditDeskSearchHint;

  /// Credit control: empty high-risk list
  ///
  /// In bn, this message translates to:
  /// **'বেশি ঝুঁকির কোনো গ্রাহক নেই'**
  String get creditDeskEmptyHighRisk;

  /// Credit control: empty overdue-bills list
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ পেরোনো কোনো বিল নেই'**
  String get creditDeskEmptyOverdue;

  /// Credit control: empty over-limit list
  ///
  /// In bn, this message translates to:
  /// **'কেউ সীমা পার করেননি'**
  String get creditDeskEmptyOverLimit;

  /// Credit control: empty all-customers list
  ///
  /// In bn, this message translates to:
  /// **'কোনো গ্রাহক নেই'**
  String get creditDeskEmptyAll;

  /// Credit control: only the first page is shown, search to find others
  ///
  /// In bn, this message translates to:
  /// **'প্রথম {shown}টা দেখানো হলো, মোট {total}টা — খুঁজে নিন।'**
  String creditDeskShowingFirst(String shown, String total);

  /// Credit dashboard: credit used (exposure)
  ///
  /// In bn, this message translates to:
  /// **'ব্যবহৃত বাকি'**
  String get creditDashUsed;

  /// Credit dashboard: limit checks are off in this company, figures are for viewing only
  ///
  /// In bn, this message translates to:
  /// **'এই প্রতিষ্ঠানে সীমার যাচাই বন্ধ — অঙ্কগুলো কেবল দেখার জন্য।'**
  String get creditDashCheckOff;

  /// Credit dashboard: total of all credit limits
  ///
  /// In bn, this message translates to:
  /// **'মোট সীমা'**
  String get creditDashTotalLimit;

  /// Credit: remaining (available) limit
  ///
  /// In bn, this message translates to:
  /// **'অবশিষ্ট সীমা'**
  String get creditAvailable;

  /// Credit dashboard: percentage of the limit used
  ///
  /// In bn, this message translates to:
  /// **'সীমার ব্যবহার'**
  String get creditDashUsedShare;

  /// Credit dashboard: overdue receivables
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ পেরোনো পাওনা'**
  String get creditDashOverdue;

  /// Credit dashboard: customers over their limit
  ///
  /// In bn, this message translates to:
  /// **'সীমা পার গ্রাহক'**
  String get creditDashOverLimit;

  /// Credit: customers stopped because of overdue credit (dashboard row and state badge)
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ পেরোনো বাকিতে বন্ধ'**
  String get creditOverdueStopped;

  /// Credit dashboard: customers on credit hold
  ///
  /// In bn, this message translates to:
  /// **'বাকি বন্ধ গ্রাহক'**
  String get creditDashBlocked;

  /// Credit dashboard: limit increase requests awaiting signature
  ///
  /// In bn, this message translates to:
  /// **'সীমা বাড়ানোর অনুরোধ (সইয়ের অপেক্ষায়)'**
  String get creditDashPending;

  /// Credit dashboard: a number of customers
  ///
  /// In bn, this message translates to:
  /// **'{count} জন'**
  String creditPeople(String count);

  /// Credit dashboard: a number of requests
  ///
  /// In bn, this message translates to:
  /// **'{count}টা'**
  String creditDashPendingCount(String count);

  /// Credit list row: bill number
  ///
  /// In bn, this message translates to:
  /// **'বিল {no}'**
  String creditRowBill(String no);

  /// Credit list row: number of days overdue
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ পেরিয়েছে {days} দিন'**
  String creditRowOverdueDays(String days);

  /// Credit list row: risk level
  ///
  /// In bn, this message translates to:
  /// **'ঝুঁকি: {level}'**
  String creditRowRisk(String level);

  /// Credit list row: limit and available limit
  ///
  /// In bn, this message translates to:
  /// **'সীমা {limit} · অবশিষ্ট {available}'**
  String creditRowLimit(String limit, String available);

  /// Customer credit page: load error
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহকের বাকির পাতা আনা গেল না।'**
  String get creditCustomerLoadFailed;

  /// Customer credit page: title before the customer's name is known
  ///
  /// In bn, this message translates to:
  /// **'বাকির পাতা'**
  String get creditCustomerTitle;

  /// Credit: credit hold (card title and state badge)
  ///
  /// In bn, this message translates to:
  /// **'বাকি বন্ধ'**
  String get creditBlocked;

  /// Customer credit page: a bounced-cheque hold can be lifted only by the owner
  ///
  /// In bn, this message translates to:
  /// **'চেক ফেরতের বন্ধ — খোলেন কেবল মালিক'**
  String get creditBouncedOwnerOnly;

  /// Customer credit page: button and dialog title, lift the credit hold
  ///
  /// In bn, this message translates to:
  /// **'বাকি বন্ধ তুলুন'**
  String get creditLift;

  /// Customer credit page: percent of the limit used
  ///
  /// In bn, this message translates to:
  /// **'সীমার {percent}% ব্যবহৃত'**
  String creditUsedPercent(String percent);

  /// Customer credit page: the base credit limit
  ///
  /// In bn, this message translates to:
  /// **'সীমা'**
  String get creditLimit;

  /// Credit: temporary limit (row label and request kind)
  ///
  /// In bn, this message translates to:
  /// **'অস্থায়ী সীমা'**
  String get creditTempLimit;

  /// Customer credit page: temporary limit amount and its last day
  ///
  /// In bn, this message translates to:
  /// **'{amount} ({date} পর্যন্ত)'**
  String creditTempLimitValue(String amount, String date);

  /// Customer credit page: the limit in force now
  ///
  /// In bn, this message translates to:
  /// **'চলতি সীমা'**
  String get creditCurrentLimit;

  /// Customer credit page: credit days (payment terms)
  ///
  /// In bn, this message translates to:
  /// **'বাকির দিন'**
  String get creditDays;

  /// Customer credit page: number of credit days
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{{days} দিন}}'**
  String creditDaysValue(int days);

  /// Customer credit page: balance due in the books
  ///
  /// In bn, this message translates to:
  /// **'খাতার বকেয়া'**
  String get creditDue;

  /// Customer credit page: amount held by open orders/challans
  ///
  /// In bn, this message translates to:
  /// **'আটকে থাকা'**
  String get creditHeld;

  /// Customer credit page: cheques not yet cleared
  ///
  /// In bn, this message translates to:
  /// **'ক্লিয়ার না হওয়া চেক'**
  String get creditUncleared;

  /// Customer credit page: total credit used
  ///
  /// In bn, this message translates to:
  /// **'ব্যবহৃত'**
  String get creditUsed;

  /// Customer credit page: risk level card title
  ///
  /// In bn, this message translates to:
  /// **'ঝুঁকির মাত্রা'**
  String get creditRiskTitle;

  /// Customer credit page: no risk signs
  ///
  /// In bn, this message translates to:
  /// **'কোনো লক্ষণ নেই'**
  String get creditRiskNone;

  /// Customer credit page: the risk level only informs, it does not change the limit or the credit wall
  ///
  /// In bn, this message translates to:
  /// **'মাত্রা কেবল জানায় — সীমা বা দেয়াল বদলায় না।'**
  String get creditRiskNote;

  /// Customer credit page: limit increase request (button and section title)
  ///
  /// In bn, this message translates to:
  /// **'সীমা বাড়ানোর অনুরোধ'**
  String get creditRequest;

  /// Customer credit page: open (unpaid) bills section
  ///
  /// In bn, this message translates to:
  /// **'খোলা বিল'**
  String get creditOpenBills;

  /// Customer credit page: no open bills
  ///
  /// In bn, this message translates to:
  /// **'কোনো খোলা বিল নেই'**
  String get creditNoOpenBills;

  /// Customer credit page: bill due date and days overdue
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ {date} — {days} দিন পেরিয়েছে'**
  String creditBillDueOverdue(String date, int days);

  /// Customer credit page: bill due date
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ {date}'**
  String creditBillDue(String date);

  /// Customer credit page: no limit requests
  ///
  /// In bn, this message translates to:
  /// **'কোনো অনুরোধ নেই'**
  String get creditNoRequests;

  /// Customer credit page: a temporary limit request and its last day
  ///
  /// In bn, this message translates to:
  /// **'অস্থায়ী, {date} পর্যন্ত'**
  String creditRequestTemporaryUntil(String date);

  /// Credit: standard (permanent) limit, as opposed to temporary
  ///
  /// In bn, this message translates to:
  /// **'সাধারণ সীমা'**
  String get creditStandardLimit;

  /// Limit request: validation, enter the requested limit
  ///
  /// In bn, this message translates to:
  /// **'চাওয়া সীমা লিখুন।'**
  String get creditSheetNeedAmount;

  /// Credit: validation, enter a reason
  ///
  /// In bn, this message translates to:
  /// **'কারণ লিখুন।'**
  String get creditNeedReason;

  /// Limit request: validation, choose the last day of the temporary limit
  ///
  /// In bn, this message translates to:
  /// **'অস্থায়ী সীমার শেষ দিন বাছুন।'**
  String get creditSheetNeedUntil;

  /// Limit request: error when sending failed
  ///
  /// In bn, this message translates to:
  /// **'অনুরোধ পাঠানো গেল না।'**
  String get creditSheetSendFailed;

  /// Limit request sheet title with the customer's name
  ///
  /// In bn, this message translates to:
  /// **'সীমা বাড়ানোর অনুরোধ — {name}'**
  String creditSheetTitle(String name);

  /// Limit request: the current limit
  ///
  /// In bn, this message translates to:
  /// **'এখনকার সীমা {amount}'**
  String creditSheetCurrent(String amount);

  /// Limit request: requested limit field (in taka)
  ///
  /// In bn, this message translates to:
  /// **'চাওয়া সীমা (৳)'**
  String get creditSheetAmount;

  /// Limit request: button to choose the last day
  ///
  /// In bn, this message translates to:
  /// **'শেষ দিন বাছুন'**
  String get creditSheetPickUntil;

  /// Limit request: chosen last day
  ///
  /// In bn, this message translates to:
  /// **'শেষ দিন {date}'**
  String creditSheetUntil(String date);

  /// Credit: reason field label
  ///
  /// In bn, this message translates to:
  /// **'কারণ'**
  String get creditReason;

  /// Limit request: it applies only after the owner signs; until then the old limit stays
  ///
  /// In bn, this message translates to:
  /// **'মালিকের সই পড়লে তবেই বসে; ততক্ষণ পুরনো সীমাই চলে।'**
  String get creditSheetOwnerNote;

  /// Limit request: button while sending
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো হচ্ছে…'**
  String get creditSheetSending;

  /// Limit request: button, send for signature
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের জন্য পাঠান'**
  String get creditSheetSend;

  /// Lift credit hold: error
  ///
  /// In bn, this message translates to:
  /// **'বাকি বন্ধ তোলা গেল না।'**
  String get creditLiftFailed;

  /// Lift credit hold dialog: close without lifting
  ///
  /// In bn, this message translates to:
  /// **'থাক'**
  String get creditLiftKeep;

  /// Lift credit hold dialog: button, lift
  ///
  /// In bn, this message translates to:
  /// **'তুলুন'**
  String get creditLiftSend;

  /// Customer credit state badge: active
  ///
  /// In bn, this message translates to:
  /// **'চালু'**
  String get creditStateActive;

  /// Customer credit state badge: limit check off
  ///
  /// In bn, this message translates to:
  /// **'সীমার যাচাই বন্ধ'**
  String get creditStateUnchecked;

  /// Credit risk level: low
  ///
  /// In bn, this message translates to:
  /// **'কম'**
  String get creditRiskLow;

  /// Credit risk level: medium
  ///
  /// In bn, this message translates to:
  /// **'মাঝারি'**
  String get creditRiskMedium;

  /// Credit risk level: high
  ///
  /// In bn, this message translates to:
  /// **'বেশি'**
  String get creditRiskHigh;

  /// Credit risk level: critical
  ///
  /// In bn, this message translates to:
  /// **'চরম'**
  String get creditRiskCritical;

  /// Limit request status: awaiting signature
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষায়'**
  String get creditRequestPending;

  /// Limit request status: signed and applied
  ///
  /// In bn, this message translates to:
  /// **'সই পড়েছে — বসেছে'**
  String get creditRequestApproved;

  /// Limit request status: rejected (owner said no)
  ///
  /// In bn, this message translates to:
  /// **'না'**
  String get creditRequestRejected;

  /// Limit request status: withdrawn by the requester
  ///
  /// In bn, this message translates to:
  /// **'ফিরিয়ে নেওয়া'**
  String get creditRequestWithdrawn;

  /// Limit request status: expired
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ পেরিয়েছে'**
  String get creditRequestExpired;

  /// Limit request status: void, no owner signature, not applied
  ///
  /// In bn, this message translates to:
  /// **'মালিকের সই নেই — বসেনি'**
  String get creditRequestVoid;

  /// Limit request: sent (when the server sent no message)
  ///
  /// In bn, this message translates to:
  /// **'অনুরোধ পাঠানো হলো।'**
  String get creditApiRequestSent;

  /// Credit hold lifted (when the server sent no message)
  ///
  /// In bn, this message translates to:
  /// **'বাকি বন্ধ তোলা হলো।'**
  String get creditApiLifted;

  /// Paper size name: an A4 sheet
  ///
  /// In bn, this message translates to:
  /// **'A4 কাগজ'**
  String get printingPaperA4;

  /// Paper size name: an A5 sheet
  ///
  /// In bn, this message translates to:
  /// **'A5 কাগজ'**
  String get printingPaperA5;

  /// Paper size name: a thermal receipt roll of the given width in millimetres (width is digits, e.g. 80)
  ///
  /// In bn, this message translates to:
  /// **'রসিদ {width}mm'**
  String printingPaperRoll(String width);

  /// Thermal printing: Android's Nearby devices permission was refused; tells where to turn it on
  ///
  /// In bn, this message translates to:
  /// **'ব্লুটুথ প্রিন্টারে ছাপতে ফোনের \"আশেপাশের ডিভাইস\" (Nearby devices) অনুমতি লাগে। অনুমতি দেওয়া হয়নি — ফোনের সেটিংস → অ্যাপ → ABOS → অনুমতি থেকে চালু করুন।'**
  String get printingBluetoothPermission;

  /// Thermal printing: the phone's Bluetooth is switched off
  ///
  /// In bn, this message translates to:
  /// **'ফোনের ব্লুটুথ বন্ধ আছে। চালু করে আবার চেষ্টা করুন।'**
  String get printingBluetoothOff;

  /// Thermal printing: the list of paired Bluetooth printers could not be read
  ///
  /// In bn, this message translates to:
  /// **'জোড়া দেওয়া প্রিন্টারের তালিকা পাওয়া গেল না। আবার চেষ্টা করুন।'**
  String get printingPairedListFailed;

  /// Thermal printing: the PDF could not be turned into an image for the receipt printer
  ///
  /// In bn, this message translates to:
  /// **'কাগজটা ছবিতে রূপান্তর করা গেল না। নরমাল প্রিন্টারে চেষ্টা করুন।'**
  String get printingRasteriseFailed;

  /// Thermal printing: the document has no pages to print
  ///
  /// In bn, this message translates to:
  /// **'কাগজটায় ছাপার মতো কোনো পাতা নেই।'**
  String get printingNoPages;

  /// Thermal printing: could not connect to the Bluetooth printer
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্টারে সংযোগ হলো না। প্রিন্টার চালু আছে, কাছে আছে আর অন্য ফোনে যুক্ত নেই কিনা দেখুন।'**
  String get printingConnectFailed;

  /// Thermal printing: the connection dropped while sending to the printer
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্টারে পাঠানো গেল না — সংযোগ কেটে গেছে। প্রিন্টারের কাগজ ও চার্জ দেখে আবার চেষ্টা করুন।'**
  String get printingSendFailed;

  /// Printed test slip: its heading line
  ///
  /// In bn, this message translates to:
  /// **'পরীক্ষামূলক ছাপা'**
  String get printingTestPageTitle;

  /// Printed test slip: paper width in millimetres and print head width in dots
  ///
  /// In bn, this message translates to:
  /// **'কাগজ {width} মিমি · {dots} ডট'**
  String printingTestPageSize(String width, String dots);

  /// Printed test slip: if the Bengali sample (printed below this line) comes out right, the printer is ready
  ///
  /// In bn, this message translates to:
  /// **'বাংলা লেখা ঠিকমতো এলে প্রিন্টার তৈরি।'**
  String get printingTestPageCheck;

  /// Print sheet: the list of paper sizes for this document could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজের ছাপার মাপ আনা গেল না। আবার চেষ্টা করুন।'**
  String get printingActionsLoadPapersFailed;

  /// Print sheet: the document PDF could not be downloaded, nothing was printed
  ///
  /// In bn, this message translates to:
  /// **'কাগজটা সার্ভার থেকে আনা গেল না — ছাপা হয়নি। ইন্টারনেট সংযোগ দেখে আবার চেষ্টা করুন।'**
  String get printingActionsFetchFailed;

  /// Print sheet: generic failure of view/print/share/save
  ///
  /// In bn, this message translates to:
  /// **'কাজটি সম্পন্ন হয়নি।'**
  String get printingActionsFailed;

  /// Print sheet: the PDF was saved on the phone at this path
  ///
  /// In bn, this message translates to:
  /// **'সেভ হয়েছে: {path}'**
  String printingActionsSaved(String path);

  /// Print sheet: the document was sent to this Bluetooth printer
  ///
  /// In bn, this message translates to:
  /// **'{printer}-এ পাঠানো হয়েছে।'**
  String printingActionsSentTo(String printer);

  /// Print sheet: button to try loading again
  ///
  /// In bn, this message translates to:
  /// **'আবার চেষ্টা'**
  String get printingActionsRetry;

  /// Print sheet: card for the ordinary (A4/A5, Android print dialog) printer
  ///
  /// In bn, this message translates to:
  /// **'নরমাল প্রিন্টার'**
  String get printingActionsNormalPrinter;

  /// Print sheet: this document has no A4/A5 layout
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজের A4/A5 নকশা নেই'**
  String get printingActionsNoSheetLayout;

  /// Print sheet: what the ordinary printer road covers
  ///
  /// In bn, this message translates to:
  /// **'A4/A5 · ওয়াইফাই, USB, PDF'**
  String get printingActionsNormalSubtitle;

  /// Print sheet: card for the Bluetooth receipt (thermal) printer
  ///
  /// In bn, this message translates to:
  /// **'থার্মাল প্রিন্টার'**
  String get printingActionsThermalPrinter;

  /// Print sheet: this document has no receipt-roll layout
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজের রসিদ নকশা নেই'**
  String get printingActionsNoReceiptLayout;

  /// Print sheet: thermal road covers 58/80 mm rolls over Bluetooth (the two widths are digits)
  ///
  /// In bn, this message translates to:
  /// **'{narrow}/{wide} মিমি · ব্লুটুথ'**
  String printingActionsThermalSubtitle(String narrow, String wide);

  /// Print sheet: open the PDF on screen
  ///
  /// In bn, this message translates to:
  /// **'পর্দায় দেখুন'**
  String get printingActionsView;

  /// Print sheet: share the PDF
  ///
  /// In bn, this message translates to:
  /// **'শেয়ার করুন'**
  String get printingActionsShare;

  /// Print sheet: examples of where the PDF can be shared
  ///
  /// In bn, this message translates to:
  /// **'হোয়াটসঅ্যাপ, ইমেইল, ড্রাইভ'**
  String get printingActionsShareHint;

  /// Print sheet: save the PDF on the phone
  ///
  /// In bn, this message translates to:
  /// **'ফোনে সেভ করুন'**
  String get printingActionsSave;

  /// Print sheet: print on the ordinary printer
  ///
  /// In bn, this message translates to:
  /// **'ছাপুন'**
  String get printingActionsPrint;

  /// Print sheet: no Bluetooth printer chosen yet
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্টার বাছা হয়নি'**
  String get printingActionsNoPrinter;

  /// Print sheet: the chosen printer is connected only when printing
  ///
  /// In bn, this message translates to:
  /// **'ছাপার সময় যুক্ত হবে'**
  String get printingActionsConnectsWhenPrinting;

  /// Print sheet: you will pick from the paired printers the first time you print
  ///
  /// In bn, this message translates to:
  /// **'প্রথমবার ছাপার সময় জোড়া দেওয়া প্রিন্টার থেকে বাছবেন'**
  String get printingActionsChooseOnFirstPrint;

  /// Print sheet: open the printer settings
  ///
  /// In bn, this message translates to:
  /// **'সেটিং'**
  String get printingActionsSettings;

  /// Print sheet: print on the Bluetooth receipt printer
  ///
  /// In bn, this message translates to:
  /// **'থার্মালে ছাপুন'**
  String get printingActionsPrintThermal;

  /// Printer settings: this printer was saved as the phone's receipt printer
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্টার রাখা হলো: {printer}'**
  String printingSettingsSaved(String printer);

  /// Printer settings: test slip sent; if the Bengali comes out right the printer is ready
  ///
  /// In bn, this message translates to:
  /// **'পরীক্ষামূলক ছাপা পাঠানো হয়েছে। বাংলা লেখা ঠিক এলে প্রিন্টার তৈরি।'**
  String get printingSettingsTestSent;

  /// Printer settings: the test slip did not print
  ///
  /// In bn, this message translates to:
  /// **'পরীক্ষামূলক ছাপা হয়নি। আবার চেষ্টা করুন।'**
  String get printingSettingsTestFailed;

  /// Printer settings: screen title
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্টার'**
  String get printingSettingsTitle;

  /// Printer settings: section heading for the Bluetooth receipt printer
  ///
  /// In bn, this message translates to:
  /// **'থার্মাল (ব্লুটুথ রসিদ) প্রিন্টার'**
  String get printingSettingsThermalHeading;

  /// Printer settings: no printer chosen yet
  ///
  /// In bn, this message translates to:
  /// **'কোনো প্রিন্টার বাছা হয়নি'**
  String get printingSettingsNoPrinter;

  /// Printer settings: pair the printer in the phone's Bluetooth settings first
  ///
  /// In bn, this message translates to:
  /// **'প্রথমে ফোনের সেটিংস → ব্লুটুথ থেকে প্রিন্টারটি জোড়া দিন'**
  String get printingSettingsPairFirst;

  /// Printer settings: button to change the chosen printer
  ///
  /// In bn, this message translates to:
  /// **'বদলান'**
  String get printingSettingsChange;

  /// Printer settings: button to choose a printer
  ///
  /// In bn, this message translates to:
  /// **'বাছুন'**
  String get printingSettingsChoose;

  /// Printer settings: forget the chosen printer
  ///
  /// In bn, this message translates to:
  /// **'এই প্রিন্টার ভুলে যান'**
  String get printingSettingsForget;

  /// Printer settings: heading for the receipt roll width
  ///
  /// In bn, this message translates to:
  /// **'রোলের মাপ'**
  String get printingSettingsRollWidth;

  /// Printer settings: a roll width in millimetres (width is digits)
  ///
  /// In bn, this message translates to:
  /// **'{width} মিমি'**
  String printingSettingsWidthMm(String width);

  /// Printer settings: button that prints a test slip
  ///
  /// In bn, this message translates to:
  /// **'পরীক্ষামূলক ছাপা'**
  String get printingSettingsTestButton;

  /// Printer settings: heading — which document prints on which printer
  ///
  /// In bn, this message translates to:
  /// **'কোন কাগজ কোথায় ছাপা হয়'**
  String get printingSettingsWhereHeading;

  /// Printer settings: each kind of document remembers the printer it was last printed on; the normal printer is the phone's own print system
  ///
  /// In bn, this message translates to:
  /// **'প্রতিটা কাগজ (বিল, চালান, ভাউচার …) শেষবার যে প্রিন্টারে ছাপা হয়েছিল, পরের বার সেটাই আগে বাছা থাকে। নরমাল প্রিন্টার মানে ফোনের নিজের প্রিন্ট ব্যবস্থা — ওয়াইফাই/USB প্রিন্টার বা PDF সেভ।'**
  String get printingSettingsWhereBody;

  /// Printer settings: clear the remembered printer choice per document kind
  ///
  /// In bn, this message translates to:
  /// **'মনে রাখা পছন্দ মুছুন'**
  String get printingSettingsResetRoads;

  /// Thermal printing: no Bluetooth printer is paired; how to pair one
  ///
  /// In bn, this message translates to:
  /// **'কোনো ব্লুটুথ প্রিন্টার জোড়া দেওয়া নেই। প্রিন্টার চালু করে ফোনের সেটিংস → ব্লুটুথ থেকে একবার জোড়া দিন (পিন সাধারণত 0000 বা 1234), তারপর আবার চেষ্টা করুন।'**
  String get printingThermalNotPaired;

  /// Dialog before Android's Nearby devices prompt: title
  ///
  /// In bn, this message translates to:
  /// **'ব্লুটুথ প্রিন্টারের অনুমতি'**
  String get printingThermalExplainTitle;

  /// Dialog before Android's Nearby devices prompt: why it is asked; quote Android's own labels for Nearby devices and Allow
  ///
  /// In bn, this message translates to:
  /// **'রসিদ প্রিন্টারে ছাপতে ABOS ফোনের ব্লুটুথ দিয়ে জোড়া দেওয়া প্রিন্টারের সাথে যুক্ত হবে। এরপর ফোন \"আশেপাশের ডিভাইস\" অনুমতি চাইবে — \"অনুমতি দিন\" চাপুন। এতে আপনার অবস্থান নেওয়া হয় না।'**
  String get printingThermalExplainBody;

  /// Dialog before the Bluetooth permission: decline for now
  ///
  /// In bn, this message translates to:
  /// **'এখন না'**
  String get printingThermalNotNow;

  /// Dialog before the Bluetooth permission: go ahead
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get printingThermalOk;

  /// Dialog title: choose one of the paired printers
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্টার বাছুন'**
  String get printingThermalChoosePrinter;

  /// Paper scan: the QR is from an old printout that the phone cannot open
  ///
  /// In bn, this message translates to:
  /// **'এটা পুরনো ছাপা কাগজ। ফোনে খোলে না — ওয়েবে খুলুন, বা কাগজটা আবার ছাপুন।'**
  String get scanPaperOldPaper;

  /// Paper scan: the QR is not an ABOS document QR
  ///
  /// In bn, this message translates to:
  /// **'এটা ABOS-এর কাগজের QR নয়।'**
  String get scanPaperNotOurs;

  /// Paper scan: the document was not found — cancelled or not in your branch
  ///
  /// In bn, this message translates to:
  /// **'কাগজটা পাওয়া যায়নি — বাতিল হয়েছে, বা আপনার শাখার নয়।'**
  String get scanPaperNotFound;

  /// Paper scan: no permission to view or change this document
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজ দেখার বা বদলানোর অনুমতি আপনার নেই।'**
  String get scanPaperForbidden;

  /// Paper scan: the goods have already left through the gate
  ///
  /// In bn, this message translates to:
  /// **'মাল আগেই বেরিয়ে গেছে।'**
  String get scanPaperAlreadyOut;

  /// Paper scan: refused — no vehicle details, or the shop is over its credit limit
  ///
  /// In bn, this message translates to:
  /// **'হয়নি — গাড়ির তথ্য বসানো নেই, বা দোকানের বাকির সীমা পার। অফিসে বলুন।'**
  String get scanPaperBlocked;

  /// Paper scan: generic failure
  ///
  /// In bn, this message translates to:
  /// **'হয়নি। আবার চেষ্টা করুন।'**
  String get scanPaperFailed;

  /// Paper scan: confirm dialog title — have the goods gone out of the gate?
  ///
  /// In bn, this message translates to:
  /// **'মাল বেরোল?'**
  String get scanPaperGateOutTitle;

  /// Paper scan: confirming gate-out issues the gate pass and the invoice; it cannot be undone
  ///
  /// In bn, this message translates to:
  /// **'গেট পাস হবে আর বিল কাটা হবে। এটা ফেরানো যায় না।'**
  String get scanPaperGateOutBody;

  /// Paper scan: gate-out confirm dialog, the No button
  ///
  /// In bn, this message translates to:
  /// **'না'**
  String get scanPaperNo;

  /// Paper scan: gate-out confirm dialog, the Yes button
  ///
  /// In bn, this message translates to:
  /// **'হ্যাঁ, বেরোল'**
  String get scanPaperGateOutYes;

  /// Paper scan: screen title
  ///
  /// In bn, this message translates to:
  /// **'কাগজ স্ক্যান'**
  String get scanPaperTitle;

  /// Paper scan: the QR cannot be read without the camera
  ///
  /// In bn, this message translates to:
  /// **'ক্যামেরা ছাড়া QR পড়া যায় না।'**
  String get scanPaperNoCamera;

  /// Paper scan: button to allow the camera
  ///
  /// In bn, this message translates to:
  /// **'ক্যামেরা চালু করুন'**
  String get scanPaperAskCamera;

  /// Paper scan: hold the delivery challan or gate pass QR in front of the camera
  ///
  /// In bn, this message translates to:
  /// **'চালান বা গেট পাসের QR ক্যামেরার সামনে ধরুন।'**
  String get scanPaperHint;

  /// Paper scan: the sale number
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় নং {number}'**
  String scanPaperSaleNo(String number);

  /// Paper scan: the document date
  ///
  /// In bn, this message translates to:
  /// **'তারিখ {date}'**
  String scanPaperDate(String date);

  /// Paper scan: the document's stage
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা: {stage}'**
  String scanPaperStatus(String stage);

  /// Paper scan: when the goods left the gate (date and time)
  ///
  /// In bn, this message translates to:
  /// **'বেরিয়েছে {when}'**
  String scanPaperOutAt(String when);

  /// Paper scan: line quantity
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ {qty}'**
  String scanPaperQty(String qty);

  /// Paper scan: free (bonus) quantity on the line
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি {qty}'**
  String scanPaperFreeQty(String qty);

  /// Paper scan: the lot (batch) of the line
  ///
  /// In bn, this message translates to:
  /// **'লট {lot}'**
  String scanPaperLot(String lot);

  /// Paper scan: vehicle details are filled in
  ///
  /// In bn, this message translates to:
  /// **'গাড়ি বসানো আছে'**
  String get scanPaperVehicleSet;

  /// Paper scan: warning — no vehicle details
  ///
  /// In bn, this message translates to:
  /// **'⚠ গাড়ি বসানো নেই'**
  String get scanPaperVehicleMissing;

  /// Paper scan: the vehicle
  ///
  /// In bn, this message translates to:
  /// **'গাড়ি {vehicle}'**
  String scanPaperVehicle(String vehicle);

  /// Paper scan: the driver
  ///
  /// In bn, this message translates to:
  /// **'চালক {driver}'**
  String scanPaperDriver(String driver);

  /// Paper scan: the invoice total (amount already formatted)
  ///
  /// In bn, this message translates to:
  /// **'বিলের মোট {amount}'**
  String scanPaperBillTotal(String amount);

  /// Paper scan: button — the goods went out of the gate
  ///
  /// In bn, this message translates to:
  /// **'মাল বেরোল'**
  String get scanPaperGateOut;

  /// Paper scan: button — confirm delivery
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি নিশ্চিত'**
  String get scanPaperConfirmDelivery;

  /// Paper scan: button — scan another QR
  ///
  /// In bn, this message translates to:
  /// **'আরেকটা স্ক্যান'**
  String get scanPaperScanAgain;

  /// Paper scan: stage — stock allocated
  ///
  /// In bn, this message translates to:
  /// **'বরাদ্দ হয়েছে'**
  String get scanPaperStageAllocated;

  /// Paper scan: stage — goods being picked
  ///
  /// In bn, this message translates to:
  /// **'মাল তোলা হচ্ছে'**
  String get scanPaperStagePicking;

  /// Paper scan: stage — packed
  ///
  /// In bn, this message translates to:
  /// **'প্যাক হয়েছে'**
  String get scanPaperStagePacked;

  /// Paper scan: stage — passed the gate
  ///
  /// In bn, this message translates to:
  /// **'গেট পেরিয়েছে'**
  String get scanPaperStageDispatched;

  /// Paper scan: stage — delivered
  ///
  /// In bn, this message translates to:
  /// **'পৌঁছেছে'**
  String get scanPaperStageDelivered;

  /// Proof of delivery: enter the name of the person who received the goods
  ///
  /// In bn, this message translates to:
  /// **'যিনি মাল নিলেন তাঁর নাম লিখুন।'**
  String get scanReceiveNameMissing;

  /// Proof of delivery: enter the receiver's phone number — the way to ask later
  ///
  /// In bn, this message translates to:
  /// **'যিনি নিলেন তাঁর ফোন নম্বর লিখুন — পরে জিজ্ঞেস করার পথ এটাই।'**
  String get scanReceivePhoneMissing;

  /// Proof of delivery: a quantity on this product's line is not valid
  ///
  /// In bn, this message translates to:
  /// **'{product}: পরিমাণ ঠিক নেই।'**
  String scanReceiveQtyInvalid(String product);

  /// Proof of delivery: taken plus damaged is more than the delivery challan quantity
  ///
  /// In bn, this message translates to:
  /// **'{product}: নেওয়া আর ভাঙা মিলে চালানের চেয়ে বেশি।'**
  String scanReceiveQtyTooMuch(String product);

  /// Proof of delivery: screen title with the delivery challan number
  ///
  /// In bn, this message translates to:
  /// **'বুঝিয়ে দিন — {number}'**
  String scanReceiveTitle(String number);

  /// Proof of delivery: field — who received the goods
  ///
  /// In bn, this message translates to:
  /// **'যিনি মাল নিলেন'**
  String get scanReceiveNameLabel;

  /// Proof of delivery: field — the receiver's phone
  ///
  /// In bn, this message translates to:
  /// **'তাঁর ফোন'**
  String get scanReceivePhoneLabel;

  /// Proof of delivery: for each product, how much was taken and how much damaged; the rest is short and comes back
  ///
  /// In bn, this message translates to:
  /// **'প্রতিটা পণ্য — কতটা নিলেন, কতটা ভাঙা; বাকিটা কম (ফেরত আসবে)'**
  String get scanReceiveLinesHint;

  /// Proof of delivery: product name and the quantity on the delivery challan
  ///
  /// In bn, this message translates to:
  /// **'{product} · চালানে {qty}'**
  String scanReceiveLineHeader(String product, String qty);

  /// Proof of delivery: field — quantity taken in good condition
  ///
  /// In bn, this message translates to:
  /// **'নিলেন'**
  String get scanReceiveTaken;

  /// Proof of delivery: field — quantity damaged
  ///
  /// In bn, this message translates to:
  /// **'ভাঙা'**
  String get scanReceiveDamaged;

  /// Proof of delivery: quantity short (not taken, not damaged)
  ///
  /// In bn, this message translates to:
  /// **'কম: {qty}'**
  String scanReceiveShort(String qty);

  /// Proof of delivery: confirm button — the receiver took the goods
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত — বুঝে নিলেন'**
  String get scanReceiveDone;

  /// Counts awaiting acceptance: one line per product - book quantity, counted quantity and the difference
  ///
  /// In bn, this message translates to:
  /// **'{product}: খাতায় {book} → গোনা {counted} (পার্থক্য {difference})'**
  String stockWriteCountLine(
      String product, String book, String counted, String difference);

  /// Stock form name: adjust stock after a physical count
  ///
  /// In bn, this message translates to:
  /// **'গণনা-সমন্বয়'**
  String get stockWriteFormAdjust;

  /// Stock form name: issue goods out of stock (not a sale)
  ///
  /// In bn, this message translates to:
  /// **'মাল ইস্যু'**
  String get stockWriteFormIssue;

  /// Stock form name: opening stock
  ///
  /// In bn, this message translates to:
  /// **'খোলা মজুদ'**
  String get stockWriteFormOpening;

  /// Stock form name: assign a lot (batch) to goods without one
  ///
  /// In bn, this message translates to:
  /// **'লট দেওয়া'**
  String get stockWriteFormLot;

  /// Count adjustment form: quantity field - the quantity found by counting
  ///
  /// In bn, this message translates to:
  /// **'গুনে পেলাম'**
  String get stockWriteQtyAdjust;

  /// Stock issue form: quantity field - how much goes out
  ///
  /// In bn, this message translates to:
  /// **'কতটা বের হবে'**
  String get stockWriteQtyIssue;

  /// Opening stock form: quantity field
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ'**
  String get stockWriteQtyOpening;

  /// Assign lot form: quantity field - how much goes into the lot
  ///
  /// In bn, this message translates to:
  /// **'কতটা লটে যাবে'**
  String get stockWriteQtyLot;

  /// Stock work screen: error when the stock setup could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'মজুদের কাজ আনা গেল না।'**
  String get stockWriteLoadFailed;

  /// Stock work screen: the server does not have this feature yet (the app is newer)
  ///
  /// In bn, this message translates to:
  /// **'এই কাজ এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get stockWriteNotOnServer;

  /// Stock work screen title: the stock tasks this person may do
  ///
  /// In bn, this message translates to:
  /// **'মজুদের কাজ'**
  String get stockWriteWorkTitle;

  /// Put-away: tile and screen title for shelving received goods
  ///
  /// In bn, this message translates to:
  /// **'মাল বসানো'**
  String get stockWritePlaceTitle;

  /// Stock work tile subtitle: put arrived goods on the shelf
  ///
  /// In bn, this message translates to:
  /// **'এসে পৌঁছানো মাল তাকে তোলা'**
  String get stockWritePlaceHint;

  /// Stock work tile subtitle: count and match with the books
  ///
  /// In bn, this message translates to:
  /// **'গুনে খাতার সাথে মেলানো'**
  String get stockWriteAdjustHint;

  /// Stock work tile subtitle: take goods out other than by a sale
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি ছাড়া মাল বের করা'**
  String get stockWriteIssueHint;

  /// Stock work tile subtitle: enter the starting stock
  ///
  /// In bn, this message translates to:
  /// **'শুরুর মজুদ বসানো'**
  String get stockWriteOpeningHint;

  /// Stock work tile subtitle: give goods without a lot a lot
  ///
  /// In bn, this message translates to:
  /// **'লট-ছাড়া মালকে লট'**
  String get stockWriteLotHint;

  /// Tile and screen title: stock counts waiting to be accepted by an approver
  ///
  /// In bn, this message translates to:
  /// **'মেনে নেওয়ার অপেক্ষার গণনা'**
  String get stockWriteCountsTitle;

  /// Stock work tile subtitle: accept counts made by others
  ///
  /// In bn, this message translates to:
  /// **'অন্যের গোনা মেনে নেওয়া'**
  String get stockWriteCountsHint;

  /// Stock work screen: empty state - no permission for any stock entry
  ///
  /// In bn, this message translates to:
  /// **'মজুদের কোনো লেখার অনুমতি নেই'**
  String get stockWriteNoAccessTitle;

  /// Stock work screen: empty state message - ask the person who grants permissions
  ///
  /// In bn, this message translates to:
  /// **'দরকার হলে যিনি অনুমতি দেন তাঁকে বলুন।'**
  String get stockWriteNoAccessMessage;

  /// Snack bar after a stock or quality entry was recorded (when the server gives no message)
  ///
  /// In bn, this message translates to:
  /// **'বসেছে।'**
  String get stockWriteResultDone;

  /// Snack bar after a stock entry was kept pending (awaiting acceptance or signature)
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায় রাখা হলো।'**
  String get stockWriteResultWaiting;

  /// Stock form validation: product, warehouse, reason and quantity are required
  ///
  /// In bn, this message translates to:
  /// **'পণ্য, গুদাম, কারণ আর পরিমাণ লাগবেই।'**
  String get stockWriteNeedFieldsWithReason;

  /// Stock form validation: product, warehouse and quantity are required
  ///
  /// In bn, this message translates to:
  /// **'পণ্য, গুদাম আর পরিমাণ লাগবেই।'**
  String get stockWriteNeedFields;

  /// Stock form: error when the entry could not be recorded; form is the form name, e.g. Opening stock
  ///
  /// In bn, this message translates to:
  /// **'{form} বসানো গেল না।'**
  String stockWriteSendFailed(String form);

  /// Stock and quality forms: tile to choose a product
  ///
  /// In bn, this message translates to:
  /// **'পণ্য বাছুন'**
  String get stockWritePickProduct;

  /// Stock forms and quality check: warehouse field label
  ///
  /// In bn, this message translates to:
  /// **'গুদাম'**
  String get stockWriteWarehouse;

  /// Stock forms, counts and quality disposal: reason field label
  ///
  /// In bn, this message translates to:
  /// **'কারণ'**
  String get stockWriteReason;

  /// Count adjustment: switch - the count is of free (promotional) goods
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি মাল গুনছি'**
  String get stockWriteCountingFree;

  /// Assign lot form: free (promotional) quantity, optional
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি (ঐচ্ছিক)'**
  String get stockWriteFreeOptional;

  /// Opening stock form: unit cost field
  ///
  /// In bn, this message translates to:
  /// **'একক দর'**
  String get stockWriteUnitCost;

  /// Count adjustment: unit cost of surplus goods found, optional
  ///
  /// In bn, this message translates to:
  /// **'বাড়তি মালের দর (ঐচ্ছিক)'**
  String get stockWriteExtraCostOptional;

  /// Assign lot form: lot (batch) number field
  ///
  /// In bn, this message translates to:
  /// **'লট নম্বর'**
  String get stockWriteLotNo;

  /// Stock forms: lot number, only for lot-tracked products
  ///
  /// In bn, this message translates to:
  /// **'লট নম্বর (লট-ধরা পণ্যে)'**
  String get stockWriteLotNoIfLotted;

  /// Stock forms: expiry date field, optional
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ (ঐচ্ছিক)'**
  String get stockWriteExpiryOptional;

  /// Stock forms: narration / description, optional
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ (ঐচ্ছিক)'**
  String get stockWriteNoteOptional;

  /// Stock form: send button; form is the form name, e.g. Opening stock
  ///
  /// In bn, this message translates to:
  /// **'{form} পাঠান'**
  String stockWriteSend(String form);

  /// Product picker: search box hint
  ///
  /// In bn, this message translates to:
  /// **'নাম, কোড বা বারকোড'**
  String get stockWriteProductSearchHint;

  /// Product picker: nothing matches the search
  ///
  /// In bn, this message translates to:
  /// **'কোনো পণ্য মেলেনি'**
  String get stockWriteNoProductMatch;

  /// Put-away screen: error loading goods waiting to be shelved
  ///
  /// In bn, this message translates to:
  /// **'বসানোর অপেক্ষার মাল আনা গেল না।'**
  String get stockWritePlaceLoadFailed;

  /// Put-away screen: error when the put-away could not be recorded
  ///
  /// In bn, this message translates to:
  /// **'মাল বসানো গেল না।'**
  String get stockWritePlaceFailed;

  /// Put-away screen: empty state - nothing waiting to be shelved
  ///
  /// In bn, this message translates to:
  /// **'বসানোর অপেক্ষায় কিছু নেই'**
  String get stockWritePlaceEmpty;

  /// Stock, quality and serial lists: empty state hint - pull down to check again
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার দেখুন।'**
  String get stockWritePullAgain;

  /// Put-away line: the lot (batch) number
  ///
  /// In bn, this message translates to:
  /// **'লট {lot}'**
  String stockWriteLot(String lot);

  /// Put-away line: quantity still waiting to be shelved
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায় {qty}'**
  String stockWriteWaitingQty(String qty);

  /// Put-away line: free (promotional) quantity waiting
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি {qty}'**
  String stockWriteFreeQty(String qty);

  /// Put-away line: quantity being shelved now
  ///
  /// In bn, this message translates to:
  /// **'বসাচ্ছি'**
  String get stockWritePlacingLabel;

  /// Put-away line: storage location (bin) in the warehouse
  ///
  /// In bn, this message translates to:
  /// **'জায়গা'**
  String get stockWritePlaceSpot;

  /// Put-away card: button to shelve the goods
  ///
  /// In bn, this message translates to:
  /// **'বসান'**
  String get stockWritePlaceButton;

  /// Counts screen: error loading counts awaiting acceptance
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষার গণনা আনা গেল না।'**
  String get stockWriteCountsLoadFailed;

  /// Counts screen: validation - choose a reason; the reason decides which account the difference goes to
  ///
  /// In bn, this message translates to:
  /// **'কারণ বাছুন — পার্থক্যটা কোন খাতে যাবে তা কারণ বলে।'**
  String get stockWriteChooseReason;

  /// Counts screen: error when the count could not be accepted
  ///
  /// In bn, this message translates to:
  /// **'গণনাটা মেনে নেওয়া গেল না।'**
  String get stockWriteAcceptFailed;

  /// Counts screen: total strip label - total pending counts
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায় মোট'**
  String get stockWriteCountsTotal;

  /// Total strip value: a number of items (no noun)
  ///
  /// In bn, this message translates to:
  /// **'{count} টি'**
  String stockWriteCountValue(int count);

  /// Counts screen: empty state - no counts pending
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায় কোনো গণনা নেই'**
  String get stockWriteCountsEmpty;

  /// Counts screen: button to accept the count
  ///
  /// In bn, this message translates to:
  /// **'মেনে নিন'**
  String get stockWriteAccept;

  /// Quality check list: error loading the list
  ///
  /// In bn, this message translates to:
  /// **'মান যাচাইয়ের তালিকা আনা গেল না।'**
  String get stockQualityLoadFailed;

  /// Quality and serial lists: the server does not have this list yet (the app is newer)
  ///
  /// In bn, this message translates to:
  /// **'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get stockQualityNotOnServer;

  /// Quality check (QC) screen title
  ///
  /// In bn, this message translates to:
  /// **'মান যাচাই'**
  String get stockQualityTitle;

  /// Quality check list: button to start a new check
  ///
  /// In bn, this message translates to:
  /// **'নতুন যাচাই'**
  String get stockQualityNew;

  /// Quality check list: status filter chip - all statuses
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get stockQualityAll;

  /// Quality check list: total strip label
  ///
  /// In bn, this message translates to:
  /// **'মোট যাচাই'**
  String get stockQualityTotal;

  /// Quality and serial lists: total strip value, a number of items (no noun)
  ///
  /// In bn, this message translates to:
  /// **'{count} টি'**
  String stockQualityCountValue(int count);

  /// Quality check list: empty state
  ///
  /// In bn, this message translates to:
  /// **'কোনো মান যাচাই নেই'**
  String get stockQualityEmpty;

  /// Quality and serial lists: button to load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get stockQualityMore;

  /// Quality check screen: error loading one check
  ///
  /// In bn, this message translates to:
  /// **'যাচাইটা আনা গেল না।'**
  String get stockQualityOneLoadFailed;

  /// Quality check screen: error when the decision or disposal could not be recorded
  ///
  /// In bn, this message translates to:
  /// **'বসানো গেল না।'**
  String get stockQualitySaveFailed;

  /// Quality check screen title with the check number
  ///
  /// In bn, this message translates to:
  /// **'মান যাচাই · {no}'**
  String stockQualityTitleNo(String no);

  /// Quality check facts: status
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get stockQualityStatus;

  /// Quality check facts: date
  ///
  /// In bn, this message translates to:
  /// **'তারিখ'**
  String get stockQualityDate;

  /// Quality check facts: lot (batch)
  ///
  /// In bn, this message translates to:
  /// **'লট'**
  String get stockQualityLot;

  /// Quality check facts: quantity inspected
  ///
  /// In bn, this message translates to:
  /// **'যাচাই করা হলো'**
  String get stockQualityInspected;

  /// Quality check: accepted quantity (fact and field)
  ///
  /// In bn, this message translates to:
  /// **'গৃহীত'**
  String get stockQualityAccepted;

  /// Quality check: rejected quantity, units that failed the check (fact and field)
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get stockQualityRejected;

  /// Quality check facts: quantity of rejected goods disposed of
  ///
  /// In bn, this message translates to:
  /// **'সরানো'**
  String get stockQualityDisposed;

  /// Quality check facts: what was checked (criteria)
  ///
  /// In bn, this message translates to:
  /// **'যা দেখা হলো'**
  String get stockQualityCriteria;

  /// Quality check facts: remarks
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য'**
  String get stockQualityRemarks;

  /// Quality check: heading of the card where the checker gives the verdict
  ///
  /// In bn, this message translates to:
  /// **'রায়'**
  String get stockQualityVerdict;

  /// Quality check: result field (pass, fail, ...)
  ///
  /// In bn, this message translates to:
  /// **'ফল'**
  String get stockQualityResult;

  /// Quality check: remarks field, optional
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য (ঐচ্ছিক)'**
  String get stockQualityRemarksOptional;

  /// Quality check: button to record the verdict
  ///
  /// In bn, this message translates to:
  /// **'রায় দিন'**
  String get stockQualityGiveVerdict;

  /// Quality check: card heading - dispose of rejected goods
  ///
  /// In bn, this message translates to:
  /// **'বাতিল মাল সরানো'**
  String get stockQualityDisposeTitle;

  /// Quality check disposal: quantity field (how much)
  ///
  /// In bn, this message translates to:
  /// **'কতটা'**
  String get stockQualityDisposeQty;

  /// Quality check disposal: button to dispose of the rejected goods
  ///
  /// In bn, this message translates to:
  /// **'সরান'**
  String get stockQualityDispose;

  /// New quality check: validation - product and quantity are required
  ///
  /// In bn, this message translates to:
  /// **'পণ্য আর পরিমাণ লাগবেই।'**
  String get stockQualityNeedFields;

  /// New quality check: error when the check could not be opened
  ///
  /// In bn, this message translates to:
  /// **'যাচাই খোলা গেল না।'**
  String get stockQualityOpenFailed;

  /// New quality check screen title
  ///
  /// In bn, this message translates to:
  /// **'নতুন মান যাচাই'**
  String get stockQualityNewTitle;

  /// New quality check: quantity to inspect
  ///
  /// In bn, this message translates to:
  /// **'কতটা যাচাই'**
  String get stockQualityQtyToCheck;

  /// New quality check: lot number, optional
  ///
  /// In bn, this message translates to:
  /// **'লট নম্বর (ঐচ্ছিক)'**
  String get stockQualityLotOptional;

  /// New quality check: what will be checked, optional
  ///
  /// In bn, this message translates to:
  /// **'কী দেখা হবে (ঐচ্ছিক)'**
  String get stockQualityCriteriaOptional;

  /// New quality check: button to open the check
  ///
  /// In bn, this message translates to:
  /// **'যাচাই খুলুন'**
  String get stockQualityOpen;

  /// Serial numbers screen: error loading the list
  ///
  /// In bn, this message translates to:
  /// **'সিরিয়ালের তালিকা আনা গেল না।'**
  String get stockSerialLoadFailed;

  /// Serial numbers screen title
  ///
  /// In bn, this message translates to:
  /// **'সিরিয়াল নম্বর'**
  String get stockSerialTitle;

  /// Serial numbers: button and screen title - receive serialised pieces into stock
  ///
  /// In bn, this message translates to:
  /// **'পিস ঢোকান'**
  String get stockSerialReceive;

  /// Serial numbers: button and screen title - issue serialised pieces out of stock
  ///
  /// In bn, this message translates to:
  /// **'পিস বের করুন'**
  String get stockSerialIssue;

  /// Serial numbers: search box hint
  ///
  /// In bn, this message translates to:
  /// **'সিরিয়াল নম্বর'**
  String get stockSerialSearchHint;

  /// Serial numbers: total strip label
  ///
  /// In bn, this message translates to:
  /// **'মোট সিরিয়াল'**
  String get stockSerialTotal;

  /// Serial numbers: empty state - no serial found
  ///
  /// In bn, this message translates to:
  /// **'কোনো সিরিয়াল মেলেনি'**
  String get stockSerialEmpty;

  /// Receive pieces: validation - a product and at least one serial are required
  ///
  /// In bn, this message translates to:
  /// **'পণ্য আর অন্তত একটা সিরিয়াল লাগবেই।'**
  String get stockSerialNeedProductAndOne;

  /// Issue pieces: validation - at least one serial is required
  ///
  /// In bn, this message translates to:
  /// **'অন্তত একটা সিরিয়াল লাগবেই।'**
  String get stockSerialNeedOne;

  /// Serial form: error when the serials could not be recorded
  ///
  /// In bn, this message translates to:
  /// **'সিরিয়াল বসানো গেল না।'**
  String get stockSerialSaveFailed;

  /// Serial form: the serials box, one per line
  ///
  /// In bn, this message translates to:
  /// **'সিরিয়াল — প্রতি সারিতে একটা'**
  String get stockSerialNumbersLabel;

  /// Issue pieces: to whom, optional
  ///
  /// In bn, this message translates to:
  /// **'কাকে (ঐচ্ছিক)'**
  String get stockSerialSoldToOptional;

  /// Issue pieces: warranty in months, optional
  ///
  /// In bn, this message translates to:
  /// **'ওয়ারেন্টি, মাস (ঐচ্ছিক)'**
  String get stockSerialWarrantyOptional;

  /// Receive pieces: send button
  ///
  /// In bn, this message translates to:
  /// **'ঢোকান'**
  String get stockSerialReceiveButton;

  /// Issue pieces: send button
  ///
  /// In bn, this message translates to:
  /// **'বের করুন'**
  String get stockSerialIssueButton;

  /// Stock list screen title: stock on hand
  ///
  /// In bn, this message translates to:
  /// **'হাতে থাকা মজুদ'**
  String get stockListTitle;

  /// Stock list: button - the stock list with values (report)
  ///
  /// In bn, this message translates to:
  /// **'মূল্যসহ তালিকা'**
  String get stockListWithValue;

  /// Stock list: title of the stock value report
  ///
  /// In bn, this message translates to:
  /// **'মজুদের মূল্য'**
  String get stockListValueReportTitle;

  /// Stock list: button and screen title - stock reports
  ///
  /// In bn, this message translates to:
  /// **'মজুদের রিপোর্ট'**
  String get stockListReports;

  /// Stock list: search box hint
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের নাম দিয়ে খুঁজুন'**
  String get stockListSearchHint;

  /// Stock list: total strip label - total quantity available for sale
  ///
  /// In bn, this message translates to:
  /// **'মোট বিক্রয়যোগ্য'**
  String get stockListTotalAvailable;

  /// Stock list: empty state - no stock synced yet
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো মজুদ সিঙ্ক হয়নি'**
  String get stockListNothingSynced;

  /// Stock list: empty state - the stock list could not be fetched
  ///
  /// In bn, this message translates to:
  /// **'মজুদের তালিকা আনা যায়নি'**
  String get stockListLoadFailed;

  /// Stock list: empty state hint - pull down to try again
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার চেষ্টা করুন।'**
  String get stockListPullRetry;

  /// Stock list: nothing matches the search
  ///
  /// In bn, this message translates to:
  /// **'কোনো মিল পাওয়া যায়নি'**
  String get stockListNoMatch;

  /// Stock list: no match while filters are on - clear the filters
  ///
  /// In bn, this message translates to:
  /// **'ফিল্টার মুছে আবার দেখুন।'**
  String get stockListClearFilters;

  /// Stock list row: the product of this stock row is not synced yet
  ///
  /// In bn, this message translates to:
  /// **'পণ্য এখনো সিঙ্ক হয়নি'**
  String get stockListProductNotSynced;

  /// Stock list row: caption under the quantity available for sale
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়যোগ্য'**
  String get stockListAvailable;

  /// Stock list row: quantity on the shelf, with unit
  ///
  /// In bn, this message translates to:
  /// **'তাকে {qty}'**
  String stockListOnShelf(String qty);

  /// Stock list row: quantity reserved for orders, with unit
  ///
  /// In bn, this message translates to:
  /// **'অর্ডারে {qty}'**
  String stockListOnOrder(String qty);

  /// Stock list row: quantity on hold, with unit
  ///
  /// In bn, this message translates to:
  /// **'আটকানো {qty}'**
  String stockListHeld(String qty);

  /// Product list screen title
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের তালিকা'**
  String get productsListTitle;

  /// Product list: search box hint
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের নাম দিয়ে খুঁজুন'**
  String get productsListSearchHint;

  /// Product list: how many products are shown
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{মোট {count} টি পণ্য}}'**
  String productsListTotal(int count);

  /// Product list: empty state - no products synced yet
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো পণ্য সিঙ্ক হয়নি'**
  String get productsListNothingSynced;

  /// Product list: empty state - the list could not be fetched
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের তালিকা আনা যায়নি'**
  String get productsListLoadFailed;

  /// Product list: empty state hint - pull down to try again
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার চেষ্টা করুন।'**
  String get productsListPullRetry;

  /// Product list: nothing matches the search
  ///
  /// In bn, this message translates to:
  /// **'কোনো মিল পাওয়া যায়নি'**
  String get productsListNoMatch;

  /// Product list: no match while filters are on - clear the filters
  ///
  /// In bn, this message translates to:
  /// **'ফিল্টার মুছে আবার দেখুন।'**
  String get productsListClearFilters;

  /// Product card: quantity available for sale, with unit
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়যোগ্য {qty}'**
  String productsListAvailable(String qty);

  /// Product card: purchase price (only for people allowed to see cost)
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়: {amount}'**
  String productsListPurchase(String amount);

  /// Loading trip crew line: the vehicle
  ///
  /// In bn, this message translates to:
  /// **'গাড়ি {vehicle}'**
  String loadingTripVehicle(String vehicle);

  /// Loading trip crew line: the driver
  ///
  /// In bn, this message translates to:
  /// **'চালক {driver}'**
  String loadingTripDriver(String driver);

  /// Loading sheet list: error loading the trips
  ///
  /// In bn, this message translates to:
  /// **'ট্রিপের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get loadingListLoadFailed;

  /// Loading sheet list: the server does not have loading sheets yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে লোডিং শিট এখনো আসেনি — অফিসে জানান।'**
  String get loadingListNotOnServer;

  /// Loading sheet list screen title
  ///
  /// In bn, this message translates to:
  /// **'লোডিং শিট'**
  String get loadingListTitle;

  /// Loading sheet list: total strip label
  ///
  /// In bn, this message translates to:
  /// **'মোট লোডিং শিট'**
  String get loadingListTotal;

  /// Loading sheet list: total strip value, a number of items (no noun)
  ///
  /// In bn, this message translates to:
  /// **'{count} টি'**
  String loadingListCountValue(int count);

  /// Loading sheet list: no open trips to load
  ///
  /// In bn, this message translates to:
  /// **'তোলার মতো কোনো খোলা ট্রিপ নেই।'**
  String get loadingListEmpty;

  /// Loading trip row: number of delivery challans on the trip
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count}টা চালান}}'**
  String loadingListChallans(int count);

  /// Loading sheet list: button to load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও দেখুন'**
  String get loadingListMore;

  /// Loading sheet: error loading the sheet
  ///
  /// In bn, this message translates to:
  /// **'লোডিং শিট আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get loadingSheetLoadFailed;

  /// Loading sheet: confirm dialog title - mark as packed?
  ///
  /// In bn, this message translates to:
  /// **'প্যাক হয়েছে?'**
  String get loadingSheetPackedQuestion;

  /// Loading sheet: confirm dialog text - the trip's delivery challans not yet loaded move to the Packed step
  ///
  /// In bn, this message translates to:
  /// **'ট্রিপের যে চালানগুলো এখনো তোলা বাকি, সেগুলো \"প্যাক হয়েছে\" ধাপে যাবে।'**
  String get loadingSheetPackedExplain;

  /// Loading sheet confirm dialog: No button
  ///
  /// In bn, this message translates to:
  /// **'না'**
  String get loadingSheetNo;

  /// Loading sheet confirm dialog: Yes, packed button
  ///
  /// In bn, this message translates to:
  /// **'হ্যাঁ, প্যাক হয়েছে'**
  String get loadingSheetYesPacked;

  /// Loading sheet: notice after marking packed; doc is the trip number, count the delivery challans moved
  ///
  /// In bn, this message translates to:
  /// **'{doc} — {count, plural, other{{count}টা চালান \"প্যাক হয়েছে\" ধাপে গেল।}}'**
  String loadingSheetPackedNotice(String doc, int count);

  /// Loading sheet: error when Packed could not be recorded
  ///
  /// In bn, this message translates to:
  /// **'\"প্যাক হয়েছে\" লেখা গেল না। আবার চেষ্টা করুন।'**
  String get loadingSheetPackFailed;

  /// Loading sheet screen title with the trip number
  ///
  /// In bn, this message translates to:
  /// **'লোডিং শিট {no}'**
  String loadingSheetTitle(String no);

  /// Loading sheet: section heading - by product, how much to load
  ///
  /// In bn, this message translates to:
  /// **'পণ্য ধরে — কত তুলবেন'**
  String get loadingSheetByProduct;

  /// Loading sheet product: lots and quantities to load from
  ///
  /// In bn, this message translates to:
  /// **'লট: {lots}'**
  String loadingSheetLots(String lots);

  /// Loading sheet: section heading - by delivery challan, for whom
  ///
  /// In bn, this message translates to:
  /// **'চালান ধরে — কার জন্য'**
  String get loadingSheetByChallan;

  /// Loading sheet: Packed - chip on a packed delivery challan and the button that marks the trip packed
  ///
  /// In bn, this message translates to:
  /// **'প্যাক হয়েছে'**
  String get loadingSheetPacked;

  /// Loading sheet: all delivery challans are packed, waiting for the vehicle to leave
  ///
  /// In bn, this message translates to:
  /// **'সব চালান প্যাক হয়েছে — গাড়ি রওনার অপেক্ষায়।'**
  String get loadingSheetAllPacked;

  /// Running offers screen title and total strip label
  ///
  /// In bn, this message translates to:
  /// **'চলতি অফার'**
  String get promoOffersTitle;

  /// Running offers: error loading the list
  ///
  /// In bn, this message translates to:
  /// **'অফারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get promoOffersLoadFailed;

  /// Running offers: the server does not have the offer list yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে অফারের তালিকা এখনো আসেনি — অফিসে জানান।'**
  String get promoOffersNotOnServer;

  /// Running offers: empty state - no offer running now
  ///
  /// In bn, this message translates to:
  /// **'এখন কোনো চলতি অফার নেই'**
  String get promoOffersNoneRunning;

  /// Running offers: empty state - offers are switched off in this branch
  ///
  /// In bn, this message translates to:
  /// **'এই শাখায় অফার এখন বন্ধ'**
  String get promoOffersOffHere;

  /// Running offers: no offer matches the searched product name
  ///
  /// In bn, this message translates to:
  /// **'\"{query}\" — এই নামে কোনো অফার মেলেনি।'**
  String promoOffersNoMatch(String query);

  /// Running offers: search box hint - type a product name
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের নাম লিখুন'**
  String get promoOffersSearchHint;

  /// Running offers: total strip value, a number of offers (no noun)
  ///
  /// In bn, this message translates to:
  /// **'{count} টি'**
  String promoOffersCountValue(int count);

  /// Offer card: which goods the offer is on
  ///
  /// In bn, this message translates to:
  /// **'কোন মালে'**
  String get promoOffersGoods;

  /// Offer card: which customers the offer is for
  ///
  /// In bn, this message translates to:
  /// **'কার জন্য'**
  String get promoOffersForWhom;

  /// Offer card: until when the offer runs
  ///
  /// In bn, this message translates to:
  /// **'কবে পর্যন্ত'**
  String get promoOffersUntil;

  /// Offer card: the offer ends today
  ///
  /// In bn, this message translates to:
  /// **'আজই শেষ'**
  String get promoOffersEndsToday;

  /// Offer card: days left until the offer ends
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{আর {days} দিন}}'**
  String promoOffersDaysLeft(int days);

  /// Home-screen widget footer: how many more rows there are than shown (count already in the reader's digits)
  ///
  /// In bn, this message translates to:
  /// **'আরও {count}টা'**
  String launcherWidgetMore(String count);

  /// Home-screen widget corner: a total (an amount or a count)
  ///
  /// In bn, this message translates to:
  /// **'মোট {value}'**
  String launcherWidgetTotal(String value);

  /// Home-screen widget bottom line: the figures are true up to this time
  ///
  /// In bn, this message translates to:
  /// **'{time} পর্যন্ত'**
  String launcherWidgetAsOf(String time);

  /// Home-screen widget after sign-out: why the figures are gone
  ///
  /// In bn, this message translates to:
  /// **'সাইন আউট করা হয়েছে'**
  String get launcherWidgetSignedOut;

  /// Widget row: cash (money channel)
  ///
  /// In bn, this message translates to:
  /// **'নগদ'**
  String get launcherWidgetCash;

  /// Widget row: bank (money channel)
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক'**
  String get launcherWidgetBank;

  /// Widget caption: the total is cash plus bank plus mobile financial services
  ///
  /// In bn, this message translates to:
  /// **'নগদ + ব্যাংক + MFS'**
  String get launcherWidgetCashBankMfs;

  /// Widget caption: the expense total leaves out staff expense claims
  ///
  /// In bn, this message translates to:
  /// **'কর্মীর খরচ বাদে'**
  String get launcherWidgetNoStaffExpenses;

  /// Widget title: invoices raised today (gross, before returns)
  ///
  /// In bn, this message translates to:
  /// **'আজ বিল হয়েছে'**
  String get launcherWidgetBilledToday;

  /// Widget title / row: money collected today
  ///
  /// In bn, this message translates to:
  /// **'আজকের আদায়'**
  String get launcherWidgetCollectionsToday;

  /// Widget title and quick-action button: notices
  ///
  /// In bn, this message translates to:
  /// **'নোটিশ'**
  String get launcherWidgetNotices;

  /// Widget name in the app's widget list: papers waiting for my signature
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষা'**
  String get launcherWidgetApprovalsName;

  /// Approvals widget heading: papers awaiting your signature
  ///
  /// In bn, this message translates to:
  /// **'আপনার সইয়ের অপেক্ষায়'**
  String get launcherWidgetApprovalsTitle;

  /// Approvals widget when nothing waits for a signature
  ///
  /// In bn, this message translates to:
  /// **'কোনো সই বাকি নেই'**
  String get launcherWidgetApprovalsEmpty;

  /// Old 2x2 approvals widget when nothing waits for a signature
  ///
  /// In bn, this message translates to:
  /// **'এখন সইয়ের জন্য কিছু নেই।'**
  String get launcherWidgetApprovalsNothingNow;

  /// Old 2x2 approvals widget: how many more papers wait than the rows show (may end in +)
  ///
  /// In bn, this message translates to:
  /// **'আরও {count} টি'**
  String launcherWidgetApprovalsMore(String count);

  /// Attendance widget title
  ///
  /// In bn, this message translates to:
  /// **'আমার উপস্থিতি'**
  String get launcherWidgetAttendanceTitle;

  /// Attendance widget when the user has no employee record
  ///
  /// In bn, this message translates to:
  /// **'আপনার কর্মীর খাতা নেই'**
  String get launcherWidgetAttendanceNoRecord;

  /// Attendance widget: I came in today at this time
  ///
  /// In bn, this message translates to:
  /// **'আজ এসেছি: {time}'**
  String launcherWidgetAttendanceInAt(String time);

  /// Attendance widget: present today
  ///
  /// In bn, this message translates to:
  /// **'আজ উপস্থিত'**
  String get launcherWidgetAttendancePresent;

  /// Attendance widget: absent today
  ///
  /// In bn, this message translates to:
  /// **'আজ অনুপস্থিত'**
  String get launcherWidgetAttendanceAbsent;

  /// Attendance widget: on leave today
  ///
  /// In bn, this message translates to:
  /// **'আজ ছুটি'**
  String get launcherWidgetAttendanceLeave;

  /// Attendance widget: public holiday today
  ///
  /// In bn, this message translates to:
  /// **'আজ সরকারি ছুটি'**
  String get launcherWidgetAttendanceHoliday;

  /// Attendance widget: today's attendance is not marked yet
  ///
  /// In bn, this message translates to:
  /// **'আজ হাজিরা বসেনি'**
  String get launcherWidgetAttendanceNotMarked;

  /// Attendance widget caption: came in late
  ///
  /// In bn, this message translates to:
  /// **'দেরি'**
  String get launcherWidgetAttendanceLate;

  /// Attendance widget: leave days left
  ///
  /// In bn, this message translates to:
  /// **'ছুটি বাকি: {days} দিন'**
  String launcherWidgetAttendanceLeaveLeft(String days);

  /// Attendance widget: this month's present and absent days
  ///
  /// In bn, this message translates to:
  /// **'এ মাসে: উপস্থিত {present} · অনুপস্থিত {absent}'**
  String launcherWidgetAttendanceMonth(String present, String absent);

  /// Attendance widget: this month's leave and late days
  ///
  /// In bn, this message translates to:
  /// **'ছুটি {leave} · দেরি {late}'**
  String launcherWidgetAttendanceMonthMore(String leave, String late);

  /// Bank reconciliation widget title: bank lines still to reconcile
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক মেলানো বাকি'**
  String get launcherWidgetBankReconcileTitle;

  /// Bank reconciliation widget: how many statement lines are unreconciled
  ///
  /// In bn, this message translates to:
  /// **'না-মেলানো সারি: {count}'**
  String launcherWidgetBankReconcileOpen(String count);

  /// Bank reconciliation widget: the bank was never reconciled
  ///
  /// In bn, this message translates to:
  /// **'এখনো কখনো মেলানো হয়নি'**
  String get launcherWidgetBankReconcileNever;

  /// Bank reconciliation widget: the day of the last reconciliation
  ///
  /// In bn, this message translates to:
  /// **'শেষ মেলানো: {day}'**
  String launcherWidgetBankReconcileLast(String day);

  /// Bank reconciliation widget: everything is reconciled
  ///
  /// In bn, this message translates to:
  /// **'সব মেলানো'**
  String get launcherWidgetBankReconcileAllDone;

  /// Bank reconciliation widget: everything reconciled, with the last day
  ///
  /// In bn, this message translates to:
  /// **'সব মেলানো · শেষ মেলানো: {day}'**
  String launcherWidgetBankReconcileAllDoneLast(String day);

  /// Big dues widget name
  ///
  /// In bn, this message translates to:
  /// **'বড় বকেয়া'**
  String get launcherWidgetBigDuesName;

  /// Big dues widget heading: the customers who owe the most
  ///
  /// In bn, this message translates to:
  /// **'সবচেয়ে বড় বকেয়া'**
  String get launcherWidgetBigDuesTitle;

  /// Big dues widget row: the customer has paid in advance
  ///
  /// In bn, this message translates to:
  /// **'অগ্রিম জমা আছে'**
  String get launcherWidgetBigDuesAdvance;

  /// Big dues widget row: the due is this many days overdue
  ///
  /// In bn, this message translates to:
  /// **'বাকির {days} দিন পার'**
  String launcherWidgetBigDuesOverdue(String days);

  /// Big dues widget: nobody owes anything
  ///
  /// In bn, this message translates to:
  /// **'কারো কাছে বাকি নেই'**
  String get launcherWidgetBigDuesEmpty;

  /// Today's delivery challans widget title
  ///
  /// In bn, this message translates to:
  /// **'আজকের চালান'**
  String get launcherWidgetChallansTitle;

  /// Challans widget row: gate pass not yet issued
  ///
  /// In bn, this message translates to:
  /// **'গেট পাস বাকি'**
  String get launcherWidgetChallansGatePending;

  /// Challans widget row: on the way to the customer
  ///
  /// In bn, this message translates to:
  /// **'পথে আছে'**
  String get launcherWidgetChallansMoving;

  /// Challans widget row: delivered
  ///
  /// In bn, this message translates to:
  /// **'পৌঁছেছে'**
  String get launcherWidgetChallansDelivered;

  /// Challans widget row: came back undelivered
  ///
  /// In bn, this message translates to:
  /// **'ফেরত এসেছে'**
  String get launcherWidgetChallansReturned;

  /// Challans widget: the latest challan's customer
  ///
  /// In bn, this message translates to:
  /// **'শেষ: {customer}'**
  String launcherWidgetChallansLatest(String customer);

  /// Challans widget: the vehicle number
  ///
  /// In bn, this message translates to:
  /// **'গাড়ি {vehicle}'**
  String launcherWidgetChallansVehicle(String vehicle);

  /// Challans widget: no challans yet today
  ///
  /// In bn, this message translates to:
  /// **'আজ এখনো কোনো চালান নেই'**
  String get launcherWidgetChallansEmpty;

  /// Cheques widget name: cheques to deposit in the bank today
  ///
  /// In bn, this message translates to:
  /// **'চেক — আজ ব্যাংকে জমা'**
  String get launcherWidgetChequesName;

  /// Cheques widget heading: cheques that must be deposited today
  ///
  /// In bn, this message translates to:
  /// **'চেক — আজ ব্যাংকে জমা দিতে হবে'**
  String get launcherWidgetChequesTitle;

  /// Cheques widget caption: total to deposit today
  ///
  /// In bn, this message translates to:
  /// **'আজ জমা দেওয়ার মোট'**
  String get launcherWidgetChequesTotal;

  /// Cheques widget: no cheque to deposit today
  ///
  /// In bn, this message translates to:
  /// **'আজ জমা দেওয়ার মতো কোনো চেক নেই'**
  String get launcherWidgetChequesEmpty;

  /// Cheques widget: bank name and cheque number
  ///
  /// In bn, this message translates to:
  /// **'{bank} চেক {number}'**
  String launcherWidgetChequesCheque(String bank, String number);

  /// Cheques widget: this customer's cheque came back (bounced)
  ///
  /// In bn, this message translates to:
  /// **'ফেরত এসেছে: {name}'**
  String launcherWidgetChequesBounced(String name);

  /// Cheques widget: a bounced cheque is due again on the customer's ledger
  ///
  /// In bn, this message translates to:
  /// **'{cheque} · গ্রাহকের খাতায় আবার বাকি'**
  String launcherWidgetChequesDueAgain(String cheque);

  /// Cheques widget: the cheque and its date
  ///
  /// In bn, this message translates to:
  /// **'{cheque} · তারিখ {day}'**
  String launcherWidgetChequesDated(String cheque, String day);

  /// Credit-limit widget title: customers near their credit limit
  ///
  /// In bn, this message translates to:
  /// **'বাকির সীমার কাছে'**
  String get launcherWidgetCreditLimitsTitle;

  /// Credit-limit widget caption under the count: customers above this share of their limit
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক সীমার {percent}%-এর বেশি'**
  String launcherWidgetCreditLimitsCaption(String percent);

  /// Credit-limit widget: no customer is near the limit
  ///
  /// In bn, this message translates to:
  /// **'কোনো গ্রাহক সীমার কাছে নেই'**
  String get launcherWidgetCreditLimitsEmpty;

  /// Expenses widget title
  ///
  /// In bn, this message translates to:
  /// **'এই মাসের খরচ'**
  String get launcherWidgetExpensesTitle;

  /// Expenses widget heading with the month's name
  ///
  /// In bn, this message translates to:
  /// **'{month} মাসের খরচ'**
  String launcherWidgetExpensesMonth(String month);

  /// Expenses widget: no expenses yet this month
  ///
  /// In bn, this message translates to:
  /// **'এই মাসে এখনো কোনো খরচ নেই'**
  String get launcherWidgetExpensesEmpty;

  /// Goods incoming widget name
  ///
  /// In bn, this message translates to:
  /// **'মাল আসছে'**
  String get launcherWidgetGoodsName;

  /// Goods incoming widget heading: purchase orders due to arrive
  ///
  /// In bn, this message translates to:
  /// **'মাল আসছে — ক্রয় অর্ডার'**
  String get launcherWidgetGoodsTitle;

  /// Goods incoming row: the supplier, then ' · branch' or nothing
  ///
  /// In bn, this message translates to:
  /// **'{supplier} (সরবরাহকারী){branch}'**
  String launcherWidgetGoodsSupplier(String supplier, String branch);

  /// Goods incoming row: a late delivery from this supplier
  ///
  /// In bn, this message translates to:
  /// **'দেরি: {supplier} (সরবরাহকারী){branch}'**
  String launcherWidgetGoodsSupplierLate(String supplier, String branch);

  /// Goods incoming row: it was expected on this day
  ///
  /// In bn, this message translates to:
  /// **'কথা ছিল {day}'**
  String launcherWidgetGoodsWasDue(String day);

  /// Goods incoming corner: orders due this week
  ///
  /// In bn, this message translates to:
  /// **'এই সপ্তাহে {count}'**
  String launcherWidgetGoodsWeek(String count);

  /// Goods incoming corner: orders due this week and how many are late
  ///
  /// In bn, this message translates to:
  /// **'এই সপ্তাহে {count} · দেরি {late}'**
  String launcherWidgetGoodsWeekLate(String count, String late);

  /// Goods incoming widget: nothing due this week
  ///
  /// In bn, this message translates to:
  /// **'এই সপ্তাহে কোনো মাল আসার কথা নেই'**
  String get launcherWidgetGoodsEmpty;

  /// Goods incoming row: how many products the order has
  ///
  /// In bn, this message translates to:
  /// **'{count}টা পণ্য'**
  String launcherWidgetGoodsProducts(String count);

  /// Goods incoming row: arrives today
  ///
  /// In bn, this message translates to:
  /// **'আসবে আজ'**
  String get launcherWidgetGoodsToday;

  /// Goods incoming row: arrives tomorrow
  ///
  /// In bn, this message translates to:
  /// **'আসবে কাল'**
  String get launcherWidgetGoodsTomorrow;

  /// Goods incoming row: arrives on this day
  ///
  /// In bn, this message translates to:
  /// **'আসবে {day}'**
  String launcherWidgetGoodsOn(String day);

  /// Money-in-hand widget title
  ///
  /// In bn, this message translates to:
  /// **'টাকা হাতে'**
  String get launcherWidgetMoneyTitle;

  /// Money-in-hand widget row: the cash box
  ///
  /// In bn, this message translates to:
  /// **'নগদ বাক্স'**
  String get launcherWidgetMoneyCashBox;

  /// Money-in-hand widget row: money on its way to the bank
  ///
  /// In bn, this message translates to:
  /// **'পথে (ব্যাংকে যাচ্ছে)'**
  String get launcherWidgetMoneyTransit;

  /// Notices widget caption under the count: unread
  ///
  /// In bn, this message translates to:
  /// **'না-পড়া'**
  String get launcherWidgetNoticesUnread;

  /// Notices widget: no notices
  ///
  /// In bn, this message translates to:
  /// **'কোনো নোটিশ নেই'**
  String get launcherWidgetNoticesEmpty;

  /// Notices widget chip: a new (unread) notice
  ///
  /// In bn, this message translates to:
  /// **'নতুন'**
  String get launcherWidgetNoticesNew;

  /// Offers widget name
  ///
  /// In bn, this message translates to:
  /// **'অফার আর স্কিম'**
  String get launcherWidgetOffersName;

  /// Offers widget heading: offers and schemes running now
  ///
  /// In bn, this message translates to:
  /// **'চলতি অফার আর স্কিম'**
  String get launcherWidgetOffersTitle;

  /// Offers widget corner: how many are active
  ///
  /// In bn, this message translates to:
  /// **'চালু {count}'**
  String launcherWidgetOffersActive(String count);

  /// Offers widget: no active offer now
  ///
  /// In bn, this message translates to:
  /// **'এখন কোনো চালু অফার নেই'**
  String get launcherWidgetOffersEmpty;

  /// Offers widget chip: ends today
  ///
  /// In bn, this message translates to:
  /// **'শেষ: আজ'**
  String get launcherWidgetOffersEndsToday;

  /// Offers widget chip: ends tomorrow
  ///
  /// In bn, this message translates to:
  /// **'শেষ: কাল'**
  String get launcherWidgetOffersEndsTomorrow;

  /// Offers widget chip: ends in this many days (days already in the reader's digits)
  ///
  /// In bn, this message translates to:
  /// **'শেষ: {days} দিন পর'**
  String launcherWidgetOffersEndsInDays(String days);

  /// Offers widget chip: ends on this day
  ///
  /// In bn, this message translates to:
  /// **'শেষ: {day}'**
  String launcherWidgetOffersEndsOn(String day);

  /// Owner's full-board widget name
  ///
  /// In bn, this message translates to:
  /// **'মালিকের পূর্ণ পর্দা'**
  String get launcherWidgetOwnerName;

  /// Owner board heading when no single branch is chosen
  ///
  /// In bn, this message translates to:
  /// **'সব শাখা'**
  String get launcherWidgetOwnerAllBranches;

  /// Owner board row: how many collection receipts today
  ///
  /// In bn, this message translates to:
  /// **'রসিদ {count}টা'**
  String launcherWidgetOwnerReceipts(String count);

  /// Owner board row: the cash part of the money
  ///
  /// In bn, this message translates to:
  /// **'নগদ {amount}'**
  String launcherWidgetOwnerCashAmount(String amount);

  /// Owner board row: total dues
  ///
  /// In bn, this message translates to:
  /// **'মোট বকেয়া'**
  String get launcherWidgetOwnerDues;

  /// Owner board row: how many shops owe
  ///
  /// In bn, this message translates to:
  /// **'{count}টা দোকান'**
  String launcherWidgetOwnerShops(String count);

  /// Owner board row: gross profit this month
  ///
  /// In bn, this message translates to:
  /// **'এ মাসের মোট লাভ'**
  String get launcherWidgetOwnerProfit;

  /// Owner board row: gross margin percent
  ///
  /// In bn, this message translates to:
  /// **'হার {margin}%'**
  String launcherWidgetOwnerMargin(String margin);

  /// Owner board row: expenses this month
  ///
  /// In bn, this message translates to:
  /// **'এ মাসের খরচ'**
  String get launcherWidgetOwnerExpenses;

  /// Owner board bar: the month's sales target and days left
  ///
  /// In bn, this message translates to:
  /// **'{month}-এর লক্ষ্য · বাকি {days} দিন'**
  String launcherWidgetOwnerTarget(String month, String days);

  /// Owner board button: papers waiting for my signature
  ///
  /// In bn, this message translates to:
  /// **'{count} সই'**
  String launcherWidgetOwnerToSign(String count);

  /// Owner board button: cheques to deposit
  ///
  /// In bn, this message translates to:
  /// **'{count} চেক'**
  String launcherWidgetOwnerCheques(String count);

  /// Owner board button: products low in stock
  ///
  /// In bn, this message translates to:
  /// **'{count} মজুদ কম'**
  String launcherWidgetOwnerLowStock(String count);

  /// Owner board button: delivery challans on the road
  ///
  /// In bn, this message translates to:
  /// **'{count} চালান পথে'**
  String launcherWidgetOwnerChallansMoving(String count);

  /// Owner board button: unread notices
  ///
  /// In bn, this message translates to:
  /// **'{count} নোটিশ'**
  String launcherWidgetOwnerNotices(String count);

  /// Owner board caption under today's sales: the server's label and the number of invoices
  ///
  /// In bn, this message translates to:
  /// **'{label} · {count}টা বিল'**
  String launcherWidgetOwnerBills(String label, String count);

  /// Owner board: nothing to show yet
  ///
  /// In bn, this message translates to:
  /// **'এখনো দেখানোর মতো কিছু নেই'**
  String get launcherWidgetOwnerEmpty;

  /// Owner board: a month whose name could not be read
  ///
  /// In bn, this message translates to:
  /// **'মাস'**
  String get launcherWidgetOwnerMonth;

  /// Principal commission widget name
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালের কমিশন'**
  String get launcherWidgetCommissionName;

  /// Principal commission widget: the total row with its side (to pay / to receive)
  ///
  /// In bn, this message translates to:
  /// **'মোট · {side}'**
  String launcherWidgetCommissionTotal(String side);

  /// Principal commission widget: no commission in this cycle
  ///
  /// In bn, this message translates to:
  /// **'এই চক্রে কোনো কমিশন নেই'**
  String get launcherWidgetCommissionEmpty;

  /// Profit widget title: gross profit this month
  ///
  /// In bn, this message translates to:
  /// **'এই মাসের মোট লাভ'**
  String get launcherWidgetProfitTitle;

  /// Profit widget heading: gross loss this month
  ///
  /// In bn, this message translates to:
  /// **'এই মাসের মোট লোকসান'**
  String get launcherWidgetProfitLossThisMonth;

  /// Profit widget heading with the month's name: gross profit
  ///
  /// In bn, this message translates to:
  /// **'{month} মাসের মোট লাভ'**
  String launcherWidgetProfitMonth(String month);

  /// Profit widget heading with the month's name: gross loss
  ///
  /// In bn, this message translates to:
  /// **'{month} মাসের মোট লোকসান'**
  String launcherWidgetProfitLossMonth(String month);

  /// Profit widget caption: gross margin percent
  ///
  /// In bn, this message translates to:
  /// **'মোট লাভের হার {margin}%'**
  String launcherWidgetProfitMargin(String margin);

  /// Profit widget row: sales net of VAT and returns
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি (ভ্যাট আর ফেরত বাদে)'**
  String get launcherWidgetProfitSales;

  /// Profit widget row: cost of goods sold
  ///
  /// In bn, this message translates to:
  /// **'বিক্রিত মালের দাম'**
  String get launcherWidgetProfitCogs;

  /// Quick actions widget name
  ///
  /// In bn, this message translates to:
  /// **'দ্রুত কাজ'**
  String get launcherWidgetQuickName;

  /// Quick action button: new order
  ///
  /// In bn, this message translates to:
  /// **'নতুন অর্ডার'**
  String get launcherWidgetQuickOrder;

  /// Quick action button: collect money from a customer
  ///
  /// In bn, this message translates to:
  /// **'টাকা আদায়'**
  String get launcherWidgetQuickCollect;

  /// Quick action button: voucher
  ///
  /// In bn, this message translates to:
  /// **'ভাউচার'**
  String get launcherWidgetQuickVoucher;

  /// Quick action button: scan
  ///
  /// In bn, this message translates to:
  /// **'স্ক্যান'**
  String get launcherWidgetQuickScan;

  /// Quick action button: sign the papers waiting for me
  ///
  /// In bn, this message translates to:
  /// **'সই'**
  String get launcherWidgetQuickSign;

  /// Quick actions widget: no quick action for this user
  ///
  /// In bn, this message translates to:
  /// **'আপনার জন্য কোনো দ্রুত কাজ নেই'**
  String get launcherWidgetQuickEmpty;

  /// Sales today widget row: number of invoices
  ///
  /// In bn, this message translates to:
  /// **'বিল {count}টা'**
  String launcherWidgetSalesBills(String count);

  /// Sales today widget row: collections
  ///
  /// In bn, this message translates to:
  /// **'আদায়'**
  String get launcherWidgetSalesCollection;

  /// Sales today widget caption: the same as yesterday
  ///
  /// In bn, this message translates to:
  /// **'গতকালের সমান'**
  String get launcherWidgetSalesSameAsYesterday;

  /// Sales today widget caption: up on yesterday by this percent
  ///
  /// In bn, this message translates to:
  /// **'▲ গতকালের চেয়ে {percent}% বেশি'**
  String launcherWidgetSalesUp(String percent);

  /// Sales today widget caption: down on yesterday by this percent
  ///
  /// In bn, this message translates to:
  /// **'▼ গতকালের চেয়ে {percent}% কম'**
  String launcherWidgetSalesDown(String percent);

  /// Sales rep board widget name
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়কর্মীর তালিকা'**
  String get launcherWidgetSrName;

  /// Sales rep board heading for a rep: my sales today
  ///
  /// In bn, this message translates to:
  /// **'আজ আমার বিক্রি'**
  String get launcherWidgetSrMine;

  /// Sales rep board row for a rep: your sales today
  ///
  /// In bn, this message translates to:
  /// **'আজ আপনার বিক্রি'**
  String get launcherWidgetSrYours;

  /// Sales rep board: your rank today
  ///
  /// In bn, this message translates to:
  /// **'আজ আপনি {rank} নম্বরে'**
  String launcherWidgetSrRank(String rank);

  /// Sales rep board: out of this many reps
  ///
  /// In bn, this message translates to:
  /// **'{of} জনের মধ্যে'**
  String launcherWidgetSrOutOf(String of);

  /// Sales rep board heading for a manager: top sales reps today
  ///
  /// In bn, this message translates to:
  /// **'আজ সবচেয়ে বেশি বিক্রি — বিক্রয়কর্মী'**
  String get launcherWidgetSrTitle;

  /// Sales rep board corner: the branch
  ///
  /// In bn, this message translates to:
  /// **'শাখা: {branch}'**
  String launcherWidgetSrBranch(String branch);

  /// Sales rep board: no sales yet today
  ///
  /// In bn, this message translates to:
  /// **'আজ এখনো কোনো বিক্রি নেই'**
  String get launcherWidgetSrEmpty;

  /// Stock alerts widget name
  ///
  /// In bn, this message translates to:
  /// **'মজুদ সতর্কতা'**
  String get launcherWidgetStockName;

  /// Stock alerts widget heading: stock running low
  ///
  /// In bn, this message translates to:
  /// **'মজুদ কমে গেছে'**
  String get launcherWidgetStockTitle;

  /// Stock alerts widget: all stock is above its reorder level
  ///
  /// In bn, this message translates to:
  /// **'সব মজুদ পুনঃক্রয়ের সীমার উপরে'**
  String get launcherWidgetStockEmpty;

  /// Stock alerts widget corner: all warehouses
  ///
  /// In bn, this message translates to:
  /// **'সব গুদাম'**
  String get launcherWidgetStockAllWarehouses;

  /// Stock alerts widget corner: one warehouse
  ///
  /// In bn, this message translates to:
  /// **'গুদাম: {warehouse}'**
  String launcherWidgetStockWarehouse(String warehouse);

  /// Sync state widget name
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্কের অবস্থা'**
  String get launcherWidgetSyncName;

  /// Sync state widget: syncing now
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক চলছে'**
  String get launcherWidgetSyncRunning;

  /// Sync state widget: no internet
  ///
  /// In bn, this message translates to:
  /// **'নেট নেই'**
  String get launcherWidgetSyncOffline;

  /// Sync state widget: the last sync failed
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক হয়নি'**
  String get launcherWidgetSyncFailed;

  /// Sync state widget: the last sync is old
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক পুরনো'**
  String get launcherWidgetSyncStale;

  /// Sync state widget: sync is fine
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক ঠিক আছে'**
  String get launcherWidgetSyncOk;

  /// Sync state widget: never synced yet
  ///
  /// In bn, this message translates to:
  /// **'শেষ সিঙ্ক: এখনো হয়নি'**
  String get launcherWidgetSyncNever;

  /// Sync state widget: how long ago the last sync was
  ///
  /// In bn, this message translates to:
  /// **'শেষ সিঙ্ক: {ago}'**
  String launcherWidgetSyncLast(String ago);

  /// Sync state widget: how many writes wait to be sent
  ///
  /// In bn, this message translates to:
  /// **'জমা কাজ: {count}'**
  String launcherWidgetSyncQueued(String count);

  /// Sync state widget: writes are kept while offline
  ///
  /// In bn, this message translates to:
  /// **'অফলাইনে জমা থাকে'**
  String get launcherWidgetSyncKeptOffline;

  /// Target widget name: target against achievement
  ///
  /// In bn, this message translates to:
  /// **'লক্ষ্য বনাম অর্জন'**
  String get launcherWidgetTargetName;

  /// Target widget heading: my own target this month
  ///
  /// In bn, this message translates to:
  /// **'এই মাসে আমার লক্ষ্য'**
  String get launcherWidgetTargetMineThisMonth;

  /// Target widget heading: this month's target
  ///
  /// In bn, this message translates to:
  /// **'এই মাসের লক্ষ্য'**
  String get launcherWidgetTargetThisMonth;

  /// Target widget heading: my own target for the named month
  ///
  /// In bn, this message translates to:
  /// **'{month}ে আমার লক্ষ্য'**
  String launcherWidgetTargetMineMonth(String month);

  /// Target widget heading: the target for the named month
  ///
  /// In bn, this message translates to:
  /// **'{month}ের লক্ষ্য'**
  String launcherWidgetTargetMonth(String month);

  /// Target widget: no target set this month
  ///
  /// In bn, this message translates to:
  /// **'এই মাসের লক্ষ্য বসানো নেই'**
  String get launcherWidgetTargetNotSet;

  /// Target widget caption: days left in the month
  ///
  /// In bn, this message translates to:
  /// **'বাকি {days} দিন'**
  String launcherWidgetTargetDaysLeft(String days);

  /// Target widget caption: the month is over
  ///
  /// In bn, this message translates to:
  /// **'মাস শেষ'**
  String get launcherWidgetTargetMonthOver;

  /// Target widget: a short amount in crore (ten million); the taka sign is added in code
  ///
  /// In bn, this message translates to:
  /// **'{amount} কোটি'**
  String launcherWidgetTargetCrore(String amount);

  /// Target widget: a short amount in lakh (hundred thousand); the taka sign is added in code
  ///
  /// In bn, this message translates to:
  /// **'{amount} লাখ'**
  String launcherWidgetTargetLakh(String amount);

  /// Old figures widget: this account may not see these figures
  ///
  /// In bn, this message translates to:
  /// **'এই অ্যাকাউন্টে এই হিসাব দেখানো হয় না'**
  String get launcherWidgetTodayNotForAccount;

  /// Old figures widget: the server does not have these figures yet
  ///
  /// In bn, this message translates to:
  /// **'এই হিসাব এখনো সার্ভারে নেই'**
  String get launcherWidgetTodayNotOnServer;
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
