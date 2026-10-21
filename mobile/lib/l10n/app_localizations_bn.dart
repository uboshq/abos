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
}
