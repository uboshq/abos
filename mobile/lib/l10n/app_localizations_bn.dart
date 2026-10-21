// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Bengali Bangla (`bn`).
class AppL10nBn extends AppL10n {
  AppL10nBn([String locale = 'bn']) : super(locale);

  @override
  String get commonOpen => 'খুলুন';

  @override
  String get commonCancel => 'বাতিল';

  @override
  String get commonSave => 'রাখুন';

  @override
  String get commonRetry => 'আবার চেষ্টা করুন';

  @override
  String commonPaperCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা কাগজ',
      zero: 'কোনো কাগজ নেই',
    );
    return '$_temp0';
  }

  @override
  String commonPaperState(String state) {
    String _temp0 = intl.Intl.selectLogic(
      state,
      {
        'draft': 'খসড়া',
        'posted': 'পোস্ট হয়েছে',
        'cancelled': 'বাতিল',
        'other': 'অজানা',
      },
    );
    return '$_temp0';
  }

  @override
  String get topBarBell => 'বিজ্ঞপ্তি';

  @override
  String topBarBellUnread(String count) {
    return 'বিজ্ঞপ্তি, $countটা না-পড়া';
  }

  @override
  String get topBarBellNews => 'বিজ্ঞপ্তি, নতুন খবর আছে';

  @override
  String get avatarMenuClose => 'মেনু বন্ধ';

  @override
  String get avatarMenuSwitchCompany => 'কোম্পানি বদল';

  @override
  String get avatarMenuColour => 'রং';

  @override
  String get avatarMenuThemePhone => 'ফোনের মতো';

  @override
  String get avatarMenuThemeLight => 'আলো';

  @override
  String get avatarMenuThemeDark => 'অন্ধকার';

  @override
  String get avatarMenuLanguage => 'ভাষা';

  @override
  String get avatarMenuLanguagePhone => 'ফোনের মতো';

  @override
  String get avatarMenuPinAndFingerprint => 'পিন ও আঙুলের ছাপ';

  @override
  String avatarMenuLockAfter(String delay) {
    return 'তালার সময়: $delay';
  }

  @override
  String get avatarMenuMyPhones => 'আমার ফোনগুলো';

  @override
  String avatarMenuSettingsVersion(String version) {
    return 'সেটিংস · সংস্করণ $version';
  }

  @override
  String get avatarMenuSignOut => 'বের হওয়া';

  @override
  String get approvalsRejectReasonRequired =>
      'প্রত্যাখ্যানের কারণ লেখা বাধ্যতামূলক';

  @override
  String approvalsSheetTotalLine(String amounts) {
    return 'মোট — $amounts';
  }

  @override
  String get claimsKindAdvance => 'অগ্রিম';

  @override
  String get claimsKindExpense => 'খরচের দাবি';

  @override
  String get claimsStateSubmitted => 'সইয়ের অপেক্ষায়';

  @override
  String get claimsStateApproved => 'অনুমোদিত — টাকার অপেক্ষায়';

  @override
  String get claimsStatePaid => 'পরিশোধিত';

  @override
  String get claimsStateRejected => 'প্রত্যাখ্যাত';

  @override
  String get claimsStateOther => 'অপেক্ষায়';

  @override
  String get hrLeaveStatePending => 'সইয়ের অপেক্ষায়';

  @override
  String get hrLeaveStateApproved => 'অনুমোদিত';

  @override
  String get hrLeaveStateRejected => 'প্রত্যাখ্যাত';

  @override
  String get hrLeaveStateCancelled => 'প্রত্যাহৃত';

  @override
  String get profilePhoneFallbackName => 'ফোন';

  @override
  String get profileServerMissingTitle => 'এখনো এই সার্ভারে নেই';

  @override
  String get profileServerMissingMessage =>
      'এখনো এই সার্ভারে নেই — সার্ভারের নতুন সংস্করণ এলে চালু হবে।';

  @override
  String get profilePhotoRefused =>
      'ছবিটা নেওয়া গেল না — JPG বা PNG, 5 MB-এর কম, আরেকটা দিয়ে চেষ্টা করুন।';

  @override
  String get profilePhotoTooLarge => 'ছবিটা খুব বড় — 5 MB-এর কম একটা দিন।';

  @override
  String get statusPaperFactLabel => 'অবস্থা';

  @override
  String get approvalsInboxTitle => 'অনুমোদন';

  @override
  String get approvalsInboxLoadFailed => 'তালিকা আনা গেল না। আবার চেষ্টা করুন।';

  @override
  String get approvalsInboxServerMissing =>
      'অনুমোদনের সুবিধাটা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।';

  @override
  String get approvalsInboxSigned => 'সই দেওয়া হলো।';

  @override
  String get approvalsInboxRejected => 'প্রত্যাখ্যান করা হয়েছে।';

  @override
  String get approvalsInboxAlreadyDecided =>
      'এটি ইতিমধ্যে নিষ্পত্তি হয়ে গেছে।';

  @override
  String get approvalsInboxNotYours => 'এই সিদ্ধান্তটি আপনার নয়।';

  @override
  String get approvalsInboxAmountChanged =>
      'কার্ড খোলার পরে কাগজের অঙ্ক বদলেছে — নতুন অঙ্ক দেখে আবার সই দিন।';

  @override
  String get approvalsInboxOffline =>
      'সংযোগ নেই। অনুমোদনের জন্য ইন্টারনেট লাগবে।';

  @override
  String get approvalsInboxActionFailed => 'কাজটি সম্পন্ন হয়নি।';

  @override
  String get approvalsInboxMoreFailed => 'আরও আনা গেল না।';

  @override
  String get approvalsInboxSignTitle => 'সই দেবেন?';

  @override
  String get approvalsInboxSignButton => 'সই দিন';

  @override
  String get approvalsInboxRejectTitle => 'প্রত্যাখ্যান করবেন?';

  @override
  String get approvalsInboxRejectButton => 'প্রত্যাখ্যান';

  @override
  String get approvalsInboxLoadFailedTitle => 'তালিকা আনা গেল না';

  @override
  String get approvalsInboxEmptyTitle => 'কিছু অপেক্ষা করছে না';

  @override
  String get approvalsInboxEmptyMessage =>
      'আপনার সিদ্ধান্তের জন্য কোনো নথি নেই।';

  @override
  String get approvalsInboxShowMore => 'আরও দেখুন';

  @override
  String approvalsInboxStep(int level) {
    return 'ধাপ $level';
  }

  @override
  String get approvalsInboxDetails => 'বিস্তারিত';

  @override
  String get approvalsInboxViewPaper => 'কাগজ দেখুন';

  @override
  String get approvalsInboxReasonRequired => 'কারণ (বাধ্যতামূলক)';

  @override
  String get approvalsInboxRemarksOptional => 'মন্তব্য (ঐচ্ছিক)';

  @override
  String get approvalsInboxDetailsFailed => 'বিস্তারিত আনা গেল না।';

  @override
  String get approvalsInboxNoDetails =>
      'এই কাগজের বিস্তারিত ফোনে নেই — \"কাগজ দেখুন\" চেপে পুরো কাগজটা দেখুন।';

  @override
  String get approvalsDelegationTitle => 'সইয়ের ভার';

  @override
  String get approvalsDelegationLoadFailed =>
      'ভারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get approvalsDelegationServerMissing =>
      'সার্ভারে ফোনের ভার দেওয়া এখনো আসেনি — অফিসে জানান।';

  @override
  String get approvalsDelegationRevokeTitle => 'ভার ফেরাবেন?';

  @override
  String approvalsDelegationRevokeBody(String person) {
    return '$person আর আপনার হয়ে সই দিতে পারবেন না।';
  }

  @override
  String get approvalsDelegationNo => 'না';

  @override
  String get approvalsDelegationRevoke => 'ফেরান';

  @override
  String get approvalsDelegationRevoked => 'ভার ফেরানো হলো।';

  @override
  String get approvalsDelegationRevokeFailed => 'ভার ফেরানো গেল না।';

  @override
  String get approvalsDelegationNew => 'নতুন ভার';

  @override
  String get approvalsDelegationLoadFailedTitle => 'আনা গেল না';

  @override
  String approvalsDelegationHeldHeading(int count) {
    return 'আমার কাছে যাঁদের ভার · $count টি';
  }

  @override
  String approvalsDelegationGivenHeading(int count) {
    return 'আমি যাঁদের দিয়েছি · $count টি';
  }

  @override
  String get approvalsDelegationNoneHeld => 'এখন কেউ আপনাকে ভার দেননি।';

  @override
  String get approvalsDelegationEmptyTitle => 'কোনো ভার নেই';

  @override
  String get approvalsDelegationEmptyMessage =>
      'ছুটিতে গেলে \"নতুন ভার\" চেপে কাউকে আপনার হয়ে সই দিতে দিন।';

  @override
  String get approvalsDelegationNoneGiven => 'আপনি কাউকে ভার দেননি।';

  @override
  String get approvalsDelegationStateRevoked => 'ফেরানো';

  @override
  String get approvalsDelegationStateActive => 'চালু';

  @override
  String get approvalsDelegationStateOther => 'সামনে বা শেষ';

  @override
  String approvalsDelegationPeriod(String from, String until, String state) {
    return '$from থেকে $until · $state';
  }

  @override
  String get approvalsDelegationAllWork => 'সব কাজে';

  @override
  String get approvalsDelegationChooseWho => 'কাকে ভার দেবেন, বাছুন।';

  @override
  String get approvalsDelegationSaved => 'ভার দেওয়া হলো।';

  @override
  String get approvalsDelegationSaveFailed => 'ভার দেওয়া গেল না।';

  @override
  String get approvalsDelegationTo => 'কার কাছে';

  @override
  String approvalsDelegationFrom(String date) {
    return 'শুরু $date';
  }

  @override
  String approvalsDelegationUntil(String date) {
    return 'শেষ $date';
  }

  @override
  String get approvalsDelegationWhichWork => 'কোন কাজে (কিছু না বাছলে সব কাজে)';

  @override
  String get approvalsDelegationReason => 'কারণ (ঐচ্ছিক)';

  @override
  String get approvalsDelegationReasonHint => 'যেমন: ছুটিতে';

  @override
  String get approvalsDelegationSaving => 'দেওয়া হচ্ছে…';

  @override
  String get approvalsDelegationSave => 'ভার দিন';

  @override
  String get approvalsQueueTabToSign => 'আমার সইয়ের অপেক্ষায়';

  @override
  String get approvalsQueueTabMine => 'আমার পাঠানো';

  @override
  String get approvalsQueueTabAsked => 'আমার চাওয়া';

  @override
  String get approvalsQueueTabSigned => 'সইয়ের ইতিহাস';

  @override
  String get approvalsQueueDocumentsTitle => 'দলিলের অনুমোদন';

  @override
  String get approvalsQueueSignaturesTitle => 'সই কেন্দ্র';

  @override
  String get approvalsQueueLoadFailed =>
      'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get approvalsQueueDocumentsServerMissing =>
      'সার্ভারে ফোনের দলিলের অনুমোদন এখনো আসেনি — অফিসে জানান।';

  @override
  String get approvalsQueueSignaturesServerMissing =>
      'সার্ভারে ফোনের সই কেন্দ্র এখনো আসেনি — অফিসে জানান।';

  @override
  String get approvalsQueueSigned => 'সই দেওয়া হলো।';

  @override
  String get approvalsQueueSignFailed => 'সই দেওয়া গেল না।';

  @override
  String get approvalsQueueSendBackTitle => 'কেন সংশোধনে ফেরত দিচ্ছেন?';

  @override
  String get approvalsQueueSendBackDismiss => 'বাদ দিন';

  @override
  String get approvalsQueueSendBack => 'সংশোধনে ফেরত দিন';

  @override
  String get approvalsQueueSendBackReasonNeeded => 'ফেরতের কারণ লিখতে হবে।';

  @override
  String get approvalsQueueSentBack => 'সংশোধনে ফেরত দেওয়া হলো।';

  @override
  String get approvalsQueueSendBackFailed => 'সংশোধনে ফেরত দেওয়া গেল না।';

  @override
  String approvalsQueueTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'মোট $count টি',
    );
    return '$_temp0';
  }

  @override
  String get approvalsQueueLoadFailedTitle => 'আনা গেল না';

  @override
  String get approvalsQueueNoSignatures => 'এখনো কোনো সই নেই';

  @override
  String get approvalsQueueNothingWaiting => 'কিছু অপেক্ষায় নেই';

  @override
  String approvalsQueueSignedBy(String signer, String at) {
    return 'সই: $signer · $at';
  }

  @override
  String approvalsQueueFingerprint(String hash) {
    return 'ছাপ $hash…';
  }

  @override
  String approvalsQueueRequestedBy(String name) {
    return 'চেয়েছেন: $name';
  }

  @override
  String approvalsQueueDue(String due) {
    return 'শেষ সময়: $due';
  }

  @override
  String get approvalsQueueSign => 'সই দিন';

  @override
  String get approvalsQueueMore => 'আরও';

  @override
  String get approvalsMyRequestsLoadFailed =>
      'আমার অনুরোধের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get approvalsMyRequestsServerMissing =>
      'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get approvalsMyRequestsTitle => 'আমার অনুরোধ';

  @override
  String get approvalsMyRequestsTotalLabel => 'মোট অনুরোধ';

  @override
  String approvalsMyRequestsTotalValue(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি',
    );
    return '$_temp0';
  }

  @override
  String approvalsMyRequestsPending(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'এখনো সইয়ের অপেক্ষায়: $count টি',
    );
    return '$_temp0';
  }

  @override
  String get approvalsMyRequestsEmptyTitle => 'আপনার পাঠানো কোনো অনুরোধ নেই';

  @override
  String get approvalsMyRequestsEmptyMessage =>
      'কোনো কাগজ সইয়ের জন্য পাঠালে এখানে দেখা যাবে।';

  @override
  String get approvalsMyRequestsMore => 'আরও';

  @override
  String approvalsMyRequestsLastWord(String said) {
    return 'শেষ কথা: $said';
  }

  @override
  String approvalsMyRequestsReason(String remarks) {
    return 'কারণ: $remarks';
  }

  @override
  String get profilePhonesTitle => 'আমার ফোনগুলো';

  @override
  String get profileModuleMe => 'আমি';

  @override
  String get profilePhonesLoadFailed =>
      'ফোনের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get profilePhonesSignOutHereTitle => 'এই ফোন থেকেই বের হবেন?';

  @override
  String get profilePhonesSignOutOtherTitle => 'এই ফোন থেকে বের করে দেবেন?';

  @override
  String profilePhonesSignOutHereBody(String phone) {
    return '“$phone” — এটা আপনার হাতের এই ফোনটাই। বের করলে এখনই লগইন পাতায় ফিরবেন; আবার ঢুকতে পাসওয়ার্ড লাগবে।';
  }

  @override
  String profilePhonesSignOutOtherBody(String phone) {
    return '“$phone” থেকে আপনার লগইন এখনই বন্ধ হবে। ঐ ফোনে আবার ঢুকতে পাসওয়ার্ড লাগবে।';
  }

  @override
  String get profilePhonesKeep => 'না, থাক';

  @override
  String get profilePhonesSignOut => 'বের করে দিন';

  @override
  String profilePhonesSignOutNamed(String phone) {
    return '$phone থেকে বের করে দিন';
  }

  @override
  String profilePhonesAlreadyOut(String phone) {
    return '“$phone” আগেই বের হয়ে গেছে — তালিকা নতুন করে আনা হলো।';
  }

  @override
  String profilePhonesSignedOut(String phone) {
    return '“$phone” থেকে বের করে দেওয়া হলো।';
  }

  @override
  String get profilePhonesSignOutFailed =>
      'বের করা গেল না। একটু পরে আবার চেষ্টা করুন।';

  @override
  String get profilePhonesServerOld =>
      'আমার ফোনগুলো সার্ভারের নতুন সংস্করণ এলে চালু হবে।';

  @override
  String get profilePhonesEmptyTitle => 'কোনো ফোনে ঢোকা নেই';

  @override
  String get profilePhonesEmptyMessage => 'নতুন ফোনে লগইন করলে এখানে দেখাবে।';

  @override
  String profilePhonesLastUsed(String when) {
    return 'শেষ ব্যবহার: $when';
  }

  @override
  String get profilePhonesLastUsedUnknown => 'জানা নেই';

  @override
  String get profilePhonesThisPhone => 'এই ফোন';

  @override
  String get profileTitlePhoto => 'প্রোফাইল ও ছবি';

  @override
  String get profileTitlePlain => 'আমার প্রোফাইল';

  @override
  String get profilePhotoUnreadable => 'ছবিটা পড়া গেল না — আরেকটা ছবি বাছুন।';

  @override
  String get profilePhotoChanged => 'ছবি বদলানো হলো — ওয়েবেও এই মুখ দেখাবে।';

  @override
  String get profilePhotoUploadFailed =>
      'ছবি পাঠানো গেল না। একটু পরে আবার চেষ্টা করুন।';

  @override
  String get profilePhotoRemoveTitle => 'ছবিটা সরাবেন?';

  @override
  String get profilePhotoRemoveBody =>
      'সরালে আপনার মুখের জায়গায় নামের প্রথম অক্ষর দেখাবে — ফোনে আর ওয়েবে দুই জায়গাতেই।';

  @override
  String get profilePhotoKeep => 'না, থাক';

  @override
  String get profilePhotoRemoveConfirm => 'সরান';

  @override
  String get profilePhotoRemoved => 'ছবি সরানো হলো।';

  @override
  String get profilePhotoRemoveFailed =>
      'ছবি সরানো গেল না। একটু পরে আবার চেষ্টা করুন।';

  @override
  String profilePhotoSendingSpoken(int percent) {
    return 'ছবি পাঠানো হচ্ছে, $percent শতাংশ';
  }

  @override
  String profilePhotoSending(int percent) {
    return 'ছবি পাঠানো হচ্ছে… $percent%';
  }

  @override
  String get profilePhotoCamera => 'ক্যামেরায় তুলুন';

  @override
  String get profilePhotoGallery => 'গ্যালারি থেকে';

  @override
  String get profilePhotoRemove => 'ছবি সরান';

  @override
  String get profilePhotoPrivacy =>
      'ছবি মাঝখান থেকে বর্গ করে ছোট করা হয়, ছবির ভেতরের জায়গা-সময়ের তথ্য মুছে। কেবল আপনার কোম্পানির সহকর্মীরা দেখেন।';

  @override
  String get profilePhonesSubtitle =>
      'কোন কোন ফোনে ঢোকা আছেন — হারানো ফোন দূর থেকে বের করে দিন';

  @override
  String get profileFaceNone =>
      'আপনার ছবি নেই — নামের প্রথম অক্ষর দেখানো হচ্ছে';

  @override
  String get profileFace => 'আপনার ছবি';

  @override
  String get hrAttendanceMarkedQueued =>
      'হাজিরা তোলা হয়েছে। সংযোগ পেলে পাঠানো হবে।';

  @override
  String get hrAttendanceTitle => 'হাজিরা';

  @override
  String get hrAttendanceEarlierDays => 'আগের দিনগুলো';

  @override
  String get hrAttendanceNoHistoryTitle => 'আগের কোনো হাজিরা সিঙ্ক হয়নি';

  @override
  String get hrAttendanceNoHistoryMessage => 'নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get hrAttendanceToday => 'আজ';

  @override
  String get hrAttendanceTodayMarked => 'আজকের হাজিরা তোলা হয়েছে';

  @override
  String get hrAttendanceWaitingToSend =>
      'সংযোগ পেলে পাঠানো হবে — এখনো এই ফোনেই আছে।';

  @override
  String get hrAttendanceMarkToday => 'আজকের হাজিরা দিন';

  @override
  String hrAttendanceIn(String time) {
    return 'ঢুকেছেন $time';
  }

  @override
  String hrAttendanceOut(String time) {
    return 'বেরিয়েছেন $time';
  }

  @override
  String get hrAttendanceLate => 'দেরি';

  @override
  String get notificationsLoadFailed => 'বিজ্ঞপ্তি আনা গেল না।';

  @override
  String get notificationsServerMissing =>
      'বিজ্ঞপ্তি এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get notificationsTitle => 'বিজ্ঞপ্তি';

  @override
  String get notificationsMarkAllRead => 'সব পড়া হয়েছে';

  @override
  String get notificationsLoading => 'আনা হচ্ছে…';

  @override
  String get notificationsEmptyTitle => 'কোনো বিজ্ঞপ্তি নেই';

  @override
  String get notificationsEmptyMessage => 'নতুন কিছু এলে এখানে দেখাবে।';

  @override
  String get noticesLoadFailed => 'নোটিশ আনা গেল না।';

  @override
  String get noticesBoardTitle => 'নোটিশ বোর্ড';

  @override
  String noticesUnreadCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা নোটিশ এখনো পড়া হয়নি',
    );
    return '$_temp0';
  }

  @override
  String get noticesEmptyTitle => 'এখন কোনো নোটিশ নেই';

  @override
  String get noticesEmptyMessage => 'প্রতিষ্ঠান নতুন কিছু জানালে এখানে দেখাবে।';

  @override
  String get noticesSigned => 'সই দেওয়া হলো।';

  @override
  String get noticesSignFailed => 'সই দেওয়া গেল না।';

  @override
  String get noticesDetailTitle => 'নোটিশ';

  @override
  String noticesValidUntil(String date) {
    return 'মেয়াদ $date পর্যন্ত';
  }

  @override
  String noticesSignBy(String date) {
    return 'সইয়ের শেষ সময় $date';
  }

  @override
  String get noticesYouSigned => 'আপনি সই দিয়েছেন';

  @override
  String get noticesSending => 'পাঠানো হচ্ছে…';

  @override
  String get noticesReadAndAgree => 'পড়েছি ও মানছি — সই দিন';

  @override
  String noticesStanding(String standing) {
    String _temp0 = intl.Intl.selectLogic(
      standing,
      {
        'unread': 'পড়েননি',
        'read': 'পড়েছেন',
        'acknowledged': 'মেনে নিয়েছেন',
        'overdue': 'সইয়ের সময় পেরিয়েছে',
        'escalated': 'উপরে জানানো হয়েছে',
        'other': '',
      },
    );
    return '$_temp0';
  }

  @override
  String noticesPriority(String priority) {
    String _temp0 = intl.Intl.selectLogic(
      priority,
      {
        'low': 'কম জরুরি',
        'normal': 'সাধারণ',
        'important': 'গুরুত্বপূর্ণ',
        'high': 'বেশি জরুরি',
        'critical': 'অত্যন্ত জরুরি',
        'emergency': 'জরুরি অবস্থা',
        'other': '',
      },
    );
    return '$_temp0';
  }

  @override
  String get hrRegisterAttendanceLoadFailed =>
      'হাজিরা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get hrRegisterAttendanceServerMissing =>
      'সার্ভারে ফোনের হাজিরার রেজিস্টার এখনো আসেনি — অফিসে জানান।';

  @override
  String get hrRegisterAttendanceTitle => 'হাজিরার রেজিস্টার';

  @override
  String hrRegisterAttendanceDay(String date) {
    return 'দিন: $date';
  }

  @override
  String get hrRegisterLoadFailedTitle => 'আনা গেল না';

  @override
  String get hrRegisterAttendanceNobody => 'এই দিনে বেতনভুক্ত কোনো কর্মী নেই';

  @override
  String hrRegisterAttendanceTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'মোট $count জন',
    );
    return '$_temp0';
  }

  @override
  String hrRegisterAttendanceCount(String label, int count) {
    return '$label: $count';
  }

  @override
  String hrRegisterAttendanceLate(String status) {
    return '$status · দেরি';
  }

  @override
  String get hrRegisterLeaveLoadFailed =>
      'ছুটির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get hrRegisterLeaveServerMissing =>
      'সার্ভারে ফোনের ছুটির রেজিস্টার এখনো আসেনি — অফিসে জানান।';

  @override
  String get hrRegisterLeaveRejectTitle => 'কেন প্রত্যাখ্যান করছেন?';

  @override
  String get hrRegisterLeaveRejectDismiss => 'বাদ দিন';

  @override
  String get hrRegisterLeaveRejectConfirm => 'প্রত্যাখ্যান করুন';

  @override
  String get hrRegisterLeaveRejectReasonNeeded =>
      'প্রত্যাখ্যানের কারণ লিখতে হবে।';

  @override
  String get hrRegisterLeaveSigned => 'ছুটিতে সই দেওয়া হলো।';

  @override
  String get hrRegisterLeaveRejected => 'ছুটি প্রত্যাখ্যান করা হলো।';

  @override
  String get hrRegisterLeaveSignFailed => 'সই দেওয়া গেল না।';

  @override
  String get hrRegisterLeaveRejectFailed => 'প্রত্যাখ্যান করা গেল না।';

  @override
  String get hrRegisterLeaveTitle => 'ছুটির রেজিস্টার';

  @override
  String get hrRegisterLeavePending => 'অপেক্ষায়';

  @override
  String get hrRegisterLeaveAll => 'সব';

  @override
  String hrRegisterLeaveTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'মোট $count টি আবেদন',
    );
    return '$_temp0';
  }

  @override
  String get hrRegisterLeaveNonePending => 'অপেক্ষায় কোনো আবেদন নেই';

  @override
  String get hrRegisterLeaveNone => 'কোনো আবেদন নেই';

  @override
  String hrRegisterLeaveLine(String type, String from, String to, num days) {
    return '$type · $from থেকে $to · $days দিন';
  }

  @override
  String hrRegisterLeaveDecider(String name) {
    return 'সিদ্ধান্ত: $name';
  }

  @override
  String get hrRegisterLeaveReject => 'প্রত্যাখ্যান';

  @override
  String get hrRegisterLeaveSign => 'সই দিন';

  @override
  String get hrRegisterLeaveMore => 'আরও';

  @override
  String get hrLeaveLoadFailed =>
      'ছুটির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get hrLeaveServerMissing =>
      'সার্ভারে ফোনের ছুটি এখনো আসেনি — অফিসে জানান।';

  @override
  String get hrLeaveTitle => 'আমার ছুটি';

  @override
  String get hrLeaveSent => 'ছুটির আবেদন গেছে — সইয়ের অপেক্ষায়।';

  @override
  String get hrLeaveApply => 'ছুটির আবেদন';

  @override
  String get hrNoEmployeeRecord => 'আপনার নামে কর্মীর খাতা নেই';

  @override
  String get hrLeaveNoEmployeeMessage =>
      'ছুটির আবেদন করতে HR-কে বলুন আপনার লগইন কর্মীর খাতার সাথে জুড়ে দিতে।';

  @override
  String get hrLeaveLeftThisYear => 'এ বছর কত বাকি';

  @override
  String get hrLeaveNoTypes => 'এখনো কোনো ছুটির ধরন নেই — HR ঠিক করে দেবেন।';

  @override
  String hrLeaveNoLimit(String taken) {
    return 'সীমা নেই · নেওয়া $taken দিন';
  }

  @override
  String hrLeaveBalance(String left, String allowed) {
    return '$left দিন বাকি · মোট $allowed';
  }

  @override
  String get hrLeaveMyApplications => 'আমার আবেদন';

  @override
  String get hrLeaveNoApplications => 'এখনো কোনো ছুটির আবেদন নেই';

  @override
  String hrLeaveCardTitle(String type, String days) {
    return '$type · $days দিন';
  }

  @override
  String hrLeaveOfficeNote(String remarks) {
    return 'অফিসের কথা: $remarks';
  }

  @override
  String get hrLeaveChooseType => 'কোন ছুটি, বাছুন।';

  @override
  String get hrLeaveDaysShape => 'দিনের সংখ্যা ঠিকভাবে লিখুন — যেমন 2 বা 1.5।';

  @override
  String hrLeaveDaysTooMany(int span) {
    String _temp0 = intl.Intl.pluralLogic(
      span,
      locale: localeName,
      other: 'দিনের সংখ্যা তারিখের ঘরের চেয়ে বেশি — $span দিনের বেশি নয়।',
    );
    return '$_temp0';
  }

  @override
  String get hrLeaveSendFailed => 'আবেদন পাঠানো গেল না। আবার চেষ্টা করুন।';

  @override
  String get hrLeaveHowItWorks =>
      'আবেদন যায় অফিসে; অনুমোদিত হলে দিনগুলো হাজিরায় \"ছুটি\" হয়ে বসে।';

  @override
  String get hrLeaveTypeField => 'কোন ছুটি';

  @override
  String hrLeaveTypeWithLeft(String name, String left) {
    return '$name ($left দিন বাকি)';
  }

  @override
  String get hrLeaveFrom => 'কবে থেকে';

  @override
  String get hrLeaveUntil => 'কবে পর্যন্ত';

  @override
  String get hrLeaveDaysField => 'কত দিন (আধা দিন হলে .5)';

  @override
  String get hrLeaveReasonField => 'কারণ';

  @override
  String get hrLeaveSend => 'পাঠান';

  @override
  String get hrPayslipListTitle => 'আমার বেতনের স্লিপ';

  @override
  String get hrPayslipListFailed =>
      'স্লিপের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get hrPayslipServerMissing =>
      'সার্ভারে ফোনের বেতনের স্লিপ এখনো আসেনি — অফিসে জানান।';

  @override
  String get hrPayslipEmptyTitle => 'এখনো কোনো পোস্ট হওয়া বেতনের স্লিপ নেই';

  @override
  String get hrPayslipEmptyMessage => 'বেতনের রান পোস্ট হলে স্লিপ এখানে আসে।';

  @override
  String get hrPayslipNoEmployeeMessage =>
      'HR-কে বলুন আপনার লগইন কর্মীর খাতার সাথে জুড়ে দিতে।';

  @override
  String hrPayslipRowLine(String gross, String deductions) {
    return 'আয় $gross · কর্তন $deductions';
  }

  @override
  String hrPayslipPaidBy(String method) {
    String _temp0 = intl.Intl.selectLogic(
      method,
      {
        'cash': 'নগদ',
        'bank': 'ব্যাংক',
        'mfs': 'মোবাইল ব্যাংকিং',
        'other': '$method',
      },
    );
    return '$_temp0';
  }

  @override
  String get hrPayslipTitle => 'বেতনের স্লিপ';

  @override
  String get hrPayslipFailed => 'স্লিপটা আনা গেল না।';

  @override
  String get hrPayslipNet => 'নিট বেতন';

  @override
  String get hrPayslipName => 'নাম';

  @override
  String get hrPayslipMonth => 'মাস';

  @override
  String get hrPayslipHow => 'কীভাবে';

  @override
  String get hrPayslipEarnings => 'আয়';

  @override
  String get hrPayslipGross => 'মোট আয়';

  @override
  String get hrPayslipDeductions => 'কর্তন';

  @override
  String get hrPayslipTotalDeductions => 'মোট কর্তন';

  @override
  String get claimsLoadFailed =>
      'দাবির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get claimsServerMissing =>
      'সার্ভারে খরচের দাবি এখনো আসেনি — অফিসে জানান।';

  @override
  String get claimsListTitle => 'খরচের দাবি ও অগ্রিম';

  @override
  String get claimsNew => 'নতুন দাবি';

  @override
  String get claimsOpenAdvance => 'হাতে খোলা অগ্রিম';

  @override
  String get claimsEmpty => 'এখনো কোনো দাবি নেই।';

  @override
  String get claimsOneFailed => 'দাবিটা আনা গেল না।';

  @override
  String get claimsOneTitle => 'দাবি';

  @override
  String get claimsKind => 'ধরন';

  @override
  String get claimsAmount => 'টাকা';

  @override
  String get claimsHead => 'খরচের খাত';

  @override
  String get claimsSpentOn => 'খরচের তারিখ';

  @override
  String get claimsFromAdvance => 'অগ্রিম থেকে মিটেছে';

  @override
  String get claimsInCash => 'নগদে পাবেন';

  @override
  String get claimsReason => 'কারণ';

  @override
  String get claimsSentOn => 'পাঠানো';

  @override
  String get claimsRejectedOn => 'প্রত্যাখ্যান';

  @override
  String get claimsApprovedOn => 'অনুমোদন';

  @override
  String get claimsPaidOn => 'টাকা দেওয়া';

  @override
  String get claimsHeadsFailed => 'খরচের খাতের তালিকা আনা গেল না।';

  @override
  String get claimsAmountShape =>
      'টাকার অঙ্ক ঠিকভাবে লিখুন — যেমন 500 বা 750.50।';

  @override
  String get claimsChooseHead => 'কোন খাতের খরচ, বাছুন।';

  @override
  String get claimsWriteReason => 'কারণ লিখুন।';

  @override
  String get claimsSendFailed => 'পাঠানো যায়নি। আবার চেষ্টা করুন।';

  @override
  String get claimsHowItWorks =>
      'সই আর ক্যাশিয়ারের পরে টাকা পাবেন। খরচ আগে মেটে হাতের অগ্রিম থেকে।';

  @override
  String get claimsKindSpent => 'খরচ হয়ে গেছে';

  @override
  String get claimsKindAdvanceWanted => 'অগ্রিম চাই';

  @override
  String get claimsReceiptTake => 'রসিদের ছবি তুলুন';

  @override
  String get claimsReceiptTaken => 'ছবি নেওয়া হয়েছে — আবার তুলুন';

  @override
  String get claimsSend => 'পাঠান';
}
