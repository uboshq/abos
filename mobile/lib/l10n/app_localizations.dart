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
