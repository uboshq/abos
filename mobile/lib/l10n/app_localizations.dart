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
  /// **'{state, select, awaiting{সইয়ের অপেক্ষায়} posted{পোস্ট হয়েছে} cancelled{বাতিল} other{খসড়া}}'**
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
  /// **'সইয়ের অপেক্ষায়'**
  String get homeAwaitingSignature;

  /// Home card title: papers waiting for a signature, and how many
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের অপেক্ষায় · {count}টি'**
  String homeAwaitingSignatureCount(String count);

  /// Home card: opens the full list
  ///
  /// In bn, this message translates to:
  /// **'সব দেখুন'**
  String get homeSeeAll;

  /// Home card: the waiting papers failed to load
  ///
  /// In bn, this message translates to:
  /// **'সইয়ের কাগজ আনা গেল না। নেট দেখে আবার চেষ্টা করুন।'**
  String get homeWaitingLoadFailed;

  /// Home card: no paper waits for a signature
  ///
  /// In bn, this message translates to:
  /// **'এখন কিছু সইয়ের অপেক্ষায় নেই।'**
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
