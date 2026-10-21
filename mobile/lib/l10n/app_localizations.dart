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
  /// **'সইয়ের অপেক্ষায়'**
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
  /// **'সইয়ের অপেক্ষায়'**
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
  /// **'সই দেওয়া হলো।'**
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
  /// **'কার্ড খোলার পরে কাগজের অঙ্ক বদলেছে — নতুন অঙ্ক দেখে আবার সই দিন।'**
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
  /// **'সই দেবেন?'**
  String get approvalsInboxSignTitle;

  /// Approvals inbox: the button that signs the paper (fe 21 Oct: 'সই দিন', was 'অনুমোদন')
  ///
  /// In bn, this message translates to:
  /// **'সই দিন'**
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
  /// **'সংশোধনে ফেরত দেবেন?'**
  String get approvalsInboxSendBackTitle;

  /// Approvals inbox: the send-back button on the card and in its dialog (fe 21 Oct: সংশোধনে ফেরত দিন)
  ///
  /// In bn, this message translates to:
  /// **'সংশোধনে ফেরত দিন'**
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
  /// **'সইয়ের ভার'**
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
  /// **'{person} আর আপনার হয়ে সই দিতে পারবেন না।'**
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
  /// **'ছুটিতে গেলে \"নতুন ভার\" চেপে কাউকে আপনার হয়ে সই দিতে দিন।'**
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
  /// **'{count, plural, other{এখনো সইয়ের অপেক্ষায়: {count} টি}}'**
  String approvalsMyRequestsPending(int count);

  /// My requests: empty state title
  ///
  /// In bn, this message translates to:
  /// **'আপনার পাঠানো কোনো অনুরোধ নেই'**
  String get approvalsMyRequestsEmptyTitle;

  /// My requests: empty state message
  ///
  /// In bn, this message translates to:
  /// **'কোনো কাগজ সইয়ের জন্য পাঠালে এখানে দেখা যাবে।'**
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
  /// **'সই দেওয়া হলো।'**
  String get noticesSigned;

  /// Notice: signing failed
  ///
  /// In bn, this message translates to:
  /// **'সই দেওয়া গেল না।'**
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
  /// **'সইয়ের শেষ সময় {date}'**
  String noticesSignBy(String date);

  /// Notice: the person has signed it
  ///
  /// In bn, this message translates to:
  /// **'আপনি সই দিয়েছেন'**
  String get noticesYouSigned;

  /// Notice: the sign button while sending
  ///
  /// In bn, this message translates to:
  /// **'পাঠানো হচ্ছে…'**
  String get noticesSending;

  /// Notice: the button that signs (acknowledges) the notice
  ///
  /// In bn, this message translates to:
  /// **'পড়েছি ও মানছি — সই দিন'**
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
  /// **'ছুটিতে সই দেওয়া হলো।'**
  String get hrRegisterLeaveSigned;

  /// Leave register: snack bar after rejecting
  ///
  /// In bn, this message translates to:
  /// **'ছুটি প্রত্যাখ্যান করা হলো।'**
  String get hrRegisterLeaveRejected;

  /// Leave register: signing failed
  ///
  /// In bn, this message translates to:
  /// **'সই দেওয়া গেল না।'**
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
  /// **'সই দিন'**
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
  /// **'ছুটির আবেদন গেছে — সইয়ের অপেক্ষায়।'**
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
  /// **'সই আর ক্যাশিয়ারের পরে টাকা পাবেন। খরচ আগে মেটে হাতের অগ্রিম থেকে।'**
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
