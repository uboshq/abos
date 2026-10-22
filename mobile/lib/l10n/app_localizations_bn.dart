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
  String get financeLoadFailed => 'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get financeNotOnServer =>
      'সার্ভারে অর্থের এই অংশ এখনো আসেনি — অফিসে জানান।';

  @override
  String get financeDaysToday => 'আজ';

  @override
  String financeDaysLeft(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: 'আর $days দিন',
    );
    return '$_temp0';
  }

  @override
  String financeDaysOverdue(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days দিন পেরিয়েছে',
    );
    return '$_temp0';
  }

  @override
  String get financeStatusActive => 'চালু';

  @override
  String get financeStatusAwaitingSign => 'সইয়ের অপেক্ষায়';

  @override
  String get financeStatusEnded => 'শেষ';

  @override
  String get financeStatusCancelled => 'বাতিল';

  @override
  String get financeStatusClosed => 'বন্ধ';

  @override
  String get financeStatusDraft => 'খসড়া';

  @override
  String get financeDepositMoveOpened => 'টাকা রাখা';

  @override
  String get financeDepositMovePayout => 'মুনাফা তোলা';

  @override
  String get financeDepositMoveClosed => 'ভাঙানো / মেয়াদপূর্তি';

  @override
  String get financeLoanKindCc => 'ক্যাশ ক্রেডিট (CC)';

  @override
  String get financeLoanKindTerm => 'মেয়াদি ঋণ';

  @override
  String get financeLoanKindLease => 'লিজ';

  @override
  String get financeLoanKindGuarantee => 'ব্যাংক গ্যারান্টি';

  @override
  String get financeInstalmentPaid => 'দেওয়া';

  @override
  String get financeInstalmentOverdue => 'দিন পেরিয়েছে';

  @override
  String get financeInstalmentToday => 'আজ দিতে হবে';

  @override
  String get financeInstalmentUpcoming => 'সামনে';

  @override
  String get financeInstalmentNoDate => 'তারিখ নেই';

  @override
  String get financeRepaymentLump => 'একবারে ফেরত';

  @override
  String get financeRepaymentMonthly => 'মাসে মাসে';

  @override
  String get financeRepaymentWhenever => 'যখন পারেন';

  @override
  String get financeDepositListTitle => 'জমা ও সঞ্চয়';

  @override
  String get financeDepositListEmpty => 'এই ট্যাবে কোনো জমা নেই';

  @override
  String get financeDepositTabMaturing => 'মেয়াদ আসছে';

  @override
  String financeDepositMatures(String date) {
    return 'মেয়াদ $date';
  }

  @override
  String get financeDepositTitle => 'জমা';

  @override
  String get financeDepositLoadFailed => 'জমাটা আনা গেল না।';

  @override
  String get financeDepositAmount => 'জমার অঙ্ক';

  @override
  String get financeFactType => 'ধরন';

  @override
  String get financeFactStatus => 'অবস্থা';

  @override
  String get financeDepositInstitution => 'প্রতিষ্ঠান';

  @override
  String get financeDepositBankRef => 'ব্যাংকের নম্বর';

  @override
  String get financeDepositHeldBy => 'কার নামে';

  @override
  String get financeDepositHeldOwner => 'মালিকের';

  @override
  String financeDepositHeldOwnerPerson(String person) {
    return 'মালিকের — $person';
  }

  @override
  String get financeDepositHeldBusiness => 'ব্যবসার';

  @override
  String get financeDepositProfitRate => 'মুনাফার হার';

  @override
  String get financeDepositOpened => 'খোলা';

  @override
  String get financeDepositMaturity => 'মেয়াদ';

  @override
  String get financeDepositMonthlyInstalment => 'মাসের কিস্তি';

  @override
  String financeDayOfMonth(String day) {
    return 'প্রতি মাসের $day তারিখ';
  }

  @override
  String get financeDepositClosedOn => 'শেষ হয়েছে';

  @override
  String get financeDepositPledged => 'বাঁধা';

  @override
  String get financeDepositPledgedNote => 'ঋণের বিপরীতে বাঁধা — ভাঙানো যাবে না';

  @override
  String get financeNote => 'নোট';

  @override
  String get financeDepositMovements => 'চলাচল';

  @override
  String get financeLoanListTitle => 'ব্যাংক ঋণ ও কিস্তি';

  @override
  String get financeLoanListEmpty => 'এই ট্যাবে কোনো ঋণ নেই';

  @override
  String financeLoanLimitLine(String amount) {
    return 'সীমা $amount';
  }

  @override
  String financeLoanNextLine(String date, String amount, String state) {
    return 'পরের কিস্তি $date · $amount · $state';
  }

  @override
  String get financeLoanTitle => 'ব্যাংক ঋণ';

  @override
  String get financeLoanLoadFailed => 'ঋণটা আনা গেল না।';

  @override
  String get financeLoanOutstanding => 'এখনকার দেনা';

  @override
  String get financeLoanBank => 'ব্যাংক';

  @override
  String get financeLoanLimit => 'সীমা';

  @override
  String get financeLoanAvailable => 'সীমার বাকি';

  @override
  String get financeInterestRate => 'সুদের হার';

  @override
  String get financeLoanSanctionNo => 'মঞ্জুরির নম্বর';

  @override
  String get financeLoanSanctioned => 'মঞ্জুরি';

  @override
  String get financeLoanRenewal => 'নবায়ন';

  @override
  String get financeInstalment => 'কিস্তি';

  @override
  String financeLoanInstalmentCounts(String paid, String left, String total) {
    return '$paid দেওয়া · $left বাকি · মোট $total';
  }

  @override
  String get financeLoanInstalmentAmount => 'কিস্তির অঙ্ক';

  @override
  String get financeLoanOverdueInstalments => 'দিন পেরোনো কিস্তি';

  @override
  String financeLoanOverdueCount(String count) {
    return '$countটি';
  }

  @override
  String get financeLoanNextInstalment => 'পরের কিস্তি';

  @override
  String get financeLoanSchedule => 'সামনের কিস্তি';

  @override
  String financeLoanScheduleTitle(String no, String date) {
    return '$no নম্বর কিস্তি · $date';
  }

  @override
  String financeLoanScheduleSplit(String principal, String interest) {
    return 'আসল $principal · সুদ $interest';
  }

  @override
  String get financeRentTitle => 'ভাড়া';

  @override
  String get financeRentListEmpty => 'এখানে কোনো ভাড়ার চুক্তি নেই';

  @override
  String get financeRentSidePay => 'আমরা ভাড়া দিই';

  @override
  String get financeRentSideLet => 'আমরা ভাড়া পাই';

  @override
  String financeRentNextLine(String date) {
    return 'পরের ভাড়া $date';
  }

  @override
  String financeRentTenantOwes(String amount) {
    return 'ভাড়াটের কাছে পাওনা $amount';
  }

  @override
  String financeRentMonthsDue(int months, String amount) {
    String _temp0 = intl.Intl.pluralLogic(
      months,
      locale: localeName,
      other: '$months মাসের ভাড়া বাকি · $amount',
    );
    return '$_temp0';
  }

  @override
  String get financeRentLoadFailed => 'ভাড়ার চুক্তিটা আনা গেল না।';

  @override
  String get financeRentMonthlyWeLet => 'মাসের ভাড়া (আমরা পাই)';

  @override
  String get financeRentMonthlyWePay => 'মাসের ভাড়া (আমরা দিই)';

  @override
  String get financeRentTenant => 'ভাড়াটে';

  @override
  String get financeRentLandlord => 'বাড়িওয়ালা';

  @override
  String get financeRentPhone => 'ফোন';

  @override
  String get financeRentPlace => 'জায়গা';

  @override
  String get financeRentAgreementNo => 'চুক্তির নম্বর';

  @override
  String get financeRentDay => 'ভাড়ার দিন';

  @override
  String get financeRentNext => 'পরের ভাড়া';

  @override
  String get financeRentStarts => 'শুরু';

  @override
  String get financeRentEnds => 'শেষ';

  @override
  String get financeRentSecurity => 'জামানত';

  @override
  String get financeRentSecurityHeld => 'হাতে থাকা জামানত';

  @override
  String get financeRentSecurityLeft => 'জামানতের বাকি';

  @override
  String get financeRentOldestUnpaid => 'সবচেয়ে পুরনো বাকি মাস';

  @override
  String get financeRentUnpaidMonths => 'বাকি মাস';

  @override
  String get financeHandLoanListTitle => 'হাতধার';

  @override
  String get financeHandLoanListEmpty => 'কোনো খোলা হাতধার নেই';

  @override
  String get financeHandLoanToUs => 'আমরা পাব';

  @override
  String get financeHandLoanWeOwe => 'আমরা দেব';

  @override
  String financeHandLoanDueLine(String date) {
    return 'ফেরতের দিন $date';
  }

  @override
  String get financeHandLoanTheyOwe => 'তিনি আমাদের দেবেন';

  @override
  String get financeHandLoanWeOweThem => 'আমরা তাঁকে দেব';

  @override
  String get financeHandLoanSettled => 'মিটে গেছে';

  @override
  String get financeHandLoanTitle => 'হাতধার';

  @override
  String get financeHandLoanLoadFailed => 'হাতধারটা আনা গেল না।';

  @override
  String get financeHandLoanMobile => 'মোবাইল';

  @override
  String get financeHandLoanPrincipal => 'আসল';

  @override
  String get financeHandLoanRepaymentType => 'ফেরতের ধরন';

  @override
  String get financeHandLoanDueDate => 'ফেরতের দিন';

  @override
  String get financeHandLoanStatusSettled => 'চুকে গেছে';

  @override
  String get financeHandLoanMoveButton => 'টাকা দেওয়া / ফেরত';

  @override
  String get financeHandLoanTransactions => 'লেনদেন';

  @override
  String get financeHandLoanGiven => 'টাকা দেওয়া';

  @override
  String get financeHandLoanReceived => 'টাকা পাওয়া';

  @override
  String financeHandLoanReturnLine(String date) {
    return 'ফেরতের কথা $date';
  }

  @override
  String get accountsChartLoadFailed =>
      'হিসাবের ছক আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get accountsChartNotOnServer =>
      'সার্ভারে ফোনের হিসাবের ছক এখনো আসেনি — অফিসে জানান।';

  @override
  String get accountsChartTitle => 'হিসাবের ছক';

  @override
  String get accountsChartSearchHint => 'কোড বা নাম দিয়ে খুঁজুন';

  @override
  String accountsChartCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'মোট $count টি হিসাব',
    );
    return '$_temp0';
  }

  @override
  String get accountsChartFetchFailed => 'আনা গেল না';

  @override
  String get accountsChartNoMatch => 'কোনো হিসাব মেলেনি';

  @override
  String get accountsChartLedgerLoadFailed =>
      'খাতা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get accountsChartGroupAccount =>
      'এটা দলের হিসাব — এন্ট্রি এর নিচের হিসাবগুলোতে। ছকে ফিরে নিচের হিসাব খুলুন।';

  @override
  String get accountsChartNoEntries => 'এই হিসাবে কোনো এন্ট্রি নেই';

  @override
  String get accountsChartMoreEntries => 'আরও এন্ট্রি';

  @override
  String accountsChartLedgerTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'সর্বমোট · $count টি এন্ট্রি',
    );
    return '$_temp0';
  }

  @override
  String get accountsChartDebit => 'ডেবিট';

  @override
  String get accountsChartCredit => 'ক্রেডিট';

  @override
  String get accountsChartClosing => 'শেষ জের';

  @override
  String accountsChartDebitLine(String amount) {
    return 'ডেবিট $amount';
  }

  @override
  String accountsChartCreditLine(String amount) {
    return 'ক্রেডিট $amount';
  }

  @override
  String get vouchersKindSuffix => ' ভাউচার';

  @override
  String get vouchersListLoadFailed =>
      'ভাউচারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get vouchersListNotOnServer =>
      'সার্ভারে ফোনের ভাউচার এখনো আসেনি — অফিসে জানান।';

  @override
  String get vouchersListTitle => 'ভাউচার';

  @override
  String get vouchersListAllTitle => 'সব ভাউচার';

  @override
  String get vouchersListNew => 'নতুন ভাউচার';

  @override
  String get vouchersListAwaitingSign => 'সইয়ের অপেক্ষায়';

  @override
  String get vouchersListAllBranches => 'সব শাখা';

  @override
  String vouchersListTotal(String kind) {
    return 'মোট · $kind';
  }

  @override
  String get vouchersListEmpty => 'কোনো ভাউচার নেই।';

  @override
  String vouchersListWrittenBy(String name) {
    return 'তুলেছেন: $name';
  }

  @override
  String get vouchersPageLoadFailed => 'ভাউচারটা আনা গেল না।';

  @override
  String get vouchersPagePostFailed => 'পাকা করা গেল না। আবার চেষ্টা করুন।';

  @override
  String get vouchersPagePrint => 'ছাপা বা শেয়ার';

  @override
  String get vouchersPageType => 'ধরন';

  @override
  String get vouchersAmount => 'টাকা';

  @override
  String get vouchersDate => 'তারিখ';

  @override
  String get vouchersTrxNo => 'লেনদেন নম্বর';

  @override
  String get vouchersNarration => 'বিবরণ';

  @override
  String get vouchersPageWriter => 'লেখক';

  @override
  String vouchersDebitLine(String amount) {
    return 'ডেবিট $amount';
  }

  @override
  String vouchersCreditLine(String amount) {
    return 'ক্রেডিট $amount';
  }

  @override
  String get vouchersPageAnotherHand =>
      'নিজের লেখা ভাউচার নিজে পাকা হয় না — অন্য কেউ খুলে \"পাকা করুন\" চাপবেন।';

  @override
  String get vouchersPageTrxLabel => 'লেনদেন নম্বর (TrxID)';

  @override
  String get vouchersPagePost => 'পাকা করুন';

  @override
  String get vouchersFormSetupFailed =>
      'খাতের তালিকা আনা গেল না। নেট দেখে আবার খুলুন।';

  @override
  String get vouchersFormSetupMissing => 'খাতের তালিকা এখনো আসেনি।';

  @override
  String get vouchersFormNarrationRequired =>
      'বিবরণ লিখুন — এই কোম্পানিতে বিবরণ বাধ্যতামূলক।';

  @override
  String get vouchersFormTwoLines => 'অন্তত দুটো সারিতে খাত বাছুন।';

  @override
  String get vouchersFormAmountShape => 'অঙ্কটা ঠিক নয় — দুই দশমিক পর্যন্ত।';

  @override
  String get vouchersFormBothSides => 'এক সারিতে ডেবিট আর ক্রেডিট দুটোই নয়।';

  @override
  String get vouchersFormUnbalanced => 'ডেবিট আর ক্রেডিট মেলেনি।';

  @override
  String get vouchersFormPickAccounts => 'দুই দিকের খাত বাছুন।';

  @override
  String get vouchersFormSameAccount => 'দুই দিকে একই খাত হয় না।';

  @override
  String get vouchersFormAmountInvalid =>
      'অঙ্কটা ঠিক নয় — শূন্যের বেশি, দুই দশমিক পর্যন্ত।';

  @override
  String get vouchersFormNotSaved => 'ভাউচারটা বসেনি। আবার চেষ্টা করুন।';

  @override
  String get vouchersFormMayHaveLanded =>
      'ভাউচারের উত্তর আসেনি — সার্ভারে হয়তো পৌঁছেছে — কিছু না বদলে আবার চাপুন; আগেরটা বসে থাকলে সেটাই দেখাবে, দুবার বসবে না।';

  @override
  String get vouchersFormNeedsNet =>
      'ভাউচার লিখতে নেট লাগবে। নেট চালু করে আবার চাপুন — ফোনে কিছু জমা থাকেনি।';

  @override
  String get vouchersFormSendFailed => 'পাঠানো গেল না। আবার চেষ্টা করুন।';

  @override
  String get vouchersFormPartyOptional => 'পক্ষ (ঐচ্ছিক — বাকিতে খরচে লাগবে)';

  @override
  String get vouchersFormClear => 'মুছুন';

  @override
  String get vouchersFormParty => 'পক্ষ';

  @override
  String get vouchersFormFrom => 'কোথা থেকে';

  @override
  String get vouchersFormTo => 'কোথায়';

  @override
  String get vouchersFormTrxLabel => 'লেনদেন নম্বর (TrxID) — পাকা করতে লাগবে';

  @override
  String get vouchersFormAccount => 'খাত';

  @override
  String get vouchersFormDebit => 'ডেবিট';

  @override
  String get vouchersFormCredit => 'ক্রেডিট';

  @override
  String get vouchersFormAddLine => 'সারি যোগ';

  @override
  String vouchersFormTotals(String debit, String credit) {
    return 'ডেবিট $debit · ক্রেডিট $credit';
  }

  @override
  String get vouchersFormNarrationMust => 'বিবরণ (বাধ্যতামূলক)';

  @override
  String get vouchersFormKeepDraft => 'খসড়া রাখুন';

  @override
  String get vouchersFormKeepDraftHint =>
      'বন্ধ থাকলে সংরক্ষণেই পাকা — সই লাগলে বা নিজের লেখা হলে খসড়াই থাকবে';

  @override
  String get vouchersFormSave => 'সংরক্ষণ';

  @override
  String get vouchersPickNothing => 'কিছু মেলেনি।';

  @override
  String get moneyResultDone => 'বসেছে।';

  @override
  String get moneyResultPending => 'সইয়ের অপেক্ষায়।';

  @override
  String moneyLoadFailed(String title) {
    return '$title আনা গেল না।';
  }

  @override
  String get moneyPageMissing =>
      'এই পাতা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get moneyCustodyTitle => 'টাকা কার হাতে';

  @override
  String get moneyCustodyOnRoad => 'পথের টাকা';

  @override
  String get moneyCustodyEmpty => 'কোনো টাকার খাত নেই';

  @override
  String get moneyCustodyInTransit => 'পথে আছে';

  @override
  String get moneyTransferListTitle => 'টাকা হস্তান্তর';

  @override
  String get moneyTransferSend => 'পাঠান';

  @override
  String get moneyTransferTotal => 'মোট হাতবদল';

  @override
  String get moneyTransferAwaitingMe => 'আমার নেওয়ার অপেক্ষায়';

  @override
  String get moneyTransferEmpty => 'কোনো হস্তান্তর নেই';

  @override
  String get moneyPullToRefresh => 'নিচে টেনে আবার দেখুন।';

  @override
  String get moneyTransferAll => 'সব';

  @override
  String get moneyActionFailed => 'করা গেল না।';

  @override
  String get moneyTransferCancelWhy => 'কেন বাতিল?';

  @override
  String get moneyTransferKeep => 'থাক';

  @override
  String get moneyTransferCancelConfirm => 'বাতিল করুন';

  @override
  String get moneyTransferTitle => 'হস্তান্তর';

  @override
  String get moneyFactStatus => 'অবস্থা';

  @override
  String get moneyFactDate => 'তারিখ';

  @override
  String get moneyTransferFrom => 'কোথা থেকে';

  @override
  String get moneyTransferTo => 'কোথায়';

  @override
  String get moneyFactAmount => 'অঙ্ক';

  @override
  String get moneyTransferGiver => 'দিলেন';

  @override
  String get moneyTransferReceiver => 'নেবেন';

  @override
  String get moneyTransferNarration => 'বিবরণ';

  @override
  String get moneyTransferCancelReason => 'বাতিলের কারণ';

  @override
  String get moneyTransferReceived => 'টাকা পেলাম';

  @override
  String get moneyTransferCancel => 'বাতিল';

  @override
  String get moneyTillsLoadFailed => 'টিলের তালিকা আনা গেল না।';

  @override
  String get moneyTransferMissing => 'কোথা থেকে, কোথায় আর কত — লাগবেই।';

  @override
  String get moneyTransferSendFailed => 'হস্তান্তর পাঠানো গেল না।';

  @override
  String get moneySendTitle => 'টাকা পাঠান';

  @override
  String get moneyTransferFromTill => 'কোন টিল থেকে';

  @override
  String get moneyTransferToLabel => 'কোথায় — টিল বা ব্যাংক';

  @override
  String get moneyTransferReceiverLabel => 'কে নেবেন (ঐচ্ছিক)';

  @override
  String get moneyTransferNoteLabel => 'বিবরণ (ঐচ্ছিক)';

  @override
  String get moneyCashCountTitle => 'নগদ গোনা';

  @override
  String get moneyCashCountNew => 'গুনুন';

  @override
  String get moneyCashCountTotal => 'মোট গোনা';

  @override
  String moneyCashCountTotalValue(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি',
    );
    return '$_temp0';
  }

  @override
  String get moneyCashCountEmpty => 'কোনো গোনা নেই';

  @override
  String moneyCashCountDifference(String difference) {
    return 'পার্থক্য $difference';
  }

  @override
  String get moneyFactTill => 'টিল';

  @override
  String get moneyFactCounted => 'গোনা';

  @override
  String get moneyFactExpected => 'খাতায়';

  @override
  String get moneyFactDifference => 'পার্থক্য';

  @override
  String moneyCashCountNoteRow(String note) {
    return '$note টাকার নোট';
  }

  @override
  String moneyCashCountNoteQty(String qty) {
    return '$qtyটা';
  }

  @override
  String get moneyCashCountBy => 'গুনেছেন';

  @override
  String get moneyCashCountApprovedBy => 'মেনেছেন';

  @override
  String get moneyCashCountAdjustment => 'সমন্বয়ের ভাউচার';

  @override
  String get moneyCashCountApproveFailed => 'মেনে নেওয়া গেল না।';

  @override
  String get moneyCashCountApprove => 'মেনে নিন';

  @override
  String get moneyCashCountPickTill => 'কোন টিল — বাছুন।';

  @override
  String get moneyCashCountSaveFailed => 'গোনা বসানো গেল না।';

  @override
  String get moneyCashCountNewTitle => 'নগদ গুনুন';

  @override
  String get moneyCashCountTillLabel => 'কোন টিল';

  @override
  String moneyCashCountTillOption(String name, String balance) {
    return '$name · খাতায় $balance';
  }

  @override
  String moneyCashCountNoteLabel(String note) {
    return '$note টাকার নোট — কয়টা';
  }

  @override
  String get moneyCashCountSave => 'গোনা বসান';

  @override
  String get moneyCashCountHint =>
      'গোনা বসলে খাতা নড়ে না — অন্য কেউ মেনে নিলে তবেই পার্থক্য খাতায় যায়।';

  @override
  String get moneyAccountsLoadFailed => 'টাকার খাত আনা গেল না।';

  @override
  String get moneyHandLoanMissing => 'টাকার খাত আর অঙ্ক লাগবেই।';

  @override
  String get moneyHandLoanSaveFailed => 'হাতধার বসানো গেল না।';

  @override
  String moneyHandLoanTitle(String name) {
    return 'হাতধার · $name';
  }

  @override
  String get moneyHandLoanOut => 'টাকা দিলাম';

  @override
  String get moneyHandLoanIn => 'ফেরত পেলাম';

  @override
  String get moneyHandLoanFromAccount => 'কোন খাত থেকে';

  @override
  String get moneyHandLoanToAccount => 'কোন খাতে';

  @override
  String get moneyHandLoanNoteLabel => 'নোট (ঐচ্ছিক)';

  @override
  String get moneyHandLoanSave => 'বসান';

  @override
  String get moneyCfoTitle => 'অর্থ-প্রধানের পাতা';

  @override
  String moneyCfoIn30Days(String days) {
    return '$days দিন পরে হাতে';
  }

  @override
  String get moneyBudgetExpenseMonth => 'এই মাসের খরচের বাজেট';

  @override
  String get moneyBudgetMonth => 'এই মাসের বাজেট';

  @override
  String get moneyBudgetLabel => 'বাজেট';

  @override
  String get moneyBudgetSpent => 'খরচ হয়েছে';

  @override
  String get moneyBudgetUsed => 'ব্যবহার';

  @override
  String get moneyForecastTitle => 'নগদের পূর্বাভাস';

  @override
  String get moneyForecastToday => 'আজ হাতে';

  @override
  String moneyForecastPeriod(String when, String until) {
    return '$when ($until পর্যন্ত)';
  }

  @override
  String get moneyForecastReceivables => 'আসবে — পাওনা';

  @override
  String get moneyForecastLoansIn => 'আসবে — ধার';

  @override
  String get moneyForecastPayables => 'যাবে — দেনা';

  @override
  String get moneyForecastLoansOut => 'যাবে — ধার শোধ';

  @override
  String get moneyForecastNet => 'নিট';

  @override
  String get moneyIncomeTitle => 'আয়';

  @override
  String get moneyIncomeTotal => 'মোট আয় · এই মাস';

  @override
  String get moneyIncomeSales => 'বিক্রি';

  @override
  String get moneyIncomeOther => 'অন্যান্য আয়';

  @override
  String get moneyIncomeByHead => 'খাত ধরে';

  @override
  String get moneyIncomeEmpty => 'এই মাসে আয়ের কোনো সারি নেই';

  @override
  String moneyIncomeBefore(String amount) {
    return 'আগের সময়ে $amount';
  }

  @override
  String get moneyBudgetTitle => 'বাজেট';

  @override
  String get moneyBudgetEmpty => 'এই মাসের কোনো বাজেট নেই';

  @override
  String get moneyBudgetSetOnWeb => 'বাজেট ওয়েবে বসান।';

  @override
  String get moneyPurchaseBudget => 'ক্রয়ের বাজেট';

  @override
  String get moneyPurchaseBought => 'কেনা হয়েছে';

  @override
  String get creditListHighRisk => 'বেশি ঝুঁকি';

  @override
  String get creditListOverdueBills => 'মেয়াদ পেরোনো বিল';

  @override
  String get creditOverLimit => 'সীমা পার';

  @override
  String get creditListAll => 'সব গ্রাহক';

  @override
  String get creditDeskLoadFailed => 'বাকির হিসাব আনা গেল না।';

  @override
  String get creditDeskMissing =>
      'বাকি নিয়ন্ত্রণ এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get creditDeskTitle => 'বাকি নিয়ন্ত্রণ';

  @override
  String get creditDeskSearchHint => 'গ্রাহকের নাম বা কোড';

  @override
  String get creditDeskEmptyHighRisk => 'বেশি ঝুঁকির কোনো গ্রাহক নেই';

  @override
  String get creditDeskEmptyOverdue => 'মেয়াদ পেরোনো কোনো বিল নেই';

  @override
  String get creditDeskEmptyOverLimit => 'কেউ সীমা পার করেননি';

  @override
  String get creditDeskEmptyAll => 'কোনো গ্রাহক নেই';

  @override
  String creditDeskShowingFirst(String shown, String total) {
    return 'প্রথম $shownটা দেখানো হলো, মোট $totalটা — খুঁজে নিন।';
  }

  @override
  String get creditDashUsed => 'ব্যবহৃত বাকি';

  @override
  String get creditDashCheckOff =>
      'এই প্রতিষ্ঠানে সীমার যাচাই বন্ধ — অঙ্কগুলো কেবল দেখার জন্য।';

  @override
  String get creditDashTotalLimit => 'মোট সীমা';

  @override
  String get creditAvailable => 'অবশিষ্ট সীমা';

  @override
  String get creditDashUsedShare => 'সীমার ব্যবহার';

  @override
  String get creditDashOverdue => 'মেয়াদ পেরোনো পাওনা';

  @override
  String get creditDashOverLimit => 'সীমা পার গ্রাহক';

  @override
  String get creditOverdueStopped => 'মেয়াদ পেরোনো বাকিতে বন্ধ';

  @override
  String get creditDashBlocked => 'বাকি বন্ধ গ্রাহক';

  @override
  String get creditDashPending => 'সীমা বাড়ানোর অনুরোধ (সইয়ের অপেক্ষায়)';

  @override
  String creditPeople(String count) {
    return '$count জন';
  }

  @override
  String creditDashPendingCount(String count) {
    return '$countটা';
  }

  @override
  String creditRowBill(String no) {
    return 'বিল $no';
  }

  @override
  String creditRowOverdueDays(String days) {
    return 'মেয়াদ পেরিয়েছে $days দিন';
  }

  @override
  String creditRowRisk(String level) {
    return 'ঝুঁকি: $level';
  }

  @override
  String creditRowLimit(String limit, String available) {
    return 'সীমা $limit · অবশিষ্ট $available';
  }

  @override
  String get creditCustomerLoadFailed => 'গ্রাহকের বাকির পাতা আনা গেল না।';

  @override
  String get creditCustomerTitle => 'বাকির পাতা';

  @override
  String get creditBlocked => 'বাকি বন্ধ';

  @override
  String get creditBouncedOwnerOnly => 'চেক ফেরতের বন্ধ — খোলেন কেবল মালিক';

  @override
  String get creditLift => 'বাকি বন্ধ তুলুন';

  @override
  String creditUsedPercent(String percent) {
    return 'সীমার $percent% ব্যবহৃত';
  }

  @override
  String get creditLimit => 'সীমা';

  @override
  String get creditTempLimit => 'অস্থায়ী সীমা';

  @override
  String creditTempLimitValue(String amount, String date) {
    return '$amount ($date পর্যন্ত)';
  }

  @override
  String get creditCurrentLimit => 'চলতি সীমা';

  @override
  String get creditDays => 'বাকির দিন';

  @override
  String creditDaysValue(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days দিন',
    );
    return '$_temp0';
  }

  @override
  String get creditDue => 'খাতার বকেয়া';

  @override
  String get creditHeld => 'আটকে থাকা';

  @override
  String get creditUncleared => 'ক্লিয়ার না হওয়া চেক';

  @override
  String get creditUsed => 'ব্যবহৃত';

  @override
  String get creditRiskTitle => 'ঝুঁকির মাত্রা';

  @override
  String get creditRiskNone => 'কোনো লক্ষণ নেই';

  @override
  String get creditRiskNote => 'মাত্রা কেবল জানায় — সীমা বা দেয়াল বদলায় না।';

  @override
  String get creditRequest => 'সীমা বাড়ানোর অনুরোধ';

  @override
  String get creditOpenBills => 'খোলা বিল';

  @override
  String get creditNoOpenBills => 'কোনো খোলা বিল নেই';

  @override
  String creditBillDueOverdue(String date, int days) {
    return 'মেয়াদ $date — $days দিন পেরিয়েছে';
  }

  @override
  String creditBillDue(String date) {
    return 'মেয়াদ $date';
  }

  @override
  String get creditNoRequests => 'কোনো অনুরোধ নেই';

  @override
  String creditRequestTemporaryUntil(String date) {
    return 'অস্থায়ী, $date পর্যন্ত';
  }

  @override
  String get creditStandardLimit => 'সাধারণ সীমা';

  @override
  String get creditSheetNeedAmount => 'চাওয়া সীমা লিখুন।';

  @override
  String get creditNeedReason => 'কারণ লিখুন।';

  @override
  String get creditSheetNeedUntil => 'অস্থায়ী সীমার শেষ দিন বাছুন।';

  @override
  String get creditSheetSendFailed => 'অনুরোধ পাঠানো গেল না।';

  @override
  String creditSheetTitle(String name) {
    return 'সীমা বাড়ানোর অনুরোধ — $name';
  }

  @override
  String creditSheetCurrent(String amount) {
    return 'এখনকার সীমা $amount';
  }

  @override
  String get creditSheetAmount => 'চাওয়া সীমা (৳)';

  @override
  String get creditSheetPickUntil => 'শেষ দিন বাছুন';

  @override
  String creditSheetUntil(String date) {
    return 'শেষ দিন $date';
  }

  @override
  String get creditReason => 'কারণ';

  @override
  String get creditSheetOwnerNote =>
      'মালিকের সই পড়লে তবেই বসে; ততক্ষণ পুরনো সীমাই চলে।';

  @override
  String get creditSheetSending => 'পাঠানো হচ্ছে…';

  @override
  String get creditSheetSend => 'সইয়ের জন্য পাঠান';

  @override
  String get creditLiftFailed => 'বাকি বন্ধ তোলা গেল না।';

  @override
  String get creditLiftKeep => 'থাক';

  @override
  String get creditLiftSend => 'তুলুন';

  @override
  String get creditStateActive => 'চালু';

  @override
  String get creditStateUnchecked => 'সীমার যাচাই বন্ধ';

  @override
  String get creditRiskLow => 'কম';

  @override
  String get creditRiskMedium => 'মাঝারি';

  @override
  String get creditRiskHigh => 'বেশি';

  @override
  String get creditRiskCritical => 'চরম';

  @override
  String get creditRequestPending => 'সইয়ের অপেক্ষায়';

  @override
  String get creditRequestApproved => 'সই পড়েছে — বসেছে';

  @override
  String get creditRequestRejected => 'না';

  @override
  String get creditRequestWithdrawn => 'ফিরিয়ে নেওয়া';

  @override
  String get creditRequestExpired => 'মেয়াদ পেরিয়েছে';

  @override
  String get creditRequestVoid => 'মালিকের সই নেই — বসেনি';

  @override
  String get creditApiRequestSent => 'অনুরোধ পাঠানো হলো।';

  @override
  String get creditApiLifted => 'বাকি বন্ধ তোলা হলো।';

  @override
  String get printingPaperA4 => 'A4 কাগজ';

  @override
  String get printingPaperA5 => 'A5 কাগজ';

  @override
  String printingPaperRoll(String width) {
    return 'রসিদ ${width}mm';
  }

  @override
  String get printingBluetoothPermission =>
      'ব্লুটুথ প্রিন্টারে ছাপতে ফোনের \"আশেপাশের ডিভাইস\" (Nearby devices) অনুমতি লাগে। অনুমতি দেওয়া হয়নি — ফোনের সেটিংস → অ্যাপ → ABOS → অনুমতি থেকে চালু করুন।';

  @override
  String get printingBluetoothOff =>
      'ফোনের ব্লুটুথ বন্ধ আছে। চালু করে আবার চেষ্টা করুন।';

  @override
  String get printingPairedListFailed =>
      'জোড়া দেওয়া প্রিন্টারের তালিকা পাওয়া গেল না। আবার চেষ্টা করুন।';

  @override
  String get printingRasteriseFailed =>
      'কাগজটা ছবিতে রূপান্তর করা গেল না। নরমাল প্রিন্টারে চেষ্টা করুন।';

  @override
  String get printingNoPages => 'কাগজটায় ছাপার মতো কোনো পাতা নেই।';

  @override
  String get printingConnectFailed =>
      'প্রিন্টারে সংযোগ হলো না। প্রিন্টার চালু আছে, কাছে আছে আর অন্য ফোনে যুক্ত নেই কিনা দেখুন।';

  @override
  String get printingSendFailed =>
      'প্রিন্টারে পাঠানো গেল না — সংযোগ কেটে গেছে। প্রিন্টারের কাগজ ও চার্জ দেখে আবার চেষ্টা করুন।';

  @override
  String get printingTestPageTitle => 'পরীক্ষামূলক ছাপা';

  @override
  String printingTestPageSize(String width, String dots) {
    return 'কাগজ $width মিমি · $dots ডট';
  }

  @override
  String get printingTestPageCheck => 'বাংলা লেখা ঠিকমতো এলে প্রিন্টার তৈরি।';

  @override
  String get printingActionsLoadPapersFailed =>
      'এই কাগজের ছাপার মাপ আনা গেল না। আবার চেষ্টা করুন।';

  @override
  String get printingActionsFetchFailed =>
      'কাগজটা সার্ভার থেকে আনা গেল না — ছাপা হয়নি। ইন্টারনেট সংযোগ দেখে আবার চেষ্টা করুন।';

  @override
  String get printingActionsFailed => 'কাজটি সম্পন্ন হয়নি।';

  @override
  String printingActionsSaved(String path) {
    return 'সেভ হয়েছে: $path';
  }

  @override
  String printingActionsSentTo(String printer) {
    return '$printer-এ পাঠানো হয়েছে।';
  }

  @override
  String get printingActionsRetry => 'আবার চেষ্টা';

  @override
  String get printingActionsNormalPrinter => 'নরমাল প্রিন্টার';

  @override
  String get printingActionsNoSheetLayout => 'এই কাগজের A4/A5 নকশা নেই';

  @override
  String get printingActionsNormalSubtitle => 'A4/A5 · ওয়াইফাই, USB, PDF';

  @override
  String get printingActionsThermalPrinter => 'থার্মাল প্রিন্টার';

  @override
  String get printingActionsNoReceiptLayout => 'এই কাগজের রসিদ নকশা নেই';

  @override
  String printingActionsThermalSubtitle(String narrow, String wide) {
    return '$narrow/$wide মিমি · ব্লুটুথ';
  }

  @override
  String get printingActionsView => 'পর্দায় দেখুন';

  @override
  String get printingActionsShare => 'শেয়ার করুন';

  @override
  String get printingActionsShareHint => 'হোয়াটসঅ্যাপ, ইমেইল, ড্রাইভ';

  @override
  String get printingActionsSave => 'ফোনে সেভ করুন';

  @override
  String get printingActionsPrint => 'ছাপুন';

  @override
  String get printingActionsNoPrinter => 'প্রিন্টার বাছা হয়নি';

  @override
  String get printingActionsConnectsWhenPrinting => 'ছাপার সময় যুক্ত হবে';

  @override
  String get printingActionsChooseOnFirstPrint =>
      'প্রথমবার ছাপার সময় জোড়া দেওয়া প্রিন্টার থেকে বাছবেন';

  @override
  String get printingActionsSettings => 'সেটিং';

  @override
  String get printingActionsPrintThermal => 'থার্মালে ছাপুন';

  @override
  String printingSettingsSaved(String printer) {
    return 'প্রিন্টার রাখা হলো: $printer';
  }

  @override
  String get printingSettingsTestSent =>
      'পরীক্ষামূলক ছাপা পাঠানো হয়েছে। বাংলা লেখা ঠিক এলে প্রিন্টার তৈরি।';

  @override
  String get printingSettingsTestFailed =>
      'পরীক্ষামূলক ছাপা হয়নি। আবার চেষ্টা করুন।';

  @override
  String get printingSettingsTitle => 'প্রিন্টার';

  @override
  String get printingSettingsThermalHeading =>
      'থার্মাল (ব্লুটুথ রসিদ) প্রিন্টার';

  @override
  String get printingSettingsNoPrinter => 'কোনো প্রিন্টার বাছা হয়নি';

  @override
  String get printingSettingsPairFirst =>
      'প্রথমে ফোনের সেটিংস → ব্লুটুথ থেকে প্রিন্টারটি জোড়া দিন';

  @override
  String get printingSettingsChange => 'বদলান';

  @override
  String get printingSettingsChoose => 'বাছুন';

  @override
  String get printingSettingsForget => 'এই প্রিন্টার ভুলে যান';

  @override
  String get printingSettingsRollWidth => 'রোলের মাপ';

  @override
  String printingSettingsWidthMm(String width) {
    return '$width মিমি';
  }

  @override
  String get printingSettingsTestButton => 'পরীক্ষামূলক ছাপা';

  @override
  String get printingSettingsWhereHeading => 'কোন কাগজ কোথায় ছাপা হয়';

  @override
  String get printingSettingsWhereBody =>
      'প্রতিটা কাগজ (বিল, চালান, ভাউচার …) শেষবার যে প্রিন্টারে ছাপা হয়েছিল, পরের বার সেটাই আগে বাছা থাকে। নরমাল প্রিন্টার মানে ফোনের নিজের প্রিন্ট ব্যবস্থা — ওয়াইফাই/USB প্রিন্টার বা PDF সেভ।';

  @override
  String get printingSettingsResetRoads => 'মনে রাখা পছন্দ মুছুন';

  @override
  String get printingThermalNotPaired =>
      'কোনো ব্লুটুথ প্রিন্টার জোড়া দেওয়া নেই। প্রিন্টার চালু করে ফোনের সেটিংস → ব্লুটুথ থেকে একবার জোড়া দিন (পিন সাধারণত 0000 বা 1234), তারপর আবার চেষ্টা করুন।';

  @override
  String get printingThermalExplainTitle => 'ব্লুটুথ প্রিন্টারের অনুমতি';

  @override
  String get printingThermalExplainBody =>
      'রসিদ প্রিন্টারে ছাপতে ABOS ফোনের ব্লুটুথ দিয়ে জোড়া দেওয়া প্রিন্টারের সাথে যুক্ত হবে। এরপর ফোন \"আশেপাশের ডিভাইস\" অনুমতি চাইবে — \"অনুমতি দিন\" চাপুন। এতে আপনার অবস্থান নেওয়া হয় না।';

  @override
  String get printingThermalNotNow => 'এখন না';

  @override
  String get printingThermalOk => 'ঠিক আছে';

  @override
  String get printingThermalChoosePrinter => 'প্রিন্টার বাছুন';

  @override
  String get scanPaperOldPaper =>
      'এটা পুরনো ছাপা কাগজ। ফোনে খোলে না — ওয়েবে খুলুন, বা কাগজটা আবার ছাপুন।';

  @override
  String get scanPaperNotOurs => 'এটা ABOS-এর কাগজের QR নয়।';

  @override
  String get scanPaperNotFound =>
      'কাগজটা পাওয়া যায়নি — বাতিল হয়েছে, বা আপনার শাখার নয়।';

  @override
  String get scanPaperForbidden => 'এই কাগজ দেখার বা বদলানোর অনুমতি আপনার নেই।';

  @override
  String get scanPaperAlreadyOut => 'মাল আগেই বেরিয়ে গেছে।';

  @override
  String get scanPaperBlocked =>
      'হয়নি — গাড়ির তথ্য বসানো নেই, বা দোকানের বাকির সীমা পার। অফিসে বলুন।';

  @override
  String get scanPaperFailed => 'হয়নি। আবার চেষ্টা করুন।';

  @override
  String get scanPaperGateOutTitle => 'মাল বেরোল?';

  @override
  String get scanPaperGateOutBody =>
      'গেট পাস হবে আর বিল কাটা হবে। এটা ফেরানো যায় না।';

  @override
  String get scanPaperNo => 'না';

  @override
  String get scanPaperGateOutYes => 'হ্যাঁ, বেরোল';

  @override
  String get scanPaperTitle => 'কাগজ স্ক্যান';

  @override
  String get scanPaperNoCamera => 'ক্যামেরা ছাড়া QR পড়া যায় না।';

  @override
  String get scanPaperAskCamera => 'ক্যামেরা চালু করুন';

  @override
  String get scanPaperHint => 'চালান বা গেট পাসের QR ক্যামেরার সামনে ধরুন।';

  @override
  String scanPaperSaleNo(String number) {
    return 'বিক্রয় নং $number';
  }

  @override
  String scanPaperDate(String date) {
    return 'তারিখ $date';
  }

  @override
  String scanPaperStatus(String stage) {
    return 'অবস্থা: $stage';
  }

  @override
  String scanPaperOutAt(String when) {
    return 'বেরিয়েছে $when';
  }

  @override
  String scanPaperQty(String qty) {
    return 'পরিমাণ $qty';
  }

  @override
  String scanPaperFreeQty(String qty) {
    return 'ফ্রি $qty';
  }

  @override
  String scanPaperLot(String lot) {
    return 'লট $lot';
  }

  @override
  String get scanPaperVehicleSet => 'গাড়ি বসানো আছে';

  @override
  String get scanPaperVehicleMissing => '⚠ গাড়ি বসানো নেই';

  @override
  String scanPaperVehicle(String vehicle) {
    return 'গাড়ি $vehicle';
  }

  @override
  String scanPaperDriver(String driver) {
    return 'চালক $driver';
  }

  @override
  String scanPaperBillTotal(String amount) {
    return 'বিলের মোট $amount';
  }

  @override
  String get scanPaperGateOut => 'মাল বেরোল';

  @override
  String get scanPaperConfirmDelivery => 'ডেলিভারি নিশ্চিত';

  @override
  String get scanPaperScanAgain => 'আরেকটা স্ক্যান';

  @override
  String get scanPaperStageAllocated => 'বরাদ্দ হয়েছে';

  @override
  String get scanPaperStagePicking => 'মাল তোলা হচ্ছে';

  @override
  String get scanPaperStagePacked => 'প্যাক হয়েছে';

  @override
  String get scanPaperStageDispatched => 'গেট পেরিয়েছে';

  @override
  String get scanPaperStageDelivered => 'পৌঁছেছে';

  @override
  String get scanReceiveNameMissing => 'যিনি মাল নিলেন তাঁর নাম লিখুন।';

  @override
  String get scanReceivePhoneMissing =>
      'যিনি নিলেন তাঁর ফোন নম্বর লিখুন — পরে জিজ্ঞেস করার পথ এটাই।';

  @override
  String scanReceiveQtyInvalid(String product) {
    return '$product: পরিমাণ ঠিক নেই।';
  }

  @override
  String scanReceiveQtyTooMuch(String product) {
    return '$product: নেওয়া আর ভাঙা মিলে চালানের চেয়ে বেশি।';
  }

  @override
  String scanReceiveTitle(String number) {
    return 'বুঝিয়ে দিন — $number';
  }

  @override
  String get scanReceiveNameLabel => 'যিনি মাল নিলেন';

  @override
  String get scanReceivePhoneLabel => 'তাঁর ফোন';

  @override
  String get scanReceiveLinesHint =>
      'প্রতিটা পণ্য — কতটা নিলেন, কতটা ভাঙা; বাকিটা কম (ফেরত আসবে)';

  @override
  String scanReceiveLineHeader(String product, String qty) {
    return '$product · চালানে $qty';
  }

  @override
  String get scanReceiveTaken => 'নিলেন';

  @override
  String get scanReceiveDamaged => 'ভাঙা';

  @override
  String scanReceiveShort(String qty) {
    return 'কম: $qty';
  }

  @override
  String get scanReceiveDone => 'নিশ্চিত — বুঝে নিলেন';

  @override
  String stockWriteCountLine(
      String product, String book, String counted, String difference) {
    return '$product: খাতায় $book → গোনা $counted (পার্থক্য $difference)';
  }

  @override
  String get stockWriteFormAdjust => 'গণনা-সমন্বয়';

  @override
  String get stockWriteFormIssue => 'মাল ইস্যু';

  @override
  String get stockWriteFormOpening => 'খোলা মজুদ';

  @override
  String get stockWriteFormLot => 'লট দেওয়া';

  @override
  String get stockWriteQtyAdjust => 'গুনে পেলাম';

  @override
  String get stockWriteQtyIssue => 'কতটা বের হবে';

  @override
  String get stockWriteQtyOpening => 'পরিমাণ';

  @override
  String get stockWriteQtyLot => 'কতটা লটে যাবে';

  @override
  String get stockWriteLoadFailed => 'মজুদের কাজ আনা গেল না।';

  @override
  String get stockWriteNotOnServer =>
      'এই কাজ এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get stockWriteWorkTitle => 'মজুদের কাজ';

  @override
  String get stockWritePlaceTitle => 'মাল বসানো';

  @override
  String get stockWritePlaceHint => 'এসে পৌঁছানো মাল তাকে তোলা';

  @override
  String get stockWriteAdjustHint => 'গুনে খাতার সাথে মেলানো';

  @override
  String get stockWriteIssueHint => 'বিক্রি ছাড়া মাল বের করা';

  @override
  String get stockWriteOpeningHint => 'শুরুর মজুদ বসানো';

  @override
  String get stockWriteLotHint => 'লট-ছাড়া মালকে লট';

  @override
  String get stockWriteCountsTitle => 'মেনে নেওয়ার অপেক্ষার গণনা';

  @override
  String get stockWriteCountsHint => 'অন্যের গোনা মেনে নেওয়া';

  @override
  String get stockWriteNoAccessTitle => 'মজুদের কোনো লেখার অনুমতি নেই';

  @override
  String get stockWriteNoAccessMessage =>
      'দরকার হলে যিনি অনুমতি দেন তাঁকে বলুন।';

  @override
  String get stockWriteResultDone => 'বসেছে।';

  @override
  String get stockWriteResultWaiting => 'অপেক্ষায় রাখা হলো।';

  @override
  String get stockWriteNeedFieldsWithReason =>
      'পণ্য, গুদাম, কারণ আর পরিমাণ লাগবেই।';

  @override
  String get stockWriteNeedFields => 'পণ্য, গুদাম আর পরিমাণ লাগবেই।';

  @override
  String stockWriteSendFailed(String form) {
    return '$form বসানো গেল না।';
  }

  @override
  String get stockWritePickProduct => 'পণ্য বাছুন';

  @override
  String get stockWriteWarehouse => 'গুদাম';

  @override
  String get stockWriteReason => 'কারণ';

  @override
  String get stockWriteCountingFree => 'ফ্রি মাল গুনছি';

  @override
  String get stockWriteFreeOptional => 'ফ্রি (ঐচ্ছিক)';

  @override
  String get stockWriteUnitCost => 'একক দর';

  @override
  String get stockWriteExtraCostOptional => 'বাড়তি মালের দর (ঐচ্ছিক)';

  @override
  String get stockWriteLotNo => 'লট নম্বর';

  @override
  String get stockWriteLotNoIfLotted => 'লট নম্বর (লট-ধরা পণ্যে)';

  @override
  String get stockWriteExpiryOptional => 'মেয়াদ (ঐচ্ছিক)';

  @override
  String get stockWriteNoteOptional => 'বিবরণ (ঐচ্ছিক)';

  @override
  String stockWriteSend(String form) {
    return '$form পাঠান';
  }

  @override
  String get stockWriteProductSearchHint => 'নাম, কোড বা বারকোড';

  @override
  String get stockWriteNoProductMatch => 'কোনো পণ্য মেলেনি';

  @override
  String get stockWritePlaceLoadFailed => 'বসানোর অপেক্ষার মাল আনা গেল না।';

  @override
  String get stockWritePlaceFailed => 'মাল বসানো গেল না।';

  @override
  String get stockWritePlaceEmpty => 'বসানোর অপেক্ষায় কিছু নেই';

  @override
  String get stockWritePullAgain => 'নিচে টেনে আবার দেখুন।';

  @override
  String stockWriteLot(String lot) {
    return 'লট $lot';
  }

  @override
  String stockWriteWaitingQty(String qty) {
    return 'অপেক্ষায় $qty';
  }

  @override
  String stockWriteFreeQty(String qty) {
    return 'ফ্রি $qty';
  }

  @override
  String get stockWritePlacingLabel => 'বসাচ্ছি';

  @override
  String get stockWritePlaceSpot => 'জায়গা';

  @override
  String get stockWritePlaceButton => 'বসান';

  @override
  String get stockWriteCountsLoadFailed => 'অপেক্ষার গণনা আনা গেল না।';

  @override
  String get stockWriteChooseReason =>
      'কারণ বাছুন — পার্থক্যটা কোন খাতে যাবে তা কারণ বলে।';

  @override
  String get stockWriteAcceptFailed => 'গণনাটা মেনে নেওয়া গেল না।';

  @override
  String get stockWriteCountsTotal => 'অপেক্ষায় মোট';

  @override
  String stockWriteCountValue(int count) {
    return '$count টি';
  }

  @override
  String get stockWriteCountsEmpty => 'অপেক্ষায় কোনো গণনা নেই';

  @override
  String get stockWriteAccept => 'মেনে নিন';

  @override
  String get stockQualityLoadFailed => 'মান যাচাইয়ের তালিকা আনা গেল না।';

  @override
  String get stockQualityNotOnServer =>
      'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get stockQualityTitle => 'মান যাচাই';

  @override
  String get stockQualityNew => 'নতুন যাচাই';

  @override
  String get stockQualityAll => 'সব';

  @override
  String get stockQualityTotal => 'মোট যাচাই';

  @override
  String stockQualityCountValue(int count) {
    return '$count টি';
  }

  @override
  String get stockQualityEmpty => 'কোনো মান যাচাই নেই';

  @override
  String get stockQualityMore => 'আরও';

  @override
  String get stockQualityOneLoadFailed => 'যাচাইটা আনা গেল না।';

  @override
  String get stockQualitySaveFailed => 'বসানো গেল না।';

  @override
  String stockQualityTitleNo(String no) {
    return 'মান যাচাই · $no';
  }

  @override
  String get stockQualityStatus => 'অবস্থা';

  @override
  String get stockQualityDate => 'তারিখ';

  @override
  String get stockQualityLot => 'লট';

  @override
  String get stockQualityInspected => 'যাচাই করা হলো';

  @override
  String get stockQualityAccepted => 'গৃহীত';

  @override
  String get stockQualityRejected => 'বাতিল';

  @override
  String get stockQualityDisposed => 'সরানো';

  @override
  String get stockQualityCriteria => 'যা দেখা হলো';

  @override
  String get stockQualityRemarks => 'মন্তব্য';

  @override
  String get stockQualityVerdict => 'রায়';

  @override
  String get stockQualityResult => 'ফল';

  @override
  String get stockQualityRemarksOptional => 'মন্তব্য (ঐচ্ছিক)';

  @override
  String get stockQualityGiveVerdict => 'রায় দিন';

  @override
  String get stockQualityDisposeTitle => 'বাতিল মাল সরানো';

  @override
  String get stockQualityDisposeQty => 'কতটা';

  @override
  String get stockQualityDispose => 'সরান';

  @override
  String get stockQualityNeedFields => 'পণ্য আর পরিমাণ লাগবেই।';

  @override
  String get stockQualityOpenFailed => 'যাচাই খোলা গেল না।';

  @override
  String get stockQualityNewTitle => 'নতুন মান যাচাই';

  @override
  String get stockQualityQtyToCheck => 'কতটা যাচাই';

  @override
  String get stockQualityLotOptional => 'লট নম্বর (ঐচ্ছিক)';

  @override
  String get stockQualityCriteriaOptional => 'কী দেখা হবে (ঐচ্ছিক)';

  @override
  String get stockQualityOpen => 'যাচাই খুলুন';

  @override
  String get stockSerialLoadFailed => 'সিরিয়ালের তালিকা আনা গেল না।';

  @override
  String get stockSerialTitle => 'সিরিয়াল নম্বর';

  @override
  String get stockSerialReceive => 'পিস ঢোকান';

  @override
  String get stockSerialIssue => 'পিস বের করুন';

  @override
  String get stockSerialSearchHint => 'সিরিয়াল নম্বর';

  @override
  String get stockSerialTotal => 'মোট সিরিয়াল';

  @override
  String get stockSerialEmpty => 'কোনো সিরিয়াল মেলেনি';

  @override
  String get stockSerialNeedProductAndOne =>
      'পণ্য আর অন্তত একটা সিরিয়াল লাগবেই।';

  @override
  String get stockSerialNeedOne => 'অন্তত একটা সিরিয়াল লাগবেই।';

  @override
  String get stockSerialSaveFailed => 'সিরিয়াল বসানো গেল না।';

  @override
  String get stockSerialNumbersLabel => 'সিরিয়াল — প্রতি সারিতে একটা';

  @override
  String get stockSerialSoldToOptional => 'কাকে (ঐচ্ছিক)';

  @override
  String get stockSerialWarrantyOptional => 'ওয়ারেন্টি, মাস (ঐচ্ছিক)';

  @override
  String get stockSerialReceiveButton => 'ঢোকান';

  @override
  String get stockSerialIssueButton => 'বের করুন';

  @override
  String get stockListTitle => 'হাতে থাকা মজুদ';

  @override
  String get stockListWithValue => 'মূল্যসহ তালিকা';

  @override
  String get stockListValueReportTitle => 'মজুদের মূল্য';

  @override
  String get stockListReports => 'মজুদের রিপোর্ট';

  @override
  String get stockListSearchHint => 'পণ্যের নাম দিয়ে খুঁজুন';

  @override
  String get stockListTotalAvailable => 'মোট বিক্রয়যোগ্য';

  @override
  String get stockListNothingSynced => 'এখনো কোনো মজুদ সিঙ্ক হয়নি';

  @override
  String get stockListLoadFailed => 'মজুদের তালিকা আনা যায়নি';

  @override
  String get stockListPullRetry => 'নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get stockListNoMatch => 'কোনো মিল পাওয়া যায়নি';

  @override
  String get stockListClearFilters => 'ফিল্টার মুছে আবার দেখুন।';

  @override
  String get stockListProductNotSynced => 'পণ্য এখনো সিঙ্ক হয়নি';

  @override
  String get stockListAvailable => 'বিক্রয়যোগ্য';

  @override
  String stockListOnShelf(String qty) {
    return 'তাকে $qty';
  }

  @override
  String stockListOnOrder(String qty) {
    return 'অর্ডারে $qty';
  }

  @override
  String stockListHeld(String qty) {
    return 'আটকানো $qty';
  }

  @override
  String get productsListTitle => 'পণ্যের তালিকা';

  @override
  String get productsListSearchHint => 'পণ্যের নাম দিয়ে খুঁজুন';

  @override
  String productsListTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'মোট $count টি পণ্য',
    );
    return '$_temp0';
  }

  @override
  String get productsListNothingSynced => 'এখনো কোনো পণ্য সিঙ্ক হয়নি';

  @override
  String get productsListLoadFailed => 'পণ্যের তালিকা আনা যায়নি';

  @override
  String get productsListPullRetry => 'নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get productsListNoMatch => 'কোনো মিল পাওয়া যায়নি';

  @override
  String get productsListClearFilters => 'ফিল্টার মুছে আবার দেখুন।';

  @override
  String productsListAvailable(String qty) {
    return 'বিক্রয়যোগ্য $qty';
  }

  @override
  String productsListPurchase(String amount) {
    return 'ক্রয়: $amount';
  }

  @override
  String loadingTripVehicle(String vehicle) {
    return 'গাড়ি $vehicle';
  }

  @override
  String loadingTripDriver(String driver) {
    return 'চালক $driver';
  }

  @override
  String get loadingListLoadFailed =>
      'ট্রিপের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get loadingListNotOnServer =>
      'সার্ভারে লোডিং শিট এখনো আসেনি — অফিসে জানান।';

  @override
  String get loadingListTitle => 'লোডিং শিট';

  @override
  String get loadingListTotal => 'মোট লোডিং শিট';

  @override
  String loadingListCountValue(int count) {
    return '$count টি';
  }

  @override
  String get loadingListEmpty => 'তোলার মতো কোনো খোলা ট্রিপ নেই।';

  @override
  String loadingListChallans(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা চালান',
    );
    return '$_temp0';
  }

  @override
  String get loadingListMore => 'আরও দেখুন';

  @override
  String get loadingSheetLoadFailed =>
      'লোডিং শিট আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get loadingSheetPackedQuestion => 'প্যাক হয়েছে?';

  @override
  String get loadingSheetPackedExplain =>
      'ট্রিপের যে চালানগুলো এখনো তোলা বাকি, সেগুলো \"প্যাক হয়েছে\" ধাপে যাবে।';

  @override
  String get loadingSheetNo => 'না';

  @override
  String get loadingSheetYesPacked => 'হ্যাঁ, প্যাক হয়েছে';

  @override
  String loadingSheetPackedNotice(String doc, int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা চালান \"প্যাক হয়েছে\" ধাপে গেল।',
    );
    return '$doc — $_temp0';
  }

  @override
  String get loadingSheetPackFailed =>
      '\"প্যাক হয়েছে\" লেখা গেল না। আবার চেষ্টা করুন।';

  @override
  String loadingSheetTitle(String no) {
    return 'লোডিং শিট $no';
  }

  @override
  String get loadingSheetByProduct => 'পণ্য ধরে — কত তুলবেন';

  @override
  String loadingSheetLots(String lots) {
    return 'লট: $lots';
  }

  @override
  String get loadingSheetByChallan => 'চালান ধরে — কার জন্য';

  @override
  String get loadingSheetPacked => 'প্যাক হয়েছে';

  @override
  String get loadingSheetAllPacked =>
      'সব চালান প্যাক হয়েছে — গাড়ি রওনার অপেক্ষায়।';

  @override
  String get promoOffersTitle => 'চলতি অফার';

  @override
  String get promoOffersLoadFailed =>
      'অফারের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get promoOffersNotOnServer =>
      'সার্ভারে অফারের তালিকা এখনো আসেনি — অফিসে জানান।';

  @override
  String get promoOffersNoneRunning => 'এখন কোনো চলতি অফার নেই';

  @override
  String get promoOffersOffHere => 'এই শাখায় অফার এখন বন্ধ';

  @override
  String promoOffersNoMatch(String query) {
    return '\"$query\" — এই নামে কোনো অফার মেলেনি।';
  }

  @override
  String get promoOffersSearchHint => 'পণ্যের নাম লিখুন';

  @override
  String promoOffersCountValue(int count) {
    return '$count টি';
  }

  @override
  String get promoOffersGoods => 'কোন মালে';

  @override
  String get promoOffersForWhom => 'কার জন্য';

  @override
  String get promoOffersUntil => 'কবে পর্যন্ত';

  @override
  String get promoOffersEndsToday => 'আজই শেষ';

  @override
  String promoOffersDaysLeft(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: 'আর $days দিন',
    );
    return '$_temp0';
  }

  @override
  String launcherWidgetMore(String count) {
    return 'আরও $countটা';
  }

  @override
  String launcherWidgetTotal(String value) {
    return 'মোট $value';
  }

  @override
  String launcherWidgetAsOf(String time) {
    return '$time পর্যন্ত';
  }

  @override
  String get launcherWidgetSignedOut => 'সাইন আউট করা হয়েছে';

  @override
  String get launcherWidgetCash => 'নগদ';

  @override
  String get launcherWidgetBank => 'ব্যাংক';

  @override
  String get launcherWidgetCashBankMfs => 'নগদ + ব্যাংক + MFS';

  @override
  String get launcherWidgetNoStaffExpenses => 'কর্মীর খরচ বাদে';

  @override
  String get launcherWidgetBilledToday => 'আজ বিল হয়েছে';

  @override
  String get launcherWidgetCollectionsToday => 'আজকের আদায়';

  @override
  String get launcherWidgetNotices => 'নোটিশ';

  @override
  String get launcherWidgetApprovalsName => 'সইয়ের অপেক্ষা';

  @override
  String get launcherWidgetApprovalsTitle => 'আপনার সইয়ের অপেক্ষায়';

  @override
  String get launcherWidgetApprovalsEmpty => 'কোনো সই বাকি নেই';

  @override
  String get launcherWidgetApprovalsNothingNow => 'এখন সইয়ের জন্য কিছু নেই।';

  @override
  String launcherWidgetApprovalsMore(String count) {
    return 'আরও $count টি';
  }

  @override
  String get launcherWidgetAttendanceTitle => 'আমার উপস্থিতি';

  @override
  String get launcherWidgetAttendanceNoRecord => 'আপনার কর্মীর খাতা নেই';

  @override
  String launcherWidgetAttendanceInAt(String time) {
    return 'আজ এসেছি: $time';
  }

  @override
  String get launcherWidgetAttendancePresent => 'আজ উপস্থিত';

  @override
  String get launcherWidgetAttendanceAbsent => 'আজ অনুপস্থিত';

  @override
  String get launcherWidgetAttendanceLeave => 'আজ ছুটি';

  @override
  String get launcherWidgetAttendanceHoliday => 'আজ সরকারি ছুটি';

  @override
  String get launcherWidgetAttendanceNotMarked => 'আজ হাজিরা বসেনি';

  @override
  String get launcherWidgetAttendanceLate => 'দেরি';

  @override
  String launcherWidgetAttendanceLeaveLeft(String days) {
    return 'ছুটি বাকি: $days দিন';
  }

  @override
  String launcherWidgetAttendanceMonth(String present, String absent) {
    return 'এ মাসে: উপস্থিত $present · অনুপস্থিত $absent';
  }

  @override
  String launcherWidgetAttendanceMonthMore(String leave, String late) {
    return 'ছুটি $leave · দেরি $late';
  }

  @override
  String get launcherWidgetBankReconcileTitle => 'ব্যাংক মেলানো বাকি';

  @override
  String launcherWidgetBankReconcileOpen(String count) {
    return 'না-মেলানো সারি: $count';
  }

  @override
  String get launcherWidgetBankReconcileNever => 'এখনো কখনো মেলানো হয়নি';

  @override
  String launcherWidgetBankReconcileLast(String day) {
    return 'শেষ মেলানো: $day';
  }

  @override
  String get launcherWidgetBankReconcileAllDone => 'সব মেলানো';

  @override
  String launcherWidgetBankReconcileAllDoneLast(String day) {
    return 'সব মেলানো · শেষ মেলানো: $day';
  }

  @override
  String get launcherWidgetBigDuesName => 'বড় বকেয়া';

  @override
  String get launcherWidgetBigDuesTitle => 'সবচেয়ে বড় বকেয়া';

  @override
  String get launcherWidgetBigDuesAdvance => 'অগ্রিম জমা আছে';

  @override
  String launcherWidgetBigDuesOverdue(String days) {
    return 'বাকির $days দিন পার';
  }

  @override
  String get launcherWidgetBigDuesEmpty => 'কারো কাছে বাকি নেই';

  @override
  String get launcherWidgetChallansTitle => 'আজকের চালান';

  @override
  String get launcherWidgetChallansGatePending => 'গেট পাস বাকি';

  @override
  String get launcherWidgetChallansMoving => 'পথে আছে';

  @override
  String get launcherWidgetChallansDelivered => 'পৌঁছেছে';

  @override
  String get launcherWidgetChallansReturned => 'ফেরত এসেছে';

  @override
  String launcherWidgetChallansLatest(String customer) {
    return 'শেষ: $customer';
  }

  @override
  String launcherWidgetChallansVehicle(String vehicle) {
    return 'গাড়ি $vehicle';
  }

  @override
  String get launcherWidgetChallansEmpty => 'আজ এখনো কোনো চালান নেই';

  @override
  String get launcherWidgetChequesName => 'চেক — আজ ব্যাংকে জমা';

  @override
  String get launcherWidgetChequesTitle => 'চেক — আজ ব্যাংকে জমা দিতে হবে';

  @override
  String get launcherWidgetChequesTotal => 'আজ জমা দেওয়ার মোট';

  @override
  String get launcherWidgetChequesEmpty => 'আজ জমা দেওয়ার মতো কোনো চেক নেই';

  @override
  String launcherWidgetChequesCheque(String bank, String number) {
    return '$bank চেক $number';
  }

  @override
  String launcherWidgetChequesBounced(String name) {
    return 'ফেরত এসেছে: $name';
  }

  @override
  String launcherWidgetChequesDueAgain(String cheque) {
    return '$cheque · গ্রাহকের খাতায় আবার বাকি';
  }

  @override
  String launcherWidgetChequesDated(String cheque, String day) {
    return '$cheque · তারিখ $day';
  }

  @override
  String get launcherWidgetCreditLimitsTitle => 'বাকির সীমার কাছে';

  @override
  String launcherWidgetCreditLimitsCaption(String percent) {
    return 'গ্রাহক সীমার $percent%-এর বেশি';
  }

  @override
  String get launcherWidgetCreditLimitsEmpty => 'কোনো গ্রাহক সীমার কাছে নেই';

  @override
  String get launcherWidgetExpensesTitle => 'এই মাসের খরচ';

  @override
  String launcherWidgetExpensesMonth(String month) {
    return '$month মাসের খরচ';
  }

  @override
  String get launcherWidgetExpensesEmpty => 'এই মাসে এখনো কোনো খরচ নেই';

  @override
  String get launcherWidgetGoodsName => 'মাল আসছে';

  @override
  String get launcherWidgetGoodsTitle => 'মাল আসছে — ক্রয় অর্ডার';

  @override
  String launcherWidgetGoodsSupplier(String supplier, String branch) {
    return '$supplier (সরবরাহকারী)$branch';
  }

  @override
  String launcherWidgetGoodsSupplierLate(String supplier, String branch) {
    return 'দেরি: $supplier (সরবরাহকারী)$branch';
  }

  @override
  String launcherWidgetGoodsWasDue(String day) {
    return 'কথা ছিল $day';
  }

  @override
  String launcherWidgetGoodsWeek(String count) {
    return 'এই সপ্তাহে $count';
  }

  @override
  String launcherWidgetGoodsWeekLate(String count, String late) {
    return 'এই সপ্তাহে $count · দেরি $late';
  }

  @override
  String get launcherWidgetGoodsEmpty => 'এই সপ্তাহে কোনো মাল আসার কথা নেই';

  @override
  String launcherWidgetGoodsProducts(String count) {
    return '$countটা পণ্য';
  }

  @override
  String get launcherWidgetGoodsToday => 'আসবে আজ';

  @override
  String get launcherWidgetGoodsTomorrow => 'আসবে কাল';

  @override
  String launcherWidgetGoodsOn(String day) {
    return 'আসবে $day';
  }

  @override
  String get launcherWidgetMoneyTitle => 'টাকা হাতে';

  @override
  String get launcherWidgetMoneyCashBox => 'নগদ বাক্স';

  @override
  String get launcherWidgetMoneyTransit => 'পথে (ব্যাংকে যাচ্ছে)';

  @override
  String get launcherWidgetNoticesUnread => 'না-পড়া';

  @override
  String get launcherWidgetNoticesEmpty => 'কোনো নোটিশ নেই';

  @override
  String get launcherWidgetNoticesNew => 'নতুন';

  @override
  String get launcherWidgetOffersName => 'অফার আর স্কিম';

  @override
  String get launcherWidgetOffersTitle => 'চলতি অফার আর স্কিম';

  @override
  String launcherWidgetOffersActive(String count) {
    return 'চালু $count';
  }

  @override
  String get launcherWidgetOffersEmpty => 'এখন কোনো চালু অফার নেই';

  @override
  String get launcherWidgetOffersEndsToday => 'শেষ: আজ';

  @override
  String get launcherWidgetOffersEndsTomorrow => 'শেষ: কাল';

  @override
  String launcherWidgetOffersEndsInDays(String days) {
    return 'শেষ: $days দিন পর';
  }

  @override
  String launcherWidgetOffersEndsOn(String day) {
    return 'শেষ: $day';
  }

  @override
  String get launcherWidgetOwnerName => 'মালিকের পূর্ণ পর্দা';

  @override
  String get launcherWidgetOwnerAllBranches => 'সব শাখা';

  @override
  String launcherWidgetOwnerReceipts(String count) {
    return 'রসিদ $countটা';
  }

  @override
  String launcherWidgetOwnerCashAmount(String amount) {
    return 'নগদ $amount';
  }

  @override
  String get launcherWidgetOwnerDues => 'মোট বকেয়া';

  @override
  String launcherWidgetOwnerShops(String count) {
    return '$countটা দোকান';
  }

  @override
  String get launcherWidgetOwnerProfit => 'এ মাসের মোট লাভ';

  @override
  String launcherWidgetOwnerMargin(String margin) {
    return 'হার $margin%';
  }

  @override
  String get launcherWidgetOwnerExpenses => 'এ মাসের খরচ';

  @override
  String launcherWidgetOwnerTarget(String month, String days) {
    return '$month-এর লক্ষ্য · বাকি $days দিন';
  }

  @override
  String launcherWidgetOwnerToSign(String count) {
    return '$count সই';
  }

  @override
  String launcherWidgetOwnerCheques(String count) {
    return '$count চেক';
  }

  @override
  String launcherWidgetOwnerLowStock(String count) {
    return '$count মজুদ কম';
  }

  @override
  String launcherWidgetOwnerChallansMoving(String count) {
    return '$count চালান পথে';
  }

  @override
  String launcherWidgetOwnerNotices(String count) {
    return '$count নোটিশ';
  }

  @override
  String launcherWidgetOwnerBills(String label, String count) {
    return '$label · $countটা বিল';
  }

  @override
  String get launcherWidgetOwnerEmpty => 'এখনো দেখানোর মতো কিছু নেই';

  @override
  String get launcherWidgetOwnerMonth => 'মাস';

  @override
  String get launcherWidgetCommissionName => 'প্রিন্সিপালের কমিশন';

  @override
  String launcherWidgetCommissionTotal(String side) {
    return 'মোট · $side';
  }

  @override
  String get launcherWidgetCommissionEmpty => 'এই চক্রে কোনো কমিশন নেই';

  @override
  String get launcherWidgetProfitTitle => 'এই মাসের মোট লাভ';

  @override
  String get launcherWidgetProfitLossThisMonth => 'এই মাসের মোট লোকসান';

  @override
  String launcherWidgetProfitMonth(String month) {
    return '$month মাসের মোট লাভ';
  }

  @override
  String launcherWidgetProfitLossMonth(String month) {
    return '$month মাসের মোট লোকসান';
  }

  @override
  String launcherWidgetProfitMargin(String margin) {
    return 'মোট লাভের হার $margin%';
  }

  @override
  String get launcherWidgetProfitSales => 'বিক্রি (ভ্যাট আর ফেরত বাদে)';

  @override
  String get launcherWidgetProfitCogs => 'বিক্রিত মালের দাম';

  @override
  String get launcherWidgetQuickName => 'দ্রুত কাজ';

  @override
  String get launcherWidgetQuickOrder => 'নতুন অর্ডার';

  @override
  String get launcherWidgetQuickCollect => 'টাকা আদায়';

  @override
  String get launcherWidgetQuickVoucher => 'ভাউচার';

  @override
  String get launcherWidgetQuickScan => 'স্ক্যান';

  @override
  String get launcherWidgetQuickSign => 'সই';

  @override
  String get launcherWidgetQuickEmpty => 'আপনার জন্য কোনো দ্রুত কাজ নেই';

  @override
  String launcherWidgetSalesBills(String count) {
    return 'বিল $countটা';
  }

  @override
  String get launcherWidgetSalesCollection => 'আদায়';

  @override
  String get launcherWidgetSalesSameAsYesterday => 'গতকালের সমান';

  @override
  String launcherWidgetSalesUp(String percent) {
    return '▲ গতকালের চেয়ে $percent% বেশি';
  }

  @override
  String launcherWidgetSalesDown(String percent) {
    return '▼ গতকালের চেয়ে $percent% কম';
  }

  @override
  String get launcherWidgetSrName => 'বিক্রয়কর্মীর তালিকা';

  @override
  String get launcherWidgetSrMine => 'আজ আমার বিক্রি';

  @override
  String get launcherWidgetSrYours => 'আজ আপনার বিক্রি';

  @override
  String launcherWidgetSrRank(String rank) {
    return 'আজ আপনি $rank নম্বরে';
  }

  @override
  String launcherWidgetSrOutOf(String of) {
    return '$of জনের মধ্যে';
  }

  @override
  String get launcherWidgetSrTitle => 'আজ সবচেয়ে বেশি বিক্রি — বিক্রয়কর্মী';

  @override
  String launcherWidgetSrBranch(String branch) {
    return 'শাখা: $branch';
  }

  @override
  String get launcherWidgetSrEmpty => 'আজ এখনো কোনো বিক্রি নেই';

  @override
  String get launcherWidgetStockName => 'মজুদ সতর্কতা';

  @override
  String get launcherWidgetStockTitle => 'মজুদ কমে গেছে';

  @override
  String get launcherWidgetStockEmpty => 'সব মজুদ পুনঃক্রয়ের সীমার উপরে';

  @override
  String get launcherWidgetStockAllWarehouses => 'সব গুদাম';

  @override
  String launcherWidgetStockWarehouse(String warehouse) {
    return 'গুদাম: $warehouse';
  }

  @override
  String get launcherWidgetSyncName => 'সিঙ্কের অবস্থা';

  @override
  String get launcherWidgetSyncRunning => 'সিঙ্ক চলছে';

  @override
  String get launcherWidgetSyncOffline => 'নেট নেই';

  @override
  String get launcherWidgetSyncFailed => 'সিঙ্ক হয়নি';

  @override
  String get launcherWidgetSyncStale => 'সিঙ্ক পুরনো';

  @override
  String get launcherWidgetSyncOk => 'সিঙ্ক ঠিক আছে';

  @override
  String get launcherWidgetSyncNever => 'শেষ সিঙ্ক: এখনো হয়নি';

  @override
  String launcherWidgetSyncLast(String ago) {
    return 'শেষ সিঙ্ক: $ago';
  }

  @override
  String launcherWidgetSyncQueued(String count) {
    return 'জমা কাজ: $count';
  }

  @override
  String get launcherWidgetSyncKeptOffline => 'অফলাইনে জমা থাকে';

  @override
  String get launcherWidgetTargetName => 'লক্ষ্য বনাম অর্জন';

  @override
  String get launcherWidgetTargetMineThisMonth => 'এই মাসে আমার লক্ষ্য';

  @override
  String get launcherWidgetTargetThisMonth => 'এই মাসের লক্ষ্য';

  @override
  String launcherWidgetTargetMineMonth(String month) {
    return '$monthে আমার লক্ষ্য';
  }

  @override
  String launcherWidgetTargetMonth(String month) {
    return '$monthের লক্ষ্য';
  }

  @override
  String get launcherWidgetTargetNotSet => 'এই মাসের লক্ষ্য বসানো নেই';

  @override
  String launcherWidgetTargetDaysLeft(String days) {
    return 'বাকি $days দিন';
  }

  @override
  String get launcherWidgetTargetMonthOver => 'মাস শেষ';

  @override
  String launcherWidgetTargetCrore(String amount) {
    return '$amount কোটি';
  }

  @override
  String launcherWidgetTargetLakh(String amount) {
    return '$amount লাখ';
  }

  @override
  String get launcherWidgetTodayNotForAccount =>
      'এই অ্যাকাউন্টে এই হিসাব দেখানো হয় না';

  @override
  String get launcherWidgetTodayNotOnServer => 'এই হিসাব এখনো সার্ভারে নেই';
}
