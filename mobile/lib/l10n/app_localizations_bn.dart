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
  String get ordersModuleSales => 'বিক্রয়';

  @override
  String get ordersUnknownCustomer => 'অজানা গ্রাহক';

  @override
  String get ordersRejectedFailed => 'ব্যর্থ';

  @override
  String ordersRejectedItemCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা পণ্য',
    );
    return '$_temp0';
  }

  @override
  String get ordersRejectedDismiss => 'বুঝেছি, সরিয়ে ফেলুন';

  @override
  String get ordersRejectedRewrite => 'নতুন করে লিখুন';

  @override
  String ordersDepositStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'pending': 'পাঠানো',
        'verifying': 'যাচাই চলছে',
        'accepted': 'গৃহীত',
        'rejected': 'প্রত্যাখ্যাত',
        'other': 'যাচাই চলছে',
      },
    );
    return '$_temp0';
  }

  @override
  String get ordersTrackingStepOrdered => 'অর্ডার এসেছে';

  @override
  String get ordersTrackingStepCreditHold => 'বাকির সীমায় আটকে';

  @override
  String get ordersTrackingStepDraft => 'খসড়া';

  @override
  String get ordersTrackingStepApproval => 'অনুমোদনের অপেক্ষায়';

  @override
  String get ordersTrackingStepWarehouse => 'গুদামে';

  @override
  String get ordersTrackingStepGateOut => 'গেট পেরিয়েছে';

  @override
  String get ordersTrackingStepPartial => 'কিছু পৌঁছেছে';

  @override
  String get ordersTrackingStepDelivered => 'পৌঁছেছে';

  @override
  String get ordersTrackingStepCancelled => 'বাতিল';

  @override
  String get ordersTrackingStepBilled => 'বিল হয়েছে';

  @override
  String get ordersListTitle => 'অর্ডারের অবস্থা';

  @override
  String get ordersListLoadFailed => 'অর্ডারের তালিকা আনা যায়নি';

  @override
  String get ordersListEmptyTitle => 'এখনো কোনো অর্ডার নেই';

  @override
  String get ordersListEmptyMessage => 'নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get ordersNewOrder => 'নতুন অর্ডার';

  @override
  String ordersListPendingHeader(String count) {
    return 'অপেক্ষমাণ ($count)';
  }

  @override
  String get ordersListPendingPill => 'অপেক্ষমাণ';

  @override
  String get ordersListPendingNote =>
      'সংযোগ পেলেই পাঠানো হবে — অর্ডার এই ফোনেই আছে।';

  @override
  String ordersListRejectedHeader(String count) {
    return 'যা যায়নি ($count)';
  }

  @override
  String ordersListSyncedHeader(String count) {
    return 'সিঙ্ক হয়েছে ($count)';
  }

  @override
  String get ordersListFilterEmpty => 'এই ফিল্টারে কোনো অর্ডার নেই।';

  @override
  String get ordersListNoNumber => 'নম্বর নেই';

  @override
  String get ordersTrackingLoadFailed =>
      'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get ordersTrackingAbsent =>
      'সার্ভারে ডেলিভারি ট্র্যাকিং এখনো আসেনি — অফিসে জানান।';

  @override
  String get ordersTrackingTitle => 'ডেলিভারি ট্র্যাকিং';

  @override
  String get ordersTrackingSearchHint => 'বিক্রয় নম্বর, ডিও বা দোকান';

  @override
  String get ordersSearch => 'খুঁজুন';

  @override
  String get ordersTrackingAll => 'সব';

  @override
  String get ordersTrackingEmptyTitle => 'কোনো বিক্রি নেই।';

  @override
  String get ordersTrackingEmptyFiltered => 'এই ছাঁকনিতে কিছু নেই।';

  @override
  String get ordersTrackingEmptyHint => 'গেট পাস হলে বিক্রি এখানে আসে।';

  @override
  String get ordersTrackingShowAll => 'সব দেখুন';

  @override
  String get ordersTrackingReload => 'নতুন করে দেখুন';

  @override
  String get ordersTrackingNoCustomer => 'গ্রাহক নেই';

  @override
  String get ordersTrackingBackOrder => 'বাকি মাল পরে যাবে';

  @override
  String get ordersTrackingStoryFailed => 'সময়রেখা আনা গেল না।';

  @override
  String get ordersTrackingStoryWho => 'কে কখন কী করলেন';

  @override
  String get counterReturnLoadFailed =>
      'ফেরতের তালিকা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।';

  @override
  String get counterReturnBillFailed => 'বিল খোলা গেল না';

  @override
  String get counterTryAgain => 'আবার চেষ্টা করুন।';

  @override
  String get counterReturnQtyUnreadable =>
      'ফেরতের পরিমাণ পড়া গেল না — কেবল সংখ্যা লিখুন।';

  @override
  String get counterReturnPickReason => 'কারণ বাছুন।';

  @override
  String get counterReturnNeedQty => 'অন্তত একটা মালের ফেরত পরিমাণ দিন।';

  @override
  String get counterReturnDraftKeptTitle => 'খসড়া ফেরত রাখা হলো';

  @override
  String get counterReturnDraftKeptText =>
      'নিশ্চিত হয়নি — ওয়েবের ফেরতের তালিকায় খসড়া হয়ে আছে।';

  @override
  String get counterReturnDone => 'ফেরত হলো';

  @override
  String get counterAwaitingSignature => 'সইয়ের অপেক্ষায়';

  @override
  String get counterReturnFailed => 'ফেরত হলো না';

  @override
  String get counterOk => 'ঠিক আছে';

  @override
  String get counterReturnTitle => 'বিক্রি ফেরত';

  @override
  String get counterReturnWhichBill => 'কোন বিলের ফেরত';

  @override
  String get counterReturnEmptyTitle =>
      'ফেরত দেওয়ার মতো কোনো নিশ্চিত বিল নেই।';

  @override
  String get counterReturnEmptyHint => 'বিল পোস্ট হলে এখানে আসবে।';

  @override
  String get counterReload => 'নতুন করে দেখুন';

  @override
  String counterReturnBillNo(String no) {
    return 'বিল $no';
  }

  @override
  String counterReturnLot(String lot) {
    return 'লট: $lot';
  }

  @override
  String counterReturnSold(String qty, String rate) {
    return 'বেচা: $qty × $rate';
  }

  @override
  String get counterReturnQtyLabel => 'কত ফেরত';

  @override
  String get counterReturnReasonLabel => 'কেন ফেরত';

  @override
  String get counterNoteLabel => 'বিবরণ (ঐচ্ছিক)';

  @override
  String get counterConfirm => 'নিশ্চিত করুন';

  @override
  String get counterReturnOtherBill => 'অন্য বিল বাছুন';

  @override
  String get ordersFilter => 'ফিল্টার';

  @override
  String get ordersFilterOffer => 'অফার আছে';

  @override
  String get ordersFilterBoughtBefore => 'এই দোকান আগে নিয়েছে';

  @override
  String get ordersSale => 'বিক্রি';

  @override
  String get ordersFree => 'ফ্রি';

  @override
  String get ordersKeypadCarton => 'কার্টন';

  @override
  String ordersKeypadCartonFactor(String one, String carton, String factor) {
    return '$one $carton = $factor পিস';
  }

  @override
  String get ordersKeypadPieces => 'পিস';

  @override
  String get ordersKeypadTotalPieces => 'মোট পিস';

  @override
  String ordersKeypadLikeLast(String qty) {
    return 'আগের মতো ($qty)';
  }

  @override
  String get ordersKeypadBackspace => 'মুছুন';

  @override
  String get ordersKeypadAddToCart => 'ঝুড়িতে যোগ';

  @override
  String ordersKeypadLineAmount(String amount) {
    return 'এই লাইনে $amount';
  }

  @override
  String get ordersKeypadEitherUnit => 'কার্টন বা পিস যেটায় খুশি লিখুন';

  @override
  String get ordersSortCatalogue => 'তালিকার ক্রমে';

  @override
  String get ordersSortName => 'নাম ধরে';

  @override
  String get ordersSortPrice => 'দাম ধরে';

  @override
  String get ordersFilterClearAll => 'সব মুছুন';

  @override
  String get ordersFilterShowHeading => 'দেখান';

  @override
  String get ordersFilterSortHeading => 'সাজান';

  @override
  String ordersFilterApply(int n, String count) {
    String _temp0 = intl.Intl.pluralLogic(
      n,
      locale: localeName,
      other: '$countটা পণ্য দেখান',
    );
    return '$_temp0';
  }

  @override
  String get ordersConfirmTitle => 'পাঠানোর আগে দেখে নিন';

  @override
  String get ordersConfirmStandingLoading => 'হিসাব আনা হচ্ছে…';

  @override
  String get ordersConfirmStandingOffline => 'নেট নেই — হিসাব পরে দেখা যাবে';

  @override
  String get ordersConfirmStandingFailed =>
      'হিসাব এখন আনা গেল না — অর্ডার তবু যাবে, হিসাব পরে দেখা যাবে';

  @override
  String get ordersConfirmAdvance => 'জমা আছে';

  @override
  String get ordersConfirmPendingClaims => 'অনুমোদনের অপেক্ষায়';

  @override
  String get ordersConfirmToPay => 'দিতে হবে';

  @override
  String ordersConfirmOverLimit(String amount) {
    return 'বাকির সীমা পার হচ্ছে — অর্ডার যাবে, কিন্তু $amount জমা না হলে ডেলিভারি হবে না। এই সীমা কেউ পার করাতে পারেন না, মালিকও না।';
  }

  @override
  String get ordersConfirmColProduct => 'পণ্য';

  @override
  String get ordersConfirmColAmount => 'টাকা';

  @override
  String get ordersConfirmTotal => 'মোট';

  @override
  String get ordersConfirmRemarks => 'মন্তব্য (ঐচ্ছিক)';

  @override
  String get ordersConfirmSend => 'ঠিক আছে, পাঠান';

  @override
  String get ordersConfirmBack => 'ফিরে যান';

  @override
  String get ordersConfirmSoon => 'শীঘ্রই আসছে';

  @override
  String get ordersOk => 'ঠিক আছে';

  @override
  String get ordersConfirmBankSlip => 'ব্যাংক স্লিপ পাঠান';

  @override
  String get ordersNewNoBarcodeProduct => 'এই বারকোডে কোনো পণ্য নেই';

  @override
  String get ordersNewQueued =>
      'অর্ডার লেখা হয়েছে। নম্বর সিঙ্কের পর আসবে — এখনই বলা যাবে না।';

  @override
  String get ordersNewPickCustomer => 'গ্রাহক বাছুন';

  @override
  String get ordersNewNoProductsTitle => 'পণ্যের তালিকা এখনো সিঙ্ক হয়নি';

  @override
  String get ordersNewNoProductsHint =>
      'পণ্যের পর্দায় গিয়ে একবার নিচে টানুন, তারপর ফিরে আসুন।';

  @override
  String get ordersNewSearchHint => 'নাম বা কোড লিখুন';

  @override
  String get ordersNewScanBarcode => 'বারকোড স্ক্যান';

  @override
  String get ordersNewChipRemove => 'সরান';

  @override
  String get ordersNewNoMatch => 'কোনো মিল পাওয়া যায়নি';

  @override
  String get ordersNewInCart => '✓ ঝুড়িতে আছে';

  @override
  String get ordersNewNoRate => 'দর জানা নেই';

  @override
  String get ordersNewLess => 'কমান';

  @override
  String get ordersNewMore => 'বাড়ান';

  @override
  String get ordersNewQty => 'পরিমাণ';

  @override
  String get ordersNewQtyHint => 'সংখ্যা লিখুন';

  @override
  String ordersNewSaleAmount(String amount) {
    return 'বিক্রি · $amount';
  }

  @override
  String get ordersNewFreeAuto => 'ফ্রি · নিজে বসেছে';

  @override
  String ordersNewBarPieces(String sold, String free) {
    return 'বিক্রি $sold পিস · ফ্রি $free';
  }

  @override
  String get ordersNewSend => 'পাঠান';

  @override
  String get ordersNewBarcodeTitle => 'বারকোড';

  @override
  String get ordersNewBarcodeHint => 'স্ক্যান করুন বা লিখুন';

  @override
  String ordersNewDueLimit(String amount) {
    return 'সীমা $amount';
  }

  @override
  String ordersNewDueHeadroom(String amount) {
    return 'বাকি $amount';
  }

  @override
  String ordersNewDueDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days দিন',
    );
    return '$_temp0';
  }

  @override
  String ordersNewOverAlready(String amount) {
    return 'সীমা আগেই পেরিয়ে আছে — এই অর্ডারে $amount যোগ হবে।';
  }

  @override
  String ordersNewOverBy(String amount) {
    return 'এই অর্ডারে সীমা $amount ছাড়িয়ে যাবে।';
  }

  @override
  String ordersNewDueSynced(String when) {
    return 'বকেয়ার তথ্য সিঙ্ক $when';
  }
}
