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

  /// Owner home: the stock value figure beside the dues (IAS 2, at cost)
  ///
  /// In bn, this message translates to:
  /// **'মজুদের মূল্য'**
  String get homeStockValue;

  /// Owner home: small line under the stock value: valued at cost
  ///
  /// In bn, this message translates to:
  /// **'খরচ-দামে'**
  String get homeStockValueBasis;

  /// Screen reader hint: tapping the stock value opens the stock page
  ///
  /// In bn, this message translates to:
  /// **'মজুদের পাতা খুলুন'**
  String get homeStockValueOpenHint;

  /// Title of the stock page opened from the owner home
  ///
  /// In bn, this message translates to:
  /// **'মজুদ'**
  String get homeStockPageTitle;

  /// Module tile and bar crumb: sales
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়'**
  String get navModuleSales;

  /// Module tile and bar crumb: purchase
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়'**
  String get navModulePurchase;

  /// Module tile and bar crumb: inventory (stock)
  ///
  /// In bn, this message translates to:
  /// **'মজুদ'**
  String get navModuleInventory;

  /// Module tile and bar crumb: accounts
  ///
  /// In bn, this message translates to:
  /// **'হিসাব'**
  String get navModuleAccounts;

  /// Module tile and bar crumb: finance (bank, deposits, loans)
  ///
  /// In bn, this message translates to:
  /// **'অর্থ'**
  String get navModuleFinance;

  /// Module tile and bar crumb: customers
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক'**
  String get navModuleCustomer;

  /// Module tile and bar crumb: suppliers (principals)
  ///
  /// In bn, this message translates to:
  /// **'সরবরাহকারী'**
  String get navModuleSupplier;

  /// Module tile and bar crumb: approvals
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন'**
  String get navModuleApproval;

  /// Module tile and bar crumb: staff (HR)
  ///
  /// In bn, this message translates to:
  /// **'কর্মী'**
  String get navModuleHr;

  /// Module tile and bar crumb: reports
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট'**
  String get navModuleReports;

  /// Module tile and bar crumb: documents
  ///
  /// In bn, this message translates to:
  /// **'নথি'**
  String get navModuleDocuments;

  /// Module tile and bar crumb: the owner's centre
  ///
  /// In bn, this message translates to:
  /// **'মালিকের কেন্দ্র'**
  String get navModuleExecutive;

  /// Module tile and bar crumb: promotion (offers)
  ///
  /// In bn, this message translates to:
  /// **'প্রমোশন'**
  String get navModulePromotion;

  /// Module tile and bar crumb: credit control
  ///
  /// In bn, this message translates to:
  /// **'বাকি নিয়ন্ত্রণ'**
  String get navModuleCreditControl;

  /// Module tile and bar crumb: notices
  ///
  /// In bn, this message translates to:
  /// **'নোটিশ'**
  String get navModuleNotification;

  /// Heading inside a module's menu: transactions
  ///
  /// In bn, this message translates to:
  /// **'লেনদেন'**
  String get navGroupTransactions;

  /// Heading inside a module's menu: field work
  ///
  /// In bn, this message translates to:
  /// **'মাঠের কাজ'**
  String get navGroupFieldWork;

  /// Heading inside a module's menu: lists (master data)
  ///
  /// In bn, this message translates to:
  /// **'তালিকা'**
  String get navGroupLists;

  /// Heading inside a module's menu: the person's own work
  ///
  /// In bn, this message translates to:
  /// **'নিজের কাজ'**
  String get navGroupMyWork;

  /// Heading inside a module's menu: reports
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট'**
  String get navGroupReports;

  /// Heading inside a module's menu: dashboards
  ///
  /// In bn, this message translates to:
  /// **'ড্যাশবোর্ড'**
  String get navGroupDashboards;

  /// Heading inside a module's menu: anything else
  ///
  /// In bn, this message translates to:
  /// **'অন্যান্য'**
  String get navGroupOther;

  /// Heading inside a module's menu: the web's approval screens
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন'**
  String get navGroupApprovals;

  /// Heading inside a module's menu: the web's settings screens
  ///
  /// In bn, this message translates to:
  /// **'সেটিংস'**
  String get navGroupSettings;

  /// Heading inside a module's menu: rows that are not built yet
  ///
  /// In bn, this message translates to:
  /// **'শীঘ্রই আসছে'**
  String get navGroupComingSoon;

  /// Sales menu row that opens the delivery screens
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি ও লোডিং'**
  String get navParentDelivery;

  /// Menu row and bar crumb: write a new order
  ///
  /// In bn, this message translates to:
  /// **'নতুন অর্ডার'**
  String get navScreenNewOrder;

  /// Menu row and bar crumb: the orders and where they stand
  ///
  /// In bn, this message translates to:
  /// **'অর্ডারের অবস্থা'**
  String get navScreenOrders;

  /// Menu row and bar crumb: delivery orders (DO)
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি অর্ডার'**
  String get navScreenDeliveryOrders;

  /// Menu row: sales orders (the same tile when the company uses sales orders instead of DO)
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় আদেশ'**
  String get navScreenSalesOrders;

  /// Menu row and bar crumb: the counter (direct sale)
  ///
  /// In bn, this message translates to:
  /// **'সরাসরি বিক্রয়'**
  String get navScreenCounter;

  /// Menu row and bar crumb: quotations
  ///
  /// In bn, this message translates to:
  /// **'উদ্ধৃতি'**
  String get navScreenQuotations;

  /// Menu row and bar crumb: money collected from customers
  ///
  /// In bn, this message translates to:
  /// **'টাকা আদায়'**
  String get navScreenCollections;

  /// Menu row and bar crumb: today's deliveries
  ///
  /// In bn, this message translates to:
  /// **'আজকের ডেলিভারি'**
  String get navScreenDeliveries;

  /// Menu row and bar crumb: the loading sheet
  ///
  /// In bn, this message translates to:
  /// **'লোডিং শিট'**
  String get navScreenLoading;

  /// Menu row and bar crumb: delivery tracking
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি ট্র্যাকিং'**
  String get navScreenTracking;

  /// Menu row and bar crumb: scan a paper's QR code
  ///
  /// In bn, this message translates to:
  /// **'কাগজ স্ক্যান'**
  String get navScreenScan;

  /// Menu row and bar crumb: today's route and its shops
  ///
  /// In bn, this message translates to:
  /// **'আজকের রুট'**
  String get navScreenMyRoute;

  /// Menu row and bar crumb: leads (new shops found in the field)
  ///
  /// In bn, this message translates to:
  /// **'লিড'**
  String get navScreenLeads;

  /// Menu row and bar crumb: today's sales figures
  ///
  /// In bn, this message translates to:
  /// **'আজকের হিসাব'**
  String get navScreenToday;

  /// Menu row and bar crumb: purchases
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়'**
  String get navScreenPurchases;

  /// Menu row and bar crumb: principals (supplier companies)
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপাল'**
  String get navScreenPrincipals;

  /// Menu row and bar crumb: the principals' commission
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালের কমিশন'**
  String get navScreenPrincipalCommission;

  /// Menu row and bar crumb: the product list
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের তালিকা'**
  String get navScreenProducts;

  /// Menu row and bar crumb: stock on hand
  ///
  /// In bn, this message translates to:
  /// **'হাতে থাকা মজুদ'**
  String get navScreenStock;

  /// Menu row and bar crumb: vouchers
  ///
  /// In bn, this message translates to:
  /// **'ভাউচার'**
  String get navScreenVouchers;

  /// Menu tile: every voucher kind in one screen
  ///
  /// In bn, this message translates to:
  /// **'সব ভাউচার'**
  String get navScreenAllVouchers;

  /// Menu row and bar crumb: cash receipt voucher
  ///
  /// In bn, this message translates to:
  /// **'নগদ রসিদ'**
  String get navScreenCashReceipt;

  /// Menu row and bar crumb: mobile banking (MFS) receipt voucher
  ///
  /// In bn, this message translates to:
  /// **'MFS রসিদ'**
  String get navScreenMfsReceipt;

  /// Menu row and bar crumb: bank receipt voucher
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক রসিদ'**
  String get navScreenBankReceipt;

  /// Menu row and bar crumb: payment voucher
  ///
  /// In bn, this message translates to:
  /// **'পেমেন্ট'**
  String get navScreenPayment;

  /// Menu row and bar crumb: contra voucher
  ///
  /// In bn, this message translates to:
  /// **'কন্ট্রা'**
  String get navScreenContra;

  /// Menu row and bar crumb: journal voucher
  ///
  /// In bn, this message translates to:
  /// **'জার্নাল'**
  String get navScreenJournal;

  /// Menu row and bar crumb: expense voucher
  ///
  /// In bn, this message translates to:
  /// **'খরচ'**
  String get navScreenExpense;

  /// Menu row and bar crumb: the customer list
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক'**
  String get navScreenCustomers;

  /// Menu row and bar crumb: customers' dues
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া তালিকা'**
  String get navScreenDues;

  /// Menu row and bar crumb: the approvals inbox
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন'**
  String get navScreenApprovals;

  /// Menu row and bar crumb: the person's own attendance
  ///
  /// In bn, this message translates to:
  /// **'হাজিরা'**
  String get navScreenAttendance;

  /// Menu row and bar crumb: the person's own expense claims
  ///
  /// In bn, this message translates to:
  /// **'খরচের দাবি'**
  String get navScreenClaims;

  /// Menu row and bar crumb: the reports list
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট'**
  String get navScreenReports;

  /// Menu row and bar crumb: the business dashboards
  ///
  /// In bn, this message translates to:
  /// **'ব্যবসার ড্যাশবোর্ড'**
  String get navScreenDashboards;

  /// Menu row and bar crumb: deposits (FDR, DPS)
  ///
  /// In bn, this message translates to:
  /// **'জমা ও সঞ্চয়'**
  String get navScreenDeposits;

  /// Menu row and bar crumb: bank loans and their instalments
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক ঋণ ও কিস্তি'**
  String get navScreenBankLoans;

  /// Menu row and bar crumb: rents (paid and received)
  ///
  /// In bn, this message translates to:
  /// **'ভাড়া'**
  String get navScreenRents;

  /// Menu row and bar crumb: hand loans (informal loans)
  ///
  /// In bn, this message translates to:
  /// **'হাতধার'**
  String get navScreenHandLoans;

  /// Menu row and bar crumb: the document library
  ///
  /// In bn, this message translates to:
  /// **'ডকুমেন্টস'**
  String get navScreenDocuments;

  /// Menu row and bar crumb: the offers running now
  ///
  /// In bn, this message translates to:
  /// **'চলতি অফার'**
  String get navScreenOffers;

  /// Menu row and bar crumb: the person's own leave
  ///
  /// In bn, this message translates to:
  /// **'আমার ছুটি'**
  String get navScreenLeave;

  /// Menu row and bar crumb: the person's own payslips
  ///
  /// In bn, this message translates to:
  /// **'বেতনের স্লিপ'**
  String get navScreenPayslips;

  /// Menu row and bar crumb: sales invoices
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় বিল'**
  String get navScreenInvoices;

  /// Screen title: the cancelled sales invoices
  ///
  /// In bn, this message translates to:
  /// **'বাতিল বিক্রয় বিল'**
  String get navScreenCancelledInvoices;

  /// Menu row and bar crumb: delivery challans
  ///
  /// In bn, this message translates to:
  /// **'চালান'**
  String get navScreenChallans;

  /// Menu row and bar crumb: the owner's view of today across every company
  ///
  /// In bn, this message translates to:
  /// **'আজ — গোটা গ্রুপ'**
  String get navScreenOwnerCentre;

  /// Menu row and bar crumb: the credit-control dashboard
  ///
  /// In bn, this message translates to:
  /// **'বাকির হিসাব'**
  String get navScreenCredit;

  /// Menu row and bar crumb: the notice board
  ///
  /// In bn, this message translates to:
  /// **'নোটিশ বোর্ড'**
  String get navScreenNotices;

  /// Menu row and bar crumb: what this phone has and has not sent
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্কের অবস্থা'**
  String get navScreenSyncStatus;

  /// Screen name: the notifications (bell)
  ///
  /// In bn, this message translates to:
  /// **'বিজ্ঞপ্তি'**
  String get navScreenNotifications;

  /// Paper kind name: purchase order
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়াদেশ'**
  String get paperKindPurchaseOrder;

  /// Paper kind name: purchase bill
  ///
  /// In bn, this message translates to:
  /// **'ক্রয় বিল'**
  String get paperKindPurchaseBill;

  /// Paper kind name: goods received note
  ///
  /// In bn, this message translates to:
  /// **'মাল গ্রহণ'**
  String get paperKindGoodsReceipt;

  /// Paper kind name: purchase return
  ///
  /// In bn, this message translates to:
  /// **'ক্রয় ফেরত'**
  String get paperKindPurchaseReturn;

  /// Paper kind name: purchase requisition
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়ের চাহিদা'**
  String get paperKindRequisition;

  /// Paper kind name: request for quotation (RFQ)
  ///
  /// In bn, this message translates to:
  /// **'দর চাওয়া'**
  String get paperKindRfq;

  /// Paper kind name: supply contract
  ///
  /// In bn, this message translates to:
  /// **'সরবরাহের চুক্তি'**
  String get paperKindSupplyContract;

  /// Paper kind name: sales return
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় ফেরত'**
  String get paperKindSalesReturn;

  /// Paper kind name: gate pass
  ///
  /// In bn, this message translates to:
  /// **'গেট পাস'**
  String get paperKindGatePass;

  /// Paper kind name: a delivery trip (shipment)
  ///
  /// In bn, this message translates to:
  /// **'ট্রিপ'**
  String get paperKindTrip;

  /// Paper kind name: loading sheet
  ///
  /// In bn, this message translates to:
  /// **'লোডিং শিট'**
  String get paperKindLoadingSheet;

  /// Paper kind name: transport
  ///
  /// In bn, this message translates to:
  /// **'পরিবহন'**
  String get paperKindTransport;

  /// Paper kind name: a price list for one customer
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহকভিত্তিক দাম'**
  String get paperKindPriceCustomer;

  /// Paper kind name: a price list for a customer tier
  ///
  /// In bn, this message translates to:
  /// **'ধরনভিত্তিক দাম'**
  String get paperKindPriceTier;

  /// Paper kind name: a price list for a territory
  ///
  /// In bn, this message translates to:
  /// **'এলাকাভিত্তিক দাম'**
  String get paperKindPriceTerritory;

  /// Paper kind name: a special price list for every customer
  ///
  /// In bn, this message translates to:
  /// **'বিশেষ দাম — সবার জন্য'**
  String get paperKindPriceAll;

  /// Paper kind name: stock transfer
  ///
  /// In bn, this message translates to:
  /// **'মাল স্থানান্তর'**
  String get paperKindStockTransfer;

  /// Paper kind name: inter-company transaction
  ///
  /// In bn, this message translates to:
  /// **'আন্তঃকোম্পানি লেনদেন'**
  String get paperKindInterCompany;

  /// Paper kind name: capital
  ///
  /// In bn, this message translates to:
  /// **'মূলধন'**
  String get paperKindCapital;

  /// Paper kind name: insurance policy
  ///
  /// In bn, this message translates to:
  /// **'বীমা পলিসি'**
  String get paperKindInsurance;

  /// Paper kind name when the kind is unknown
  ///
  /// In bn, this message translates to:
  /// **'কাগজ'**
  String get paperKindFallback;

  /// Recent papers: the kind of a delivery order
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি অর্ডার'**
  String get paperKindDeliveryOrder;

  /// Recent papers: the kind of a sales order
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় আদেশ'**
  String get paperKindSalesOrder;

  /// Recent papers: the kind of a quotation
  ///
  /// In bn, this message translates to:
  /// **'উদ্ধৃতি'**
  String get paperKindQuotation;

  /// Recent papers: the kind of a money collection
  ///
  /// In bn, this message translates to:
  /// **'টাকা আদায়'**
  String get paperKindCollection;

  /// Recent papers: the kind of a purchase
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়'**
  String get paperKindPurchase;

  /// Recent papers: the kind of a voucher
  ///
  /// In bn, this message translates to:
  /// **'ভাউচার'**
  String get paperKindVoucher;

  /// Recent papers: the kind of an expense claim
  ///
  /// In bn, this message translates to:
  /// **'খরচের দাবি'**
  String get paperKindClaim;

  /// Recent papers: the kind of a deposit
  ///
  /// In bn, this message translates to:
  /// **'জমা'**
  String get paperKindDeposit;

  /// Recent papers: the kind of a bank loan
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক ঋণ'**
  String get paperKindBankLoan;

  /// Recent papers: the kind of a rent
  ///
  /// In bn, this message translates to:
  /// **'ভাড়া'**
  String get paperKindRent;

  /// Recent papers: the kind of a hand loan
  ///
  /// In bn, this message translates to:
  /// **'হাতধার'**
  String get paperKindHandLoan;

  /// Recent papers: the kind of a document
  ///
  /// In bn, this message translates to:
  /// **'ডকুমেন্ট'**
  String get paperKindDocument;

  /// Recent papers: the kind of a payslip
  ///
  /// In bn, this message translates to:
  /// **'বেতনের স্লিপ'**
  String get paperKindPayslip;

  /// Recent papers: the kind of a sales invoice
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় বিল'**
  String get paperKindInvoice;

  /// Recent papers: the kind of a delivery challan
  ///
  /// In bn, this message translates to:
  /// **'চালান'**
  String get paperKindChallan;

  /// Bottom bar tab: home
  ///
  /// In bn, this message translates to:
  /// **'হোম'**
  String get navTabHome;

  /// Bottom bar tab: approvals
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন'**
  String get navTabApprovals;

  /// Bottom bar tab: reports
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট'**
  String get navTabReports;

  /// Bottom bar tab: orders
  ///
  /// In bn, this message translates to:
  /// **'অর্ডার'**
  String get navTabOrders;

  /// Bottom bar tab: collections
  ///
  /// In bn, this message translates to:
  /// **'আদায়'**
  String get navTabCollections;

  /// Bottom bar tab: delivery challans
  ///
  /// In bn, this message translates to:
  /// **'চালান'**
  String get navTabChallans;

  /// Bottom bar tab: stock
  ///
  /// In bn, this message translates to:
  /// **'মজুদ'**
  String get navTabStock;

  /// Bottom bar tab: vouchers
  ///
  /// In bn, this message translates to:
  /// **'ভাউচার'**
  String get navTabVouchers;

  /// Bottom bar tab: every module
  ///
  /// In bn, this message translates to:
  /// **'সব মডিউল'**
  String get navTabModules;

  /// Screen reader: the word after a tab's red count, as in 'Approvals, 5 waiting'
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায়'**
  String get navTabWaiting;

  /// Screen reader: a tab with a dot (something new, count unknown)
  ///
  /// In bn, this message translates to:
  /// **'{label}, নতুন {waiting}'**
  String navTabSpokenNew(String label, String waiting);

  /// Screen reader: a tab's red count; shown is already written in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'{shown}টা'**
  String navTabBadgeCount(String shown);

  /// Screen reader: a tab's red count over the limit; shown is already written in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'{shown}টার বেশি'**
  String navTabBadgeOver(String shown);

  /// Home's + button: opens the list of things to create
  ///
  /// In bn, this message translates to:
  /// **'নতুন'**
  String get navFabNew;

  /// + button: write a new order
  ///
  /// In bn, this message translates to:
  /// **'নতুন অর্ডার'**
  String get navFabNewOrder;

  /// + button: write a new collection
  ///
  /// In bn, this message translates to:
  /// **'নতুন আদায়'**
  String get navFabNewCollection;

  /// + button: write a new expense claim
  ///
  /// In bn, this message translates to:
  /// **'নতুন দাবি'**
  String get navFabNewClaim;

  /// + button: write a new expense voucher
  ///
  /// In bn, this message translates to:
  /// **'নতুন খরচ'**
  String get navFabNewExpense;

  /// + button: write a new voucher
  ///
  /// In bn, this message translates to:
  /// **'নতুন ভাউচার'**
  String get navFabNewVoucher;

  /// Home's + list: write an expense claim
  ///
  /// In bn, this message translates to:
  /// **'খরচের দাবি'**
  String get navFabExpenseClaim;

  /// Top bar: the back button
  ///
  /// In bn, this message translates to:
  /// **'পিছনে'**
  String get topBarBack;

  /// Top bar: the button that opens the whole menu
  ///
  /// In bn, this message translates to:
  /// **'পুরো মেনু'**
  String get topBarFullMenu;

  /// Top bar: the search button
  ///
  /// In bn, this message translates to:
  /// **'খোঁজা'**
  String get topBarSearch;

  /// Top bar: the person's own menu (avatar)
  ///
  /// In bn, this message translates to:
  /// **'আমার মেনু'**
  String get topBarMyMenu;

  /// Screen reader: what tapping the company and branch title does
  ///
  /// In bn, this message translates to:
  /// **'শাখা বদল'**
  String get topBarSwitchBranch;

  /// Top bar: the mark on a demo server
  ///
  /// In bn, this message translates to:
  /// **'ডেমো'**
  String get topBarDemo;

  /// Top bar: the overflow menu of a screen's own buttons
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get topBarMore;

  /// Screen reader: the branch's name in the title
  ///
  /// In bn, this message translates to:
  /// **'{branch} শাখা'**
  String topBarBranchSpoken(String branch);

  /// Screen reader: the title on a demo server
  ///
  /// In bn, this message translates to:
  /// **'ডেমো সার্ভার, আসল খাতা নয়'**
  String get topBarDemoSpoken;

  /// Offline ribbon with papers waiting; shown is the count in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{অফলাইন: {shown}টা কাগজ পাঠানো বাকি, নেট এলে নিজেই যাবে}}'**
  String topBarOfflinePending(int count, String shown);

  /// Offline ribbon with nothing waiting
  ///
  /// In bn, this message translates to:
  /// **'অফলাইন: নেট নেই, যা লিখবেন তা নেট এলে নিজেই যাবে'**
  String get topBarOfflineNoNet;

  /// Dialog before switching branch or company with an unsaved form: title
  ///
  /// In bn, this message translates to:
  /// **'না রেখে বদলাবেন?'**
  String get topBarUnsavedTitle;

  /// Dialog before switching branch with an unsaved form: body
  ///
  /// In bn, this message translates to:
  /// **'একটা ফর্মে যা লিখেছেন তা এখনো রাখা হয়নি। শাখা বদলালে ওই লেখা হারিয়ে যাবে।'**
  String get topBarUnsavedBody;

  /// Dialog before switching branch with an unsaved form: stay
  ///
  /// In bn, this message translates to:
  /// **'থাকুন'**
  String get topBarUnsavedStay;

  /// Dialog before switching branch with an unsaved form: switch anyway
  ///
  /// In bn, this message translates to:
  /// **'না রেখে বদলান'**
  String get topBarUnsavedGo;

  /// A screen whose module the company switched off for the phone: title
  ///
  /// In bn, this message translates to:
  /// **'এই অংশটা এখন বন্ধ'**
  String get moduleGateOffTitle;

  /// A screen whose module the company switched off for the phone: what to do
  ///
  /// In bn, this message translates to:
  /// **'আপনার কোম্পানি অ্যাপে এই অংশটা বন্ধ রেখেছে। দরকার হলে প্রশাসককে বলুন কন্ট্রোল প্যানেলের \"মোবাইল অ্যাপ\" ট্যাব থেকে চালু করতে।'**
  String get moduleGateOffMessage;

  /// Before Android's own camera question: title
  ///
  /// In bn, this message translates to:
  /// **'ক্যামেরার অনুমতি'**
  String get permissionCameraTitle;

  /// Before Android's own camera question: why
  ///
  /// In bn, this message translates to:
  /// **'QR পড়তে, স্লিপ বা রসিদের ছবি আর নিজের প্রোফাইলের ছবি তুলতে ABOS ফোনের ক্যামেরা ব্যবহার করবে। এরপর ফোন ক্যামেরার অনুমতি চাইবে — \"অনুমতি দিন\" চাপুন। আপনি না চাপলে ক্যামেরা চালু হয় না, আর কেবল আপনার পাঠানো ছবিটাই অফিসে যায়।'**
  String get permissionCameraBody;

  /// Before Android's own notification question: title
  ///
  /// In bn, this message translates to:
  /// **'বার্তার অনুমতি'**
  String get permissionNotificationsTitle;

  /// Before Android's own notification question: why
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন চাওয়া, অনুমোদন আর ডেলিভারির খবর অ্যাপ বন্ধ থাকলেও জানাতে ABOS বার্তা পাঠাবে। এরপর ফোন বার্তার অনুমতি চাইবে — \"অনুমতি দিন\" চাপুন। না দিলেও অ্যাপ চলবে, কেবল খবরগুলো অ্যাপ খুললে তবে দেখবেন।'**
  String get permissionNotificationsBody;

  /// Permission explanation: do not ask now
  ///
  /// In bn, this message translates to:
  /// **'এখন না'**
  String get permissionNotNow;

  /// Permission explanation: go on to the phone's question
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get permissionOk;

  /// Device check: rooted and an emulator
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনটা root করা, আর আসল ফোন নয় (emulator) বলে মনে হচ্ছে'**
  String get deviceIntegrityRootedEmulator;

  /// Device check: rooted
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনটা root করা বলে মনে হচ্ছে'**
  String get deviceIntegrityRooted;

  /// Device check: an emulator
  ///
  /// In bn, this message translates to:
  /// **'এটা আসল ফোন নয় (emulator) বলে মনে হচ্ছে'**
  String get deviceIntegrityEmulator;

  /// Device check: the warning; what is one of the deviceIntegrity sentences
  ///
  /// In bn, this message translates to:
  /// **'{what}। এমন ফোনে অন্য অ্যাপ ABOS-এর তথ্য পড়তে পারে — টাকার অঙ্ক আর গ্রাহকের তথ্য ঝুঁকিতে থাকে। অ্যাপ চলবে, তবে অফিসকে জানান।'**
  String deviceIntegrityWarning(String what);

  /// App lock delay choice: lock at once
  ///
  /// In bn, this message translates to:
  /// **'সাথে সাথে'**
  String get lockDelayNow;

  /// App lock delay choice in minutes; shown is the count in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{shown} মিনিট}}'**
  String lockDelayMinutes(int count, String shown);

  /// Setting a PIN: the PIN has the wrong length; min and max are in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'PIN {min} থেকে {max}টা অঙ্ক।'**
  String lockScreenPinLength(String min, String max);

  /// Setting a PIN: the two PINs differ
  ///
  /// In bn, this message translates to:
  /// **'দুইবার একই PIN লিখুন।'**
  String get lockScreenPinTwice;

  /// Lock screen: too many wrong PINs
  ///
  /// In bn, this message translates to:
  /// **'পাঁচবার ভুল PIN — আবার পাসওয়ার্ড দিয়ে ঢুকুন।'**
  String get lockScreenTooManyTries;

  /// Lock screen: a wrong PIN and the tries left; shown is the count in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{PIN মেলেনি — আর {shown}বার চেষ্টা করা যাবে।}}'**
  String lockScreenPinWrong(int count, String shown);

  /// The phone's own unlock prompt: why
  ///
  /// In bn, this message translates to:
  /// **'ABOS খুলতে আঙুলের ছাপ বা ফোনের PIN দিন'**
  String get lockScreenBiometricReason;

  /// The phone's own unlock prompt: title
  ///
  /// In bn, this message translates to:
  /// **'ABOS খুলুন'**
  String get lockScreenBiometricTitle;

  /// The phone's own unlock prompt: cancel
  ///
  /// In bn, this message translates to:
  /// **'থাক'**
  String get lockScreenBiometricCancel;

  /// Lock screen: enter the app PIN
  ///
  /// In bn, this message translates to:
  /// **'অ্যাপের PIN দিন'**
  String get lockScreenEnterPin;

  /// Lock screen: sign out
  ///
  /// In bn, this message translates to:
  /// **'বেরিয়ে যান'**
  String get lockScreenSignOut;

  /// Lock screen with no phone lock and no app PIN: title
  ///
  /// In bn, this message translates to:
  /// **'আবার পাসওয়ার্ড দিয়ে ঢুকুন'**
  String get lockScreenSignInAgainTitle;

  /// Lock screen with no phone lock and no app PIN: why
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনে তালা নেই, আর অ্যাপের PIN-ও বসানো হয়নি — কে ধরে আছেন জানার উপায় নেই। ঢুকলেই PIN বসাতে বলা হবে।'**
  String get lockScreenSignInAgainBody;

  /// Lock screen with no phone lock and no app PIN: button
  ///
  /// In bn, this message translates to:
  /// **'বেরিয়ে আবার ঢুকুন'**
  String get lockScreenSignOutAndIn;

  /// Set a PIN after signing in on a phone with no lock: title
  ///
  /// In bn, this message translates to:
  /// **'অ্যাপের PIN বসান'**
  String get lockScreenSetPinTitle;

  /// Set a PIN on a phone with no lock: why; min and max are in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনে তালা নেই। অনুমোদন আর টাকার অঙ্ক রক্ষায় {min}–{max} অঙ্কের একটা PIN বসান — ফিরে এলে এটাই চাওয়া হবে।'**
  String lockScreenSetPinBody(String min, String max);

  /// Set a PIN: the first field
  ///
  /// In bn, this message translates to:
  /// **'নতুন PIN'**
  String get lockScreenNewPin;

  /// Set a PIN: the second field
  ///
  /// In bn, this message translates to:
  /// **'আবার একই PIN'**
  String get lockScreenPinAgain;

  /// Set a PIN: save it
  ///
  /// In bn, this message translates to:
  /// **'সংরক্ষণ'**
  String get lockScreenSave;

  /// Lock screen: title
  ///
  /// In bn, this message translates to:
  /// **'অ্যাপ তালাবদ্ধ'**
  String get lockScreenLockedTitle;

  /// Lock screen: how to open
  ///
  /// In bn, this message translates to:
  /// **'আঙুলের ছাপ বা ফোনের PIN দিয়ে খুলুন।'**
  String get lockScreenLockedBody;

  /// Any request that failed for want of a network
  ///
  /// In bn, this message translates to:
  /// **'সংযোগ নেই। ইন্টারনেট চেক করে আবার চেষ্টা করুন।'**
  String get networkErrorNoConnection;

  /// Sign-in: the server answered without the tokens
  ///
  /// In bn, this message translates to:
  /// **'সার্ভার থেকে অসম্পূর্ণ উত্তর এসেছে। আবার চেষ্টা করুন।'**
  String get authIncompleteAnswer;

  /// Sign-in failed for another reason
  ///
  /// In bn, this message translates to:
  /// **'ঢোকা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।'**
  String get authSignInFailed;

  /// Sign-in screen greeting before noon (Dhaka time)
  ///
  /// In bn, this message translates to:
  /// **'শুভ সকাল'**
  String get authLoginGreetingMorning;

  /// Sign-in screen greeting from noon to 5 pm (Dhaka time)
  ///
  /// In bn, this message translates to:
  /// **'শুভ দুপুর'**
  String get authLoginGreetingAfternoon;

  /// Sign-in screen greeting after 5 pm (Dhaka time)
  ///
  /// In bn, this message translates to:
  /// **'শুভ সন্ধ্যা'**
  String get authLoginGreetingEvening;

  /// Sign-in screen: the line under the greeting
  ///
  /// In bn, this message translates to:
  /// **'আজকের হিসাব আপনার অপেক্ষায়'**
  String get authLoginSubtitle;

  /// Sign-in screen: the identifier field
  ///
  /// In bn, this message translates to:
  /// **'লগইন আইডি (নাম বা ইমেইল)'**
  String get authLoginIdentifier;

  /// Sign-in screen: the identifier is empty
  ///
  /// In bn, this message translates to:
  /// **'নাম বা ইমেইল দিন'**
  String get authLoginIdentifierMissing;

  /// Sign-in screen: the password field
  ///
  /// In bn, this message translates to:
  /// **'পাসওয়ার্ড'**
  String get authLoginPassword;

  /// Sign-in screen: the password is empty
  ///
  /// In bn, this message translates to:
  /// **'পাসওয়ার্ড দিন'**
  String get authLoginPasswordMissing;

  /// Sign-in screen: the sign-in button
  ///
  /// In bn, this message translates to:
  /// **'প্রবেশ করুন'**
  String get authLoginSignIn;

  /// Sign-in screen: the button that sends the MFA code
  ///
  /// In bn, this message translates to:
  /// **'যাচাই করুন'**
  String get authLoginVerify;

  /// Sign-in screen: back from the MFA step
  ///
  /// In bn, this message translates to:
  /// **'ফিরে যান'**
  String get authLoginBack;

  /// Sign-in screen: remember the identifier
  ///
  /// In bn, this message translates to:
  /// **'মনে রাখুন'**
  String get authLoginRemember;

  /// Sign-in screen: forgot password link
  ///
  /// In bn, this message translates to:
  /// **'পাসওয়ার্ড ভুলে গেছেন?'**
  String get authLoginForgot;

  /// Sign-in screen: forgot password dialog body
  ///
  /// In bn, this message translates to:
  /// **'নিচের বোতাম চাপলে ব্রাউজারে পাসওয়ার্ড বদলের পাতা খুলবে — ইমেইল দিলে লিংক আসবে। ইমেইল না থাকলে কোম্পানির প্রশাসককে বলুন।'**
  String get authLoginForgotBody;

  /// Sign-in screen: forgot password dialog button
  ///
  /// In bn, this message translates to:
  /// **'ইমেইলে লিংক নিন'**
  String get authLoginForgotOpen;

  /// Sign-in screen: close a dialog
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get authLoginOk;

  /// Sign-in screen: the MFA step; n is the code's length in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'আপনার অথেনটিকেটর অ্যাপ থেকে {n}-সংখ্যার কোডটি দিন।'**
  String authLoginMfaPrompt(String n);

  /// Sign-in screen: the MFA code was wrong
  ///
  /// In bn, this message translates to:
  /// **'কোডটি ভুল ছিল — আবার চেষ্টা করুন।'**
  String get authLoginMfaWrong;

  /// Sign-in screen: the MFA code field
  ///
  /// In bn, this message translates to:
  /// **'এমএফএ কোড'**
  String get authLoginMfaLabel;

  /// Sign-in screen: the MFA code is empty; n is the code's length in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'{n}-সংখ্যার কোডটি দিন'**
  String authLoginMfaMissing(String n);

  /// Sign-in screen footer: the app's version
  ///
  /// In bn, this message translates to:
  /// **'সংস্করণ'**
  String get authLoginVersion;

  /// Sign-in screen: hide the password
  ///
  /// In bn, this message translates to:
  /// **'পাসওয়ার্ড লুকান'**
  String get authLoginHidePassword;

  /// Sign-in screen: show the password
  ///
  /// In bn, this message translates to:
  /// **'পাসওয়ার্ড দেখুন'**
  String get authLoginShowPassword;

  /// Avatar menu's first row when the server can change the photo
  ///
  /// In bn, this message translates to:
  /// **'প্রোফাইল ও ছবি বদল'**
  String get profileMenuProfileAndPhoto;

  /// Avatar menu's first row otherwise
  ///
  /// In bn, this message translates to:
  /// **'আমার প্রোফাইল'**
  String get profileMenuMyProfile;

  /// A web screen inside the app could not get its key
  ///
  /// In bn, this message translates to:
  /// **'পাতাটা খোলা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।'**
  String get webInsidePageFailed;

  /// A web screen inside the app: the two-step code was wrong
  ///
  /// In bn, this message translates to:
  /// **'কোডটা মেলেনি — আবার দিন।'**
  String get webInsideCodeWrong;

  /// A web screen inside the app needs the two-step code
  ///
  /// In bn, this message translates to:
  /// **'দুই ধাপের কোডটা দিন।'**
  String get webInsideCodeNeeded;

  /// A web screen inside the app: the server is too old
  ///
  /// In bn, this message translates to:
  /// **'সার্ভার এখনো এই সুবিধা পায়নি — অফিসকে সার্ভার হালনাগাদ করতে বলুন।'**
  String get webInsideServerTooOld;

  /// A web screen inside the app: unknown address
  ///
  /// In bn, this message translates to:
  /// **'এই পর্দার ঠিকানাটা চেনা গেল না — মেনু থেকে আবার খুলুন।'**
  String get webInsideBadAddress;

  /// A web screen inside the app: the code dialog was closed
  ///
  /// In bn, this message translates to:
  /// **'দুই ধাপের কোড ছাড়া ওয়েবের পর্দা খোলে না — ওয়েবের লগইনের মতোই।'**
  String get webInsideNeedsCode;

  /// A web screen inside the app: the code dialog title
  ///
  /// In bn, this message translates to:
  /// **'দুই ধাপের কোড'**
  String get webInsideCodeTitle;

  /// A web screen inside the app: the code field hint; n is in the reader's digits
  ///
  /// In bn, this message translates to:
  /// **'অথেনটিকেটর অ্যাপের {n} অঙ্ক'**
  String webInsideCodeHint(String n);

  /// A web screen inside the app: the page failed to load
  ///
  /// In bn, this message translates to:
  /// **'পাতাটা আনা গেল না — সংযোগ দেখে আবার চেষ্টা করুন।'**
  String get webInsideLoadFailed;

  /// A web screen inside the app: downloading a file
  ///
  /// In bn, this message translates to:
  /// **'ফাইল আনা হচ্ছে…'**
  String get webInsideFileFetching;

  /// A web screen inside the app: download failed
  ///
  /// In bn, this message translates to:
  /// **'ফাইলটা আনা গেল না — আবার চেষ্টা করুন।'**
  String get webInsideFileFetchFailed;

  /// A web screen inside the app: the file could not be written
  ///
  /// In bn, this message translates to:
  /// **'ফাইলটা রাখা গেল না — ফোনে জায়গা আছে কি না দেখুন।'**
  String get webInsideFileSaveFailed;

  /// A web screen inside the app: share or save a downloaded file
  ///
  /// In bn, this message translates to:
  /// **'পাঠান বা সেভ করুন'**
  String get webInsideFileShare;

  /// A web screen inside the app: print failed
  ///
  /// In bn, this message translates to:
  /// **'ছাপা গেল না — পাতাটা পুরো আসার পরে আবার চেষ্টা করুন।'**
  String get webInsidePrintFailed;

  /// A web screen inside the app: attach a new photo
  ///
  /// In bn, this message translates to:
  /// **'ছবি তুলুন'**
  String get webInsideTakePhoto;

  /// A web screen inside the app: attach a photo from the gallery
  ///
  /// In bn, this message translates to:
  /// **'গ্যালারি থেকে'**
  String get webInsideFromGallery;

  /// A web screen inside the app: attach a file
  ///
  /// In bn, this message translates to:
  /// **'ফাইল বাছুন (PDF, এক্সেল…)'**
  String get webInsidePickFile;

  /// A web screen inside the app: a file's default name
  ///
  /// In bn, this message translates to:
  /// **'ফাইল'**
  String get webInsideFile;

  /// A web screen inside the app: attaching failed
  ///
  /// In bn, this message translates to:
  /// **'ফাইলটা নেওয়া গেল না — আবার চেষ্টা করুন।'**
  String get webInsideFilePickFailed;

  /// A web screen inside the app: the page's alert or prompt, OK
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get webInsideOk;

  /// A web screen inside the app: the page's confirm, no
  ///
  /// In bn, this message translates to:
  /// **'না'**
  String get webInsideNo;

  /// A web screen inside the app: the page's confirm, yes
  ///
  /// In bn, this message translates to:
  /// **'হ্যাঁ'**
  String get webInsideYes;

  /// A web screen inside the app: close it
  ///
  /// In bn, this message translates to:
  /// **'বন্ধ করুন'**
  String get webInsideClose;

  /// A web screen inside the app: print the page
  ///
  /// In bn, this message translates to:
  /// **'ছাপুন বা PDF'**
  String get webInsidePrint;

  /// A web screen inside the app: reload the page
  ///
  /// In bn, this message translates to:
  /// **'নতুন করে আনুন'**
  String get webInsideReload;

  /// A web screen inside the app: error title
  ///
  /// In bn, this message translates to:
  /// **'পাতাটা খোলা গেল না'**
  String get webInsideOpenFailedTitle;

  /// A web screen inside the app: the page's prompt, cancel
  ///
  /// In bn, this message translates to:
  /// **'থাক'**
  String get webInsideCancel;

  /// Button: no / cancel a yes-no question
  ///
  /// In bn, this message translates to:
  /// **'না'**
  String get commonNo;

  /// Button: load or show more rows
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get commonMore;

  /// Filter choice: everything, no filter
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get commonAll;

  /// Label: the state of a paper or a row
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get commonStatus;

  /// Label: a company branch
  ///
  /// In bn, this message translates to:
  /// **'শাখা'**
  String get commonBranch;

  /// Error title: the list or page could not be fetched
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get commonLoadFailed;

  /// Button: sign (approve) a paper — fe: the action is "সই দিন"
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দিন'**
  String get commonSign;

  /// Voucher type name when the server sends none: receipt
  ///
  /// In bn, this message translates to:
  /// **'আদায় ভাউচার'**
  String get voucherTypeReceipt;

  /// Voucher type name: payment
  ///
  /// In bn, this message translates to:
  /// **'পরিশোধ ভাউচার'**
  String get voucherTypePayment;

  /// Voucher type name: expense
  ///
  /// In bn, this message translates to:
  /// **'খরচ ভাউচার'**
  String get voucherTypeExpense;

  /// Voucher type name: journal
  ///
  /// In bn, this message translates to:
  /// **'জাবেদা ভাউচার'**
  String get voucherTypeJournal;

  /// Voucher type name: contra (cash to bank and back)
  ///
  /// In bn, this message translates to:
  /// **'কন্ট্রা ভাউচার'**
  String get voucherTypeContra;

  /// Voucher list card: the money account the money left
  ///
  /// In bn, this message translates to:
  /// **'থেকে: {account}'**
  String voucherLineFrom(String account);

  /// Voucher list card: the money account the money went into
  ///
  /// In bn, this message translates to:
  /// **'জমা: {account}'**
  String voucherLineInto(String account);

  /// Contra voucher page: label of the account the money left
  ///
  /// In bn, this message translates to:
  /// **'কোথা থেকে'**
  String get voucherFactFrom;

  /// Contra voucher page: label of the account the money went to
  ///
  /// In bn, this message translates to:
  /// **'কোথায় গেল'**
  String get voucherFactTo;

  /// Collection voucher page: label of the account the money was put in
  ///
  /// In bn, this message translates to:
  /// **'জমা হয়েছে'**
  String get voucherFactDepositedTo;

  /// Payment or expense voucher page: label of the account the money was paid from
  ///
  /// In bn, this message translates to:
  /// **'যেখান থেকে দেওয়া'**
  String get voucherFactPaidFrom;

  /// Voucher page: label of the cash, bank or MFS account when the kind is unknown
  ///
  /// In bn, this message translates to:
  /// **'টাকার খাত'**
  String get voucherFactMoneyAccount;

  /// Collection voucher: label of the party who paid
  ///
  /// In bn, this message translates to:
  /// **'কার কাছ থেকে'**
  String get voucherPartyReceivedFrom;

  /// Payment or expense voucher: label of the party who was paid
  ///
  /// In bn, this message translates to:
  /// **'কাকে'**
  String get voucherPartyPaidTo;

  /// Voucher: label of the party when the kind is unknown
  ///
  /// In bn, this message translates to:
  /// **'পক্ষ'**
  String get voucherPartyParty;

  /// Voucher status when the server sends no label; anything unknown reads as draft
  ///
  /// In bn, this message translates to:
  /// **'{state, select, awaiting{অনুমোদনের অপেক্ষায়} posted{পোস্ট হয়েছে} cancelled{বাতিল} other{খসড়া}}'**
  String voucherState(String state);

  /// Voucher list filter button: every voucher
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get voucherViewAll;

  /// Voucher list filter button: collections into cash
  ///
  /// In bn, this message translates to:
  /// **'নগদ রসিদ'**
  String get voucherViewCashReceipts;

  /// Voucher list filter button: collections into a bank
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক রসিদ'**
  String get voucherViewBankReceipts;

  /// Voucher list filter button: payment vouchers
  ///
  /// In bn, this message translates to:
  /// **'পেমেন্ট'**
  String get voucherViewPayments;

  /// Voucher list filter button: collections into a mobile-money (MFS) account
  ///
  /// In bn, this message translates to:
  /// **'MFS রসিদ'**
  String get voucherViewMfsReceipts;

  /// Voucher list filter button: contra vouchers
  ///
  /// In bn, this message translates to:
  /// **'কন্ট্রা'**
  String get voucherViewContras;

  /// Voucher list filter button: journal vouchers
  ///
  /// In bn, this message translates to:
  /// **'জার্নাল'**
  String get voucherViewJournals;

  /// Voucher list filter button: expense vouchers
  ///
  /// In bn, this message translates to:
  /// **'খরচ'**
  String get voucherViewExpenses;

  /// Voucher list opened from the menu row: every collection voucher
  ///
  /// In bn, this message translates to:
  /// **'সব রসিদ'**
  String get voucherViewAllReceipts;

  /// Offer benefit: a percent discount; amount is the number as the server sends it
  ///
  /// In bn, this message translates to:
  /// **'{amount}% ছাড়'**
  String offerBenefitPercent(String amount);

  /// Offer benefit: a money discount; amount is already written as money (৳)
  ///
  /// In bn, this message translates to:
  /// **'{amount} ছাড়'**
  String offerBenefitAmount(String amount);

  /// Offer benefit: free pieces of the same goods; count as the server sends it
  ///
  /// In bn, this message translates to:
  /// **'{count}টা ফ্রি'**
  String offerBenefitFree(String count);

  /// Offer benefit: free pieces of another product (gift is its name)
  ///
  /// In bn, this message translates to:
  /// **'{count}টা {gift} ফ্রি'**
  String offerBenefitFreeGift(String count, String gift);

  /// Offer benefit: money given back as credit for the next purchase; amount is money
  ///
  /// In bn, this message translates to:
  /// **'{amount} ফেরত (পরের কেনায়)'**
  String offerBenefitCredit(String amount);

  /// Offer benefit: loyalty points
  ///
  /// In bn, this message translates to:
  /// **'{amount} পয়েন্ট'**
  String offerBenefitPoints(String amount);

  /// Offer benefit with a ceiling per bill; cap is money
  ///
  /// In bn, this message translates to:
  /// **'{benefit} (এক বিলে সর্বোচ্চ {cap})'**
  String offerBenefitCap(String benefit, String cap);

  /// Offer condition range with only a lower end, e.g. "10 or more"
  ///
  /// In bn, this message translates to:
  /// **'{from} বা বেশি'**
  String offerRangeOrMore(String from);

  /// Offer condition range with only an upper end
  ///
  /// In bn, this message translates to:
  /// **'{to} পর্যন্ত'**
  String offerRangeUpTo(String to);

  /// Offer condition: a count of pieces (Bengali adds the counting suffix)
  ///
  /// In bn, this message translates to:
  /// **'{count}টা'**
  String offerQuantityUnit(String count);

  /// Offer condition: buying this range or this product
  ///
  /// In bn, this message translates to:
  /// **'{what} কিনলে'**
  String offerWhenBuying(String what);

  /// Offer condition: a quantity step with no range
  ///
  /// In bn, this message translates to:
  /// **'যেকোনো পরিমাণে'**
  String get offerAnyQuantity;

  /// Offer condition: a bill-value step with no range
  ///
  /// In bn, this message translates to:
  /// **'যেকোনো অঙ্কে'**
  String get offerAnyAmount;

  /// Offer condition: buying a product the server did not name
  ///
  /// In bn, this message translates to:
  /// **'নির্দিষ্ট পণ্য কিনলে'**
  String get offerSetProduct;

  /// Offer condition: a time window the server did not give
  ///
  /// In bn, this message translates to:
  /// **'নির্দিষ্ট সময়ে'**
  String get offerSetTime;

  /// Offer condition: buying inside a time window
  ///
  /// In bn, this message translates to:
  /// **'{from}–{to} সময়ে কিনলে'**
  String offerTimeWindow(String from, String to);

  /// Offer goods: a product category
  ///
  /// In bn, this message translates to:
  /// **'{name} (ধরন)'**
  String offerGoodsCategory(String name);

  /// Offer goods: a brand
  ///
  /// In bn, this message translates to:
  /// **'{name} (ব্র্যান্ড)'**
  String offerGoodsBrand(String name);

  /// Offer goods: the offer covers every product
  ///
  /// In bn, this message translates to:
  /// **'সব মালে'**
  String get offerGoodsAll;

  /// Offer audience: the named shops
  ///
  /// In bn, this message translates to:
  /// **'দোকান: {names}'**
  String offerForShops(String names);

  /// Offer audience: the shop types
  ///
  /// In bn, this message translates to:
  /// **'ধরন: {names}'**
  String offerForShopTypes(String names);

  /// Offer audience: the areas
  ///
  /// In bn, this message translates to:
  /// **'এলাকা: {names}'**
  String offerForAreas(String names);

  /// Approval card: the amount on a discount request is the discount, not the bill total; amount is money
  ///
  /// In bn, this message translates to:
  /// **'ছাড় {amount}'**
  String approvalCardDiscount(String amount);

  /// Approval card: the kind of paper waiting for a signature (server model PurchaseOrder)
  ///
  /// In bn, this message translates to:
  /// **'ক্রয় আদেশ'**
  String get approvalDocPurchaseOrder;

  /// Approval card: the kind of paper waiting for a signature (server model PurchaseBill)
  ///
  /// In bn, this message translates to:
  /// **'ক্রয় বিল'**
  String get approvalDocPurchaseBill;

  /// Approval card: the kind of paper waiting for a signature (server model PurchaseReturn)
  ///
  /// In bn, this message translates to:
  /// **'ক্রয় ফেরত'**
  String get approvalDocPurchaseReturn;

  /// Approval card: the kind of paper waiting for a signature (server model Payment)
  ///
  /// In bn, this message translates to:
  /// **'পরিশোধ'**
  String get approvalDocPayment;

  /// Approval card: the kind of paper waiting for a signature (server model StockTransfer)
  ///
  /// In bn, this message translates to:
  /// **'মজুদ স্থানান্তর'**
  String get approvalDocStockTransfer;

  /// Approval card: the kind of paper waiting for a signature (server model Payroll)
  ///
  /// In bn, this message translates to:
  /// **'বেতন'**
  String get approvalDocPayroll;

  /// Approval card: the kind of paper waiting for a signature (server model Voucher)
  ///
  /// In bn, this message translates to:
  /// **'ভাউচার'**
  String get approvalDocVoucher;

  /// Approval card: the kind of paper waiting for a signature (server model DeliveryOrder)
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি অর্ডার'**
  String get approvalDocDeliveryOrder;

  /// Approval card: the kind of paper waiting for a signature (server model SalesOrder)
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় আদেশ'**
  String get approvalDocSalesOrder;

  /// Approval card: the kind of paper waiting for a signature (server model SalesInvoice)
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় বিল'**
  String get approvalDocSalesInvoice;

  /// Approval card: the kind of paper waiting for a signature (server model DeliveryChallan)
  ///
  /// In bn, this message translates to:
  /// **'চালান'**
  String get approvalDocDeliveryChallan;

  /// Approval card: the kind of paper waiting for a signature (server model SalesQuotation)
  ///
  /// In bn, this message translates to:
  /// **'দরপত্র'**
  String get approvalDocSalesQuotation;

  /// Approval card: the kind of paper waiting for a signature (server model SalesReturn)
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় ফেরত'**
  String get approvalDocSalesReturn;

  /// Approval card: the kind of paper waiting for a signature (server model Collection)
  ///
  /// In bn, this message translates to:
  /// **'আদায়'**
  String get approvalDocCollection;

  /// Approval card: the kind of paper waiting for a signature (server model ExpenseClaim)
  ///
  /// In bn, this message translates to:
  /// **'খরচের দাবি'**
  String get approvalDocExpenseClaim;

  /// Approval card: the kind of paper waiting for a signature (server model Note)
  ///
  /// In bn, this message translates to:
  /// **'নোট'**
  String get approvalDocNote;

  /// Approval card: the kind of paper waiting for a signature (server model MoneyTransfer)
  ///
  /// In bn, this message translates to:
  /// **'টাকা স্থানান্তর'**
  String get approvalDocMoneyTransfer;

  /// Approval card: the kind of paper waiting for a signature (server model Promotion)
  ///
  /// In bn, this message translates to:
  /// **'অফার'**
  String get approvalDocPromotion;

  /// Approval card: the kind of paper waiting for a signature (server model CustomerCreditRequest)
  ///
  /// In bn, this message translates to:
  /// **'বাকির সীমা বাড়ানোর অনুরোধ'**
  String get approvalDocCustomerCreditRequest;

  /// Approval card: the kind of paper when the server sends no type
  ///
  /// In bn, this message translates to:
  /// **'নথি'**
  String get approvalDocUnknown;

  /// Attendance day status; an unknown status is shown as the server sent it
  ///
  /// In bn, this message translates to:
  /// **'{status, select, present{উপস্থিত} absent{অনুপস্থিত} leave{ছুটি} holiday{সরকারি ছুটি} other{{status}}}'**
  String attendanceStatus(String status);

  /// A customer or product row that came with no name and no code
  ///
  /// In bn, this message translates to:
  /// **'নাম নেই'**
  String get customerNoName;

  /// Customer due: the shop owes nothing
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া নেই'**
  String get customerDueNone;

  /// Customer due: the shop has paid ahead; amount is money
  ///
  /// In bn, this message translates to:
  /// **'অগ্রিম {amount}'**
  String customerDueAdvance(String amount);

  /// Customer due: what the shop owes; amount is money
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া {amount}'**
  String customerDueAmount(String amount);

  /// List sort option: by name, alphabetical
  ///
  /// In bn, this message translates to:
  /// **'নাম: ক থেকে হ'**
  String get listSortNameAz;

  /// List sort option: by name, reverse
  ///
  /// In bn, this message translates to:
  /// **'নাম: হ থেকে ক'**
  String get listSortNameZa;

  /// List sort option: by code
  ///
  /// In bn, this message translates to:
  /// **'কোড অনুযায়ী'**
  String get listSortCode;

  /// List filter group: active or inactive, or the order status
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get listFilterStatus;

  /// List filter choice: active rows
  ///
  /// In bn, this message translates to:
  /// **'সক্রিয়'**
  String get listFilterActive;

  /// List filter choice: inactive rows
  ///
  /// In bn, this message translates to:
  /// **'নিষ্ক্রিয়'**
  String get listFilterInactive;

  /// Customer list filter group: has a mobile number or not
  ///
  /// In bn, this message translates to:
  /// **'মোবাইল নম্বর'**
  String get listFilterMobile;

  /// List filter choice: the row has it
  ///
  /// In bn, this message translates to:
  /// **'আছে'**
  String get listFilterYes;

  /// List filter choice: the row does not have it
  ///
  /// In bn, this message translates to:
  /// **'নেই'**
  String get listFilterNo;

  /// Customer list filter group: customer type
  ///
  /// In bn, this message translates to:
  /// **'ধরন'**
  String get listFilterType;

  /// Due list sort option
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া: বেশি আগে'**
  String get listSortDueHigh;

  /// Due list sort option
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া: কম আগে'**
  String get listSortDueLow;

  /// Due list sort option: share of the credit limit used
  ///
  /// In bn, this message translates to:
  /// **'সীমার ব্যবহার: বেশি আগে'**
  String get listSortLimitUsed;

  /// Due list filter group: the credit limit
  ///
  /// In bn, this message translates to:
  /// **'ঋণসীমা'**
  String get listFilterCreditLimit;

  /// Due list filter choice: due above the credit limit
  ///
  /// In bn, this message translates to:
  /// **'সীমা ছাড়িয়েছে'**
  String get listFilterOverLimit;

  /// Due list filter choice: due inside the credit limit
  ///
  /// In bn, this message translates to:
  /// **'সীমার মধ্যে'**
  String get listFilterWithinLimit;

  /// Due list filter choice: no credit limit set
  ///
  /// In bn, this message translates to:
  /// **'সীমা নেই'**
  String get listFilterNoLimit;

  /// Due list filter group: credit days
  ///
  /// In bn, this message translates to:
  /// **'বাকির শর্ত'**
  String get listFilterCreditTerms;

  /// Due list filter choice: zero credit days
  ///
  /// In bn, this message translates to:
  /// **'শর্ত নেই'**
  String get listFilterNoTerms;

  /// Product list sort option
  ///
  /// In bn, this message translates to:
  /// **'দাম: বেশি আগে'**
  String get listSortPriceHigh;

  /// Product list sort option
  ///
  /// In bn, this message translates to:
  /// **'দাম: কম আগে'**
  String get listSortPriceLow;

  /// Product list filter group: has a sale price or not
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় দাম'**
  String get listFilterSalePrice;

  /// Product list filter choice
  ///
  /// In bn, this message translates to:
  /// **'দাম আছে'**
  String get listFilterHasPrice;

  /// Product list filter choice
  ///
  /// In bn, this message translates to:
  /// **'দাম নেই'**
  String get listFilterNoPrice;

  /// Product list filter group: stock on hand
  ///
  /// In bn, this message translates to:
  /// **'মজুদ'**
  String get listFilterStock;

  /// Product list filter choice: some stock can be sold
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়যোগ্য আছে'**
  String get listFilterSellable;

  /// Product list filter choice: no stock
  ///
  /// In bn, this message translates to:
  /// **'মজুদ শূন্য'**
  String get listFilterOutOfStock;

  /// Stock list sort option
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ: বেশি আগে'**
  String get listSortQtyHigh;

  /// Stock list sort option
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ: কম আগে'**
  String get listSortQtyLow;

  /// Stock list filter group: rows with nothing on hand
  ///
  /// In bn, this message translates to:
  /// **'শূন্য মজুদ'**
  String get listFilterZeroStock;

  /// Stock list filter choice: hide zero-stock rows
  ///
  /// In bn, this message translates to:
  /// **'লুকান'**
  String get listFilterHide;

  /// Stock list filter choice: show only zero-stock rows
  ///
  /// In bn, this message translates to:
  /// **'শুধু শূন্য'**
  String get listFilterOnlyZero;

  /// Stock list filter group: stock promised to orders or held
  ///
  /// In bn, this message translates to:
  /// **'অর্ডারে বা আটকানো'**
  String get listFilterCommitted;

  /// Stock list filter group: free (offer) goods on hand
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি মাল'**
  String get listFilterFreeGoods;

  /// Order list sort option
  ///
  /// In bn, this message translates to:
  /// **'তারিখ: নতুন আগে'**
  String get listSortDateNew;

  /// Order list sort option
  ///
  /// In bn, this message translates to:
  /// **'তারিখ: পুরনো আগে'**
  String get listSortDateOld;

  /// Order list sort option
  ///
  /// In bn, this message translates to:
  /// **'টাকা: বেশি আগে'**
  String get listSortAmountHigh;

  /// Order list sort option
  ///
  /// In bn, this message translates to:
  /// **'টাকা: কম আগে'**
  String get listSortAmountLow;

  /// Order list sort option: by shop name
  ///
  /// In bn, this message translates to:
  /// **'দোকান: ক থেকে হ'**
  String get listSortShopAz;

  /// Due list filter choice: this many credit days
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{{days} দিন}}'**
  String listFilterCreditDays(int days);

  /// List controls: the filter button and the filter sheet title
  ///
  /// In bn, this message translates to:
  /// **'ফিল্টার'**
  String get listFilter;

  /// List controls: the sort button and the sort sheet title
  ///
  /// In bn, this message translates to:
  /// **'সাজান'**
  String get listSort;

  /// List filter sheet: the choice that keeps every row of a group
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get listFilterAll;

  /// List filter sheet: clears every filter at once
  ///
  /// In bn, this message translates to:
  /// **'সব মুছুন'**
  String get listFilterClearAll;

  /// List filter sheet: applies the chosen filters
  ///
  /// In bn, this message translates to:
  /// **'প্রয়োগ করুন'**
  String get listFilterApply;

  /// Screen reader money words: this many crore (ten million); n is digits
  ///
  /// In bn, this message translates to:
  /// **'{n} কোটি'**
  String moneySpokenCrore(String n);

  /// Screen reader money words: this many lakh (hundred thousand); n is digits
  ///
  /// In bn, this message translates to:
  /// **'{n} লাখ'**
  String moneySpokenLakh(String n);

  /// Screen reader money words: this many thousand; n is digits
  ///
  /// In bn, this message translates to:
  /// **'{n} হাজার'**
  String moneySpokenThousand(String n);

  /// Screen reader money words: said before a negative amount
  ///
  /// In bn, this message translates to:
  /// **'ঋণাত্মক'**
  String get moneySpokenMinus;

  /// Screen reader money words: the whole taka part, already in words (e.g. "12 lakh 567")
  ///
  /// In bn, this message translates to:
  /// **'{amount} টাকা'**
  String moneySpokenTaka(String amount);

  /// Screen reader money words: the paisa part; n is digits
  ///
  /// In bn, this message translates to:
  /// **'{n} পয়সা'**
  String moneySpokenPaisa(String n);

  /// Principal commission balance, one word: we owe the principal company
  ///
  /// In bn, this message translates to:
  /// **'দেনা'**
  String get commissionSidePay;

  /// Principal commission balance, one word: the principal company owes us
  ///
  /// In bn, this message translates to:
  /// **'পাওনা'**
  String get commissionSideReceive;

  /// Principal commission balance: nothing owed either way
  ///
  /// In bn, this message translates to:
  /// **'জের নেই'**
  String get commissionSideSettled;

  /// Principal commission balance, full: we must pay the principal
  ///
  /// In bn, this message translates to:
  /// **'দেনা · দিতে হবে'**
  String get commissionSidePaySentence;

  /// Principal commission balance, full: the principal company owes us
  ///
  /// In bn, this message translates to:
  /// **'পাওনা · কোম্পানির কাছে পাব'**
  String get commissionSideReceiveSentence;

  /// A negative commission in words; amount is money without the minus sign
  ///
  /// In bn, this message translates to:
  /// **'লোকসান {amount} — কেনা দামের নিচে বিক্রি'**
  String commissionLoss(String amount);

  /// Greeting for this part of the day (night)
  ///
  /// In bn, this message translates to:
  /// **'শুভ রাত্রি'**
  String get dayGreetingNight;

  /// Greeting for this part of the day (morning)
  ///
  /// In bn, this message translates to:
  /// **'শুভ সকাল'**
  String get dayGreetingMorning;

  /// Greeting for this part of the day (noon)
  ///
  /// In bn, this message translates to:
  /// **'শুভ দুপুর'**
  String get dayGreetingNoon;

  /// Greeting for this part of the day (afternoon)
  ///
  /// In bn, this message translates to:
  /// **'শুভ বিকাল'**
  String get dayGreetingAfternoon;

  /// Greeting for this part of the day (evening)
  ///
  /// In bn, this message translates to:
  /// **'শুভ সন্ধ্যা'**
  String get dayGreetingEvening;

  /// Part of the day written before a clock time in Bengali (night)
  ///
  /// In bn, this message translates to:
  /// **'রাত'**
  String get dayPartNight;

  /// Part of the day written before a clock time in Bengali (dawn)
  ///
  /// In bn, this message translates to:
  /// **'ভোর'**
  String get dayPartDawn;

  /// Part of the day written before a clock time in Bengali (morning)
  ///
  /// In bn, this message translates to:
  /// **'সকাল'**
  String get dayPartMorning;

  /// Part of the day written before a clock time in Bengali (noon)
  ///
  /// In bn, this message translates to:
  /// **'দুপুর'**
  String get dayPartNoon;

  /// Part of the day written before a clock time in Bengali (afternoon)
  ///
  /// In bn, this message translates to:
  /// **'বিকাল'**
  String get dayPartAfternoon;

  /// Part of the day written before a clock time in Bengali (evening)
  ///
  /// In bn, this message translates to:
  /// **'সন্ধ্যা'**
  String get dayPartEvening;

  /// Month name: January
  ///
  /// In bn, this message translates to:
  /// **'জানুয়ারি'**
  String get monthJanuary;

  /// Month name: February
  ///
  /// In bn, this message translates to:
  /// **'ফেব্রুয়ারি'**
  String get monthFebruary;

  /// Month name: March
  ///
  /// In bn, this message translates to:
  /// **'মার্চ'**
  String get monthMarch;

  /// Month name: April
  ///
  /// In bn, this message translates to:
  /// **'এপ্রিল'**
  String get monthApril;

  /// Month name: May
  ///
  /// In bn, this message translates to:
  /// **'মে'**
  String get monthMay;

  /// Month name: June
  ///
  /// In bn, this message translates to:
  /// **'জুন'**
  String get monthJune;

  /// Month name: July
  ///
  /// In bn, this message translates to:
  /// **'জুলাই'**
  String get monthJuly;

  /// Month name: August
  ///
  /// In bn, this message translates to:
  /// **'আগস্ট'**
  String get monthAugust;

  /// Month name: September
  ///
  /// In bn, this message translates to:
  /// **'সেপ্টেম্বর'**
  String get monthSeptember;

  /// Month name: October
  ///
  /// In bn, this message translates to:
  /// **'অক্টোবর'**
  String get monthOctober;

  /// Month name: November
  ///
  /// In bn, this message translates to:
  /// **'নভেম্বর'**
  String get monthNovember;

  /// Month name: December
  ///
  /// In bn, this message translates to:
  /// **'ডিসেম্বর'**
  String get monthDecember;

  /// Report page: the page has no rows
  ///
  /// In bn, this message translates to:
  /// **'কোনো সারি নেই'**
  String get reportNoRows;

  /// Report page: which rows of the whole result this page shows
  ///
  /// In bn, this message translates to:
  /// **'{total} টির মধ্যে {first}–{last}'**
  String reportRangeOf(String total, String first, String last);

  /// Order row: the shop is not on this phone
  ///
  /// In bn, this message translates to:
  /// **'অজানা গ্রাহক'**
  String get salesOrderUnknownCustomer;

  /// Sales order status; an unknown status is shown as the server sent it
  ///
  /// In bn, this message translates to:
  /// **'{status, select, draft{খসড়া} confirmed{নিশ্চিত} cancelled{বাতিল} closed{বন্ধ} other{{status}}}'**
  String salesOrderStatus(String status);

  /// The total row under the principal commission rows
  ///
  /// In bn, this message translates to:
  /// **'মোট'**
  String get todayTotal;

  /// Sales figure heading when the server sends none: what was billed today, returns not taken off
  ///
  /// In bn, this message translates to:
  /// **'আজ বিল হয়েছে'**
  String get todayBilledToday;

  /// Under net sales: the two parts, billed and sent back (returned); both are money
  ///
  /// In bn, this message translates to:
  /// **'বিল {billed} · ফেরত {returned}'**
  String todaySalesBilledReturned(String billed, String returned);

  /// Under sales on an older server: how many bills
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} টি বিক্রয়}}'**
  String todaySalesCount(int count);

  /// Principal balance: we still have to pay the principal; amount is money
  ///
  /// In bn, this message translates to:
  /// **'দিতে হবে: {amount}'**
  String todayBalanceToPay(String amount);

  /// Principal balance: the principal company owes us; amount is money
  ///
  /// In bn, this message translates to:
  /// **'কোম্পানির কাছে পাব: {amount}'**
  String todayBalanceOwedToUs(String amount);

  /// Sync screen: the module a queued change belongs to (server module "sales")
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়'**
  String get syncModuleSales;

  /// Sync screen: the module a queued change belongs to (server module "purchase")
  ///
  /// In bn, this message translates to:
  /// **'ক্রয়'**
  String get syncModulePurchase;

  /// Sync screen: the module a queued change belongs to (server module "inventory")
  ///
  /// In bn, this message translates to:
  /// **'মজুদ'**
  String get syncModuleInventory;

  /// Sync screen: the module a queued change belongs to (server module "accounts")
  ///
  /// In bn, this message translates to:
  /// **'হিসাব'**
  String get syncModuleAccounts;

  /// Sync screen: the module a queued change belongs to (server module "finance")
  ///
  /// In bn, this message translates to:
  /// **'অর্থ'**
  String get syncModuleFinance;

  /// Sync screen: the module a queued change belongs to (server module "customer")
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক'**
  String get syncModuleCustomer;

  /// Sync screen: the module a queued change belongs to (server module "supplier")
  ///
  /// In bn, this message translates to:
  /// **'সরবরাহকারী'**
  String get syncModuleSupplier;

  /// Sync screen: the module a queued change belongs to (server module "hr")
  ///
  /// In bn, this message translates to:
  /// **'জনবল'**
  String get syncModuleHr;

  /// Sync screen: what a catalogue holds (server entity "Customer")
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক'**
  String get syncEntityCustomer;

  /// Sync screen: what a catalogue holds (server entity "Supplier")
  ///
  /// In bn, this message translates to:
  /// **'সরবরাহকারী'**
  String get syncEntitySupplier;

  /// Sync screen: what a catalogue holds (server entity "Product")
  ///
  /// In bn, this message translates to:
  /// **'পণ্য'**
  String get syncEntityProduct;

  /// Sync screen: what a catalogue holds (server entity "ProductPrice")
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের দাম'**
  String get syncEntityProductPrice;

  /// Sync screen: what a catalogue holds (server entity "SalesOrder")
  ///
  /// In bn, this message translates to:
  /// **'অর্ডার'**
  String get syncEntitySalesOrder;

  /// Sync screen: what a catalogue holds (server entity "Collection")
  ///
  /// In bn, this message translates to:
  /// **'আদায়'**
  String get syncEntityCollection;

  /// Sync screen: what a catalogue holds (server entity "CustomerDue")
  ///
  /// In bn, this message translates to:
  /// **'দোকানের বকেয়া'**
  String get syncEntityCustomerDue;

  /// Sync screen: what a catalogue holds (server entity "StockOnHand")
  ///
  /// In bn, this message translates to:
  /// **'হাতে থাকা মজুদ'**
  String get syncEntityStockOnHand;

  /// Sync: a refused change that came with no reason
  ///
  /// In bn, this message translates to:
  /// **'অজানা কারণ'**
  String get syncUnknownReason;

  /// Sync: a queued change the server never answered, after this many tries
  ///
  /// In bn, this message translates to:
  /// **'সার্ভার এই বদলের উত্তর দেয়নি ({attempts, plural, other{{attempts} বার}}) — তালিকা দেখে নিজে থেকে আবার লিখুন।'**
  String syncNoAnswer(int attempts);

  /// Sync: a queued change that could not be sent, after this many tries
  ///
  /// In bn, this message translates to:
  /// **'বারবার পাঠানো ব্যর্থ হয়েছে ({attempts, plural, other{{attempts} বার}}) — নিজে থেকে আবার লিখুন।'**
  String syncSendFailedRepeatedly(int attempts);

  /// Sync: the server refused a change without saying why
  ///
  /// In bn, this message translates to:
  /// **'কারণ জানানো হয়নি।'**
  String get syncNoReasonGiven;

  /// Sync error: fetching failed for want of a network
  ///
  /// In bn, this message translates to:
  /// **'সংযোগ নেই — সংযোগ পেয়ে আবার নিচে টানুন।'**
  String get syncOfflinePull;

  /// Sync error: sending waits for a network
  ///
  /// In bn, this message translates to:
  /// **'সংযোগ নেই — সংযোগ পেলেই নিজে থেকে চলে যাবে।'**
  String get syncOfflinePush;

  /// Sync error: the server refused the request with this HTTP code
  ///
  /// In bn, this message translates to:
  /// **'সার্ভার অনুরোধটাই নিচ্ছে না (কোড {code})'**
  String syncServerRefuses(String code);

  /// Sync error: the server refused with its own message
  ///
  /// In bn, this message translates to:
  /// **'সার্ভার বলছে: {message} (কোড {code})'**
  String syncServerSays(String message, String code);

  /// Sync error: a server refusal on fetching; what is the sentence before
  ///
  /// In bn, this message translates to:
  /// **'{what} — অফিসে জানান, বারবার টেনে ঠিক হবে না।'**
  String syncRefusalTellOfficePull(String what);

  /// Sync error: a server refusal on sending; what is the sentence before
  ///
  /// In bn, this message translates to:
  /// **'{what} — অফিসে জানান, অপেক্ষা করে ঠিক হবে না।'**
  String syncRefusalTellOfficePush(String what);

  /// Sync error: fetching failed for an unknown reason
  ///
  /// In bn, this message translates to:
  /// **'তালিকা আনা যায়নি — কারণ জানা যায়নি। অফিসে জানান।'**
  String get syncPullUnknown;

  /// Sync error: sending failed for an unknown reason
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো যায়নি — কারণ জানা যায়নি। অফিসে জানান।'**
  String get syncPushUnknown;

  /// Last-sync line: a sync is running
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক হচ্ছে…'**
  String get syncStatusRunning;

  /// Last-sync line: offline; queued work waits
  ///
  /// In bn, this message translates to:
  /// **'নেট নেই — কাজ জমা থাকছে'**
  String get syncStatusOffline;

  /// Last-sync line: never synced and the last try failed
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক হয়নি — টেনে আবার চেষ্টা করুন'**
  String get syncStatusFailedNever;

  /// Last-sync line: never synced
  ///
  /// In bn, this message translates to:
  /// **'এখনো সিঙ্ক হয়নি'**
  String get syncStatusNever;

  /// Last-sync line: when the last good sync was, e.g. "2 minutes ago"
  ///
  /// In bn, this message translates to:
  /// **'শেষ সিঙ্ক: {ago}'**
  String syncStatusLast(String ago);

  /// Last-sync line: the last good sync, and the try after it failed
  ///
  /// In bn, this message translates to:
  /// **'শেষ সিঙ্ক: {ago} · শেষবার ব্যর্থ'**
  String syncStatusLastFailed(String ago);

  /// Time since: under a minute
  ///
  /// In bn, this message translates to:
  /// **'এইমাত্র'**
  String get syncAgoJustNow;

  /// Time since, in minutes; digits is the count as written (Bengali or English digits)
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{digits} মিনিট আগে}}'**
  String syncAgoMinutes(int count, String digits);

  /// Time since, in hours; digits is the count as written
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{digits} ঘণ্টা আগে}}'**
  String syncAgoHours(int count, String digits);

  /// Time since, in days; digits is the count as written
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{digits} দিন আগে}}'**
  String syncAgoDays(int count, String digits);

  /// Overview sheet before sending: the button that confirms
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত করুন'**
  String get overviewConfirm;

  /// Overview sheet: keeps the paper as a draft
  ///
  /// In bn, this message translates to:
  /// **'খসড়া রাখুন'**
  String get overviewKeepDraft;

  /// Sheet or search: closes and goes back without doing anything
  ///
  /// In bn, this message translates to:
  /// **'ফিরে যান'**
  String get sheetGoBack;

  /// Screen reader: the loading skeleton
  ///
  /// In bn, this message translates to:
  /// **'লোড হচ্ছে'**
  String get stateLoading;

  /// Totals bar: the sum of the rows on this page, and how many
  ///
  /// In bn, this message translates to:
  /// **'এই পাতা · {count}টি'**
  String totalsThisPage(String count);

  /// Totals bar: the sum of every row of the filtered list, and how many
  ///
  /// In bn, this message translates to:
  /// **'সর্বমোট · {count}টি'**
  String totalsAll(String count);

  /// Leave-guard dialog title: the form has unsent writing
  ///
  /// In bn, this message translates to:
  /// **'লেখা ফেলে চলে যাবেন?'**
  String get unsavedTitle;

  /// Leave-guard dialog text
  ///
  /// In bn, this message translates to:
  /// **'যা লিখেছেন তা এখনো পাঠানো হয়নি — চলে গেলে হারিয়ে যাবে।'**
  String get unsavedBody;

  /// Leave-guard dialog: stay on the form
  ///
  /// In bn, this message translates to:
  /// **'থাকুন'**
  String get unsavedStay;

  /// Leave-guard dialog: leave and lose the writing
  ///
  /// In bn, this message translates to:
  /// **'ফেলে যান'**
  String get unsavedLeave;

  /// Company or branch switch refused: orders still queued on this phone
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনে {count} টি অর্ডার এখনো পাঠানো হয়নি। আগে ওগুলো পাঠান (সিঙ্কের অবস্থা দেখুন), তারপর কোম্পানি বা শাখা বদলান — নইলে ওগুলো নতুন জায়গার নামে বসবে।'**
  String workspacePendingOrders(int count);

  /// Company switch: the server has no such door yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভার এখনো অ্যাপ থেকে কোম্পানি বদলানো চেনে না। সার্ভার হালনাগাদ হলে পারবেন; ততক্ষণ ওয়েব থেকে বদলান।'**
  String get workspaceServerTooOld;

  /// Company switch refused (403)
  ///
  /// In bn, this message translates to:
  /// **'এই কোম্পানিতে ঢোকার অনুমতি আপনার নেই।'**
  String get workspaceNoCompanyAccess;

  /// Branch switch refused (422)
  ///
  /// In bn, this message translates to:
  /// **'এই শাখায় যাওয়া গেল না — শাখাটা আপনার নাগালে নেই, বা এই কোম্পানির নয়।'**
  String get workspaceBranchOutOfReach;

  /// Company or branch switch failed for another reason
  ///
  /// In bn, this message translates to:
  /// **'বদলানো গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।'**
  String get workspaceSwitchFailed;

  /// Home: title of the order status screen it opens
  ///
  /// In bn, this message translates to:
  /// **'অর্ডারের অবস্থা'**
  String get homeOrderStatus;

  /// Home: title of the due list screen it opens
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া তালিকা'**
  String get homeDueList;

  /// Home: title of the approvals screen it opens
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন'**
  String get homeApprovals;

  /// Home and today: the principal commission card and screen title
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালের কমিশন'**
  String get homePrincipalCommission;

  /// Home: the row that opens the business dashboards
  ///
  /// In bn, this message translates to:
  /// **'ব্যবসার ড্যাশবোর্ড'**
  String get homeDashboards;

  /// Home: the line under the business dashboards row
  ///
  /// In bn, this message translates to:
  /// **'মজুদ, বিক্রি, হিসাব — ওয়েবের একই সংখ্যা'**
  String get homeDashboardsHint;

  /// Home: section title of the pinned menu rows
  ///
  /// In bn, this message translates to:
  /// **'প্রিয়'**
  String get homeFavourites;

  /// Home: favourites section when empty
  ///
  /// In bn, this message translates to:
  /// **'কোনো মডিউলের মেনুতে একটা সারি চেপে ধরে রাখুন — এখানে এসে বসবে (ছয়টা পর্যন্ত)।'**
  String get homeFavouritesEmpty;

  /// Home: section title of recently opened screens
  ///
  /// In bn, this message translates to:
  /// **'সাম্প্রতিক'**
  String get homeRecent;

  /// Home: section title above the day figures
  ///
  /// In bn, this message translates to:
  /// **'আজ'**
  String get homeToday;

  /// Home: title of the rooted or tampered phone warning
  ///
  /// In bn, this message translates to:
  /// **'ফোনটা নিরাপদ নয়'**
  String get homeUnsafePhoneTitle;

  /// Home: closes the unsafe phone warning
  ///
  /// In bn, this message translates to:
  /// **'বুঝেছি'**
  String get homeUnderstood;

  /// Header and workspace picker: every branch is in view
  ///
  /// In bn, this message translates to:
  /// **'সব শাখা'**
  String get homeAllBranches;

  /// Snack bar: a menu row was pinned to the home
  ///
  /// In bn, this message translates to:
  /// **'\"{name}\" প্রিয়-তে যোগ হলো'**
  String homeFavouriteAdded(String name);

  /// Snack bar: a menu row was unpinned
  ///
  /// In bn, this message translates to:
  /// **'\"{name}\" প্রিয় থেকে সরানো হলো'**
  String homeFavouriteRemoved(String name);

  /// Snack bar: favourites are full
  ///
  /// In bn, this message translates to:
  /// **'প্রিয়-তে ছয়টার বেশি রাখা যায় না — আগে একটা সরান (হোমে চেপে ধরে)।'**
  String get homeFavouritesFull;

  /// Menu row under orders: how many orders wait on the phone for a connection
  ///
  /// In bn, this message translates to:
  /// **'{count} অপেক্ষমাণ'**
  String homeQueuedCount(String count);

  /// Top bar crumb and menu: the list of every module
  ///
  /// In bn, this message translates to:
  /// **'সব মডিউল'**
  String get homeAllModules;

  /// Top bar crumb and search: the personal page
  ///
  /// In bn, this message translates to:
  /// **'আমি'**
  String get homeMe;

  /// Module menu: note for a module whose screens are not built yet
  ///
  /// In bn, this message translates to:
  /// **'{module}-এর পর্দাগুলো এখনো ফোনে আসেনি — নিচের সারিগুলো শীঘ্রই আসছে।'**
  String homeModuleComingSoon(String module);

  /// Me page row and screen title: the sync status screen
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্কের অবস্থা'**
  String get homeSyncStatus;

  /// Me page: the line under the sync status row
  ///
  /// In bn, this message translates to:
  /// **'কী গেছে, কী যায়নি, কী ফেরত এসেছে'**
  String get homeSyncStatusHint;

  /// Me page: the printer settings row
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্টার'**
  String get homePrinter;

  /// Me page: the line under the printer row
  ///
  /// In bn, this message translates to:
  /// **'ব্লুটুথ রসিদ প্রিন্টার, রোলের মাপ, পরীক্ষামূলক ছাপা'**
  String get homePrinterHint;

  /// Me page button and sign-out dialog button
  ///
  /// In bn, this message translates to:
  /// **'বেরিয়ে যান'**
  String get homeSignOut;

  /// Sign-out dialog title
  ///
  /// In bn, this message translates to:
  /// **'বেরিয়ে যাবেন?'**
  String get homeSignOutTitle;

  /// Sign-out dialog: orders still queued on this phone
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনে আপনার {count} টি অর্ডার এখনো পাঠানো হয়নি। বেরিয়ে গেলেও ওগুলো ফোনেই থাকবে, আর আপনি আবার ঢুকলে তবেই যাবে — অন্য কেউ ঢুকলে তাঁর নামে যাবে না।'**
  String homeSignOutPending(int count);

  /// Sign-out dialog: nothing queued
  ///
  /// In bn, this message translates to:
  /// **'আবার ঢুকতে পাসওয়ার্ড লাগবে।'**
  String get homeSignOutPassword;

  /// Sign-out dialog: stay signed in
  ///
  /// In bn, this message translates to:
  /// **'থাক'**
  String get homeStay;

  /// Me page row and sheet title: how long away before the app locks
  ///
  /// In bn, this message translates to:
  /// **'অ্যাপ-তালার সময়'**
  String get homeLockDelay;

  /// Me page: the line under the app lock row; delay is the chosen time
  ///
  /// In bn, this message translates to:
  /// **'এতক্ষণ বাইরে থাকলে ফিরতে আঙুলের ছাপ বা PIN লাগবে: {delay}'**
  String homeLockDelayHint(String delay);

  /// Module list: the account has no menu at all
  ///
  /// In bn, this message translates to:
  /// **'আপনার জন্য কোনো মেনু নেই'**
  String get homeNoMenu;

  /// Module list: what to do when there is no menu
  ///
  /// In bn, this message translates to:
  /// **'অফিসে জানান — আপনার অ্যাকাউন্টে কোনো অনুমতি বসানো নেই।'**
  String get homeNoMenuHint;

  /// Module tile or menu row: not on the phone yet
  ///
  /// In bn, this message translates to:
  /// **'শীঘ্রই আসছে'**
  String get homeComingSoon;

  /// Menu row and search result: the screen is the web page opened inside the app
  ///
  /// In bn, this message translates to:
  /// **'ওয়েবের পর্দা'**
  String get homeWebScreen;

  /// Home search box hint; the second example is a sample shop name
  ///
  /// In bn, this message translates to:
  /// **'খুঁজুন: \"চালান\", \"রহিম ট্রেডার্স\"…'**
  String get homeSearchHint;

  /// Search page: clears the typed text
  ///
  /// In bn, this message translates to:
  /// **'মুছুন'**
  String get homeSearchClear;

  /// Search page before anything is typed
  ///
  /// In bn, this message translates to:
  /// **'পর্দার নাম (\"অর্ডার\", \"চালান\", \"stock\") বা গ্রাহকের নাম/মোবাইল লিখুন।'**
  String get homeSearchHelp;

  /// Search page: no screen or customer matched
  ///
  /// In bn, this message translates to:
  /// **'\"{query}\" — কিছু পাওয়া গেল না।'**
  String homeSearchNothing(String query);

  /// Search results: heading of the screens found
  ///
  /// In bn, this message translates to:
  /// **'পর্দা'**
  String get homeSearchScreens;

  /// Search results: heading of the customers found
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক'**
  String get homeSearchCustomers;

  /// Notice ticker sheet: title over the running notices
  ///
  /// In bn, this message translates to:
  /// **'নোটিশ'**
  String get homeNotices;

  /// Paper link screen title when the paper cannot be opened
  ///
  /// In bn, this message translates to:
  /// **'কাগজ'**
  String get homePaper;

  /// Paper link: the kind of paper is unknown to this app version
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজটা এই অ্যাপে খোলা যায় না'**
  String get homePaperCannotOpen;

  /// Paper link: what to do when it cannot open
  ///
  /// In bn, this message translates to:
  /// **'অ্যাপটা হালনাগাদ করে আবার চেষ্টা করুন।'**
  String get homeUpdateAndRetry;

  /// Workspace picker sheet title
  ///
  /// In bn, this message translates to:
  /// **'কোম্পানি ও শাখা'**
  String get workspacePickerTitle;

  /// Workspace picker: closes the sheet
  ///
  /// In bn, this message translates to:
  /// **'বন্ধ করুন'**
  String get workspacePickerClose;

  /// Workspace picker: section of companies
  ///
  /// In bn, this message translates to:
  /// **'কোম্পানি'**
  String get workspacePickerCompany;

  /// Workspace picker: section of branches
  ///
  /// In bn, this message translates to:
  /// **'শাখা'**
  String get workspacePickerBranch;

  /// Workspace picker: section of branches of this company
  ///
  /// In bn, this message translates to:
  /// **'শাখা — {company}'**
  String workspacePickerBranchOf(String company);

  /// Workspace picker: note under the branches
  ///
  /// In bn, this message translates to:
  /// **'আপনি যা লিখবেন তা বাছা শাখার নামেই বসবে। \"সব শাখা\" কেবল দেখার জন্য — কাজের শাখা বদলায় না।'**
  String get workspacePickerNote;

  /// Branch sales row: bills with no branch
  ///
  /// In bn, this message translates to:
  /// **'শাখা নেই'**
  String get homeNoBranch;

  /// Screen reader: a figure the server did not send
  ///
  /// In bn, this message translates to:
  /// **'জানা নেই'**
  String get homeNotKnown;

  /// Screen reader hint on the MFS figure: opens the list of MFS accounts
  ///
  /// In bn, this message translates to:
  /// **'MFS খাতগুলো দেখুন'**
  String get homeMfsAccountsHint;

  /// Money figure: cash in hand
  ///
  /// In bn, this message translates to:
  /// **'হাতে নগদ'**
  String get homeCashInHand;

  /// Money figure: money in the bank
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংকে'**
  String get homeInBank;

  /// Money figure: money on the road between accounts
  ///
  /// In bn, this message translates to:
  /// **'পথে'**
  String get homeInTransit;

  /// Money figure: money that came in today
  ///
  /// In bn, this message translates to:
  /// **'আজ এসেছে'**
  String get homeCameInToday;

  /// Under money in: the collected part; amount is money
  ///
  /// In bn, this message translates to:
  /// **'আদায় {amount}'**
  String homeCollectionAmount(String amount);

  /// Money figure: money that went out today
  ///
  /// In bn, this message translates to:
  /// **'আজ গেছে'**
  String get homeWentOutToday;

  /// Money figure: what the shops owe
  ///
  /// In bn, this message translates to:
  /// **'বাজারে বকেয়া'**
  String get homeMarketDues;

  /// Under dues: how many shops owe
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} টি দোকান}}'**
  String homeShopCount(int count);

  /// Money card: the big total of cash, MFS and bank
  ///
  /// In bn, this message translates to:
  /// **'হাতে ও ব্যাংকে মোট'**
  String get homeCashAndBankTotal;

  /// MFS accounts sheet title
  ///
  /// In bn, this message translates to:
  /// **'MFS খাত'**
  String get homeMfsAccounts;

  /// Home card title: papers waiting for a signature
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায়'**
  String get homeAwaitingSignature;

  /// Home card title: papers waiting for a signature, and how many
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায় · {count}টি'**
  String homeAwaitingSignatureCount(String count);

  /// Home card: opens the full list
  ///
  /// In bn, this message translates to:
  /// **'সব দেখুন'**
  String get homeSeeAll;

  /// Home card: the waiting papers failed to load
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের কাগজ আনা গেল না। নেট দেখে আবার চেষ্টা করুন।'**
  String get homeWaitingLoadFailed;

  /// Home card: no paper waits for a signature
  ///
  /// In bn, this message translates to:
  /// **'এখন কিছু অনুমোদনের অপেক্ষায় নেই।'**
  String get homeNothingWaiting;

  /// Home card: opens the detail page
  ///
  /// In bn, this message translates to:
  /// **'বিস্তারিত'**
  String get homeDetails;

  /// Payable card: the hand loans inside the payable; amount is money or words
  ///
  /// In bn, this message translates to:
  /// **'হাতধার {amount} সহ'**
  String homeHandLoansIncluded(String amount);

  /// Payable card title: what we owe
  ///
  /// In bn, this message translates to:
  /// **'দেনা'**
  String get homePayable;

  /// Principal row: a negative commission; amount is money
  ///
  /// In bn, this message translates to:
  /// **'লোকসান {amount}'**
  String homeLossAmount(String amount);

  /// Principal row: the commission; amount is money
  ///
  /// In bn, this message translates to:
  /// **'কমিশন {amount}'**
  String homeCommissionAmount(String amount);

  /// Principal row: inflow, then the commission words
  ///
  /// In bn, this message translates to:
  /// **'এসেছে {inflow} · {commission}'**
  String homePrincipalDetail(String inflow, String commission);

  /// Report: fetching the report failed (sentence)
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট আনা গেল না।'**
  String get reportLoadFailed;

  /// Report screens: the title when none is given
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট'**
  String get reportTitle;

  /// Report: fetching failed (title of the empty state)
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট আনা গেল না'**
  String get reportLoadFailedTitle;

  /// Report by key: the person may not run this report
  ///
  /// In bn, this message translates to:
  /// **'এই রিপোর্ট আপনার জন্য খোলা নেই'**
  String get reportNotOpenForYou;

  /// Report by key: why it is not open, and what to do
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্টের চাবি নেই, অথবা এই মডিউল ফোনে বন্ধ। দরকার হলে অফিসে চাবি চেয়ে নিন।'**
  String get reportNotOpenHint;

  /// Reports list: fetching failed
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্টের তালিকা আনা গেল না।'**
  String get reportListLoadFailed;

  /// Reports list: the server has no reports door yet
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্ট এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।'**
  String get reportServerTooOld;

  /// Reports list: search box hint
  ///
  /// In bn, this message translates to:
  /// **'রিপোর্টের নাম দিয়ে খুঁজুন'**
  String get reportSearchHint;

  /// Reports list: fetching failed (title)
  ///
  /// In bn, this message translates to:
  /// **'তালিকা আনা গেল না'**
  String get reportListFailedTitle;

  /// Reports list: the person may run no report
  ///
  /// In bn, this message translates to:
  /// **'আপনার জন্য কোনো রিপোর্ট নেই'**
  String get reportNoneForYou;

  /// Reports list: the search matched nothing
  ///
  /// In bn, this message translates to:
  /// **'কোনো মিল পাওয়া যায়নি'**
  String get reportNoMatch;

  /// Report: the date range picker heading
  ///
  /// In bn, this message translates to:
  /// **'কোন তারিখ থেকে কোন তারিখ'**
  String get reportDateRangeHelp;

  /// Report: the date range picker button
  ///
  /// In bn, this message translates to:
  /// **'দেখান'**
  String get reportDateRangeShow;

  /// Report: the PDF button tooltip
  ///
  /// In bn, this message translates to:
  /// **'PDF — দেখা, ছাপা, পাঠানো'**
  String get reportPdfTooltip;

  /// Report filter chip and sheet: no filter chosen
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get reportChoiceAll;

  /// Report filter chip: the filter and its chosen value
  ///
  /// In bn, this message translates to:
  /// **'{label}: {value}'**
  String reportChoice(String label, String value);

  /// Report: no rows for the chosen dates
  ///
  /// In bn, this message translates to:
  /// **'এই সময়ে কোনো সারি নেই'**
  String get reportNoRowsInPeriod;

  /// Report filter sheet: search box label; what is the filter name
  ///
  /// In bn, this message translates to:
  /// **'{what} খুঁজুন'**
  String reportChoiceSearch(String what);

  /// Report table: the totals row
  ///
  /// In bn, this message translates to:
  /// **'মোট'**
  String get reportTotal;

  /// Report pager: previous page
  ///
  /// In bn, this message translates to:
  /// **'আগের'**
  String get reportPrevious;

  /// Report pager: next page
  ///
  /// In bn, this message translates to:
  /// **'পরের'**
  String get reportNext;

  /// Report pager: this page of how many
  ///
  /// In bn, this message translates to:
  /// **'পাতা {page} / {last}'**
  String reportPageOf(String page, String last);

  /// Sync screen: section of changes queued on the phone
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষমাণ'**
  String get syncScreenPending;

  /// Sync screen: the queue is empty
  ///
  /// In bn, this message translates to:
  /// **'সব পাঠানো হয়ে গেছে'**
  String get syncScreenAllSent;

  /// Sync screen: changes wait for a network
  ///
  /// In bn, this message translates to:
  /// **'সংযোগের অপেক্ষায় — এই ফোনেই আছে'**
  String get syncScreenWaitingConnection;

  /// Sync screen: section of refused changes
  ///
  /// In bn, this message translates to:
  /// **'যা যায়নি — কারণসহ'**
  String get syncScreenRejectedHeader;

  /// Sync screen: no refused change
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যাত কিছু নেই'**
  String get syncScreenNothingRejected;

  /// Sync screen: section of the last sync per module
  ///
  /// In bn, this message translates to:
  /// **'শেষ সিঙ্ক'**
  String get syncScreenLastSync;

  /// Sync screen: the server sent no last-sync rows
  ///
  /// In bn, this message translates to:
  /// **'কোনো তথ্য পাওয়া যায়নি'**
  String get syncScreenNoInfo;

  /// Sync screen: a module never synced
  ///
  /// In bn, this message translates to:
  /// **'কখনো না'**
  String get syncScreenNever;

  /// Sync screen: section of sync conflicts
  ///
  /// In bn, this message translates to:
  /// **'দ্বন্দ্ব'**
  String get syncScreenConflicts;

  /// Sync screen: no sync conflict
  ///
  /// In bn, this message translates to:
  /// **'কোনো দ্বন্দ্ব নেই'**
  String get syncScreenNoConflicts;

  /// Sync screen: a conflict still to resolve
  ///
  /// In bn, this message translates to:
  /// **'সমাধান বাকি'**
  String get syncScreenNeedsResolution;

  /// Sync screen: the queue box is busy
  ///
  /// In bn, this message translates to:
  /// **'পেছনের সিঙ্ক এখন সারিটা পাঠাচ্ছে — একটু পরে এখানেও খুলবে। কিছুই মোছা হয়নি।'**
  String get syncQueueBusy;

  /// Sync screen: the secure key store is locked
  ///
  /// In bn, this message translates to:
  /// **'ফোনের নিরাপদ ভাণ্ডার এখন খোলেনি — অপেক্ষমাণ অর্ডার ফোনেই রাখা আছে। অ্যাপ বন্ধ করে আবার খুলুন।'**
  String get syncQueueKeyUnavailable;

  /// Sync screen: the queue file was set aside
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষমাণ সারির ফাইল খোলেনি — ফাইলটা আলাদা করে রাখা আছে, মোছা হয়নি। নতুন অর্ডার যাচ্ছে; আগেরগুলোর জন্য অফিসকে জানান।'**
  String get syncQueueQuarantined;

  /// Sync screen: the queue file is broken
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষমাণ সারির ফাইল খোলেনি — ফাইল মোছা হয়নি, সিঙ্ক বন্ধ। অফিসকে জানান।'**
  String get syncQueueBroken;

  /// Today panel: fetching failed
  ///
  /// In bn, this message translates to:
  /// **'আজকের হিসাব আনা গেল না।'**
  String get todayLoadFailed;

  /// Today panel: the server has no such door yet
  ///
  /// In bn, this message translates to:
  /// **'আজকের হিসাব এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।'**
  String get todayServerTooOld;

  /// Today panel: pull to refresh failed
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক হয়নি — সংযোগ দেখে আবার টানুন। ফোনের কাজ ফোনেই আছে।'**
  String get todayPullFailed;

  /// Today panel: the error, then that the figures shown are the last ones kept
  ///
  /// In bn, this message translates to:
  /// **'{error} নিচের সংখ্যাগুলো এই ফোনে রাখা ছিল।'**
  String todayStaleNotice(String error);

  /// Today panel: money that came in today
  ///
  /// In bn, this message translates to:
  /// **'আজকের ইনফ্লো/আদায়'**
  String get todayInflowCollections;

  /// Today panel: collections today
  ///
  /// In bn, this message translates to:
  /// **'আজকের আদায়'**
  String get todayCollections;

  /// Today panel: how many collections
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} টি আদায়}}'**
  String todayCollectionCount(int count);

  /// Today panel: what the shops owe us
  ///
  /// In bn, this message translates to:
  /// **'পাওনা (Recoverable)'**
  String get todayRecoverable;

  /// Today panel: what we owe
  ///
  /// In bn, this message translates to:
  /// **'দেনা (Payable)'**
  String get todayPayable;

  /// Today panel: loading
  ///
  /// In bn, this message translates to:
  /// **'আনা হচ্ছে…'**
  String get todayFetching;

  /// Today panel: when the figures were counted; stamp is date and time
  ///
  /// In bn, this message translates to:
  /// **'হিসাব {stamp} পর্যন্ত'**
  String todayFiguresAsOf(String stamp);

  /// Today panel: card title
  ///
  /// In bn, this message translates to:
  /// **'আদায় ও বকেয়া'**
  String get todayCollectionsAndDues;

  /// Today panel: all the dues
  ///
  /// In bn, this message translates to:
  /// **'মোট বকেয়া'**
  String get todayTotalDues;

  /// Money box part: cash
  ///
  /// In bn, this message translates to:
  /// **'নগদ'**
  String get todayPartCash;

  /// Money box part: bank
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক'**
  String get todayPartBank;

  /// Principal commission: the period counted
  ///
  /// In bn, this message translates to:
  /// **'সময়কাল: {period}'**
  String todayPeriod(String period);

  /// Principal commission: all inflow
  ///
  /// In bn, this message translates to:
  /// **'মোট ইনফ্লো'**
  String get todayTotalInflow;

  /// Principal commission: the commission at this rate
  ///
  /// In bn, this message translates to:
  /// **'কমিশন ({rate})'**
  String todayCommissionRate(String rate);

  /// Principal commission: money already sent
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালকে পাঠানো'**
  String get todaySentToPrincipal;

  /// Principal commission: the balance
  ///
  /// In bn, this message translates to:
  /// **'বাকি ইনফ্লো'**
  String get todayInflowLeft;

  /// Today panel: documents waiting for this person to decide
  ///
  /// In bn, this message translates to:
  /// **'{count} টি নথি আপনার সিদ্ধান্তের অপেক্ষায়'**
  String todayDocumentsAwaitingDecision(int count);

  /// Today screen title
  ///
  /// In bn, this message translates to:
  /// **'আজকের হিসাব'**
  String get todayScreenTitle;

  /// Me page: the app update row
  ///
  /// In bn, this message translates to:
  /// **'অ্যাপ হালনাগাদ'**
  String get updateTileTitle;

  /// App update row: the version on this phone
  ///
  /// In bn, this message translates to:
  /// **'চলছে {version}'**
  String updateRunningVersion(String version);

  /// App update row: no newer version
  ///
  /// In bn, this message translates to:
  /// **'সর্বশেষ সংস্করণ চলছে।'**
  String get updateLatest;

  /// App update row: the check failed for want of a network
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে পৌঁছানো গেল না। ইন্টারনেট সংযোগ দেখে আবার চাপুন।'**
  String get updateOffline;

  /// App update: a newer version exists (no name sent)
  ///
  /// In bn, this message translates to:
  /// **'নতুন সংস্করণ এসেছে।'**
  String get updateNewVersion;

  /// App update: a newer version exists, with its name
  ///
  /// In bn, this message translates to:
  /// **'নতুন সংস্করণ এসেছে ({version})।'**
  String updateNewVersionNamed(String version);

  /// App update: the button that downloads and installs
  ///
  /// In bn, this message translates to:
  /// **'এখনই আপডেট করুন'**
  String get updateNow;

  /// App update: the default download button
  ///
  /// In bn, this message translates to:
  /// **'নতুন সংস্করণ নামান'**
  String get updateDownloadNew;

  /// App update: the release cannot be verified or installed in the app
  ///
  /// In bn, this message translates to:
  /// **'এই আপডেট অ্যাপের ভেতর থেকে বসানো যাচ্ছে না। অফিসে জানান।'**
  String get updateNotInstallable;

  /// App update: Android blocks installs from this app
  ///
  /// In bn, this message translates to:
  /// **'এই অ্যাপ থেকে আপডেট বসানোর অনুমতি দেওয়া নেই। সেটিংসে গিয়ে \"এই উৎস থেকে অনুমতি দিন\" চালু করুন, তারপর ফিরে এসে আবার চাপুন।'**
  String get updateNeedsPermission;

  /// App update: opens the install permission settings
  ///
  /// In bn, this message translates to:
  /// **'সেটিংস খুলুন'**
  String get updateOpenSettings;

  /// App update step: checking
  ///
  /// In bn, this message translates to:
  /// **'দেখা হচ্ছে…'**
  String get updateChecking;

  /// App update step: downloading
  ///
  /// In bn, this message translates to:
  /// **'নামানো হচ্ছে…'**
  String get updateDownloading;

  /// App update step: downloading, with progress
  ///
  /// In bn, this message translates to:
  /// **'নামানো হচ্ছে… {percent}%'**
  String updateDownloadingPercent(String percent);

  /// App update step: verifying the downloaded file
  ///
  /// In bn, this message translates to:
  /// **'ফাইলটা মিলিয়ে দেখা হচ্ছে…'**
  String get updateVerifying;

  /// App update failure: no room
  ///
  /// In bn, this message translates to:
  /// **'ফোনে যথেষ্ট জায়গা নেই। অন্তত {megabytes}MB ফাঁকা করে আবার চেষ্টা করুন।'**
  String updateNotEnoughSpace(String megabytes);

  /// App update failure: partial download
  ///
  /// In bn, this message translates to:
  /// **'নামানো ফাইলটা অসম্পূর্ণ, তাই বসানো হয়নি। আবার চেষ্টা করুন।'**
  String get updateIncomplete;

  /// App update failure: checksum mismatch
  ///
  /// In bn, this message translates to:
  /// **'নামানো ফাইলটা অফিসের দেওয়া ফাইলের সাথে মেলেনি, তাই বসানো হয়নি। ফাইলটা মুছে ফেলা হয়েছে। আবার চেষ্টা করুন; আবারও না মিললে অফিসে জানান।'**
  String get updateMismatch;

  /// App update failure: another app or another signing key
  ///
  /// In bn, this message translates to:
  /// **'নামানো ফাইলটা ABOS-এর নিজের সই করা অ্যাপ নয়, তাই বসানো হয়নি। ফাইলটা মুছে ফেলা হয়েছে। অফিসে জানান — সার্ভারে ভুল ফাইল রাখা হয়েছে।'**
  String get updateForeignPackage;

  /// App update failure: not a newer version
  ///
  /// In bn, this message translates to:
  /// **'নামানো ফাইলটা এই ফোনের অ্যাপের চেয়ে নতুন নয়, তাই বসানো হয়নি — পুরনো সংস্করণে ফেরা যায় না। ফাইলটা মুছে ফেলা হয়েছে। অফিসে জানান।'**
  String get updateNotNewer;

  /// App update failure: network
  ///
  /// In bn, this message translates to:
  /// **'নামানো গেল না। ইন্টারনেট সংযোগ দেখে আবার চেষ্টা করুন।'**
  String get updateDownloadOffline;

  /// App update failure: the installer refused; detail is a short technical reason
  ///
  /// In bn, this message translates to:
  /// **'ফাইল নামানো আর মেলানো হয়েছে, কিন্তু ফোন বসানোর পর্দা খুলতে দিল না। সেটিংসে এই অ্যাপের \"অজানা অ্যাপ বসানো\" অনুমতি চালু করে আবার চাপুন; তবুও না হলে এই লেখাটা অফিসে পাঠান: {detail}'**
  String updateInstallerRefused(String detail);

  /// App update failure: anything else
  ///
  /// In bn, this message translates to:
  /// **'আপডেট বসানো গেল না। আবার চেষ্টা করুন।'**
  String get updateFailed;

  /// Update banner: hides it for now
  ///
  /// In bn, this message translates to:
  /// **'সরিয়ে রাখুন'**
  String get updateDismiss;

  /// Update wall: the build is retired
  ///
  /// In bn, this message translates to:
  /// **'এই সংস্করণটি আর ব্যবহার করা যাবে না'**
  String get updateWallTitle;

  /// Update wall: what to do, when the server sends no note
  ///
  /// In bn, this message translates to:
  /// **'অ্যাপের নতুন সংস্করণ নামিয়ে বসান। পুরনো সংস্করণ ভুল তথ্য দেখাতে পারে।'**
  String get updateWallBody;

  /// Update wall: orders still queued
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনে {count} টি অর্ডার এখনো পাঠানো হয়নি'**
  String updateWallPending(int count);

  /// Update wall: how not to lose queued orders
  ///
  /// In bn, this message translates to:
  /// **'অ্যাপ মুছে ফেললে ওগুলো চলে যাবে। নতুন সংস্করণ বসানোর সময় পুরনোটা মুছবেন না — উপরে বসিয়ে দিন, অর্ডারগুলো থেকে যাবে।'**
  String get updateWallKeepOrders;

  /// Reports list: the module name under a report, the web's module name (server code backup)
  ///
  /// In bn, this message translates to:
  /// **'ব্যাকআপ ও পুনরুদ্ধার'**
  String get reportModuleBackup;

  /// Reports list: the module name under a report, the web's module name (server code fixed_assets)
  ///
  /// In bn, this message translates to:
  /// **'স্থায়ী সম্পদ'**
  String get reportModuleFixedAssets;

  /// Reports list: the module name under a report, the web's module name (server code governance)
  ///
  /// In bn, this message translates to:
  /// **'নিয়ন্ত্রণ ও নিরীক্ষা'**
  String get reportModuleGovernance;

  /// Reports list: the module name under a report, the web's module name (server code master_data)
  ///
  /// In bn, this message translates to:
  /// **'মাস্টার ডাটা'**
  String get reportModuleMasterData;

  /// Reports list: the module name under a report, the web's module name (server code restaurant)
  ///
  /// In bn, this message translates to:
  /// **'রেস্টুরেন্ট'**
  String get reportModuleRestaurant;

  /// Reports list: the module name under a report, the web's module name (server code system_admin)
  ///
  /// In bn, this message translates to:
  /// **'সিস্টেম প্রশাসন'**
  String get reportModuleSystemAdmin;

  /// Sales return: shown in place of the reason picker when the server sends no return reasons; the confirm button stays off
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের কোনো কারণ বসানো নেই। অফিসকে ওয়েবে একটা কারণ যোগ করতে বলুন — কারণ ছাড়া ফেরত নিশ্চিত হয় না।'**
  String get counterReturnNoReasons;

  /// Customer list total: what the shown shops owe minus their advances, as the web's customer list sums it
  ///
  /// In bn, this message translates to:
  /// **'নিট বকেয়া'**
  String get customersTotalNet;

  /// Customer list total: the advances the shown shops have paid, on their own line under the net
  ///
  /// In bn, this message translates to:
  /// **'মোট অগ্রিম'**
  String get customersTotalAdvances;

  /// Beside a total: the list is not limited to any dates, so the total covers all of them
  ///
  /// In bn, this message translates to:
  /// **'সব তারিখ'**
  String get totalsPeriodAllDates;

  /// Beside a total: the chosen date range the total covers, first and last day as dd/MM/yyyy
  ///
  /// In bn, this message translates to:
  /// **'{from} – {to}'**
  String totalsPeriodRange(String from, String to);

  /// Screen reader: which dates the total next to it covers, e.g. 'Period: This month'
  ///
  /// In bn, this message translates to:
  /// **'সময়কাল: {period}'**
  String totalsPeriodSpoken(String period);

  /// Totals bar: a separate line for the rejected rows shown on screen, which the total leaves out, with how many; the amount sits beside it
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যাত, মোটে নেই · {count}টি'**
  String totalsRejectedLeftOut(String count);

  /// Payment voucher, top figures: what we owe the party now
  ///
  /// In bn, this message translates to:
  /// **'পাওনা'**
  String get payFigureOwed;

  /// Payment voucher, top figures: the amount of this payment
  ///
  /// In bn, this message translates to:
  /// **'এই পরিশোধ'**
  String get payFigureThis;

  /// Payment voucher, top figures: paid beyond what is owed; it stays as a debit in the party's own ledger (never called an advance)
  ///
  /// In bn, this message translates to:
  /// **'খাতায় থাকবে (Dr)'**
  String get payFigureStays;

  /// Payment voucher: label over the party type chips (supplier, customer, staff …)
  ///
  /// In bn, this message translates to:
  /// **'পক্ষের ধরন *'**
  String get payPartyType;

  /// Payment voucher: the party field (name · point · branch)
  ///
  /// In bn, this message translates to:
  /// **'পক্ষ *'**
  String get payParty;

  /// Payment voucher: screen-reader hint on the empty party field
  ///
  /// In bn, this message translates to:
  /// **'পক্ষ বাছুন'**
  String get payPickParty;

  /// Payment voucher: what we owe the chosen party, beside the name
  ///
  /// In bn, this message translates to:
  /// **'পাওনা {amount}'**
  String payDueBeside(String amount);

  /// Payment and expense voucher: the money account the money leaves (only the chosen method's accounts)
  ///
  /// In bn, this message translates to:
  /// **'যে খাত থেকে *'**
  String get payFromAccount;

  /// Payment and expense voucher: the chosen method has no account
  ///
  /// In bn, this message translates to:
  /// **'এই কোম্পানিতে {method}-এর কোনো খাত নেই — হিসাবরক্ষককে বলুন।'**
  String payAccountNone(String method);

  /// Expense voucher: a payable account in the paid-from list
  ///
  /// In bn, this message translates to:
  /// **'বাকিতে — {account} (দেনা তৈরি হবে)'**
  String payOnCredit(String account);

  /// Expense voucher: note when the expense is on credit
  ///
  /// In bn, this message translates to:
  /// **'টাকা এখন যাচ্ছে না — এই খরচটা দেনা হিসেবে বসবে, আর পরে পরিশোধ করতে হবে।'**
  String get payOnCreditNote;

  /// Expense voucher on credit: the party the payable is owed to
  ///
  /// In bn, this message translates to:
  /// **'কার কাছে দেনা *'**
  String get payCreditParty;

  /// Payment and expense voucher, MFS: the transaction ID
  ///
  /// In bn, this message translates to:
  /// **'TrxID *'**
  String get payTrxId;

  /// Payment and expense voucher, cheque: the cheque number
  ///
  /// In bn, this message translates to:
  /// **'চেক নম্বর *'**
  String get payChequeNo;

  /// Payment and expense voucher, cheque: the bank of the cheque
  ///
  /// In bn, this message translates to:
  /// **'চেকের ব্যাংক'**
  String get payChequeBank;

  /// Payment and expense voucher, MFS: the charge the MFS took
  ///
  /// In bn, this message translates to:
  /// **'MFS চার্জ (৳)'**
  String get payMfsCharge;

  /// Payment and expense voucher: where the bank or MFS charge goes
  ///
  /// In bn, this message translates to:
  /// **'চার্জ {charge} আমাদের খরচ — চার্জের খাতে যাবে।'**
  String payChargeOurs(String charge);

  /// Payment voucher to a supplier: the read-only bank box
  ///
  /// In bn, this message translates to:
  /// **'প্রাপকের ব্যাংক (সরবরাহকারীর রেকর্ড থেকে — এখানে বদলায় না)'**
  String get payPayeeBank;

  /// Payment voucher: the supplier bank details carry both signatures (a tick icon stands before it)
  ///
  /// In bn, this message translates to:
  /// **'দুজনের অনুমোদনে যাচাই-করা'**
  String get payPayeeBankVerified;

  /// Payment voucher: the supplier has no signed bank details
  ///
  /// In bn, this message translates to:
  /// **'প্রাপকের ব্যাংক তথ্য নেই'**
  String get payPayeeBankNone;

  /// Payment voucher: a change to the supplier bank details waits for the second signature
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায়'**
  String get payPayeeBankPending;

  /// Payment voucher: an unsigned supplier bank blocks a bank transfer (the web words)
  ///
  /// In bn, this message translates to:
  /// **'প্রাপকের ব্যাংক তথ্যে এখনো দ্বিতীয় অনুমোদন হয়নি — ব্যাংকে পরিশোধ করা যাবে না। পথ: সরবরাহকারীর পাতায় ব্যাংক তথ্য বসান, তারপর আরেকজন দ্বিতীয় অনুমোদন দিন। নগদ, MFS বা চেকে এখনই দেওয়া যায়।'**
  String get payUnsignedBlocks;

  /// Payment voucher to a party that is not a supplier: the account the payment is booked to
  ///
  /// In bn, this message translates to:
  /// **'কোন খাতে যাবে *'**
  String get payToAccount;

  /// Expense voucher: the expense account
  ///
  /// In bn, this message translates to:
  /// **'খরচের খাত *'**
  String get payExpenseHead;

  /// Expense voucher: the payee name, typed
  ///
  /// In bn, this message translates to:
  /// **'কাকে দেওয়া হলো'**
  String get payPayee;

  /// Expense voucher: the bill or voucher number on the paper
  ///
  /// In bn, this message translates to:
  /// **'বিল / ভাউচার নম্বর'**
  String get payBillNo;

  /// Expense voucher: the folded AIT/VDS section
  ///
  /// In bn, this message translates to:
  /// **'উৎসে কর্তন'**
  String get payDeductionTitle;

  /// Expense voucher: the bill total before AIT and VDS
  ///
  /// In bn, this message translates to:
  /// **'বিলের মোট (৳)'**
  String get payBillGross;

  /// Expense voucher: advance income tax deducted
  ///
  /// In bn, this message translates to:
  /// **'AIT (৳)'**
  String get payAit;

  /// Expense voucher: VAT deducted at source
  ///
  /// In bn, this message translates to:
  /// **'VDS (৳)'**
  String get payVds;

  /// Expense voucher: the bill total less AIT and VDS
  ///
  /// In bn, this message translates to:
  /// **'হাতে যাবে'**
  String get payNetPayable;

  /// Expense voucher: the method and paid-from section title
  ///
  /// In bn, this message translates to:
  /// **'কীভাবে দেওয়া হলো'**
  String get payHowPaid;

  /// Payment and expense voucher, bottom bar: leave without saving
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get payCancel;

  /// Payment and expense voucher, bottom bar: keep as a draft
  ///
  /// In bn, this message translates to:
  /// **'খসড়া রাখুন'**
  String get payKeepDraft;

  /// Payment and expense voucher, bottom bar: post (or send for signature when signatures are on)
  ///
  /// In bn, this message translates to:
  /// **'পোস্ট'**
  String get payPost;

  /// Payment voucher: refused without a party
  ///
  /// In bn, this message translates to:
  /// **'পক্ষ বাছুন।'**
  String get payPickPartyError;

  /// Expense voucher: refused without an expense head
  ///
  /// In bn, this message translates to:
  /// **'খরচের খাত বাছুন।'**
  String get payPickHeadError;

  /// Payment and expense voucher: refused without the paid-from account
  ///
  /// In bn, this message translates to:
  /// **'যে খাত থেকে দেওয়া হচ্ছে বাছুন।'**
  String get payPickAccountError;

  /// Payment voucher to a non-supplier: refused without the account
  ///
  /// In bn, this message translates to:
  /// **'কোন খাতে যাবে বাছুন।'**
  String get payPickToAccountError;

  /// Expense voucher on credit: refused without the party
  ///
  /// In bn, this message translates to:
  /// **'বাকিতে খরচ লিখতে হলে কার কাছে দেনা হচ্ছে সেটা বলতে হবে।'**
  String get payCreditNeedsParty;

  /// Payment and expense voucher: post refused without the cheque number
  ///
  /// In bn, this message translates to:
  /// **'চেক নম্বর লিখুন — চেক নম্বর ছাড়া পোস্ট হয় না।'**
  String get payChequeNoMissing;

  /// Payment and expense voucher: the account does not fit the method (the web words)
  ///
  /// In bn, this message translates to:
  /// **'\"{account}\" {method}-এর খাত নয় — পদ্ধতির খাত বাছুন।'**
  String payMismatch(String account, String method);

  /// Payment voucher (contract 2): the gross amount, what the party's ledger goes down by
  ///
  /// In bn, this message translates to:
  /// **'মোট টাকা *'**
  String get payGross;

  /// Payment voucher summary: the gross amount as typed
  ///
  /// In bn, this message translates to:
  /// **'মোট টাকা'**
  String get payGrossShort;

  /// Payment voucher (contract 2): the open bills section title
  ///
  /// In bn, this message translates to:
  /// **'খোলা বিল — পুরনো আগে'**
  String get payBillsTitle;

  /// Payment voucher bill row: what is still due on the bill
  ///
  /// In bn, this message translates to:
  /// **'বাকি {amount}'**
  String payBillDue(String amount);

  /// Payment voucher bill row: the amount set against this bill
  ///
  /// In bn, this message translates to:
  /// **'এই বিলে (৳)'**
  String get payBillApply;

  /// Payment voucher: the sum set against the ticked bills
  ///
  /// In bn, this message translates to:
  /// **'বিলে বসল'**
  String get payBillsApplied;

  /// Payment voucher: the total above the ticked bills; it stays as a debit in the supplier's own ledger (2111) — never called an advance
  ///
  /// In bn, this message translates to:
  /// **'বিলে প্রয়োগ হয়নি — সরবরাহকারীর খাতায় থাকবে (Dr): {amount}'**
  String payBillsUnappliedSupplier(String amount);

  /// Payment voucher to a non-supplier party: the total above the ticked bills stays as a debit in the party's own ledger — never called an advance
  ///
  /// In bn, this message translates to:
  /// **'বিলে প্রয়োগ হয়নি — পক্ষের খাতায় থাকবে (Dr): {amount}'**
  String payBillsUnappliedParty(String amount);

  /// Payment voucher bill row: the typed amount was above the bill's due and was capped
  ///
  /// In bn, this message translates to:
  /// **'বিলের বাকি {amount}-এর বেশি বসে না।'**
  String payBillOverDue(String amount);

  /// Payment voucher: the bills add up to more than the gross; nothing is sent
  ///
  /// In bn, this message translates to:
  /// **'বিলে বসানো টাকা মোট টাকার বেশি — মোট বাড়ান বা বিলের অঙ্ক কমান।'**
  String get payBillsOverGross;

  /// Payment voucher (contract 2): the summary section title
  ///
  /// In bn, this message translates to:
  /// **'হিসাব'**
  String get paySummaryTitle;

  /// Payment voucher summary: the bank or MFS charge, booked as our expense
  ///
  /// In bn, this message translates to:
  /// **'চার্জ — মোটের বাইরে, কোম্পানির খরচ'**
  String get payChargeOutside;

  /// Payment voucher summary: the net amount, only as the server returned it
  ///
  /// In bn, this message translates to:
  /// **'নিট — সার্ভারের হিসাবে'**
  String get payNetFromServer;

  /// Payment voucher summary: a ledger line's debit from the server
  ///
  /// In bn, this message translates to:
  /// **'ডেবিট {amount}'**
  String payLedgerDr(String amount);

  /// Payment voucher summary: a ledger line's credit from the server
  ///
  /// In bn, this message translates to:
  /// **'ক্রেডিট {amount}'**
  String payLedgerCr(String amount);

  /// Payment voucher summary: no net yet; the server can show it
  ///
  /// In bn, this message translates to:
  /// **'নিট সার্ভার গোনে — দেখতে \"হিসাব দেখুন\" চাপুন।'**
  String get payNetAsk;

  /// Payment voucher summary: no net yet and no preview door on this server
  ///
  /// In bn, this message translates to:
  /// **'নিট সার্ভার গোনে — খসড়া রাখলে বা পোস্টের পরে ভাউচারে দেখাবে।'**
  String get payNetLater;

  /// Payment voucher: ask the server for the net and the ledger, nothing is saved
  ///
  /// In bn, this message translates to:
  /// **'হিসাব দেখুন'**
  String get payPreview;

  /// Voucher page: the net amount the server counted
  ///
  /// In bn, this message translates to:
  /// **'নিট'**
  String get vouchersPageNet;

  /// Screen bar: the module name above the title on the order and tracking screens
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়'**
  String get ordersModuleSales;

  /// A queued order whose customer is no longer on this phone
  ///
  /// In bn, this message translates to:
  /// **'অজানা গ্রাহক'**
  String get ordersUnknownCustomer;

  /// Rejected order card: the red pill
  ///
  /// In bn, this message translates to:
  /// **'ব্যর্থ'**
  String get ordersRejectedFailed;

  /// Rejected order card: how many products the order had
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count}টা পণ্য}}'**
  String ordersRejectedItemCount(int count);

  /// Rejected order card: tooltip of the close button that removes the card
  ///
  /// In bn, this message translates to:
  /// **'বুঝেছি, সরিয়ে ফেলুন'**
  String get ordersRejectedDismiss;

  /// Rejected order card: button that opens a new order filled from the rejected one
  ///
  /// In bn, this message translates to:
  /// **'নতুন করে লিখুন'**
  String get ordersRejectedRewrite;

  /// Payment advice: its state when the server sends no label of its own
  ///
  /// In bn, this message translates to:
  /// **'{status, select, pending{পাঠানো} verifying{যাচাই চলছে} accepted{গৃহীত} rejected{প্রত্যাখ্যাত} other{যাচাই চলছে}}'**
  String ordersDepositStatus(String status);

  /// Delivery tracking step: the order came in
  ///
  /// In bn, this message translates to:
  /// **'অর্ডার এসেছে'**
  String get ordersTrackingStepOrdered;

  /// Delivery tracking step: held because the customer is over the credit limit
  ///
  /// In bn, this message translates to:
  /// **'বাকির সীমায় আটকে'**
  String get ordersTrackingStepCreditHold;

  /// Delivery tracking step: draft
  ///
  /// In bn, this message translates to:
  /// **'খসড়া'**
  String get ordersTrackingStepDraft;

  /// Delivery tracking step: waiting for approval
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায়'**
  String get ordersTrackingStepApproval;

  /// Delivery tracking step: being picked in the warehouse
  ///
  /// In bn, this message translates to:
  /// **'গুদামে'**
  String get ordersTrackingStepWarehouse;

  /// Delivery tracking step: the goods left through the gate
  ///
  /// In bn, this message translates to:
  /// **'গেট পেরিয়েছে'**
  String get ordersTrackingStepGateOut;

  /// Delivery tracking step: part of the goods delivered
  ///
  /// In bn, this message translates to:
  /// **'কিছু পৌঁছেছে'**
  String get ordersTrackingStepPartial;

  /// Delivery tracking step: delivered
  ///
  /// In bn, this message translates to:
  /// **'পৌঁছেছে'**
  String get ordersTrackingStepDelivered;

  /// Delivery tracking step: cancelled
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get ordersTrackingStepCancelled;

  /// Delivery tracking step and chip: the sale has been billed
  ///
  /// In bn, this message translates to:
  /// **'বিল হয়েছে'**
  String get ordersTrackingStepBilled;

  /// Order list screen title
  ///
  /// In bn, this message translates to:
  /// **'অর্ডারের অবস্থা'**
  String get ordersListTitle;

  /// Order list: the last pull failed
  ///
  /// In bn, this message translates to:
  /// **'অর্ডারের তালিকা আনা যায়নি'**
  String get ordersListLoadFailed;

  /// Order list: empty state title
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো অর্ডার নেই'**
  String get ordersListEmptyTitle;

  /// Order list: empty state hint to pull down
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার চেষ্টা করুন।'**
  String get ordersListEmptyMessage;

  /// Order list: button to start a new order
  ///
  /// In bn, this message translates to:
  /// **'নতুন অর্ডার'**
  String get ordersNewOrder;

  /// Order list: section header for orders waiting on this phone; count in English digits
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষমাণ ({count})'**
  String ordersListPendingHeader(String count);

  /// Order list: pill on the waiting-orders card
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষমাণ'**
  String get ordersListPendingPill;

  /// Order list: the waiting orders go out once there is a connection
  ///
  /// In bn, this message translates to:
  /// **'সংযোগ পেলেই পাঠানো হবে — অর্ডার এই ফোনেই আছে।'**
  String get ordersListPendingNote;

  /// Order list: section header for orders the server refused
  ///
  /// In bn, this message translates to:
  /// **'যা যায়নি ({count})'**
  String ordersListRejectedHeader(String count);

  /// Order list: section header for orders confirmed by the server
  ///
  /// In bn, this message translates to:
  /// **'সিঙ্ক হয়েছে ({count})'**
  String ordersListSyncedHeader(String count);

  /// Order list: nothing matches the chosen filter
  ///
  /// In bn, this message translates to:
  /// **'এই ফিল্টারে কোনো অর্ডার নেই।'**
  String get ordersListFilterEmpty;

  /// Order list: an order without its number yet
  ///
  /// In bn, this message translates to:
  /// **'নম্বর নেই'**
  String get ordersListNoNumber;

  /// Delivery tracking: the list could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get ordersTrackingLoadFailed;

  /// Delivery tracking: the server does not have this feature yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ডেলিভারি ট্র্যাকিং এখনো আসেনি — অফিসে জানান।'**
  String get ordersTrackingAbsent;

  /// Delivery tracking screen title
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি ট্র্যাকিং'**
  String get ordersTrackingTitle;

  /// Delivery tracking: search box hint
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় নম্বর, ডিও বা দোকান'**
  String get ordersTrackingSearchHint;

  /// Delivery tracking: tooltip of the search button
  ///
  /// In bn, this message translates to:
  /// **'খুঁজুন'**
  String get ordersSearch;

  /// Delivery tracking: empty state title
  ///
  /// In bn, this message translates to:
  /// **'কোনো বিক্রি নেই।'**
  String get ordersTrackingEmptyTitle;

  /// Delivery tracking: nothing matches the filter
  ///
  /// In bn, this message translates to:
  /// **'এই ছাঁকনিতে কিছু নেই।'**
  String get ordersTrackingEmptyFiltered;

  /// Delivery tracking: a sale appears here after its gate pass
  ///
  /// In bn, this message translates to:
  /// **'গেট পাস হলে বিক্রি এখানে আসে।'**
  String get ordersTrackingEmptyHint;

  /// Delivery tracking: clear the filter
  ///
  /// In bn, this message translates to:
  /// **'সব দেখুন'**
  String get ordersTrackingShowAll;

  /// Delivery tracking: load the list again
  ///
  /// In bn, this message translates to:
  /// **'নতুন করে দেখুন'**
  String get ordersTrackingReload;

  /// Delivery tracking: a sale without a customer name
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক নেই'**
  String get ordersTrackingNoCustomer;

  /// Delivery tracking chip: the rest of the goods go later (back order)
  ///
  /// In bn, this message translates to:
  /// **'বাকি মাল পরে যাবে'**
  String get ordersTrackingBackOrder;

  /// Delivery tracking: the sale's timeline could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'সময়রেখা আনা গেল না।'**
  String get ordersTrackingStoryFailed;

  /// Delivery tracking timeline: header over who did what and when
  ///
  /// In bn, this message translates to:
  /// **'কে কখন কী করলেন'**
  String get ordersTrackingStoryWho;

  /// Sales return: the bills to return against could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের তালিকা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।'**
  String get counterReturnLoadFailed;

  /// Sales return: popup title when the chosen bill could not be opened
  ///
  /// In bn, this message translates to:
  /// **'বিল খোলা গেল না'**
  String get counterReturnBillFailed;

  /// Counter and sales return: fallback popup text when the server gave no reason
  ///
  /// In bn, this message translates to:
  /// **'আবার চেষ্টা করুন।'**
  String get counterTryAgain;

  /// Sales return: a quantity box holds something that is not a number
  ///
  /// In bn, this message translates to:
  /// **'ফেরতের পরিমাণ পড়া গেল না — কেবল সংখ্যা লিখুন।'**
  String get counterReturnQtyUnreadable;

  /// Sales return: no return reason chosen
  ///
  /// In bn, this message translates to:
  /// **'কারণ বাছুন।'**
  String get counterReturnPickReason;

  /// Sales return: every quantity is empty
  ///
  /// In bn, this message translates to:
  /// **'অন্তত একটা মালের ফেরত পরিমাণ দিন।'**
  String get counterReturnNeedQty;

  /// Sales return: popup title when the person backs out of the summary
  ///
  /// In bn, this message translates to:
  /// **'খসড়া ফেরত রাখা হলো'**
  String get counterReturnDraftKeptTitle;

  /// Sales return: the draft stays on the web, unconfirmed
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত হয়নি — ওয়েবের ফেরতের তালিকায় খসড়া হয়ে আছে।'**
  String get counterReturnDraftKeptText;

  /// Sales return: popup title after the return is confirmed
  ///
  /// In bn, this message translates to:
  /// **'ফেরত হলো'**
  String get counterReturnDone;

  /// Counter and sales return: popup title when the paper waits for a signature
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায়'**
  String get counterAwaitingSignature;

  /// Sales return: popup title when the return could not be made
  ///
  /// In bn, this message translates to:
  /// **'ফেরত হলো না'**
  String get counterReturnFailed;

  /// Counter and sales return: popup button that closes it
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get counterOk;

  /// Sales return screen title
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি ফেরত'**
  String get counterReturnTitle;

  /// Sales return: heading over the bill list
  ///
  /// In bn, this message translates to:
  /// **'কোন বিলের ফেরত'**
  String get counterReturnWhichBill;

  /// Sales return: empty state title
  ///
  /// In bn, this message translates to:
  /// **'ফেরত দেওয়ার মতো কোনো নিশ্চিত বিল নেই।'**
  String get counterReturnEmptyTitle;

  /// Sales return: empty state hint (fe: posted is পোস্ট, not পাকা)
  ///
  /// In bn, this message translates to:
  /// **'বিল পোস্ট হলে এখানে আসবে।'**
  String get counterReturnEmptyHint;

  /// Counter and sales return: load the list again
  ///
  /// In bn, this message translates to:
  /// **'নতুন করে দেখুন'**
  String get counterReload;

  /// Sales return: the chosen bill number
  ///
  /// In bn, this message translates to:
  /// **'বিল {no}'**
  String counterReturnBillNo(String no);

  /// Sales return line: the lot number
  ///
  /// In bn, this message translates to:
  /// **'লট: {lot}'**
  String counterLotNo(String lot);

  /// Sales return line: quantity sold times the rate (rate from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'বেচা: {qty} × {rate}'**
  String counterReturnSold(String qty, String rate);

  /// Sales return line: the quantity box label
  ///
  /// In bn, this message translates to:
  /// **'কত ফেরত'**
  String get counterReturnQtyLabel;

  /// Sales return: the reason dropdown label
  ///
  /// In bn, this message translates to:
  /// **'কেন ফেরত'**
  String get counterReturnReasonLabel;

  /// Counter and sales return: the free-text note label
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ (ঐচ্ছিক)'**
  String get counterNoteLabel;

  /// Counter and sales return: button that shows the summary before confirming
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত করুন'**
  String get counterConfirm;

  /// Sales return: back to the bill list
  ///
  /// In bn, this message translates to:
  /// **'অন্য বিল বাছুন'**
  String get counterReturnOtherBill;

  /// New order: the filter button and the filter sheet title
  ///
  /// In bn, this message translates to:
  /// **'ফিল্টার'**
  String get ordersFilter;

  /// New order filter: only products with an offer
  ///
  /// In bn, this message translates to:
  /// **'অফার আছে'**
  String get ordersFilterOffer;

  /// New order filter: only products this shop bought before
  ///
  /// In bn, this message translates to:
  /// **'এই দোকান আগে নিয়েছে'**
  String get ordersFilterBoughtBefore;

  /// New order: the sold quantity (column head and the label under the quantity)
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি'**
  String get ordersSale;

  /// New order: the free quantity (column head and the label under it)
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি'**
  String get ordersFree;

  /// Keypad: the carton unit when the product has no name for it
  ///
  /// In bn, this message translates to:
  /// **'কার্টন'**
  String get ordersKeypadCarton;

  /// Keypad: how many pieces one carton holds; numbers as text, English digits
  ///
  /// In bn, this message translates to:
  /// **'{one} {carton} = {factor} পিস'**
  String ordersKeypadCartonFactor(String one, String carton, String factor);

  /// Keypad: the pieces box
  ///
  /// In bn, this message translates to:
  /// **'পিস'**
  String get ordersKeypadPieces;

  /// Keypad: the total pieces box
  ///
  /// In bn, this message translates to:
  /// **'মোট পিস'**
  String get ordersKeypadTotalPieces;

  /// Keypad: quick button with the quantity of the last order
  ///
  /// In bn, this message translates to:
  /// **'আগের মতো ({qty})'**
  String ordersKeypadLikeLast(String qty);

  /// Keypad: screen reader name of the backspace key
  ///
  /// In bn, this message translates to:
  /// **'মুছুন'**
  String get ordersKeypadBackspace;

  /// Keypad: the button that puts the quantity in the cart
  ///
  /// In bn, this message translates to:
  /// **'ঝুড়িতে যোগ'**
  String get ordersKeypadAddToCart;

  /// Keypad: what this line comes to (from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'এই লাইনে {amount}'**
  String ordersKeypadLineAmount(String amount);

  /// Keypad: hint under the keys
  ///
  /// In bn, this message translates to:
  /// **'কার্টন বা পিস যেটায় খুশি লিখুন'**
  String get ordersKeypadEitherUnit;

  /// New order sort: as the catalogue lists them
  ///
  /// In bn, this message translates to:
  /// **'তালিকার ক্রমে'**
  String get ordersSortCatalogue;

  /// New order sort: by name
  ///
  /// In bn, this message translates to:
  /// **'নাম ধরে'**
  String get ordersSortName;

  /// New order sort: by price
  ///
  /// In bn, this message translates to:
  /// **'দাম ধরে'**
  String get ordersSortPrice;

  /// Filter sheet: clear every choice
  ///
  /// In bn, this message translates to:
  /// **'সব মুছুন'**
  String get ordersFilterClearAll;

  /// Filter sheet: heading over the show-only choices
  ///
  /// In bn, this message translates to:
  /// **'দেখান'**
  String get ordersFilterShowHeading;

  /// Filter sheet: heading over the sort choices
  ///
  /// In bn, this message translates to:
  /// **'সাজান'**
  String get ordersFilterSortHeading;

  /// Filter sheet: apply button; n picks the form, count is n as text (English digits in both languages)
  ///
  /// In bn, this message translates to:
  /// **'{n, plural, other{{count}টা পণ্য দেখান}}'**
  String ordersFilterApply(int n, String count);

  /// Order confirm sheet title
  ///
  /// In bn, this message translates to:
  /// **'পাঠানোর আগে দেখে নিন'**
  String get ordersConfirmTitle;

  /// Order confirm: the customer account is loading
  ///
  /// In bn, this message translates to:
  /// **'হিসাব আনা হচ্ছে…'**
  String get ordersConfirmStandingLoading;

  /// Order confirm: offline, the account cannot be loaded
  ///
  /// In bn, this message translates to:
  /// **'নেট নেই — হিসাব পরে দেখা যাবে'**
  String get ordersConfirmStandingOffline;

  /// Order confirm: the account could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'হিসাব এখন আনা গেল না — অর্ডার তবু যাবে, হিসাব পরে দেখা যাবে'**
  String get ordersConfirmStandingFailed;

  /// Order confirm tile: money the customer has paid in advance
  ///
  /// In bn, this message translates to:
  /// **'জমা আছে'**
  String get ordersConfirmAdvance;

  /// Order confirm tile: payments waiting for approval
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায়'**
  String get ordersConfirmPendingClaims;

  /// Order confirm tile: what the customer must pay
  ///
  /// In bn, this message translates to:
  /// **'দিতে হবে'**
  String get ordersConfirmToPay;

  /// Order confirm: the order crosses the absolute credit limit (amount from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'বাকির সীমা পার হচ্ছে — অর্ডার যাবে, কিন্তু {amount} জমা না হলে ডেলিভারি হবে না। এই সীমা কেউ পার করাতে পারেন না, মালিকও না।'**
  String ordersConfirmOverLimit(String amount);

  /// Order confirm table: product column
  ///
  /// In bn, this message translates to:
  /// **'পণ্য'**
  String get ordersConfirmColProduct;

  /// Order confirm table: amount column
  ///
  /// In bn, this message translates to:
  /// **'টাকা'**
  String get ordersConfirmColAmount;

  /// Order confirm table: total row
  ///
  /// In bn, this message translates to:
  /// **'মোট'**
  String get ordersConfirmTotal;

  /// Order confirm: the note box label
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য (ঐচ্ছিক)'**
  String get ordersConfirmRemarks;

  /// Order confirm: send the order
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে, পাঠান'**
  String get ordersConfirmSend;

  /// Order confirm: back to the cart
  ///
  /// In bn, this message translates to:
  /// **'ফিরে যান'**
  String get ordersConfirmBack;

  /// Order confirm: the bank slip feature is not ready
  ///
  /// In bn, this message translates to:
  /// **'শীঘ্রই আসছে'**
  String get ordersConfirmSoon;

  /// Order screens: button that closes a popup
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get ordersOk;

  /// Order confirm: send a bank slip
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক স্লিপ পাঠান'**
  String get ordersConfirmBankSlip;

  /// New order: the scanned barcode matched nothing
  ///
  /// In bn, this message translates to:
  /// **'এই বারকোডে কোনো পণ্য নেই'**
  String get ordersNewNoBarcodeProduct;

  /// New order: the order is queued on the phone
  ///
  /// In bn, this message translates to:
  /// **'অর্ডার লেখা হয়েছে। নম্বর সিঙ্কের পর আসবে — এখনই বলা যাবে না।'**
  String get ordersNewQueued;

  /// New order: the customer picker and its search hint
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক বাছুন'**
  String get ordersNewPickCustomer;

  /// New order: no products on the phone
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের তালিকা এখনো সিঙ্ক হয়নি'**
  String get ordersNewNoProductsTitle;

  /// New order: how to get the products
  ///
  /// In bn, this message translates to:
  /// **'পণ্যের পর্দায় গিয়ে একবার নিচে টানুন, তারপর ফিরে আসুন।'**
  String get ordersNewNoProductsHint;

  /// New order: product search hint
  ///
  /// In bn, this message translates to:
  /// **'নাম বা কোড লিখুন'**
  String get ordersNewSearchHint;

  /// New order: the barcode button
  ///
  /// In bn, this message translates to:
  /// **'বারকোড স্ক্যান'**
  String get ordersNewScanBarcode;

  /// New order: tooltip that removes a filter chip
  ///
  /// In bn, this message translates to:
  /// **'সরান'**
  String get ordersNewChipRemove;

  /// New order: search found nothing
  ///
  /// In bn, this message translates to:
  /// **'কোনো মিল পাওয়া যায়নি'**
  String get ordersNewNoMatch;

  /// New order row: the product is in the cart
  ///
  /// In bn, this message translates to:
  /// **'✓ ঝুড়িতে আছে'**
  String get ordersNewInCart;

  /// New order row: no price for this product
  ///
  /// In bn, this message translates to:
  /// **'দর জানা নেই'**
  String get ordersNewNoRate;

  /// New order row: screen reader name of the minus button
  ///
  /// In bn, this message translates to:
  /// **'কমান'**
  String get ordersNewLess;

  /// New order row: screen reader name of the plus button
  ///
  /// In bn, this message translates to:
  /// **'বাড়ান'**
  String get ordersNewMore;

  /// New order row: screen reader name of the quantity
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ'**
  String get ordersNewQty;

  /// New order row: screen reader hint on the quantity
  ///
  /// In bn, this message translates to:
  /// **'সংখ্যা লিখুন'**
  String get ordersNewQtyHint;

  /// New order row: what the sold quantity comes to (from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি · {amount}'**
  String ordersNewSaleAmount(String amount);

  /// New order row: the free quantity the offer put in
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি · নিজে বসেছে'**
  String get ordersNewFreeAuto;

  /// New order bottom bar: pieces sold and free; numbers as text, English digits
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি {sold} পিস · ফ্রি {free}'**
  String ordersNewBarPieces(String sold, String free);

  /// New order bottom bar: send the order
  ///
  /// In bn, this message translates to:
  /// **'পাঠান'**
  String get ordersNewSend;

  /// Barcode dialog title
  ///
  /// In bn, this message translates to:
  /// **'বারকোড'**
  String get ordersNewBarcodeTitle;

  /// Barcode dialog: the field hint
  ///
  /// In bn, this message translates to:
  /// **'স্ক্যান করুন বা লিখুন'**
  String get ordersNewBarcodeHint;

  /// New order dues card: the credit limit (from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'সীমা {amount}'**
  String ordersNewDueLimit(String amount);

  /// New order dues card: what is left under the limit (from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'বাকি {amount}'**
  String ordersNewDueHeadroom(String amount);

  /// New order dues card: the credit days
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{{days} দিন}}'**
  String ordersNewDueDays(int days);

  /// New order dues card: the customer was over the limit before this order
  ///
  /// In bn, this message translates to:
  /// **'সীমা আগেই পেরিয়ে আছে — এই অর্ডারে {amount} যোগ হবে।'**
  String ordersNewOverAlready(String amount);

  /// New order dues card: this order crosses the limit by amount
  ///
  /// In bn, this message translates to:
  /// **'এই অর্ডারে সীমা {amount} ছাড়িয়ে যাবে।'**
  String ordersNewOverBy(String amount);

  /// New order dues card: when the dues were last synced (from stampText)
  ///
  /// In bn, this message translates to:
  /// **'বকেয়ার তথ্য সিঙ্ক {when}'**
  String ordersNewDueSynced(String when);

  /// Counter: the setup could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'কাউন্টার খোলা গেল না। নেট আছে কি না দেখে আবার চেষ্টা করুন।'**
  String get counterLoadFailed;

  /// Counter: the server does not have the phone counter yet
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের কাউন্টার এখনো আসেনি — অফিসে জানান।'**
  String get counterAbsent;

  /// Counter: stands in for the unit name when the product has none
  ///
  /// In bn, this message translates to:
  /// **'এই একক'**
  String get counterThisUnit;

  /// Counter: this unit takes whole numbers only
  ///
  /// In bn, this message translates to:
  /// **'{unit} ভগ্নাংশে বিক্রি হয় না — পূর্ণ সংখ্যা লিখুন।'**
  String counterNoFraction(String unit);

  /// Counter: too many decimals in the quantity
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ {places} দশমিকের বেশি নয় (যেমন 2.5 বা 0.125)।'**
  String counterQtyDecimals(String places);

  /// Counter: no warehouse, so no lots
  ///
  /// In bn, this message translates to:
  /// **'গুদাম পাওয়া যায়নি — গুদাম ছাড়া লট আসে না।'**
  String get counterNoWarehouseLot;

  /// Counter: no product chosen
  ///
  /// In bn, this message translates to:
  /// **'পণ্য বাছুন।'**
  String get counterPickProduct;

  /// Counter: a lot-tracked product needs a lot
  ///
  /// In bn, this message translates to:
  /// **'লট বাছুন — এই পণ্য লট ধরে বিক্রি হয়।'**
  String get counterPickLot;

  /// Counter: no quantity
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ দিন।'**
  String get counterNeedQty;

  /// Counter: a zero rate is refused
  ///
  /// In bn, this message translates to:
  /// **'দর ছাড়া বিক্রি হয় না।'**
  String get counterNeedRate;

  /// Counter: the discount percent is out of range
  ///
  /// In bn, this message translates to:
  /// **'ছাড় {min} থেকে {max}%।'**
  String counterDiscountRange(String min, String max);

  /// Counter: more free quantity than the scheme allows
  ///
  /// In bn, this message translates to:
  /// **'স্কিমে ফ্রি সর্বোচ্চ {max}।'**
  String counterFreeMax(String max);

  /// Counter: no warehouse, so no sale
  ///
  /// In bn, this message translates to:
  /// **'গুদাম পাওয়া যায়নি — গুদাম ছাড়া বিক্রি হয় না।'**
  String get counterNoWarehouseSale;

  /// Counter: no buyer chosen
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতা বাছুন।'**
  String get counterPickBuyer;

  /// Counter: the cart is empty
  ///
  /// In bn, this message translates to:
  /// **'কার্টে অন্তত একটা পণ্য দিন।'**
  String get counterCartEmpty;

  /// Counter popup title: the sale is done
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি হলো'**
  String get counterSold;

  /// Counter popup title: the sale is kept as a draft
  ///
  /// In bn, this message translates to:
  /// **'খসড়া রাখা হলো'**
  String get counterDraftKept;

  /// Counter popup: open the new bill
  ///
  /// In bn, this message translates to:
  /// **'বিলটা দেখুন'**
  String get counterOpenBill;

  /// Counter popup title: the sale was refused
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি হলো না'**
  String get counterSaleFailed;

  /// Counter: fallback when the sale failed without a reason
  ///
  /// In bn, this message translates to:
  /// **'বিক্রি করা গেল না। আবার চেষ্টা করুন।'**
  String get counterSaleFailedRetry;

  /// Counter popup title: the summary before confirming could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'সারাংশ আনা গেল না'**
  String get counterOverviewFailed;

  /// Counter: fallback when the server could not be reached
  ///
  /// In bn, this message translates to:
  /// **'নেট আছে কি না দেখে আবার চেষ্টা করুন।'**
  String get counterCheckNet;

  /// Counter: the money-taken sheet title and its button
  ///
  /// In bn, this message translates to:
  /// **'টাকা নেওয়া'**
  String get counterMoneyTitle;

  /// Counter money sheet: a payment reference
  ///
  /// In bn, this message translates to:
  /// **'রেফারেন্স: {ref}'**
  String counterMoneyReference(String ref);

  /// Counter: remove a payment or a cart line
  ///
  /// In bn, this message translates to:
  /// **'বাদ দিন'**
  String get counterRemove;

  /// Counter money sheet: the payment method
  ///
  /// In bn, this message translates to:
  /// **'কীভাবে'**
  String get counterMoneyMethod;

  /// Counter money sheet: the money account
  ///
  /// In bn, this message translates to:
  /// **'কোন খাতে'**
  String get counterMoneyAccount;

  /// Counter money sheet: the amount box
  ///
  /// In bn, this message translates to:
  /// **'টাকা'**
  String get counterMoneyAmount;

  /// Counter money sheet: the reference box
  ///
  /// In bn, this message translates to:
  /// **'রেফারেন্স / লেনদেন নম্বর'**
  String get counterMoneyReferenceLabel;

  /// Counter money sheet: add one more payment
  ///
  /// In bn, this message translates to:
  /// **'আরেকটা টাকা যোগ করুন'**
  String get counterMoneyAddAnother;

  /// Counter money sheet: the total taken (from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'মোট নেওয়া: {amount}'**
  String counterMoneyTotal(String amount);

  /// Counter money sheet: the amount is not a number
  ///
  /// In bn, this message translates to:
  /// **'অঙ্কটা পড়া গেল না — কেবল সংখ্যা লিখুন, যেমন 1250।'**
  String get counterUnreadableAmount;

  /// Counter: the vehicle and fare sheet title and its button
  ///
  /// In bn, this message translates to:
  /// **'গাড়ি ও ভাড়া'**
  String get counterVehicleTitle;

  /// Counter vehicle sheet: whose vehicle
  ///
  /// In bn, this message translates to:
  /// **'কার গাড়ি'**
  String get counterVehicleOwner;

  /// Counter vehicle sheet: our own vehicle
  ///
  /// In bn, this message translates to:
  /// **'আমাদের গাড়ি'**
  String get counterVehicleOwn;

  /// Counter vehicle sheet: a hired vehicle
  ///
  /// In bn, this message translates to:
  /// **'ভাড়ার গাড়ি'**
  String get counterVehicleHired;

  /// Counter vehicle sheet: the buyer's vehicle
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতার গাড়ি'**
  String get counterVehicleCustomer;

  /// Counter vehicle sheet: no vehicle
  ///
  /// In bn, this message translates to:
  /// **'গাড়ি লাগবে না'**
  String get counterVehicleNone;

  /// Counter vehicle sheet: the carrier list
  ///
  /// In bn, this message translates to:
  /// **'বাহক (পরিবহনকারী)'**
  String get counterCarrier;

  /// Counter vehicle sheet: a carrier typed by hand
  ///
  /// In bn, this message translates to:
  /// **'বাহকের নাম (তালিকায় না থাকলে)'**
  String get counterCarrierName;

  /// Counter vehicle sheet: the fare amount
  ///
  /// In bn, this message translates to:
  /// **'ভাড়া'**
  String get counterFare;

  /// Counter vehicle sheet: who pays the fare
  ///
  /// In bn, this message translates to:
  /// **'ভাড়া কে দেবে'**
  String get counterFarePaidBy;

  /// Counter vehicle sheet: we pay, as our cost
  ///
  /// In bn, this message translates to:
  /// **'আমরা — আমাদের খরচ'**
  String get counterFareUs;

  /// Counter vehicle sheet: we pay and add it to the bill
  ///
  /// In bn, this message translates to:
  /// **'আমরা দেব, বিলে যোগ হবে'**
  String get counterFareUsAddToBill;

  /// Counter vehicle sheet: the buyer pays the driver
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতা চালককে দেবেন'**
  String get counterFareCustomer;

  /// Counter vehicle sheet: no fare
  ///
  /// In bn, this message translates to:
  /// **'ভাড়া নেই'**
  String get counterFareNone;

  /// Counter vehicle sheet: the fare is paid now
  ///
  /// In bn, this message translates to:
  /// **'এখনই দিলাম'**
  String get counterFareNow;

  /// Counter vehicle sheet: the fare is paid later
  ///
  /// In bn, this message translates to:
  /// **'পরে দেব'**
  String get counterFareLater;

  /// Counter vehicle sheet: the account the fare came from
  ///
  /// In bn, this message translates to:
  /// **'কোন খাত থেকে দিলেন'**
  String get counterFareAccount;

  /// Counter vehicle sheet: the bank or MFS transaction id
  ///
  /// In bn, this message translates to:
  /// **'লেনদেন নম্বর (TrxID)'**
  String get counterFareTrxId;

  /// Counter vehicle sheet: the person who paid the fare
  ///
  /// In bn, this message translates to:
  /// **'কে দিলেন'**
  String get counterFarePayer;

  /// Counter vehicle sheet: the carrier owed the fare
  ///
  /// In bn, this message translates to:
  /// **'বাহক — যাঁকে পরে দেবেন'**
  String get counterFareCarrierLater;

  /// Counter: the delivery sheet title
  ///
  /// In bn, this message translates to:
  /// **'মাল কীভাবে যাবে'**
  String get counterDeliveryTitle;

  /// Counter delivery sheet: taken now
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতা এখনই নিয়ে যাবেন'**
  String get counterDeliveryTakeNow;

  /// Counter delivery sheet: sent later
  ///
  /// In bn, this message translates to:
  /// **'পরে পাঠানো হবে'**
  String get counterDeliverySendLater;

  /// Counter delivery sheet: collected later
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতা পরে এসে নেবেন'**
  String get counterDeliveryPickupLater;

  /// Counter delivery sheet: the address
  ///
  /// In bn, this message translates to:
  /// **'কোথায় পাঠানো হবে'**
  String get counterShipTo;

  /// Counter delivery sheet: no date chosen yet
  ///
  /// In bn, this message translates to:
  /// **'কবে পাঠানো হবে — তারিখ বাছুন'**
  String get counterShipDatePick;

  /// Counter delivery sheet: the chosen date
  ///
  /// In bn, this message translates to:
  /// **'পাঠানোর তারিখ: {date}'**
  String counterShipDate(String date);

  /// Counter popup title: a kept draft could not be opened
  ///
  /// In bn, this message translates to:
  /// **'খসড়া খোলা গেল না'**
  String get counterDraftOpenFailed;

  /// Counter price check: the product picker title
  ///
  /// In bn, this message translates to:
  /// **'দাম দেখুন — পণ্য বাছুন'**
  String get counterPriceCheckPick;

  /// Counter price check: popup title when the price could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'দাম আনা গেল না'**
  String get counterPriceFailed;

  /// Counter price check: the rate per unit (rate from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয় দর: {rate} / {unit}'**
  String counterPriceRate(String rate, String unit);

  /// Counter price check: the person may not see stock
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়যোগ্য মজুদ: দেখার অনুমতি নেই'**
  String get counterPriceStockHidden;

  /// Counter price check: the saleable stock
  ///
  /// In bn, this message translates to:
  /// **'বিক্রয়যোগ্য মজুদ: {qty} {unit}'**
  String counterPriceStock(String qty, String unit);

  /// Counter price check: a lot number
  ///
  /// In bn, this message translates to:
  /// **'লট {no}'**
  String counterPriceLot(String no);

  /// Counter: the lot expiry date
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ {date}'**
  String counterPriceExpiry(String date);

  /// Counter: the quantity left in a lot
  ///
  /// In bn, this message translates to:
  /// **'আছে {qty}'**
  String counterPriceHave(String qty);

  /// Counter: close a sheet or dialog without acting
  ///
  /// In bn, this message translates to:
  /// **'ফিরে যান'**
  String get counterBack;

  /// Counter price check: put the product in the entry row
  ///
  /// In bn, this message translates to:
  /// **'বিলে তুলুন'**
  String get counterPriceToBill;

  /// Counter: print the last bill again (button and popup title)
  ///
  /// In bn, this message translates to:
  /// **'আবার ছাপা'**
  String get counterReprint;

  /// Counter: nothing to reprint yet
  ///
  /// In bn, this message translates to:
  /// **'এই কাউন্টারে এখনো কোনো বিল হয়নি। পুরনো বিল ছাপতে বিলের তালিকা থেকে খুলুন।'**
  String get counterReprintNone;

  /// Counter: title of the print sheet for the last bill
  ///
  /// In bn, this message translates to:
  /// **'বিল · {no}'**
  String counterBillTitle(String no);

  /// Counter: cancel the bill being written (button, dialog and popup title)
  ///
  /// In bn, this message translates to:
  /// **'বিল বাতিল'**
  String get counterVoidBill;

  /// Counter: the cart is empty and no draft is open
  ///
  /// In bn, this message translates to:
  /// **'বাতিল করার মতো কিছু নেই।'**
  String get counterVoidNothing;

  /// Counter cancel dialog: the reason
  ///
  /// In bn, this message translates to:
  /// **'কেন বাতিল (বাধ্যতামূলক)'**
  String get counterVoidReason;

  /// Counter cancel dialog: the red button
  ///
  /// In bn, this message translates to:
  /// **'বাতিল করুন'**
  String get counterVoidConfirm;

  /// Counter popup title: the bill is cancelled
  ///
  /// In bn, this message translates to:
  /// **'বিল বাতিল হলো'**
  String get counterVoided;

  /// Counter popup: the cancel is recorded with the reason
  ///
  /// In bn, this message translates to:
  /// **'কারণসহ খাতায় লেখা থাকল।'**
  String get counterVoidedNote;

  /// Counter popup title: the cancel failed
  ///
  /// In bn, this message translates to:
  /// **'বাতিল হলো না'**
  String get counterVoidFailed;

  /// Counter: the server warns of a duplicate bill
  ///
  /// In bn, this message translates to:
  /// **'একই বিল আগেই হয়েছে'**
  String get counterRepeatTitle;

  /// Counter duplicate dialog: the tick that allows a repeat
  ///
  /// In bn, this message translates to:
  /// **'জেনেশুনে আবার একই বিল করছি'**
  String get counterRepeatTick;

  /// Counter duplicate dialog: send the same bill again
  ///
  /// In bn, this message translates to:
  /// **'আবার করুন'**
  String get counterRepeatGo;

  /// Counter screen title
  ///
  /// In bn, this message translates to:
  /// **'সরাসরি বিক্রয়'**
  String get counterTitle;

  /// Counter: a kept draft is open for editing
  ///
  /// In bn, this message translates to:
  /// **'খসড়া {no} খোলা আছে'**
  String counterResumeBanner(String no);

  /// Counter: confirming the open draft posts it (fe: পোস্ট, not পাকা)
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত করলে এই খসড়াটাই পোস্ট হবে, নতুন বিল নয়।'**
  String get counterResumeNote;

  /// Counter: the buyer row and the buyer picker title
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতা বাছুন'**
  String get counterPickBuyerTitle;

  /// Counter: the price check button
  ///
  /// In bn, this message translates to:
  /// **'দাম দেখুন'**
  String get counterPriceCheck;

  /// Counter: the branch has no active warehouse
  ///
  /// In bn, this message translates to:
  /// **'এই শাখায় কোনো চালু গুদাম পাওয়া যায়নি — গুদাম ছাড়া লট আসে না, বিক্রিও হয় না। অফিসে জানান।'**
  String get counterNoWarehouseBranch;

  /// Counter: no warehouse chosen yet
  ///
  /// In bn, this message translates to:
  /// **'গুদাম ঠিক হয়নি — নিচে গুদাম বাছুন; গুদাম ছাড়া লট আসে না।'**
  String get counterWarehouseNotSet;

  /// Counter: the warehouse dropdown
  ///
  /// In bn, this message translates to:
  /// **'গুদাম'**
  String get counterWarehouse;

  /// Counter: the payment terms dropdown
  ///
  /// In bn, this message translates to:
  /// **'শর্ত'**
  String get counterTerm;

  /// Counter: the product row and the product picker title
  ///
  /// In bn, this message translates to:
  /// **'পণ্য বাছুন'**
  String get counterPickProductTitle;

  /// Counter: the lot dropdown
  ///
  /// In bn, this message translates to:
  /// **'লট'**
  String get counterLot;

  /// Counter: the quantity box
  ///
  /// In bn, this message translates to:
  /// **'পরিমাণ'**
  String get counterQty;

  /// Counter: the rate box
  ///
  /// In bn, this message translates to:
  /// **'দর'**
  String get counterRate;

  /// Counter: the discount box; any discount needs the owner to sign
  ///
  /// In bn, this message translates to:
  /// **'ছাড় % (দিলে মালিকের অনুমোদন লাগবে)'**
  String get counterDiscountLabel;

  /// Counter: the free quantity box
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি'**
  String get counterFree;

  /// Counter: the free quantity box when a scheme caps it
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি (স্কিমে সর্বোচ্চ {max})'**
  String counterFreeMaxLabel(String max);

  /// Counter: put the entry row in the cart
  ///
  /// In bn, this message translates to:
  /// **'কার্টে যোগ করুন'**
  String get counterAddToCart;

  /// Counter: save changes to a cart line
  ///
  /// In bn, this message translates to:
  /// **'হালনাগাদ করুন'**
  String get counterUpdate;

  /// Counter cart line: its discount
  ///
  /// In bn, this message translates to:
  /// **'ছাড়: {percent}%'**
  String counterLineDiscount(String percent);

  /// Counter cart line: its free quantity
  ///
  /// In bn, this message translates to:
  /// **'ফ্রি: {qty}'**
  String counterLineFree(String qty);

  /// Counter cart line: edit it
  ///
  /// In bn, this message translates to:
  /// **'সম্পাদনা'**
  String get counterEdit;

  /// Counter: the cart total (from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'চলতি মোট: {amount}'**
  String counterRunningTotal(String amount);

  /// Counter: the money taken so far (from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'নেওয়া টাকা: {amount}'**
  String counterMoneyTaken(String amount);

  /// Counter: how the goods go, in one line
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি: {mode}'**
  String counterDeliverySummary(String mode);

  /// Counter delivery line: sent later to an address on a date
  ///
  /// In bn, this message translates to:
  /// **'পরে পাঠানো — {to} · {date}'**
  String counterDeliverySummarySend(String to, String date);

  /// Counter delivery line: collected later
  ///
  /// In bn, this message translates to:
  /// **'ক্রেতা পরে নেবেন'**
  String get counterDeliverySummaryPickup;

  /// Counter delivery line: taken now
  ///
  /// In bn, this message translates to:
  /// **'এখনই নিয়ে যাবেন'**
  String get counterDeliverySummaryNow;

  /// Counter: the fare (from Money.taka)
  ///
  /// In bn, this message translates to:
  /// **'ভাড়া: {amount}'**
  String counterFareSummary(String amount);

  /// Counter: the delivery button
  ///
  /// In bn, this message translates to:
  /// **'ডেলিভারি'**
  String get counterDelivery;

  /// Counter: the button that opens the sales return
  ///
  /// In bn, this message translates to:
  /// **'ফেরত'**
  String get counterReturnButton;

  /// Counter: keep the bill as a draft
  ///
  /// In bn, this message translates to:
  /// **'খসড়া রাখুন'**
  String get counterKeepDraft;

  /// Counter: open a kept draft (button and sheet title)
  ///
  /// In bn, this message translates to:
  /// **'খসড়া খুলুন'**
  String get counterOpenDraft;

  /// Counter: confirm the open draft
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত করুন — খসড়া {no}'**
  String counterConfirmDraft(String no);

  /// Counter drafts sheet: the list could not be loaded
  ///
  /// In bn, this message translates to:
  /// **'খসড়া আনা গেল না। নেট আছে কি না দেখে আবার চেষ্টা করুন।'**
  String get counterDraftsLoadFailed;

  /// Counter drafts sheet: how many products a draft has
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count}টি পণ্য}}'**
  String counterDraftLines(int count);

  /// Counter drafts sheet: empty state title
  ///
  /// In bn, this message translates to:
  /// **'রাখা কোনো খসড়া নেই'**
  String get counterDraftsEmpty;

  /// Counter drafts sheet: empty state hint
  ///
  /// In bn, this message translates to:
  /// **'কাউন্টারে \"খসড়া রাখুন\" চাপলে বিল এখানে আসে।'**
  String get counterDraftsEmptyHint;

  /// Counter drafts sheet: close it and write a new bill
  ///
  /// In bn, this message translates to:
  /// **'নতুন বিল লিখুন'**
  String get counterNewBill;

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
  /// **'আমার অনুমোদনের অপেক্ষায়'**
  String get deliveryOrdersFilterMine;

  /// Delivery orders: empty list title
  ///
  /// In bn, this message translates to:
  /// **'{kind, select, order{কোনো আদেশ নেই।} other{কোনো DO নেই।}}'**
  String deliveryOrdersEmpty(String kind);

  /// Delivery orders: empty list when filtered to my signature
  ///
  /// In bn, this message translates to:
  /// **'এখন আপনার অনুমোদনের অপেক্ষায় কিছু নেই।'**
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

  /// Approvals: error when a rejection is sent with no reason (the screen checks first)
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যানের কারণ লেখা বাধ্যতামূলক'**
  String get approvalsRejectReasonRequired;

  /// Approvals: error when a send-back is sent with no reason (the screen checks first)
  ///
  /// In bn, this message translates to:
  /// **'সংশোধনে ফেরতের কারণ লেখা বাধ্যতামূলক'**
  String get approvalsSendBackReasonRequired;

  /// Approvals: title of the last question before a rejection is sent (inbox and signing queue)
  ///
  /// In bn, this message translates to:
  /// **'চূড়ান্ত প্রত্যাখ্যান?'**
  String get approvalsFinalRejectTitle;

  /// Approvals: body of the last question before a rejection; {what} is the paper (type · number · amount). The quoted words are the send-back button
  ///
  /// In bn, this message translates to:
  /// **'{what}\n\nএটা চূড়ান্ত — আর ফেরানো যাবে না। লেখককে ঠিক করার সুযোগ দিতে চাইলে \"সংশোধনে ফেরত দিন\" বেছে নিন।'**
  String approvalsFinalRejectQuestion(String what);

  /// Approvals: cancels the last question; nothing is sent and the paper stays waiting
  ///
  /// In bn, this message translates to:
  /// **'থাক'**
  String get approvalsFinalRejectKeep;

  /// Approvals: confirms the rejection for good
  ///
  /// In bn, this message translates to:
  /// **'হ্যাঁ, প্রত্যাখ্যান'**
  String get approvalsFinalRejectConfirm;

  /// Approvals: the sheet's total line; amounts are server column labels with figures, joined by ' · '
  ///
  /// In bn, this message translates to:
  /// **'মোট — {amounts}'**
  String approvalsSheetTotalLine(String amounts);

  /// Claims: the kind of a request — money asked for in advance
  ///
  /// In bn, this message translates to:
  /// **'অগ্রিম'**
  String get claimsKindAdvance;

  /// Claims: the kind of a request — money already spent, with receipts
  ///
  /// In bn, this message translates to:
  /// **'খরচের দাবি'**
  String get claimsKindExpense;

  /// Claims: fallback state word when the server sends no label — submitted
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায়'**
  String get claimsStateSubmitted;

  /// Claims: fallback state word — approved, the cashier has not paid yet
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদিত — টাকার অপেক্ষায়'**
  String get claimsStateApproved;

  /// Claims: fallback state word — paid
  ///
  /// In bn, this message translates to:
  /// **'পরিশোধিত'**
  String get claimsStatePaid;

  /// Claims: fallback state word — rejected
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যাত'**
  String get claimsStateRejected;

  /// Claims: fallback state word for a state the app does not know
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায়'**
  String get claimsStateOther;

  /// Leave: state of an application — waiting for its signature (fe 21 Oct: was 'অনুমোদনের অপেক্ষায়')
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায়'**
  String get hrLeaveStatePending;

  /// Leave: state — approved (fe 21 Oct: 'অনুমোদিত', not 'মঞ্জুর')
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদিত'**
  String get hrLeaveStateApproved;

  /// Leave: state — rejected (fe 21 Oct: 'প্রত্যাখ্যাত', not 'নামঞ্জুর')
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যাত'**
  String get hrLeaveStateRejected;

  /// Leave: state — the employee withdrew the application
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাহৃত'**
  String get hrLeaveStateCancelled;

  /// My phones: name shown for a phone the server sent without a name
  ///
  /// In bn, this message translates to:
  /// **'ফোন'**
  String get profilePhoneFallbackName;

  /// Profile: title when the server is too old for the profile menu's doors
  ///
  /// In bn, this message translates to:
  /// **'এখনো এই সার্ভারে নেই'**
  String get profileServerMissingTitle;

  /// Profile: message when the server is too old for the profile menu's doors
  ///
  /// In bn, this message translates to:
  /// **'এখনো এই সার্ভারে নেই — সার্ভারের নতুন সংস্করণ এলে চালু হবে।'**
  String get profileServerMissingMessage;

  /// Profile: the server refused the photo and gave no sentence of its own
  ///
  /// In bn, this message translates to:
  /// **'ছবিটা নেওয়া গেল না — JPG বা PNG, 5 MB-এর কম, আরেকটা দিয়ে চেষ্টা করুন।'**
  String get profilePhotoRefused;

  /// Profile: the photo is over the server's size limit (413)
  ///
  /// In bn, this message translates to:
  /// **'ছবিটা খুব বড় — 5 MB-এর কম একটা দিন।'**
  String get profilePhotoTooLarge;

  /// Paper page: the label of the row that holds the state chip
  ///
  /// In bn, this message translates to:
  /// **'অবস্থা'**
  String get statusPaperFactLabel;

  /// Approvals inbox: screen title
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন'**
  String get approvalsInboxTitle;

  /// Approvals inbox: the list did not load (fallback when the server gave no sentence)
  ///
  /// In bn, this message translates to:
  /// **'তালিকা আনা গেল না। আবার চেষ্টা করুন।'**
  String get approvalsInboxLoadFailed;

  /// Approvals inbox: the server has no approvals door (404)
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের সুবিধাটা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।'**
  String get approvalsInboxServerMissing;

  /// Approvals inbox: snack bar after signing (fe 21 Oct: the act is 'সই দিন')
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দেওয়া হলো।'**
  String get approvalsInboxSigned;

  /// Approvals inbox: snack bar after rejecting
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করা হয়েছে।'**
  String get approvalsInboxRejected;

  /// Approvals inbox: snack bar after sending a paper back to its writer for correction
  ///
  /// In bn, this message translates to:
  /// **'সংশোধনে ফেরত পাঠানো হলো।'**
  String get approvalsInboxSentBack;

  /// Approvals inbox: someone else decided this paper first
  ///
  /// In bn, this message translates to:
  /// **'এটি ইতিমধ্যে নিষ্পত্তি হয়ে গেছে।'**
  String get approvalsInboxAlreadyDecided;

  /// Approvals inbox: the person may not decide this paper
  ///
  /// In bn, this message translates to:
  /// **'এই সিদ্ধান্তটি আপনার নয়।'**
  String get approvalsInboxNotYours;

  /// Approvals inbox: the amount changed between opening and signing; nothing was signed
  ///
  /// In bn, this message translates to:
  /// **'কার্ড খোলার পরে কাগজের অঙ্ক বদলেছে — নতুন অঙ্ক দেখে আবার অনুমোদন দিন।'**
  String get approvalsInboxAmountChanged;

  /// Approvals inbox: no network
  ///
  /// In bn, this message translates to:
  /// **'সংযোগ নেই। অনুমোদনের জন্য ইন্টারনেট লাগবে।'**
  String get approvalsInboxOffline;

  /// Approvals inbox: the decision failed for another reason
  ///
  /// In bn, this message translates to:
  /// **'কাজটি সম্পন্ন হয়নি।'**
  String get approvalsInboxActionFailed;

  /// Approvals inbox: the next page did not load
  ///
  /// In bn, this message translates to:
  /// **'আরও আনা গেল না।'**
  String get approvalsInboxMoreFailed;

  /// Approvals inbox: title of the sign dialog (fe 21 Oct: was 'অনুমোদন করবেন?')
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দেবেন?'**
  String get approvalsInboxSignTitle;

  /// Approvals inbox: the button that signs the paper (fe 21 Oct: 'সই দিন', was 'অনুমোদন')
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দিন'**
  String get approvalsInboxSignButton;

  /// Approvals inbox: title of the reject dialog
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করবেন?'**
  String get approvalsInboxRejectTitle;

  /// Approvals inbox: the reject button on the card and in its dialog (final; a last question follows)
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করুন'**
  String get approvalsInboxRejectButton;

  /// Approvals inbox: title of the send-back dialog (the paper returns to its writer to fix; not a rejection)
  ///
  /// In bn, this message translates to:
  /// **'সংশোধনে ফেরত পাঠাবেন?'**
  String get approvalsInboxSendBackTitle;

  /// Approvals inbox: the send-back button on the card and in its dialog (fe 21 Oct: সংশোধনে ফেরত দিন)
  ///
  /// In bn, this message translates to:
  /// **'সংশোধনে ফেরত পাঠান'**
  String get approvalsInboxSendBackButton;

  /// Approvals inbox: title of the error state
  ///
  /// In bn, this message translates to:
  /// **'তালিকা আনা গেল না'**
  String get approvalsInboxLoadFailedTitle;

  /// Approvals inbox: title when nothing waits for this person
  ///
  /// In bn, this message translates to:
  /// **'কিছু অপেক্ষা করছে না'**
  String get approvalsInboxEmptyTitle;

  /// Approvals inbox: message when nothing waits
  ///
  /// In bn, this message translates to:
  /// **'আপনার সিদ্ধান্তের জন্য কোনো নথি নেই।'**
  String get approvalsInboxEmptyMessage;

  /// Approvals inbox: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও দেখুন'**
  String get approvalsInboxShowMore;

  /// Approvals inbox: the signing step this paper is at
  ///
  /// In bn, this message translates to:
  /// **'ধাপ {level}'**
  String approvalsInboxStep(int level);

  /// Approvals inbox: button that opens the paper's lines before signing
  ///
  /// In bn, this message translates to:
  /// **'বিস্তারিত'**
  String get approvalsInboxDetails;

  /// Approvals inbox: button that opens the server's PDF of the paper
  ///
  /// In bn, this message translates to:
  /// **'কাগজ দেখুন'**
  String get approvalsInboxViewPaper;

  /// Approvals inbox: the reason field when rejecting
  ///
  /// In bn, this message translates to:
  /// **'কারণ (বাধ্যতামূলক)'**
  String get approvalsInboxReasonRequired;

  /// Approvals inbox: the remarks field when signing
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য (ঐচ্ছিক)'**
  String get approvalsInboxRemarksOptional;

  /// Approvals inbox: the details sheet did not load
  ///
  /// In bn, this message translates to:
  /// **'বিস্তারিত আনা গেল না।'**
  String get approvalsInboxDetailsFailed;

  /// Approvals inbox: the server sent no lines for this paper
  ///
  /// In bn, this message translates to:
  /// **'এই কাগজের বিস্তারিত ফোনে নেই — \"কাগজ দেখুন\" চেপে পুরো কাগজটা দেখুন।'**
  String get approvalsInboxNoDetails;

  /// Delegation screen: title (signing handed to someone else for a while)
  ///
  /// In bn, this message translates to:
  /// **'দায়িত্ব অর্পণ'**
  String get approvalsDelegationTitle;

  /// Delegation: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'ভারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get approvalsDelegationLoadFailed;

  /// Delegation: the server has no door for it (404)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের ভার দেওয়া এখনো আসেনি — অফিসে জানান।'**
  String get approvalsDelegationServerMissing;

  /// Delegation: title of the revoke dialog
  ///
  /// In bn, this message translates to:
  /// **'ভার ফেরাবেন?'**
  String get approvalsDelegationRevokeTitle;

  /// Delegation: what revoking means; person is the delegate's name
  ///
  /// In bn, this message translates to:
  /// **'{person} আর আপনার হয়ে অনুমোদন দিতে পারবেন না।'**
  String approvalsDelegationRevokeBody(String person);

  /// Delegation: the 'no' button of the revoke dialog
  ///
  /// In bn, this message translates to:
  /// **'না'**
  String get approvalsDelegationNo;

  /// Delegation: button that revokes a hand over
  ///
  /// In bn, this message translates to:
  /// **'ফেরান'**
  String get approvalsDelegationRevoke;

  /// Delegation: snack bar after revoking
  ///
  /// In bn, this message translates to:
  /// **'ভার ফেরানো হলো।'**
  String get approvalsDelegationRevoked;

  /// Delegation: revoking failed
  ///
  /// In bn, this message translates to:
  /// **'ভার ফেরানো গেল না।'**
  String get approvalsDelegationRevokeFailed;

  /// Delegation: button and screen title for a new hand over
  ///
  /// In bn, this message translates to:
  /// **'নতুন ভার'**
  String get approvalsDelegationNew;

  /// Delegation: title of the error state
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get approvalsDelegationLoadFailedTitle;

  /// Delegation: heading over the hand overs others gave me, with how many
  ///
  /// In bn, this message translates to:
  /// **'আমার কাছে যাঁদের ভার · {count} টি'**
  String approvalsDelegationHeldHeading(int count);

  /// Delegation: heading over the hand overs I gave, with how many
  ///
  /// In bn, this message translates to:
  /// **'আমি যাঁদের দিয়েছি · {count} টি'**
  String approvalsDelegationGivenHeading(int count);

  /// Delegation: nobody has handed signing to me
  ///
  /// In bn, this message translates to:
  /// **'এখন কেউ আপনাকে ভার দেননি।'**
  String get approvalsDelegationNoneHeld;

  /// Delegation: empty state title
  ///
  /// In bn, this message translates to:
  /// **'কোনো ভার নেই'**
  String get approvalsDelegationEmptyTitle;

  /// Delegation: empty state message
  ///
  /// In bn, this message translates to:
  /// **'ছুটিতে গেলে \"নতুন ভার\" চেপে কাউকে আপনার হয়ে অনুমোদন দিতে দিন।'**
  String get approvalsDelegationEmptyMessage;

  /// Delegation: I have not handed signing to anyone
  ///
  /// In bn, this message translates to:
  /// **'আপনি কাউকে ভার দেননি।'**
  String get approvalsDelegationNoneGiven;

  /// Delegation card: state — revoked
  ///
  /// In bn, this message translates to:
  /// **'ফেরানো'**
  String get approvalsDelegationStateRevoked;

  /// Delegation card: state — active today
  ///
  /// In bn, this message translates to:
  /// **'চালু'**
  String get approvalsDelegationStateActive;

  /// Delegation card: state — not active today
  ///
  /// In bn, this message translates to:
  /// **'সামনে বা শেষ'**
  String get approvalsDelegationStateOther;

  /// Delegation card: the dates and the state
  ///
  /// In bn, this message translates to:
  /// **'{from} থেকে {until} · {state}'**
  String approvalsDelegationPeriod(String from, String until, String state);

  /// Delegation card: no module chosen means every kind of work
  ///
  /// In bn, this message translates to:
  /// **'সব কাজে'**
  String get approvalsDelegationAllWork;

  /// New hand over: no person chosen
  ///
  /// In bn, this message translates to:
  /// **'কাকে ভার দেবেন, বাছুন।'**
  String get approvalsDelegationChooseWho;

  /// New hand over: snack bar after saving
  ///
  /// In bn, this message translates to:
  /// **'ভার দেওয়া হলো।'**
  String get approvalsDelegationSaved;

  /// New hand over: saving failed
  ///
  /// In bn, this message translates to:
  /// **'ভার দেওয়া গেল না।'**
  String get approvalsDelegationSaveFailed;

  /// New hand over: the person field
  ///
  /// In bn, this message translates to:
  /// **'কার কাছে'**
  String get approvalsDelegationTo;

  /// New hand over: the start-date button
  ///
  /// In bn, this message translates to:
  /// **'শুরু {date}'**
  String approvalsDelegationFrom(String date);

  /// New hand over: the end-date button
  ///
  /// In bn, this message translates to:
  /// **'শেষ {date}'**
  String approvalsDelegationUntil(String date);

  /// New hand over: label over the module chips
  ///
  /// In bn, this message translates to:
  /// **'কোন কাজে (কিছু না বাছলে সব কাজে)'**
  String get approvalsDelegationWhichWork;

  /// New hand over: the reason field
  ///
  /// In bn, this message translates to:
  /// **'কারণ (ঐচ্ছিক)'**
  String get approvalsDelegationReason;

  /// New hand over: hint in the reason field
  ///
  /// In bn, this message translates to:
  /// **'যেমন: ছুটিতে'**
  String get approvalsDelegationReasonHint;

  /// New hand over: the save button while saving
  ///
  /// In bn, this message translates to:
  /// **'দেওয়া হচ্ছে…'**
  String get approvalsDelegationSaving;

  /// New hand over: the save button
  ///
  /// In bn, this message translates to:
  /// **'ভার দিন'**
  String get approvalsDelegationSave;

  /// Signing queues: tab with the papers waiting for my signature (both queues)
  ///
  /// In bn, this message translates to:
  /// **'আমার সইয়ের অপেক্ষায়'**
  String get approvalsQueueTabToSign;

  /// Document approvals: tab with the papers I sent
  ///
  /// In bn, this message translates to:
  /// **'আমার পাঠানো'**
  String get approvalsQueueTabMine;

  /// Signing centre: tab with the signatures I asked for
  ///
  /// In bn, this message translates to:
  /// **'আমার চাওয়া'**
  String get approvalsQueueTabAsked;

  /// Signing centre: tab with the signatures already given
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের ইতিহাস'**
  String get approvalsQueueTabSigned;

  /// Document approvals queue: screen title
  ///
  /// In bn, this message translates to:
  /// **'দলিলের অনুমোদন'**
  String get approvalsQueueDocumentsTitle;

  /// Signing centre: screen title
  ///
  /// In bn, this message translates to:
  /// **'সই কেন্দ্র'**
  String get approvalsQueueSignaturesTitle;

  /// Signing queues: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get approvalsQueueLoadFailed;

  /// Document approvals: the server has no door for it (404)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের দলিলের অনুমোদন এখনো আসেনি — অফিসে জানান।'**
  String get approvalsQueueDocumentsServerMissing;

  /// Signing centre: the server has no door for it (404)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের সই কেন্দ্র এখনো আসেনি — অফিসে জানান।'**
  String get approvalsQueueSignaturesServerMissing;

  /// Signing queues: snack bar after signing
  ///
  /// In bn, this message translates to:
  /// **'সই দেওয়া হলো।'**
  String get approvalsQueueSigned;

  /// Signing queues: signing failed
  ///
  /// In bn, this message translates to:
  /// **'সই দেওয়া গেল না।'**
  String get approvalsQueueSignFailed;

  /// Signing queues: title of the send-back dialog, asking what the writer must fix (fe 21 Oct: সংশোধনে ফেরত)
  ///
  /// In bn, this message translates to:
  /// **'কী ঠিক করতে হবে? (সংশোধনে ফেরত)'**
  String get approvalsQueueSendBackTitle;

  /// Signing queues: closes the reason dialog (send back or reject) without sending
  ///
  /// In bn, this message translates to:
  /// **'বাদ দিন'**
  String get approvalsQueueReasonDismiss;

  /// Signing queues: the reason dialog (send back or reject) was sent empty
  ///
  /// In bn, this message translates to:
  /// **'কারণ লিখতে হবে।'**
  String get approvalsQueueReasonNeeded;

  /// Signing queues: the send-back button on a row and in its dialog; calls send-back, never reject (fe 21 Oct: সংশোধনে ফেরত দিন)
  ///
  /// In bn, this message translates to:
  /// **'সংশোধনে ফেরত দিন'**
  String get approvalsQueueSendBack;

  /// Signing queues: snack bar after sending back
  ///
  /// In bn, this message translates to:
  /// **'সংশোধনে ফেরত পাঠানো হলো।'**
  String get approvalsQueueSentBack;

  /// Signing queues: sending back failed
  ///
  /// In bn, this message translates to:
  /// **'সংশোধনে ফেরত পাঠানো গেল না।'**
  String get approvalsQueueSendBackFailed;

  /// Signing queues: title of the reject dialog that asks for the reason (a final refusal, unlike send back)
  ///
  /// In bn, this message translates to:
  /// **'কেন প্রত্যাখ্যান করছেন?'**
  String get approvalsQueueRejectTitle;

  /// Signing queues: the reject button on a row and in its reason dialog (fe 21 Oct: প্রত্যাখ্যান; final, asks a last question)
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করুন'**
  String get approvalsQueueReject;

  /// Signing queues: snack bar after rejecting
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করা হয়েছে।'**
  String get approvalsQueueRejected;

  /// Signing queues: rejecting failed
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করা গেল না।'**
  String get approvalsQueueRejectFailed;

  /// Signing queues: how many rows the server has
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{মোট {count} টি}}'**
  String approvalsQueueTotal(int count);

  /// Signing queues: title of the error state
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get approvalsQueueLoadFailedTitle;

  /// Signing history: empty
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো সই নেই'**
  String get approvalsQueueNoSignatures;

  /// Signing queues: empty
  ///
  /// In bn, this message translates to:
  /// **'কিছু অপেক্ষায় নেই'**
  String get approvalsQueueNothingWaiting;

  /// Signing history row: who signed and when (server text)
  ///
  /// In bn, this message translates to:
  /// **'সই: {signer} · {at}'**
  String approvalsQueueSignedBy(String signer, String at);

  /// Signing history row: the start of the signed paper's hash
  ///
  /// In bn, this message translates to:
  /// **'ছাপ {hash}…'**
  String approvalsQueueFingerprint(String hash);

  /// Signing queue row: who asked for the signature
  ///
  /// In bn, this message translates to:
  /// **'চেয়েছেন: {name}'**
  String approvalsQueueRequestedBy(String name);

  /// Signing queue row: when the signature is due (server text)
  ///
  /// In bn, this message translates to:
  /// **'শেষ সময়: {due}'**
  String approvalsQueueDue(String due);

  /// Signing queue row: the sign button
  ///
  /// In bn, this message translates to:
  /// **'সই দিন'**
  String get approvalsQueueSign;

  /// Signing queues: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get approvalsQueueMore;

  /// My requests: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'আমার অনুরোধের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get approvalsMyRequestsLoadFailed;

  /// My requests: the server has no door for it (404)
  ///
  /// In bn, this message translates to:
  /// **'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get approvalsMyRequestsServerMissing;

  /// My requests: screen title when the menu gives none
  ///
  /// In bn, this message translates to:
  /// **'আমার অনুরোধ'**
  String get approvalsMyRequestsTitle;

  /// My requests: label of the total strip
  ///
  /// In bn, this message translates to:
  /// **'মোট অনুরোধ'**
  String get approvalsMyRequestsTotalLabel;

  /// My requests: how many requests in all
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count} টি}}'**
  String approvalsMyRequestsTotalValue(int count);

  /// My requests: how many still wait for a signature
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{এখনো অনুমোদনের অপেক্ষায়: {count} টি}}'**
  String approvalsMyRequestsPending(int count);

  /// My requests: empty state title
  ///
  /// In bn, this message translates to:
  /// **'আপনার পাঠানো কোনো অনুরোধ নেই'**
  String get approvalsMyRequestsEmptyTitle;

  /// My requests: empty state message
  ///
  /// In bn, this message translates to:
  /// **'কোনো কাগজ অনুমোদনের জন্য পাঠালে এখানে দেখা যাবে।'**
  String get approvalsMyRequestsEmptyMessage;

  /// My requests: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get approvalsMyRequestsMore;

  /// My requests card: the last decision and who made it (server text)
  ///
  /// In bn, this message translates to:
  /// **'শেষ কথা: {said}'**
  String approvalsMyRequestsLastWord(String said);

  /// My requests card: the reason given with the last decision (server text)
  ///
  /// In bn, this message translates to:
  /// **'কারণ: {remarks}'**
  String approvalsMyRequestsReason(String remarks);

  /// My phones: screen title
  ///
  /// In bn, this message translates to:
  /// **'আমার ফোনগুলো'**
  String get profilePhonesTitle;

  /// Profile screens: the module name shown in the screen bar
  ///
  /// In bn, this message translates to:
  /// **'আমি'**
  String get profileModuleMe;

  /// My phones: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'ফোনের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get profilePhonesLoadFailed;

  /// My phones: dialog title when the chosen phone is the one in hand
  ///
  /// In bn, this message translates to:
  /// **'এই ফোন থেকেই বের হবেন?'**
  String get profilePhonesSignOutHereTitle;

  /// My phones: dialog title for another phone
  ///
  /// In bn, this message translates to:
  /// **'এই ফোন থেকে বের করে দেবেন?'**
  String get profilePhonesSignOutOtherTitle;

  /// My phones: what signing out of this very phone means
  ///
  /// In bn, this message translates to:
  /// **'“{phone}” — এটা আপনার হাতের এই ফোনটাই। বের করলে এখনই লগইন পাতায় ফিরবেন; আবার ঢুকতে পাসওয়ার্ড লাগবে।'**
  String profilePhonesSignOutHereBody(String phone);

  /// My phones: what signing another phone out means
  ///
  /// In bn, this message translates to:
  /// **'“{phone}” থেকে আপনার লগইন এখনই বন্ধ হবে। ঐ ফোনে আবার ঢুকতে পাসওয়ার্ড লাগবে।'**
  String profilePhonesSignOutOtherBody(String phone);

  /// My phones: dialog button that keeps the phone signed in
  ///
  /// In bn, this message translates to:
  /// **'না, থাক'**
  String get profilePhonesKeep;

  /// My phones: button that signs a phone out
  ///
  /// In bn, this message translates to:
  /// **'বের করে দিন'**
  String get profilePhonesSignOut;

  /// My phones: what the screen reader says for the sign-out button, with the phone's name
  ///
  /// In bn, this message translates to:
  /// **'{phone} থেকে বের করে দিন'**
  String profilePhonesSignOutNamed(String phone);

  /// My phones: the phone was signed out elsewhere first
  ///
  /// In bn, this message translates to:
  /// **'“{phone}” আগেই বের হয়ে গেছে — তালিকা নতুন করে আনা হলো।'**
  String profilePhonesAlreadyOut(String phone);

  /// My phones: snack bar after signing a phone out
  ///
  /// In bn, this message translates to:
  /// **'“{phone}” থেকে বের করে দেওয়া হলো।'**
  String profilePhonesSignedOut(String phone);

  /// My phones: signing out failed
  ///
  /// In bn, this message translates to:
  /// **'বের করা গেল না। একটু পরে আবার চেষ্টা করুন।'**
  String get profilePhonesSignOutFailed;

  /// My phones: the server is too old for this screen
  ///
  /// In bn, this message translates to:
  /// **'আমার ফোনগুলো সার্ভারের নতুন সংস্করণ এলে চালু হবে।'**
  String get profilePhonesServerOld;

  /// My phones: empty state title
  ///
  /// In bn, this message translates to:
  /// **'কোনো ফোনে ঢোকা নেই'**
  String get profilePhonesEmptyTitle;

  /// My phones: empty state message
  ///
  /// In bn, this message translates to:
  /// **'নতুন ফোনে লগইন করলে এখানে দেখাবে।'**
  String get profilePhonesEmptyMessage;

  /// My phones row: when the phone was last used; when is a date and time, or the unknown word
  ///
  /// In bn, this message translates to:
  /// **'শেষ ব্যবহার: {when}'**
  String profilePhonesLastUsed(String when);

  /// My phones row: the last use is not known
  ///
  /// In bn, this message translates to:
  /// **'জানা নেই'**
  String get profilePhonesLastUsedUnknown;

  /// My phones row: badge on the phone in hand
  ///
  /// In bn, this message translates to:
  /// **'এই ফোন'**
  String get profilePhonesThisPhone;

  /// Profile: screen title when the server lets the person change the photo
  ///
  /// In bn, this message translates to:
  /// **'প্রোফাইল ও ছবি'**
  String get profileTitlePhoto;

  /// Profile: screen title when the photo cannot be changed
  ///
  /// In bn, this message translates to:
  /// **'আমার প্রোফাইল'**
  String get profileTitlePlain;

  /// Profile: the chosen picture could not be read on the phone
  ///
  /// In bn, this message translates to:
  /// **'ছবিটা পড়া গেল না — আরেকটা ছবি বাছুন।'**
  String get profilePhotoUnreadable;

  /// Profile: snack bar after the photo is saved
  ///
  /// In bn, this message translates to:
  /// **'ছবি বদলানো হলো — ওয়েবেও এই মুখ দেখাবে।'**
  String get profilePhotoChanged;

  /// Profile: uploading the photo failed
  ///
  /// In bn, this message translates to:
  /// **'ছবি পাঠানো গেল না। একটু পরে আবার চেষ্টা করুন।'**
  String get profilePhotoUploadFailed;

  /// Profile: title of the remove dialog
  ///
  /// In bn, this message translates to:
  /// **'ছবিটা সরাবেন?'**
  String get profilePhotoRemoveTitle;

  /// Profile: what removing the photo means
  ///
  /// In bn, this message translates to:
  /// **'সরালে আপনার মুখের জায়গায় নামের প্রথম অক্ষর দেখাবে — ফোনে আর ওয়েবে দুই জায়গাতেই।'**
  String get profilePhotoRemoveBody;

  /// Profile: dialog button that keeps the photo
  ///
  /// In bn, this message translates to:
  /// **'না, থাক'**
  String get profilePhotoKeep;

  /// Profile: dialog button that removes the photo
  ///
  /// In bn, this message translates to:
  /// **'সরান'**
  String get profilePhotoRemoveConfirm;

  /// Profile: snack bar after removing the photo
  ///
  /// In bn, this message translates to:
  /// **'ছবি সরানো হলো।'**
  String get profilePhotoRemoved;

  /// Profile: removing the photo failed
  ///
  /// In bn, this message translates to:
  /// **'ছবি সরানো গেল না। একটু পরে আবার চেষ্টা করুন।'**
  String get profilePhotoRemoveFailed;

  /// Profile: what the screen reader says while the photo uploads
  ///
  /// In bn, this message translates to:
  /// **'ছবি পাঠানো হচ্ছে, {percent} শতাংশ'**
  String profilePhotoSendingSpoken(int percent);

  /// Profile: the text under the upload bar
  ///
  /// In bn, this message translates to:
  /// **'ছবি পাঠানো হচ্ছে… {percent}%'**
  String profilePhotoSending(int percent);

  /// Profile: button that takes a new photo
  ///
  /// In bn, this message translates to:
  /// **'ক্যামেরায় তুলুন'**
  String get profilePhotoCamera;

  /// Profile: button that picks a photo from the gallery
  ///
  /// In bn, this message translates to:
  /// **'গ্যালারি থেকে'**
  String get profilePhotoGallery;

  /// Profile: button that removes the photo
  ///
  /// In bn, this message translates to:
  /// **'ছবি সরান'**
  String get profilePhotoRemove;

  /// Profile: what happens to the photo and who sees it
  ///
  /// In bn, this message translates to:
  /// **'ছবি মাঝখান থেকে বর্গ করে ছোট করা হয়, ছবির ভেতরের জায়গা-সময়ের তথ্য মুছে। কেবল আপনার কোম্পানির সহকর্মীরা দেখেন।'**
  String get profilePhotoPrivacy;

  /// Profile: the line under 'My phones'
  ///
  /// In bn, this message translates to:
  /// **'কোন কোন ফোনে ঢোকা আছেন — হারানো ফোন দূর থেকে বের করে দিন'**
  String get profilePhonesSubtitle;

  /// Profile: screen reader, the face circle without a photo
  ///
  /// In bn, this message translates to:
  /// **'আপনার ছবি নেই — নামের প্রথম অক্ষর দেখানো হচ্ছে'**
  String get profileFaceNone;

  /// Profile: screen reader, the face circle with a photo
  ///
  /// In bn, this message translates to:
  /// **'আপনার ছবি'**
  String get profileFace;

  /// Attendance: snack bar after marking; it is queued on the phone
  ///
  /// In bn, this message translates to:
  /// **'হাজিরা তোলা হয়েছে। সংযোগ পেলে পাঠানো হবে।'**
  String get hrAttendanceMarkedQueued;

  /// Attendance: screen title
  ///
  /// In bn, this message translates to:
  /// **'হাজিরা'**
  String get hrAttendanceTitle;

  /// Attendance: heading over the past days
  ///
  /// In bn, this message translates to:
  /// **'আগের দিনগুলো'**
  String get hrAttendanceEarlierDays;

  /// Attendance: empty history title
  ///
  /// In bn, this message translates to:
  /// **'আগের কোনো হাজিরা সিঙ্ক হয়নি'**
  String get hrAttendanceNoHistoryTitle;

  /// Attendance: empty history message
  ///
  /// In bn, this message translates to:
  /// **'নিচে টেনে আবার চেষ্টা করুন।'**
  String get hrAttendanceNoHistoryMessage;

  /// Attendance: under today's date
  ///
  /// In bn, this message translates to:
  /// **'আজ'**
  String get hrAttendanceToday;

  /// Attendance: today is already marked
  ///
  /// In bn, this message translates to:
  /// **'আজকের হাজিরা তোলা হয়েছে'**
  String get hrAttendanceTodayMarked;

  /// Attendance: today's mark has not reached the office yet
  ///
  /// In bn, this message translates to:
  /// **'সংযোগ পেলে পাঠানো হবে — এখনো এই ফোনেই আছে।'**
  String get hrAttendanceWaitingToSend;

  /// Attendance: button that marks today
  ///
  /// In bn, this message translates to:
  /// **'আজকের হাজিরা দিন'**
  String get hrAttendanceMarkToday;

  /// Attendance day: the time the person came in (server text)
  ///
  /// In bn, this message translates to:
  /// **'ঢুকেছেন {time}'**
  String hrAttendanceIn(String time);

  /// Attendance day: the time the person left (server text)
  ///
  /// In bn, this message translates to:
  /// **'বেরিয়েছেন {time}'**
  String hrAttendanceOut(String time);

  /// Attendance day: the person came late
  ///
  /// In bn, this message translates to:
  /// **'দেরি'**
  String get hrAttendanceLate;

  /// Notifications: the list did not load (fe 21 Oct: 'বিজ্ঞপ্তি', not 'নোটিফিকেশন')
  ///
  /// In bn, this message translates to:
  /// **'বিজ্ঞপ্তি আনা গেল না।'**
  String get notificationsLoadFailed;

  /// Notifications: the server has no door for them (404)
  ///
  /// In bn, this message translates to:
  /// **'বিজ্ঞপ্তি এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।'**
  String get notificationsServerMissing;

  /// Notifications: screen title (fe 21 Oct: 'বিজ্ঞপ্তি')
  ///
  /// In bn, this message translates to:
  /// **'বিজ্ঞপ্তি'**
  String get notificationsTitle;

  /// Notifications: button in the bar that marks every notification read
  ///
  /// In bn, this message translates to:
  /// **'সব পড়া হয়েছে'**
  String get notificationsMarkAllRead;

  /// Notifications: the first load is running
  ///
  /// In bn, this message translates to:
  /// **'আনা হচ্ছে…'**
  String get notificationsLoading;

  /// Notifications: empty state title
  ///
  /// In bn, this message translates to:
  /// **'কোনো বিজ্ঞপ্তি নেই'**
  String get notificationsEmptyTitle;

  /// Notifications: empty state message
  ///
  /// In bn, this message translates to:
  /// **'নতুন কিছু এলে এখানে দেখাবে।'**
  String get notificationsEmptyMessage;

  /// Notice board: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'নোটিশ আনা গেল না।'**
  String get noticesLoadFailed;

  /// Notice board: screen title
  ///
  /// In bn, this message translates to:
  /// **'নোটিশ বোর্ড'**
  String get noticesBoardTitle;

  /// Notice board: how many notices the person has not opened
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count}টা নোটিশ এখনো পড়া হয়নি}}'**
  String noticesUnreadCount(int count);

  /// Notice board: empty state title
  ///
  /// In bn, this message translates to:
  /// **'এখন কোনো নোটিশ নেই'**
  String get noticesEmptyTitle;

  /// Notice board: empty state message
  ///
  /// In bn, this message translates to:
  /// **'প্রতিষ্ঠান নতুন কিছু জানালে এখানে দেখাবে।'**
  String get noticesEmptyMessage;

  /// Notice: snack bar after the person signs (acknowledges) the notice
  ///
  /// In bn, this message translates to:
  /// **'স্বীকৃতি দেওয়া হলো।'**
  String get noticesSigned;

  /// Notice: signing failed
  ///
  /// In bn, this message translates to:
  /// **'স্বীকৃতি দেওয়া গেল না।'**
  String get noticesSignFailed;

  /// Notice: screen title when the notice has no number
  ///
  /// In bn, this message translates to:
  /// **'নোটিশ'**
  String get noticesDetailTitle;

  /// Notice: when the notice stops applying; date is a date and time
  ///
  /// In bn, this message translates to:
  /// **'মেয়াদ {date} পর্যন্ত'**
  String noticesValidUntil(String date);

  /// Notice: the deadline for signing; date is a date and time
  ///
  /// In bn, this message translates to:
  /// **'স্বীকৃতির শেষ সময় {date}'**
  String noticesSignBy(String date);

  /// Notice: the person has signed it
  ///
  /// In bn, this message translates to:
  /// **'আপনি স্বীকৃতি দিয়েছেন'**
  String get noticesYouSigned;

  /// Notice: the sign button while sending
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো হচ্ছে…'**
  String get noticesSending;

  /// Notice: the button that signs (acknowledges) the notice
  ///
  /// In bn, this message translates to:
  /// **'পড়েছি ও মানছি — স্বীকৃতি দিন'**
  String get noticesReadAndAgree;

  /// Notice: where the person stands with it — the server's code picks the word (fe 21 Oct: 'পড়েছেন', 'মেনে নিয়েছেন')
  ///
  /// In bn, this message translates to:
  /// **'{standing, select, unread{পড়েননি} read{পড়েছেন} acknowledged{মেনে নিয়েছেন} overdue{সইয়ের সময় পেরিয়েছে} escalated{উপরে জানানো হয়েছে} other{}}'**
  String noticesStanding(String standing);

  /// Notice: the priority word, as the web's core.notice.priority — the server's code picks it
  ///
  /// In bn, this message translates to:
  /// **'{priority, select, low{কম জরুরি} normal{সাধারণ} important{গুরুত্বপূর্ণ} high{বেশি জরুরি} critical{অত্যন্ত জরুরি} emergency{জরুরি অবস্থা} other{}}'**
  String noticesPriority(String priority);

  /// Attendance register: the day did not load
  ///
  /// In bn, this message translates to:
  /// **'হাজিরা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get hrRegisterAttendanceLoadFailed;

  /// Attendance register: the server has no door for it (404)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের হাজিরার রেজিস্টার এখনো আসেনি — অফিসে জানান।'**
  String get hrRegisterAttendanceServerMissing;

  /// Attendance register: screen title
  ///
  /// In bn, this message translates to:
  /// **'হাজিরার রেজিস্টার'**
  String get hrRegisterAttendanceTitle;

  /// Attendance register: the day button
  ///
  /// In bn, this message translates to:
  /// **'দিন: {date}'**
  String hrRegisterAttendanceDay(String date);

  /// HR registers: title of the error state
  ///
  /// In bn, this message translates to:
  /// **'আনা গেল না'**
  String get hrRegisterLoadFailedTitle;

  /// Attendance register: nobody to list for the day
  ///
  /// In bn, this message translates to:
  /// **'এই দিনে বেতনভুক্ত কোনো কর্মী নেই'**
  String get hrRegisterAttendanceNobody;

  /// Attendance register: how many people the day lists
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{মোট {count} জন}}'**
  String hrRegisterAttendanceTotal(int count);

  /// Attendance register: one state and how many (label from the server)
  ///
  /// In bn, this message translates to:
  /// **'{label}: {count}'**
  String hrRegisterAttendanceCount(String label, int count);

  /// Attendance register row: the server's state word and that the person came late
  ///
  /// In bn, this message translates to:
  /// **'{status} · দেরি'**
  String hrRegisterAttendanceLate(String status);

  /// Leave register: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'ছুটির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get hrRegisterLeaveLoadFailed;

  /// Leave register: the server has no door for it (404)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের ছুটির রেজিস্টার এখনো আসেনি — অফিসে জানান।'**
  String get hrRegisterLeaveServerMissing;

  /// Leave register: title of the reject dialog (fe 21 Oct: 'প্রত্যাখ্যান', the state is 'প্রত্যাখ্যাত')
  ///
  /// In bn, this message translates to:
  /// **'কেন প্রত্যাখ্যান করছেন?'**
  String get hrRegisterLeaveRejectTitle;

  /// Leave register: closes the reject dialog without rejecting
  ///
  /// In bn, this message translates to:
  /// **'বাদ দিন'**
  String get hrRegisterLeaveRejectDismiss;

  /// Leave register: the dialog button that rejects
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করুন'**
  String get hrRegisterLeaveRejectConfirm;

  /// Leave register: no reason was written
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যানের কারণ লিখতে হবে।'**
  String get hrRegisterLeaveRejectReasonNeeded;

  /// Leave register: snack bar after signing (fe 21 Oct: 'সই দিন', not 'মঞ্জুর')
  ///
  /// In bn, this message translates to:
  /// **'ছুটিতে অনুমোদন দেওয়া হলো।'**
  String get hrRegisterLeaveSigned;

  /// Leave register: snack bar after rejecting
  ///
  /// In bn, this message translates to:
  /// **'ছুটি প্রত্যাখ্যান করা হলো।'**
  String get hrRegisterLeaveRejected;

  /// Leave register: signing failed
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দেওয়া গেল না।'**
  String get hrRegisterLeaveSignFailed;

  /// Leave register: rejecting failed
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করা গেল না।'**
  String get hrRegisterLeaveRejectFailed;

  /// Leave register: screen title
  ///
  /// In bn, this message translates to:
  /// **'ছুটির রেজিস্টার'**
  String get hrRegisterLeaveTitle;

  /// Leave register: filter chip — applications still waiting
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায়'**
  String get hrRegisterLeavePending;

  /// Leave register: filter chip — every application
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get hrRegisterLeaveAll;

  /// Leave register: how many applications the server has
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{মোট {count} টি আবেদন}}'**
  String hrRegisterLeaveTotal(int count);

  /// Leave register: nothing waiting
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায় কোনো আবেদন নেই'**
  String get hrRegisterLeaveNonePending;

  /// Leave register: no application at all
  ///
  /// In bn, this message translates to:
  /// **'কোনো আবেদন নেই'**
  String get hrRegisterLeaveNone;

  /// Leave register row: leave type (server), dates and number of days (server text, may be 1.5); days is a number (1.5 for a half day)
  ///
  /// In bn, this message translates to:
  /// **'{type} · {from} থেকে {to} · {days} দিন'**
  String hrRegisterLeaveLine(String type, String from, String to, num days);

  /// Leave register row: who decided (server text)
  ///
  /// In bn, this message translates to:
  /// **'সিদ্ধান্ত: {name}'**
  String hrRegisterLeaveDecider(String name);

  /// Leave register row: the reject button (was 'ফেরত')
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান'**
  String get hrRegisterLeaveReject;

  /// Leave register row: the sign button (fe 21 Oct: 'সই দিন', was 'মঞ্জুর')
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দিন'**
  String get hrRegisterLeaveSign;

  /// Leave register: load the next page
  ///
  /// In bn, this message translates to:
  /// **'আরও'**
  String get hrRegisterLeaveMore;

  /// My leave: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'ছুটির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get hrLeaveLoadFailed;

  /// My leave: the server has no door for it (404)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের ছুটি এখনো আসেনি — অফিসে জানান।'**
  String get hrLeaveServerMissing;

  /// My leave: screen title
  ///
  /// In bn, this message translates to:
  /// **'আমার ছুটি'**
  String get hrLeaveTitle;

  /// My leave: snack bar after applying (fe 21 Oct: 'সইয়ের অপেক্ষায়')
  ///
  /// In bn, this message translates to:
  /// **'ছুটির আবেদন গেছে — অনুমোদনের অপেক্ষায়।'**
  String get hrLeaveSent;

  /// My leave: the button and the screen title for a new application
  ///
  /// In bn, this message translates to:
  /// **'ছুটির আবেদন'**
  String get hrLeaveApply;

  /// Self service: the login is not linked to an employee
  ///
  /// In bn, this message translates to:
  /// **'আপনার নামে কর্মীর খাতা নেই'**
  String get hrNoEmployeeRecord;

  /// My leave: what to do without an employee record
  ///
  /// In bn, this message translates to:
  /// **'ছুটির আবেদন করতে HR-কে বলুন আপনার লগইন কর্মীর খাতার সাথে জুড়ে দিতে।'**
  String get hrLeaveNoEmployeeMessage;

  /// My leave: heading over the balance per leave type
  ///
  /// In bn, this message translates to:
  /// **'এ বছর কত বাকি'**
  String get hrLeaveLeftThisYear;

  /// My leave: the company has no leave types
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো ছুটির ধরন নেই — HR ঠিক করে দেবেন।'**
  String get hrLeaveNoTypes;

  /// My leave: a leave type with no yearly limit; taken is the server's number of days
  ///
  /// In bn, this message translates to:
  /// **'সীমা নেই · নেওয়া {taken} দিন'**
  String hrLeaveNoLimit(String taken);

  /// My leave: days left of the yearly allowance (server numbers)
  ///
  /// In bn, this message translates to:
  /// **'{left} দিন বাকি · মোট {allowed}'**
  String hrLeaveBalance(String left, String allowed);

  /// My leave: heading over my applications
  ///
  /// In bn, this message translates to:
  /// **'আমার আবেদন'**
  String get hrLeaveMyApplications;

  /// My leave: no application yet
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো ছুটির আবেদন নেই'**
  String get hrLeaveNoApplications;

  /// My leave card: the leave type (server) and the number of days (server text, may be 1.5)
  ///
  /// In bn, this message translates to:
  /// **'{type} · {days} দিন'**
  String hrLeaveCardTitle(String type, String days);

  /// My leave card: what the office wrote (server text)
  ///
  /// In bn, this message translates to:
  /// **'অফিসের কথা: {remarks}'**
  String hrLeaveOfficeNote(String remarks);

  /// Apply for leave: no leave type chosen
  ///
  /// In bn, this message translates to:
  /// **'কোন ছুটি, বাছুন।'**
  String get hrLeaveChooseType;

  /// Apply for leave: the days field is not a number (English digits, owner's rule)
  ///
  /// In bn, this message translates to:
  /// **'দিনের সংখ্যা ঠিকভাবে লিখুন — যেমন 2 বা 1.5।'**
  String get hrLeaveDaysShape;

  /// Apply for leave: more days than the chosen dates hold
  ///
  /// In bn, this message translates to:
  /// **'{span, plural, other{দিনের সংখ্যা তারিখের ঘরের চেয়ে বেশি — {span} দিনের বেশি নয়।}}'**
  String hrLeaveDaysTooMany(int span);

  /// Apply for leave: sending failed
  ///
  /// In bn, this message translates to:
  /// **'আবেদন পাঠানো গেল না। আবার চেষ্টা করুন।'**
  String get hrLeaveSendFailed;

  /// Apply for leave: what happens after sending (fe 21 Oct: 'অনুমোদিত', not 'মঞ্জুর')
  ///
  /// In bn, this message translates to:
  /// **'আবেদন যায় অফিসে; অনুমোদিত হলে দিনগুলো হাজিরায় \"ছুটি\" হয়ে বসে।'**
  String get hrLeaveHowItWorks;

  /// Apply for leave: the leave type field
  ///
  /// In bn, this message translates to:
  /// **'কোন ছুটি'**
  String get hrLeaveTypeField;

  /// Apply for leave: a leave type in the list with its days left
  ///
  /// In bn, this message translates to:
  /// **'{name} ({left} দিন বাকি)'**
  String hrLeaveTypeWithLeft(String name, String left);

  /// Apply for leave: the first day
  ///
  /// In bn, this message translates to:
  /// **'কবে থেকে'**
  String get hrLeaveFrom;

  /// Apply for leave: the last day
  ///
  /// In bn, this message translates to:
  /// **'কবে পর্যন্ত'**
  String get hrLeaveUntil;

  /// Apply for leave: the days field (English digits, owner's rule)
  ///
  /// In bn, this message translates to:
  /// **'কত দিন (আধা দিন হলে .5)'**
  String get hrLeaveDaysField;

  /// Apply for leave: the reason field
  ///
  /// In bn, this message translates to:
  /// **'কারণ'**
  String get hrLeaveReasonField;

  /// Apply for leave: the send button
  ///
  /// In bn, this message translates to:
  /// **'পাঠান'**
  String get hrLeaveSend;

  /// Payslips: screen title (one person's slips — fe 21 Oct: 'বেতনের স্লিপ')
  ///
  /// In bn, this message translates to:
  /// **'আমার বেতনের স্লিপ'**
  String get hrPayslipListTitle;

  /// Payslips: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'স্লিপের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get hrPayslipListFailed;

  /// Payslips: the server has no door for them (404)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে ফোনের বেতনের স্লিপ এখনো আসেনি — অফিসে জানান।'**
  String get hrPayslipServerMissing;

  /// Payslips: empty (fe 21 Oct: 'পোস্ট', not 'পাকা')
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো পোস্ট হওয়া বেতনের স্লিপ নেই'**
  String get hrPayslipEmptyTitle;

  /// Payslips: empty state message
  ///
  /// In bn, this message translates to:
  /// **'বেতনের রান পোস্ট হলে স্লিপ এখানে আসে।'**
  String get hrPayslipEmptyMessage;

  /// Payslips: what to do without an employee record
  ///
  /// In bn, this message translates to:
  /// **'HR-কে বলুন আপনার লগইন কর্মীর খাতার সাথে জুড়ে দিতে।'**
  String get hrPayslipNoEmployeeMessage;

  /// Payslips row: gross and deductions (money already written)
  ///
  /// In bn, this message translates to:
  /// **'আয় {gross} · কর্তন {deductions}'**
  String hrPayslipRowLine(String gross, String deductions);

  /// Payslip: how the salary was paid — the server's code picks the word; an unknown code shows as it came
  ///
  /// In bn, this message translates to:
  /// **'{method, select, cash{নগদ} bank{ব্যাংক} mfs{মোবাইল ব্যাংকিং} other{{method}}}'**
  String hrPayslipPaidBy(String method);

  /// Payslip: screen title
  ///
  /// In bn, this message translates to:
  /// **'বেতনের স্লিপ'**
  String get hrPayslipTitle;

  /// Payslip: the slip did not load
  ///
  /// In bn, this message translates to:
  /// **'স্লিপটা আনা গেল না।'**
  String get hrPayslipFailed;

  /// Payslip: the net pay strip
  ///
  /// In bn, this message translates to:
  /// **'নিট বেতন'**
  String get hrPayslipNet;

  /// Payslip: the employee's name
  ///
  /// In bn, this message translates to:
  /// **'নাম'**
  String get hrPayslipName;

  /// Payslip: the month
  ///
  /// In bn, this message translates to:
  /// **'মাস'**
  String get hrPayslipMonth;

  /// Payslip: how it was paid
  ///
  /// In bn, this message translates to:
  /// **'কীভাবে'**
  String get hrPayslipHow;

  /// Payslip: heading over the earnings lines
  ///
  /// In bn, this message translates to:
  /// **'আয়'**
  String get hrPayslipEarnings;

  /// Payslip: total earnings
  ///
  /// In bn, this message translates to:
  /// **'মোট আয়'**
  String get hrPayslipGross;

  /// Payslip: heading over the deduction lines
  ///
  /// In bn, this message translates to:
  /// **'কর্তন'**
  String get hrPayslipDeductions;

  /// Payslip: total deductions
  ///
  /// In bn, this message translates to:
  /// **'মোট কর্তন'**
  String get hrPayslipTotalDeductions;

  /// Claims: the list did not load
  ///
  /// In bn, this message translates to:
  /// **'দাবির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।'**
  String get claimsLoadFailed;

  /// Claims: the server has no door for them (404)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারে খরচের দাবি এখনো আসেনি — অফিসে জানান।'**
  String get claimsServerMissing;

  /// Claims: screen title
  ///
  /// In bn, this message translates to:
  /// **'খরচের দাবি ও অগ্রিম'**
  String get claimsListTitle;

  /// Claims: button and screen title for a new claim
  ///
  /// In bn, this message translates to:
  /// **'নতুন দাবি'**
  String get claimsNew;

  /// Claims: the advance the person still holds
  ///
  /// In bn, this message translates to:
  /// **'হাতে খোলা অগ্রিম'**
  String get claimsOpenAdvance;

  /// Claims: empty list
  ///
  /// In bn, this message translates to:
  /// **'এখনো কোনো দাবি নেই।'**
  String get claimsEmpty;

  /// Claim: the claim did not load
  ///
  /// In bn, this message translates to:
  /// **'দাবিটা আনা গেল না।'**
  String get claimsOneFailed;

  /// Claim: screen title before the number is known
  ///
  /// In bn, this message translates to:
  /// **'দাবি'**
  String get claimsOneTitle;

  /// Claim: expense claim or advance
  ///
  /// In bn, this message translates to:
  /// **'ধরন'**
  String get claimsKind;

  /// Claim: the amount (row label and form field)
  ///
  /// In bn, this message translates to:
  /// **'টাকা'**
  String get claimsAmount;

  /// Claim: the expense head (row label and form field)
  ///
  /// In bn, this message translates to:
  /// **'খরচের খাত'**
  String get claimsHead;

  /// Claim: the day the money was spent (row label and form field)
  ///
  /// In bn, this message translates to:
  /// **'খরচের তারিখ'**
  String get claimsSpentOn;

  /// Claim: the part settled from the open advance
  ///
  /// In bn, this message translates to:
  /// **'অগ্রিম থেকে মিটেছে'**
  String get claimsFromAdvance;

  /// Claim: the part paid in cash
  ///
  /// In bn, this message translates to:
  /// **'নগদে পাবেন'**
  String get claimsInCash;

  /// Claim: the reason (row label and form field)
  ///
  /// In bn, this message translates to:
  /// **'কারণ'**
  String get claimsReason;

  /// Claim: the day it was sent
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো'**
  String get claimsSentOn;

  /// Claim: the day it was rejected
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান'**
  String get claimsRejectedOn;

  /// Claim: the day it was approved
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন'**
  String get claimsApprovedOn;

  /// Claim: the day the cashier paid it
  ///
  /// In bn, this message translates to:
  /// **'টাকা দেওয়া'**
  String get claimsPaidOn;

  /// New claim: the expense heads did not load
  ///
  /// In bn, this message translates to:
  /// **'খরচের খাতের তালিকা আনা গেল না।'**
  String get claimsHeadsFailed;

  /// New claim: the amount is not a number (English digits, owner's rule)
  ///
  /// In bn, this message translates to:
  /// **'টাকার অঙ্ক ঠিকভাবে লিখুন — যেমন 500 বা 750.50।'**
  String get claimsAmountShape;

  /// New claim: no expense head chosen
  ///
  /// In bn, this message translates to:
  /// **'কোন খাতের খরচ, বাছুন।'**
  String get claimsChooseHead;

  /// New claim: no reason written
  ///
  /// In bn, this message translates to:
  /// **'কারণ লিখুন।'**
  String get claimsWriteReason;

  /// New claim: sending failed
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো যায়নি। আবার চেষ্টা করুন।'**
  String get claimsSendFailed;

  /// New claim: what happens after sending
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন আর ক্যাশিয়ারের পরে টাকা পাবেন। খরচ আগে মেটে হাতের অগ্রিম থেকে।'**
  String get claimsHowItWorks;

  /// New claim: the money is already spent (expense claim)
  ///
  /// In bn, this message translates to:
  /// **'খরচ হয়ে গেছে'**
  String get claimsKindSpent;

  /// New claim: money wanted in advance
  ///
  /// In bn, this message translates to:
  /// **'অগ্রিম চাই'**
  String get claimsKindAdvanceWanted;

  /// New claim: button that takes the receipt photo
  ///
  /// In bn, this message translates to:
  /// **'রসিদের ছবি তুলুন'**
  String get claimsReceiptTake;

  /// New claim: the receipt photo is taken
  ///
  /// In bn, this message translates to:
  /// **'ছবি নেওয়া হয়েছে — আবার তুলুন'**
  String get claimsReceiptTaken;

  /// New claim: the send button
  ///
  /// In bn, this message translates to:
  /// **'পাঠান'**
  String get claimsSend;

  /// Payslip: label on a slip from a bonus run (run_type 'bonus'); a salary slip has no label
  ///
  /// In bn, this message translates to:
  /// **'উৎসব ভাতা'**
  String get hrPayslipFestivalBonus;

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
  /// **'অনুমোদনের অপেক্ষায়'**
  String get ownerAwaitingSignature;

  /// Owner's centre Today: nothing awaits signature
  ///
  /// In bn, this message translates to:
  /// **'এখন কোনো কাগজ অনুমোদনের অপেক্ষায় নেই।'**
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
  /// **'অনুমোদনের অপেক্ষায় ({count}টা)'**
  String ownerAwaitingSignatureCount(int count);

  /// Owner's centre Alerts: nothing awaits signature
  ///
  /// In bn, this message translates to:
  /// **'কোনো কাগজ অনুমোদনের অপেক্ষায় নেই।'**
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
  /// **'প্রতিটা বিলের সারির আসল FIFO খরচ — বিল পোস্ট হওয়ার সময় স্তর থেকে যা টানা হয়েছিল; ফেরত তার নিজের খরচে বাদ।'**
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
  /// **'অনুমোদন'**
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
  /// **'অনুমোদন দিন'**
  String get papersSign;

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
  /// **'অনুমোদনের অপেক্ষায়'**
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
  /// **'অনুমোদনের অপেক্ষায়'**
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
  /// **'পোস্ট করা গেল না। আবার চেষ্টা করুন।'**
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
  /// **'নিজের লেখা ভাউচার নিজে পোস্ট হয় না — অন্য কেউ খুলে \"পোস্ট করুন\" চাপবেন।'**
  String get vouchersPageAnotherHand;

  /// Voucher page: transaction ID field label
  ///
  /// In bn, this message translates to:
  /// **'লেনদেন নম্বর (TrxID)'**
  String get vouchersPageTrxLabel;

  /// Voucher page: post button
  ///
  /// In bn, this message translates to:
  /// **'পোস্ট করুন'**
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
  /// **'লেনদেন নম্বর (TrxID) — পোস্ট করতে লাগবে'**
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
  /// **'বন্ধ থাকলে সংরক্ষণেই পোস্ট — অনুমোদন লাগলে বা নিজের লেখা হলে খসড়াই থাকবে'**
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
  /// **'অনুমোদনের অপেক্ষায়।'**
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
  /// **'সীমা বাড়ানোর অনুরোধ (অনুমোদনের অপেক্ষায়)'**
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
  /// **'মালিকের অনুমোদন হলে তবেই বসে; ততক্ষণ পুরনো সীমাই চলে।'**
  String get creditSheetOwnerNote;

  /// Limit request: button while sending
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো হচ্ছে…'**
  String get creditSheetSending;

  /// Limit request: button, send for signature
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের জন্য পাঠান'**
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
  /// **'অনুমোদনের অপেক্ষায়'**
  String get creditRequestPending;

  /// Limit request status: signed and applied
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন হয়েছে — বসেছে'**
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
  /// **'মালিকের অনুমোদন নেই — বসেনি'**
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
  /// **'অনুমোদনের অপেক্ষা'**
  String get launcherWidgetApprovalsName;

  /// Approvals widget heading: papers awaiting your signature
  ///
  /// In bn, this message translates to:
  /// **'আপনার অনুমোদনের অপেক্ষায়'**
  String get launcherWidgetApprovalsTitle;

  /// Approvals widget when nothing waits for a signature
  ///
  /// In bn, this message translates to:
  /// **'কোনো অনুমোদন বাকি নেই'**
  String get launcherWidgetApprovalsEmpty;

  /// Old 2x2 approvals widget when nothing waits for a signature
  ///
  /// In bn, this message translates to:
  /// **'এখন অনুমোদনের জন্য কিছু নেই।'**
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
  /// **'{count} অনুমোদন'**
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
  /// **'অনুমোদন'**
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

  /// Collection screen 1: the bar title, the web's page name
  ///
  /// In bn, this message translates to:
  /// **'টাকা নেওয়া'**
  String get collectTitle;

  /// Collection header: the saved paper number
  ///
  /// In bn, this message translates to:
  /// **'নম্বর: {no}'**
  String collectNumber(String no);

  /// Collection header: the branch is always the customer's own (item 14)
  ///
  /// In bn, this message translates to:
  /// **'শাখা: গ্রাহকের শাখা ({branch})'**
  String collectBranchOf(String branch);

  /// Collection header: the accounting month is open (item 13)
  ///
  /// In bn, this message translates to:
  /// **'মাস খোলা'**
  String get collectMonthOpen;

  /// Collection header: the accounting month is closed (item 13)
  ///
  /// In bn, this message translates to:
  /// **'মাস বন্ধ — পোস্ট হবে না'**
  String get collectMonthClosed;

  /// Collection: top figure 1, what the customer owes
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া'**
  String get collectFigureDue;

  /// Collection: top figure 2, the amount being collected
  ///
  /// In bn, this message translates to:
  /// **'এই আদায়'**
  String get collectFigureThis;

  /// Collection: top figure 3, due minus this collection
  ///
  /// In bn, this message translates to:
  /// **'বাকি থাকবে'**
  String get collectFigureLeft;

  /// Collection: top figure 3 when the collection is more than the due
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহকের খাতায় জমা থাকবে'**
  String get collectFigureCredit;

  /// Collection: customer field label (required)
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক *'**
  String get collectCustomer;

  /// Collection: customer field before a choice
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক বাছুন'**
  String get collectPickCustomer;

  /// Collection: the customer's due under the name
  ///
  /// In bn, this message translates to:
  /// **'বকেয়া {amount}'**
  String collectDue(String amount);

  /// Collection: the customer's credit limit under the name (item 1)
  ///
  /// In bn, this message translates to:
  /// **'সীমা {amount}'**
  String collectCreditLimit(String amount);

  /// Collection: days since the oldest unpaid invoice fell due (item 1)
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{পুরনো বাকি {days} দিন}}'**
  String collectOldestOverdue(int days);

  /// Collection: date field label
  ///
  /// In bn, this message translates to:
  /// **'তারিখ *'**
  String get collectDate;

  /// Collection: amount field label
  ///
  /// In bn, this message translates to:
  /// **'টাকা *'**
  String get collectAmount;

  /// Collection: chip that fills the whole due
  ///
  /// In bn, this message translates to:
  /// **'পুরো বকেয়া'**
  String get collectFullDue;

  /// Collection: warning when the amount is more than the due
  ///
  /// In bn, this message translates to:
  /// **'বকেয়ার চেয়ে {extra} বেশি — বাড়তি টাকা গ্রাহকের খাতায় জমা (Cr) থাকবে।'**
  String collectOverDue(String extra);

  /// Collection: the payment method choice
  ///
  /// In bn, this message translates to:
  /// **'পদ্ধতি'**
  String get collectMethod;

  /// Collection method: cash
  ///
  /// In bn, this message translates to:
  /// **'নগদ'**
  String get collectMethodCash;

  /// Collection method: bank transfer, shown as bank
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক'**
  String get collectMethodTransfer;

  /// Collection method: cash deposited into the bank
  ///
  /// In bn, this message translates to:
  /// **'নগদ জমা'**
  String get collectMethodDeposit;

  /// Collection method: cheque
  ///
  /// In bn, this message translates to:
  /// **'চেক'**
  String get collectMethodCheque;

  /// Collection: the money account (web accounts::field.paid_into)
  ///
  /// In bn, this message translates to:
  /// **'যে খাতে জমা *'**
  String get collectAccount;

  /// Collection: the person's own cash account in the list
  ///
  /// In bn, this message translates to:
  /// **'{name} · আমার হাতের নগদ'**
  String collectAccountMine(String name);

  /// Collection: bank transfer mode (web accounts::field.transfer_mode)
  ///
  /// In bn, this message translates to:
  /// **'ট্রান্সফার মোড'**
  String get collectTransferMode;

  /// Collection: the reference or TrxID field
  ///
  /// In bn, this message translates to:
  /// **'রেফারেন্স / TrxID *'**
  String get collectReference;

  /// Collection: the bank deposit slip number for a cash deposit
  ///
  /// In bn, this message translates to:
  /// **'জমার স্লিপ নম্বর *'**
  String get collectDepositSlip;

  /// Collection: deposit slip number required for a cash deposit
  ///
  /// In bn, this message translates to:
  /// **'জমার স্লিপ নম্বর দিন।'**
  String get collectDepositSlipMissing;

  /// Collection: bank value date
  ///
  /// In bn, this message translates to:
  /// **'মূল্য-তারিখ'**
  String get collectValueDate;

  /// Collection: cheque date (web sales::field.instrument_date)
  ///
  /// In bn, this message translates to:
  /// **'চেকের তারিখ'**
  String get collectChequeDate;

  /// Collection: bank charge, one field (item 9)
  ///
  /// In bn, this message translates to:
  /// **'ব্যাংক চার্জ (৳)'**
  String get collectBankCharge;

  /// Collection: what the bank charge does (item 9)
  ///
  /// In bn, this message translates to:
  /// **'চার্জ যায় ব্যাংক চার্জ খাতে; গ্রাহকের খাতায় পুরো টাকা।'**
  String get collectBankChargeHint;

  /// Collection: under a money field when a pasted sign (-, +, ৳) was removed, and announced to the screen reader (fe, 23 Oct 2026)
  ///
  /// In bn, this message translates to:
  /// **'চিহ্ন বাদ দেওয়া হয়েছে — টাকা সবসময় ধনাত্মক'**
  String get collectSignDropped;

  /// Collection: the open items table title (item 3)
  ///
  /// In bn, this message translates to:
  /// **'খোলা বিল ও ক্রেডিট'**
  String get collectBillsTitle;

  /// Collection: the folded bills line, also on an old server
  ///
  /// In bn, this message translates to:
  /// **'নিজে থেকে: পুরনো বিল আগে'**
  String get collectBillsAuto;

  /// Collection: the folded bills line with the invoices
  ///
  /// In bn, this message translates to:
  /// **'নিজে থেকে: পুরনো বিল আগে — {list}'**
  String collectBillsAutoWith(String list);

  /// Collection: unfold the bills to change them
  ///
  /// In bn, this message translates to:
  /// **'বদলান'**
  String get collectBillsChange;

  /// Collection: button that re-applies oldest first (web sales::action.oldest_bills_first)
  ///
  /// In bn, this message translates to:
  /// **'পুরনো বিল আগে'**
  String get collectBillsOldestFirst;

  /// Collection: no open invoice
  ///
  /// In bn, this message translates to:
  /// **'এই গ্রাহকের কোনো খোলা বিল নেই — টাকাটা তাঁর খাতায় জমা থাকবে, পরের বিলে কাটবে।'**
  String get collectBillsNone;

  /// Collection: bills before a customer
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক বাছলে খোলা বিল দেখাবে।'**
  String get collectBillsNeedCustomer;

  /// Collection: open invoices failed to load
  ///
  /// In bn, this message translates to:
  /// **'খোলা বিল আনা গেল না — সার্ভার নিজে পুরনো বিল আগে বসাবে।'**
  String get collectBillsFailed;

  /// Collection: invoice due date
  ///
  /// In bn, this message translates to:
  /// **'শেষ দিন {date}'**
  String collectItemDueOn(String date);

  /// Collection: days overdue
  ///
  /// In bn, this message translates to:
  /// **'{days, plural, other{{days} দিন দেরি}}'**
  String collectItemLate(int days);

  /// Collection: what is still open on the paper
  ///
  /// In bn, this message translates to:
  /// **'বাকি {amount}'**
  String collectItemOpen(String amount);

  /// Collection: the paper's original amount
  ///
  /// In bn, this message translates to:
  /// **'মূল টাকা {amount}'**
  String collectItemOriginal(String amount);

  /// Collection: screen reader label of a row's amount field
  ///
  /// In bn, this message translates to:
  /// **'{no} — এখন প্রয়োগ'**
  String collectItemApplyOf(String no);

  /// Collection: screen reader label of a row's tick
  ///
  /// In bn, this message translates to:
  /// **'{no} প্রয়োগ করুন'**
  String collectItemTick(String no);

  /// Collection: tag of an open credit
  ///
  /// In bn, this message translates to:
  /// **'ক্রেডিট'**
  String get collectItemCredit;

  /// Collection: tag of a credit note (a sales return)
  ///
  /// In bn, this message translates to:
  /// **'ফেরত'**
  String get collectItemCreditNote;

  /// Collection: tag of a debit note
  ///
  /// In bn, this message translates to:
  /// **'ডেবিট নোট'**
  String get collectItemDebitNote;

  /// Collection: total of the bills table
  ///
  /// In bn, this message translates to:
  /// **'মোট প্রয়োগ'**
  String get collectAppliedTotal;

  /// Collection screen 1: under the bills' total, the money not applied to any bill (received + VDS + AIT + discount/rounding − applied); the server credits it to the customer's own ledger, no advance account (fe, 23 Oct 2026)
  ///
  /// In bn, this message translates to:
  /// **'প্রয়োগ হয়নি — গ্রাহকের খাতায় জমা (Cr) থাকবে: {amount}'**
  String collectUnappliedLine(String amount);

  /// Collection screen 1: red stop under the bills' total when more is applied to the bills than the collection covers; posting is off until it is reduced (the server refuses it, allocation_over_amount)
  ///
  /// In bn, this message translates to:
  /// **'প্রয়োগ আদায়ের চেয়ে {extra} বেশি — কমান'**
  String collectAppliedOverGross(String extra);

  /// Collection: an allocation was capped
  ///
  /// In bn, this message translates to:
  /// **'এর বেশি বসানো যায় না — সর্বোচ্চ {amount}।'**
  String collectOverApplied(String amount);

  /// Collection: a credit cannot drop below what the ticked invoices need
  ///
  /// In bn, this message translates to:
  /// **'এর কম নেওয়া যায় না — বসানো বিলের টাকা হাতে থাকতে হবে।'**
  String get collectCreditFloor;

  /// Collection: the difference line (item 4)
  ///
  /// In bn, this message translates to:
  /// **'ছাড় / রাউন্ডিং'**
  String get collectDifference;

  /// Collection: difference kind discount
  ///
  /// In bn, this message translates to:
  /// **'ছাড়'**
  String get collectDifferenceDiscount;

  /// Collection: difference kind rounding
  ///
  /// In bn, this message translates to:
  /// **'রাউন্ডিং'**
  String get collectDifferenceRounding;

  /// Collection: the difference amount field
  ///
  /// In bn, this message translates to:
  /// **'ছাড় / রাউন্ডিংয়ের অঙ্ক'**
  String get collectDifferenceAmount;

  /// Collection: a discount or rounding needs the owner's signature (item 4)
  ///
  /// In bn, this message translates to:
  /// **'মালিকের অনুমোদন লাগবে — পোস্ট করলে অনুমোদনে যাবে।'**
  String get collectDifferenceSign;

  /// Collection: the folded card with discount, rounding, VDS and AIT
  ///
  /// In bn, this message translates to:
  /// **'কাটা ও ছাড়'**
  String get collectWithheldTitle;

  /// Collection: VAT deducted at source by the customer
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক কেটে রেখেছে: উৎসে ভ্যাট (VDS)'**
  String get collectVds;

  /// Collection: income tax deducted at source by the customer
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক কেটে রেখেছে: উৎসে কর (AIT)'**
  String get collectAit;

  /// Collection: the calculation summary card
  ///
  /// In bn, this message translates to:
  /// **'হিসাবের সারাংশ'**
  String get collectSummaryTitle;

  /// Collection summary: amount received
  ///
  /// In bn, this message translates to:
  /// **'পাওয়া টাকা'**
  String get collectSumReceived;

  /// Collection summary: applied to invoices
  ///
  /// In bn, this message translates to:
  /// **'বিলে প্রয়োগ'**
  String get collectSumApplied;

  /// Collection summary: what is left unapplied, the customer's credit (item 4)
  ///
  /// In bn, this message translates to:
  /// **'অপ্রয়োগকৃত'**
  String get collectSumUnapplied;

  /// Collection summary: the check banner with the amount in words
  ///
  /// In bn, this message translates to:
  /// **'মিলেছে · {words}'**
  String collectSumBalanced(String words);

  /// Collection: the journal preview (item 10)
  ///
  /// In bn, this message translates to:
  /// **'খাতায় যা যাবে'**
  String get collectJournalTitle;

  /// Collection journal: total row
  ///
  /// In bn, this message translates to:
  /// **'মোট'**
  String get collectJournalTotal;

  /// Collection journal: debit equals credit
  ///
  /// In bn, this message translates to:
  /// **'মোট মিলেছে'**
  String get collectJournalBalanced;

  /// Collection journal: debit differs from credit
  ///
  /// In bn, this message translates to:
  /// **'মোট মেলেনি — পোস্ট করবেন না, অফিসে জানান।'**
  String get collectJournalUnbalanced;

  /// Collection: the control messages card
  ///
  /// In bn, this message translates to:
  /// **'নিয়ন্ত্রণ'**
  String get collectControlsTitle;

  /// Collection control: no signature needed
  ///
  /// In bn, this message translates to:
  /// **'এই আদায়ে অনুমোদন লাগবে না — পোস্ট করলে সাথে সাথে খাতায় উঠবে।'**
  String get collectControlNoSignature;

  /// Collection control: slip required (item 15)
  ///
  /// In bn, this message translates to:
  /// **'এই কোম্পানিতে স্লিপের ছবি বাধ্যতামূলক।'**
  String get collectControlSlip;

  /// Collection control: posted papers are immutable
  ///
  /// In bn, this message translates to:
  /// **'পোস্টের পরে বদলানো যায় না; ভুল হলে কারণ লিখে \"উল্টান\" (দ্বিতীয় অনুমোদন)।'**
  String get collectControlNoUndo;

  /// Collection control: the server's stops block posting
  ///
  /// In bn, this message translates to:
  /// **'উপরের কারণে পোস্ট হবে না — খসড়া রাখা যায়।'**
  String get collectControlStops;

  /// Collection: attachment and narration card
  ///
  /// In bn, this message translates to:
  /// **'সংযুক্তি ও বিবরণ'**
  String get collectAttachTitle;

  /// Collection: slip photo, required (item 15)
  ///
  /// In bn, this message translates to:
  /// **'স্লিপের ছবি *'**
  String get collectSlipNeeded;

  /// Collection: pick the slip photo
  ///
  /// In bn, this message translates to:
  /// **'গ্যালারি'**
  String get collectSlipGallery;

  /// Collection: remove the chosen photo
  ///
  /// In bn, this message translates to:
  /// **'ছবি সরান'**
  String get collectSlipRemove;

  /// Collection: the chosen photo's name
  ///
  /// In bn, this message translates to:
  /// **'ছবি বাছা হয়েছে: {name}'**
  String collectSlipChosen(String name);

  /// Collection: slip required but missing
  ///
  /// In bn, this message translates to:
  /// **'স্লিপের ছবি দিন — এই কোম্পানিতে বাধ্যতামূলক।'**
  String get collectSlipMissing;

  /// Collection: slip over the server limit
  ///
  /// In bn, this message translates to:
  /// **'ছবিটা 5 MB-এর বেশি — ছোট ছবি দিন।'**
  String get collectSlipTooBig;

  /// Collection: slip upload failed after saving
  ///
  /// In bn, this message translates to:
  /// **'আদায় বসেছে, কিন্তু স্লিপের ছবি পাঠানো গেল না — রসিদের পাতা থেকে আবার দিন।'**
  String get collectSlipFailed;

  /// Collection: narration (web sales::field.narration)
  ///
  /// In bn, this message translates to:
  /// **'বিবরণ'**
  String get collectNarration;

  /// Collection: bottom button, keep as draft
  ///
  /// In bn, this message translates to:
  /// **'খসড়া'**
  String get collectDraft;

  /// Collection: bottom button, opens screen 2 to check before posting
  ///
  /// In bn, this message translates to:
  /// **'দেখে পোস্ট ›'**
  String get collectPost;

  /// Collection: reference required for MFS and bank
  ///
  /// In bn, this message translates to:
  /// **'রেফারেন্স / TrxID দিন।'**
  String get collectReferenceMissing;

  /// Collection: difference bigger than the amount
  ///
  /// In bn, this message translates to:
  /// **'ছাড় / রাউন্ডিং পাওয়া টাকার চেয়ে বেশি হতে পারে না।'**
  String get collectDifferenceTooBig;

  /// Collection screen 2: the bar title (item 10)
  ///
  /// In bn, this message translates to:
  /// **'পোস্টের আগে দেখুন'**
  String get collectCheckTitle;

  /// Collection summary sheet: back to the form
  ///
  /// In bn, this message translates to:
  /// **'ফিরে যান'**
  String get collectCheckBack;

  /// Collection screen 2: the one tap that posts
  ///
  /// In bn, this message translates to:
  /// **'পোস্ট'**
  String get collectCheckPost;

  /// Collection: saved, with its number
  ///
  /// In bn, this message translates to:
  /// **'আদায় বসেছে: {no}'**
  String collectDone(String no);

  /// Collection: saved, the amount in words
  ///
  /// In bn, this message translates to:
  /// **'কথায়: {words}'**
  String collectDoneWords(String words);

  /// Collection: open the receipt PDF (item 17)
  ///
  /// In bn, this message translates to:
  /// **'রসিদ খুলুন'**
  String get collectOpenReceipt;

  /// Collection: close after saving
  ///
  /// In bn, this message translates to:
  /// **'শেষ'**
  String get collectClose;

  /// Collection summary: the check banner without words
  ///
  /// In bn, this message translates to:
  /// **'মিলেছে'**
  String get collectSumBalancedPlain;

  /// Collection summary: the identity the banner checks
  ///
  /// In bn, this message translates to:
  /// **'পাওয়া + কাটা + ছাড় = বিলে প্রয়োগ + অপ্রয়োগকৃত'**
  String get collectSumFormula;

  /// Collection summary: VAT and tax withheld by the customer
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক কেটেছে (VDS/AIT)'**
  String get collectSumWithheld;

  /// Collection summary: the discount, which needs the owner's signature
  ///
  /// In bn, this message translates to:
  /// **'আদায়ে ছাড় (অনুমোদন লাগবে)'**
  String get collectSumDiscount;

  /// Collection summary: rounding; above the company's threshold (server setup rounding_sign_above, else Tk 0.50) the owner signs, in the style of the discount line
  ///
  /// In bn, this message translates to:
  /// **'রাউন্ডিং ({amount}-এর বেশি হলে মালিকের অনুমোদন)'**
  String collectSumRounding(String amount);

  /// Collection: rounding above the company limit (server setup rounding_limit, else Tk 10)
  ///
  /// In bn, this message translates to:
  /// **'রাউন্ডিং {amount}-এর বেশি হতে পারে না।'**
  String collectRoundingTooBig(String amount);

  /// Collection control: online only, idempotent
  ///
  /// In bn, this message translates to:
  /// **'নেট না থাকলে জমা হবে না; আবার চাপলে একটাই রসিদ হবে।'**
  String get collectControlNet;

  /// Collection: the first card
  ///
  /// In bn, this message translates to:
  /// **'সাধারণ তথ্য'**
  String get collectGeneralTitle;

  /// Collection: who the money is from
  ///
  /// In bn, this message translates to:
  /// **'পক্ষের ধরন *'**
  String get collectPartyKind;

  /// Collection party type: customer
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক'**
  String get collectPartyCustomer;

  /// Collection party type: supplier
  ///
  /// In bn, this message translates to:
  /// **'সরবরাহকারী'**
  String get collectPartySupplier;

  /// Collection party type: staff
  ///
  /// In bn, this message translates to:
  /// **'কর্মী'**
  String get collectPartyStaff;

  /// Collection party type: anyone else
  ///
  /// In bn, this message translates to:
  /// **'অন্য'**
  String get collectPartyOther;

  /// Collection: chip that fills the amount with the ticked invoices
  ///
  /// In bn, this message translates to:
  /// **'নির্বাচিত বিল'**
  String get collectSelectedBills;

  /// Collection bank line: the transfer mode
  ///
  /// In bn, this message translates to:
  /// **'মোড {mode}'**
  String collectBankMode(String mode);

  /// Collection bank line: the value date
  ///
  /// In bn, this message translates to:
  /// **'মূল্য-তারিখ {date}'**
  String collectBankValueDate(String date);

  /// Collection bank line: the cheque date
  ///
  /// In bn, this message translates to:
  /// **'চেকের তারিখ {date}'**
  String collectBankChequeDate(String date);

  /// Collection bank line: the bank charge
  ///
  /// In bn, this message translates to:
  /// **'চার্জ {amount}'**
  String collectBankChargeShort(String amount);

  /// Collection bank line: the value date is today
  ///
  /// In bn, this message translates to:
  /// **'আজ'**
  String get collectToday;

  /// Collection: the bank details editor
  ///
  /// In bn, this message translates to:
  /// **'মোড, মূল্য-তারিখ আর চার্জ'**
  String get collectBankEditorTitle;

  /// Collection: close the bank details editor
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get collectBankDone;

  /// Collection: the bills card fold, how many and the order
  ///
  /// In bn, this message translates to:
  /// **'{count, plural, other{{count}টা · পুরনো আগে}}'**
  String collectBillsCount(int count);

  /// Collection: the dashed box, camera half
  ///
  /// In bn, this message translates to:
  /// **'স্লিপের ছবি তুলুন'**
  String get collectSlipTake;

  /// Collection screen 2: the card after posting
  ///
  /// In bn, this message translates to:
  /// **'পোস্টের পরে'**
  String get collectAfterTitle;

  /// Collection: open the receipt PDF (item 17)
  ///
  /// In bn, this message translates to:
  /// **'রসিদ PDF'**
  String get collectReceiptPdf;

  /// Collection: print the receipt
  ///
  /// In bn, this message translates to:
  /// **'ছাপুন'**
  String get collectReceiptPrint;

  /// Collection: a non-customer party type, interim note until one screen serves all four
  ///
  /// In bn, this message translates to:
  /// **'এই ধরনের আদায় এখন পুরনো ফর্মে'**
  String get collectPartyOldNote;

  /// Collection: opens the receipt voucher form for a non-customer party
  ///
  /// In bn, this message translates to:
  /// **'পুরনো ফর্ম খুলুন'**
  String get collectPartyOldOpen;

  /// Collection control: rounding above Tk 0.50 and up to Tk 10 needs the owner's signature (owner, 2 Oct 2026)
  ///
  /// In bn, this message translates to:
  /// **'রাউন্ডিং {amount} — মালিকের অনুমোদনের পর পোস্ট হবে'**
  String collectRoundingSign(String amount);

  /// Collection screen 2 control: a discount, rounding above Tk 0.50 or the server says it needs the owner
  ///
  /// In bn, this message translates to:
  /// **'এই আদায়ে মালিকের অনুমোদন লাগবে — অনুমোদনের পর খাতায় উঠবে'**
  String get collectOwnerSign;

  /// Collection screen 2: the post button when the owner must sign first
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনে পাঠান'**
  String get collectSendForSign;

  /// Collection: the server answers 404 for a customer outside this person's reach (on purpose, so existence is not revealed)
  ///
  /// In bn, this message translates to:
  /// **'এই গ্রাহক আপনার শাখায় নেই'**
  String get collectOutOfBranch;

  /// Collection: the server answers 403 (a walled salesman only gives orders; owner, 7 Oct 2026)
  ///
  /// In bn, this message translates to:
  /// **'আদায় নেওয়ার অনুমতি নেই'**
  String get collectNotAllowed;

  /// Voucher list: income from non-customers, a separate entry
  ///
  /// In bn, this message translates to:
  /// **'অন্য আয়'**
  String get collectOtherIncome;

  /// Voucher list: hint under Other income
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহক নন এমন কারো থেকে, যেমন ভাড়া'**
  String get collectOtherIncomeHint;

  /// Voucher list: hint under the collection voucher
  ///
  /// In bn, this message translates to:
  /// **'গ্রাহকের কাছ থেকে — এক পাতায়'**
  String get collectFromCustomerHint;

  /// Owner-only Net Worth Statement: the home card title and the screen title
  ///
  /// In bn, this message translates to:
  /// **'নিট সম্পদ বিবরণী'**
  String get netWorthTitle;

  /// Net Worth Statement: the date the figures stand at
  ///
  /// In bn, this message translates to:
  /// **'{date} পর্যন্ত'**
  String netWorthAsOf(String date);

  /// Net Worth Statement: assets less liabilities, the big figure
  ///
  /// In bn, this message translates to:
  /// **'নিট সম্পদ'**
  String get netWorthNet;

  /// Net Worth Statement: this financial year's profit
  ///
  /// In bn, this message translates to:
  /// **'এ বছরের লাভ'**
  String get netWorthThisYear;

  /// Net Worth Statement: assets less liabilities equals capital plus profit
  ///
  /// In bn, this message translates to:
  /// **'দুই দিক মেলে — খাতা ঠিক আছে'**
  String get netWorthAgrees;

  /// Net Worth Statement: the two sides differ, the books hold an error
  ///
  /// In bn, this message translates to:
  /// **'দুই দিক মেলে না — খাতায় ভুল আছে'**
  String get netWorthDoesNotAgree;

  /// Net Worth Statement: the server could not be reached
  ///
  /// In bn, this message translates to:
  /// **'নিট সম্পদ বিবরণী আনা গেল না'**
  String get netWorthLoadFailed;

  /// Net Worth Statement tile: cash, mobile money and bank
  ///
  /// In bn, this message translates to:
  /// **'নগদ · MFS · ব্যাংক'**
  String get netWorthCash;

  /// Net Worth Statement tile: trade receivables
  ///
  /// In bn, this message translates to:
  /// **'পাওনা (গ্রাহকের কাছে)'**
  String get netWorthReceivable;

  /// Net Worth Statement tile: inventory at cost
  ///
  /// In bn, this message translates to:
  /// **'মজুদ'**
  String get netWorthStock;

  /// Net Worth Statement tile: fixed and other assets
  ///
  /// In bn, this message translates to:
  /// **'অন্য সম্পদ'**
  String get netWorthOtherAssets;

  /// Net Worth Statement: the assets less liabilities side
  ///
  /// In bn, this message translates to:
  /// **'সম্পদ − দায়'**
  String get netWorthPosition;

  /// Net Worth Statement: the owner's equity side
  ///
  /// In bn, this message translates to:
  /// **'মূলধন + লাভ'**
  String get netWorthEquity;

  /// Net Worth Statement: total assets
  ///
  /// In bn, this message translates to:
  /// **'মোট সম্পদ'**
  String get netWorthAssets;

  /// Net Worth Statement: less trade payables
  ///
  /// In bn, this message translates to:
  /// **'বাদ: দেনা (সরবরাহকারীর)'**
  String get netWorthLessPayable;

  /// Net Worth Statement: less other liabilities
  ///
  /// In bn, this message translates to:
  /// **'বাদ: অন্য দায়'**
  String get netWorthLessOtherLiabilities;

  /// Net Worth Statement: owner's capital
  ///
  /// In bn, this message translates to:
  /// **'মূলধন'**
  String get netWorthCapital;

  /// Net Worth Statement: owner's drawings
  ///
  /// In bn, this message translates to:
  /// **'উত্তোলন'**
  String get netWorthDrawings;

  /// Net Worth Statement: retained earnings of earlier years
  ///
  /// In bn, this message translates to:
  /// **'আগের বছরগুলোর লাভ'**
  String get netWorthEarlier;

  /// Approvals inbox: the chip that shows every kind of work
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get approvalsChipAll;

  /// Approvals inbox: the chip that shows only late papers
  ///
  /// In bn, this message translates to:
  /// **'দেরি'**
  String get approvalsChipLate;

  /// Approvals: which signing step of how many
  ///
  /// In bn, this message translates to:
  /// **'ধাপ {at}/{of}'**
  String approvalsStepOf(int at, int of);

  /// Approvals: deciding on behalf of someone who handed over
  ///
  /// In bn, this message translates to:
  /// **'{name}-এর হয়ে'**
  String approvalsOnBehalf(String name);

  /// Approvals inbox footer: papers on this page
  ///
  /// In bn, this message translates to:
  /// **'এই তালিকায় {count}টা'**
  String approvalsListTotal(int count);

  /// Approvals inbox footer: every paper in the tab
  ///
  /// In bn, this message translates to:
  /// **'সব {count}টা'**
  String approvalsAllTotal(int count);

  /// Approvals inbox: the bulk approve button
  ///
  /// In bn, this message translates to:
  /// **'বাছাই {count}টা · একসাথে অনুমোদন'**
  String approvalsBulkButton(int count);

  /// Approvals inbox: screen-reader name of a card's checkbox
  ///
  /// In bn, this message translates to:
  /// **'বাছুন {paper}'**
  String approvalsPick(String paper);

  /// Bulk approve sheet: the question
  ///
  /// In bn, this message translates to:
  /// **'{count}টা কাগজে একসাথে অনুমোদন দেবেন?'**
  String approvalsBulkTitle(int count);

  /// Bulk approve sheet: total line
  ///
  /// In bn, this message translates to:
  /// **'মোট {count}টা'**
  String approvalsBulkTotal(int count);

  /// Bulk approve sheet: how many were left out
  ///
  /// In bn, this message translates to:
  /// **'আপনি {picked}টা বেছেছিলেন — বাদ পড়ছে {count}টা:'**
  String approvalsBulkSkipped(int picked, int count);

  /// Bulk approve sheet: confirm button
  ///
  /// In bn, this message translates to:
  /// **'{count}টায় অনুমোদন দিন'**
  String approvalsBulkConfirm(int count);

  /// Bulk approve sheet: nothing can go
  ///
  /// In bn, this message translates to:
  /// **'বাছাই থেকে একটাও একসাথে দেওয়া যায় না'**
  String get approvalsBulkNone;

  /// Bulk approve: result
  ///
  /// In bn, this message translates to:
  /// **'{done}টায় অনুমোদন হয়েছে'**
  String approvalsBulkDone(int done);

  /// Bulk approve: result with papers the server left out
  ///
  /// In bn, this message translates to:
  /// **'{done}টায় অনুমোদন হয়েছে · {skipped}টা বাদ'**
  String approvalsBulkDoneSkipped(int done, int skipped);

  /// Approvals: close a sheet without doing anything
  ///
  /// In bn, this message translates to:
  /// **'ফিরে যান'**
  String get approvalsGoBack;

  /// Approvals paper page: back to the inbox, which paper of how many
  ///
  /// In bn, this message translates to:
  /// **'ইনবক্স · {at}/{of}'**
  String approvalsBackToInbox(int at, int of);

  /// Approvals paper page: who asked and when
  ///
  /// In bn, this message translates to:
  /// **'চেয়েছেন {name} · {when}'**
  String approvalsAskedBy(String name, String when);

  /// Approvals paper page: the lines of the paper
  ///
  /// In bn, this message translates to:
  /// **'কাগজের লাইন'**
  String get approvalsLines;

  /// Approvals paper page: open the whole paper
  ///
  /// In bn, this message translates to:
  /// **'পুরো কাগজ'**
  String get approvalsWholePaper;

  /// Approvals paper page: who decided what, step by step
  ///
  /// In bn, this message translates to:
  /// **'যাত্রাপথ'**
  String get approvalsJourney;

  /// Approvals journey: who made the request and when
  ///
  /// In bn, this message translates to:
  /// **'তৈরি — {name} · {when}'**
  String approvalsJourneyCreated(String name, String when);

  /// Approvals journey: a step
  ///
  /// In bn, this message translates to:
  /// **'ধাপ {level}'**
  String approvalsJourneyStep(int level);

  /// Approvals journey: the step the paper is at
  ///
  /// In bn, this message translates to:
  /// **'এখন এখানে'**
  String get approvalsJourneyNow;

  /// Approvals journey: a step not reached yet
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায়'**
  String get approvalsJourneyWaiting;

  /// Approvals journey: how long a step took
  ///
  /// In bn, this message translates to:
  /// **'{hours} ঘণ্টা'**
  String approvalsJourneyTook(int hours);

  /// Approvals paper page: attached slips and bills
  ///
  /// In bn, this message translates to:
  /// **'সংযুক্তি'**
  String get approvalsAttachments;

  /// Approvals paper page: an attachment failed to open
  ///
  /// In bn, this message translates to:
  /// **'ফাইলটা খোলা গেল না'**
  String get approvalsAttachmentFailed;

  /// Approvals paper page: the total under the paper's lines
  ///
  /// In bn, this message translates to:
  /// **'মোট'**
  String get approvalsLinesTotal;

  /// ড্যা-৭: module dashboard watch list title (the web's "নজরে রাখুন")
  ///
  /// In bn, this message translates to:
  /// **'নজরে রাখুন'**
  String get dashboardWatchTitle;

  /// ড্যা-৭: watch list when nothing needs doing
  ///
  /// In bn, this message translates to:
  /// **'সব ঠিক আছে — এখন কিছু করার নেই'**
  String get dashboardWatchAllClear;

  /// ড্যা-৭: the figures below the top four
  ///
  /// In bn, this message translates to:
  /// **'আরও অঙ্ক'**
  String get dashboardMoreFigures;

  /// Inbox summary band: waiting count label
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায়'**
  String get approvalsSumWaiting;

  /// Inbox summary band: late count label
  ///
  /// In bn, this message translates to:
  /// **'দেরি হয়ে গেছে'**
  String get approvalsSumLate;

  /// Inbox summary band: total amount label
  ///
  /// In bn, this message translates to:
  /// **'মোট টাকা'**
  String get approvalsSumAmount;

  /// Inbox summary band: amount in lakh
  ///
  /// In bn, this message translates to:
  /// **'{n} লাখ'**
  String approvalsLakh(String n);

  /// Inbox search field hint
  ///
  /// In bn, this message translates to:
  /// **'নম্বর, পক্ষ বা কে চেয়েছেন — খুঁজুন'**
  String get approvalsSearchHint;

  /// Inbox short tab name
  ///
  /// In bn, this message translates to:
  /// **'অপেক্ষায়'**
  String get approvalsTabWaiting;

  /// Inbox short tab name
  ///
  /// In bn, this message translates to:
  /// **'পাঠিয়েছি'**
  String get approvalsTabSent;

  /// Inbox short tab name
  ///
  /// In bn, this message translates to:
  /// **'দিয়েছি'**
  String get approvalsTabDone;

  /// Inbox short tab name
  ///
  /// In bn, this message translates to:
  /// **'অন্যের হয়ে'**
  String get approvalsTabBehalf;

  /// Inbox sort bar label
  ///
  /// In bn, this message translates to:
  /// **'সাজানো:'**
  String get approvalsSortLabel;

  /// Inbox sort choice
  ///
  /// In bn, this message translates to:
  /// **'দেরি আগে'**
  String get approvalsSortLate;

  /// Inbox sort choice
  ///
  /// In bn, this message translates to:
  /// **'নতুন আগে'**
  String get approvalsSortNew;

  /// Inbox sort choice
  ///
  /// In bn, this message translates to:
  /// **'বড় অঙ্ক আগে'**
  String get approvalsSortAmount;

  /// Inbox group head: past the deadline
  ///
  /// In bn, this message translates to:
  /// **'দেরি হয়ে গেছে'**
  String get approvalsGroupLate;

  /// Inbox group head: near the deadline
  ///
  /// In bn, this message translates to:
  /// **'আজকের মধ্যে'**
  String get approvalsGroupNear;

  /// Inbox group head: time left
  ///
  /// In bn, this message translates to:
  /// **'সময় আছে'**
  String get approvalsGroupOk;

  /// Inbox group head: no deadline set
  ///
  /// In bn, this message translates to:
  /// **'শেষ সময় নেই'**
  String get approvalsGroupNoClock;

  /// Inbox group head count
  ///
  /// In bn, this message translates to:
  /// **'· {count}টা'**
  String approvalsGroupCount(int count);

  /// Inbox card: amount changed warning
  ///
  /// In bn, this message translates to:
  /// **'⚠ চাওয়ার পর অঙ্ক বদলেছে'**
  String get approvalsAmountChangedPill;

  /// Inbox card button: open the paper
  ///
  /// In bn, this message translates to:
  /// **'দেখুন'**
  String get approvalsCardView;

  /// Inbox card button: approve
  ///
  /// In bn, this message translates to:
  /// **'✓ অনুমোদন দিন'**
  String get approvalsCardApprove;

  /// Inbox totals line
  ///
  /// In bn, this message translates to:
  /// **'এই তালিকায় {count}টা · আরও {more}টা নিচে'**
  String approvalsTotalsMore(int count, int more);

  /// Decided tab: decision and time
  ///
  /// In bn, this message translates to:
  /// **'{label} · {when}'**
  String approvalsDecidedAt(String label, String when);

  /// Inbox hint for bulk picking
  ///
  /// In bn, this message translates to:
  /// **'একসাথে অনেকগুলো দিতে লম্বা চাপে বাছুন'**
  String get approvalsLongPressHint;

  /// Approval paper page: why your approval is needed (sample v4)
  ///
  /// In bn, this message translates to:
  /// **'কেন আপনার অনুমোদন লাগছে'**
  String get approvalsWhyTitle;

  /// Approval paper page: the requester's reason
  ///
  /// In bn, this message translates to:
  /// **'চাওয়ার কারণ: “{reason}”'**
  String approvalsWhyReason(String reason);

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দেবেন?'**
  String get approveSheetTitle;

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'যে অঙ্ক দেখছেন, ঠিক সেটাই অনুমোদন হবে। এর মধ্যে কেউ অঙ্ক বদলালে অ্যাপ থামিয়ে নতুন অঙ্ক দেখাবে।'**
  String get approveSheetExact;

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'মন্তব্য (ইচ্ছা হলে)…'**
  String get approveSheetRemarks;

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'{limit}-এর বেশি, তাই আঙুলের ছাপ বা ফোনের PIN দিয়ে নিশ্চিত করতে হবে। কেউ আপনার খোলা ফোন হাতে পেলেও অনুমোদন দিতে পারবে না।'**
  String approveSheetBio(String limit);

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'এটা শেষ ধাপ: অনুমোদন দিলেই কাগজটা খাতায় বসবে। আপনার নাম আর সময় কাগজে থেকে যাবে।'**
  String get approveSheetLast;

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'না, ফিরে যাই'**
  String get approveSheetBack;

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'✓ হ্যাঁ, অনুমোদন দিন'**
  String get approveSheetYes;

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত হলো না — আবার চেষ্টা করুন'**
  String get approveSheetBioFailed;

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'ফোনে তালা (PIN বা আঙুলের ছাপ) বসানো নেই — বড় অঙ্কে অনুমোদনের আগে ফোনের সেটিংসে বসান'**
  String get approveSheetNoLock;

  /// Approve sheet (sample v4 picture 4)
  ///
  /// In bn, this message translates to:
  /// **'বড় অঙ্কের অনুমোদন নিশ্চিত করুন'**
  String get approveSheetBioReason;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান করবেন?'**
  String get rejectSheetTitle;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'এক চাপে কারণ বাছুন, দরকার হলে লিখে দিন। কারণটা যিনি চেয়েছিলেন তার কাছে যাবে, আর রিপোর্টে গোনা হবে।'**
  String get rejectSheetHint;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'আরও কিছু লিখুন (বাধ্যতামূলক)…'**
  String get rejectSheetNote;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'প্রত্যাখ্যান আর ফেরানো যায় না, তাই আরেকবার জিজ্ঞেস করা হবে। শুধু ঠিক করাতে চাইলে “↩ ফেরত পাঠান” বেছে নিন, তাহলে কাগজটা আবার আপনার কাছে আসবে।'**
  String get rejectSheetFinal;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'✕ প্রত্যাখ্যান করুন'**
  String get rejectSheetYes;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'দাম ঠিক নেই'**
  String get rejectReasonPrice;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'কাগজে ভুল আছে'**
  String get rejectReasonDocument;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'বাকির সীমা পেরিয়ে যায়'**
  String get rejectReasonCredit;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'বাজেট নেই'**
  String get rejectReasonBudget;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'নিয়মে চলে না'**
  String get rejectReasonPolicy;

  /// Reject sheet (sample v4 picture 5)
  ///
  /// In bn, this message translates to:
  /// **'অন্য কারণ'**
  String get rejectReasonOther;

  /// Attachments inside the app (sample v4 pictures 2-3)
  ///
  /// In bn, this message translates to:
  /// **'চাপলে অ্যাপেই খোলে'**
  String get attachmentThumbsHint;

  /// Attachments inside the app (sample v4 pictures 2-3)
  ///
  /// In bn, this message translates to:
  /// **'দুই আঙুলে বড় করুন · পাশে সরালে পরের ফাইল'**
  String get attachmentViewerHint;

  /// Attachments inside the app (sample v4 pictures 2-3)
  ///
  /// In bn, this message translates to:
  /// **'↻ ঘোরান'**
  String get attachmentViewerRotate;

  /// Attachments inside the app (sample v4 pictures 2-3)
  ///
  /// In bn, this message translates to:
  /// **'Excel বা Word ফাইল এখনো অ্যাপে দেখা যায় না — সার্ভার PDF বানানো শুরু করলে এখানেই দেখা যাবে। ওয়েবে খুলুন।'**
  String get attachmentViewerOffice;

  /// Attachments inside the app (sample v4 pictures 2-3)
  ///
  /// In bn, this message translates to:
  /// **'PowerPoint ফাইল অ্যাপে খোলে না — ওয়েবে খুলুন।'**
  String get attachmentViewerSlides;

  /// Attachments inside the app (sample v4 pictures 2-3)
  ///
  /// In bn, this message translates to:
  /// **'এই ধরনের ফাইল অ্যাপে দেখা যায় না — ওয়েবে খুলুন।'**
  String get attachmentViewerOther;

  /// Approval paper page (sample v4 picture 2)
  ///
  /// In bn, this message translates to:
  /// **'পরের'**
  String get approvalsNext;

  /// Approval paper page (sample v4 picture 2)
  ///
  /// In bn, this message translates to:
  /// **'কার, কে চেয়েছেন'**
  String get approvalsWhoTitle;

  /// Approval paper page (sample v4 picture 2)
  ///
  /// In bn, this message translates to:
  /// **'পক্ষ'**
  String get approvalsWhoParty;

  /// Approval paper page (sample v4 picture 2)
  ///
  /// In bn, this message translates to:
  /// **'চেয়েছেন'**
  String get approvalsWhoAsked;

  /// Approval paper page (sample v4 picture 2)
  ///
  /// In bn, this message translates to:
  /// **'কার হয়ে'**
  String get approvalsWhoFor;

  /// Approval paper page: impact box (sample v4 picture 2)
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদন দিলে কী হবে'**
  String get approvalsImpactTitle;

  /// App list row actions (web-like Actions menu, owner 8 Nov)
  ///
  /// In bn, this message translates to:
  /// **'অ্যাকশন'**
  String get rowActionsButton;

  /// App list row actions (web-like Actions menu, owner 8 Nov)
  ///
  /// In bn, this message translates to:
  /// **'দেখা'**
  String get rowActionsLook;

  /// App list row actions (web-like Actions menu, owner 8 Nov)
  ///
  /// In bn, this message translates to:
  /// **'দেখুন'**
  String get rowActionsView;

  /// App list row actions (web-like Actions menu, owner 8 Nov)
  ///
  /// In bn, this message translates to:
  /// **'ছাপা ও পাঠানো'**
  String get rowActionsPaper;

  /// App list row actions (web-like Actions menu, owner 8 Nov)
  ///
  /// In bn, this message translates to:
  /// **'ছাপুন, PDF নামান বা পাঠান'**
  String get rowActionsPrintSend;

  /// App list row actions (web-like Actions menu, owner 8 Nov)
  ///
  /// In bn, this message translates to:
  /// **'কাজ'**
  String get rowActionsWork;

  /// App list row actions (web-like Actions menu, owner 8 Nov)
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত করুন — পাতায় সারাংশ দেখে'**
  String get rowActionsConfirmOnPage;

  /// App list row actions (web-like Actions menu, owner 8 Nov)
  ///
  /// In bn, this message translates to:
  /// **'নিশ্চিত হওয়া কাগজে আর কিছু নিশ্চিত করার নেই'**
  String get rowActionsWhyNotDraft;

  /// App list row actions: why confirm is off for a paper waiting for approval
  ///
  /// In bn, this message translates to:
  /// **'অনুমোদনের অপেক্ষায় — অনুমোদন হলে নিজে খাতায় বসবে'**
  String get rowActionsWhyAwaiting;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'চলতি মূলধন'**
  String get wcTitle;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'আজ'**
  String get wcToday;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'গত মাসের শেষ'**
  String get wcLastMonthEnd;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'গত বছরের শেষ'**
  String get wcLastYearEnd;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'তারিখ বাছুন'**
  String get wcPickDate;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'▲ {pct}% আগের মাসের চেয়ে'**
  String wcUp(String pct);

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'▼ {pct}% আগের মাসের চেয়ে'**
  String wcDown(String pct);

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'চলতি অনুপাত'**
  String get wcRatio;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'ভালো · 1.5-এর উপরে'**
  String get wcRatioGood;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'নজরে রাখুন · 1 থেকে 1.5'**
  String get wcRatioWatch;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'দুর্বল · 1-এর নিচে'**
  String get wcRatioBad;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'গড় মাসিক বিক্রির'**
  String get wcSalesShare;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'{n} মাস'**
  String wcMonths(String n);

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'পাওনা'**
  String get wcReceivable;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'মজুদ'**
  String get wcStock;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'হাতে টাকা'**
  String get wcMoney;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'প্রিন্সিপালের দেনা'**
  String get wcPayable;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'অন্যান্য চলতি দায়'**
  String get wcOther;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'টাকা'**
  String get wcBarMoney;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'দেনা'**
  String get wcBarPayable;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'দায়'**
  String get wcBarOther;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'মূলধন'**
  String get wcBarCapital;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'লাখ টাকায় · যোগ হয়ে ওঠে, বাদ গিয়ে নামে'**
  String get wcChartNote;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'পুরো রিপোর্ট →'**
  String get wcFullReport;

  /// Home working capital card under the money card (owner 9 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'চলতি মূলধন আনা গেল না'**
  String get wcFailed;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'SMS গেটওয়ে'**
  String get smsGatewayTitle;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'এই ফোন অফিসের SMS পাঠাবে — নিজের সিমে'**
  String get smsGatewaySubtitle;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'ERP-র ঠিকানা'**
  String get smsGatewayAddress;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'ফোনের চাবি (কন্ট্রোল প্যানেল → SMS-এর ফোন)'**
  String get smsGatewayKey;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'চালু করুন'**
  String get smsGatewayStart;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'বন্ধ করুন'**
  String get smsGatewayStop;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'চালু — মিনিটে একবার সারি দেখে'**
  String get smsGatewayOn;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'বন্ধ'**
  String get smsGatewayOff;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'আজ এই ফোন থেকে গেছে'**
  String get smsGatewaySentToday;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'শেষ দেখা'**
  String get smsGatewayLastRun;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'শেষ ভুল'**
  String get smsGatewayError;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'সার্ভারকে জানানো বাকি'**
  String get smsGatewayWaitingReports;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'SMS পাঠানোর অনুমতি দিন — না দিলে কোনো SMS যাবে না'**
  String get smsGatewayNeedPermission;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'চাবি আর চলে না — কন্ট্রোল প্যানেলে নতুন চাবি নিয়ে আবার চালু করুন'**
  String get smsGatewayKeyRefused;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'চাবি লিখুন — কন্ট্রোল প্যানেল → SMS-এর ফোন থেকে'**
  String get smsGatewayMissing;

  /// Office SMS phone screen (owner ok, sample U1X687u5MkwVfEL7ci7MGv step 2, 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'খরচ কেবল সিমের SMS প্যাকেজ। ফোনটা চার্জে আর নেটে রাখুন; বন্ধ থাকলে SMS সারিতে অপেক্ষা করে, 2 ঘণ্টার বেশি হলে মালিককে খবর যায়।'**
  String get smsGatewayNote;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'কোপাইলট'**
  String get navScreenCopilot;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'কোপাইলট আনা গেল না'**
  String get copilotFailed;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'আজ'**
  String get copilotTabToday;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'জিজ্ঞাসা'**
  String get copilotTabAsk;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'সকালের সারাংশ'**
  String get copilotBrief;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'নজর — অস্বাভাবিক কিছু'**
  String get copilotWatch;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'এখন অস্বাভাবিক কিছু নেই।'**
  String get copilotWatchEmpty;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'প্রস্তাব'**
  String get copilotSuggestions;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'এখন কোনো প্রস্তাব নেই।'**
  String get copilotSuggestionsEmpty;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'ঠিক আছে'**
  String get copilotDismiss;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'এটা ঠিক আছে — 7 দিন চুপ'**
  String get copilotDismissTitle;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'কেন ঠিক আছে (লিখতে হবে)'**
  String get copilotDismissReason;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'চুপ রাখুন'**
  String get copilotDismissGo;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get copilotCancel;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'কোপাইলট খাতায় কিছু তোলে না, কিছু পাঠায় না — শুধু দেখায়। কে কী দেখল ও খুঁজল, মালিকের খাতায় থাকে।'**
  String get copilotRules;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'অফিসের AI সার্ভার এখনো যুক্ত নয় — উদাহরণের মতো প্রশ্নের উত্তর দেয়।'**
  String get copilotModelOff;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'লিখুন… যেমন \"আজ কত বিক্রি হলো?\"'**
  String get copilotPlaceholder;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'জিজ্ঞেস'**
  String get copilotAskButton;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'উৎস'**
  String get copilotSource;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'ঠিক উত্তর'**
  String get copilotRight;

  /// Copilot on the phone (owner 11 Nov 2026)
  ///
  /// In bn, this message translates to:
  /// **'ভুল উত্তর'**
  String get copilotWrong;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'কোপাইলটকে জিজ্ঞেস করুন'**
  String get copilotQuickOpen;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'কী জানতে চান?'**
  String get copilotQuickHello;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'আপনি আছেন: {page}'**
  String copilotQuickWhere(String page);

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'শুনছি…'**
  String get copilotQuickListening;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'বলা শেষ'**
  String get copilotQuickDoneSpeaking;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'বাতিল'**
  String get copilotQuickCancel;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'বলে জিজ্ঞেস করুন'**
  String get copilotQuickMic;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'পাঠান'**
  String get copilotQuickSend;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'শুনুন'**
  String get copilotQuickListen;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'থামান'**
  String get copilotQuickStop;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'শোনাচ্ছে'**
  String get copilotQuickSpeaking;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'পুরো দেখুন →'**
  String get copilotQuickFull;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'বন্ধ'**
  String get copilotQuickClose;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'খুঁজছি…'**
  String get copilotQuickThinking;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'মাইকের অনুমতি নেই — লিখে জিজ্ঞেস করুন।'**
  String get copilotQuickMicDenied;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'কিছু শোনা গেল না — আবার বলুন, বা লিখুন।'**
  String get copilotQuickMicNone;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনে কথা-চেনা নেই — লিখে জিজ্ঞেস করুন।'**
  String get copilotQuickMicOff;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'এই ফোনে বাংলার কণ্ঠ নেই — উত্তর লেখায় আছে।'**
  String get copilotQuickNoVoice;

  /// Copilot quick ask on the phone (owner 12 Nov 2026, sample 3uJhK92DAMhwyELt63mSmr)
  ///
  /// In bn, this message translates to:
  /// **'উত্তর কেবল আপনার চাবির তথ্য থেকে; কথার অডিও কোথাও জমা থাকে না।'**
  String get copilotQuickPrivacy;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'চেহারা'**
  String get lookTitle;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'ABOS'**
  String get lookAbos;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'Liquid Glass'**
  String get lookGlass;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'Material 3'**
  String get lookExpressive;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'কাচের ঝাপসা'**
  String get lookBlur;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'স্বচ্ছ'**
  String get lookBlurClear;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'হালকা'**
  String get lookBlurLight;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'মাঝারি'**
  String get lookBlurMedium;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'ঘন'**
  String get lookBlurDense;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'ঘষা কাচ'**
  String get lookBlurFrosted;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'পেছনের রং নড়বে'**
  String get lookMotion;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'খুঁজুন'**
  String get lookSearch;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'দ্রুত কাজ'**
  String get lookQuickActions;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'সুপ্রভাত, {name}'**
  String lookGreeting(String name);

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'আজকের কাজ'**
  String get lookTodayWork;

  /// App looks: Liquid Glass and Material 3 Expressive (owner 20 Nov 2026, samples BshQzFsr and BvqYK2tZ)
  ///
  /// In bn, this message translates to:
  /// **'টাকা জমার খবর'**
  String get lookQuickDeposit;
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
