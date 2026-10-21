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
  String get booksMethodCash => 'নগদ';

  @override
  String get booksMethodBank => 'ব্যাংক';

  @override
  String get booksMethodCheque => 'চেক';

  @override
  String get booksMethodOther => 'অন্য';

  @override
  String get booksMethodMobileBanking => 'মোবাইল ব্যাংকিং';

  @override
  String get booksReceiptPosted => 'পোস্ট হয়েছে · খাতায় উঠেছে';

  @override
  String get booksReceiptPending => 'অপেক্ষায় — অনুমোদন বা নিশ্চিত বাকি';

  @override
  String get booksReceiptCancelled => 'বাতিল';

  @override
  String get booksBalanceNone => 'জের নেই';

  @override
  String booksBalanceToPay(String amount) {
    return 'দিতে হবে: $amount';
  }

  @override
  String booksBalanceToReceive(String amount) {
    return 'পাব: $amount';
  }

  @override
  String get booksRangeToday => 'আজ';

  @override
  String get booksRangeMonth => 'এ মাস';

  @override
  String booksTotalCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '· $count টি',
    );
    return '$_temp0';
  }

  @override
  String get booksMore => 'আরও দেখুন';

  @override
  String get booksCollectAccountsFailed =>
      'টাকার খাতের তালিকা আনা গেল না। নেট দেখে আবার খুলুন।';

  @override
  String get booksCollectPickCustomerError => 'গ্রাহক বাছুন।';

  @override
  String get booksCollectAmountWrong =>
      'অঙ্কটা ঠিক নয় — শূন্যের বেশি, দুই দশমিক পর্যন্ত।';

  @override
  String get booksCollectPickAccountError => 'টাকার খাত বাছুন।';

  @override
  String get booksCollectNotLanded => 'আদায়টা বসেনি। আবার চেষ্টা করুন।';

  @override
  String get booksCollectMayHaveLanded =>
      'আদায়ের উত্তর আসেনি — সার্ভারে হয়তো পৌঁছেছে — কিছু না বদলে আবার চাপুন; আগেরটা বসে থাকলে সেটাই দেখাবে, দুবার বসবে না।';

  @override
  String get booksCollectNeedsNet =>
      'আদায় দিতে নেট লাগবে। নেট চালু করে আবার চাপুন — ফোনে কিছু জমা থাকেনি।';

  @override
  String get booksCollectSendFailed =>
      'আদায় জমা করা গেল না। আবার চেষ্টা করুন।';

  @override
  String get booksCollectTitle => 'টাকা আদায় নিন';

  @override
  String get booksCollectPickCustomer => 'গ্রাহক বাছুন';

  @override
  String get booksCollectAmountLabel => 'অঙ্ক (টাকা)';

  @override
  String get booksCollectAccountLabel => 'কোন খাতে';

  @override
  String get booksCollectInstrumentLabel => 'মাধ্যম';

  @override
  String get booksCollectInstrumentHint => 'যেমন bKash, Nagad, ব্যাংক জমা';

  @override
  String get booksCollectTrxLabel => 'লেনদেন-নম্বর (TrxID)';

  @override
  String get booksCollectDate => 'তারিখ';

  @override
  String get booksCollectRemarks => 'মন্তব্য (ঐচ্ছিক)';

  @override
  String get booksCollectConfirmNow => 'এখনই নিশ্চিত';

  @override
  String get booksCollectConfirmHint =>
      'অনুমোদন লাগলে আদায়টা অপেক্ষায় থাকবে, ওয়েবের মতো';

  @override
  String get booksCollectSend => 'আদায় জমা দিন';

  @override
  String booksCollectSendAmount(String amount) {
    return 'আদায় জমা দিন — $amount';
  }

  @override
  String get booksCollectSearchLabel => 'নাম, কোড, ফোন বা পয়েন্ট';

  @override
  String get booksCollectNoCustomer => 'কোনো গ্রাহক মেলেনি।';

  @override
  String get booksMoneyInListFailed =>
      'আদায়ের তালিকা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।';

  @override
  String get booksMoneyInListAbsent =>
      'সার্ভারে আদায়ের তালিকা এখনো আসেনি — অফিসে জানান।';

  @override
  String get booksMoneyInTitle => 'আদায়';

  @override
  String get booksModuleSales => 'বিক্রয়';

  @override
  String get booksMoneyInNew => 'নতুন আদায়';

  @override
  String get booksMoneyInSearchHint => 'গ্রাহক, দোকান বা নম্বর খুঁজুন';

  @override
  String get booksMoneyInAllMethods => 'সব';

  @override
  String get booksMoneyInEmptyTitle => 'এই সময়ে কোনো আদায় নেই';

  @override
  String get booksMoneyInEmptyHint => 'তারিখ বা পদ্ধতি বদলে দেখুন।';

  @override
  String get booksMoneyInEmptyCollect => 'আদায় নিন';

  @override
  String get booksRefresh => 'নতুন করে দেখুন';

  @override
  String get booksMoneyInToday => 'আজকের আদায়';

  @override
  String get booksShopDuesTitle => 'দোকানের বকেয়া';

  @override
  String booksShopDuesMore(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'আরও $countটি দোকান',
    );
    return '$_temp0';
  }

  @override
  String booksShopDuesTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'মোট · $countটি দোকান',
    );
    return '$_temp0';
  }

  @override
  String get booksMoneyInFailed =>
      'আদায়টা আনা গেল না। নেট দেখে আবার চেষ্টা করুন।';

  @override
  String get booksPrintSend => 'ছাপুন / পাঠান';

  @override
  String booksMoneyInPrintTitle(String no) {
    return 'টাকার রসিদ · $no';
  }

  @override
  String get booksFactCustomer => 'গ্রাহক';

  @override
  String get booksFactDate => 'তারিখ';

  @override
  String get booksFactAmount => 'টাকা';

  @override
  String get booksFactStatus => 'অবস্থা';

  @override
  String get booksFactMethod => 'পদ্ধতি';

  @override
  String get booksFactAccount => 'খাত';

  @override
  String get booksFactNumber => 'নম্বর';

  @override
  String get booksFactInstrumentDate => 'কাগজের তারিখ';

  @override
  String get booksFactNarration => 'বিবরণ';

  @override
  String get booksFactWrittenBy => 'লিখেছেন';

  @override
  String get booksMoneyInLines => 'কোন বিলে কত';

  @override
  String get booksMoneyInAdvance => 'অগ্রিম';

  @override
  String get booksPcTitle => 'প্রিন্সিপালের কমিশন';

  @override
  String get booksModuleSupplier => 'সরবরাহকারী';

  @override
  String get booksPcLoadFailedTitle => 'তথ্য আনা গেল না';

  @override
  String get booksPcLoadFailed =>
      'প্রিন্সিপালের কমিশন আনা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।';

  @override
  String get booksPcAbsent =>
      'সার্ভারে এই পাতা এখনো নেই, বা সরবরাহকারীর অংশ বন্ধ। সার্ভার হালনাগাদ হলে দেখা যাবে।';

  @override
  String get booksPcEmptyTitle => 'এই চক্রে কোনো প্রিন্সিপালের কমিশন নেই';

  @override
  String get booksPcEmptyHint =>
      'কমিশনের নিয়ম দেওয়া প্রিন্সিপাল থাকলে এখানে দেখা যাবে।';

  @override
  String booksPcGrandTotal(int count, String side) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'সর্বমোট · $side · $count জন',
    );
    return '$_temp0';
  }

  @override
  String get booksPcPrevMonth => 'আগের মাস';

  @override
  String get booksPcCurrentCycle => 'চলতি চক্র';

  @override
  String get booksPcNextMonth => 'পরের মাস';

  @override
  String get booksPcCurrent => 'চলতি';

  @override
  String get booksPcTotalInflow => 'মোট এসেছে';

  @override
  String get booksPcTotalCommission => 'কমিশন';

  @override
  String get booksPcTotalSent => 'পাঠানো';

  @override
  String booksPcInflowLine(String amount) {
    return 'এসেছে $amount';
  }

  @override
  String booksPcCommissionLine(String amount) {
    return 'কমিশন $amount';
  }

  @override
  String booksPcCommissionRateLine(String rate, String amount) {
    return 'কমিশন ($rate) $amount';
  }

  @override
  String booksPcSentLine(String amount) {
    return 'পাঠানো $amount';
  }

  @override
  String get booksPcUnknown => 'জানা নেই';

  @override
  String booksPcCycle(String cycle) {
    return 'চক্র: $cycle';
  }

  @override
  String get booksPrincipalListFailed =>
      'প্রিন্সিপালের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get booksPrincipalListAbsent =>
      'সার্ভারে প্রিন্সিপালের তালিকা এখনো আসেনি — অফিসে জানান।';

  @override
  String get booksPrincipalTitle => 'প্রিন্সিপাল';

  @override
  String get booksPrincipalSearchHint => 'নাম বা কোড খুঁজুন';

  @override
  String get booksPrincipalTotalDue => 'মোট দেনা';

  @override
  String get booksPrincipalNone => 'কোনো প্রিন্সিপাল নেই।';

  @override
  String get booksPrincipalNoPurchase => 'এখনো কোনো ক্রয় নেই';

  @override
  String booksPrincipalLastPurchaseOn(String date) {
    return 'শেষ ক্রয়: $date';
  }

  @override
  String get booksLedgerFailed => 'খাতা আনা গেল না।';

  @override
  String get booksFactFullName => 'পুরো নাম';

  @override
  String get booksFactPhone => 'ফোন';

  @override
  String get booksFactBalance => 'জের';

  @override
  String get booksFactLastPurchase => 'শেষ ক্রয়';

  @override
  String get booksLedgerEmpty => 'খাতায় কোনো লেনদেন নেই।';

  @override
  String get booksPurchaseListFailed =>
      'ক্রয়ের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get booksPurchaseListAbsent =>
      'সার্ভারে ক্রয়ের তালিকা এখনো আসেনি — অফিসে জানান।';

  @override
  String get booksPurchasePrincipalsFailed =>
      'প্রিন্সিপালের তালিকা আনা গেল না।';

  @override
  String get booksPurchaseAllPrincipals => 'সব প্রিন্সিপাল';

  @override
  String get booksPurchaseTitle => 'ক্রয়';

  @override
  String get booksPurchaseBill => 'ক্রয় বিল';

  @override
  String get booksPurchaseGoodsReceipt => 'মাল গ্রহণ';

  @override
  String get booksPurchaseTotalBills => 'মোট ক্রয়';

  @override
  String get booksPurchaseTotalReceipts => 'মোট গ্রহণ';

  @override
  String get booksPurchaseEmpty => 'এই সময়ে কিছু নেই।';

  @override
  String booksPurchasePaidDue(String paid, String due) {
    return 'পরিশোধিত $paid · বাকি $due';
  }

  @override
  String get booksPurchaseFailed => 'কাগজটা আনা গেল না।';

  @override
  String booksPurchasePrintTitle(String kind, String no) {
    return '$kind · $no';
  }

  @override
  String get booksFactPrincipal => 'প্রিন্সিপাল';

  @override
  String get booksFactSupplierNo => 'তাদের কাগজের নম্বর';

  @override
  String get booksFactTotal => 'মোট';

  @override
  String get booksFactPaid => 'পরিশোধিত';

  @override
  String get booksFactDue => 'বাকি';

  @override
  String get booksPurchaseProducts => 'পণ্য';

  @override
  String booksPurchaseQty(String qty) {
    return 'পরিমাণ $qty';
  }

  @override
  String booksPurchaseFree(String qty) {
    return 'ফ্রি $qty';
  }

  @override
  String booksPurchaseLot(String lot) {
    return 'লট $lot';
  }

  @override
  String booksPurchaseRate(String amount) {
    return 'দর $amount';
  }

  @override
  String get customersTitle => 'গ্রাহক';

  @override
  String get customersDetailGone =>
      'এই গ্রাহক আর এই ফোনে নেই — সিঙ্ক করে দেখুন।';

  @override
  String get customersOpenLedgerHint => 'খাতা খুলুন';

  @override
  String customersDetailOrders(int count) {
    return 'এই দোকানের অর্ডার ($count)';
  }

  @override
  String get customersDetailNoOrders =>
      'এই ফোনে এই দোকানের কোনো অর্ডার সিঙ্ক হয়নি।';

  @override
  String get customersDetailNoNumber => 'নম্বর নেই';

  @override
  String get customersDetailInactive => '⚠️ এই গ্রাহক নিষ্ক্রিয়';

  @override
  String customersDetailLimit(String amount) {
    return 'সীমা $amount';
  }

  @override
  String customersDetailCreditDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days দিনের শর্ত',
    );
    return '$_temp0';
  }

  @override
  String customersDetailSynced(String stamp) {
    return 'সিঙ্ক $stamp';
  }

  @override
  String get customersDetailCall => 'কল করুন';

  @override
  String get customersDetailAddress => 'ঠিকানা';

  @override
  String get customersDetailLedger => 'খাতা দেখুন';

  @override
  String get customersDetailCredit => 'বাকির অবস্থা';

  @override
  String get customersDepositNotice => 'জমার বিজ্ঞপ্তি';

  @override
  String get customersDetailNewOrder => 'নতুন অর্ডার';

  @override
  String get customersLedgerFailed => 'খাতা আনা গেল না।';

  @override
  String get customersLedgerEmpty => 'এই দোকানের খাতায় এখনো কোনো লেনদেন নেই।';

  @override
  String get customersLedgerMore => 'আরও আগের লেনদেন';

  @override
  String get customersLedgerTitle => 'খাতা';

  @override
  String get customersLedgerHasDue => 'বাকি আছে';

  @override
  String get customersLedgerHasAdvance => 'আগাম জমা আছে';

  @override
  String get customersLedgerSettled => 'হিসাব মিলে আছে';

  @override
  String customersLedgerCode(String code) {
    return 'কোড $code';
  }

  @override
  String get customersLedgerOpening => 'শুরুর জের';

  @override
  String get customersLedgerGoodsTotal => 'মোট মাল নিয়েছেন';

  @override
  String get customersLedgerPaidTotal => 'মোট টাকা দিয়েছেন';

  @override
  String get customersLedgerClosing => 'শেষের জের';

  @override
  String customersLedgerSpokenGoods(String amount) {
    return 'মাল $amount';
  }

  @override
  String customersLedgerSpokenPaid(String amount) {
    return 'জমা $amount';
  }

  @override
  String get customersLedgerTookGoods => 'মাল নিয়েছেন';

  @override
  String get customersLedgerPaidMoney => 'টাকা দিয়েছেন';

  @override
  String get customersLedgerRowSettled => 'মিলে গেছে';

  @override
  String customersLedgerRowDue(String amount) {
    return 'বাকি $amount';
  }

  @override
  String customersLedgerRowCredit(String amount) {
    return 'জমা $amount';
  }

  @override
  String get customersLedgerNoDate => 'তারিখ নেই';

  @override
  String get customersPickFromList => 'বাঁয়ের তালিকা থেকে একজন গ্রাহক বাছুন';

  @override
  String get customersSearchHint => 'নাম বা মোবাইল দিয়ে খুঁজুন';

  @override
  String get customersTotalDue => 'মোট বকেয়া';

  @override
  String get customersNoneSynced => 'এখনো কোনো গ্রাহক সিঙ্ক হয়নি';

  @override
  String get customersListFailed => 'গ্রাহকের তালিকা আনা যায়নি';

  @override
  String get customersPullToRetry => 'নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get customersNoMatch => 'কোনো মিল পাওয়া যায়নি';

  @override
  String get customersClearFilters => 'ফিল্টার মুছে আবার দেখুন।';

  @override
  String customersCreditDaysShort(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days দিন',
    );
    return '$_temp0';
  }

  @override
  String get customersDueTitle => 'বকেয়া তালিকা';

  @override
  String get customersDueListFailed => 'বকেয়ার তালিকা আনা যায়নি';

  @override
  String get customersDueNone => 'কারো কাছে বকেয়া নেই';

  @override
  String get customersDueNoneHint => 'সিঙ্ক হয়নি মনে হলে নতুন করে দেখুন।';

  @override
  String get customersRefresh => 'নতুন করে দেখুন';

  @override
  String get customersDueWord => 'বকেয়া';

  @override
  String customersDueWithCode(String code) {
    return '$code · বকেয়া';
  }

  @override
  String get customersDepositLoadFailed => 'তথ্য আনা যায়নি।';

  @override
  String get customersDepositAbsent =>
      'সার্ভারে এই সুবিধা এখনো আসেনি — অফিসে জানান।';

  @override
  String get customersDepositNeedAmount => 'টাকার অঙ্ক লিখুন।';

  @override
  String get customersDepositNeedSlip => 'ব্যাংকে জমায় স্লিপের ছবি দিতেই হবে।';

  @override
  String customersDepositShareUnreadable(String no) {
    return 'বিল $no-এর ভাগ পড়া গেল না — কেবল সংখ্যা লিখুন।';
  }

  @override
  String customersDepositShareOverDue(String no, String amount) {
    return 'বিল $no-এর বকেয়া $amount — তার বেশি এই বিলে দেখানো যায় না।';
  }

  @override
  String customersDepositSharesOverAmount(String shared, String amount) {
    return 'বিলগুলোতে মোট $shared, অথচ জমা $amount — বিলের ভাগ জমার চেয়ে বেশি হতে পারে না।';
  }

  @override
  String get customersDepositSent =>
      'পাঠানো হয়েছে — হিসাবরক্ষক মিলিয়ে দেখবেন। ততক্ষণ বকেয়া কমবে না।';

  @override
  String get customersDepositSendFailed => 'পাঠানো যায়নি। আবার চেষ্টা করুন।';

  @override
  String get customersDepositIntro =>
      'হিসাবরক্ষক স্লিপ মিলিয়ে গ্রহণ করলে তবেই বকেয়া কমবে।';

  @override
  String get customersDepositAmount => 'টাকা';

  @override
  String get customersDepositDate => 'জমার তারিখ';

  @override
  String get customersDepositBank => 'ব্যাংক';

  @override
  String get customersDepositMfs => 'বিকাশ/নগদ';

  @override
  String get customersDepositCash => 'নগদ';

  @override
  String get customersDepositWhichBank => 'কোন ব্যাংকে';

  @override
  String get customersDepositReference => 'স্লিপ / লেনদেন নম্বর';

  @override
  String get customersDepositAgainstBills => 'কোন বিলের বিপরীতে (ঐচ্ছিক)';

  @override
  String get customersDepositBillsHint =>
      'বিল বাছলে গ্রহণের সময় টাকা সেই বিলগুলোতে মিলবে; না বাছলে মোট টাকা দোকানের খাতায় বসবে।';

  @override
  String customersDepositBillDue(String no, String amount) {
    return '$no · বকেয়া $amount';
  }

  @override
  String get customersDepositOnThisBill => 'এই বিলে';

  @override
  String get customersDepositNote => 'আর কিছু বলার';

  @override
  String get customersDepositTakeSlip => 'স্লিপের ছবি তুলুন';

  @override
  String get customersDepositSlipTaken => 'ছবি নেওয়া হয়েছে — আবার তুলুন';

  @override
  String get customersDepositSend => 'পাঠান';

  @override
  String customersDepositHistory(int count) {
    return 'আগের বিজ্ঞপ্তি ($count)';
  }

  @override
  String customersDepositHistoryDate(String date) {
    return 'তারিখ $date';
  }

  @override
  String customersDepositHistoryBy(String name) {
    return 'পাঠালেন $name';
  }

  @override
  String customersDepositHistoryRef(String reference) {
    return 'নম্বর $reference';
  }

  @override
  String customersDepositHistoryBill(String no, String amount) {
    return 'বিল $no · $amount';
  }

  @override
  String customersDepositHistoryReason(String reason) {
    return 'কারণ: $reason';
  }

  @override
  String get customersDepositReasonNotGiven => 'জানানো হয়নি';
}
