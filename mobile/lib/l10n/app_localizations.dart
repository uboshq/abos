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

  /// Delivery tracking: the chip for every step
  ///
  /// In bn, this message translates to:
  /// **'সব'**
  String get ordersTrackingAll;

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
  /// **'সইয়ের অপেক্ষায়'**
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

  /// Keypad: how many pieces one carton holds; digits already written
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

  /// Filter sheet: apply button; n picks the form, count is n already written in the screen digits
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

  /// New order bottom bar: pieces sold and free; digits already written
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
  /// **'ছাড় % (দিলে মালিকের সই লাগবে)'**
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
