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
  String get commonNo => 'না';

  @override
  String get commonMore => 'আরও';

  @override
  String get commonAll => 'সব';

  @override
  String get commonStatus => 'অবস্থা';

  @override
  String get commonBranch => 'শাখা';

  @override
  String get commonLoadFailed => 'আনা গেল না';

  @override
  String get commonSign => 'সই দিন';

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
  String counterLotNo(String lot) {
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

  @override
  String get counterLoadFailed =>
      'কাউন্টার খোলা গেল না। নেট আছে কি না দেখে আবার চেষ্টা করুন।';

  @override
  String get counterAbsent =>
      'সার্ভারে ফোনের কাউন্টার এখনো আসেনি — অফিসে জানান।';

  @override
  String get counterThisUnit => 'এই একক';

  @override
  String counterNoFraction(String unit) {
    return '$unit ভগ্নাংশে বিক্রি হয় না — পূর্ণ সংখ্যা লিখুন।';
  }

  @override
  String counterQtyDecimals(String places) {
    return 'পরিমাণ $places দশমিকের বেশি নয় (যেমন 2.5 বা 0.125)।';
  }

  @override
  String get counterNoWarehouseLot =>
      'গুদাম পাওয়া যায়নি — গুদাম ছাড়া লট আসে না।';

  @override
  String get counterPickProduct => 'পণ্য বাছুন।';

  @override
  String get counterPickLot => 'লট বাছুন — এই পণ্য লট ধরে বিক্রি হয়।';

  @override
  String get counterNeedQty => 'পরিমাণ দিন।';

  @override
  String get counterNeedRate => 'দর ছাড়া বিক্রি হয় না।';

  @override
  String counterDiscountRange(String min, String max) {
    return 'ছাড় $min থেকে $max%।';
  }

  @override
  String counterFreeMax(String max) {
    return 'স্কিমে ফ্রি সর্বোচ্চ $max।';
  }

  @override
  String get counterNoWarehouseSale =>
      'গুদাম পাওয়া যায়নি — গুদাম ছাড়া বিক্রি হয় না।';

  @override
  String get counterPickBuyer => 'ক্রেতা বাছুন।';

  @override
  String get counterCartEmpty => 'কার্টে অন্তত একটা পণ্য দিন।';

  @override
  String get counterSold => 'বিক্রি হলো';

  @override
  String get counterDraftKept => 'খসড়া রাখা হলো';

  @override
  String get counterOpenBill => 'বিলটা দেখুন';

  @override
  String get counterSaleFailed => 'বিক্রি হলো না';

  @override
  String get counterSaleFailedRetry => 'বিক্রি করা গেল না। আবার চেষ্টা করুন।';

  @override
  String get counterOverviewFailed => 'সারাংশ আনা গেল না';

  @override
  String get counterCheckNet => 'নেট আছে কি না দেখে আবার চেষ্টা করুন।';

  @override
  String get counterMoneyTitle => 'টাকা নেওয়া';

  @override
  String counterMoneyReference(String ref) {
    return 'রেফারেন্স: $ref';
  }

  @override
  String get counterRemove => 'বাদ দিন';

  @override
  String get counterMoneyMethod => 'কীভাবে';

  @override
  String get counterMoneyAccount => 'কোন খাতে';

  @override
  String get counterMoneyAmount => 'টাকা';

  @override
  String get counterMoneyReferenceLabel => 'রেফারেন্স / লেনদেন নম্বর';

  @override
  String get counterMoneyAddAnother => 'আরেকটা টাকা যোগ করুন';

  @override
  String counterMoneyTotal(String amount) {
    return 'মোট নেওয়া: $amount';
  }

  @override
  String get counterUnreadableAmount =>
      'অঙ্কটা পড়া গেল না — কেবল সংখ্যা লিখুন, যেমন 1250।';

  @override
  String get counterVehicleTitle => 'গাড়ি ও ভাড়া';

  @override
  String get counterVehicleOwner => 'কার গাড়ি';

  @override
  String get counterVehicleOwn => 'আমাদের গাড়ি';

  @override
  String get counterVehicleHired => 'ভাড়ার গাড়ি';

  @override
  String get counterVehicleCustomer => 'ক্রেতার গাড়ি';

  @override
  String get counterVehicleNone => 'গাড়ি লাগবে না';

  @override
  String get counterCarrier => 'বাহক (পরিবহনকারী)';

  @override
  String get counterCarrierName => 'বাহকের নাম (তালিকায় না থাকলে)';

  @override
  String get counterFare => 'ভাড়া';

  @override
  String get counterFarePaidBy => 'ভাড়া কে দেবে';

  @override
  String get counterFareUs => 'আমরা — আমাদের খরচ';

  @override
  String get counterFareUsAddToBill => 'আমরা দেব, বিলে যোগ হবে';

  @override
  String get counterFareCustomer => 'ক্রেতা চালককে দেবেন';

  @override
  String get counterFareNone => 'ভাড়া নেই';

  @override
  String get counterFareNow => 'এখনই দিলাম';

  @override
  String get counterFareLater => 'পরে দেব';

  @override
  String get counterFareAccount => 'কোন খাত থেকে দিলেন';

  @override
  String get counterFareTrxId => 'লেনদেন নম্বর (TrxID)';

  @override
  String get counterFarePayer => 'কে দিলেন';

  @override
  String get counterFareCarrierLater => 'বাহক — যাঁকে পরে দেবেন';

  @override
  String get counterDeliveryTitle => 'মাল কীভাবে যাবে';

  @override
  String get counterDeliveryTakeNow => 'ক্রেতা এখনই নিয়ে যাবেন';

  @override
  String get counterDeliverySendLater => 'পরে পাঠানো হবে';

  @override
  String get counterDeliveryPickupLater => 'ক্রেতা পরে এসে নেবেন';

  @override
  String get counterShipTo => 'কোথায় পাঠানো হবে';

  @override
  String get counterShipDatePick => 'কবে পাঠানো হবে — তারিখ বাছুন';

  @override
  String counterShipDate(String date) {
    return 'পাঠানোর তারিখ: $date';
  }

  @override
  String get counterDraftOpenFailed => 'খসড়া খোলা গেল না';

  @override
  String get counterPriceCheckPick => 'দাম দেখুন — পণ্য বাছুন';

  @override
  String get counterPriceFailed => 'দাম আনা গেল না';

  @override
  String counterPriceRate(String rate, String unit) {
    return 'বিক্রয় দর: $rate / $unit';
  }

  @override
  String get counterPriceStockHidden => 'বিক্রয়যোগ্য মজুদ: দেখার অনুমতি নেই';

  @override
  String counterPriceStock(String qty, String unit) {
    return 'বিক্রয়যোগ্য মজুদ: $qty $unit';
  }

  @override
  String counterPriceLot(String no) {
    return 'লট $no';
  }

  @override
  String counterPriceExpiry(String date) {
    return 'মেয়াদ $date';
  }

  @override
  String counterPriceHave(String qty) {
    return 'আছে $qty';
  }

  @override
  String get counterBack => 'ফিরে যান';

  @override
  String get counterPriceToBill => 'বিলে তুলুন';

  @override
  String get counterReprint => 'আবার ছাপা';

  @override
  String get counterReprintNone =>
      'এই কাউন্টারে এখনো কোনো বিল হয়নি। পুরনো বিল ছাপতে বিলের তালিকা থেকে খুলুন।';

  @override
  String counterBillTitle(String no) {
    return 'বিল · $no';
  }

  @override
  String get counterVoidBill => 'বিল বাতিল';

  @override
  String get counterVoidNothing => 'বাতিল করার মতো কিছু নেই।';

  @override
  String get counterVoidReason => 'কেন বাতিল (বাধ্যতামূলক)';

  @override
  String get counterVoidConfirm => 'বাতিল করুন';

  @override
  String get counterVoided => 'বিল বাতিল হলো';

  @override
  String get counterVoidedNote => 'কারণসহ খাতায় লেখা থাকল।';

  @override
  String get counterVoidFailed => 'বাতিল হলো না';

  @override
  String get counterRepeatTitle => 'একই বিল আগেই হয়েছে';

  @override
  String get counterRepeatTick => 'জেনেশুনে আবার একই বিল করছি';

  @override
  String get counterRepeatGo => 'আবার করুন';

  @override
  String get counterTitle => 'সরাসরি বিক্রয়';

  @override
  String counterResumeBanner(String no) {
    return 'খসড়া $no খোলা আছে';
  }

  @override
  String get counterResumeNote =>
      'নিশ্চিত করলে এই খসড়াটাই পোস্ট হবে, নতুন বিল নয়।';

  @override
  String get counterPickBuyerTitle => 'ক্রেতা বাছুন';

  @override
  String get counterPriceCheck => 'দাম দেখুন';

  @override
  String get counterNoWarehouseBranch =>
      'এই শাখায় কোনো চালু গুদাম পাওয়া যায়নি — গুদাম ছাড়া লট আসে না, বিক্রিও হয় না। অফিসে জানান।';

  @override
  String get counterWarehouseNotSet =>
      'গুদাম ঠিক হয়নি — নিচে গুদাম বাছুন; গুদাম ছাড়া লট আসে না।';

  @override
  String get counterWarehouse => 'গুদাম';

  @override
  String get counterTerm => 'শর্ত';

  @override
  String get counterPickProductTitle => 'পণ্য বাছুন';

  @override
  String get counterLot => 'লট';

  @override
  String get counterQty => 'পরিমাণ';

  @override
  String get counterRate => 'দর';

  @override
  String get counterDiscountLabel => 'ছাড় % (দিলে মালিকের সই লাগবে)';

  @override
  String get counterFree => 'ফ্রি';

  @override
  String counterFreeMaxLabel(String max) {
    return 'ফ্রি (স্কিমে সর্বোচ্চ $max)';
  }

  @override
  String get counterAddToCart => 'কার্টে যোগ করুন';

  @override
  String get counterUpdate => 'হালনাগাদ করুন';

  @override
  String counterLineDiscount(String percent) {
    return 'ছাড়: $percent%';
  }

  @override
  String counterLineFree(String qty) {
    return 'ফ্রি: $qty';
  }

  @override
  String get counterEdit => 'সম্পাদনা';

  @override
  String counterRunningTotal(String amount) {
    return 'চলতি মোট: $amount';
  }

  @override
  String counterMoneyTaken(String amount) {
    return 'নেওয়া টাকা: $amount';
  }

  @override
  String counterDeliverySummary(String mode) {
    return 'ডেলিভারি: $mode';
  }

  @override
  String counterDeliverySummarySend(String to, String date) {
    return 'পরে পাঠানো — $to · $date';
  }

  @override
  String get counterDeliverySummaryPickup => 'ক্রেতা পরে নেবেন';

  @override
  String get counterDeliverySummaryNow => 'এখনই নিয়ে যাবেন';

  @override
  String counterFareSummary(String amount) {
    return 'ভাড়া: $amount';
  }

  @override
  String get counterDelivery => 'ডেলিভারি';

  @override
  String get counterReturnButton => 'ফেরত';

  @override
  String get counterKeepDraft => 'খসড়া রাখুন';

  @override
  String get counterOpenDraft => 'খসড়া খুলুন';

  @override
  String counterConfirmDraft(String no) {
    return 'নিশ্চিত করুন — খসড়া $no';
  }

  @override
  String get counterDraftsLoadFailed =>
      'খসড়া আনা গেল না। নেট আছে কি না দেখে আবার চেষ্টা করুন।';

  @override
  String counterDraftLines(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটি পণ্য',
    );
    return '$_temp0';
  }

  @override
  String get counterDraftsEmpty => 'রাখা কোনো খসড়া নেই';

  @override
  String get counterDraftsEmptyHint =>
      'কাউন্টারে \"খসড়া রাখুন\" চাপলে বিল এখানে আসে।';

  @override
  String get counterNewBill => 'নতুন বিল লিখুন';

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

  @override
  String get salesDeskComingSoonTitle => 'শীঘ্রই আসছে';

  @override
  String get salesDeskComingSoonMessage =>
      'এই পর্দাটা এখনো তৈরি হচ্ছে। তৈরি হলে এখানেই খুলবে।';

  @override
  String get deliveriesTodayLoadFailed =>
      'ডেলিভারির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get deliveriesTodayNotOnServer =>
      'সার্ভারে আজকের ডেলিভারি এখনো আসেনি — অফিসে জানান।';

  @override
  String get deliveriesTodayMoreFailed =>
      'পরের পাতা আনা গেল না। আবার চেষ্টা করুন।';

  @override
  String deliveriesTodayPartlyDelivered(String no) {
    return '$no: আংশিক পৌঁছেছে — কম আর ভাঙা মাল ফেরতের কাগজে উঠেছে।';
  }

  @override
  String deliveriesTodayDelivered(String no, String receiver) {
    return '$no: পৌঁছেছে — $receiver বুঝে নিলেন।';
  }

  @override
  String get deliveriesTodaySaveFailed =>
      'পৌঁছানো লেখা গেল না। আবার চেষ্টা করুন।';

  @override
  String get deliveriesTodayTitle => 'আজকের ডেলিভারি';

  @override
  String get deliveriesTodayTotalLabel => 'পথে মোট চালান';

  @override
  String deliveriesTodayTotalValue(String count) {
    return '$count টি';
  }

  @override
  String get deliveriesTodayEmpty => 'পথে কোনো চালান নেই।';

  @override
  String deliveriesTodayVehicle(String vehicle) {
    return 'গাড়ি $vehicle';
  }

  @override
  String deliveriesTodayDriver(String driver) {
    return 'চালক $driver';
  }

  @override
  String get deliveriesTodayHandOver => 'বুঝিয়ে দিন';

  @override
  String get deliveriesTodayMore => 'আরও দেখুন';

  @override
  String routeMyWeekday(String day) {
    String _temp0 = intl.Intl.selectLogic(
      day,
      {
        'mon': 'সোমবার',
        'tue': 'মঙ্গলবার',
        'wed': 'বুধবার',
        'thu': 'বৃহস্পতিবার',
        'fri': 'শুক্রবার',
        'sat': 'শনিবার',
        'other': 'রবিবার',
      },
    );
    return '$_temp0';
  }

  @override
  String get routeMyLoadFailed => 'রুট আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get routeMyNotOnServer =>
      'সার্ভারে রুটের দরজা এখনো আসেনি — অফিসে জানান।';

  @override
  String get routeMyPickDay => 'কোন দিনের রুট';

  @override
  String get routeMyTitle => 'আজকের রুট';

  @override
  String get routeMyErrorTitle => 'আনা গেল না';

  @override
  String get routeMyNoRouteTitle => 'এই দিনে কোনো রুট নেই';

  @override
  String get routeMyNoRouteMessage =>
      'সাপ্তাহিক ছকে এই দিনে আপনার নামে রুট বসানো নেই — অফিসে জানান।';

  @override
  String routeMyMonthSales(String amount) {
    return 'এ মাসে বিক্রি: $amount';
  }

  @override
  String routeMyMonthCollections(String amount) {
    return 'এ মাসে আদায়: $amount';
  }

  @override
  String routeMyOutstanding(String amount) {
    return 'বকেয়া: $amount';
  }

  @override
  String get routeMyMoreShops => 'আরও দোকান';

  @override
  String get salesDashboardsListLoadFailed => 'ড্যাশবোর্ডের তালিকা আনা গেল না।';

  @override
  String get salesDashboardsTitle => 'ব্যবসার ড্যাশবোর্ড';

  @override
  String get salesDashboardsModule => 'ড্যাশবোর্ড';

  @override
  String get salesDashboardsNoneTitle => 'আপনার জন্য কোনো ড্যাশবোর্ড নেই';

  @override
  String get salesDashboardsNoneMessage =>
      'কোনো মডিউলের ড্যাশবোর্ড দেখার অনুমতি পেলে এখানে আসবে।';

  @override
  String get salesDashboardsNoKey => 'চাবি নেই';

  @override
  String get salesDashboardsLoadFailed => 'ড্যাশবোর্ড আনা গেল না।';

  @override
  String get salesDashboardsEmptyTitle => 'এই ড্যাশবোর্ডে এখনো কিছু নেই';

  @override
  String get salesDashboardsEmptyMessage =>
      'কাজ শুরু হলে বা অনুমতি পেলে সংখ্যাগুলো এখানে আসবে।';

  @override
  String get salesDashboardsReports => 'এই মডিউলের রিপোর্ট';

  @override
  String salesDashboardsReportsTitle(String name) {
    return '$name — রিপোর্ট';
  }

  @override
  String get salesDashboardsOverviewLoadFailed =>
      'বিক্রয়ের হালচাল আনা গেল না।';

  @override
  String get salesDashboardsOverviewTitle => 'বিক্রয়ের হালচাল';

  @override
  String get salesDashboardsSalesModule => 'বিক্রয়';

  @override
  String get salesDashboardsOverviewNoAccess =>
      'এই পর্দার কোনো সংখ্যা দেখার অনুমতি আপনার নেই।';

  @override
  String get salesDashboardsNoKeyLong => 'চাবি নেই — দেখার অনুমতি লাগে';

  @override
  String get salesDashboardsRefresh => 'নতুন করে দেখুন';

  @override
  String get salesDashboardsGrandTotal => 'সর্বমোট';

  @override
  String get quotationsCompareListLoadFailed => 'উদ্ধৃতির তালিকা আনা গেল না।';

  @override
  String get quotationsCompareLoadFailed => 'তুলনা আনা গেল না।';

  @override
  String get quotationsCompareNotOnServer =>
      'তুলনা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get quotationsCompareTitle => 'উদ্ধৃতির তুলনা';

  @override
  String get quotationsCompareRepick => 'আবার বাছুন';

  @override
  String get quotationsCompareNeedTwoTitle => 'তুলনার জন্য অন্তত দুটো লাগে';

  @override
  String get quotationsCompareNeedTwoMessage => 'আরেকটা উদ্ধৃতি বাছুন।';

  @override
  String get quotationsCompareNoChoices => 'বাছার মতো কোনো উদ্ধৃতি নেই';

  @override
  String quotationsComparePickHint(String max) {
    return 'দুই থেকে $maxটা উদ্ধৃতি বাছুন — প্রথমটা ভিত্তি';
  }

  @override
  String quotationsCompareGo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা তুলনা করুন',
    );
    return '$_temp0';
  }

  @override
  String quotationsCompareRevision(String revision) {
    return ' (সং $revision)';
  }

  @override
  String get quotationsRevisionsLoadFailed => 'সংস্করণের তালিকা আনা গেল না।';

  @override
  String get quotationsRevisionsNotOnServer =>
      'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get quotationsRevisionsTitle => 'উদ্ধৃতির সংস্করণ';

  @override
  String get quotationsRevisionsSearch => 'নম্বর বা দোকানের নাম';

  @override
  String get quotationsRevisionsEmptyTitle => 'কোনো উদ্ধৃতির সংস্করণ নেই';

  @override
  String get quotationsRevisionsEmptyMessage => 'নিচে টেনে আবার দেখুন।';

  @override
  String quotationsRevisionsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা সংস্করণ',
    );
    return '$_temp0';
  }

  @override
  String get quotationsRevisionsMore => 'আরও';

  @override
  String get salesDeskNothing => 'কিছু নেই';

  @override
  String get salesDeskLoadFailed => 'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get salesDeskNotOnServer =>
      'সার্ভারে ফোনের এই পর্দা এখনো আসেনি — অফিসে জানান।';

  @override
  String get salesDeskErrorTitle => 'আনা গেল না';

  @override
  String get salesDeskDepotTitle => 'ডিপোর যাচাই';

  @override
  String get salesDeskDepotEmpty => 'যাচাইয়ের অপেক্ষায় কিছু নেই';

  @override
  String get salesDeskDepotSearch => 'নম্বর, দোকানের নাম বা কোড';

  @override
  String get salesDeskDepotTotal => 'যাচাইয়ের অপেক্ষায় DO';

  @override
  String get salesDeskDepotWebNote =>
      'ⓘ যাচাই করে বিক্রয়ে খোলা এখনো ওয়েবের কাউন্টারে।';

  @override
  String salesDeskDepotLine(String date, int lines, String status) {
    String _temp0 = intl.Intl.pluralLogic(
      lines,
      locale: localeName,
      other: '$linesটি পণ্য',
    );
    return '$date · $_temp0 · $status';
  }

  @override
  String get salesDeskDepotHeld => 'ধরে রাখা বিক্রয় আদেশ';

  @override
  String get salesDeskPrintTitle => 'না ছাপা কাগজ';

  @override
  String get salesDeskPrintEmpty => 'সব কাগজ ছাপা হয়েছে';

  @override
  String salesDeskTotalCount(String count) {
    return 'মোট $count টি';
  }

  @override
  String salesDeskPrintPaper(String paper) {
    return 'কাগজ: $paper';
  }

  @override
  String salesDeskPrintReason(String reason) {
    return 'কারণ: $reason';
  }

  @override
  String get salesDeskPrintSettled => 'ছাপা হয়েছে বলে সরানো হলো।';

  @override
  String get salesDeskPrintSettleFailed => 'সরানো গেল না।';

  @override
  String get salesDeskPrintSettle => 'হাতে ছাপা হলো';

  @override
  String get salesDeskDeliveryTitle => 'ডেলিভারির কাজ';

  @override
  String salesDeskDeliveryLate(String count) {
    return 'দেরির চালান · $count টি';
  }

  @override
  String salesDeskDeliveryDaysLate(String days) {
    return '$days দিন দেরি';
  }

  @override
  String salesDeskDeliveryPromised(String promised, String arrived) {
    return 'কথা ছিল $promised · পৌঁছেছে $arrived';
  }

  @override
  String salesDeskDeliveryOrder(String no) {
    return 'আদেশ $no';
  }

  @override
  String get salesDeskPipelineTitle => 'পাইপলাইন';

  @override
  String get salesDeskPipelineEmpty => 'কোনো সম্ভাবনা নেই';

  @override
  String get salesDeskPipelineOpen => 'চলমান সম্ভাবনা (ওজন-করা)';

  @override
  String salesDeskPipelineStage(String stage, String count) {
    return '$stage · $count টি';
  }

  @override
  String salesDeskPipelineTotals(String total, String weighted) {
    return 'মোট $total · ওজন-করা $weighted';
  }

  @override
  String salesDeskPipelineCloses(String date) {
    return 'শেষ $date';
  }

  @override
  String get salesDeskTemplatesTitle => 'দলিলের ছাঁচ';

  @override
  String get salesDeskTemplatesEmpty => 'কোনো ছাঁচ নেই';

  @override
  String salesDeskTemplatesCount(String count) {
    return 'মোট $count টি ছাঁচ · লেখা ও বদল ওয়েবে';
  }

  @override
  String get salesDeskTemplatesInactive => 'বন্ধ';

  @override
  String salesPapersKindTitle(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'বিক্রয় বিল',
        'other': 'চালান',
      },
    );
    return '$_temp0';
  }

  @override
  String salesPapersListLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'বিক্রয় বিলের তালিকা আনা গেল না।',
        'other': 'চালানের তালিকা আনা গেল না।',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersNotOnServer =>
      'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get salesPapersSearch => 'নম্বর বা দোকানের নাম';

  @override
  String salesPapersTotal(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'মোট বিক্রয় বিল',
        'other': 'মোট চালান',
      },
    );
    return '$_temp0';
  }

  @override
  String salesPapersEmptyTitle(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'এই সময়ে কোনো বিক্রয় বিল নেই',
        'other': 'এই সময়ে কোনো চালান নেই',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersEmptyMessage => 'তারিখ বদলে বা নিচে টেনে আবার দেখুন।';

  @override
  String salesPapersDueAmount(String amount) {
    return 'বাকি $amount';
  }

  @override
  String get salesPapersPaid => 'শোধ';

  @override
  String get salesPapersMore => 'আরও';

  @override
  String salesPapersLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'বিক্রয় বিলটা আনা গেল না।',
        'other': 'চালানটা আনা গেল না।',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersPrint => 'ছাপা / পাঠান';

  @override
  String get salesPapersDate => 'তারিখ';

  @override
  String get salesPapersSaleNo => 'বিক্রির নম্বর';

  @override
  String get salesPapersDueOn => 'মেয়াদ';

  @override
  String get salesPapersWarehouse => 'গুদাম';

  @override
  String get salesPapersVehicle => 'গাড়ি';

  @override
  String get salesPapersCreatedBy => 'লিখেছেন';

  @override
  String get salesPapersProducts => 'পণ্য';

  @override
  String salesPapersFreeQty(String qty) {
    return 'ফ্রি $qty';
  }

  @override
  String salesPapersLineDiscount(String amount) {
    return 'ছাড় $amount';
  }

  @override
  String get salesPapersDiscount => 'ছাড়';

  @override
  String get salesPapersTax => 'কর';

  @override
  String get salesPapersGrandTotal => 'মোট';

  @override
  String get salesPapersCollected => 'আদায়';

  @override
  String get salesPapersDue => 'বাকি';

  @override
  String get salesPapersInvoiceChallans => 'এই বিলের চালান';

  @override
  String get salesPapersChallanInvoices => 'এই চালানের বিল';

  @override
  String salesPapersStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'draft': 'খসড়া',
        'confirmed': 'নিশ্চিত',
        'cancelled': 'বাতিল',
        'closed': 'সম্পন্ন',
        'awaiting_approval': 'অনুমোদনের অপেক্ষায়',
        'other': 'অজানা',
      },
    );
    return '$_temp0';
  }

  @override
  String quotationsListTab(String tab) {
    String _temp0 = intl.Intl.selectLogic(
      tab,
      {
        'all': 'সব',
        'draft': 'খসড়া',
        'approved': 'অনুমোদিত',
        'sent': 'পাঠানো',
        'accepted': 'গৃহীত',
        'other': 'আদেশ হয়েছে',
      },
    );
    return '$_temp0';
  }

  @override
  String get quotationsListLoadFailed =>
      'উদ্ধৃতি আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get quotationsListNotOnServer =>
      'সার্ভারে উদ্ধৃতির দরজা এখনো আসেনি — অফিসে জানান।';

  @override
  String get quotationsListTitle => 'উদ্ধৃতি';

  @override
  String get quotationsListNew => 'নতুন উদ্ধৃতি';

  @override
  String get quotationsListTotal => 'মোট উদ্ধৃতি';

  @override
  String get quotationsListErrorTitle => 'আনা গেল না';

  @override
  String get quotationsListEmpty => 'কোনো উদ্ধৃতি নেই';

  @override
  String get quotationsListMore => 'আরও উদ্ধৃতি';

  @override
  String get quotationsNewPickProduct => 'পণ্য বাছুন';

  @override
  String get quotationsNewNeedShop => 'দোকান বাছুন।';

  @override
  String get quotationsNewNeedLine => 'অন্তত একটা পণ্য দিন।';

  @override
  String get quotationsNewSaveFailed =>
      'উদ্ধৃতি রাখা গেল না। আবার চেষ্টা করুন।';

  @override
  String get quotationsNewPickShop => 'দোকান বাছুন';

  @override
  String quotationsQty(String qty) {
    return 'পরিমাণ: $qty';
  }

  @override
  String quotationsRate(String rate) {
    return 'দর: $rate';
  }

  @override
  String get quotationsNewProductPrice => 'পণ্যের দাম';

  @override
  String get quotationsNewRemoveLine => 'বাদ দিন';

  @override
  String get quotationsNewAddLine => 'পণ্য যোগ করুন';

  @override
  String get quotationsNewNote => 'মন্তব্য (ঐচ্ছিক)';

  @override
  String get quotationsSubmit => 'জমা দিন';

  @override
  String get quotationsNewKeepDraft => 'খসড়া রাখুন';

  @override
  String get quotationsActFailed => 'কাজটা হলো না। আবার চেষ্টা করুন।';

  @override
  String quotationsStatus(String status) {
    return 'অবস্থা: $status';
  }

  @override
  String quotationsShop(String shop) {
    return 'দোকান: $shop';
  }

  @override
  String quotationsDate(String date) {
    return 'তারিখ: $date';
  }

  @override
  String quotationsValidUntil(String date) {
    return 'মেয়াদ: $date';
  }

  @override
  String quotationsTotal(String amount) {
    return 'মোট: $amount';
  }

  @override
  String quotationsOrder(String no) {
    return 'আদেশ: $no';
  }

  @override
  String get quotationsSubmitted => 'জমা হলো।';

  @override
  String get quotationsSentDone => 'দোকানিকে পাঠানো হলো।';

  @override
  String get quotationsSend => 'দোকানিকে পাঠানো হলো';

  @override
  String get quotationsAcceptedDone => 'দোকানি রাজি — এখন আদেশ বানানো যায়।';

  @override
  String get quotationsAccept => 'দোকানি রাজি';

  @override
  String get quotationsRejectReason => 'রাজি না হওয়ার কারণ';

  @override
  String get quotationsRejectNeedReason => 'রাজি না হওয়ার কারণ লিখুন।';

  @override
  String get quotationsRejectedDone => 'দোকানি রাজি নন — লেখা হলো।';

  @override
  String get quotationsReject => 'দোকানি রাজি নন';

  @override
  String get quotationsConvertedDone => 'বিক্রয় আদেশ হলো।';

  @override
  String get quotationsConvert => 'আদেশ বানান';

  @override
  String get quotationsLineQty => 'পরিমাণ';

  @override
  String get quotationsLineRate => 'দর';

  @override
  String get quotationsLineCancel => 'বাদ';

  @override
  String get quotationsLineOk => 'ঠিক আছে';

  @override
  String deliveryOrdersPaper(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ',
        'other': 'DO',
      },
    );
    return '$_temp0';
  }

  @override
  String deliveryOrdersTitle(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'বিক্রয় আদেশ',
        'other': 'ডেলিভারি অর্ডার',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersListLoadFailed =>
      'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String deliveryOrdersNotOnServer(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'সার্ভারে বিক্রয় আদেশ এখনো আসেনি — অফিসে জানান।',
        'other': 'সার্ভারে ডেলিভারি অর্ডার এখনো আসেনি — অফিসে জানান।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersModule => 'বিক্রয়';

  @override
  String deliveryOrdersNew(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'নতুন আদেশ',
        'other': 'নতুন DO',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersFilterAll => 'সব';

  @override
  String get deliveryOrdersFilterMine => 'আমার সইয়ের অপেক্ষায়';

  @override
  String deliveryOrdersEmpty(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'কোনো আদেশ নেই।',
        'other': 'কোনো DO নেই।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersEmptyMine => 'এখন আপনার সইয়ের অপেক্ষায় কিছু নেই।';

  @override
  String get deliveryOrdersNoCustomer => 'গ্রাহক নেই';

  @override
  String get deliveryOrdersPickProduct => 'পণ্য বাছুন';

  @override
  String get deliveryOrdersNeedDealer => 'ডিলার বাছুন।';

  @override
  String get deliveryOrdersNeedLine => 'অন্তত একটা পণ্য দিন।';

  @override
  String deliveryOrdersSaveFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ রাখা গেল না। আবার চেষ্টা করুন।',
        'other': 'DO রাখা গেল না। আবার চেষ্টা করুন।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersPickDealer => 'ডিলার বাছুন';

  @override
  String deliveryOrdersQty(String qty) {
    return 'পরিমাণ: $qty';
  }

  @override
  String get deliveryOrdersRemoveLine => 'বাদ দিন';

  @override
  String get deliveryOrdersAddLine => 'পণ্য যোগ করুন';

  @override
  String get deliveryOrdersNote => 'মন্তব্য (ঐচ্ছিক)';

  @override
  String get deliveryOrdersSubmit => 'জমা দিন';

  @override
  String get deliveryOrdersKeepDraft => 'খসড়া রাখুন';

  @override
  String get deliveryOrdersActFailed => 'কাজটা হলো না। আবার চেষ্টা করুন।';

  @override
  String deliveryOrdersLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ আনা গেল না।',
        'other': 'DO আনা গেল না।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersPrint => 'ছাপুন / পাঠান';

  @override
  String deliveryOrdersPrintTitle(String no) {
    return 'বিক্রয় আদেশ · $no';
  }

  @override
  String deliveryOrdersStatus(String status) {
    return 'অবস্থা: $status';
  }

  @override
  String deliveryOrdersTotal(String amount) {
    return 'মোট: $amount';
  }

  @override
  String deliveryOrdersCreditShort(String amount) {
    return 'বাকির সীমায় আটকে — কম $amount';
  }

  @override
  String deliveryOrdersAsked(String qty) {
    return 'চাওয়া: $qty';
  }

  @override
  String get deliveryOrdersApprovedQtyField => 'অনুমোদিত পরিমাণ';

  @override
  String deliveryOrdersApprovedQty(String qty) {
    return 'অনুমোদিত: $qty';
  }

  @override
  String deliveryOrdersSubmitted(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ জমা হলো — সুপারভাইজার দেখবেন।',
        'other': 'DO জমা হলো — সুপারভাইজার দেখবেন।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersQtySaved => 'অনুমোদিত পরিমাণ রাখা হলো।';

  @override
  String get deliveryOrdersSaveQty => 'পরিমাণ রাখুন';

  @override
  String get deliveryOrdersApproved => 'অনুমোদন দেওয়া হলো।';

  @override
  String get deliveryOrdersApprove => 'অনুমোদন দিন';

  @override
  String get deliveryOrdersRejectReason => 'ফেরতের কারণ';

  @override
  String get deliveryOrdersRejectNeedReason => 'ফেরতের কারণ লিখুন।';

  @override
  String deliveryOrdersRejected(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ ফেরত পাঠানো হলো।',
        'other': 'DO ফেরত পাঠানো হলো।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersReject => 'ফেরত পাঠান';

  @override
  String get deliveryOrdersDialogQty => 'পরিমাণ';

  @override
  String get deliveryOrdersDialogCancel => 'বাদ';

  @override
  String get deliveryOrdersDialogOk => 'ঠিক আছে';

  @override
  String get deliveryOrdersStockTitle => 'মজুদ এখন — ধরা হয়নি';

  @override
  String get deliveryOrdersStockNote =>
      'মাল আটকায় ডিপোর চালান নিশ্চিত হলে; এখানে কেবল কত আছে আর কত চাই।';

  @override
  String deliveryOrdersStockHaveWant(String have, String want) {
    return 'আছে $have · চাই $want';
  }

  @override
  String deliveryOrdersStockWant(String want) {
    return 'চাই $want';
  }

  @override
  String deliveryOrdersStockShort(String qty) {
    return 'কম $qty';
  }

  @override
  String get deliveryOrdersStockNotEnough => 'মজুদে কুলোয় না';

  @override
  String get salesPlanLoadFailed => 'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get salesPlanNotOnServer =>
      'সার্ভারে ফোনের এই পর্দা এখনো আসেনি — অফিসে জানান।';

  @override
  String get salesPlanPreviousMonth => 'আগের মাস';

  @override
  String get salesPlanNextMonth => 'পরের মাস';

  @override
  String salesPlanPeople(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count জন',
    );
    return '$_temp0';
  }

  @override
  String get salesPlanTargetsTitle => 'লক্ষ্যমাত্রা';

  @override
  String get salesPlanTargetsEmpty => 'এই মাসে কোনো বিক্রয়কর্মী নেই';

  @override
  String get salesPlanTargetsNote =>
      'ⓘ লক্ষ্য বসানো ওয়েবে। অর্জন নিশ্চিত বিলের, ভ্যাট আর ছাড় বাদে।';

  @override
  String get salesPlanTotalTarget => 'মোট লক্ষ্য';

  @override
  String get salesPlanTotalAchieved => 'মোট অর্জন';

  @override
  String get salesPlanTargetHoldersAchieved => 'লক্ষ্যধারীদের অর্জন';

  @override
  String get salesPlanTargetSet => 'লক্ষ্য বসানো';

  @override
  String salesPlanTargetAchieved(String target, String achieved) {
    return 'লক্ষ্য $target · অর্জন $achieved';
  }

  @override
  String get salesPlanDealerTargetsTitle => 'ডিলারের লক্ষ্য';

  @override
  String get salesPlanDealerTargetsNoneSet => 'এই মাসে কারো লক্ষ্য বসানো নেই';

  @override
  String get salesPlanDealerTargetsEmpty => 'কোনো ডিলার নেই';

  @override
  String get salesPlanDealerTargetsSearch => 'ডিলারের নাম বা কোড';

  @override
  String get salesPlanDealerTargetsOnlySet => 'কেবল যাঁদের লক্ষ্য আছে';

  @override
  String get salesPlanDealerTargetsNote =>
      'ⓘ লক্ষ্য বসানো ওয়েবে। অর্জন মানে আদায়, রসিদ আর পাশ হওয়া চেক।';

  @override
  String get salesPlanTotalDue => 'মোট বাকি';

  @override
  String salesPlanAchieved(String amount) {
    return 'অর্জন $amount';
  }

  @override
  String salesPlanDue(String amount) {
    return 'বাকি $amount';
  }

  @override
  String salesPlanEnds(String date) {
    return 'শেষ $date';
  }

  @override
  String get salesPlanRouteBookTitle => 'রুটের খাতা';

  @override
  String get salesPlanRouteBookEmpty => 'কোনো রুট নেই';

  @override
  String get salesPlanRouteBookSearch => 'রুটের নাম বা কোড';

  @override
  String get salesPlanTotalSales => 'মোট বিক্রি';

  @override
  String get salesPlanTotalCollections => 'মোট আদায়';

  @override
  String get salesPlanTargetAchievement => 'লক্ষ্যের অর্জন';

  @override
  String get salesPlanDealers => 'ডিলার';

  @override
  String salesPlanUnrouted(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '⚠ $count জন ডিলার কোনো রুটে বসানো নেই — তাঁদের অঙ্ক এখানে নেই।',
    );
    return '$_temp0';
  }

  @override
  String salesPlanDealerCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count জন ডিলার',
    );
    return '$_temp0';
  }

  @override
  String salesPlanSalesCollections(String sales, String collections) {
    return 'বিক্রি $sales · আদায় $collections';
  }

  @override
  String salesPlanTargetPercent(String target, String percent) {
    return 'লক্ষ্য $target · $percent';
  }

  @override
  String get salesPlanRouteEmpty => 'এই রুটে কোনো ডিলার নেই';

  @override
  String get salesPlanClosingBalance => 'শেষ জের (বাকি)';

  @override
  String get salesPlanOpeningBalance => 'আগের জের';

  @override
  String get salesPlanSales => 'বিক্রি';

  @override
  String get salesPlanReturns => 'ফেরত';

  @override
  String get salesPlanCollections => 'আদায়';

  @override
  String get salesPlanOther => 'অন্যান্য';

  @override
  String get salesPlanTarget => 'লক্ষ্য';

  @override
  String get salesPlanWeeklyPlan => 'সাপ্তাহিক ছক';

  @override
  String get salesPlanWeeklyPlanEmpty => 'ছকে কেউ বসানো নেই।';

  @override
  String get salesPlanVisitActive => 'চালু';

  @override
  String get salesPlanVisitEnded => 'শেষ';

  @override
  String salesPlanFromTo(String from, String to) {
    return '$from থেকে $to';
  }

  @override
  String get salesPlanOngoing => 'চলছে';

  @override
  String get salesPlanRouteNote => 'ⓘ ছক আর লক্ষ্য বসানো ওয়েবে।';

  @override
  String get salesPlanStatusAll => 'সব';

  @override
  String get salesPlanSchemesTitle => 'স্কিম';

  @override
  String get salesPlanSchemesEmpty => 'কোনো স্কিম নেই';

  @override
  String get salesPlanSchemesSearch => 'স্কিমের কোড বা নাম';

  @override
  String salesPlanSchemesCount(String count) {
    return 'মোট $count টি স্কিম · বসানো ও বদল ওয়েবে';
  }

  @override
  String salesPlanSchemeLine(String basis, String appliesTo, int rules) {
    String _temp0 = intl.Intl.pluralLogic(
      rules,
      locale: localeName,
      other: '$rulesটি ধাপ',
    );
    return '$basis · $appliesTo · $_temp0';
  }

  @override
  String get salesPlanSchemeLive => ' · আজ চলছে';

  @override
  String get salesPlanSchemeCode => 'কোড';

  @override
  String get salesPlanSchemeStatus => 'অবস্থা';

  @override
  String get salesPlanSchemeBasis => 'ভিত্তি';

  @override
  String get salesPlanSchemeAppliesTo => 'কীসের উপর';

  @override
  String get salesPlanSchemeValidity => 'মেয়াদ';

  @override
  String get salesPlanSchemeNotes => 'নোট';

  @override
  String salesPlanSchemeSteps(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'ধাপ · $countটি',
    );
    return '$_temp0';
  }

  @override
  String get salesPlanSchemeNoSteps => 'কোনো ধাপ বসানো নেই';

  @override
  String salesPlanSchemeRoleLevel(String role, String level) {
    return '$role · স্তর $level';
  }

  @override
  String salesPlanSchemeFromUp(String from) {
    return '$from থেকে উপরে';
  }

  @override
  String get salesPlanSchemeNote => 'ⓘ ধাপ বসানো, চালু আর থামানো ওয়েবে।';

  @override
  String get salesPlanCommissionTitle => 'কমিশন';

  @override
  String get salesPlanCommissionEmpty => 'কোনো কমিশন নেই';

  @override
  String get salesPlanCommissionSearch => 'নম্বর, ডিলার বা কোম্পানি';

  @override
  String get salesPlanCommissionGrandTotal => 'সর্বমোট';

  @override
  String get salesPlanCommissionPending => 'কোম্পানির কাছে দাবি, এখনো বাকি';

  @override
  String salesPlanCommissionByStatus(String label, int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটি',
    );
    return '$label · $_temp0';
  }

  @override
  String get salesPlanCommissionTotalNote =>
      'ⓘ বাতিল দাবি মোটে নেই; ফেরত দেওয়া দাবি ডিপোর খরচ হিসেবে মোটে আছে';

  @override
  String get salesPlanCommissionWebNote =>
      'ⓘ নতুন দাবি, মেটানো আর বাতিল ওয়েবে।';

  @override
  String salesPlanCommissionInvoice(String no) {
    return 'বিল $no';
  }

  @override
  String salesPlanCommissionBase(String amount) {
    return 'ভিত্তি $amount';
  }

  @override
  String salesPlanCommissionRate(String rate) {
    return 'হার $rate';
  }

  @override
  String salesPlanCommissionLump(String amount) {
    return 'থোক $amount';
  }

  @override
  String salesPlanCommissionReason(String reason) {
    return 'কারণ: $reason';
  }

  @override
  String get approvalsRejectReasonRequired =>
      'প্রত্যাখ্যানের কারণ লেখা বাধ্যতামূলক';

  @override
  String get approvalsSendBackReasonRequired =>
      'সংশোধনে ফেরতের কারণ লেখা বাধ্যতামূলক';

  @override
  String get approvalsFinalRejectTitle => 'চূড়ান্ত প্রত্যাখ্যান?';

  @override
  String approvalsFinalRejectQuestion(String what) {
    return '$what\n\nএটা চূড়ান্ত — আর ফেরানো যাবে না। লেখককে ঠিক করার সুযোগ দিতে চাইলে \"সংশোধনে ফেরত দিন\" বেছে নিন।';
  }

  @override
  String get approvalsFinalRejectKeep => 'থাক';

  @override
  String get approvalsFinalRejectConfirm => 'হ্যাঁ, প্রত্যাখ্যান';

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
  String get approvalsInboxSentBack => 'সংশোধনে ফেরত পাঠানো হলো।';

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
  String get approvalsInboxRejectButton => 'প্রত্যাখ্যান করুন';

  @override
  String get approvalsInboxSendBackTitle => 'সংশোধনে ফেরত দেবেন?';

  @override
  String get approvalsInboxSendBackButton => 'সংশোধনে ফেরত দিন';

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
  String get approvalsQueueSendBackTitle => 'কী ঠিক করতে হবে? (সংশোধনে ফেরত)';

  @override
  String get approvalsQueueReasonDismiss => 'বাদ দিন';

  @override
  String get approvalsQueueReasonNeeded => 'কারণ লিখতে হবে।';

  @override
  String get approvalsQueueSendBack => 'সংশোধনে ফেরত দিন';

  @override
  String get approvalsQueueSentBack => 'সংশোধনে ফেরত পাঠানো হলো।';

  @override
  String get approvalsQueueSendBackFailed => 'সংশোধনে ফেরত পাঠানো গেল না।';

  @override
  String get approvalsQueueRejectTitle => 'কেন প্রত্যাখ্যান করছেন?';

  @override
  String get approvalsQueueReject => 'প্রত্যাখ্যান করুন';

  @override
  String get approvalsQueueRejected => 'প্রত্যাখ্যান করা হয়েছে।';

  @override
  String get approvalsQueueRejectFailed => 'প্রত্যাখ্যান করা গেল না।';

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

  @override
  String get hrPayslipFestivalBonus => 'উৎসব ভাতা';

  @override
  String get ownerCentreTitle => 'মালিকের কেন্দ্র';

  @override
  String get ownerTodayFailed => 'মালিকের কেন্দ্রের সংখ্যা আনা গেল না।';

  @override
  String get ownerTodayAbsent =>
      'মালিকের কেন্দ্র এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get ownerPageAbsent =>
      'মালিকের কেন্দ্রের এই পাতা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।';

  @override
  String get ownerNoCompanyTitle => 'দেখার মতো কোনো কোম্পানি নেই';

  @override
  String get ownerNoCompanyMessage =>
      'কোনো কোম্পানিতে আপনার মালিকের কেন্দ্র দেখার অনুমতি নেই।';

  @override
  String get ownerCompaniesAndBranches => 'কোম্পানি ও শাখা';

  @override
  String get ownerAlerts => 'সতর্কতা';

  @override
  String get ownerAwaitingSignature => 'সইয়ের অপেক্ষায়';

  @override
  String get ownerNothingAwaitingNow => 'এখন কোনো কাগজ সইয়ের অপেক্ষায় নেই।';

  @override
  String get ownerSomeCompaniesLeftOut => 'কিছু কোম্পানি বাদ — অনুমতি নেই';

  @override
  String get ownerCredit => 'বাকি';

  @override
  String get ownerCreditExposure => 'ব্যবহৃত বাকি';

  @override
  String get ownerCreditOverdue => 'মেয়াদ পেরোনো পাওনা';

  @override
  String get ownerCreditBlocked => 'বাকি বন্ধ গ্রাহক';

  @override
  String ownerCreditBlockedCount(int count) {
    return '$count জন';
  }

  @override
  String get ownerCreditRequests => 'সীমা বাড়ানোর অনুরোধ';

  @override
  String ownerCount(int count) {
    return '$countটা';
  }

  @override
  String ownerCompanySummary(String sales, String collections) {
    return 'বিক্রি $sales · আদায় $collections';
  }

  @override
  String get ownerUnsplit => 'শাখায় ভাগ হয়নি';

  @override
  String get ownerNotAllowed => 'অনুমতি নেই';

  @override
  String get ownerNone => 'কিছু নেই';

  @override
  String get ownerPeriodToday => 'আজ';

  @override
  String get ownerPeriodWeek => 'এই সপ্তাহ';

  @override
  String get ownerPeriodMonth => 'এই মাস';

  @override
  String get ownerFigureSales => 'বিক্রি';

  @override
  String get ownerFigureCollections => 'আদায়';

  @override
  String get ownerFigureReceivable => 'পাওনা (বাকি)';

  @override
  String get ownerFigurePayable => 'সরবরাহকারীর দেনা';

  @override
  String get ownerFigureFund => 'নগদ + ব্যাংক';

  @override
  String get ownerFigureStock => 'মজুদের মূল্য';

  @override
  String get ownerFigureProfit => 'লাভ';

  @override
  String get ownerFigureExpenses => 'খরচ';

  @override
  String get ownerFigureCashFlow => 'নগদ প্রবাহ (নিট)';

  @override
  String get ownerAlertOverLimit => 'বাকির সীমা ছাড়িয়েছে';

  @override
  String get ownerAlertOverdue => 'মেয়াদ পেরোনো বাকি';

  @override
  String get ownerAlertStockLow => 'মজুদ পুনঃঅর্ডারের নিচে';

  @override
  String get ownerAlertStockNegative => 'মজুদ শূন্যের নিচে';

  @override
  String get ownerAlertStockDead => 'অচল মাল';

  @override
  String ownerAlertLateDelivery(int days) {
    return 'দেরির চালান ($days দিন)';
  }

  @override
  String ownerAlertFailedLogin(int days) {
    return 'ব্যর্থ লগইন ($days দিন)';
  }

  @override
  String get ownerAlertBackdated => 'পিছনের তারিখের কাগজ (এ মাসে)';

  @override
  String get ownerAlertsFailed => 'সতর্কতার তালিকা আনা গেল না।';

  @override
  String ownerFiguresMayBeOld(int minutes) {
    return 'সংখ্যাগুলো $minutes মিনিট পর্যন্ত পুরনো হতে পারে। টেনে নামালে নতুন করে আনে।';
  }

  @override
  String ownerAwaitingSignatureCount(int count) {
    return 'সইয়ের অপেক্ষায় ($countটা)';
  }

  @override
  String get ownerNothingAwaiting => 'কোনো কাগজ সইয়ের অপেক্ষায় নেই।';

  @override
  String get ownerGroupTotal => 'গ্রুপের মোট';

  @override
  String get ownerHistoryTitle => 'আগের দিনের হিসাব';

  @override
  String get ownerHistoryFailed => 'আগের দিনের হিসাব আনা গেল না।';

  @override
  String ownerHistoryDay(String day) {
    return 'কোন দিন: $day';
  }

  @override
  String ownerHistoryAsKept(String date, String time) {
    return '$date — ঐ দিন রাত $time-এ পর্দায় যা ছিল, ঠিক তাই। আজকের খাতা থেকে নতুন করে গোনা নয়।';
  }

  @override
  String get ownerHistoryNoneTitle => 'ঐ দিনের রাতের হিসাব নেই';

  @override
  String get ownerHistoryNoneMessage =>
      'হিসাব রাখা শুরু হওয়ার আগের দিন, বা সেই রাতে লেখা হয়নি।';

  @override
  String ownerHistoryTrend(String figure, int days) {
    return '$figure — গত $days দিন, রাতের হিসাব থেকে';
  }

  @override
  String get ownerHistoryMonthBefore => 'গত মাসের একই দিন';

  @override
  String get ownerReportProfitByCustomer => 'ক্রেতা ধরে লাভ';

  @override
  String get ownerReportProfitByCustomerHint =>
      'প্রতিটা বিলের সারির আসল FIFO খরচ — বিল পাকা হওয়ার সময় স্তর থেকে যা টানা হয়েছিল; ফেরত তার নিজের খরচে বাদ।';

  @override
  String get ownerReportsTitle => 'রিপোর্ট';

  @override
  String get ownerReportsFailed => 'রিপোর্টের পাতা আনা গেল না।';

  @override
  String get ownerReportsCentre => 'রিপোর্ট সেন্টার — সব রিপোর্ট';

  @override
  String get ownerReportsOwn => 'মালিকের কেন্দ্রের রিপোর্ট';

  @override
  String get ownerReportNotAllowed => 'এই রিপোর্ট দেখার অনুমতি নেই।';

  @override
  String get ownerReportsExportNote =>
      'রিপোর্টের পাতায় তারিখ বাছা, PDF আর ভাগ করা যায়; প্রতিটা নামানো রপ্তানির খাতায় লেখা থাকে।';

  @override
  String ownerSavedViews(int count) {
    return 'আমার সংরক্ষিত দৃশ্য ($countটা)';
  }

  @override
  String get ownerNoSavedViews =>
      'কোনো সংরক্ষিত দৃশ্য নেই — রিপোর্টের পাতায় \"দৃশ্য\" থেকে রাখা যায়।';

  @override
  String get ownerScheduledReports => 'নির্ধারিত রিপোর্ট';

  @override
  String get ownerSchedulesOnWeb => 'সূচি বসানো আর বদলানো ওয়েবের পাতায়।';

  @override
  String get ownerNoScheduleKey => 'নির্ধারিত রিপোর্ট বসানোর অনুমতি নেই।';

  @override
  String get ownerReportsInAppOnly =>
      'তৈরি হওয়া রিপোর্ট অ্যাপের ভিতরেই আসে, ই-মেইলে নয়।';

  @override
  String get ownerLinksTitle => 'ভাই-কোম্পানির পক্ষ';

  @override
  String get ownerLinksFailed => 'ভাই-কোম্পানির পক্ষের তালিকা আনা গেল না।';

  @override
  String get ownerLinksExplain =>
      'এক কোম্পানির কোনো ক্রেতা বা সরবরাহকারী যদি আসলে আমাদেরই আরেকটা কোম্পানি হয়, জোড়া থাকলে গ্রুপের মোট থেকে দুইয়ের মাঝের বিক্রি, পাওনা আর দেনা বাদ যায়। নাম মিলিয়ে কিছু আন্দাজ করা হয় না।';

  @override
  String get ownerLinksWebOnly =>
      'জোড়া দেওয়া আর তোলা ওয়েবের পাতায় — এখানে কেবল দেখা।';

  @override
  String get ownerLinksTotal => 'মোট জোড়া';

  @override
  String get ownerLinksNoneTitle => 'কোনো জোড়া নেই';

  @override
  String get ownerLinksNoneMessage => 'গ্রুপের মোট থেকে কিছু বাদ যাচ্ছে না।';

  @override
  String ownerLinksIsSister(String company, String sister) {
    return '$company → আসলে আমাদের $sister';
  }

  @override
  String get ownerPartyCustomer => 'ক্রেতা';

  @override
  String get ownerPartySupplier => 'সরবরাহকারী';

  @override
  String get ownerAnalysisTitle => 'বিশ্লেষণ';

  @override
  String get ownerAnalysisFailed => 'বিশ্লেষণের তালিকা আনা গেল না।';

  @override
  String get ownerPeriodThreeMonths => 'গত তিন মাস';

  @override
  String get ownerPeriodYear => 'এই বছর';

  @override
  String ownerListTopCustomers(int count) {
    return 'সেরা $count ক্রেতা';
  }

  @override
  String ownerListTopProducts(int count) {
    return 'সেরা $count পণ্য';
  }

  @override
  String ownerListTopAreas(int count) {
    return 'সেরা $count রুট';
  }

  @override
  String ownerListTopSalespeople(int count) {
    return 'সেরা $count বিক্রয়কর্মী';
  }

  @override
  String get ownerListLosing => 'সবচেয়ে কম লাভের ক্রেতা';

  @override
  String get ownerListThin => 'সবচেয়ে কম মার্জিনের পণ্য';

  @override
  String get ownerListDead => 'অচল মাল (দাম ধরে)';

  @override
  String get ownerBucketCurrent => 'চলতি বাকি';

  @override
  String ownerBucketOver(int days) {
    return '$days দিনের বেশি';
  }

  @override
  String get ownerOutstanding => 'মোট বাকি';

  @override
  String get ownerAgeing => 'বাকির বয়স — সব কোম্পানি মিলিয়ে';

  @override
  String ownerRefused(String companies) {
    return 'দেখার অনুমতি নেই: $companies';
  }

  @override
  String get ownerNothingInPeriod => 'এই সময়ে কিছু নেই।';

  @override
  String get ownerNoCostKey =>
      'খরচ দেখার অনুমতি নেই — লাভ আর মার্জিনের ঘর ঢাকা থাকবে।';

  @override
  String get ownerCompareTitle => 'তুলনা';

  @override
  String get ownerCompareFailed => 'তুলনার সংখ্যা আনা গেল না।';

  @override
  String get ownerModeCompanies => 'কোম্পানি বনাম কোম্পানি';

  @override
  String get ownerModeBranches => 'শাখা বনাম শাখা';

  @override
  String get ownerAgainstPreviousMonth => 'আগের মাসের একই দিনগুলো';

  @override
  String get ownerAgainstLastYear => 'গত বছরের একই সময়';

  @override
  String get ownerGrainDaily => 'দিন ধরে';

  @override
  String get ownerGrainWeekly => 'সপ্তাহ ধরে';

  @override
  String get ownerGrainMonthly => 'মাস ধরে';

  @override
  String get ownerGrainQuarterly => 'তিন মাস ধরে';

  @override
  String get ownerGrainYearly => 'বছর ধরে';

  @override
  String get ownerAllCompanies => 'সব কোম্পানি';

  @override
  String ownerNowThen(String now, String was) {
    return 'এখন: $now · তখন: $was';
  }

  @override
  String get ownerNothingToShow => 'দেখার মতো কিছু নেই';

  @override
  String get ownerScreenNotAllowed =>
      'কোনো কোম্পানিতে আপনার এই পর্দা দেখার অনুমতি নেই।';

  @override
  String get ownerReceivableNote =>
      'বাকি একটা জের: \"তখন\" মানে ঐ দিনের রাতে রাখা হিসাব। রাতের হিসাব না থাকলে \"—\"।';

  @override
  String ownerTrendOf(String figure) {
    return '$figure — সময়ের সাথে';
  }

  @override
  String get ownerNotAllowedToSee => 'দেখার অনুমতি নেই';

  @override
  String get ownerLastYear => 'গত বছর';

  @override
  String ownerThen(String value) {
    return 'তখন $value';
  }

  @override
  String get documentsLevelPublic => 'সবার জন্য';

  @override
  String get documentsLevelInternal => 'অভ্যন্তরীণ';

  @override
  String get documentsLevelConfidential => 'গোপন';

  @override
  String get documentsLevelHighlyConfidential => 'অতি গোপন';

  @override
  String get documentsLevelRestricted => 'সংরক্ষিত';

  @override
  String get documentsStatusDraft => 'খসড়া';

  @override
  String get documentsStatusSubmitted => 'জমা হয়েছে';

  @override
  String get documentsStatusUnderReview => 'পর্যালোচনায়';

  @override
  String get documentsStatusChangesRequested => 'বদল চাওয়া হয়েছে';

  @override
  String get documentsStatusApproved => 'অনুমোদিত';

  @override
  String get documentsStatusRejected => 'প্রত্যাখ্যাত';

  @override
  String get documentsStatusPublished => 'প্রকাশিত';

  @override
  String get documentsStatusPublishedUnapproved => 'অনুমোদন ছাড়া প্রকাশিত';

  @override
  String get documentsStatusExpired => 'মেয়াদোত্তীর্ণ';

  @override
  String get documentsStatusArchived => 'আর্কাইভে';

  @override
  String get documentsSignaturePending => 'সইয়ের অপেক্ষায়';

  @override
  String get documentsSignatureSigned => 'সই হয়েছে — চলতি ভার্সনে';

  @override
  String get documentsSignatureStale => 'সই পুরনো ভার্সনে — চলতিটায় সই নেই';

  @override
  String get documentsSignatureNone => 'সই চাওয়া হয়নি';

  @override
  String get documentsViewAll => 'সব';

  @override
  String get documentsViewMine => 'আমার';

  @override
  String get documentsViewShared => 'আমার সাথে শেয়ার';

  @override
  String get documentsViewRecent => 'সাম্প্রতিক';

  @override
  String get documentsViewExpiry => 'মেয়াদ';

  @override
  String get documentsViewArchive => 'আর্কাইভ';

  @override
  String get documentsViewRecycle => 'রিসাইকেল বিন';

  @override
  String get documentsViewSearch => 'খোঁজ (আর্কাইভসহ)';

  @override
  String get documentsTitle => 'ডকুমেন্টস';

  @override
  String get documentsListFailed =>
      'কাগজের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get documentsListAbsent =>
      'সার্ভারে ডকুমেন্টস এখনো আসেনি — অফিসে জানান।';

  @override
  String get documentsListEmpty => 'এখানে দেখার মতো কোনো কাগজ নেই';

  @override
  String get documentsSearchHint => 'নাম, নম্বর বা ট্যাগ';

  @override
  String documentsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'মোট $count টি কাগজ',
    );
    return '$_temp0';
  }

  @override
  String documentsExpires(String date) {
    return 'মেয়াদ $date';
  }

  @override
  String get documentsDeleted => 'মোছা — ফেরানো ওয়েবে';

  @override
  String get documentsFileOpenFailed => 'ফাইলটা খোলা গেল না।';

  @override
  String get documentsSignAsk => 'এই কাগজে সই দেবেন?';

  @override
  String get documentsRemarksOptional => 'মন্তব্য (ইচ্ছা হলে)';

  @override
  String get documentsNo => 'না';

  @override
  String get documentsSign => 'সই দিন';

  @override
  String get documentsSigned => 'সই দেওয়া হয়েছে।';

  @override
  String get documentsRefuseAsk => 'সই দেবেন না?';

  @override
  String get documentsReasonField => 'কারণ লিখুন';

  @override
  String get documentsGoBack => 'ফিরে যান';

  @override
  String get documentsRefuse => 'সই দেব না';

  @override
  String get documentsReasonNeeded => 'সই না দেওয়ার কারণ লিখতে হবে।';

  @override
  String get documentsRefused => 'সই দেওয়া হয়নি — কারণসহ জানানো হয়েছে।';

  @override
  String get documentsDecisionFailed =>
      'সিদ্ধান্তটা পাঠানো গেল না। আবার চেষ্টা করুন।';

  @override
  String get documentsPaper => 'কাগজ';

  @override
  String get documentsOneFailed => 'কাগজটা আনা গেল না।';

  @override
  String get documentsNumber => 'নম্বর';

  @override
  String get documentsType => 'ধরন';

  @override
  String get documentsFolder => 'ফোল্ডার';

  @override
  String get documentsLevel => 'গোপনীয়তা';

  @override
  String get documentsStatus => 'অবস্থা';

  @override
  String get documentsDate => 'তারিখ';

  @override
  String get documentsExpiry => 'মেয়াদ';

  @override
  String get documentsOwner => 'মালিক';

  @override
  String get documentsTags => 'ট্যাগ';

  @override
  String get documentsDescription => 'বিবরণ';

  @override
  String get documentsFile => 'ফাইল';

  @override
  String get documentsNoFile => 'এই কাগজে এখনো কোনো ফাইল নেই।';

  @override
  String get documentsFileName => 'নাম';

  @override
  String get documentsVersion => 'ভার্সন';

  @override
  String get documentsViewFile => 'দেখুন';

  @override
  String get documentsDownload => 'নামান / পাঠান';

  @override
  String get documentsCannotOpen =>
      'এই ফাইলটা ফোনে দেখা যায় না, আর নামানোর অধিকার আপনার নেই।';

  @override
  String get documentsSignature => 'সই';

  @override
  String documentsSignedOld(String date) {
    return '$date · পুরনো ভার্সনে';
  }

  @override
  String get documentsSignWanted =>
      'এই কাগজে আপনার সই চাওয়া হয়েছে। ফাইলটা দেখে সই দিন।';

  @override
  String get documentsUploadFormFailed =>
      'তোলার ফর্ম আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get documentsUploadAbsent =>
      'সার্ভারে ফোন থেকে তোলা এখনো আসেনি — অফিসে জানান।';

  @override
  String get documentsUploadTakePhotoFirst => 'আগে কাগজটার ছবি তুলুন।';

  @override
  String get documentsUploadPickFirst => 'আগে অন্তত একটা ছবি বা ফাইল বাছুন।';

  @override
  String get documentsUploadPickFields => 'ফোল্ডার, ধরন আর গোপনীয়তা বাছুন।';

  @override
  String documentsUploaded(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি কাগজ তোলা হলো।',
    );
    return '$_temp0';
  }

  @override
  String get documentsUploadFailed => 'কাগজ তোলা গেল না।';

  @override
  String get documentsScanTitle => 'ক্যামেরায় স্ক্যান';

  @override
  String get documentsUploadTitle => 'কাগজ তোলা';

  @override
  String get documentsLoadFailed => 'আনা গেল না';

  @override
  String get documentsTakePhoto => 'ছবি তুলুন';

  @override
  String get documentsPickPhotos => 'ছবি বাছুন';

  @override
  String get documentsPickFiles => 'ফাইল বাছুন (PDF ইত্যাদি)';

  @override
  String documentsUploadFileCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি ফাইল — প্রতিটা আলাদা কাগজ হবে',
    );
    return '$_temp0';
  }

  @override
  String get documentsUploadRulesNote =>
      'ⓘ কোন ধরনের ফাইল চলে আর কত বড়, সার্ভার ওয়েবের নিয়মেই দেখে। লেখা পড়া (OCR) ওয়েবে।';

  @override
  String get documentsUploadName => 'নাম (ফাঁকা রাখলে ফাইলের নাম)';

  @override
  String get documentsBranch => 'শাখা';

  @override
  String get documentsWholeCompany => 'গোটা কোম্পানি';

  @override
  String get documentsDescriptionOptional => 'বিবরণ (ঐচ্ছিক)';

  @override
  String get documentsUploading => 'তোলা হচ্ছে…';

  @override
  String get documentsUpload => 'তুলুন';

  @override
  String get leadsFailed => 'লিড আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get leadsAbsent => 'সার্ভারে লিডের দরজা এখনো আসেনি — অফিসে জানান।';

  @override
  String get leadsTitle => 'লিড';

  @override
  String get leadsNew => 'নতুন লিড';

  @override
  String get leadsSearchHint => 'নাম, ফোন বা নম্বর';

  @override
  String get leadsAll => 'সব';

  @override
  String get leadsTotal => 'মোট লিড';

  @override
  String leadsCount(int count) {
    return '$count টি';
  }

  @override
  String get leadsLoadFailed => 'আনা গেল না';

  @override
  String get leadsNone => 'কোনো লিড নেই';

  @override
  String get leadsMore => 'আরও লিড';

  @override
  String get leadsNameNeeded => 'দোকানের নাম লিখুন।';

  @override
  String get leadsSourceNeeded => 'কীভাবে খোঁজ পেলেন, বাছুন।';

  @override
  String get leadsSaveFailed => 'লিড রাখা গেল না। আবার চেষ্টা করুন।';

  @override
  String leadsStatusNow(String status) {
    return 'অবস্থা: $status';
  }

  @override
  String leadsOwner(String name) {
    return 'মালিক: $name';
  }

  @override
  String get leadsConverted => 'গ্রাহক হয়ে গেছেন — আর বদলানো যায় না।';

  @override
  String get leadsShopName => 'দোকানের নাম';

  @override
  String get leadsContactPerson => 'যাঁর সাথে কথা';

  @override
  String get leadsPhone => 'ফোন';

  @override
  String get leadsAddress => 'ঠিকানা';

  @override
  String get leadsSource => 'কীভাবে খোঁজ পেলেন';

  @override
  String get leadsStatus => 'অবস্থা';

  @override
  String get leadsLostReason => 'কেন হারালাম';

  @override
  String get leadsNotes => 'টুকিটাকি';

  @override
  String papersListFailed(String title) {
    return '$title-এর তালিকা আনা গেল না।';
  }

  @override
  String get papersListAbsent =>
      'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get papersAllBranches => 'সব শাখা';

  @override
  String get papersAll => 'সব';

  @override
  String get papersSearchHint => 'নম্বর বা নাম';

  @override
  String get papersGrandTotal => 'সর্বমোট';

  @override
  String papersPageTotal(int count) {
    return 'এই পাতার মোট ($countটি)';
  }

  @override
  String papersCount(int count) {
    return '$countটি';
  }

  @override
  String papersNone(String title) {
    return 'কোনো $title নেই';
  }

  @override
  String get papersNoneDated =>
      'তারিখ বা ছাঁকনি বদলে, বা নিচে টেনে আবার দেখুন।';

  @override
  String get papersNoneUndated => 'নিচে টেনে আবার দেখুন।';

  @override
  String get papersMore => 'আরও';

  @override
  String papersOneFailed(String title) {
    return '$title-টা আনা গেল না।';
  }

  @override
  String get papersPrint => 'ছাপা / পাঠান';

  @override
  String get papersStatus => 'অবস্থা';

  @override
  String get papersBranch => 'শাখা';

  @override
  String get papersLines => 'সারি';

  @override
  String papersTotalOf(String column) {
    return 'মোট $column';
  }

  @override
  String get papersSignatures => 'সই';

  @override
  String papersRequestedBy(String name) {
    return 'চেয়েছেন: $name';
  }

  @override
  String papersSteps(String steps) {
    return 'ধাপ: $steps';
  }

  @override
  String get papersSign => 'সই দিন';

  @override
  String get collectTitle => 'আদায়';

  @override
  String get collectNoLater => 'নম্বর: সংরক্ষণ করলে বসবে';

  @override
  String collectNumber(String no) {
    return 'নম্বর: $no';
  }

  @override
  String collectBranchOf(String branch) {
    return 'শাখা: গ্রাহকের শাখা ($branch)';
  }

  @override
  String get collectMonthOpen => 'মাস খোলা';

  @override
  String get collectMonthClosed => 'মাস বন্ধ — পোস্ট হবে না';

  @override
  String get collectFigureDue => 'গ্রাহকের বকেয়া';

  @override
  String get collectFigureThis => 'এই আদায়';

  @override
  String get collectFigureLeft => 'বাকি থাকবে';

  @override
  String get collectFigureCredit => 'গ্রাহকের খাতায় জমা থাকবে';

  @override
  String get collectCustomer => 'গ্রাহক *';

  @override
  String get collectPickCustomer => 'গ্রাহক বাছুন';

  @override
  String collectDue(String amount) {
    return 'বকেয়া $amount';
  }

  @override
  String collectCreditLimit(String amount) {
    return 'বাকির সীমা $amount';
  }

  @override
  String collectOldestOverdue(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: 'সবচেয়ে পুরনো বাকি $days দিন',
    );
    return '$_temp0';
  }

  @override
  String get collectDate => 'তারিখ *';

  @override
  String get collectAmount => 'টাকা *';

  @override
  String get collectFullDue => 'পুরো বকেয়া';

  @override
  String collectOverDue(String extra) {
    return 'বকেয়ার চেয়ে $extra বেশি — বাড়তি টাকা গ্রাহকের খাতায় জমা (Cr) থাকবে।';
  }

  @override
  String get collectMethod => 'পদ্ধতি';

  @override
  String get collectMethodCash => 'নগদ';

  @override
  String get collectMethodTransfer => 'ব্যাংক ট্রান্সফার';

  @override
  String get collectMethodDeposit => 'নগদ জমা';

  @override
  String get collectMethodCheque => 'চেক';

  @override
  String get collectAccount => 'যে খাতে জমা হলো *';

  @override
  String collectAccountMine(String name) {
    return '$name · আমার হাতের নগদ';
  }

  @override
  String get collectTransferMode => 'ট্রান্সফার মোড';

  @override
  String get collectReference => 'রেফারেন্স / TrxID *';

  @override
  String get collectDepositSlip => 'জমার স্লিপ নম্বর *';

  @override
  String get collectDepositSlipMissing => 'জমার স্লিপ নম্বর দিন।';

  @override
  String get collectValueDate => 'মূল্য-তারিখ';

  @override
  String get collectChequeDate => 'চেকের তারিখ';

  @override
  String get collectBankCharge => 'ব্যাংক চার্জ (৳)';

  @override
  String get collectBankChargeHint =>
      'চার্জ যায় ব্যাংক চার্জ খাতে; গ্রাহকের খাতায় পুরো টাকা।';

  @override
  String get collectBillsTitle => 'খোলা বিল ও ক্রেডিট';

  @override
  String get collectBillsAuto => 'নিজে থেকে: পুরনো বিল আগে';

  @override
  String collectBillsAutoWith(String list) {
    return 'নিজে থেকে: পুরনো বিল আগে — $list';
  }

  @override
  String get collectBillsChange => 'বদলান';

  @override
  String get collectBillsFold => 'গুটিয়ে নিন';

  @override
  String get collectBillsHint =>
      'পুরনো আগে নিজে বসেছে · টিক বা অঙ্ক বদলে ঠিক করুন';

  @override
  String get collectBillsOldestFirst => 'পুরনো বিল আগে';

  @override
  String get collectBillsNone =>
      'এই গ্রাহকের কোনো খোলা বিল নেই — টাকাটা তাঁর খাতায় জমা থাকবে, পরের বিলে কাটবে।';

  @override
  String get collectBillsNeedCustomer => 'গ্রাহক বাছলে খোলা বিল দেখাবে।';

  @override
  String get collectBillsFailed =>
      'খোলা বিল আনা গেল না — সার্ভার নিজে পুরনো বিল আগে বসাবে।';

  @override
  String collectItemDueOn(String date) {
    return 'শেষ দিন $date';
  }

  @override
  String collectItemLate(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days দিন দেরি',
    );
    return '$_temp0';
  }

  @override
  String collectItemOpen(String amount) {
    return 'বাকি $amount';
  }

  @override
  String collectItemOriginal(String amount) {
    return 'মূল টাকা $amount';
  }

  @override
  String get collectItemApply => 'এখন প্রয়োগ';

  @override
  String collectItemApplyOf(String no) {
    return '$no — এখন প্রয়োগ';
  }

  @override
  String collectItemTick(String no) {
    return '$no প্রয়োগ করুন';
  }

  @override
  String get collectItemCredit => 'ক্রেডিট';

  @override
  String get collectItemCreditNote => 'ক্রেডিট নোট';

  @override
  String get collectItemDebitNote => 'ডেবিট নোট';

  @override
  String get collectAppliedTotal => 'মোট প্রয়োগ';

  @override
  String collectOverApplied(String amount) {
    return 'এর বেশি বসানো যায় না — সর্বোচ্চ $amount।';
  }

  @override
  String get collectCreditFloor =>
      'এর কম নেওয়া যায় না — বসানো বিলের টাকা হাতে থাকতে হবে।';

  @override
  String get collectDifference => 'ছাড় / রাউন্ডিং';

  @override
  String get collectDifferenceDiscount => 'ছাড়';

  @override
  String get collectDifferenceRounding => 'রাউন্ডিং';

  @override
  String get collectDifferenceAmount => 'ছাড় / রাউন্ডিংয়ের অঙ্ক';

  @override
  String get collectDifferenceSign =>
      'মালিকের সই লাগবে — পোস্ট করলে সইয়ে যাবে।';

  @override
  String get collectWithheldTitle => 'উৎসে কাটা (ঐচ্ছিক)';

  @override
  String get collectVds => 'গ্রাহক কেটে রেখেছে: উৎসে ভ্যাট (VDS)';

  @override
  String get collectAit => 'গ্রাহক কেটে রেখেছে: উৎসে কর (AIT)';

  @override
  String get collectSummaryTitle => 'হিসাবের সারাংশ';

  @override
  String get collectSumReceived => 'পাওয়া টাকা';

  @override
  String get collectSumApplied => 'বিলে প্রয়োগ';

  @override
  String get collectSumUnapplied => 'অপ্রয়োগকৃত (গ্রাহকের খাতায় জমা)';

  @override
  String get collectSumBalanced =>
      'মিলেছে: পাওয়া + কাটা + ছাড় = প্রয়োগ + অপ্রয়োগকৃত';

  @override
  String get collectJournalTitle => 'খাতায় যা যাবে';

  @override
  String get collectJournalHint => 'পোস্টের আগে দেখুন';

  @override
  String get collectJournalAccount => 'খাত';

  @override
  String get collectJournalBranch => 'শাখা';

  @override
  String get collectJournalDr => 'ডেবিট';

  @override
  String get collectJournalCr => 'ক্রেডিট';

  @override
  String get collectJournalTotal => 'মোট';

  @override
  String get collectJournalBalanced => 'মোট মিলেছে';

  @override
  String get collectJournalUnbalanced =>
      'মোট মেলেনি — পোস্ট করবেন না, অফিসে জানান।';

  @override
  String get collectControlsTitle => 'নিয়ন্ত্রণ';

  @override
  String get collectControlSignature =>
      'এই আদায়ে সই লাগবে — পোস্ট করলে সইয়ের অপেক্ষায় যাবে।';

  @override
  String get collectControlNoSignature =>
      'এই আদায়ে সই লাগবে না — পোস্ট করলে সাথে সাথে খাতায় উঠবে।';

  @override
  String get collectControlSlip => 'এই কোম্পানিতে স্লিপের ছবি বাধ্যতামূলক।';

  @override
  String get collectControlNoUndo =>
      'পোস্টের পরে বদলানো যায় না; ভুল হলে কারণ লিখে উল্টাতে হয় (দ্বিতীয় জনের সই)।';

  @override
  String get collectControlStops =>
      'উপরের কারণে পোস্ট হবে না — খসড়া রাখা যায়।';

  @override
  String get collectAttachTitle => 'সংযুক্তি ও বিবরণ';

  @override
  String get collectSlip => 'স্লিপের ছবি';

  @override
  String get collectSlipNeeded => 'স্লিপের ছবি *';

  @override
  String get collectSlipCamera => 'ক্যামেরা';

  @override
  String get collectSlipGallery => 'গ্যালারি';

  @override
  String get collectSlipRemove => 'ছবি সরান';

  @override
  String collectSlipChosen(String name) {
    return 'ছবি বাছা হয়েছে: $name';
  }

  @override
  String get collectSlipMissing =>
      'স্লিপের ছবি দিন — এই কোম্পানিতে বাধ্যতামূলক।';

  @override
  String get collectSlipTooBig => 'ছবিটা 5 MB-এর বেশি — ছোট ছবি দিন।';

  @override
  String get collectSlipFailed =>
      'আদায় বসেছে, কিন্তু স্লিপের ছবি পাঠানো গেল না — রসিদের পাতা থেকে আবার দিন।';

  @override
  String get collectNarration => 'বিবরণ (ঐচ্ছিক)';

  @override
  String get collectDraft => 'খসড়া রাখুন';

  @override
  String get collectPost => 'পোস্ট';

  @override
  String get collectReferenceMissing => 'রেফারেন্স / TrxID দিন।';

  @override
  String get collectDifferenceTooBig =>
      'ছাড় / রাউন্ডিং পাওয়া টাকার চেয়ে বেশি হতে পারে না।';

  @override
  String get collectCheckTitle => 'পোস্টের আগে দেখে নিন';

  @override
  String get collectCheckCustomer => 'গ্রাহক';

  @override
  String get collectCheckAmount => 'টাকা';

  @override
  String get collectCheckWords => 'কথায়';

  @override
  String get collectCheckAccount => 'যে খাতে জমা হলো';

  @override
  String get collectCheckBills => 'কোন বিলে বসবে';

  @override
  String get collectCheckBack => 'ফিরে যান';

  @override
  String get collectCheckPost => 'পোস্ট করুন';

  @override
  String collectDone(String no) {
    return 'আদায় বসেছে: $no';
  }

  @override
  String collectDoneWords(String words) {
    return 'কথায়: $words';
  }

  @override
  String get collectOpenReceipt => 'রসিদ খুলুন';

  @override
  String get collectReceiptHint =>
      'পোস্টের পরে: টাকার রসিদ (টাকা কথায়) · PDF · WhatsApp বা ইমেইল';

  @override
  String get collectNewOne => 'নতুন আদায়';

  @override
  String get collectClose => 'শেষ';

  @override
  String get collectOtherIncome => 'অন্য আয়';

  @override
  String get collectOtherIncomeHint => 'গ্রাহক নন এমন কারো থেকে, যেমন ভাড়া';

  @override
  String get collectFromCustomerHint => 'গ্রাহকের কাছ থেকে — এক পাতায়';
}
