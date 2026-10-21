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
  /// **'সই চাওয়া, অনুমোদন আর ডেলিভারির খবর অ্যাপ বন্ধ থাকলেও জানাতে ABOS বার্তা পাঠাবে। এরপর ফোন বার্তার অনুমতি চাইবে — \"অনুমতি দিন\" চাপুন। না দিলেও অ্যাপ চলবে, কেবল খবরগুলো অ্যাপ খুললে তবে দেখবেন।'**
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
