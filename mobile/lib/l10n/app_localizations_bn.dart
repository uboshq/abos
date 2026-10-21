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
  String get ordersListNewOrder => 'নতুন অর্ডার';

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
  String get ordersTrackingSearch => 'খুঁজুন';

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
}
