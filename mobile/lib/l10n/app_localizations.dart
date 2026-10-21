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
  String get ordersListNewOrder;

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
  String get ordersTrackingSearch;

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
