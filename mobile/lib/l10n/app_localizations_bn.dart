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
  String get homeStockValue => 'মজুদের মূল্য';

  @override
  String get homeStockValueBasis => 'খরচ-দামে';

  @override
  String get homeStockValueOpenHint => 'মজুদের পাতা খুলুন';

  @override
  String get homeStockPageTitle => 'মজুদ';

  @override
  String get navModuleSales => 'বিক্রয়';

  @override
  String get navModulePurchase => 'ক্রয়';

  @override
  String get navModuleInventory => 'মজুদ';

  @override
  String get navModuleAccounts => 'হিসাব';

  @override
  String get navModuleFinance => 'অর্থ';

  @override
  String get navModuleCustomer => 'গ্রাহক';

  @override
  String get navModuleSupplier => 'সরবরাহকারী';

  @override
  String get navModuleApproval => 'অনুমোদন';

  @override
  String get navModuleHr => 'কর্মী';

  @override
  String get navModuleReports => 'রিপোর্ট';

  @override
  String get navModuleDocuments => 'নথি';

  @override
  String get navModuleExecutive => 'মালিকের কেন্দ্র';

  @override
  String get navModulePromotion => 'প্রমোশন';

  @override
  String get navModuleCreditControl => 'বাকি নিয়ন্ত্রণ';

  @override
  String get navModuleNotification => 'নোটিশ';

  @override
  String get navGroupTransactions => 'লেনদেন';

  @override
  String get navGroupFieldWork => 'মাঠের কাজ';

  @override
  String get navGroupLists => 'তালিকা';

  @override
  String get navGroupMyWork => 'নিজের কাজ';

  @override
  String get navGroupReports => 'রিপোর্ট';

  @override
  String get navGroupDashboards => 'ড্যাশবোর্ড';

  @override
  String get navGroupOther => 'অন্যান্য';

  @override
  String get navGroupApprovals => 'অনুমোদন';

  @override
  String get navGroupSettings => 'সেটিংস';

  @override
  String get navGroupComingSoon => 'শীঘ্রই আসছে';

  @override
  String get navParentDelivery => 'ডেলিভারি ও লোডিং';

  @override
  String get navScreenNewOrder => 'নতুন অর্ডার';

  @override
  String get navScreenOrders => 'অর্ডারের অবস্থা';

  @override
  String get navScreenDeliveryOrders => 'ডেলিভারি অর্ডার';

  @override
  String get navScreenSalesOrders => 'বিক্রয় আদেশ';

  @override
  String get navScreenCounter => 'সরাসরি বিক্রয়';

  @override
  String get navScreenQuotations => 'উদ্ধৃতি';

  @override
  String get navScreenCollections => 'টাকা আদায়';

  @override
  String get navScreenDeliveries => 'আজকের ডেলিভারি';

  @override
  String get navScreenLoading => 'লোডিং শিট';

  @override
  String get navScreenTracking => 'ডেলিভারি ট্র্যাকিং';

  @override
  String get navScreenScan => 'কাগজ স্ক্যান';

  @override
  String get navScreenMyRoute => 'আজকের রুট';

  @override
  String get navScreenLeads => 'লিড';

  @override
  String get navScreenToday => 'আজকের হিসাব';

  @override
  String get navScreenPurchases => 'ক্রয়';

  @override
  String get navScreenPrincipals => 'প্রিন্সিপাল';

  @override
  String get navScreenPrincipalCommission => 'প্রিন্সিপালের কমিশন';

  @override
  String get navScreenProducts => 'পণ্যের তালিকা';

  @override
  String get navScreenStock => 'হাতে থাকা মজুদ';

  @override
  String get navScreenVouchers => 'ভাউচার';

  @override
  String get navScreenAllVouchers => 'সব ভাউচার';

  @override
  String get navScreenCashReceipt => 'নগদ রসিদ';

  @override
  String get navScreenMfsReceipt => 'MFS রসিদ';

  @override
  String get navScreenBankReceipt => 'ব্যাংক রসিদ';

  @override
  String get navScreenPayment => 'পেমেন্ট';

  @override
  String get navScreenContra => 'কন্ট্রা';

  @override
  String get navScreenJournal => 'জার্নাল';

  @override
  String get navScreenExpense => 'খরচ';

  @override
  String get navScreenCustomers => 'গ্রাহক';

  @override
  String get navScreenDues => 'বকেয়া তালিকা';

  @override
  String get navScreenApprovals => 'অনুমোদন';

  @override
  String get navScreenAttendance => 'হাজিরা';

  @override
  String get navScreenClaims => 'খরচের দাবি';

  @override
  String get navScreenReports => 'রিপোর্ট';

  @override
  String get navScreenDashboards => 'ব্যবসার ড্যাশবোর্ড';

  @override
  String get navScreenDeposits => 'জমা ও সঞ্চয়';

  @override
  String get navScreenBankLoans => 'ব্যাংক ঋণ ও কিস্তি';

  @override
  String get navScreenRents => 'ভাড়া';

  @override
  String get navScreenHandLoans => 'হাতধার';

  @override
  String get navScreenDocuments => 'ডকুমেন্টস';

  @override
  String get navScreenOffers => 'চলতি অফার';

  @override
  String get navScreenLeave => 'আমার ছুটি';

  @override
  String get navScreenPayslips => 'বেতনের স্লিপ';

  @override
  String get navScreenInvoices => 'বিক্রয় বিল';

  @override
  String get navScreenCancelledInvoices => 'বাতিল বিক্রয় বিল';

  @override
  String get navScreenChallans => 'চালান';

  @override
  String get navScreenOwnerCentre => 'আজ — গোটা গ্রুপ';

  @override
  String get navScreenCredit => 'বাকির হিসাব';

  @override
  String get navScreenNotices => 'নোটিশ বোর্ড';

  @override
  String get navScreenSyncStatus => 'সিঙ্কের অবস্থা';

  @override
  String get navScreenNotifications => 'বিজ্ঞপ্তি';

  @override
  String get paperKindPurchaseOrder => 'ক্রয়াদেশ';

  @override
  String get paperKindPurchaseBill => 'ক্রয় বিল';

  @override
  String get paperKindGoodsReceipt => 'মাল গ্রহণ';

  @override
  String get paperKindPurchaseReturn => 'ক্রয় ফেরত';

  @override
  String get paperKindRequisition => 'ক্রয়ের চাহিদা';

  @override
  String get paperKindRfq => 'দর চাওয়া';

  @override
  String get paperKindSupplyContract => 'সরবরাহের চুক্তি';

  @override
  String get paperKindSalesReturn => 'বিক্রয় ফেরত';

  @override
  String get paperKindGatePass => 'গেট পাস';

  @override
  String get paperKindTrip => 'ট্রিপ';

  @override
  String get paperKindLoadingSheet => 'লোডিং শিট';

  @override
  String get paperKindTransport => 'পরিবহন';

  @override
  String get paperKindPriceCustomer => 'গ্রাহকভিত্তিক দাম';

  @override
  String get paperKindPriceTier => 'ধরনভিত্তিক দাম';

  @override
  String get paperKindPriceTerritory => 'এলাকাভিত্তিক দাম';

  @override
  String get paperKindPriceAll => 'বিশেষ দাম — সবার জন্য';

  @override
  String get paperKindStockTransfer => 'মাল স্থানান্তর';

  @override
  String get paperKindInterCompany => 'আন্তঃকোম্পানি লেনদেন';

  @override
  String get paperKindCapital => 'মূলধন';

  @override
  String get paperKindInsurance => 'বীমা পলিসি';

  @override
  String get paperKindFallback => 'কাগজ';

  @override
  String get paperKindDeliveryOrder => 'ডেলিভারি অর্ডার';

  @override
  String get paperKindSalesOrder => 'বিক্রয় আদেশ';

  @override
  String get paperKindQuotation => 'উদ্ধৃতি';

  @override
  String get paperKindCollection => 'টাকা আদায়';

  @override
  String get paperKindPurchase => 'ক্রয়';

  @override
  String get paperKindVoucher => 'ভাউচার';

  @override
  String get paperKindClaim => 'খরচের দাবি';

  @override
  String get paperKindDeposit => 'জমা';

  @override
  String get paperKindBankLoan => 'ব্যাংক ঋণ';

  @override
  String get paperKindRent => 'ভাড়া';

  @override
  String get paperKindHandLoan => 'হাতধার';

  @override
  String get paperKindDocument => 'ডকুমেন্ট';

  @override
  String get paperKindPayslip => 'বেতনের স্লিপ';

  @override
  String get paperKindInvoice => 'বিক্রয় বিল';

  @override
  String get paperKindChallan => 'চালান';

  @override
  String get navTabHome => 'হোম';

  @override
  String get navTabApprovals => 'অনুমোদন';

  @override
  String get navTabReports => 'রিপোর্ট';

  @override
  String get navTabOrders => 'অর্ডার';

  @override
  String get navTabCollections => 'আদায়';

  @override
  String get navTabChallans => 'চালান';

  @override
  String get navTabStock => 'মজুদ';

  @override
  String get navTabVouchers => 'ভাউচার';

  @override
  String get navTabModules => 'সব মডিউল';

  @override
  String get navTabWaiting => 'অপেক্ষায়';

  @override
  String navTabSpokenNew(String label, String waiting) {
    return '$label, নতুন $waiting';
  }

  @override
  String navTabBadgeCount(String shown) {
    return '$shownটা';
  }

  @override
  String navTabBadgeOver(String shown) {
    return '$shownটার বেশি';
  }

  @override
  String get navFabNew => 'নতুন';

  @override
  String get navFabNewOrder => 'নতুন অর্ডার';

  @override
  String get navFabNewCollection => 'নতুন আদায়';

  @override
  String get navFabNewClaim => 'নতুন দাবি';

  @override
  String get navFabNewExpense => 'নতুন খরচ';

  @override
  String get navFabNewVoucher => 'নতুন ভাউচার';

  @override
  String get navFabExpenseClaim => 'খরচের দাবি';

  @override
  String get topBarBack => 'পিছনে';

  @override
  String get topBarFullMenu => 'পুরো মেনু';

  @override
  String get topBarSearch => 'খোঁজা';

  @override
  String get topBarMyMenu => 'আমার মেনু';

  @override
  String get topBarSwitchBranch => 'শাখা বদল';

  @override
  String get topBarDemo => 'ডেমো';

  @override
  String get topBarMore => 'আরও';

  @override
  String topBarBranchSpoken(String branch) {
    return '$branch শাখা';
  }

  @override
  String get topBarDemoSpoken => 'ডেমো সার্ভার, আসল খাতা নয়';

  @override
  String topBarOfflinePending(int count, String shown) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'অফলাইন: $shownটা কাগজ পাঠানো বাকি, নেট এলে নিজেই যাবে',
    );
    return '$_temp0';
  }

  @override
  String get topBarOfflineNoNet =>
      'অফলাইন: নেট নেই, যা লিখবেন তা নেট এলে নিজেই যাবে';

  @override
  String get topBarUnsavedTitle => 'না রেখে বদলাবেন?';

  @override
  String get topBarUnsavedBody =>
      'একটা ফর্মে যা লিখেছেন তা এখনো রাখা হয়নি। শাখা বদলালে ওই লেখা হারিয়ে যাবে।';

  @override
  String get topBarUnsavedStay => 'থাকুন';

  @override
  String get topBarUnsavedGo => 'না রেখে বদলান';

  @override
  String get moduleGateOffTitle => 'এই অংশটা এখন বন্ধ';

  @override
  String get moduleGateOffMessage =>
      'আপনার কোম্পানি অ্যাপে এই অংশটা বন্ধ রেখেছে। দরকার হলে প্রশাসককে বলুন কন্ট্রোল প্যানেলের \"মোবাইল অ্যাপ\" ট্যাব থেকে চালু করতে।';

  @override
  String get permissionCameraTitle => 'ক্যামেরার অনুমতি';

  @override
  String get permissionCameraBody =>
      'QR পড়তে, স্লিপ বা রসিদের ছবি আর নিজের প্রোফাইলের ছবি তুলতে ABOS ফোনের ক্যামেরা ব্যবহার করবে। এরপর ফোন ক্যামেরার অনুমতি চাইবে — \"অনুমতি দিন\" চাপুন। আপনি না চাপলে ক্যামেরা চালু হয় না, আর কেবল আপনার পাঠানো ছবিটাই অফিসে যায়।';

  @override
  String get permissionNotificationsTitle => 'বার্তার অনুমতি';

  @override
  String get permissionNotificationsBody =>
      'সই চাওয়া, অনুমোদন আর ডেলিভারির খবর অ্যাপ বন্ধ থাকলেও জানাতে ABOS বার্তা পাঠাবে। এরপর ফোন বার্তার অনুমতি চাইবে — \"অনুমতি দিন\" চাপুন। না দিলেও অ্যাপ চলবে, কেবল খবরগুলো অ্যাপ খুললে তবে দেখবেন।';

  @override
  String get permissionNotNow => 'এখন না';

  @override
  String get permissionOk => 'ঠিক আছে';

  @override
  String get deviceIntegrityRootedEmulator =>
      'এই ফোনটা root করা, আর আসল ফোন নয় (emulator) বলে মনে হচ্ছে';

  @override
  String get deviceIntegrityRooted => 'এই ফোনটা root করা বলে মনে হচ্ছে';

  @override
  String get deviceIntegrityEmulator =>
      'এটা আসল ফোন নয় (emulator) বলে মনে হচ্ছে';

  @override
  String deviceIntegrityWarning(String what) {
    return '$what। এমন ফোনে অন্য অ্যাপ ABOS-এর তথ্য পড়তে পারে — টাকার অঙ্ক আর গ্রাহকের তথ্য ঝুঁকিতে থাকে। অ্যাপ চলবে, তবে অফিসকে জানান।';
  }

  @override
  String get lockDelayNow => 'সাথে সাথে';

  @override
  String lockDelayMinutes(int count, String shown) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$shown মিনিট',
    );
    return '$_temp0';
  }

  @override
  String lockScreenPinLength(String min, String max) {
    return 'PIN $min থেকে $maxটা অঙ্ক।';
  }

  @override
  String get lockScreenPinTwice => 'দুইবার একই PIN লিখুন।';

  @override
  String get lockScreenTooManyTries =>
      'পাঁচবার ভুল PIN — আবার পাসওয়ার্ড দিয়ে ঢুকুন।';

  @override
  String lockScreenPinWrong(int count, String shown) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'PIN মেলেনি — আর $shownবার চেষ্টা করা যাবে।',
    );
    return '$_temp0';
  }

  @override
  String get lockScreenBiometricReason =>
      'ABOS খুলতে আঙুলের ছাপ বা ফোনের PIN দিন';

  @override
  String get lockScreenBiometricTitle => 'ABOS খুলুন';

  @override
  String get lockScreenBiometricCancel => 'থাক';

  @override
  String get lockScreenEnterPin => 'অ্যাপের PIN দিন';

  @override
  String get lockScreenSignOut => 'বেরিয়ে যান';

  @override
  String get lockScreenSignInAgainTitle => 'আবার পাসওয়ার্ড দিয়ে ঢুকুন';

  @override
  String get lockScreenSignInAgainBody =>
      'এই ফোনে তালা নেই, আর অ্যাপের PIN-ও বসানো হয়নি — কে ধরে আছেন জানার উপায় নেই। ঢুকলেই PIN বসাতে বলা হবে।';

  @override
  String get lockScreenSignOutAndIn => 'বেরিয়ে আবার ঢুকুন';

  @override
  String get lockScreenSetPinTitle => 'অ্যাপের PIN বসান';

  @override
  String lockScreenSetPinBody(String min, String max) {
    return 'এই ফোনে তালা নেই। অনুমোদন আর টাকার অঙ্ক রক্ষায় $min–$max অঙ্কের একটা PIN বসান — ফিরে এলে এটাই চাওয়া হবে।';
  }

  @override
  String get lockScreenNewPin => 'নতুন PIN';

  @override
  String get lockScreenPinAgain => 'আবার একই PIN';

  @override
  String get lockScreenSave => 'সংরক্ষণ';

  @override
  String get lockScreenLockedTitle => 'অ্যাপ তালাবদ্ধ';

  @override
  String get lockScreenLockedBody => 'আঙুলের ছাপ বা ফোনের PIN দিয়ে খুলুন।';

  @override
  String get networkErrorNoConnection =>
      'সংযোগ নেই। ইন্টারনেট চেক করে আবার চেষ্টা করুন।';

  @override
  String get authIncompleteAnswer =>
      'সার্ভার থেকে অসম্পূর্ণ উত্তর এসেছে। আবার চেষ্টা করুন।';

  @override
  String get authSignInFailed => 'ঢোকা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।';

  @override
  String get authLoginGreetingMorning => 'শুভ সকাল';

  @override
  String get authLoginGreetingAfternoon => 'শুভ দুপুর';

  @override
  String get authLoginGreetingEvening => 'শুভ সন্ধ্যা';

  @override
  String get authLoginSubtitle => 'আজকের হিসাব আপনার অপেক্ষায়';

  @override
  String get authLoginIdentifier => 'লগইন আইডি (নাম বা ইমেইল)';

  @override
  String get authLoginIdentifierMissing => 'নাম বা ইমেইল দিন';

  @override
  String get authLoginPassword => 'পাসওয়ার্ড';

  @override
  String get authLoginPasswordMissing => 'পাসওয়ার্ড দিন';

  @override
  String get authLoginSignIn => 'প্রবেশ করুন';

  @override
  String get authLoginVerify => 'যাচাই করুন';

  @override
  String get authLoginBack => 'ফিরে যান';

  @override
  String get authLoginRemember => 'মনে রাখুন';

  @override
  String get authLoginForgot => 'পাসওয়ার্ড ভুলে গেছেন?';

  @override
  String get authLoginForgotBody =>
      'নিচের বোতাম চাপলে ব্রাউজারে পাসওয়ার্ড বদলের পাতা খুলবে — ইমেইল দিলে লিংক আসবে। ইমেইল না থাকলে কোম্পানির প্রশাসককে বলুন।';

  @override
  String get authLoginForgotOpen => 'ইমেইলে লিংক নিন';

  @override
  String get authLoginOk => 'ঠিক আছে';

  @override
  String authLoginMfaPrompt(String n) {
    return 'আপনার অথেনটিকেটর অ্যাপ থেকে $n-সংখ্যার কোডটি দিন।';
  }

  @override
  String get authLoginMfaWrong => 'কোডটি ভুল ছিল — আবার চেষ্টা করুন।';

  @override
  String get authLoginMfaLabel => 'এমএফএ কোড';

  @override
  String authLoginMfaMissing(String n) {
    return '$n-সংখ্যার কোডটি দিন';
  }

  @override
  String get authLoginVersion => 'সংস্করণ';

  @override
  String get authLoginHidePassword => 'পাসওয়ার্ড লুকান';

  @override
  String get authLoginShowPassword => 'পাসওয়ার্ড দেখুন';

  @override
  String get profileMenuProfileAndPhoto => 'প্রোফাইল ও ছবি বদল';

  @override
  String get profileMenuMyProfile => 'আমার প্রোফাইল';

  @override
  String get webInsidePageFailed =>
      'পাতাটা খোলা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।';

  @override
  String get webInsideCodeWrong => 'কোডটা মেলেনি — আবার দিন।';

  @override
  String get webInsideCodeNeeded => 'দুই ধাপের কোডটা দিন।';

  @override
  String get webInsideServerTooOld =>
      'সার্ভার এখনো এই সুবিধা পায়নি — অফিসকে সার্ভার হালনাগাদ করতে বলুন।';

  @override
  String get webInsideBadAddress =>
      'এই পর্দার ঠিকানাটা চেনা গেল না — মেনু থেকে আবার খুলুন।';

  @override
  String get webInsideNeedsCode =>
      'দুই ধাপের কোড ছাড়া ওয়েবের পর্দা খোলে না — ওয়েবের লগইনের মতোই।';

  @override
  String get webInsideCodeTitle => 'দুই ধাপের কোড';

  @override
  String webInsideCodeHint(String n) {
    return 'অথেনটিকেটর অ্যাপের $n অঙ্ক';
  }

  @override
  String get webInsideLoadFailed =>
      'পাতাটা আনা গেল না — সংযোগ দেখে আবার চেষ্টা করুন।';

  @override
  String get webInsideFileFetching => 'ফাইল আনা হচ্ছে…';

  @override
  String get webInsideFileFetchFailed =>
      'ফাইলটা আনা গেল না — আবার চেষ্টা করুন।';

  @override
  String get webInsideFileSaveFailed =>
      'ফাইলটা রাখা গেল না — ফোনে জায়গা আছে কি না দেখুন।';

  @override
  String get webInsideFileShare => 'পাঠান বা সেভ করুন';

  @override
  String get webInsidePrintFailed =>
      'ছাপা গেল না — পাতাটা পুরো আসার পরে আবার চেষ্টা করুন।';

  @override
  String get webInsideTakePhoto => 'ছবি তুলুন';

  @override
  String get webInsideFromGallery => 'গ্যালারি থেকে';

  @override
  String get webInsidePickFile => 'ফাইল বাছুন (PDF, এক্সেল…)';

  @override
  String get webInsideFile => 'ফাইল';

  @override
  String get webInsideFilePickFailed =>
      'ফাইলটা নেওয়া গেল না — আবার চেষ্টা করুন।';

  @override
  String get webInsideOk => 'ঠিক আছে';

  @override
  String get webInsideNo => 'না';

  @override
  String get webInsideYes => 'হ্যাঁ';

  @override
  String get webInsideClose => 'বন্ধ করুন';

  @override
  String get webInsidePrint => 'ছাপুন বা PDF';

  @override
  String get webInsideReload => 'নতুন করে আনুন';

  @override
  String get webInsideOpenFailedTitle => 'পাতাটা খোলা গেল না';

  @override
  String get webInsideCancel => 'থাক';

  @override
  String get voucherTypeReceipt => 'আদায় ভাউচার';

  @override
  String get voucherTypePayment => 'পরিশোধ ভাউচার';

  @override
  String get voucherTypeExpense => 'খরচ ভাউচার';

  @override
  String get voucherTypeJournal => 'জাবেদা ভাউচার';

  @override
  String get voucherTypeContra => 'কন্ট্রা ভাউচার';

  @override
  String voucherLineFrom(String account) {
    return 'থেকে: $account';
  }

  @override
  String voucherLineInto(String account) {
    return 'জমা: $account';
  }

  @override
  String get voucherFactFrom => 'কোথা থেকে';

  @override
  String get voucherFactTo => 'কোথায় গেল';

  @override
  String get voucherFactDepositedTo => 'জমা হয়েছে';

  @override
  String get voucherFactPaidFrom => 'যেখান থেকে দেওয়া';

  @override
  String get voucherFactMoneyAccount => 'টাকার খাত';

  @override
  String get voucherPartyReceivedFrom => 'কার কাছ থেকে';

  @override
  String get voucherPartyPaidTo => 'কাকে';

  @override
  String get voucherPartyParty => 'পক্ষ';

  @override
  String voucherState(String state) {
    String _temp0 = intl.Intl.selectLogic(
      state,
      {
        'awaiting': 'সইয়ের অপেক্ষায়',
        'posted': 'পোস্ট হয়েছে',
        'cancelled': 'বাতিল',
        'other': 'খসড়া',
      },
    );
    return '$_temp0';
  }

  @override
  String get voucherViewAll => 'সব';

  @override
  String get voucherViewCashReceipts => 'নগদ রসিদ';

  @override
  String get voucherViewBankReceipts => 'ব্যাংক রসিদ';

  @override
  String get voucherViewPayments => 'পেমেন্ট';

  @override
  String get voucherViewMfsReceipts => 'MFS রসিদ';

  @override
  String get voucherViewContras => 'কন্ট্রা';

  @override
  String get voucherViewJournals => 'জার্নাল';

  @override
  String get voucherViewExpenses => 'খরচ';

  @override
  String get voucherViewAllReceipts => 'সব রসিদ';

  @override
  String offerBenefitPercent(String amount) {
    return '$amount% ছাড়';
  }

  @override
  String offerBenefitAmount(String amount) {
    return '$amount ছাড়';
  }

  @override
  String offerBenefitFree(String count) {
    return '$countটা ফ্রি';
  }

  @override
  String offerBenefitFreeGift(String count, String gift) {
    return '$countটা $gift ফ্রি';
  }

  @override
  String offerBenefitCredit(String amount) {
    return '$amount ফেরত (পরের কেনায়)';
  }

  @override
  String offerBenefitPoints(String amount) {
    return '$amount পয়েন্ট';
  }

  @override
  String offerBenefitCap(String benefit, String cap) {
    return '$benefit (এক বিলে সর্বোচ্চ $cap)';
  }

  @override
  String offerRangeOrMore(String from) {
    return '$from বা বেশি';
  }

  @override
  String offerRangeUpTo(String to) {
    return '$to পর্যন্ত';
  }

  @override
  String offerQuantityUnit(String count) {
    return '$countটা';
  }

  @override
  String offerWhenBuying(String what) {
    return '$what কিনলে';
  }

  @override
  String get offerAnyQuantity => 'যেকোনো পরিমাণে';

  @override
  String get offerAnyAmount => 'যেকোনো অঙ্কে';

  @override
  String get offerSetProduct => 'নির্দিষ্ট পণ্য কিনলে';

  @override
  String get offerSetTime => 'নির্দিষ্ট সময়ে';

  @override
  String offerTimeWindow(String from, String to) {
    return '$from–$to সময়ে কিনলে';
  }

  @override
  String offerGoodsCategory(String name) {
    return '$name (ধরন)';
  }

  @override
  String offerGoodsBrand(String name) {
    return '$name (ব্র্যান্ড)';
  }

  @override
  String get offerGoodsAll => 'সব মালে';

  @override
  String offerForShops(String names) {
    return 'দোকান: $names';
  }

  @override
  String offerForShopTypes(String names) {
    return 'ধরন: $names';
  }

  @override
  String offerForAreas(String names) {
    return 'এলাকা: $names';
  }

  @override
  String approvalCardDiscount(String amount) {
    return 'ছাড় $amount';
  }

  @override
  String get approvalDocPurchaseOrder => 'ক্রয় আদেশ';

  @override
  String get approvalDocPurchaseBill => 'ক্রয় বিল';

  @override
  String get approvalDocPurchaseReturn => 'ক্রয় ফেরত';

  @override
  String get approvalDocPayment => 'পরিশোধ';

  @override
  String get approvalDocStockTransfer => 'মজুদ স্থানান্তর';

  @override
  String get approvalDocPayroll => 'বেতন';

  @override
  String get approvalDocVoucher => 'ভাউচার';

  @override
  String get approvalDocDeliveryOrder => 'ডেলিভারি অর্ডার';

  @override
  String get approvalDocSalesOrder => 'বিক্রয় আদেশ';

  @override
  String get approvalDocSalesInvoice => 'বিক্রয় বিল';

  @override
  String get approvalDocDeliveryChallan => 'চালান';

  @override
  String get approvalDocSalesQuotation => 'দরপত্র';

  @override
  String get approvalDocSalesReturn => 'বিক্রয় ফেরত';

  @override
  String get approvalDocCollection => 'আদায়';

  @override
  String get approvalDocExpenseClaim => 'খরচের দাবি';

  @override
  String get approvalDocNote => 'নোট';

  @override
  String get approvalDocMoneyTransfer => 'টাকা স্থানান্তর';

  @override
  String get approvalDocPromotion => 'অফার';

  @override
  String get approvalDocCustomerCreditRequest => 'বাকির সীমা বাড়ানোর অনুরোধ';

  @override
  String get approvalDocUnknown => 'নথি';

  @override
  String attendanceStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'present': 'উপস্থিত',
        'absent': 'অনুপস্থিত',
        'leave': 'ছুটি',
        'holiday': 'সরকারি ছুটি',
        'other': '$status',
      },
    );
    return '$_temp0';
  }

  @override
  String get customerNoName => 'নাম নেই';

  @override
  String get customerDueNone => 'বকেয়া নেই';

  @override
  String customerDueAdvance(String amount) {
    return 'অগ্রিম $amount';
  }

  @override
  String customerDueAmount(String amount) {
    return 'বকেয়া $amount';
  }

  @override
  String get listSortNameAz => 'নাম: ক থেকে হ';

  @override
  String get listSortNameZa => 'নাম: হ থেকে ক';

  @override
  String get listSortCode => 'কোড অনুযায়ী';

  @override
  String get listFilterStatus => 'অবস্থা';

  @override
  String get listFilterActive => 'সক্রিয়';

  @override
  String get listFilterInactive => 'নিষ্ক্রিয়';

  @override
  String get listFilterMobile => 'মোবাইল নম্বর';

  @override
  String get listFilterYes => 'আছে';

  @override
  String get listFilterNo => 'নেই';

  @override
  String get listFilterType => 'ধরন';

  @override
  String get listSortDueHigh => 'বকেয়া: বেশি আগে';

  @override
  String get listSortDueLow => 'বকেয়া: কম আগে';

  @override
  String get listSortLimitUsed => 'সীমার ব্যবহার: বেশি আগে';

  @override
  String get listFilterCreditLimit => 'ঋণসীমা';

  @override
  String get listFilterOverLimit => 'সীমা ছাড়িয়েছে';

  @override
  String get listFilterWithinLimit => 'সীমার মধ্যে';

  @override
  String get listFilterNoLimit => 'সীমা নেই';

  @override
  String get listFilterCreditTerms => 'বাকির শর্ত';

  @override
  String get listFilterNoTerms => 'শর্ত নেই';

  @override
  String get listSortPriceHigh => 'দাম: বেশি আগে';

  @override
  String get listSortPriceLow => 'দাম: কম আগে';

  @override
  String get listFilterSalePrice => 'বিক্রয় দাম';

  @override
  String get listFilterHasPrice => 'দাম আছে';

  @override
  String get listFilterNoPrice => 'দাম নেই';

  @override
  String get listFilterStock => 'মজুদ';

  @override
  String get listFilterSellable => 'বিক্রয়যোগ্য আছে';

  @override
  String get listFilterOutOfStock => 'মজুদ শূন্য';

  @override
  String get listSortQtyHigh => 'পরিমাণ: বেশি আগে';

  @override
  String get listSortQtyLow => 'পরিমাণ: কম আগে';

  @override
  String get listFilterZeroStock => 'শূন্য মজুদ';

  @override
  String get listFilterHide => 'লুকান';

  @override
  String get listFilterOnlyZero => 'শুধু শূন্য';

  @override
  String get listFilterCommitted => 'অর্ডারে বা আটকানো';

  @override
  String get listFilterFreeGoods => 'ফ্রি মাল';

  @override
  String get listSortDateNew => 'তারিখ: নতুন আগে';

  @override
  String get listSortDateOld => 'তারিখ: পুরনো আগে';

  @override
  String get listSortAmountHigh => 'টাকা: বেশি আগে';

  @override
  String get listSortAmountLow => 'টাকা: কম আগে';

  @override
  String get listSortShopAz => 'দোকান: ক থেকে হ';

  @override
  String listFilterCreditDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days দিন',
    );
    return '$_temp0';
  }

  @override
  String get listFilter => 'ফিল্টার';

  @override
  String get listSort => 'সাজান';

  @override
  String get listFilterAll => 'সব';

  @override
  String get listFilterClearAll => 'সব মুছুন';

  @override
  String get listFilterApply => 'প্রয়োগ করুন';

  @override
  String moneySpokenCrore(String n) {
    return '$n কোটি';
  }

  @override
  String moneySpokenLakh(String n) {
    return '$n লাখ';
  }

  @override
  String moneySpokenThousand(String n) {
    return '$n হাজার';
  }

  @override
  String get moneySpokenMinus => 'ঋণাত্মক';

  @override
  String moneySpokenTaka(String amount) {
    return '$amount টাকা';
  }

  @override
  String moneySpokenPaisa(String n) {
    return '$n পয়সা';
  }

  @override
  String get commissionSidePay => 'দেনা';

  @override
  String get commissionSideReceive => 'পাওনা';

  @override
  String get commissionSideSettled => 'জের নেই';

  @override
  String get commissionSidePaySentence => 'দেনা · দিতে হবে';

  @override
  String get commissionSideReceiveSentence => 'পাওনা · কোম্পানির কাছে পাব';

  @override
  String commissionLoss(String amount) {
    return 'লোকসান $amount — কেনা দামের নিচে বিক্রি';
  }

  @override
  String get dayGreetingNight => 'শুভ রাত্রি';

  @override
  String get dayGreetingMorning => 'শুভ সকাল';

  @override
  String get dayGreetingNoon => 'শুভ দুপুর';

  @override
  String get dayGreetingAfternoon => 'শুভ বিকাল';

  @override
  String get dayGreetingEvening => 'শুভ সন্ধ্যা';

  @override
  String get dayPartNight => 'রাত';

  @override
  String get dayPartDawn => 'ভোর';

  @override
  String get dayPartMorning => 'সকাল';

  @override
  String get dayPartNoon => 'দুপুর';

  @override
  String get dayPartAfternoon => 'বিকাল';

  @override
  String get dayPartEvening => 'সন্ধ্যা';

  @override
  String get monthJanuary => 'জানুয়ারি';

  @override
  String get monthFebruary => 'ফেব্রুয়ারি';

  @override
  String get monthMarch => 'মার্চ';

  @override
  String get monthApril => 'এপ্রিল';

  @override
  String get monthMay => 'মে';

  @override
  String get monthJune => 'জুন';

  @override
  String get monthJuly => 'জুলাই';

  @override
  String get monthAugust => 'আগস্ট';

  @override
  String get monthSeptember => 'সেপ্টেম্বর';

  @override
  String get monthOctober => 'অক্টোবর';

  @override
  String get monthNovember => 'নভেম্বর';

  @override
  String get monthDecember => 'ডিসেম্বর';

  @override
  String get reportNoRows => 'কোনো সারি নেই';

  @override
  String reportRangeOf(String total, String first, String last) {
    return '$total টির মধ্যে $first–$last';
  }

  @override
  String get salesOrderUnknownCustomer => 'অজানা গ্রাহক';

  @override
  String salesOrderStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'draft': 'খসড়া',
        'confirmed': 'নিশ্চিত',
        'cancelled': 'বাতিল',
        'closed': 'বন্ধ',
        'other': '$status',
      },
    );
    return '$_temp0';
  }

  @override
  String get todayTotal => 'মোট';

  @override
  String get todayBilledToday => 'আজ বিল হয়েছে';

  @override
  String todaySalesBilledReturned(String billed, String returned) {
    return 'বিল $billed · ফেরত $returned';
  }

  @override
  String todaySalesCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি বিক্রয়',
    );
    return '$_temp0';
  }

  @override
  String todayBalanceToPay(String amount) {
    return 'দিতে হবে: $amount';
  }

  @override
  String todayBalanceOwedToUs(String amount) {
    return 'কোম্পানির কাছে পাব: $amount';
  }

  @override
  String get syncModuleSales => 'বিক্রয়';

  @override
  String get syncModulePurchase => 'ক্রয়';

  @override
  String get syncModuleInventory => 'মজুদ';

  @override
  String get syncModuleAccounts => 'হিসাব';

  @override
  String get syncModuleFinance => 'অর্থ';

  @override
  String get syncModuleCustomer => 'গ্রাহক';

  @override
  String get syncModuleSupplier => 'সরবরাহকারী';

  @override
  String get syncModuleHr => 'জনবল';

  @override
  String get syncEntityCustomer => 'গ্রাহক';

  @override
  String get syncEntitySupplier => 'সরবরাহকারী';

  @override
  String get syncEntityProduct => 'পণ্য';

  @override
  String get syncEntityProductPrice => 'পণ্যের দাম';

  @override
  String get syncEntitySalesOrder => 'অর্ডার';

  @override
  String get syncEntityCollection => 'আদায়';

  @override
  String get syncEntityCustomerDue => 'দোকানের বকেয়া';

  @override
  String get syncEntityStockOnHand => 'হাতে থাকা মজুদ';

  @override
  String get syncUnknownReason => 'অজানা কারণ';

  @override
  String syncNoAnswer(int attempts) {
    String _temp0 = intl.Intl.pluralLogic(
      attempts,
      locale: localeName,
      other: '$attempts বার',
    );
    return 'সার্ভার এই বদলের উত্তর দেয়নি ($_temp0) — তালিকা দেখে নিজে থেকে আবার লিখুন।';
  }

  @override
  String syncSendFailedRepeatedly(int attempts) {
    String _temp0 = intl.Intl.pluralLogic(
      attempts,
      locale: localeName,
      other: '$attempts বার',
    );
    return 'বারবার পাঠানো ব্যর্থ হয়েছে ($_temp0) — নিজে থেকে আবার লিখুন।';
  }

  @override
  String get syncNoReasonGiven => 'কারণ জানানো হয়নি।';

  @override
  String get syncOfflinePull => 'সংযোগ নেই — সংযোগ পেয়ে আবার নিচে টানুন।';

  @override
  String get syncOfflinePush => 'সংযোগ নেই — সংযোগ পেলেই নিজে থেকে চলে যাবে।';

  @override
  String syncServerRefuses(String code) {
    return 'সার্ভার অনুরোধটাই নিচ্ছে না (কোড $code)';
  }

  @override
  String syncServerSays(String message, String code) {
    return 'সার্ভার বলছে: $message (কোড $code)';
  }

  @override
  String syncRefusalTellOfficePull(String what) {
    return '$what — অফিসে জানান, বারবার টেনে ঠিক হবে না।';
  }

  @override
  String syncRefusalTellOfficePush(String what) {
    return '$what — অফিসে জানান, অপেক্ষা করে ঠিক হবে না।';
  }

  @override
  String get syncPullUnknown =>
      'তালিকা আনা যায়নি — কারণ জানা যায়নি। অফিসে জানান।';

  @override
  String get syncPushUnknown =>
      'পাঠানো যায়নি — কারণ জানা যায়নি। অফিসে জানান।';

  @override
  String get syncStatusRunning => 'সিঙ্ক হচ্ছে…';

  @override
  String get syncStatusOffline => 'নেট নেই — কাজ জমা থাকছে';

  @override
  String get syncStatusFailedNever => 'সিঙ্ক হয়নি — টেনে আবার চেষ্টা করুন';

  @override
  String get syncStatusNever => 'এখনো সিঙ্ক হয়নি';

  @override
  String syncStatusLast(String ago) {
    return 'শেষ সিঙ্ক: $ago';
  }

  @override
  String syncStatusLastFailed(String ago) {
    return 'শেষ সিঙ্ক: $ago · শেষবার ব্যর্থ';
  }

  @override
  String get syncAgoJustNow => 'এইমাত্র';

  @override
  String syncAgoMinutes(int count, String digits) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$digits মিনিট আগে',
    );
    return '$_temp0';
  }

  @override
  String syncAgoHours(int count, String digits) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$digits ঘণ্টা আগে',
    );
    return '$_temp0';
  }

  @override
  String syncAgoDays(int count, String digits) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$digits দিন আগে',
    );
    return '$_temp0';
  }

  @override
  String get overviewConfirm => 'নিশ্চিত করুন';

  @override
  String get overviewKeepDraft => 'খসড়া রাখুন';

  @override
  String get sheetGoBack => 'ফিরে যান';

  @override
  String get stateLoading => 'লোড হচ্ছে';

  @override
  String totalsThisPage(String count) {
    return 'এই পাতা · $countটি';
  }

  @override
  String totalsAll(String count) {
    return 'সর্বমোট · $countটি';
  }

  @override
  String get unsavedTitle => 'লেখা ফেলে চলে যাবেন?';

  @override
  String get unsavedBody =>
      'যা লিখেছেন তা এখনো পাঠানো হয়নি — চলে গেলে হারিয়ে যাবে।';

  @override
  String get unsavedStay => 'থাকুন';

  @override
  String get unsavedLeave => 'ফেলে যান';

  @override
  String workspacePendingOrders(int count) {
    return 'এই ফোনে $count টি অর্ডার এখনো পাঠানো হয়নি। আগে ওগুলো পাঠান (সিঙ্কের অবস্থা দেখুন), তারপর কোম্পানি বা শাখা বদলান — নইলে ওগুলো নতুন জায়গার নামে বসবে।';
  }

  @override
  String get workspaceServerTooOld =>
      'সার্ভার এখনো অ্যাপ থেকে কোম্পানি বদলানো চেনে না। সার্ভার হালনাগাদ হলে পারবেন; ততক্ষণ ওয়েব থেকে বদলান।';

  @override
  String get workspaceNoCompanyAccess =>
      'এই কোম্পানিতে ঢোকার অনুমতি আপনার নেই।';

  @override
  String get workspaceBranchOutOfReach =>
      'এই শাখায় যাওয়া গেল না — শাখাটা আপনার নাগালে নেই, বা এই কোম্পানির নয়।';

  @override
  String get workspaceSwitchFailed =>
      'বদলানো গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।';

  @override
  String get homeOrderStatus => 'অর্ডারের অবস্থা';

  @override
  String get homeDueList => 'বকেয়া তালিকা';

  @override
  String get homeApprovals => 'অনুমোদন';

  @override
  String get homePrincipalCommission => 'প্রিন্সিপালের কমিশন';

  @override
  String get homeDashboards => 'ব্যবসার ড্যাশবোর্ড';

  @override
  String get homeDashboardsHint => 'মজুদ, বিক্রি, হিসাব — ওয়েবের একই সংখ্যা';

  @override
  String get homeFavourites => 'প্রিয়';

  @override
  String get homeFavouritesEmpty =>
      'কোনো মডিউলের মেনুতে একটা সারি চেপে ধরে রাখুন — এখানে এসে বসবে (ছয়টা পর্যন্ত)।';

  @override
  String get homeRecent => 'সাম্প্রতিক';

  @override
  String get homeToday => 'আজ';

  @override
  String get homeUnsafePhoneTitle => 'ফোনটা নিরাপদ নয়';

  @override
  String get homeUnderstood => 'বুঝেছি';

  @override
  String get homeAllBranches => 'সব শাখা';

  @override
  String homeFavouriteAdded(String name) {
    return '\"$name\" প্রিয়-তে যোগ হলো';
  }

  @override
  String homeFavouriteRemoved(String name) {
    return '\"$name\" প্রিয় থেকে সরানো হলো';
  }

  @override
  String get homeFavouritesFull =>
      'প্রিয়-তে ছয়টার বেশি রাখা যায় না — আগে একটা সরান (হোমে চেপে ধরে)।';

  @override
  String homeQueuedCount(String count) {
    return '$count অপেক্ষমাণ';
  }

  @override
  String get homeAllModules => 'সব মডিউল';

  @override
  String get homeMe => 'আমি';

  @override
  String homeModuleComingSoon(String module) {
    return '$module-এর পর্দাগুলো এখনো ফোনে আসেনি — নিচের সারিগুলো শীঘ্রই আসছে।';
  }

  @override
  String get homeSyncStatus => 'সিঙ্কের অবস্থা';

  @override
  String get homeSyncStatusHint => 'কী গেছে, কী যায়নি, কী ফেরত এসেছে';

  @override
  String get homePrinter => 'প্রিন্টার';

  @override
  String get homePrinterHint =>
      'ব্লুটুথ রসিদ প্রিন্টার, রোলের মাপ, পরীক্ষামূলক ছাপা';

  @override
  String get homeSignOut => 'বেরিয়ে যান';

  @override
  String get homeSignOutTitle => 'বেরিয়ে যাবেন?';

  @override
  String homeSignOutPending(int count) {
    return 'এই ফোনে আপনার $count টি অর্ডার এখনো পাঠানো হয়নি। বেরিয়ে গেলেও ওগুলো ফোনেই থাকবে, আর আপনি আবার ঢুকলে তবেই যাবে — অন্য কেউ ঢুকলে তাঁর নামে যাবে না।';
  }

  @override
  String get homeSignOutPassword => 'আবার ঢুকতে পাসওয়ার্ড লাগবে।';

  @override
  String get homeStay => 'থাক';

  @override
  String get homeLockDelay => 'অ্যাপ-তালার সময়';

  @override
  String homeLockDelayHint(String delay) {
    return 'এতক্ষণ বাইরে থাকলে ফিরতে আঙুলের ছাপ বা PIN লাগবে: $delay';
  }

  @override
  String get homeNoMenu => 'আপনার জন্য কোনো মেনু নেই';

  @override
  String get homeNoMenuHint =>
      'অফিসে জানান — আপনার অ্যাকাউন্টে কোনো অনুমতি বসানো নেই।';

  @override
  String get homeComingSoon => 'শীঘ্রই আসছে';

  @override
  String get homeWebScreen => 'ওয়েবের পর্দা';

  @override
  String get homeSearchHint => 'খুঁজুন: \"চালান\", \"রহিম ট্রেডার্স\"…';

  @override
  String get homeSearchClear => 'মুছুন';

  @override
  String get homeSearchHelp =>
      'পর্দার নাম (\"অর্ডার\", \"চালান\", \"stock\") বা গ্রাহকের নাম/মোবাইল লিখুন।';

  @override
  String homeSearchNothing(String query) {
    return '\"$query\" — কিছু পাওয়া গেল না।';
  }

  @override
  String get homeSearchScreens => 'পর্দা';

  @override
  String get homeSearchCustomers => 'গ্রাহক';

  @override
  String get homeNotices => 'নোটিশ';

  @override
  String get homePaper => 'কাগজ';

  @override
  String get homePaperCannotOpen => 'এই কাগজটা এই অ্যাপে খোলা যায় না';

  @override
  String get homeUpdateAndRetry => 'অ্যাপটা হালনাগাদ করে আবার চেষ্টা করুন।';

  @override
  String get workspacePickerTitle => 'কোম্পানি ও শাখা';

  @override
  String get workspacePickerClose => 'বন্ধ করুন';

  @override
  String get workspacePickerCompany => 'কোম্পানি';

  @override
  String get workspacePickerBranch => 'শাখা';

  @override
  String workspacePickerBranchOf(String company) {
    return 'শাখা — $company';
  }

  @override
  String get workspacePickerNote =>
      'আপনি যা লিখবেন তা বাছা শাখার নামেই বসবে। \"সব শাখা\" কেবল দেখার জন্য — কাজের শাখা বদলায় না।';

  @override
  String get homeNoBranch => 'শাখা নেই';

  @override
  String get homeNotKnown => 'জানা নেই';

  @override
  String get homeMfsAccountsHint => 'MFS খাতগুলো দেখুন';

  @override
  String get homeCashInHand => 'হাতে নগদ';

  @override
  String get homeInBank => 'ব্যাংকে';

  @override
  String get homeInTransit => 'পথে';

  @override
  String get homeCameInToday => 'আজ এসেছে';

  @override
  String homeCollectionAmount(String amount) {
    return 'আদায় $amount';
  }

  @override
  String get homeWentOutToday => 'আজ গেছে';

  @override
  String get homeMarketDues => 'বাজারে বকেয়া';

  @override
  String homeShopCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি দোকান',
    );
    return '$_temp0';
  }

  @override
  String get homeCashAndBankTotal => 'হাতে ও ব্যাংকে মোট';

  @override
  String get homeMfsAccounts => 'MFS খাত';

  @override
  String get homeAwaitingSignature => 'সইয়ের অপেক্ষায়';

  @override
  String homeAwaitingSignatureCount(String count) {
    return 'সইয়ের অপেক্ষায় · $countটি';
  }

  @override
  String get homeSeeAll => 'সব দেখুন';

  @override
  String get homeWaitingLoadFailed =>
      'সইয়ের কাগজ আনা গেল না। নেট দেখে আবার চেষ্টা করুন।';

  @override
  String get homeNothingWaiting => 'এখন কিছু সইয়ের অপেক্ষায় নেই।';

  @override
  String get homeDetails => 'বিস্তারিত';

  @override
  String homeHandLoansIncluded(String amount) {
    return 'হাতধার $amount সহ';
  }

  @override
  String get homePayable => 'দেনা';

  @override
  String homeLossAmount(String amount) {
    return 'লোকসান $amount';
  }

  @override
  String homeCommissionAmount(String amount) {
    return 'কমিশন $amount';
  }

  @override
  String homePrincipalDetail(String inflow, String commission) {
    return 'এসেছে $inflow · $commission';
  }

  @override
  String get reportLoadFailed => 'রিপোর্ট আনা গেল না।';

  @override
  String get reportTitle => 'রিপোর্ট';

  @override
  String get reportLoadFailedTitle => 'রিপোর্ট আনা গেল না';

  @override
  String get reportNotOpenForYou => 'এই রিপোর্ট আপনার জন্য খোলা নেই';

  @override
  String get reportNotOpenHint =>
      'রিপোর্টের চাবি নেই, অথবা এই মডিউল ফোনে বন্ধ। দরকার হলে অফিসে চাবি চেয়ে নিন।';

  @override
  String get reportListLoadFailed => 'রিপোর্টের তালিকা আনা গেল না।';

  @override
  String get reportServerTooOld =>
      'রিপোর্ট এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।';

  @override
  String get reportSearchHint => 'রিপোর্টের নাম দিয়ে খুঁজুন';

  @override
  String get reportListFailedTitle => 'তালিকা আনা গেল না';

  @override
  String get reportNoneForYou => 'আপনার জন্য কোনো রিপোর্ট নেই';

  @override
  String get reportNoMatch => 'কোনো মিল পাওয়া যায়নি';

  @override
  String get reportDateRangeHelp => 'কোন তারিখ থেকে কোন তারিখ';

  @override
  String get reportDateRangeShow => 'দেখান';

  @override
  String get reportPdfTooltip => 'PDF — দেখা, ছাপা, পাঠানো';

  @override
  String get reportChoiceAll => 'সব';

  @override
  String reportChoice(String label, String value) {
    return '$label: $value';
  }

  @override
  String get reportNoRowsInPeriod => 'এই সময়ে কোনো সারি নেই';

  @override
  String reportChoiceSearch(String what) {
    return '$what খুঁজুন';
  }

  @override
  String get reportTotal => 'মোট';

  @override
  String get reportPrevious => 'আগের';

  @override
  String get reportNext => 'পরের';

  @override
  String reportPageOf(String page, String last) {
    return 'পাতা $page / $last';
  }

  @override
  String get syncScreenPending => 'অপেক্ষমাণ';

  @override
  String get syncScreenAllSent => 'সব পাঠানো হয়ে গেছে';

  @override
  String get syncScreenWaitingConnection => 'সংযোগের অপেক্ষায় — এই ফোনেই আছে';

  @override
  String get syncScreenRejectedHeader => 'যা যায়নি — কারণসহ';

  @override
  String get syncScreenNothingRejected => 'প্রত্যাখ্যাত কিছু নেই';

  @override
  String get syncScreenLastSync => 'শেষ সিঙ্ক';

  @override
  String get syncScreenNoInfo => 'কোনো তথ্য পাওয়া যায়নি';

  @override
  String get syncScreenNever => 'কখনো না';

  @override
  String get syncScreenConflicts => 'দ্বন্দ্ব';

  @override
  String get syncScreenNoConflicts => 'কোনো দ্বন্দ্ব নেই';

  @override
  String get syncScreenNeedsResolution => 'সমাধান বাকি';

  @override
  String get syncQueueBusy =>
      'পেছনের সিঙ্ক এখন সারিটা পাঠাচ্ছে — একটু পরে এখানেও খুলবে। কিছুই মোছা হয়নি।';

  @override
  String get syncQueueKeyUnavailable =>
      'ফোনের নিরাপদ ভাণ্ডার এখন খোলেনি — অপেক্ষমাণ অর্ডার ফোনেই রাখা আছে। অ্যাপ বন্ধ করে আবার খুলুন।';

  @override
  String get syncQueueQuarantined =>
      'অপেক্ষমাণ সারির ফাইল খোলেনি — ফাইলটা আলাদা করে রাখা আছে, মোছা হয়নি। নতুন অর্ডার যাচ্ছে; আগেরগুলোর জন্য অফিসকে জানান।';

  @override
  String get syncQueueBroken =>
      'অপেক্ষমাণ সারির ফাইল খোলেনি — ফাইল মোছা হয়নি, সিঙ্ক বন্ধ। অফিসকে জানান।';

  @override
  String get todayLoadFailed => 'আজকের হিসাব আনা গেল না।';

  @override
  String get todayServerTooOld =>
      'আজকের হিসাব এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।';

  @override
  String get todayPullFailed =>
      'সিঙ্ক হয়নি — সংযোগ দেখে আবার টানুন। ফোনের কাজ ফোনেই আছে।';

  @override
  String todayStaleNotice(String error) {
    return '$error নিচের সংখ্যাগুলো এই ফোনে রাখা ছিল।';
  }

  @override
  String get todayInflowCollections => 'আজকের ইনফ্লো/আদায়';

  @override
  String get todayCollections => 'আজকের আদায়';

  @override
  String todayCollectionCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি আদায়',
    );
    return '$_temp0';
  }

  @override
  String get todayRecoverable => 'পাওনা (Recoverable)';

  @override
  String get todayPayable => 'দেনা (Payable)';

  @override
  String get todayFetching => 'আনা হচ্ছে…';

  @override
  String todayFiguresAsOf(String stamp) {
    return 'হিসাব $stamp পর্যন্ত';
  }

  @override
  String get todayCollectionsAndDues => 'আদায় ও বকেয়া';

  @override
  String get todayTotalDues => 'মোট বকেয়া';

  @override
  String get todayPartCash => 'নগদ';

  @override
  String get todayPartBank => 'ব্যাংক';

  @override
  String todayPeriod(String period) {
    return 'সময়কাল: $period';
  }

  @override
  String get todayTotalInflow => 'মোট ইনফ্লো';

  @override
  String todayCommissionRate(String rate) {
    return 'কমিশন ($rate)';
  }

  @override
  String get todaySentToPrincipal => 'প্রিন্সিপালকে পাঠানো';

  @override
  String get todayInflowLeft => 'বাকি ইনফ্লো';

  @override
  String todayDocumentsAwaitingDecision(int count) {
    return '$count টি নথি আপনার সিদ্ধান্তের অপেক্ষায়';
  }

  @override
  String get todayScreenTitle => 'আজকের হিসাব';

  @override
  String get updateTileTitle => 'অ্যাপ হালনাগাদ';

  @override
  String updateRunningVersion(String version) {
    return 'চলছে $version';
  }

  @override
  String get updateLatest => 'সর্বশেষ সংস্করণ চলছে।';

  @override
  String get updateOffline =>
      'সার্ভারে পৌঁছানো গেল না। ইন্টারনেট সংযোগ দেখে আবার চাপুন।';

  @override
  String get updateNewVersion => 'নতুন সংস্করণ এসেছে।';

  @override
  String updateNewVersionNamed(String version) {
    return 'নতুন সংস্করণ এসেছে ($version)।';
  }

  @override
  String get updateNow => 'এখনই আপডেট করুন';

  @override
  String get updateDownloadNew => 'নতুন সংস্করণ নামান';

  @override
  String get updateNotInstallable =>
      'এই আপডেট অ্যাপের ভেতর থেকে বসানো যাচ্ছে না। অফিসে জানান।';

  @override
  String get updateNeedsPermission =>
      'এই অ্যাপ থেকে আপডেট বসানোর অনুমতি দেওয়া নেই। সেটিংসে গিয়ে \"এই উৎস থেকে অনুমতি দিন\" চালু করুন, তারপর ফিরে এসে আবার চাপুন।';

  @override
  String get updateOpenSettings => 'সেটিংস খুলুন';

  @override
  String get updateChecking => 'দেখা হচ্ছে…';

  @override
  String get updateDownloading => 'নামানো হচ্ছে…';

  @override
  String updateDownloadingPercent(String percent) {
    return 'নামানো হচ্ছে… $percent%';
  }

  @override
  String get updateVerifying => 'ফাইলটা মিলিয়ে দেখা হচ্ছে…';

  @override
  String updateNotEnoughSpace(String megabytes) {
    return 'ফোনে যথেষ্ট জায়গা নেই। অন্তত ${megabytes}MB ফাঁকা করে আবার চেষ্টা করুন।';
  }

  @override
  String get updateIncomplete =>
      'নামানো ফাইলটা অসম্পূর্ণ, তাই বসানো হয়নি। আবার চেষ্টা করুন।';

  @override
  String get updateMismatch =>
      'নামানো ফাইলটা অফিসের দেওয়া ফাইলের সাথে মেলেনি, তাই বসানো হয়নি। ফাইলটা মুছে ফেলা হয়েছে। আবার চেষ্টা করুন; আবারও না মিললে অফিসে জানান।';

  @override
  String get updateForeignPackage =>
      'নামানো ফাইলটা ABOS-এর নিজের সই করা অ্যাপ নয়, তাই বসানো হয়নি। ফাইলটা মুছে ফেলা হয়েছে। অফিসে জানান — সার্ভারে ভুল ফাইল রাখা হয়েছে।';

  @override
  String get updateNotNewer =>
      'নামানো ফাইলটা এই ফোনের অ্যাপের চেয়ে নতুন নয়, তাই বসানো হয়নি — পুরনো সংস্করণে ফেরা যায় না। ফাইলটা মুছে ফেলা হয়েছে। অফিসে জানান।';

  @override
  String get updateDownloadOffline =>
      'নামানো গেল না। ইন্টারনেট সংযোগ দেখে আবার চেষ্টা করুন।';

  @override
  String updateInstallerRefused(String detail) {
    return 'ফাইল নামানো আর মেলানো হয়েছে, কিন্তু ফোন বসানোর পর্দা খুলতে দিল না। সেটিংসে এই অ্যাপের \"অজানা অ্যাপ বসানো\" অনুমতি চালু করে আবার চাপুন; তবুও না হলে এই লেখাটা অফিসে পাঠান: $detail';
  }

  @override
  String get updateFailed => 'আপডেট বসানো গেল না। আবার চেষ্টা করুন।';

  @override
  String get updateDismiss => 'সরিয়ে রাখুন';

  @override
  String get updateWallTitle => 'এই সংস্করণটি আর ব্যবহার করা যাবে না';

  @override
  String get updateWallBody =>
      'অ্যাপের নতুন সংস্করণ নামিয়ে বসান। পুরনো সংস্করণ ভুল তথ্য দেখাতে পারে।';

  @override
  String updateWallPending(int count) {
    return 'এই ফোনে $count টি অর্ডার এখনো পাঠানো হয়নি';
  }

  @override
  String get updateWallKeepOrders =>
      'অ্যাপ মুছে ফেললে ওগুলো চলে যাবে। নতুন সংস্করণ বসানোর সময় পুরনোটা মুছবেন না — উপরে বসিয়ে দিন, অর্ডারগুলো থেকে যাবে।';
}
