// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppL10nEn extends AppL10n {
  AppL10nEn([String locale = 'en']) : super(locale);

  @override
  String get commonOpen => 'Open';

  @override
  String get commonCancel => 'Cancel';

  @override
  String get commonSave => 'Save';

  @override
  String get commonRetry => 'Try again';

  @override
  String commonPaperCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count papers',
      one: '$count paper',
      zero: 'No papers',
    );
    return '$_temp0';
  }

  @override
  String commonPaperState(String state) {
    String _temp0 = intl.Intl.selectLogic(
      state,
      {
        'draft': 'Draft',
        'posted': 'Posted',
        'cancelled': 'Cancelled',
        'other': 'Unknown',
      },
    );
    return '$_temp0';
  }

  @override
  String get topBarBell => 'Notifications';

  @override
  String topBarBellUnread(String count) {
    return 'Notifications, $count unread';
  }

  @override
  String get topBarBellNews => 'Notifications, something new';

  @override
  String get avatarMenuClose => 'Close menu';

  @override
  String get avatarMenuSwitchCompany => 'Switch company';

  @override
  String get avatarMenuColour => 'Colour';

  @override
  String get avatarMenuThemePhone => 'System';

  @override
  String get avatarMenuThemeLight => 'Light';

  @override
  String get avatarMenuThemeDark => 'Dark';

  @override
  String get avatarMenuLanguage => 'Language';

  @override
  String get avatarMenuLanguagePhone => 'Phone language';

  @override
  String get avatarMenuPinAndFingerprint => 'PIN and fingerprint';

  @override
  String avatarMenuLockAfter(String delay) {
    return 'Locks after: $delay';
  }

  @override
  String get avatarMenuMyPhones => 'My phones';

  @override
  String avatarMenuSettingsVersion(String version) {
    return 'Settings · Version $version';
  }

  @override
  String get avatarMenuSignOut => 'Sign out';

  @override
  String get homeStockValue => 'Stock value';

  @override
  String get homeStockValueBasis => 'At cost';

  @override
  String get homeStockValueOpenHint => 'Open the stock page';

  @override
  String get homeStockPageTitle => 'Stock';

  @override
  String get navModuleSales => 'Sales';

  @override
  String get navModulePurchase => 'Purchase';

  @override
  String get navModuleInventory => 'Inventory';

  @override
  String get navModuleAccounts => 'Accounts';

  @override
  String get navModuleFinance => 'Finance';

  @override
  String get navModuleCustomer => 'Customers';

  @override
  String get navModuleSupplier => 'Suppliers';

  @override
  String get navModuleApproval => 'Approvals';

  @override
  String get navModuleHr => 'HR';

  @override
  String get navModuleReports => 'Reports';

  @override
  String get navModuleDocuments => 'Documents';

  @override
  String get navModuleExecutive => 'Owner\'s centre';

  @override
  String get navModulePromotion => 'Promotion';

  @override
  String get navModuleCreditControl => 'Credit control';

  @override
  String get navModuleNotification => 'Notices';

  @override
  String get navGroupTransactions => 'Transactions';

  @override
  String get navGroupFieldWork => 'Field work';

  @override
  String get navGroupLists => 'Lists';

  @override
  String get navGroupMyWork => 'My work';

  @override
  String get navGroupReports => 'Reports';

  @override
  String get navGroupDashboards => 'Dashboards';

  @override
  String get navGroupOther => 'Other';

  @override
  String get navGroupApprovals => 'Approvals';

  @override
  String get navGroupSettings => 'Settings';

  @override
  String get navGroupComingSoon => 'Coming soon';

  @override
  String get navParentDelivery => 'Delivery and loading';

  @override
  String get navScreenNewOrder => 'New order';

  @override
  String get navScreenOrders => 'Order status';

  @override
  String get navScreenDeliveryOrders => 'Delivery orders';

  @override
  String get navScreenSalesOrders => 'Sales orders';

  @override
  String get navScreenCounter => 'Direct sale';

  @override
  String get navScreenQuotations => 'Quotations';

  @override
  String get navScreenCollections => 'Collections';

  @override
  String get navScreenDeliveries => 'Today\'s deliveries';

  @override
  String get navScreenLoading => 'Loading sheet';

  @override
  String get navScreenTracking => 'Delivery tracking';

  @override
  String get navScreenScan => 'Scan a paper';

  @override
  String get navScreenMyRoute => 'Today\'s route';

  @override
  String get navScreenLeads => 'Leads';

  @override
  String get navScreenToday => 'Today\'s figures';

  @override
  String get navScreenPurchases => 'Purchases';

  @override
  String get navScreenPrincipals => 'Principals';

  @override
  String get navScreenPrincipalCommission => 'Principal commission';

  @override
  String get navScreenProducts => 'Products';

  @override
  String get navScreenStock => 'Stock on hand';

  @override
  String get navScreenVouchers => 'Vouchers';

  @override
  String get navScreenAllVouchers => 'All vouchers';

  @override
  String get navScreenCashReceipt => 'Cash receipt';

  @override
  String get navScreenMfsReceipt => 'MFS receipt';

  @override
  String get navScreenBankReceipt => 'Bank receipt';

  @override
  String get navScreenPayment => 'Payment';

  @override
  String get navScreenContra => 'Contra';

  @override
  String get navScreenJournal => 'Journal';

  @override
  String get navScreenExpense => 'Expense';

  @override
  String get navScreenCustomers => 'Customers';

  @override
  String get navScreenDues => 'Dues list';

  @override
  String get navScreenApprovals => 'Approvals';

  @override
  String get navScreenAttendance => 'Attendance';

  @override
  String get navScreenClaims => 'Expense claims';

  @override
  String get navScreenReports => 'Reports';

  @override
  String get navScreenDashboards => 'Business dashboards';

  @override
  String get navScreenDeposits => 'Deposits and savings';

  @override
  String get navScreenBankLoans => 'Bank loans and instalments';

  @override
  String get navScreenRents => 'Rent';

  @override
  String get navScreenHandLoans => 'Hand loans';

  @override
  String get navScreenDocuments => 'Documents';

  @override
  String get navScreenOffers => 'Current offers';

  @override
  String get navScreenLeave => 'My leave';

  @override
  String get navScreenPayslips => 'Payslips';

  @override
  String get navScreenInvoices => 'Sales invoices';

  @override
  String get navScreenCancelledInvoices => 'Cancelled sales invoices';

  @override
  String get navScreenChallans => 'Delivery challans';

  @override
  String get navScreenOwnerCentre => 'Today — the whole group';

  @override
  String get navScreenCredit => 'Credit overview';

  @override
  String get navScreenNotices => 'Notice board';

  @override
  String get navScreenSyncStatus => 'Sync status';

  @override
  String get navScreenNotifications => 'Notifications';

  @override
  String get paperKindPurchaseOrder => 'Purchase order';

  @override
  String get paperKindPurchaseBill => 'Purchase bill';

  @override
  String get paperKindGoodsReceipt => 'Goods receipt';

  @override
  String get paperKindPurchaseReturn => 'Purchase return';

  @override
  String get paperKindRequisition => 'Purchase requisition';

  @override
  String get paperKindRfq => 'Request for quotation';

  @override
  String get paperKindSupplyContract => 'Supply contract';

  @override
  String get paperKindSalesReturn => 'Sales return';

  @override
  String get paperKindGatePass => 'Gate pass';

  @override
  String get paperKindTrip => 'Trip';

  @override
  String get paperKindLoadingSheet => 'Loading sheet';

  @override
  String get paperKindTransport => 'Transport';

  @override
  String get paperKindPriceCustomer => 'Customer prices';

  @override
  String get paperKindPriceTier => 'Tier prices';

  @override
  String get paperKindPriceTerritory => 'Territory prices';

  @override
  String get paperKindPriceAll => 'Special price — for everyone';

  @override
  String get paperKindStockTransfer => 'Stock transfer';

  @override
  String get paperKindInterCompany => 'Inter-company transaction';

  @override
  String get paperKindCapital => 'Capital';

  @override
  String get paperKindInsurance => 'Insurance policy';

  @override
  String get paperKindFallback => 'Paper';

  @override
  String get paperKindDeliveryOrder => 'Delivery order';

  @override
  String get paperKindSalesOrder => 'Sales order';

  @override
  String get paperKindQuotation => 'Quotation';

  @override
  String get paperKindCollection => 'Collection';

  @override
  String get paperKindPurchase => 'Purchase';

  @override
  String get paperKindVoucher => 'Voucher';

  @override
  String get paperKindClaim => 'Expense claim';

  @override
  String get paperKindDeposit => 'Deposit';

  @override
  String get paperKindBankLoan => 'Bank loan';

  @override
  String get paperKindRent => 'Rent';

  @override
  String get paperKindHandLoan => 'Hand loan';

  @override
  String get paperKindDocument => 'Document';

  @override
  String get paperKindPayslip => 'Payslip';

  @override
  String get paperKindInvoice => 'Sales invoice';

  @override
  String get paperKindChallan => 'Delivery challan';

  @override
  String get navTabHome => 'Home';

  @override
  String get navTabApprovals => 'Approvals';

  @override
  String get navTabReports => 'Reports';

  @override
  String get navTabOrders => 'Orders';

  @override
  String get navTabCollections => 'Collections';

  @override
  String get navTabChallans => 'Challans';

  @override
  String get navTabStock => 'Stock';

  @override
  String get navTabVouchers => 'Vouchers';

  @override
  String get navTabModules => 'All modules';

  @override
  String get navTabWaiting => 'waiting';

  @override
  String navTabSpokenNew(String label, String waiting) {
    return '$label, new items $waiting';
  }

  @override
  String navTabBadgeCount(String shown) {
    return '$shown';
  }

  @override
  String navTabBadgeOver(String shown) {
    return 'more than $shown';
  }

  @override
  String get navFabNew => 'New';

  @override
  String get navFabNewOrder => 'New order';

  @override
  String get navFabNewCollection => 'New collection';

  @override
  String get navFabNewClaim => 'New claim';

  @override
  String get navFabNewExpense => 'New expense';

  @override
  String get navFabNewVoucher => 'New voucher';

  @override
  String get navFabExpenseClaim => 'Expense claim';

  @override
  String get topBarBack => 'Back';

  @override
  String get topBarFullMenu => 'Full menu';

  @override
  String get topBarSearch => 'Search';

  @override
  String get topBarMyMenu => 'My menu';

  @override
  String get topBarSwitchBranch => 'Switch branch';

  @override
  String get topBarDemo => 'Demo';

  @override
  String get topBarMore => 'More';

  @override
  String topBarBranchSpoken(String branch) {
    return '$branch branch';
  }

  @override
  String get topBarDemoSpoken => 'Demo server, not the real books';

  @override
  String topBarOfflinePending(int count, String shown) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other:
          'Offline: $shown papers not sent yet, they will go by themselves when the network is back',
      one:
          'Offline: $shown paper not sent yet, it will go by itself when the network is back',
    );
    return '$_temp0';
  }

  @override
  String get topBarOfflineNoNet =>
      'Offline: no network, what you write will go by itself when the network is back';

  @override
  String get topBarUnsavedTitle => 'Switch without saving?';

  @override
  String get topBarUnsavedBody =>
      'A form has text that is not saved yet. Switching the branch will lose it.';

  @override
  String get topBarUnsavedStay => 'Stay';

  @override
  String get topBarUnsavedGo => 'Switch without saving';

  @override
  String get moduleGateOffTitle => 'This part is switched off';

  @override
  String get moduleGateOffMessage =>
      'Your company has switched this part off in the app. If you need it, ask the administrator to switch it on from the \"Mobile app\" tab of the control panel.';

  @override
  String get permissionCameraTitle => 'Camera permission';

  @override
  String get permissionCameraBody =>
      'ABOS uses the phone\'s camera to read QR codes and to take slip photos, receipt photos and your own profile photo. The phone will ask for camera permission next — tap \"Allow\". The camera never turns on unless you tap, and only the photo you send goes to the office.';

  @override
  String get permissionNotificationsTitle => 'Notification permission';

  @override
  String get permissionNotificationsBody =>
      'ABOS sends notifications so you hear about signature requests, approvals and deliveries even when the app is closed. The phone will ask for notification permission next — tap \"Allow\". Without it the app still works; you just see the news when you open the app.';

  @override
  String get permissionNotNow => 'Not now';

  @override
  String get permissionOk => 'OK';

  @override
  String get deviceIntegrityRootedEmulator =>
      'This phone seems to be rooted, and not a real phone (an emulator)';

  @override
  String get deviceIntegrityRooted => 'This phone seems to be rooted';

  @override
  String get deviceIntegrityEmulator =>
      'This seems not to be a real phone (an emulator)';

  @override
  String deviceIntegrityWarning(String what) {
    return '$what. On such a phone other apps can read ABOS\'s data — amounts and customer details are at risk. The app will keep working, but tell the office.';
  }

  @override
  String get lockDelayNow => 'Immediately';

  @override
  String lockDelayMinutes(int count, String shown) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$shown minutes',
      one: '$shown minute',
    );
    return '$_temp0';
  }

  @override
  String lockScreenPinLength(String min, String max) {
    return 'The PIN is $min to $max digits.';
  }

  @override
  String get lockScreenPinTwice => 'Type the same PIN twice.';

  @override
  String get lockScreenTooManyTries =>
      'Five wrong PINs — sign in with your password again.';

  @override
  String lockScreenPinWrong(int count, String shown) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Wrong PIN — $shown more tries left.',
      one: 'Wrong PIN — $shown more try left.',
    );
    return '$_temp0';
  }

  @override
  String get lockScreenBiometricReason =>
      'Use your fingerprint or phone PIN to open ABOS';

  @override
  String get lockScreenBiometricTitle => 'Open ABOS';

  @override
  String get lockScreenBiometricCancel => 'Cancel';

  @override
  String get lockScreenEnterPin => 'Enter the app\'s PIN';

  @override
  String get lockScreenSignOut => 'Sign out';

  @override
  String get lockScreenSignInAgainTitle => 'Sign in with your password again';

  @override
  String get lockScreenSignInAgainBody =>
      'This phone has no lock and no app PIN is set — there is no way to know who is holding it. You will be asked to set a PIN as soon as you sign in.';

  @override
  String get lockScreenSignOutAndIn => 'Sign out and sign in again';

  @override
  String get lockScreenSetPinTitle => 'Set an app PIN';

  @override
  String lockScreenSetPinBody(String min, String max) {
    return 'This phone has no lock. Set a $min–$max digit PIN to protect approvals and amounts — it will be asked for when you come back.';
  }

  @override
  String get lockScreenNewPin => 'New PIN';

  @override
  String get lockScreenPinAgain => 'Same PIN again';

  @override
  String get lockScreenSave => 'Save';

  @override
  String get lockScreenLockedTitle => 'App locked';

  @override
  String get lockScreenLockedBody => 'Open with your fingerprint or phone PIN.';

  @override
  String get networkErrorNoConnection =>
      'No connection. Check the internet and try again.';

  @override
  String get authIncompleteAnswer =>
      'The server sent an incomplete answer. Try again.';

  @override
  String get authSignInFailed =>
      'Could not sign in. Try again in a little while.';

  @override
  String get authLoginGreetingMorning => 'Good morning';

  @override
  String get authLoginGreetingAfternoon => 'Good afternoon';

  @override
  String get authLoginGreetingEvening => 'Good evening';

  @override
  String get authLoginSubtitle => 'Today\'s books are waiting for you';

  @override
  String get authLoginIdentifier => 'Login ID (name or email)';

  @override
  String get authLoginIdentifierMissing => 'Enter your name or email';

  @override
  String get authLoginPassword => 'Password';

  @override
  String get authLoginPasswordMissing => 'Enter your password';

  @override
  String get authLoginSignIn => 'Sign in';

  @override
  String get authLoginVerify => 'Verify';

  @override
  String get authLoginBack => 'Go back';

  @override
  String get authLoginRemember => 'Remember me';

  @override
  String get authLoginForgot => 'Forgot password?';

  @override
  String get authLoginForgotBody =>
      'Tap below to open the reset page in your browser — enter your email and a link will arrive. No email? Ask your company\'s administrator.';

  @override
  String get authLoginForgotOpen => 'Get a link by email';

  @override
  String get authLoginOk => 'OK';

  @override
  String authLoginMfaPrompt(String n) {
    return 'Enter the $n-digit code from your authenticator app.';
  }

  @override
  String get authLoginMfaWrong => 'That code was wrong — try again.';

  @override
  String get authLoginMfaLabel => 'MFA code';

  @override
  String authLoginMfaMissing(String n) {
    return 'Enter the $n-digit code';
  }

  @override
  String get authLoginVersion => 'Version';

  @override
  String get authLoginHidePassword => 'Hide password';

  @override
  String get authLoginShowPassword => 'Show password';

  @override
  String get profileMenuProfileAndPhoto => 'Profile and photo';

  @override
  String get profileMenuMyProfile => 'My profile';

  @override
  String get webInsidePageFailed =>
      'The page could not be opened. Try again in a little while.';

  @override
  String get webInsideCodeWrong => 'The code didn\'t match — enter it again.';

  @override
  String get webInsideCodeNeeded => 'Enter the two-step code.';

  @override
  String get webInsideServerTooOld =>
      'The server doesn\'t have this yet — ask the office to update the server.';

  @override
  String get webInsideBadAddress =>
      'This screen\'s address was not recognised — open it again from the menu.';

  @override
  String get webInsideNeedsCode =>
      'Web screens do not open without the two-step code — just like the web sign-in.';

  @override
  String get webInsideCodeTitle => 'Two-step code';

  @override
  String webInsideCodeHint(String n) {
    return 'The $n digits from the authenticator app';
  }

  @override
  String get webInsideLoadFailed =>
      'The page could not be loaded — check the connection and try again.';

  @override
  String get webInsideFileFetching => 'Fetching the file…';

  @override
  String get webInsideFileFetchFailed =>
      'The file could not be fetched — try again.';

  @override
  String get webInsideFileSaveFailed =>
      'The file could not be kept — check that the phone has room.';

  @override
  String get webInsideFileShare => 'Share or save';

  @override
  String get webInsidePrintFailed =>
      'Could not print — try again once the page has fully loaded.';

  @override
  String get webInsideTakePhoto => 'Take a photo';

  @override
  String get webInsideFromGallery => 'From the gallery';

  @override
  String get webInsidePickFile => 'Choose a file (PDF, Excel…)';

  @override
  String get webInsideFile => 'File';

  @override
  String get webInsideFilePickFailed =>
      'The file could not be taken — try again.';

  @override
  String get webInsideOk => 'OK';

  @override
  String get webInsideNo => 'No';

  @override
  String get webInsideYes => 'Yes';

  @override
  String get webInsideClose => 'Close';

  @override
  String get webInsidePrint => 'Print or PDF';

  @override
  String get webInsideReload => 'Reload';

  @override
  String get webInsideOpenFailedTitle => 'The page could not be opened';

  @override
  String get webInsideCancel => 'Cancel';

  @override
  String get commonNo => 'No';

  @override
  String get commonMore => 'More';

  @override
  String get commonAll => 'All';

  @override
  String get commonStatus => 'Status';

  @override
  String get commonBranch => 'Branch';

  @override
  String get commonLoadFailed => 'Could not load';

  @override
  String get commonSign => 'Sign';

  @override
  String get voucherTypeReceipt => 'Collection voucher';

  @override
  String get voucherTypePayment => 'Payment voucher';

  @override
  String get voucherTypeExpense => 'Expense voucher';

  @override
  String get voucherTypeJournal => 'Journal voucher';

  @override
  String get voucherTypeContra => 'Contra voucher';

  @override
  String voucherLineFrom(String account) {
    return 'From: $account';
  }

  @override
  String voucherLineInto(String account) {
    return 'Into: $account';
  }

  @override
  String get voucherFactFrom => 'From';

  @override
  String get voucherFactTo => 'To';

  @override
  String get voucherFactDepositedTo => 'Deposited to';

  @override
  String get voucherFactPaidFrom => 'Paid from';

  @override
  String get voucherFactMoneyAccount => 'Money account';

  @override
  String get voucherPartyReceivedFrom => 'Received from';

  @override
  String get voucherPartyPaidTo => 'Paid to';

  @override
  String get voucherPartyParty => 'Party';

  @override
  String voucherState(String state) {
    String _temp0 = intl.Intl.selectLogic(
      state,
      {
        'awaiting': 'Awaiting signature',
        'posted': 'Posted',
        'cancelled': 'Cancelled',
        'other': 'Draft',
      },
    );
    return '$_temp0';
  }

  @override
  String get voucherViewAll => 'All';

  @override
  String get voucherViewCashReceipts => 'Cash receipts';

  @override
  String get voucherViewBankReceipts => 'Bank receipts';

  @override
  String get voucherViewPayments => 'Payments';

  @override
  String get voucherViewMfsReceipts => 'MFS receipts';

  @override
  String get voucherViewContras => 'Contra';

  @override
  String get voucherViewJournals => 'Journal';

  @override
  String get voucherViewExpenses => 'Expenses';

  @override
  String get voucherViewAllReceipts => 'All receipts';

  @override
  String offerBenefitPercent(String amount) {
    return '$amount% discount';
  }

  @override
  String offerBenefitAmount(String amount) {
    return '$amount discount';
  }

  @override
  String offerBenefitFree(String count) {
    return '$count free';
  }

  @override
  String offerBenefitFreeGift(String count, String gift) {
    return '$count $gift free';
  }

  @override
  String offerBenefitCredit(String amount) {
    return '$amount returned as credit on the next purchase';
  }

  @override
  String offerBenefitPoints(String amount) {
    return '$amount points';
  }

  @override
  String offerBenefitCap(String benefit, String cap) {
    return '$benefit (at most $cap per bill)';
  }

  @override
  String offerRangeOrMore(String from) {
    return '$from or more';
  }

  @override
  String offerRangeUpTo(String to) {
    return 'up to $to';
  }

  @override
  String offerQuantityUnit(String count) {
    return '$count';
  }

  @override
  String offerWhenBuying(String what) {
    return 'when buying $what';
  }

  @override
  String get offerAnyQuantity => 'on any quantity';

  @override
  String get offerAnyAmount => 'on any amount';

  @override
  String get offerSetProduct => 'when buying a set product';

  @override
  String get offerSetTime => 'at set times';

  @override
  String offerTimeWindow(String from, String to) {
    return 'when buying between $from–$to';
  }

  @override
  String offerGoodsCategory(String name) {
    return '$name (category)';
  }

  @override
  String offerGoodsBrand(String name) {
    return '$name (brand)';
  }

  @override
  String get offerGoodsAll => 'on all goods';

  @override
  String offerForShops(String names) {
    return 'Shops: $names';
  }

  @override
  String offerForShopTypes(String names) {
    return 'Shop types: $names';
  }

  @override
  String offerForAreas(String names) {
    return 'Areas: $names';
  }

  @override
  String approvalCardDiscount(String amount) {
    return 'Discount $amount';
  }

  @override
  String get approvalDocPurchaseOrder => 'Purchase order';

  @override
  String get approvalDocPurchaseBill => 'Purchase bill';

  @override
  String get approvalDocPurchaseReturn => 'Purchase return';

  @override
  String get approvalDocPayment => 'Payment';

  @override
  String get approvalDocStockTransfer => 'Stock transfer';

  @override
  String get approvalDocPayroll => 'Payroll';

  @override
  String get approvalDocVoucher => 'Voucher';

  @override
  String get approvalDocDeliveryOrder => 'Delivery order';

  @override
  String get approvalDocSalesOrder => 'Sales order';

  @override
  String get approvalDocSalesInvoice => 'Sales invoice';

  @override
  String get approvalDocDeliveryChallan => 'Delivery challan';

  @override
  String get approvalDocSalesQuotation => 'Sales quotation';

  @override
  String get approvalDocSalesReturn => 'Sales return';

  @override
  String get approvalDocCollection => 'Collection';

  @override
  String get approvalDocExpenseClaim => 'Expense claim';

  @override
  String get approvalDocNote => 'Debit or credit note';

  @override
  String get approvalDocMoneyTransfer => 'Money transfer';

  @override
  String get approvalDocPromotion => 'Offer';

  @override
  String get approvalDocCustomerCreditRequest => 'Credit-limit raise request';

  @override
  String get approvalDocUnknown => 'Document';

  @override
  String attendanceStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'present': 'Present',
        'absent': 'Absent',
        'leave': 'On leave',
        'holiday': 'Holiday',
        'other': '$status',
      },
    );
    return '$_temp0';
  }

  @override
  String get customerNoName => 'No name';

  @override
  String get customerDueNone => 'No due';

  @override
  String customerDueAdvance(String amount) {
    return 'Advance $amount';
  }

  @override
  String customerDueAmount(String amount) {
    return 'Due $amount';
  }

  @override
  String get listSortNameAz => 'Name: A to Z';

  @override
  String get listSortNameZa => 'Name: Z to A';

  @override
  String get listSortCode => 'By code';

  @override
  String get listFilterStatus => 'Status';

  @override
  String get listFilterActive => 'Active';

  @override
  String get listFilterInactive => 'Inactive';

  @override
  String get listFilterMobile => 'Mobile number';

  @override
  String get listFilterYes => 'Yes';

  @override
  String get listFilterNo => 'No';

  @override
  String get listFilterType => 'Type';

  @override
  String get listSortDueHigh => 'Due: highest first';

  @override
  String get listSortDueLow => 'Due: lowest first';

  @override
  String get listSortLimitUsed => 'Limit used: highest first';

  @override
  String get listFilterCreditLimit => 'Credit limit';

  @override
  String get listFilterOverLimit => 'Over the limit';

  @override
  String get listFilterWithinLimit => 'Within the limit';

  @override
  String get listFilterNoLimit => 'No limit';

  @override
  String get listFilterCreditTerms => 'Credit terms';

  @override
  String get listFilterNoTerms => 'No terms';

  @override
  String get listSortPriceHigh => 'Price: highest first';

  @override
  String get listSortPriceLow => 'Price: lowest first';

  @override
  String get listFilterSalePrice => 'Sale price';

  @override
  String get listFilterHasPrice => 'Has a price';

  @override
  String get listFilterNoPrice => 'No price';

  @override
  String get listFilterStock => 'Stock';

  @override
  String get listFilterSellable => 'Sellable in stock';

  @override
  String get listFilterOutOfStock => 'Out of stock';

  @override
  String get listSortQtyHigh => 'Quantity: highest first';

  @override
  String get listSortQtyLow => 'Quantity: lowest first';

  @override
  String get listFilterZeroStock => 'Zero stock';

  @override
  String get listFilterHide => 'Hide';

  @override
  String get listFilterOnlyZero => 'Only zero';

  @override
  String get listFilterCommitted => 'On order or held';

  @override
  String get listFilterFreeGoods => 'Free goods';

  @override
  String get listSortDateNew => 'Date: newest first';

  @override
  String get listSortDateOld => 'Date: oldest first';

  @override
  String get listSortAmountHigh => 'Amount: highest first';

  @override
  String get listSortAmountLow => 'Amount: lowest first';

  @override
  String get listSortShopAz => 'Shop: A to Z';

  @override
  String listFilterCreditDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days',
      one: '$days day',
    );
    return '$_temp0';
  }

  @override
  String get listFilter => 'Filter';

  @override
  String get listSort => 'Sort';

  @override
  String get listFilterAll => 'All';

  @override
  String get listFilterClearAll => 'Clear all';

  @override
  String get listFilterApply => 'Apply';

  @override
  String moneySpokenCrore(String n) {
    return '$n crore';
  }

  @override
  String moneySpokenLakh(String n) {
    return '$n lakh';
  }

  @override
  String moneySpokenThousand(String n) {
    return '$n thousand';
  }

  @override
  String get moneySpokenMinus => 'minus';

  @override
  String moneySpokenTaka(String amount) {
    return '$amount taka';
  }

  @override
  String moneySpokenPaisa(String n) {
    return '$n paisa';
  }

  @override
  String get commissionSidePay => 'Payable';

  @override
  String get commissionSideReceive => 'Receivable';

  @override
  String get commissionSideSettled => 'Settled';

  @override
  String get commissionSidePaySentence => 'Payable · to pay';

  @override
  String get commissionSideReceiveSentence =>
      'Receivable · the company owes us';

  @override
  String commissionLoss(String amount) {
    return 'Loss $amount — sold below cost';
  }

  @override
  String get dayGreetingNight => 'Good night';

  @override
  String get dayGreetingMorning => 'Good morning';

  @override
  String get dayGreetingNoon => 'Good afternoon';

  @override
  String get dayGreetingAfternoon => 'Good afternoon';

  @override
  String get dayGreetingEvening => 'Good evening';

  @override
  String get dayPartNight => 'night';

  @override
  String get dayPartDawn => 'dawn';

  @override
  String get dayPartMorning => 'morning';

  @override
  String get dayPartNoon => 'noon';

  @override
  String get dayPartAfternoon => 'afternoon';

  @override
  String get dayPartEvening => 'evening';

  @override
  String get monthJanuary => 'January';

  @override
  String get monthFebruary => 'February';

  @override
  String get monthMarch => 'March';

  @override
  String get monthApril => 'April';

  @override
  String get monthMay => 'May';

  @override
  String get monthJune => 'June';

  @override
  String get monthJuly => 'July';

  @override
  String get monthAugust => 'August';

  @override
  String get monthSeptember => 'September';

  @override
  String get monthOctober => 'October';

  @override
  String get monthNovember => 'November';

  @override
  String get monthDecember => 'December';

  @override
  String get reportNoRows => 'No rows';

  @override
  String reportRangeOf(String total, String first, String last) {
    return '$first–$last of $total';
  }

  @override
  String get salesOrderUnknownCustomer => 'Unknown customer';

  @override
  String salesOrderStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'draft': 'Draft',
        'confirmed': 'Confirmed',
        'cancelled': 'Cancelled',
        'closed': 'Closed',
        'other': '$status',
      },
    );
    return '$_temp0';
  }

  @override
  String get todayTotal => 'Total';

  @override
  String get todayBilledToday => 'Billed today';

  @override
  String todaySalesBilledReturned(String billed, String returned) {
    return 'Billed $billed · Returns $returned';
  }

  @override
  String todaySalesCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count sales',
      one: '$count sale',
    );
    return '$_temp0';
  }

  @override
  String todayBalanceToPay(String amount) {
    return 'To pay: $amount';
  }

  @override
  String todayBalanceOwedToUs(String amount) {
    return 'The company owes us: $amount';
  }

  @override
  String get syncModuleSales => 'Sales';

  @override
  String get syncModulePurchase => 'Purchase';

  @override
  String get syncModuleInventory => 'Stock';

  @override
  String get syncModuleAccounts => 'Accounts';

  @override
  String get syncModuleFinance => 'Finance';

  @override
  String get syncModuleCustomer => 'Customers';

  @override
  String get syncModuleSupplier => 'Suppliers';

  @override
  String get syncModuleHr => 'People';

  @override
  String get syncEntityCustomer => 'Customers';

  @override
  String get syncEntitySupplier => 'Suppliers';

  @override
  String get syncEntityProduct => 'Products';

  @override
  String get syncEntityProductPrice => 'Product prices';

  @override
  String get syncEntitySalesOrder => 'Orders';

  @override
  String get syncEntityCollection => 'Collections';

  @override
  String get syncEntityCustomerDue => 'Shop dues';

  @override
  String get syncEntityStockOnHand => 'Stock on hand';

  @override
  String get syncUnknownReason => 'Unknown reason';

  @override
  String syncNoAnswer(int attempts) {
    String _temp0 = intl.Intl.pluralLogic(
      attempts,
      locale: localeName,
      other: '$attempts tries',
      one: '$attempts try',
    );
    return 'The server did not answer this change ($_temp0) — check the list and write it again yourself.';
  }

  @override
  String syncSendFailedRepeatedly(int attempts) {
    String _temp0 = intl.Intl.pluralLogic(
      attempts,
      locale: localeName,
      other: '$attempts tries',
      one: '$attempts try',
    );
    return 'Sending failed again and again ($_temp0) — write it again yourself.';
  }

  @override
  String get syncNoReasonGiven => 'No reason was given.';

  @override
  String get syncOfflinePull =>
      'No connection — pull down again once you are connected.';

  @override
  String get syncOfflinePush =>
      'No connection — it will go by itself once you are connected.';

  @override
  String syncServerRefuses(String code) {
    return 'The server is refusing the request (code $code)';
  }

  @override
  String syncServerSays(String message, String code) {
    return 'The server says: $message (code $code)';
  }

  @override
  String syncRefusalTellOfficePull(String what) {
    return '$what — tell the office; pulling again will not fix it.';
  }

  @override
  String syncRefusalTellOfficePush(String what) {
    return '$what — tell the office; waiting will not fix it.';
  }

  @override
  String get syncPullUnknown =>
      'Could not fetch the list — the reason is not known. Tell the office.';

  @override
  String get syncPushUnknown =>
      'Could not send — the reason is not known. Tell the office.';

  @override
  String get syncStatusRunning => 'Syncing…';

  @override
  String get syncStatusOffline => 'No network — work is kept on the phone';

  @override
  String get syncStatusFailedNever => 'Not synced — pull to try again';

  @override
  String get syncStatusNever => 'Not synced yet';

  @override
  String syncStatusLast(String ago) {
    return 'Last sync: $ago';
  }

  @override
  String syncStatusLastFailed(String ago) {
    return 'Last sync: $ago · last try failed';
  }

  @override
  String get syncAgoJustNow => 'just now';

  @override
  String syncAgoMinutes(int count, String digits) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$digits minutes ago',
      one: '$digits minute ago',
    );
    return '$_temp0';
  }

  @override
  String syncAgoHours(int count, String digits) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$digits hours ago',
      one: '$digits hour ago',
    );
    return '$_temp0';
  }

  @override
  String syncAgoDays(int count, String digits) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$digits days ago',
      one: '$digits day ago',
    );
    return '$_temp0';
  }

  @override
  String get overviewConfirm => 'Confirm';

  @override
  String get overviewKeepDraft => 'Keep as draft';

  @override
  String get sheetGoBack => 'Go back';

  @override
  String get stateLoading => 'Loading';

  @override
  String totalsThisPage(String count) {
    return 'This page · $count';
  }

  @override
  String totalsAll(String count) {
    return 'Grand total · $count';
  }

  @override
  String get unsavedTitle => 'Leave without sending?';

  @override
  String get unsavedBody =>
      'What you wrote has not been sent yet — it will be lost if you leave.';

  @override
  String get unsavedStay => 'Stay';

  @override
  String get unsavedLeave => 'Leave';

  @override
  String workspacePendingOrders(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count orders on this phone have not been sent yet.',
      one: '$count order on this phone has not been sent yet.',
    );
    return '$_temp0 Send them first (see the sync status), then change the company or branch — otherwise they will be filed under the new place.';
  }

  @override
  String get workspaceServerTooOld =>
      'The server does not yet know how to switch company from the app. You can once the server is updated; until then switch on the web.';

  @override
  String get workspaceNoCompanyAccess =>
      'You do not have access to this company.';

  @override
  String get workspaceBranchOutOfReach =>
      'Could not move to this branch — it is not in your reach, or not in this company.';

  @override
  String get workspaceSwitchFailed =>
      'Could not switch. Try again in a little while.';

  @override
  String get homeOrderStatus => 'Order status';

  @override
  String get homeDueList => 'Due list';

  @override
  String get homeApprovals => 'Approvals';

  @override
  String get homePrincipalCommission => 'Principal commission';

  @override
  String get homeDashboards => 'Business dashboards';

  @override
  String get homeDashboardsHint =>
      'Stock, sales, accounts — the same numbers as the web';

  @override
  String get homeFavourites => 'Favourites';

  @override
  String get homeFavouritesEmpty =>
      'Press and hold a row in any module menu — it will sit here (up to six).';

  @override
  String get homeRecent => 'Recent';

  @override
  String get homeToday => 'Today';

  @override
  String get homeUnsafePhoneTitle => 'This phone is not safe';

  @override
  String get homeUnderstood => 'Got it';

  @override
  String get homeAllBranches => 'All branches';

  @override
  String homeFavouriteAdded(String name) {
    return '\"$name\" added to favourites';
  }

  @override
  String homeFavouriteRemoved(String name) {
    return '\"$name\" removed from favourites';
  }

  @override
  String get homeFavouritesFull =>
      'Favourites hold at most six — remove one first (press and hold it on the home).';

  @override
  String homeQueuedCount(String count) {
    return '$count waiting';
  }

  @override
  String get homeAllModules => 'All modules';

  @override
  String get homeMe => 'Me';

  @override
  String homeModuleComingSoon(String module) {
    return 'The $module screens have not reached the phone yet — the rows below are coming soon.';
  }

  @override
  String get homeSyncStatus => 'Sync status';

  @override
  String get homeSyncStatusHint =>
      'What went, what did not, what was sent back';

  @override
  String get homePrinter => 'Printer';

  @override
  String get homePrinterHint =>
      'Bluetooth receipt printer, roll size, test print';

  @override
  String get homeSignOut => 'Sign out';

  @override
  String get homeSignOutTitle => 'Sign out?';

  @override
  String homeSignOutPending(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count of your orders on this phone have not been sent yet.',
      one: '$count of your orders on this phone has not been sent yet.',
    );
    return '$_temp0 They stay on the phone after you sign out, and go only when you sign in again — never under someone else\'s name.';
  }

  @override
  String get homeSignOutPassword =>
      'You will need your password to sign in again.';

  @override
  String get homeStay => 'Stay';

  @override
  String get homeLockDelay => 'App lock time';

  @override
  String homeLockDelayHint(String delay) {
    return 'After this long away, coming back needs your fingerprint or PIN: $delay';
  }

  @override
  String get homeNoMenu => 'No menu for you';

  @override
  String get homeNoMenuHint =>
      'Tell the office — your account has no permissions set.';

  @override
  String get homeComingSoon => 'Coming soon';

  @override
  String get homeWebScreen => 'Web screen';

  @override
  String get homeSearchHint =>
      'Search: \"delivery challan\", \"Rahim Traders\"…';

  @override
  String get homeSearchClear => 'Clear';

  @override
  String get homeSearchHelp =>
      'Type a screen name (\"order\", \"delivery challan\", \"stock\") or a customer name or mobile.';

  @override
  String homeSearchNothing(String query) {
    return '\"$query\" — nothing found.';
  }

  @override
  String get homeSearchScreens => 'Screens';

  @override
  String get homeSearchCustomers => 'Customers';

  @override
  String get homeNotices => 'Notices';

  @override
  String get homePaper => 'Paper';

  @override
  String get homePaperCannotOpen => 'This paper cannot be opened in this app';

  @override
  String get homeUpdateAndRetry => 'Update the app and try again.';

  @override
  String get workspacePickerTitle => 'Company and branch';

  @override
  String get workspacePickerClose => 'Close';

  @override
  String get workspacePickerCompany => 'Company';

  @override
  String get workspacePickerBranch => 'Branch';

  @override
  String workspacePickerBranchOf(String company) {
    return 'Branch — $company';
  }

  @override
  String get workspacePickerNote =>
      'Whatever you write is filed under the chosen branch. \"All branches\" is for viewing only — it does not change your working branch.';

  @override
  String get homeNoBranch => 'No branch';

  @override
  String get homeNotKnown => 'not known';

  @override
  String get homeMfsAccountsHint => 'See the MFS accounts';

  @override
  String get homeCashInHand => 'Cash in hand';

  @override
  String get homeInBank => 'In bank';

  @override
  String get homeInTransit => 'In transit';

  @override
  String get homeCameInToday => 'In today';

  @override
  String homeCollectionAmount(String amount) {
    return 'Collection $amount';
  }

  @override
  String get homeWentOutToday => 'Out today';

  @override
  String get homeMarketDues => 'Market dues';

  @override
  String homeShopCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count shops',
      one: '$count shop',
    );
    return '$_temp0';
  }

  @override
  String get homeCashAndBankTotal => 'Total in hand and bank';

  @override
  String get homeMfsAccounts => 'MFS accounts';

  @override
  String get homeAwaitingSignature => 'Awaiting signature';

  @override
  String homeAwaitingSignatureCount(String count) {
    return 'Awaiting signature · $count';
  }

  @override
  String get homeSeeAll => 'See all';

  @override
  String get homeWaitingLoadFailed =>
      'Could not fetch the papers to sign. Check the network and try again.';

  @override
  String get homeNothingWaiting => 'Nothing is awaiting signature now.';

  @override
  String get homeDetails => 'Details';

  @override
  String homeHandLoansIncluded(String amount) {
    return 'Including hand loans of $amount';
  }

  @override
  String get homePayable => 'Payable';

  @override
  String homeLossAmount(String amount) {
    return 'Loss $amount';
  }

  @override
  String homeCommissionAmount(String amount) {
    return 'Commission $amount';
  }

  @override
  String homePrincipalDetail(String inflow, String commission) {
    return 'In $inflow · $commission';
  }

  @override
  String get reportLoadFailed => 'Could not fetch the report.';

  @override
  String get reportTitle => 'Reports';

  @override
  String get reportLoadFailedTitle => 'Could not fetch the report';

  @override
  String get reportNotOpenForYou => 'This report is not open to you';

  @override
  String get reportNotOpenHint =>
      'You do not have the permission for this report, or this module is off on phones. Ask the office for it if you need it.';

  @override
  String get reportListLoadFailed => 'Could not fetch the list of reports.';

  @override
  String get reportServerTooOld =>
      'Reports are not on this server yet — the app is newer than the server. Tell the office.';

  @override
  String get reportSearchHint => 'Search by report name';

  @override
  String get reportListFailedTitle => 'Could not fetch the list';

  @override
  String get reportNoneForYou => 'No reports for you';

  @override
  String get reportNoMatch => 'No match found';

  @override
  String get reportDateRangeHelp => 'From which date to which date';

  @override
  String get reportDateRangeShow => 'Show';

  @override
  String get reportPdfTooltip => 'PDF — view, print, send';

  @override
  String get reportChoiceAll => 'All';

  @override
  String reportChoice(String label, String value) {
    return '$label: $value';
  }

  @override
  String get reportNoRowsInPeriod => 'No rows in this period';

  @override
  String reportChoiceSearch(String what) {
    return 'Search $what';
  }

  @override
  String get reportTotal => 'Total';

  @override
  String get reportPrevious => 'Previous';

  @override
  String get reportNext => 'Next';

  @override
  String reportPageOf(String page, String last) {
    return 'Page $page / $last';
  }

  @override
  String get syncScreenPending => 'Waiting';

  @override
  String get syncScreenAllSent => 'Everything has been sent';

  @override
  String get syncScreenWaitingConnection =>
      'Waiting for a connection — kept on this phone';

  @override
  String get syncScreenRejectedHeader => 'What did not go — with the reason';

  @override
  String get syncScreenNothingRejected => 'Nothing rejected';

  @override
  String get syncScreenLastSync => 'Last sync';

  @override
  String get syncScreenNoInfo => 'No information found';

  @override
  String get syncScreenNever => 'Never';

  @override
  String get syncScreenConflicts => 'Conflicts';

  @override
  String get syncScreenNoConflicts => 'No conflicts';

  @override
  String get syncScreenNeedsResolution => 'Needs resolving';

  @override
  String get syncQueueBusy =>
      'The background sync is sending the queue now — it will open here in a moment. Nothing was deleted.';

  @override
  String get syncQueueKeyUnavailable =>
      'The phone secure store did not open — the waiting orders are kept on the phone. Close the app and open it again.';

  @override
  String get syncQueueQuarantined =>
      'The waiting queue file did not open — it was set aside, not deleted. New orders are going; tell the office about the earlier ones.';

  @override
  String get syncQueueBroken =>
      'The waiting queue file did not open — it was not deleted, and sync is stopped. Tell the office.';

  @override
  String get todayLoadFailed => 'Could not fetch today\'s figures.';

  @override
  String get todayServerTooOld =>
      'Today\'s figures are not on this server yet — the app is newer than the server. Tell the office.';

  @override
  String get todayPullFailed =>
      'Not synced — check the connection and pull again. The phone\'s work stays on the phone.';

  @override
  String todayStaleNotice(String error) {
    return '$error The figures below were kept on this phone.';
  }

  @override
  String get todayInflowCollections => 'Inflow / collections today';

  @override
  String get todayCollections => 'Today\'s collections';

  @override
  String todayCollectionCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count collections',
      one: '$count collection',
    );
    return '$_temp0';
  }

  @override
  String get todayRecoverable => 'Recoverable';

  @override
  String get todayPayable => 'Payable';

  @override
  String get todayFetching => 'Fetching…';

  @override
  String todayFiguresAsOf(String stamp) {
    return 'Figures as of $stamp';
  }

  @override
  String get todayCollectionsAndDues => 'Collections and dues';

  @override
  String get todayTotalDues => 'Total dues';

  @override
  String get todayPartCash => 'Cash';

  @override
  String get todayPartBank => 'Bank';

  @override
  String todayPeriod(String period) {
    return 'Period: $period';
  }

  @override
  String get todayTotalInflow => 'Total inflow';

  @override
  String todayCommissionRate(String rate) {
    return 'Commission ($rate)';
  }

  @override
  String get todaySentToPrincipal => 'Sent to the principal';

  @override
  String get todayInflowLeft => 'Inflow left';

  @override
  String todayDocumentsAwaitingDecision(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count documents waiting for your decision',
      one: '$count document waiting for your decision',
    );
    return '$_temp0';
  }

  @override
  String get todayScreenTitle => 'Today\'s figures';

  @override
  String get updateTileTitle => 'App update';

  @override
  String updateRunningVersion(String version) {
    return 'Running $version';
  }

  @override
  String get updateLatest => 'You are on the latest version.';

  @override
  String get updateOffline =>
      'Could not reach the server. Check the internet connection and tap again.';

  @override
  String get updateNewVersion => 'A new version is out.';

  @override
  String updateNewVersionNamed(String version) {
    return 'A new version is out ($version).';
  }

  @override
  String get updateNow => 'Update now';

  @override
  String get updateDownloadNew => 'Download the new version';

  @override
  String get updateNotInstallable =>
      'This update cannot be installed from inside the app. Tell the office.';

  @override
  String get updateNeedsPermission =>
      'This app is not allowed to install updates. In settings turn on \"Allow from this source\", then come back and tap again.';

  @override
  String get updateOpenSettings => 'Open settings';

  @override
  String get updateChecking => 'Checking…';

  @override
  String get updateDownloading => 'Downloading…';

  @override
  String updateDownloadingPercent(String percent) {
    return 'Downloading… $percent%';
  }

  @override
  String get updateVerifying => 'Checking the file…';

  @override
  String updateNotEnoughSpace(String megabytes) {
    return 'Not enough space on the phone. Free at least ${megabytes}MB and try again.';
  }

  @override
  String get updateIncomplete =>
      'The downloaded file is incomplete, so it was not installed. Try again.';

  @override
  String get updateMismatch =>
      'The downloaded file did not match the file the office gave, so it was not installed. The file was deleted. Try again; if it still does not match, tell the office.';

  @override
  String get updateForeignPackage =>
      'The downloaded file is not an app signed by ABOS itself, so it was not installed. The file was deleted. Tell the office — the wrong file is on the server.';

  @override
  String get updateNotNewer =>
      'The downloaded file is not newer than the app on this phone, so it was not installed — an older version cannot be put back. The file was deleted. Tell the office.';

  @override
  String get updateDownloadOffline =>
      'Could not download. Check the internet connection and try again.';

  @override
  String updateInstallerRefused(String detail) {
    return 'The file was downloaded and checked, but the phone would not open the install screen. In settings turn on \"Install unknown apps\" for this app and tap again; if it still fails, send this text to the office: $detail';
  }

  @override
  String get updateFailed => 'Could not install the update. Try again.';

  @override
  String get updateDismiss => 'Dismiss';

  @override
  String get updateWallTitle => 'This version can no longer be used';

  @override
  String get updateWallBody =>
      'Download and install the new version of the app. The old version may show wrong figures.';

  @override
  String updateWallPending(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count orders on this phone have not been sent yet',
      one: '$count order on this phone has not been sent yet',
    );
    return '$_temp0';
  }

  @override
  String get updateWallKeepOrders =>
      'Uninstalling the app loses them. Do not remove the old version when installing the new one — install over it, and the orders stay.';

  @override
  String get ordersModuleSales => 'Sales';

  @override
  String get ordersUnknownCustomer => 'Unknown customer';

  @override
  String get ordersRejectedFailed => 'Failed';

  @override
  String ordersRejectedItemCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count products',
      one: '$count product',
    );
    return '$_temp0';
  }

  @override
  String get ordersRejectedDismiss => 'Got it, remove';

  @override
  String get ordersRejectedRewrite => 'Write it again';

  @override
  String ordersDepositStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'pending': 'Sent',
        'verifying': 'Being checked',
        'accepted': 'Accepted',
        'rejected': 'Rejected',
        'other': 'Being checked',
      },
    );
    return '$_temp0';
  }

  @override
  String get ordersTrackingStepOrdered => 'Order received';

  @override
  String get ordersTrackingStepCreditHold => 'Held at the credit limit';

  @override
  String get ordersTrackingStepDraft => 'Draft';

  @override
  String get ordersTrackingStepApproval => 'Awaiting approval';

  @override
  String get ordersTrackingStepWarehouse => 'In the warehouse';

  @override
  String get ordersTrackingStepGateOut => 'Out of the gate';

  @override
  String get ordersTrackingStepPartial => 'Partly delivered';

  @override
  String get ordersTrackingStepDelivered => 'Delivered';

  @override
  String get ordersTrackingStepCancelled => 'Cancelled';

  @override
  String get ordersTrackingStepBilled => 'Billed';

  @override
  String get ordersListTitle => 'Order status';

  @override
  String get ordersListLoadFailed => 'Could not load the order list';

  @override
  String get ordersListEmptyTitle => 'No orders yet';

  @override
  String get ordersListEmptyMessage => 'Pull down to try again.';

  @override
  String get ordersNewOrder => 'New order';

  @override
  String ordersListPendingHeader(String count) {
    return 'Waiting ($count)';
  }

  @override
  String get ordersListPendingPill => 'Waiting';

  @override
  String get ordersListPendingNote =>
      'It goes out as soon as there is a connection — the order is safe on this phone.';

  @override
  String ordersListRejectedHeader(String count) {
    return 'Not accepted ($count)';
  }

  @override
  String ordersListSyncedHeader(String count) {
    return 'Synced ($count)';
  }

  @override
  String get ordersListFilterEmpty => 'No orders match this filter.';

  @override
  String get ordersListNoNumber => 'No number';

  @override
  String get ordersTrackingLoadFailed =>
      'Could not load the list. Pull down to try again.';

  @override
  String get ordersTrackingAbsent =>
      'Delivery tracking is not on the server yet — tell the office.';

  @override
  String get ordersTrackingTitle => 'Delivery tracking';

  @override
  String get ordersTrackingSearchHint => 'Sale number, DO or shop';

  @override
  String get ordersSearch => 'Search';

  @override
  String get ordersTrackingEmptyTitle => 'No sales.';

  @override
  String get ordersTrackingEmptyFiltered => 'Nothing matches this filter.';

  @override
  String get ordersTrackingEmptyHint =>
      'A sale appears here once its gate pass is done.';

  @override
  String get ordersTrackingShowAll => 'Show all';

  @override
  String get ordersTrackingReload => 'Check again';

  @override
  String get ordersTrackingNoCustomer => 'No customer';

  @override
  String get ordersTrackingBackOrder => 'The rest goes later';

  @override
  String get ordersTrackingStoryFailed => 'Could not load the timeline.';

  @override
  String get ordersTrackingStoryWho => 'Who did what, and when';

  @override
  String get counterReturnLoadFailed =>
      'Could not load the bills for returns. Check the connection and try again.';

  @override
  String get counterReturnBillFailed => 'Could not open the bill';

  @override
  String get counterTryAgain => 'Try again.';

  @override
  String get counterReturnQtyUnreadable =>
      'Could not read the quantity returned — type numbers only.';

  @override
  String get counterReturnPickReason => 'Choose a reason.';

  @override
  String get counterReturnNeedQty =>
      'Give a quantity returned for at least one item.';

  @override
  String get counterReturnDraftKeptTitle => 'Returns draft kept';

  @override
  String get counterReturnDraftKeptText =>
      'Not confirmed — it stays as a draft in the web returns list.';

  @override
  String get counterReturnDone => 'Returned';

  @override
  String get counterAwaitingSignature => 'Awaiting signature';

  @override
  String get counterReturnFailed => 'Not returned';

  @override
  String get counterOk => 'OK';

  @override
  String get counterReturnTitle => 'Sales return';

  @override
  String get counterReturnWhichBill => 'Returns against which bill';

  @override
  String get counterReturnEmptyTitle =>
      'No confirmed bill that can be returned.';

  @override
  String get counterReturnEmptyHint => 'A bill shows here once it is posted.';

  @override
  String get counterReload => 'Check again';

  @override
  String counterReturnBillNo(String no) {
    return 'Bill $no';
  }

  @override
  String counterLotNo(String lot) {
    return 'Lot: $lot';
  }

  @override
  String counterReturnSold(String qty, String rate) {
    return 'Sold: $qty × $rate';
  }

  @override
  String get counterReturnQtyLabel => 'Quantity returned';

  @override
  String get counterReturnReasonLabel => 'Why returned';

  @override
  String get counterNoteLabel => 'Details (optional)';

  @override
  String get counterConfirm => 'Confirm';

  @override
  String get counterReturnOtherBill => 'Choose another bill';

  @override
  String get ordersFilter => 'Filter';

  @override
  String get ordersFilterOffer => 'Has an offer';

  @override
  String get ordersFilterBoughtBefore => 'This shop bought it before';

  @override
  String get ordersSale => 'Sale';

  @override
  String get ordersFree => 'Free';

  @override
  String get ordersKeypadCarton => 'Carton';

  @override
  String ordersKeypadCartonFactor(String one, String carton, String factor) {
    return '$one $carton = $factor pcs';
  }

  @override
  String get ordersKeypadPieces => 'Pieces';

  @override
  String get ordersKeypadTotalPieces => 'Total pieces';

  @override
  String ordersKeypadLikeLast(String qty) {
    return 'Same as last time ($qty)';
  }

  @override
  String get ordersKeypadBackspace => 'Delete';

  @override
  String get ordersKeypadAddToCart => 'Add to cart';

  @override
  String ordersKeypadLineAmount(String amount) {
    return 'This line $amount';
  }

  @override
  String get ordersKeypadEitherUnit =>
      'Type cartons or pieces, whichever you like';

  @override
  String get ordersSortCatalogue => 'Catalogue order';

  @override
  String get ordersSortName => 'By name';

  @override
  String get ordersSortPrice => 'By price';

  @override
  String get ordersFilterClearAll => 'Clear all';

  @override
  String get ordersFilterShowHeading => 'Show';

  @override
  String get ordersFilterSortHeading => 'Sort';

  @override
  String ordersFilterApply(int n, String count) {
    String _temp0 = intl.Intl.pluralLogic(
      n,
      locale: localeName,
      other: 'Show $count products',
      one: 'Show $count product',
    );
    return '$_temp0';
  }

  @override
  String get ordersConfirmTitle => 'Check before sending';

  @override
  String get ordersConfirmStandingLoading => 'Loading the account…';

  @override
  String get ordersConfirmStandingOffline =>
      'No internet — the account can be seen later';

  @override
  String get ordersConfirmStandingFailed =>
      'Could not load the account now — the order still goes; see the account later';

  @override
  String get ordersConfirmAdvance => 'In advance';

  @override
  String get ordersConfirmPendingClaims => 'Awaiting approval';

  @override
  String get ordersConfirmToPay => 'To pay';

  @override
  String ordersConfirmOverLimit(String amount) {
    return 'Over the credit limit — the order goes, but it is not delivered until $amount is paid. No one can pass this limit, not even the owner.';
  }

  @override
  String get ordersConfirmColProduct => 'Product';

  @override
  String get ordersConfirmColAmount => 'Amount';

  @override
  String get ordersConfirmTotal => 'Total';

  @override
  String get ordersConfirmRemarks => 'Remarks (optional)';

  @override
  String get ordersConfirmSend => 'OK, send';

  @override
  String get ordersConfirmBack => 'Go back';

  @override
  String get ordersConfirmSoon => 'Coming soon';

  @override
  String get ordersOk => 'OK';

  @override
  String get ordersConfirmBankSlip => 'Send bank slip';

  @override
  String get ordersNewNoBarcodeProduct => 'No product has this barcode';

  @override
  String get ordersNewQueued =>
      'Order saved. Its number comes after sync — it cannot be told yet.';

  @override
  String get ordersNewPickCustomer => 'Choose a customer';

  @override
  String get ordersNewNoProductsTitle => 'The product list has not synced yet';

  @override
  String get ordersNewNoProductsHint =>
      'Go to the products screen, pull down once, then come back.';

  @override
  String get ordersNewSearchHint => 'Type a name or code';

  @override
  String get ordersNewScanBarcode => 'Scan barcode';

  @override
  String get ordersNewChipRemove => 'Remove';

  @override
  String get ordersNewNoMatch => 'No match found';

  @override
  String get ordersNewInCart => '✓ In the cart';

  @override
  String get ordersNewNoRate => 'Price not known';

  @override
  String get ordersNewLess => 'Less';

  @override
  String get ordersNewMore => 'More';

  @override
  String get ordersNewQty => 'Quantity';

  @override
  String get ordersNewQtyHint => 'Type a number';

  @override
  String ordersNewSaleAmount(String amount) {
    return 'Sale · $amount';
  }

  @override
  String get ordersNewFreeAuto => 'Free · added by itself';

  @override
  String ordersNewBarPieces(String sold, String free) {
    return 'Sale $sold pcs · Free $free';
  }

  @override
  String get ordersNewSend => 'Send';

  @override
  String get ordersNewBarcodeTitle => 'Barcode';

  @override
  String get ordersNewBarcodeHint => 'Scan or type';

  @override
  String ordersNewDueLimit(String amount) {
    return 'Limit $amount';
  }

  @override
  String ordersNewDueHeadroom(String amount) {
    return 'Left $amount';
  }

  @override
  String ordersNewDueDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days',
      one: '$days day',
    );
    return '$_temp0';
  }

  @override
  String ordersNewOverAlready(String amount) {
    return 'Already past the limit — this order adds $amount.';
  }

  @override
  String ordersNewOverBy(String amount) {
    return 'This order goes $amount over the limit.';
  }

  @override
  String ordersNewDueSynced(String when) {
    return 'Dues synced $when';
  }

  @override
  String get counterLoadFailed =>
      'Could not open the counter. Check the connection and try again.';

  @override
  String get counterAbsent =>
      'The phone counter is not on the server yet — tell the office.';

  @override
  String get counterThisUnit => 'This unit';

  @override
  String counterNoFraction(String unit) {
    return '$unit is not sold in fractions — type a whole number.';
  }

  @override
  String counterQtyDecimals(String places) {
    return 'A quantity takes at most $places decimals (like 2.5 or 0.125).';
  }

  @override
  String get counterNoWarehouseLot =>
      'No warehouse found — lots need a warehouse.';

  @override
  String get counterPickProduct => 'Choose a product.';

  @override
  String get counterPickLot => 'Choose a lot — this product is sold by lot.';

  @override
  String get counterNeedQty => 'Give a quantity.';

  @override
  String get counterNeedRate => 'No sale without a rate.';

  @override
  String counterDiscountRange(String min, String max) {
    return 'Discount is $min to $max%.';
  }

  @override
  String counterFreeMax(String max) {
    return 'The scheme allows at most $max free.';
  }

  @override
  String get counterNoWarehouseSale =>
      'No warehouse found — no sale without a warehouse.';

  @override
  String get counterPickBuyer => 'Choose a buyer.';

  @override
  String get counterCartEmpty => 'Put at least one product in the cart.';

  @override
  String get counterSold => 'Sold';

  @override
  String get counterDraftKept => 'Draft kept';

  @override
  String get counterOpenBill => 'See the bill';

  @override
  String get counterSaleFailed => 'Sale failed';

  @override
  String get counterSaleFailedRetry => 'Could not make the sale. Try again.';

  @override
  String get counterOverviewFailed => 'Could not load the summary';

  @override
  String get counterCheckNet => 'Check the connection and try again.';

  @override
  String get counterMoneyTitle => 'Amount received';

  @override
  String counterMoneyReference(String ref) {
    return 'Reference: $ref';
  }

  @override
  String get counterRemove => 'Remove';

  @override
  String get counterMoneyMethod => 'How';

  @override
  String get counterMoneyAccount => 'Into which account';

  @override
  String get counterMoneyAmount => 'Amount';

  @override
  String get counterMoneyReferenceLabel => 'Reference / transaction number';

  @override
  String get counterMoneyAddAnother => 'Add another amount';

  @override
  String counterMoneyTotal(String amount) {
    return 'Total taken: $amount';
  }

  @override
  String get counterUnreadableAmount =>
      'Could not read the figure — type numbers only, like 1250.';

  @override
  String get counterVehicleTitle => 'Vehicle and fare';

  @override
  String get counterVehicleOwner => 'Whose vehicle';

  @override
  String get counterVehicleOwn => 'Our vehicle';

  @override
  String get counterVehicleHired => 'Hired vehicle';

  @override
  String get counterVehicleCustomer => 'Buyer\'s vehicle';

  @override
  String get counterVehicleNone => 'No vehicle needed';

  @override
  String get counterCarrier => 'Carrier';

  @override
  String get counterCarrierName => 'Carrier name (if not in the list)';

  @override
  String get counterFare => 'Fare';

  @override
  String get counterFarePaidBy => 'Who pays the fare';

  @override
  String get counterFareUs => 'We do — our cost';

  @override
  String get counterFareUsAddToBill => 'We pay, added to the bill';

  @override
  String get counterFareCustomer => 'The buyer pays the driver';

  @override
  String get counterFareNone => 'No fare';

  @override
  String get counterFareNow => 'Paid now';

  @override
  String get counterFareLater => 'Pay later';

  @override
  String get counterFareAccount => 'Paid from which account';

  @override
  String get counterFareTrxId => 'Transaction number (TrxID)';

  @override
  String get counterFarePayer => 'Who paid';

  @override
  String get counterFareCarrierLater => 'Carrier — to be paid later';

  @override
  String get counterDeliveryTitle => 'How the goods go';

  @override
  String get counterDeliveryTakeNow => 'The buyer takes them now';

  @override
  String get counterDeliverySendLater => 'Sent later';

  @override
  String get counterDeliveryPickupLater => 'The buyer collects them later';

  @override
  String get counterShipTo => 'Where to send';

  @override
  String get counterShipDatePick => 'When to send — choose a date';

  @override
  String counterShipDate(String date) {
    return 'Send date: $date';
  }

  @override
  String get counterDraftOpenFailed => 'Could not open the draft';

  @override
  String get counterPriceCheckPick => 'Check a price — choose a product';

  @override
  String get counterPriceFailed => 'Could not load the price';

  @override
  String counterPriceRate(String rate, String unit) {
    return 'Sale rate: $rate / $unit';
  }

  @override
  String get counterPriceStockHidden =>
      'Saleable stock: no permission to see it';

  @override
  String counterPriceStock(String qty, String unit) {
    return 'Saleable stock: $qty $unit';
  }

  @override
  String counterPriceLot(String no) {
    return 'Lot $no';
  }

  @override
  String counterPriceExpiry(String date) {
    return 'Expires $date';
  }

  @override
  String counterPriceHave(String qty) {
    return 'In stock $qty';
  }

  @override
  String get counterBack => 'Go back';

  @override
  String get counterPriceToBill => 'Put on the bill';

  @override
  String get counterReprint => 'Reprint';

  @override
  String get counterReprintNone =>
      'No bill made at this counter yet. To print an old bill, open it from the bill list.';

  @override
  String counterBillTitle(String no) {
    return 'Bill · $no';
  }

  @override
  String get counterVoidBill => 'Cancel bill';

  @override
  String get counterVoidNothing => 'Nothing to cancel.';

  @override
  String get counterVoidReason => 'Why cancel (required)';

  @override
  String get counterVoidConfirm => 'Cancel it';

  @override
  String get counterVoided => 'Bill cancelled';

  @override
  String get counterVoidedNote => 'It stays in the books with its reason.';

  @override
  String get counterVoidFailed => 'Not cancelled';

  @override
  String get counterRepeatTitle => 'The same bill was already made';

  @override
  String get counterRepeatTick => 'I know, and I am making the same bill again';

  @override
  String get counterRepeatGo => 'Do it again';

  @override
  String get counterTitle => 'Direct sale';

  @override
  String counterResumeBanner(String no) {
    return 'Draft $no is open';
  }

  @override
  String get counterResumeNote =>
      'Confirming posts this same draft, not a new bill.';

  @override
  String get counterPickBuyerTitle => 'Choose a buyer';

  @override
  String get counterPriceCheck => 'Check price';

  @override
  String get counterNoWarehouseBranch =>
      'No active warehouse in this branch — without one no lots come and nothing can be sold. Tell the office.';

  @override
  String get counterWarehouseNotSet =>
      'Warehouse not set — choose one below; lots need a warehouse.';

  @override
  String get counterWarehouse => 'Warehouse';

  @override
  String get counterTerm => 'Terms';

  @override
  String get counterPickProductTitle => 'Choose a product';

  @override
  String get counterLot => 'Lot';

  @override
  String get counterQty => 'Quantity';

  @override
  String get counterRate => 'Rate';

  @override
  String get counterDiscountLabel =>
      'Discount % (needs the owner\'s signature)';

  @override
  String get counterFree => 'Free';

  @override
  String counterFreeMaxLabel(String max) {
    return 'Free (scheme allows at most $max)';
  }

  @override
  String get counterAddToCart => 'Add to cart';

  @override
  String get counterUpdate => 'Update';

  @override
  String counterLineDiscount(String percent) {
    return 'Discount: $percent%';
  }

  @override
  String counterLineFree(String qty) {
    return 'Free: $qty';
  }

  @override
  String get counterEdit => 'Edit';

  @override
  String counterRunningTotal(String amount) {
    return 'Running total: $amount';
  }

  @override
  String counterMoneyTaken(String amount) {
    return 'Amount taken: $amount';
  }

  @override
  String counterDeliverySummary(String mode) {
    return 'Delivery: $mode';
  }

  @override
  String counterDeliverySummarySend(String to, String date) {
    return 'Sent later — $to · $date';
  }

  @override
  String get counterDeliverySummaryPickup => 'The buyer collects later';

  @override
  String get counterDeliverySummaryNow => 'Taken now';

  @override
  String counterFareSummary(String amount) {
    return 'Fare: $amount';
  }

  @override
  String get counterDelivery => 'Delivery';

  @override
  String get counterReturnButton => 'Returns';

  @override
  String get counterKeepDraft => 'Keep as draft';

  @override
  String get counterOpenDraft => 'Open a draft';

  @override
  String counterConfirmDraft(String no) {
    return 'Confirm — draft $no';
  }

  @override
  String get counterDraftsLoadFailed =>
      'Could not load the drafts. Check the connection and try again.';

  @override
  String counterDraftLines(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count products',
      one: '$count product',
    );
    return '$_temp0';
  }

  @override
  String get counterDraftsEmpty => 'No kept drafts';

  @override
  String get counterDraftsEmptyHint =>
      'Press \"Keep as draft\" at the counter and the bill shows here.';

  @override
  String get counterNewBill => 'Write a new bill';

  @override
  String get booksMethodCash => 'Cash';

  @override
  String get booksMethodBank => 'Bank';

  @override
  String get booksMethodCheque => 'Cheque';

  @override
  String get booksMethodOther => 'Other';

  @override
  String get booksMethodMobileBanking => 'Mobile banking';

  @override
  String get booksReceiptPosted => 'Posted · Posted to the books';

  @override
  String get booksReceiptPending => 'Pending — still to approve or confirm';

  @override
  String get booksReceiptCancelled => 'Cancelled';

  @override
  String get booksBalanceNone => 'No balance';

  @override
  String booksBalanceToPay(String amount) {
    return 'To pay: $amount';
  }

  @override
  String booksBalanceToReceive(String amount) {
    return 'To receive: $amount';
  }

  @override
  String get booksRangeToday => 'Today';

  @override
  String get booksRangeMonth => 'This month';

  @override
  String booksTotalCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '· $count items',
      one: '· $count item',
    );
    return '$_temp0';
  }

  @override
  String get booksMore => 'Show more';

  @override
  String get booksCollectAccountsFailed =>
      'Could not load the money accounts. Check the internet and open again.';

  @override
  String get booksCollectPickCustomerError => 'Choose a customer.';

  @override
  String get booksCollectAmountWrong =>
      'The figure is not right — more than zero, up to two decimals.';

  @override
  String get booksCollectPickAccountError => 'Choose a money account.';

  @override
  String get booksCollectNotLanded =>
      'The collection did not go in. Try again.';

  @override
  String get booksCollectMayHaveLanded =>
      'No answer for the collection — it may have reached the server — press again without changing anything; if the first one went in you will see it, it will not go in twice.';

  @override
  String get booksCollectNeedsNet =>
      'A collection needs the internet. Turn it on and press again — nothing was kept on the phone.';

  @override
  String get booksCollectSendFailed =>
      'Could not submit the collection. Try again.';

  @override
  String get booksCollectTitle => 'Take a collection amount';

  @override
  String get booksCollectPickCustomer => 'Choose a customer';

  @override
  String get booksCollectAmountLabel => 'Amount (Tk)';

  @override
  String get booksCollectAccountLabel => 'Into which account';

  @override
  String get booksCollectInstrumentLabel => 'Channel';

  @override
  String get booksCollectInstrumentHint => 'e.g. bKash, Nagad, bank deposit';

  @override
  String get booksCollectTrxLabel => 'Transaction no. (TrxID)';

  @override
  String get booksCollectDate => 'Date';

  @override
  String get booksCollectRemarks => 'Remarks (optional)';

  @override
  String get booksCollectConfirmNow => 'Confirm now';

  @override
  String get booksCollectConfirmHint =>
      'If approvals are needed, the collection stays pending, as on the web';

  @override
  String get booksCollectSend => 'Submit collection';

  @override
  String booksCollectSendAmount(String amount) {
    return 'Submit collection — $amount';
  }

  @override
  String get booksCollectSearchLabel => 'Name, code, phone or point';

  @override
  String get booksCollectNoCustomer => 'No customer matched.';

  @override
  String get booksMoneyInListFailed =>
      'Could not load the collections. Check the internet and try again.';

  @override
  String get booksMoneyInListAbsent =>
      'The server does not have the collections list yet — tell the office.';

  @override
  String get booksMoneyInTitle => 'Collection';

  @override
  String get booksModuleSales => 'Sales';

  @override
  String get booksMoneyInNew => 'New collection';

  @override
  String get booksMoneyInSearchHint => 'Search customer, shop or number';

  @override
  String get booksMoneyInEmptyTitle => 'No collection in this period';

  @override
  String get booksMoneyInEmptyHint => 'Try another date or method.';

  @override
  String get booksMoneyInEmptyCollect => 'Take a collection';

  @override
  String get booksRefresh => 'Refresh';

  @override
  String get booksMoneyInToday => 'Today\'s collection';

  @override
  String get booksShopDuesTitle => 'Shop dues';

  @override
  String booksShopDuesMore(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count more shops',
      one: '$count more shop',
    );
    return '$_temp0';
  }

  @override
  String booksShopDuesTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Total · $count shops',
      one: 'Total · $count shop',
    );
    return '$_temp0';
  }

  @override
  String get booksMoneyInFailed =>
      'Could not load this collection. Check the internet and try again.';

  @override
  String get booksPrintSend => 'Print / send';

  @override
  String booksMoneyInPrintTitle(String no) {
    return 'Money receipt · $no';
  }

  @override
  String get booksFactCustomer => 'Customer';

  @override
  String get booksFactDate => 'Date';

  @override
  String get booksFactAmount => 'Amount';

  @override
  String get booksFactMethod => 'Method';

  @override
  String get booksFactAccount => 'Account';

  @override
  String get booksFactNumber => 'Number';

  @override
  String get booksFactInstrumentDate => 'Instrument date';

  @override
  String get booksFactNarration => 'Narration';

  @override
  String get booksFactWrittenBy => 'Entered by';

  @override
  String get booksMoneyInLines => 'Against which bills';

  @override
  String get booksMoneyInAdvance => 'Advance';

  @override
  String get booksPcTitle => 'Principal commission';

  @override
  String get booksModuleSupplier => 'Supplier';

  @override
  String get booksPcLoadFailedTitle => 'Could not load the data';

  @override
  String get booksPcLoadFailed =>
      'Could not load the principal commission. Try again in a little while.';

  @override
  String get booksPcAbsent =>
      'The server does not have this page yet, or the supplier part is off. It will show once the server is updated.';

  @override
  String get booksPcEmptyTitle => 'No principal commission in this cycle';

  @override
  String get booksPcEmptyHint =>
      'Principals with a commission rule will show here.';

  @override
  String booksPcGrandTotal(int count, String side) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Grand total · $side · $count principals',
      one: 'Grand total · $side · $count principal',
    );
    return '$_temp0';
  }

  @override
  String get booksPcPrevMonth => 'Previous month';

  @override
  String get booksPcCurrentCycle => 'Current cycle';

  @override
  String get booksPcNextMonth => 'Next month';

  @override
  String get booksPcCurrent => 'Current';

  @override
  String get booksPcTotalInflow => 'Total inflow';

  @override
  String get booksPcTotalCommission => 'Commission';

  @override
  String get booksPcTotalSent => 'Sent';

  @override
  String booksPcInflowLine(String amount) {
    return 'Inflow $amount';
  }

  @override
  String booksPcCommissionLine(String amount) {
    return 'Commission $amount';
  }

  @override
  String booksPcCommissionRateLine(String rate, String amount) {
    return 'Commission ($rate) $amount';
  }

  @override
  String booksPcSentLine(String amount) {
    return 'Sent $amount';
  }

  @override
  String get booksPcUnknown => 'not known';

  @override
  String booksPcCycle(String cycle) {
    return 'Cycle: $cycle';
  }

  @override
  String get booksPrincipalListFailed =>
      'Could not load the principals. Pull down to try again.';

  @override
  String get booksPrincipalListAbsent =>
      'The server does not have the principals list yet — tell the office.';

  @override
  String get booksPrincipalTitle => 'Principals';

  @override
  String get booksPrincipalSearchHint => 'Search name or code';

  @override
  String get booksPrincipalTotalDue => 'Total payable';

  @override
  String get booksPrincipalNone => 'No principal.';

  @override
  String get booksPrincipalNoPurchase => 'No purchase yet';

  @override
  String booksPrincipalLastPurchaseOn(String date) {
    return 'Last purchase: $date';
  }

  @override
  String get booksLedgerFailed => 'Could not load the ledger.';

  @override
  String get booksFactFullName => 'Full name';

  @override
  String get booksFactPhone => 'Phone';

  @override
  String get booksFactBalance => 'Balance';

  @override
  String get booksFactLastPurchase => 'Last purchase';

  @override
  String get booksLedgerEmpty => 'No transaction in the ledger.';

  @override
  String get booksPurchaseListFailed =>
      'Could not load the purchases. Pull down to try again.';

  @override
  String get booksPurchaseListAbsent =>
      'The server does not have the purchases list yet — tell the office.';

  @override
  String get booksPurchasePrincipalsFailed => 'Could not load the principals.';

  @override
  String get booksPurchaseAllPrincipals => 'All principals';

  @override
  String get booksPurchaseTitle => 'Purchases';

  @override
  String get booksPurchaseBill => 'Purchase bill';

  @override
  String get booksPurchaseGoodsReceipt => 'Goods receipt';

  @override
  String get booksPurchaseTotalBills => 'Total purchases';

  @override
  String get booksPurchaseTotalReceipts => 'Total received';

  @override
  String get booksPurchaseEmpty => 'Nothing in this period.';

  @override
  String booksPurchasePaidDue(String paid, String due) {
    return 'Paid $paid · Due $due';
  }

  @override
  String get booksPurchaseFailed => 'Could not load this paper.';

  @override
  String booksPurchasePrintTitle(String kind, String no) {
    return '$kind · $no';
  }

  @override
  String get booksFactPrincipal => 'Principal';

  @override
  String get booksFactSupplierNo => 'Their paper number';

  @override
  String get booksFactTotal => 'Total';

  @override
  String get booksFactPaid => 'Paid';

  @override
  String get booksFactDue => 'Due';

  @override
  String get booksPurchaseProducts => 'Products';

  @override
  String booksPurchaseQty(String qty) {
    return 'Qty $qty';
  }

  @override
  String booksPurchaseFree(String qty) {
    return 'Free $qty';
  }

  @override
  String booksPurchaseLot(String lot) {
    return 'Lot $lot';
  }

  @override
  String booksPurchaseRate(String amount) {
    return 'Rate $amount';
  }

  @override
  String get customersTitle => 'Customers';

  @override
  String get customersDetailGone =>
      'This customer is no longer on this phone — sync and look again.';

  @override
  String get customersOpenLedgerHint => 'Open the ledger';

  @override
  String customersDetailOrders(int count) {
    return 'Orders of this shop ($count)';
  }

  @override
  String get customersDetailNoOrders =>
      'No order of this shop has synced to this phone.';

  @override
  String get customersDetailNoNumber => 'No number';

  @override
  String get customersDetailInactive => '⚠️ This customer is inactive';

  @override
  String customersDetailLimit(String amount) {
    return 'Limit $amount';
  }

  @override
  String customersDetailCreditDays(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days-day terms',
      one: '$days-day terms',
    );
    return '$_temp0';
  }

  @override
  String customersDetailSynced(String stamp) {
    return 'Synced $stamp';
  }

  @override
  String get customersDetailCall => 'Call';

  @override
  String get customersDetailAddress => 'Address';

  @override
  String get customersDetailLedger => 'View ledger';

  @override
  String get customersDetailCredit => 'Credit status';

  @override
  String get customersDepositNotice => 'Deposit notification';

  @override
  String get customersDetailNewOrder => 'New order';

  @override
  String get customersLedgerFailed => 'Could not load the ledger.';

  @override
  String get customersLedgerEmpty =>
      'This shop\'s ledger has no transaction yet.';

  @override
  String get customersLedgerMore => 'Older transactions';

  @override
  String get customersLedgerTitle => 'Ledger';

  @override
  String get customersLedgerHasDue => 'Due';

  @override
  String get customersLedgerHasAdvance => 'Paid in advance';

  @override
  String get customersLedgerSettled => 'All settled';

  @override
  String customersLedgerCode(String code) {
    return 'Code $code';
  }

  @override
  String get customersLedgerOpening => 'Opening balance';

  @override
  String get customersLedgerGoodsTotal => 'Total goods taken';

  @override
  String get customersLedgerPaidTotal => 'Total amount paid';

  @override
  String get customersLedgerClosing => 'Closing balance';

  @override
  String customersLedgerSpokenGoods(String amount) {
    return 'Goods $amount';
  }

  @override
  String customersLedgerSpokenPaid(String amount) {
    return 'Paid $amount';
  }

  @override
  String get customersLedgerTookGoods => 'Took goods';

  @override
  String get customersLedgerPaidMoney => 'Paid an amount';

  @override
  String get customersLedgerRowSettled => 'Settled';

  @override
  String customersLedgerRowDue(String amount) {
    return 'Due $amount';
  }

  @override
  String customersLedgerRowCredit(String amount) {
    return 'In credit $amount';
  }

  @override
  String get customersLedgerNoDate => 'No date';

  @override
  String get customersPickFromList =>
      'Choose a customer from the list on the left';

  @override
  String get customersSearchHint => 'Search by name or mobile';

  @override
  String get customersTotalDue => 'Total due';

  @override
  String get customersNoneSynced => 'No customer has synced yet';

  @override
  String get customersListFailed => 'Could not load the customers';

  @override
  String get customersPullToRetry => 'Pull down to try again.';

  @override
  String get customersNoMatch => 'No match found';

  @override
  String get customersClearFilters => 'Clear the filters and look again.';

  @override
  String customersCreditDaysShort(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days',
      one: '$days day',
    );
    return '$_temp0';
  }

  @override
  String get customersDueTitle => 'Due list';

  @override
  String get customersDueListFailed => 'Could not load the due list';

  @override
  String get customersDueNone => 'Nobody owes anything';

  @override
  String get customersDueNoneHint => 'If it looks unsynced, refresh.';

  @override
  String get customersRefresh => 'Refresh';

  @override
  String get customersDueWord => 'Due';

  @override
  String customersDueWithCode(String code) {
    return '$code · Due';
  }

  @override
  String get customersDepositLoadFailed => 'Could not load the data.';

  @override
  String get customersDepositAbsent =>
      'The server does not have this feature yet — tell the office.';

  @override
  String get customersDepositNeedAmount => 'Write the amount.';

  @override
  String get customersDepositNeedSlip =>
      'A bank deposit needs a photo of the slip.';

  @override
  String customersDepositShareUnreadable(String no) {
    return 'Could not read the share for bill $no — write numbers only.';
  }

  @override
  String customersDepositShareOverDue(String no, String amount) {
    return 'Bill $no has $amount due — you cannot put more than that on it.';
  }

  @override
  String customersDepositSharesOverAmount(String shared, String amount) {
    return 'The bills add up to $shared, but the deposit is $amount — the bill shares cannot be more than the deposit.';
  }

  @override
  String get customersDepositSent =>
      'Sent — the accountant will check it. Until then the due will not go down.';

  @override
  String get customersDepositSendFailed => 'Could not send. Try again.';

  @override
  String get customersDepositIntro =>
      'The due goes down only when the accountant checks the slip and accepts it.';

  @override
  String get customersDepositAmount => 'Amount';

  @override
  String get customersDepositDate => 'Deposit date';

  @override
  String get customersDepositBank => 'Bank';

  @override
  String get customersDepositMfs => 'bKash/Nagad (mobile cash)';

  @override
  String get customersDepositCash => 'Cash';

  @override
  String get customersDepositWhichBank => 'Which bank';

  @override
  String get customersDepositReference => 'Slip / transaction number';

  @override
  String get customersDepositAgainstBills => 'Against which bills (optional)';

  @override
  String get customersDepositBillsHint =>
      'Choose bills and the amount is matched to them when accepted; otherwise the whole amount goes to the shop ledger.';

  @override
  String customersDepositBillDue(String no, String amount) {
    return '$no · Due $amount';
  }

  @override
  String get customersDepositOnThisBill => 'On this bill';

  @override
  String get customersDepositNote => 'Anything else to say';

  @override
  String get customersDepositTakeSlip => 'Take a photo of the slip';

  @override
  String get customersDepositSlipTaken => 'Photo taken — take it again';

  @override
  String get customersDepositSend => 'Send';

  @override
  String customersDepositHistory(int count) {
    return 'Earlier notifications ($count)';
  }

  @override
  String customersDepositHistoryDate(String date) {
    return 'Date $date';
  }

  @override
  String customersDepositHistoryBy(String name) {
    return 'Sent by $name';
  }

  @override
  String customersDepositHistoryRef(String reference) {
    return 'Number $reference';
  }

  @override
  String customersDepositHistoryBill(String no, String amount) {
    return 'Bill $no · $amount';
  }

  @override
  String customersDepositHistoryReason(String reason) {
    return 'Reason: $reason';
  }

  @override
  String get customersDepositReasonNotGiven => 'not given';

  @override
  String get salesDeskComingSoonTitle => 'Coming soon';

  @override
  String get salesDeskComingSoonMessage =>
      'This screen is still being built. It will open here when it is ready.';

  @override
  String get deliveriesTodayLoadFailed =>
      'Could not load the delivery list. Pull down to try again.';

  @override
  String get deliveriesTodayNotOnServer =>
      'Today\'s deliveries are not on the server yet. Tell the office.';

  @override
  String get deliveriesTodayMoreFailed =>
      'Could not load the next page. Try again.';

  @override
  String deliveriesTodayPartlyDelivered(String no) {
    return '$no: partly delivered. The short and damaged goods went on a return paper.';
  }

  @override
  String deliveriesTodayDelivered(String no, String receiver) {
    return '$no: delivered. $receiver took the goods.';
  }

  @override
  String get deliveriesTodaySaveFailed =>
      'Could not record the delivery. Try again.';

  @override
  String get deliveriesTodayTitle => 'Today\'s deliveries';

  @override
  String get deliveriesTodayTotalLabel => 'Delivery challans on the road';

  @override
  String deliveriesTodayTotalValue(String count) {
    return '$count';
  }

  @override
  String get deliveriesTodayEmpty => 'No delivery challan is on the road.';

  @override
  String deliveriesTodayVehicle(String vehicle) {
    return 'Vehicle $vehicle';
  }

  @override
  String deliveriesTodayDriver(String driver) {
    return 'Driver $driver';
  }

  @override
  String get deliveriesTodayHandOver => 'Hand over';

  @override
  String get deliveriesTodayMore => 'Show more';

  @override
  String routeMyWeekday(String day) {
    String _temp0 = intl.Intl.selectLogic(
      day,
      {
        'mon': 'Monday',
        'tue': 'Tuesday',
        'wed': 'Wednesday',
        'thu': 'Thursday',
        'fri': 'Friday',
        'sat': 'Saturday',
        'other': 'Sunday',
      },
    );
    return '$_temp0';
  }

  @override
  String get routeMyLoadFailed =>
      'Could not load the route. Pull down to try again.';

  @override
  String get routeMyNotOnServer =>
      'The server does not have routes yet. Tell the office.';

  @override
  String get routeMyPickDay => 'Route for which day';

  @override
  String get routeMyTitle => 'Today\'s route';

  @override
  String get routeMyErrorTitle => 'Could not load';

  @override
  String get routeMyNoRouteTitle => 'No route on this day';

  @override
  String get routeMyNoRouteMessage =>
      'The weekly plan has no route in your name on this day. Tell the office.';

  @override
  String routeMyMonthSales(String amount) {
    return 'Sales this month: $amount';
  }

  @override
  String routeMyMonthCollections(String amount) {
    return 'Collection this month: $amount';
  }

  @override
  String routeMyOutstanding(String amount) {
    return 'Due: $amount';
  }

  @override
  String get routeMyMoreShops => 'More shops';

  @override
  String get salesDashboardsListLoadFailed =>
      'Could not load the dashboard list.';

  @override
  String get salesDashboardsTitle => 'Business dashboards';

  @override
  String get salesDashboardsModule => 'Dashboards';

  @override
  String get salesDashboardsNoneTitle => 'No dashboard for you';

  @override
  String get salesDashboardsNoneMessage =>
      'A module\'s dashboard appears here once you may see it.';

  @override
  String get salesDashboardsNoKey => 'No access';

  @override
  String get salesDashboardsLoadFailed => 'Could not load the dashboard.';

  @override
  String get salesDashboardsEmptyTitle => 'Nothing on this dashboard yet';

  @override
  String get salesDashboardsEmptyMessage =>
      'The figures appear here once work starts or you are given access.';

  @override
  String get salesDashboardsReports => 'This module\'s reports';

  @override
  String salesDashboardsReportsTitle(String name) {
    return '$name — Reports';
  }

  @override
  String get salesDashboardsOverviewLoadFailed =>
      'Could not load the sales overview.';

  @override
  String get salesDashboardsOverviewTitle => 'Sales overview';

  @override
  String get salesDashboardsSalesModule => 'Sales';

  @override
  String get salesDashboardsOverviewNoAccess =>
      'You may not see any figure on this screen.';

  @override
  String get salesDashboardsNoKeyLong =>
      'No access — permission needed to see this';

  @override
  String get salesDashboardsRefresh => 'Refresh';

  @override
  String get salesDashboardsGrandTotal => 'Grand total';

  @override
  String get quotationsCompareListLoadFailed =>
      'Could not load the quotation list.';

  @override
  String get quotationsCompareLoadFailed => 'Could not load the comparison.';

  @override
  String get quotationsCompareNotOnServer =>
      'This server cannot compare yet. The app is newer than the server.';

  @override
  String get quotationsCompareTitle => 'Compare quotations';

  @override
  String get quotationsCompareRepick => 'Pick again';

  @override
  String get quotationsCompareNeedTwoTitle =>
      'At least two are needed to compare';

  @override
  String get quotationsCompareNeedTwoMessage => 'Pick one more quotation.';

  @override
  String get quotationsCompareNoChoices => 'No quotation to pick';

  @override
  String quotationsComparePickHint(String max) {
    return 'Pick two to $max quotations. The first is the base.';
  }

  @override
  String quotationsCompareGo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Compare $count',
      one: 'Compare $count',
    );
    return '$_temp0';
  }

  @override
  String quotationsCompareRevision(String revision) {
    return ' (rev $revision)';
  }

  @override
  String get quotationsRevisionsLoadFailed =>
      'Could not load the revision list.';

  @override
  String get quotationsRevisionsNotOnServer =>
      'This server does not have this list yet. The app is newer than the server.';

  @override
  String get quotationsRevisionsTitle => 'Quotation revisions';

  @override
  String get quotationsRevisionsSearch => 'Number or shop name';

  @override
  String get quotationsRevisionsEmptyTitle => 'No quotation revisions';

  @override
  String get quotationsRevisionsEmptyMessage => 'Pull down to look again.';

  @override
  String quotationsRevisionsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count revisions',
      one: '$count revision',
    );
    return '$_temp0';
  }

  @override
  String get quotationsRevisionsMore => 'More';

  @override
  String get salesDeskNothing => 'Nothing here';

  @override
  String get salesDeskLoadFailed => 'Could not load. Pull down to try again.';

  @override
  String get salesDeskNotOnServer =>
      'The server does not have this phone screen yet. Tell the office.';

  @override
  String get salesDeskErrorTitle => 'Could not load';

  @override
  String get salesDeskDepotTitle => 'Depot check';

  @override
  String get salesDeskDepotEmpty => 'Nothing is pending a check';

  @override
  String get salesDeskDepotSearch => 'Number, shop name or code';

  @override
  String get salesDeskDepotTotal => 'DOs pending a check';

  @override
  String get salesDeskDepotWebNote =>
      'ⓘ Checking and opening the sale is still on the web counter.';

  @override
  String salesDeskDepotLine(String date, int lines, String status) {
    String _temp0 = intl.Intl.pluralLogic(
      lines,
      locale: localeName,
      other: '$lines products',
      one: '$lines product',
    );
    return '$date · $_temp0 · $status';
  }

  @override
  String get salesDeskDepotHeld => 'Sales orders on hold';

  @override
  String get salesDeskPrintTitle => 'Unprinted papers';

  @override
  String get salesDeskPrintEmpty => 'Every paper is printed';

  @override
  String salesDeskTotalCount(String count) {
    return 'Total $count';
  }

  @override
  String salesDeskPrintPaper(String paper) {
    return 'Paper: $paper';
  }

  @override
  String salesDeskPrintReason(String reason) {
    return 'Reason: $reason';
  }

  @override
  String get salesDeskPrintSettled => 'Marked as printed and removed.';

  @override
  String get salesDeskPrintSettleFailed => 'Could not remove it.';

  @override
  String get salesDeskPrintSettle => 'Printed by hand';

  @override
  String get salesDeskDeliveryTitle => 'Delivery performance';

  @override
  String salesDeskDeliveryLate(String count) {
    return 'Late delivery challans · $count';
  }

  @override
  String salesDeskDeliveryDaysLate(String days) {
    return '$days days late';
  }

  @override
  String salesDeskDeliveryPromised(String promised, String arrived) {
    return 'Promised $promised · Arrived $arrived';
  }

  @override
  String salesDeskDeliveryOrder(String no) {
    return 'Order $no';
  }

  @override
  String get salesDeskPipelineTitle => 'Pipeline';

  @override
  String get salesDeskPipelineEmpty => 'No opportunities';

  @override
  String get salesDeskPipelineOpen => 'Open opportunities (weighted)';

  @override
  String salesDeskPipelineStage(String stage, String count) {
    return '$stage · $count';
  }

  @override
  String salesDeskPipelineTotals(String total, String weighted) {
    return 'Total $total · Weighted $weighted';
  }

  @override
  String salesDeskPipelineCloses(String date) {
    return 'Closes $date';
  }

  @override
  String get salesDeskTemplatesTitle => 'Document templates';

  @override
  String get salesDeskTemplatesEmpty => 'No templates';

  @override
  String salesDeskTemplatesCount(String count) {
    return 'Total $count templates · Writing and editing on the web';
  }

  @override
  String get salesDeskTemplatesInactive => 'Inactive';

  @override
  String salesPapersKindTitle(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'Sales invoice',
        'other': 'Delivery challan',
      },
    );
    return '$_temp0';
  }

  @override
  String salesPapersListLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'Could not load the sales invoice list.',
        'other': 'Could not load the delivery challan list.',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersNotOnServer =>
      'This server does not have this list yet. The app is newer than the server.';

  @override
  String get salesPapersSearch => 'Number or shop name';

  @override
  String salesPapersTotal(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'Total sales invoices',
        'other': 'Total delivery challans',
      },
    );
    return '$_temp0';
  }

  @override
  String salesPapersEmptyTitle(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'No sales invoice in this period',
        'other': 'No delivery challan in this period',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersEmptyMessage =>
      'Change the dates or pull down to look again.';

  @override
  String salesPapersDueAmount(String amount) {
    return 'Due $amount';
  }

  @override
  String get salesPapersPaid => 'Paid';

  @override
  String get salesPapersMore => 'More';

  @override
  String salesPapersLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'Could not load the sales invoice.',
        'other': 'Could not load the delivery challan.',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersPrint => 'Print / Send';

  @override
  String get salesPapersDate => 'Date';

  @override
  String get salesPapersSaleNo => 'Sale number';

  @override
  String get salesPapersDueOn => 'Expires (due date)';

  @override
  String get salesPapersWarehouse => 'Warehouse';

  @override
  String get salesPapersVehicle => 'Vehicle';

  @override
  String get salesPapersCreatedBy => 'Created by';

  @override
  String get salesPapersProducts => 'Products';

  @override
  String salesPapersFreeQty(String qty) {
    return 'Free $qty';
  }

  @override
  String salesPapersLineDiscount(String amount) {
    return 'Discount $amount';
  }

  @override
  String get salesPapersDiscount => 'Discount';

  @override
  String get salesPapersTax => 'Tax';

  @override
  String get salesPapersGrandTotal => 'Total';

  @override
  String get salesPapersCollected => 'Collection';

  @override
  String get salesPapersDue => 'Due';

  @override
  String get salesPapersInvoiceChallans => 'Delivery challans of this invoice';

  @override
  String get salesPapersChallanInvoices => 'Invoices of this challan';

  @override
  String salesPapersStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'draft': 'Draft',
        'confirmed': 'Confirmed',
        'cancelled': 'Cancelled',
        'closed': 'Closed',
        'awaiting_approval': 'Awaiting approval',
        'other': 'Unknown',
      },
    );
    return '$_temp0';
  }

  @override
  String quotationsListTab(String tab) {
    String _temp0 = intl.Intl.selectLogic(
      tab,
      {
        'all': 'All',
        'draft': 'Draft',
        'approved': 'Approved',
        'sent': 'Sent',
        'accepted': 'Accepted',
        'other': 'Converted to order',
      },
    );
    return '$_temp0';
  }

  @override
  String get quotationsListLoadFailed =>
      'Could not load the quotations. Pull down to try again.';

  @override
  String get quotationsListNotOnServer =>
      'The server does not have quotations yet. Tell the office.';

  @override
  String get quotationsListTitle => 'Quotations';

  @override
  String get quotationsListNew => 'New quotation';

  @override
  String get quotationsListTotal => 'Total quotations';

  @override
  String get quotationsListErrorTitle => 'Could not load';

  @override
  String get quotationsListEmpty => 'No quotations';

  @override
  String get quotationsListMore => 'More quotations';

  @override
  String get quotationsNewPickProduct => 'Pick a product';

  @override
  String get quotationsNewNeedShop => 'Pick a shop.';

  @override
  String get quotationsNewNeedLine => 'Add at least one product.';

  @override
  String get quotationsNewSaveFailed =>
      'Could not save the quotation. Try again.';

  @override
  String get quotationsNewPickShop => 'Pick a shop';

  @override
  String quotationsQty(String qty) {
    return 'Quantity: $qty';
  }

  @override
  String quotationsRate(String rate) {
    return 'Rate: $rate';
  }

  @override
  String get quotationsNewProductPrice => 'product\'s price';

  @override
  String get quotationsNewRemoveLine => 'Remove';

  @override
  String get quotationsNewAddLine => 'Add a product';

  @override
  String get quotationsNewNote => 'Remarks (optional)';

  @override
  String get quotationsSubmit => 'Submit';

  @override
  String get quotationsNewKeepDraft => 'Keep as draft';

  @override
  String get quotationsActFailed => 'That did not work. Try again.';

  @override
  String quotationsStatus(String status) {
    return 'Status: $status';
  }

  @override
  String quotationsShop(String shop) {
    return 'Shop: $shop';
  }

  @override
  String quotationsDate(String date) {
    return 'Date: $date';
  }

  @override
  String quotationsValidUntil(String date) {
    return 'Expires: $date';
  }

  @override
  String quotationsTotal(String amount) {
    return 'Total: $amount';
  }

  @override
  String quotationsOrder(String no) {
    return 'Order: $no';
  }

  @override
  String get quotationsSubmitted => 'Submitted.';

  @override
  String get quotationsSentDone => 'Sent to the shopkeeper.';

  @override
  String get quotationsSend => 'Sent to the shopkeeper';

  @override
  String get quotationsAcceptedDone =>
      'The shopkeeper agreed. An order can be made now.';

  @override
  String get quotationsAccept => 'Shopkeeper agreed';

  @override
  String get quotationsRejectReason => 'Reason for refusal';

  @override
  String get quotationsRejectNeedReason => 'Write the reason for refusal.';

  @override
  String get quotationsRejectedDone => 'The shopkeeper refused. Recorded.';

  @override
  String get quotationsReject => 'Shopkeeper refused';

  @override
  String get quotationsConvertedDone => 'Sales order made.';

  @override
  String get quotationsConvert => 'Make the order';

  @override
  String get quotationsLineQty => 'Quantity';

  @override
  String get quotationsLineRate => 'Rate';

  @override
  String get quotationsLineCancel => 'Cancel';

  @override
  String get quotationsLineOk => 'OK';

  @override
  String deliveryOrdersPaper(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'Order',
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
        'order': 'Sales order',
        'other': 'Delivery order',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersListLoadFailed =>
      'Could not load the list. Pull down to try again.';

  @override
  String deliveryOrdersNotOnServer(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'The server does not have sales orders yet. Tell the office.',
        'other':
            'The server does not have delivery orders yet. Tell the office.',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersModule => 'Sales';

  @override
  String deliveryOrdersNew(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'New order',
        'other': 'New DO',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersFilterAll => 'All';

  @override
  String get deliveryOrdersFilterMine => 'Awaiting signature (mine)';

  @override
  String deliveryOrdersEmpty(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'No orders.',
        'other': 'No DOs.',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersEmptyMine =>
      'Nothing is awaiting signature from you now.';

  @override
  String get deliveryOrdersNoCustomer => 'No customer';

  @override
  String get deliveryOrdersPickProduct => 'Pick a product';

  @override
  String get deliveryOrdersNeedDealer => 'Pick a dealer.';

  @override
  String get deliveryOrdersNeedLine => 'Add at least one product.';

  @override
  String deliveryOrdersSaveFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'Could not save the order. Try again.',
        'other': 'Could not save the DO. Try again.',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersPickDealer => 'Pick a dealer';

  @override
  String deliveryOrdersQty(String qty) {
    return 'Quantity: $qty';
  }

  @override
  String get deliveryOrdersRemoveLine => 'Remove';

  @override
  String get deliveryOrdersAddLine => 'Add a product';

  @override
  String get deliveryOrdersNote => 'Remarks (optional)';

  @override
  String get deliveryOrdersSubmit => 'Submit';

  @override
  String get deliveryOrdersKeepDraft => 'Keep as draft';

  @override
  String get deliveryOrdersActFailed => 'That did not work. Try again.';

  @override
  String deliveryOrdersLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'Could not load the order.',
        'other': 'Could not load the DO.',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersPrint => 'Print / Send';

  @override
  String deliveryOrdersPrintTitle(String no) {
    return 'Sales order · $no';
  }

  @override
  String deliveryOrdersStatus(String status) {
    return 'Status: $status';
  }

  @override
  String deliveryOrdersTotal(String amount) {
    return 'Total: $amount';
  }

  @override
  String deliveryOrdersCreditShort(String amount) {
    return 'Held at the credit limit — short by $amount';
  }

  @override
  String deliveryOrdersAsked(String qty) {
    return 'Asked: $qty';
  }

  @override
  String get deliveryOrdersApprovedQtyField => 'Approved quantity';

  @override
  String deliveryOrdersApprovedQty(String qty) {
    return 'Approved: $qty';
  }

  @override
  String deliveryOrdersSubmitted(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'Order submitted. The supervisor will look at it.',
        'other': 'DO submitted. The supervisor will look at it.',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersQtySaved => 'Approved quantities saved.';

  @override
  String get deliveryOrdersSaveQty => 'Save quantities';

  @override
  String get deliveryOrdersApproved => 'Approved.';

  @override
  String get deliveryOrdersApprove => 'Approve';

  @override
  String get deliveryOrdersRejectReason => 'Reason for sending back';

  @override
  String get deliveryOrdersRejectNeedReason =>
      'Write the reason for sending back.';

  @override
  String deliveryOrdersRejected(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'Order sent back.',
        'other': 'DO sent back.',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersReject => 'Send back';

  @override
  String get deliveryOrdersDialogQty => 'Quantity';

  @override
  String get deliveryOrdersDialogCancel => 'Cancel';

  @override
  String get deliveryOrdersDialogOk => 'OK';

  @override
  String get deliveryOrdersStockTitle => 'Stock now — not reserved';

  @override
  String get deliveryOrdersStockNote =>
      'Goods are held when the depot\'s delivery challan is confirmed; this only shows what is in stock and what is wanted.';

  @override
  String deliveryOrdersStockHaveWant(String have, String want) {
    return 'In stock $have · Wanted $want';
  }

  @override
  String deliveryOrdersStockWant(String want) {
    return 'Wanted $want';
  }

  @override
  String deliveryOrdersStockShort(String qty) {
    return 'Short $qty';
  }

  @override
  String get deliveryOrdersStockNotEnough => 'Not enough stock';

  @override
  String get salesPlanLoadFailed => 'Could not load. Pull down to try again.';

  @override
  String get salesPlanNotOnServer =>
      'The server does not have this phone screen yet. Tell the office.';

  @override
  String get salesPlanPreviousMonth => 'Previous month';

  @override
  String get salesPlanNextMonth => 'Next month';

  @override
  String salesPlanPeople(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count people',
      one: '$count person',
    );
    return '$_temp0';
  }

  @override
  String get salesPlanTargetsTitle => 'Sales targets';

  @override
  String get salesPlanTargetsEmpty => 'No salespeople this month';

  @override
  String get salesPlanTargetsNote =>
      'ⓘ Targets are set on the web. Achievement counts confirmed invoices, without VAT and discount.';

  @override
  String get salesPlanTotalTarget => 'Total target';

  @override
  String get salesPlanTotalAchieved => 'Total achieved';

  @override
  String get salesPlanTargetHoldersAchieved =>
      'Achievement of those with a target';

  @override
  String get salesPlanTargetSet => 'Target set';

  @override
  String salesPlanTargetAchieved(String target, String achieved) {
    return 'Target $target · Achieved $achieved';
  }

  @override
  String get salesPlanDealerTargetsTitle => 'Dealer targets';

  @override
  String get salesPlanDealerTargetsNoneSet => 'No one has a target this month';

  @override
  String get salesPlanDealerTargetsEmpty => 'No dealers';

  @override
  String get salesPlanDealerTargetsSearch => 'Dealer name or code';

  @override
  String get salesPlanDealerTargetsOnlySet => 'Only those with a target';

  @override
  String get salesPlanDealerTargetsNote =>
      'ⓘ Targets are set on the web. Achieved means collections, receipts and cleared cheques.';

  @override
  String get salesPlanTotalDue => 'Total due';

  @override
  String salesPlanAchieved(String amount) {
    return 'Achieved $amount';
  }

  @override
  String salesPlanDue(String amount) {
    return 'Due $amount';
  }

  @override
  String salesPlanEnds(String date) {
    return 'Ends $date';
  }

  @override
  String get salesPlanRouteBookTitle => 'Route book';

  @override
  String get salesPlanRouteBookEmpty => 'No routes';

  @override
  String get salesPlanRouteBookSearch => 'Route name or code';

  @override
  String get salesPlanTotalSales => 'Total sales';

  @override
  String get salesPlanTotalCollections => 'Total collection';

  @override
  String get salesPlanTargetAchievement => 'Target achievement';

  @override
  String get salesPlanDealers => 'Dealers';

  @override
  String salesPlanUnrouted(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '⚠ $count dealers are on no route — their figures are not here.',
      one: '⚠ $count dealer is on no route — their figures are not here.',
    );
    return '$_temp0';
  }

  @override
  String salesPlanDealerCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count dealers',
      one: '$count dealer',
    );
    return '$_temp0';
  }

  @override
  String salesPlanSalesCollections(String sales, String collections) {
    return 'Sales $sales · Collection $collections';
  }

  @override
  String salesPlanTargetPercent(String target, String percent) {
    return 'Target $target · $percent';
  }

  @override
  String get salesPlanRouteEmpty => 'No dealers on this route';

  @override
  String get salesPlanClosingBalance => 'Closing balance (due)';

  @override
  String get salesPlanOpeningBalance => 'Opening balance';

  @override
  String get salesPlanSales => 'Sales';

  @override
  String get salesPlanReturns => 'Returns';

  @override
  String get salesPlanCollections => 'Collection';

  @override
  String get salesPlanOther => 'Other';

  @override
  String get salesPlanTarget => 'Target';

  @override
  String get salesPlanWeeklyPlan => 'Weekly plan';

  @override
  String get salesPlanWeeklyPlanEmpty => 'No one is on the plan.';

  @override
  String get salesPlanVisitActive => 'Active';

  @override
  String get salesPlanVisitEnded => 'Ended';

  @override
  String salesPlanFromTo(String from, String to) {
    return '$from to $to';
  }

  @override
  String get salesPlanOngoing => 'ongoing';

  @override
  String get salesPlanRouteNote => 'ⓘ The plan and targets are set on the web.';

  @override
  String get salesPlanStatusAll => 'All';

  @override
  String get salesPlanSchemesTitle => 'Schemes';

  @override
  String get salesPlanSchemesEmpty => 'No schemes';

  @override
  String get salesPlanSchemesSearch => 'Scheme code or name';

  @override
  String salesPlanSchemesCount(String count) {
    return 'Total $count schemes · Set up and changed on the web';
  }

  @override
  String salesPlanSchemeLine(String basis, String appliesTo, int rules) {
    String _temp0 = intl.Intl.pluralLogic(
      rules,
      locale: localeName,
      other: '$rules steps',
      one: '$rules step',
    );
    return '$basis · $appliesTo · $_temp0';
  }

  @override
  String get salesPlanSchemeLive => ' · Running today';

  @override
  String get salesPlanSchemeCode => 'Code';

  @override
  String get salesPlanSchemeStatus => 'Status';

  @override
  String get salesPlanSchemeBasis => 'Basis';

  @override
  String get salesPlanSchemeAppliesTo => 'Applies to';

  @override
  String get salesPlanSchemeValidity => 'Validity (expires)';

  @override
  String get salesPlanSchemeNotes => 'Notes';

  @override
  String salesPlanSchemeSteps(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Steps · $count',
      one: 'Steps · $count',
    );
    return '$_temp0';
  }

  @override
  String get salesPlanSchemeNoSteps => 'No steps set';

  @override
  String salesPlanSchemeRoleLevel(String role, String level) {
    return '$role · Level $level';
  }

  @override
  String salesPlanSchemeFromUp(String from) {
    return '$from and above';
  }

  @override
  String get salesPlanSchemeNote =>
      'ⓘ Steps are set, started and stopped on the web.';

  @override
  String get salesPlanCommissionTitle => 'Commission';

  @override
  String get salesPlanCommissionEmpty => 'No commission';

  @override
  String get salesPlanCommissionSearch => 'Number, dealer or company';

  @override
  String get salesPlanCommissionGrandTotal => 'Grand total';

  @override
  String get salesPlanCommissionPending =>
      'Claimed from the company, still due';

  @override
  String salesPlanCommissionByStatus(String label, int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count',
      one: '$count',
    );
    return '$label · $_temp0';
  }

  @override
  String get salesPlanCommissionTotalNote =>
      'ⓘ Cancelled claims are not in the total; claims sent back are in the total as the depot\'s cost';

  @override
  String get salesPlanCommissionWebNote =>
      'ⓘ New claims, settling and cancelling are on the web.';

  @override
  String salesPlanCommissionInvoice(String no) {
    return 'Invoice $no';
  }

  @override
  String salesPlanCommissionBase(String amount) {
    return 'Base $amount';
  }

  @override
  String salesPlanCommissionRate(String rate) {
    return 'Rate $rate';
  }

  @override
  String salesPlanCommissionLump(String amount) {
    return 'Lump sum $amount';
  }

  @override
  String salesPlanCommissionReason(String reason) {
    return 'Reason: $reason';
  }

  @override
  String get approvalsRejectReasonRequired => 'A reason is required to reject';

  @override
  String get approvalsSendBackReasonRequired =>
      'A reason is required to send back for correction';

  @override
  String get approvalsFinalRejectTitle => 'Reject for good?';

  @override
  String approvalsFinalRejectQuestion(String what) {
    return '$what\n\nThis is final and cannot be undone. To give the writer a chance to fix it, choose \"Send back for correction\".';
  }

  @override
  String get approvalsFinalRejectKeep => 'No, keep it';

  @override
  String get approvalsFinalRejectConfirm => 'Yes, reject';

  @override
  String approvalsSheetTotalLine(String amounts) {
    return 'Total — $amounts';
  }

  @override
  String get claimsKindAdvance => 'Cash advance';

  @override
  String get claimsKindExpense => 'Expense claim';

  @override
  String get claimsStateSubmitted => 'Awaiting signature';

  @override
  String get claimsStateApproved => 'Approved — awaiting payment';

  @override
  String get claimsStatePaid => 'Paid';

  @override
  String get claimsStateRejected => 'Rejected';

  @override
  String get claimsStateOther => 'Pending';

  @override
  String get hrLeaveStatePending => 'Awaiting signature';

  @override
  String get hrLeaveStateApproved => 'Approved';

  @override
  String get hrLeaveStateRejected => 'Rejected';

  @override
  String get hrLeaveStateCancelled => 'Withdrawn';

  @override
  String get profilePhoneFallbackName => 'Phone';

  @override
  String get profileServerMissingTitle => 'Not on this server yet';

  @override
  String get profileServerMissingMessage =>
      'Not on this server yet — it will work once the server is updated.';

  @override
  String get profilePhotoRefused =>
      'The photo was not accepted — use a JPG or PNG under 5 MB and try another.';

  @override
  String get profilePhotoTooLarge =>
      'The photo is too large — choose one under 5 MB.';

  @override
  String get statusPaperFactLabel => 'Status';

  @override
  String get approvalsInboxTitle => 'Approvals';

  @override
  String get approvalsInboxLoadFailed => 'Could not load the list. Try again.';

  @override
  String get approvalsInboxServerMissing =>
      'Approvals are not on this server yet — the app is newer than the server. Tell the office.';

  @override
  String get approvalsInboxSigned => 'Signed.';

  @override
  String get approvalsInboxRejected => 'Rejected.';

  @override
  String get approvalsInboxSentBack => 'Sent back for correction.';

  @override
  String get approvalsInboxAlreadyDecided => 'This has already been decided.';

  @override
  String get approvalsInboxNotYours => 'This decision is not yours to make.';

  @override
  String get approvalsInboxAmountChanged =>
      'The paper\'s amount changed after you opened the card — check the new amount and sign again.';

  @override
  String get approvalsInboxOffline =>
      'No connection. Approvals need the internet.';

  @override
  String get approvalsInboxActionFailed => 'That did not go through.';

  @override
  String get approvalsInboxMoreFailed => 'Could not load more.';

  @override
  String get approvalsInboxSignTitle => 'Sign this paper?';

  @override
  String get approvalsInboxSignButton => 'Sign';

  @override
  String get approvalsInboxRejectTitle => 'Reject this paper?';

  @override
  String get approvalsInboxRejectButton => 'Reject';

  @override
  String get approvalsInboxSendBackTitle => 'Send back for correction?';

  @override
  String get approvalsInboxSendBackButton => 'Send back for correction';

  @override
  String get approvalsInboxLoadFailedTitle => 'Could not load the list';

  @override
  String get approvalsInboxEmptyTitle => 'Nothing is waiting';

  @override
  String get approvalsInboxEmptyMessage =>
      'No document is waiting for your decision.';

  @override
  String get approvalsInboxShowMore => 'Show more';

  @override
  String approvalsInboxStep(int level) {
    return 'Step $level';
  }

  @override
  String get approvalsInboxDetails => 'Details';

  @override
  String get approvalsInboxViewPaper => 'View paper';

  @override
  String get approvalsInboxReasonRequired => 'Reason (required)';

  @override
  String get approvalsInboxRemarksOptional => 'Remarks (optional)';

  @override
  String get approvalsInboxDetailsFailed => 'Could not load the details.';

  @override
  String get approvalsInboxNoDetails =>
      'This paper\'s details are not on the phone — tap \"View paper\" to see the whole paper.';

  @override
  String get approvalsDelegationTitle => 'Delegation';

  @override
  String get approvalsDelegationLoadFailed =>
      'Could not load the delegations. Pull down to try again.';

  @override
  String get approvalsDelegationServerMissing =>
      'Delegation from the phone is not on the server yet — tell the office.';

  @override
  String get approvalsDelegationRevokeTitle => 'Revoke this delegation?';

  @override
  String approvalsDelegationRevokeBody(String person) {
    return '$person will no longer be able to sign for you.';
  }

  @override
  String get approvalsDelegationNo => 'No';

  @override
  String get approvalsDelegationRevoke => 'Revoke';

  @override
  String get approvalsDelegationRevoked => 'Delegation revoked.';

  @override
  String get approvalsDelegationRevokeFailed =>
      'Could not revoke the delegation.';

  @override
  String get approvalsDelegationNew => 'New delegation';

  @override
  String get approvalsDelegationLoadFailedTitle => 'Could not load';

  @override
  String approvalsDelegationHeldHeading(int count) {
    return 'Delegated to me · $count';
  }

  @override
  String approvalsDelegationGivenHeading(int count) {
    return 'Delegated by me · $count';
  }

  @override
  String get approvalsDelegationNoneHeld =>
      'No one has delegated to you right now.';

  @override
  String get approvalsDelegationEmptyTitle => 'No delegations';

  @override
  String get approvalsDelegationEmptyMessage =>
      'Going on leave? Tap \"New delegation\" to let someone sign for you.';

  @override
  String get approvalsDelegationNoneGiven =>
      'You have not delegated to anyone.';

  @override
  String get approvalsDelegationStateRevoked => 'Revoked';

  @override
  String get approvalsDelegationStateActive => 'Active';

  @override
  String get approvalsDelegationStateOther => 'Upcoming or ended';

  @override
  String approvalsDelegationPeriod(String from, String until, String state) {
    return '$from to $until · $state';
  }

  @override
  String get approvalsDelegationAllWork => 'All work';

  @override
  String get approvalsDelegationChooseWho => 'Choose who to delegate to.';

  @override
  String get approvalsDelegationSaved => 'Delegation saved.';

  @override
  String get approvalsDelegationSaveFailed => 'Could not save the delegation.';

  @override
  String get approvalsDelegationTo => 'To whom';

  @override
  String approvalsDelegationFrom(String date) {
    return 'From $date';
  }

  @override
  String approvalsDelegationUntil(String date) {
    return 'Until $date';
  }

  @override
  String get approvalsDelegationWhichWork =>
      'For which work (none chosen means all work)';

  @override
  String get approvalsDelegationReason => 'Reason (optional)';

  @override
  String get approvalsDelegationReasonHint => 'e.g. away on leave';

  @override
  String get approvalsDelegationSaving => 'Saving…';

  @override
  String get approvalsDelegationSave => 'Delegate';

  @override
  String get approvalsQueueTabToSign => 'Awaiting signature from me';

  @override
  String get approvalsQueueTabMine => 'Sent by me';

  @override
  String get approvalsQueueTabAsked => 'Asked by me';

  @override
  String get approvalsQueueTabSigned => 'Signing history';

  @override
  String get approvalsQueueDocumentsTitle => 'Document approvals';

  @override
  String get approvalsQueueSignaturesTitle => 'Signing centre';

  @override
  String get approvalsQueueLoadFailed =>
      'Could not load the list. Pull down to try again.';

  @override
  String get approvalsQueueDocumentsServerMissing =>
      'Document approvals for the phone are not on the server yet — tell the office.';

  @override
  String get approvalsQueueSignaturesServerMissing =>
      'The signing centre for the phone is not on the server yet — tell the office.';

  @override
  String get approvalsQueueSigned => 'Signed.';

  @override
  String get approvalsQueueSignFailed => 'Could not sign.';

  @override
  String get approvalsQueueSendBackTitle =>
      'What needs correcting? (Send back for correction)';

  @override
  String get approvalsQueueReasonDismiss => 'Cancel';

  @override
  String get approvalsQueueReasonNeeded => 'Write the reason.';

  @override
  String get approvalsQueueSendBack => 'Send back for correction';

  @override
  String get approvalsQueueSentBack => 'Sent back for correction.';

  @override
  String get approvalsQueueSendBackFailed =>
      'Could not send back for correction.';

  @override
  String get approvalsQueueRejectTitle => 'Why reject it?';

  @override
  String get approvalsQueueReject => 'Reject';

  @override
  String get approvalsQueueRejected => 'Rejected.';

  @override
  String get approvalsQueueRejectFailed => 'Could not reject.';

  @override
  String approvalsQueueTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Total $count papers',
      one: 'Total $count paper',
    );
    return '$_temp0';
  }

  @override
  String get approvalsQueueLoadFailedTitle => 'Could not load';

  @override
  String get approvalsQueueNoSignatures => 'No signatures yet';

  @override
  String get approvalsQueueNothingWaiting => 'Nothing is waiting';

  @override
  String approvalsQueueSignedBy(String signer, String at) {
    return 'Signed by $signer · $at';
  }

  @override
  String approvalsQueueFingerprint(String hash) {
    return 'Fingerprint $hash…';
  }

  @override
  String approvalsQueueRequestedBy(String name) {
    return 'Requested by $name';
  }

  @override
  String approvalsQueueDue(String due) {
    return 'Due: $due';
  }

  @override
  String get approvalsQueueSign => 'Sign';

  @override
  String get approvalsQueueMore => 'More';

  @override
  String get approvalsMyRequestsLoadFailed =>
      'Could not load my requests. Pull down to try again.';

  @override
  String get approvalsMyRequestsServerMissing =>
      'This list is not on this server yet — the app is newer than the server.';

  @override
  String get approvalsMyRequestsTitle => 'My requests';

  @override
  String get approvalsMyRequestsTotalLabel => 'Total requests';

  @override
  String approvalsMyRequestsTotalValue(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count requests',
      one: '$count request',
    );
    return '$_temp0';
  }

  @override
  String approvalsMyRequestsPending(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Still awaiting signature: $count requests',
      one: 'Still awaiting signature: $count request',
    );
    return '$_temp0';
  }

  @override
  String get approvalsMyRequestsEmptyTitle => 'You have sent no request';

  @override
  String get approvalsMyRequestsEmptyMessage =>
      'A paper you send for signature shows here.';

  @override
  String get approvalsMyRequestsMore => 'More';

  @override
  String approvalsMyRequestsLastWord(String said) {
    return 'Last word: $said';
  }

  @override
  String approvalsMyRequestsReason(String remarks) {
    return 'Reason: $remarks';
  }

  @override
  String get profilePhonesTitle => 'My phones';

  @override
  String get profileModuleMe => 'Me';

  @override
  String get profilePhonesLoadFailed =>
      'Could not load the phones. Pull down to try again.';

  @override
  String get profilePhonesSignOutHereTitle => 'Sign out of this phone?';

  @override
  String get profilePhonesSignOutOtherTitle => 'Sign this phone out?';

  @override
  String profilePhonesSignOutHereBody(String phone) {
    return '“$phone” — this is the phone in your hand. Signing out takes you to the login page now; you will need your password to come back.';
  }

  @override
  String profilePhonesSignOutOtherBody(String phone) {
    return 'Your login on “$phone” ends now. That phone will need your password to come back.';
  }

  @override
  String get profilePhonesKeep => 'No, keep it';

  @override
  String get profilePhonesSignOut => 'Sign out';

  @override
  String profilePhonesSignOutNamed(String phone) {
    return 'Sign out $phone';
  }

  @override
  String profilePhonesAlreadyOut(String phone) {
    return '“$phone” was already signed out — the list is reloaded.';
  }

  @override
  String profilePhonesSignedOut(String phone) {
    return '“$phone” is signed out.';
  }

  @override
  String get profilePhonesSignOutFailed =>
      'Could not sign it out. Try again a little later.';

  @override
  String get profilePhonesServerOld =>
      'My phones will work once the server is updated.';

  @override
  String get profilePhonesEmptyTitle => 'Not signed in on any phone';

  @override
  String get profilePhonesEmptyMessage => 'A phone you log in on shows here.';

  @override
  String profilePhonesLastUsed(String when) {
    return 'Last used: $when';
  }

  @override
  String get profilePhonesLastUsedUnknown => 'not known';

  @override
  String get profilePhonesThisPhone => 'This phone';

  @override
  String get profileTitlePhoto => 'Profile and photo';

  @override
  String get profileTitlePlain => 'My profile';

  @override
  String get profilePhotoUnreadable =>
      'The photo could not be read — choose another one.';

  @override
  String get profilePhotoChanged =>
      'Photo changed — the web shows this face too.';

  @override
  String get profilePhotoUploadFailed =>
      'Could not send the photo. Try again a little later.';

  @override
  String get profilePhotoRemoveTitle => 'Remove the photo?';

  @override
  String get profilePhotoRemoveBody =>
      'Without a photo, the first letter of your name shows in its place — on the phone and on the web.';

  @override
  String get profilePhotoKeep => 'No, keep it';

  @override
  String get profilePhotoRemoveConfirm => 'Remove';

  @override
  String get profilePhotoRemoved => 'Photo removed.';

  @override
  String get profilePhotoRemoveFailed =>
      'Could not remove the photo. Try again a little later.';

  @override
  String profilePhotoSendingSpoken(int percent) {
    return 'Sending the photo, $percent percent';
  }

  @override
  String profilePhotoSending(int percent) {
    return 'Sending the photo… $percent%';
  }

  @override
  String get profilePhotoCamera => 'Take with camera';

  @override
  String get profilePhotoGallery => 'From gallery';

  @override
  String get profilePhotoRemove => 'Remove photo';

  @override
  String get profilePhotoPrivacy =>
      'The photo is cropped square from the middle and made smaller, with its place and time data removed. Only colleagues in your company see it.';

  @override
  String get profilePhonesSubtitle =>
      'The phones you are signed in on — sign a lost phone out from here';

  @override
  String get profileFaceNone =>
      'No photo — the first letter of your name is shown';

  @override
  String get profileFace => 'Your photo';

  @override
  String get hrAttendanceMarkedQueued =>
      'Attendance marked. It will be sent when there is a connection.';

  @override
  String get hrAttendanceTitle => 'Attendance';

  @override
  String get hrAttendanceEarlierDays => 'Earlier days';

  @override
  String get hrAttendanceNoHistoryTitle => 'No earlier attendance has synced';

  @override
  String get hrAttendanceNoHistoryMessage => 'Pull down to try again.';

  @override
  String get hrAttendanceToday => 'Today';

  @override
  String get hrAttendanceTodayMarked => 'Today\'s attendance is marked';

  @override
  String get hrAttendanceWaitingToSend =>
      'It will be sent when there is a connection — it is still on this phone.';

  @override
  String get hrAttendanceMarkToday => 'Mark today\'s attendance';

  @override
  String hrAttendanceIn(String time) {
    return 'In $time';
  }

  @override
  String hrAttendanceOut(String time) {
    return 'Out $time';
  }

  @override
  String get hrAttendanceLate => 'Late';

  @override
  String get notificationsLoadFailed => 'Could not load the notifications.';

  @override
  String get notificationsServerMissing =>
      'Notifications are not on this server yet — the app is newer than the server.';

  @override
  String get notificationsTitle => 'Notifications';

  @override
  String get notificationsMarkAllRead => 'Mark all read';

  @override
  String get notificationsLoading => 'Loading…';

  @override
  String get notificationsEmptyTitle => 'No notifications';

  @override
  String get notificationsEmptyMessage => 'Anything new shows here.';

  @override
  String get noticesLoadFailed => 'Could not load the notices.';

  @override
  String get noticesBoardTitle => 'Notice board';

  @override
  String noticesUnreadCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count notices not read yet',
      one: '$count notice not read yet',
    );
    return '$_temp0';
  }

  @override
  String get noticesEmptyTitle => 'No notices now';

  @override
  String get noticesEmptyMessage => 'Anything new from the company shows here.';

  @override
  String get noticesSigned => 'Signed.';

  @override
  String get noticesSignFailed => 'Could not sign.';

  @override
  String get noticesDetailTitle => 'Notice';

  @override
  String noticesValidUntil(String date) {
    return 'Expires $date';
  }

  @override
  String noticesSignBy(String date) {
    return 'Sign by $date';
  }

  @override
  String get noticesYouSigned => 'You have signed';

  @override
  String get noticesSending => 'Sending…';

  @override
  String get noticesReadAndAgree => 'I have read and I agree — Sign';

  @override
  String noticesStanding(String standing) {
    String _temp0 = intl.Intl.selectLogic(
      standing,
      {
        'unread': 'Not read',
        'read': 'Read',
        'acknowledged': 'Acknowledged',
        'overdue': 'Overdue',
        'escalated': 'Escalated',
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
        'low': 'Low',
        'normal': 'Normal',
        'important': 'Important',
        'high': 'High',
        'critical': 'Critical',
        'emergency': 'Emergency',
        'other': '',
      },
    );
    return '$_temp0';
  }

  @override
  String get hrRegisterAttendanceLoadFailed =>
      'Could not load the attendance. Pull down to try again.';

  @override
  String get hrRegisterAttendanceServerMissing =>
      'The attendance register for the phone is not on the server yet — tell the office.';

  @override
  String get hrRegisterAttendanceTitle => 'Attendance register';

  @override
  String hrRegisterAttendanceDay(String date) {
    return 'Day: $date';
  }

  @override
  String get hrRegisterLoadFailedTitle => 'Could not load';

  @override
  String get hrRegisterAttendanceNobody => 'No salaried employee on this day';

  @override
  String hrRegisterAttendanceTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count people in all',
      one: '$count person in all',
    );
    return '$_temp0';
  }

  @override
  String hrRegisterAttendanceCount(String label, int count) {
    return '$label: $count';
  }

  @override
  String hrRegisterAttendanceLate(String status) {
    return '$status · late';
  }

  @override
  String get hrRegisterLeaveLoadFailed =>
      'Could not load the leave list. Pull down to try again.';

  @override
  String get hrRegisterLeaveServerMissing =>
      'The leave register for the phone is not on the server yet — tell the office.';

  @override
  String get hrRegisterLeaveRejectTitle => 'Why reject it?';

  @override
  String get hrRegisterLeaveRejectDismiss => 'Cancel';

  @override
  String get hrRegisterLeaveRejectConfirm => 'Reject';

  @override
  String get hrRegisterLeaveRejectReasonNeeded =>
      'Write the reason for rejecting.';

  @override
  String get hrRegisterLeaveSigned => 'Leave signed.';

  @override
  String get hrRegisterLeaveRejected => 'Leave rejected.';

  @override
  String get hrRegisterLeaveSignFailed => 'Could not sign.';

  @override
  String get hrRegisterLeaveRejectFailed => 'Could not reject.';

  @override
  String get hrRegisterLeaveTitle => 'Leave register';

  @override
  String get hrRegisterLeavePending => 'Pending';

  @override
  String get hrRegisterLeaveAll => 'All';

  @override
  String hrRegisterLeaveTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count applications in all',
      one: '$count application in all',
    );
    return '$_temp0';
  }

  @override
  String get hrRegisterLeaveNonePending => 'No pending application';

  @override
  String get hrRegisterLeaveNone => 'No application';

  @override
  String hrRegisterLeaveLine(String type, String from, String to, num days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days',
      one: '$days day',
    );
    return '$type · $from to $to · $_temp0';
  }

  @override
  String hrRegisterLeaveDecider(String name) {
    return 'Decided by $name';
  }

  @override
  String get hrRegisterLeaveReject => 'Reject';

  @override
  String get hrRegisterLeaveSign => 'Sign';

  @override
  String get hrRegisterLeaveMore => 'More';

  @override
  String get hrLeaveLoadFailed =>
      'Could not load your leave. Pull down to try again.';

  @override
  String get hrLeaveServerMissing =>
      'Leave for the phone is not on the server yet — tell the office.';

  @override
  String get hrLeaveTitle => 'My leave';

  @override
  String get hrLeaveSent => 'Leave application sent — awaiting signature.';

  @override
  String get hrLeaveApply => 'Apply for leave';

  @override
  String get hrNoEmployeeRecord => 'No employee record in your name';

  @override
  String get hrLeaveNoEmployeeMessage =>
      'To apply for leave, ask HR to link your login to your employee record.';

  @override
  String get hrLeaveLeftThisYear => 'Left this year';

  @override
  String get hrLeaveNoTypes => 'No leave type yet — HR will set them up.';

  @override
  String hrLeaveNoLimit(String taken) {
    return 'No limit · days taken: $taken';
  }

  @override
  String hrLeaveBalance(String left, String allowed) {
    return 'Days left: $left of $allowed';
  }

  @override
  String get hrLeaveMyApplications => 'My applications';

  @override
  String get hrLeaveNoApplications => 'No leave application yet';

  @override
  String hrLeaveCardTitle(String type, String days) {
    return '$type · days: $days';
  }

  @override
  String hrLeaveOfficeNote(String remarks) {
    return 'From the office: $remarks';
  }

  @override
  String get hrLeaveChooseType => 'Choose the leave type.';

  @override
  String get hrLeaveDaysShape =>
      'Write the number of days properly — e.g. 2 or 1.5.';

  @override
  String hrLeaveDaysTooMany(int span) {
    String _temp0 = intl.Intl.pluralLogic(
      span,
      locale: localeName,
      other: 'More days than the dates cover — no more than $span days.',
      one: 'More days than the dates cover — no more than $span day.',
    );
    return '$_temp0';
  }

  @override
  String get hrLeaveSendFailed => 'Could not send the application. Try again.';

  @override
  String get hrLeaveHowItWorks =>
      'The application goes to the office; once approved, the days show as \"Leave\" in attendance.';

  @override
  String get hrLeaveTypeField => 'Which leave';

  @override
  String hrLeaveTypeWithLeft(String name, String left) {
    return '$name (days left: $left)';
  }

  @override
  String get hrLeaveFrom => 'From';

  @override
  String get hrLeaveUntil => 'Until';

  @override
  String get hrLeaveDaysField => 'How many days (.5 for a half day)';

  @override
  String get hrLeaveReasonField => 'Reason';

  @override
  String get hrLeaveSend => 'Send';

  @override
  String get hrPayslipListTitle => 'My payslips';

  @override
  String get hrPayslipListFailed =>
      'Could not load the payslips. Pull down to try again.';

  @override
  String get hrPayslipServerMissing =>
      'Payslips for the phone are not on the server yet — tell the office.';

  @override
  String get hrPayslipEmptyTitle => 'No posted payslip yet';

  @override
  String get hrPayslipEmptyMessage =>
      'A slip arrives here once the payroll run is posted.';

  @override
  String get hrPayslipNoEmployeeMessage =>
      'Ask HR to link your login to your employee record.';

  @override
  String hrPayslipRowLine(String gross, String deductions) {
    return 'Earnings $gross · Deductions $deductions';
  }

  @override
  String hrPayslipPaidBy(String method) {
    String _temp0 = intl.Intl.selectLogic(
      method,
      {
        'cash': 'Cash',
        'bank': 'Bank',
        'mfs': 'Mobile banking',
        'other': '$method',
      },
    );
    return '$_temp0';
  }

  @override
  String get hrPayslipTitle => 'Payslip';

  @override
  String get hrPayslipFailed => 'Could not load the payslip.';

  @override
  String get hrPayslipNet => 'Net salary';

  @override
  String get hrPayslipName => 'Name';

  @override
  String get hrPayslipMonth => 'Month';

  @override
  String get hrPayslipHow => 'Paid by';

  @override
  String get hrPayslipEarnings => 'Earnings';

  @override
  String get hrPayslipGross => 'Gross';

  @override
  String get hrPayslipDeductions => 'Deductions';

  @override
  String get hrPayslipTotalDeductions => 'Total deductions';

  @override
  String get claimsLoadFailed =>
      'Could not load the claims. Pull down to try again.';

  @override
  String get claimsServerMissing =>
      'Expense claims are not on the server yet — tell the office.';

  @override
  String get claimsListTitle => 'Expense claims and cash advances';

  @override
  String get claimsNew => 'New claim';

  @override
  String get claimsOpenAdvance => 'Open advance in hand';

  @override
  String get claimsEmpty => 'No claims yet.';

  @override
  String get claimsOneFailed => 'Could not load the claim.';

  @override
  String get claimsOneTitle => 'Claim';

  @override
  String get claimsKind => 'Kind';

  @override
  String get claimsAmount => 'Amount';

  @override
  String get claimsHead => 'Expense head';

  @override
  String get claimsSpentOn => 'Spent on';

  @override
  String get claimsFromAdvance => 'Settled from advance';

  @override
  String get claimsInCash => 'You get in cash';

  @override
  String get claimsReason => 'Reason';

  @override
  String get claimsSentOn => 'Sent';

  @override
  String get claimsRejectedOn => 'Rejected';

  @override
  String get claimsApprovedOn => 'Approved';

  @override
  String get claimsPaidOn => 'Amount paid';

  @override
  String get claimsHeadsFailed => 'Could not load the expense heads.';

  @override
  String get claimsAmountShape =>
      'Write the amount properly — e.g. 500 or 750.50.';

  @override
  String get claimsChooseHead => 'Choose the expense head.';

  @override
  String get claimsWriteReason => 'Write the reason.';

  @override
  String get claimsSendFailed => 'Could not send. Try again.';

  @override
  String get claimsHowItWorks =>
      'You get the amount after the signatures and the cashier. Spending is settled from your advance first.';

  @override
  String get claimsKindSpent => 'Already spent';

  @override
  String get claimsKindAdvanceWanted => 'Need an advance';

  @override
  String get claimsReceiptTake => 'Take a receipt photo';

  @override
  String get claimsReceiptTaken => 'Photo taken — take it again';

  @override
  String get claimsSend => 'Send';

  @override
  String get hrPayslipFestivalBonus => 'Festival bonus';

  @override
  String get ownerCentreTitle => 'Owner\'s centre';

  @override
  String get ownerTodayFailed =>
      'The owner\'s centre figures could not be loaded.';

  @override
  String get ownerTodayAbsent =>
      'The owner\'s centre is not on this server yet — the app is newer than the server.';

  @override
  String get ownerPageAbsent =>
      'This owner\'s centre page is not on this server yet — the app is newer than the server. Tell the office.';

  @override
  String get ownerNoCompanyTitle => 'No company to show';

  @override
  String get ownerNoCompanyMessage =>
      'You may not see the owner\'s centre in any company.';

  @override
  String get ownerCompaniesAndBranches => 'By company and branch';

  @override
  String get ownerAlerts => 'Alerts';

  @override
  String get ownerAwaitingSignature => 'Awaiting signature';

  @override
  String get ownerNothingAwaitingNow => 'No paper is awaiting signature now.';

  @override
  String get ownerSomeCompaniesLeftOut =>
      'Some companies left out — not allowed';

  @override
  String get ownerCredit => 'Credit';

  @override
  String get ownerCreditExposure => 'Exposure';

  @override
  String get ownerCreditOverdue => 'Overdue';

  @override
  String get ownerCreditBlocked => 'Blocked customers';

  @override
  String ownerCreditBlockedCount(int count) {
    return '$count';
  }

  @override
  String get ownerCreditRequests => 'Limit requests waiting';

  @override
  String ownerCount(int count) {
    return '$count';
  }

  @override
  String ownerCompanySummary(String sales, String collections) {
    return 'Sales $sales · Collections $collections';
  }

  @override
  String get ownerUnsplit => 'Not split by branch';

  @override
  String get ownerNotAllowed => 'Not allowed';

  @override
  String get ownerNone => 'None';

  @override
  String get ownerPeriodToday => 'Today';

  @override
  String get ownerPeriodWeek => 'This week';

  @override
  String get ownerPeriodMonth => 'This month';

  @override
  String get ownerFigureSales => 'Sales';

  @override
  String get ownerFigureCollections => 'Collections';

  @override
  String get ownerFigureReceivable => 'Receivables';

  @override
  String get ownerFigurePayable => 'Supplier payables';

  @override
  String get ownerFigureFund => 'Cash + bank';

  @override
  String get ownerFigureStock => 'Stock value';

  @override
  String get ownerFigureProfit => 'Profit';

  @override
  String get ownerFigureExpenses => 'Expenses';

  @override
  String get ownerFigureCashFlow => 'Net cash flow';

  @override
  String get ownerAlertOverLimit => 'Over credit limit';

  @override
  String get ownerAlertOverdue => 'Overdue dues';

  @override
  String get ownerAlertStockLow => 'Stock below reorder';

  @override
  String get ownerAlertStockNegative => 'Stock below zero';

  @override
  String get ownerAlertStockDead => 'Dead stock';

  @override
  String ownerAlertLateDelivery(int days) {
    return 'Late delivery challans ($days days)';
  }

  @override
  String ownerAlertFailedLogin(int days) {
    return 'Failed sign-ins ($days days)';
  }

  @override
  String get ownerAlertBackdated => 'Back-dated papers (this month)';

  @override
  String get ownerAlertsFailed => 'The alerts could not be loaded.';

  @override
  String ownerFiguresMayBeOld(int minutes) {
    return 'Figures can be up to $minutes minutes old. Pull down to load them again.';
  }

  @override
  String ownerAwaitingSignatureCount(int count) {
    return 'Awaiting signature ($count)';
  }

  @override
  String get ownerNothingAwaiting => 'No paper is awaiting signature.';

  @override
  String get ownerGroupTotal => 'Group total';

  @override
  String get ownerHistoryTitle => 'Figures on a past day';

  @override
  String get ownerHistoryFailed =>
      'The figures for a past day could not be loaded.';

  @override
  String ownerHistoryDay(String day) {
    return 'Which day: $day';
  }

  @override
  String ownerHistoryAsKept(String date, String time) {
    return '$date — exactly what the screen showed at $time that night, not counted again from today\'s books.';
  }

  @override
  String get ownerHistoryNoneTitle => 'No nightly record for that day';

  @override
  String get ownerHistoryNoneMessage =>
      'It is before records began, or nothing was written that night.';

  @override
  String ownerHistoryTrend(String figure, int days) {
    return '$figure — last $days days, from the nightly records';
  }

  @override
  String get ownerHistoryMonthBefore => 'Same day last month';

  @override
  String get ownerReportProfitByCustomer => 'Profit by customer';

  @override
  String get ownerReportProfitByCustomerHint =>
      'Each bill line\'s actual FIFO cost, as drawn from the layers when the bill was posted; returned goods come off at their own cost.';

  @override
  String get ownerReportsTitle => 'Reports';

  @override
  String get ownerReportsFailed => 'The reports page could not be loaded.';

  @override
  String get ownerReportsCentre => 'Report centre — every report';

  @override
  String get ownerReportsOwn => 'The owner\'s centre reports';

  @override
  String get ownerReportNotAllowed => 'You may not see this report.';

  @override
  String get ownerReportsExportNote =>
      'On a report page you can pick dates, get a PDF and share; each download is written in the export journal.';

  @override
  String ownerSavedViews(int count) {
    return 'My saved views ($count)';
  }

  @override
  String get ownerNoSavedViews =>
      'No saved views — keep one from \"Views\" on any report page.';

  @override
  String get ownerScheduledReports => 'Scheduled reports';

  @override
  String get ownerSchedulesOnWeb =>
      'Schedules are set and changed on the web page.';

  @override
  String get ownerNoScheduleKey => 'You may not set scheduled reports.';

  @override
  String get ownerReportsInAppOnly =>
      'Finished reports arrive inside the app, not by e-mail.';

  @override
  String get ownerLinksTitle => 'Sister-company parties';

  @override
  String get ownerLinksFailed =>
      'The sister-company parties could not be loaded.';

  @override
  String get ownerLinksExplain =>
      'If a customer or supplier of one company is really another of our companies and the two are linked, the group total leaves out the sales, receivables and payables between them. Names are never matched by guess.';

  @override
  String get ownerLinksWebOnly =>
      'Linking and unlinking is on the web page — here you can only look.';

  @override
  String get ownerLinksTotal => 'Total links';

  @override
  String get ownerLinksNoneTitle => 'No links';

  @override
  String get ownerLinksNoneMessage =>
      'Nothing is removed from the group total.';

  @override
  String ownerLinksIsSister(String company, String sister) {
    return '$company → really our $sister';
  }

  @override
  String get ownerPartyCustomer => 'Customer';

  @override
  String get ownerPartySupplier => 'Supplier';

  @override
  String get ownerAnalysisTitle => 'Analysis';

  @override
  String get ownerAnalysisFailed => 'The analysis could not be loaded.';

  @override
  String get ownerPeriodThreeMonths => 'Last three months';

  @override
  String get ownerPeriodYear => 'This year';

  @override
  String ownerListTopCustomers(int count) {
    return 'Top $count customers';
  }

  @override
  String ownerListTopProducts(int count) {
    return 'Top $count products';
  }

  @override
  String ownerListTopAreas(int count) {
    return 'Top $count routes';
  }

  @override
  String ownerListTopSalespeople(int count) {
    return 'Top $count salespeople';
  }

  @override
  String get ownerListLosing => 'Least profitable customers';

  @override
  String get ownerListThin => 'Lowest-margin products';

  @override
  String get ownerListDead => 'Dead stock (by value)';

  @override
  String get ownerBucketCurrent => 'Current dues';

  @override
  String ownerBucketOver(int days) {
    return 'Over $days days';
  }

  @override
  String get ownerOutstanding => 'Total dues';

  @override
  String get ownerAgeing => 'Age of dues — all companies together';

  @override
  String ownerRefused(String companies) {
    return 'Not allowed to see: $companies';
  }

  @override
  String get ownerNothingInPeriod => 'Nothing in this period.';

  @override
  String get ownerNoCostKey =>
      'You may not see costs — profit and margin stay hidden.';

  @override
  String get ownerCompareTitle => 'Compare';

  @override
  String get ownerCompareFailed => 'The comparison could not be loaded.';

  @override
  String get ownerModeCompanies => 'Company vs company';

  @override
  String get ownerModeBranches => 'Branch vs branch';

  @override
  String get ownerAgainstPreviousMonth => 'The same days last month';

  @override
  String get ownerAgainstLastYear => 'The same time last year';

  @override
  String get ownerGrainDaily => 'By day';

  @override
  String get ownerGrainWeekly => 'By week';

  @override
  String get ownerGrainMonthly => 'By month';

  @override
  String get ownerGrainQuarterly => 'By quarter';

  @override
  String get ownerGrainYearly => 'By year';

  @override
  String get ownerAllCompanies => 'All companies';

  @override
  String ownerNowThen(String now, String was) {
    return 'Now: $now · Then: $was';
  }

  @override
  String get ownerNothingToShow => 'Nothing to show';

  @override
  String get ownerScreenNotAllowed =>
      'You may not see this screen in any company.';

  @override
  String get ownerReceivableNote =>
      'Receivables are a balance: \"then\" is the figure kept that night. Without a nightly record it shows \"—\".';

  @override
  String ownerTrendOf(String figure) {
    return '$figure over time';
  }

  @override
  String get ownerNotAllowedToSee => 'Not allowed to see';

  @override
  String get ownerLastYear => 'Last year';

  @override
  String ownerThen(String value) {
    return 'Then $value';
  }

  @override
  String get documentsLevelPublic => 'Public';

  @override
  String get documentsLevelInternal => 'Internal';

  @override
  String get documentsLevelConfidential => 'Confidential';

  @override
  String get documentsLevelHighlyConfidential => 'Highly confidential';

  @override
  String get documentsLevelRestricted => 'Restricted';

  @override
  String get documentsStatusDraft => 'Draft';

  @override
  String get documentsStatusSubmitted => 'Submitted';

  @override
  String get documentsStatusUnderReview => 'Under review';

  @override
  String get documentsStatusChangesRequested => 'Changes asked for';

  @override
  String get documentsStatusApproved => 'Approved';

  @override
  String get documentsStatusRejected => 'Rejected';

  @override
  String get documentsStatusPublished => 'Published';

  @override
  String get documentsStatusPublishedUnapproved => 'Published, not approved';

  @override
  String get documentsStatusExpired => 'Expired';

  @override
  String get documentsStatusArchived => 'Archived';

  @override
  String get documentsSignaturePending => 'Awaiting signature';

  @override
  String get documentsSignatureSigned => 'Signed — on the current version';

  @override
  String get documentsSignatureStale =>
      'Signed on an old version — the current one has no signature';

  @override
  String get documentsSignatureNone => 'No signature asked for';

  @override
  String get documentsViewAll => 'All';

  @override
  String get documentsViewMine => 'Mine';

  @override
  String get documentsViewShared => 'Shared with me';

  @override
  String get documentsViewRecent => 'Recent';

  @override
  String get documentsViewExpiry => 'Expires';

  @override
  String get documentsViewArchive => 'Archive';

  @override
  String get documentsViewRecycle => 'Recycle bin';

  @override
  String get documentsViewSearch => 'Search (with archive)';

  @override
  String get documentsTitle => 'Documents';

  @override
  String get documentsListFailed =>
      'The documents could not be loaded. Pull down to try again.';

  @override
  String get documentsListAbsent =>
      'Documents have not reached the server yet — tell the office.';

  @override
  String get documentsListEmpty => 'No documents to see here';

  @override
  String get documentsSearchHint => 'Name, number or tag';

  @override
  String documentsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count documents in all',
      one: '$count document in all',
    );
    return '$_temp0';
  }

  @override
  String documentsExpires(String date) {
    return 'Expires $date';
  }

  @override
  String get documentsDeleted => 'Deleted — restore on the web';

  @override
  String get documentsFileOpenFailed => 'The file could not be opened.';

  @override
  String get documentsSignAsk => 'Sign this document?';

  @override
  String get documentsRemarksOptional => 'Remarks (optional)';

  @override
  String get documentsNo => 'No';

  @override
  String get documentsSign => 'Sign';

  @override
  String get documentsSigned => 'Signed.';

  @override
  String get documentsRefuseAsk => 'Refuse to sign?';

  @override
  String get documentsReasonField => 'Write the reason';

  @override
  String get documentsGoBack => 'Go back';

  @override
  String get documentsRefuse => 'Do not sign';

  @override
  String get documentsReasonNeeded => 'Write the reason for not signing.';

  @override
  String get documentsRefused => 'Not signed — the reason was sent.';

  @override
  String get documentsDecisionFailed =>
      'The decision could not be sent. Try again.';

  @override
  String get documentsPaper => 'Document';

  @override
  String get documentsOneFailed => 'The document could not be loaded.';

  @override
  String get documentsNumber => 'Number';

  @override
  String get documentsType => 'Type';

  @override
  String get documentsFolder => 'Folder';

  @override
  String get documentsLevel => 'Confidentiality';

  @override
  String get documentsStatus => 'Status';

  @override
  String get documentsDate => 'Date';

  @override
  String get documentsExpiry => 'Expires';

  @override
  String get documentsOwner => 'Owner';

  @override
  String get documentsTags => 'Tags';

  @override
  String get documentsDescription => 'Description';

  @override
  String get documentsFile => 'File';

  @override
  String get documentsNoFile => 'This document has no file yet.';

  @override
  String get documentsFileName => 'Name';

  @override
  String get documentsVersion => 'Version';

  @override
  String get documentsViewFile => 'View';

  @override
  String get documentsDownload => 'Download / Send';

  @override
  String get documentsCannotOpen =>
      'This file cannot be shown on the phone, and you may not download it.';

  @override
  String get documentsSignature => 'Signature';

  @override
  String documentsSignedOld(String date) {
    return '$date · on an old version';
  }

  @override
  String get documentsSignWanted =>
      'Your signature is asked for on this document. Look at the file, then sign.';

  @override
  String get documentsUploadFormFailed =>
      'The upload form could not be loaded. Pull down to try again.';

  @override
  String get documentsUploadAbsent =>
      'Uploading from the phone has not reached the server yet — tell the office.';

  @override
  String get documentsUploadTakePhotoFirst =>
      'First take a photo of the document.';

  @override
  String get documentsUploadPickFirst =>
      'First pick at least one photo or file.';

  @override
  String get documentsUploadPickFields =>
      'Pick the folder, type and confidentiality.';

  @override
  String documentsUploaded(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count documents uploaded.',
      one: '$count document uploaded.',
    );
    return '$_temp0';
  }

  @override
  String get documentsUploadFailed => 'The document could not be uploaded.';

  @override
  String get documentsScanTitle => 'Scan with the camera';

  @override
  String get documentsUploadTitle => 'Upload a document';

  @override
  String get documentsLoadFailed => 'Could not load';

  @override
  String get documentsTakePhoto => 'Take a photo';

  @override
  String get documentsPickPhotos => 'Pick photos';

  @override
  String get documentsPickFiles => 'Pick files (PDF etc.)';

  @override
  String documentsUploadFileCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count files — each becomes its own document',
      one: '$count file — it becomes its own document',
    );
    return '$_temp0';
  }

  @override
  String get documentsUploadRulesNote =>
      'ⓘ Which file types are allowed, and how big, the server checks by the web\'s rules. Reading the text (OCR) is on the web.';

  @override
  String get documentsUploadName => 'Name (blank: the file\'s name)';

  @override
  String get documentsBranch => 'Branch';

  @override
  String get documentsWholeCompany => 'Whole company';

  @override
  String get documentsDescriptionOptional => 'Description (optional)';

  @override
  String get documentsUploading => 'Uploading…';

  @override
  String get documentsUpload => 'Upload';

  @override
  String get leadsFailed =>
      'The leads could not be loaded. Pull down to try again.';

  @override
  String get leadsAbsent =>
      'Leads have not reached the server yet — tell the office.';

  @override
  String get leadsTitle => 'Leads';

  @override
  String get leadsNew => 'New lead';

  @override
  String get leadsSearchHint => 'Name, phone or number';

  @override
  String get leadsAll => 'All';

  @override
  String get leadsTotal => 'Total leads';

  @override
  String leadsCount(int count) {
    return '$count';
  }

  @override
  String get leadsLoadFailed => 'Could not load';

  @override
  String get leadsNone => 'No leads';

  @override
  String get leadsMore => 'More leads';

  @override
  String get leadsNameNeeded => 'Write the shop\'s name.';

  @override
  String get leadsSourceNeeded => 'Pick how you found it.';

  @override
  String get leadsSaveFailed => 'The lead could not be saved. Try again.';

  @override
  String leadsStatusNow(String status) {
    return 'Status: $status';
  }

  @override
  String leadsOwner(String name) {
    return 'Owner: $name';
  }

  @override
  String get leadsConverted => 'Already a customer — can no longer be changed.';

  @override
  String get leadsShopName => 'Shop name';

  @override
  String get leadsContactPerson => 'Contact person';

  @override
  String get leadsPhone => 'Phone';

  @override
  String get leadsAddress => 'Address';

  @override
  String get leadsSource => 'How it was found';

  @override
  String get leadsStatus => 'Status';

  @override
  String get leadsLostReason => 'Why it was lost';

  @override
  String get leadsNotes => 'Notes';

  @override
  String papersListFailed(String title) {
    return 'The $title list could not be loaded.';
  }

  @override
  String get papersListAbsent =>
      'This list is not on this server yet — the app is newer than the server.';

  @override
  String get papersAllBranches => 'All branches';

  @override
  String get papersAll => 'All';

  @override
  String get papersSearchHint => 'Number or name';

  @override
  String get papersGrandTotal => 'Grand total';

  @override
  String papersPageTotal(int count) {
    return 'Total on this page ($count)';
  }

  @override
  String papersCount(int count) {
    return '$count';
  }

  @override
  String papersNone(String title) {
    return 'No $title';
  }

  @override
  String get papersNoneDated =>
      'Change the dates or filters, or pull down to look again.';

  @override
  String get papersNoneUndated => 'Pull down to look again.';

  @override
  String get papersMore => 'More';

  @override
  String papersOneFailed(String title) {
    return 'The $title could not be loaded.';
  }

  @override
  String get papersPrint => 'Print / Send';

  @override
  String get papersStatus => 'Status';

  @override
  String get papersBranch => 'Branch';

  @override
  String get papersLines => 'Lines';

  @override
  String papersTotalOf(String column) {
    return 'Total $column';
  }

  @override
  String get papersSignatures => 'Signatures';

  @override
  String papersRequestedBy(String name) {
    return 'Asked by: $name';
  }

  @override
  String papersSteps(String steps) {
    return 'Steps: $steps';
  }

  @override
  String get papersSign => 'Sign';

  @override
  String get collectTitle => 'Collection';

  @override
  String get collectNoLater => 'Number: given on saving';

  @override
  String collectNumber(String no) {
    return 'No: $no';
  }

  @override
  String collectBranchOf(String branch) {
    return 'Branch: the customer\'s branch ($branch)';
  }

  @override
  String get collectMonthOpen => 'Month open';

  @override
  String get collectMonthClosed => 'Month closed — posting is refused';

  @override
  String get collectFigureDue => 'Customer\'s due';

  @override
  String get collectFigureThis => 'This collection';

  @override
  String get collectFigureLeft => 'Left to pay';

  @override
  String get collectFigureCredit => 'Stays as credit to the customer';

  @override
  String get collectCustomer => 'Customer *';

  @override
  String get collectPickCustomer => 'Choose a customer';

  @override
  String collectDue(String amount) {
    return 'Due $amount';
  }

  @override
  String collectCreditLimit(String amount) {
    return 'Credit limit $amount';
  }

  @override
  String collectOldestOverdue(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: 'Oldest unpaid $days days',
      one: 'Oldest unpaid $days day',
    );
    return '$_temp0';
  }

  @override
  String get collectDate => 'Date *';

  @override
  String get collectAmount => 'Amount *';

  @override
  String get collectFullDue => 'Full due';

  @override
  String collectOverDue(String extra) {
    return '$extra more than the due — the extra amount stays as credit (Cr) in the customer\'s ledger.';
  }

  @override
  String get collectMethod => 'Method';

  @override
  String get collectMethodCash => 'Cash';

  @override
  String get collectMethodTransfer => 'Bank transfer';

  @override
  String get collectMethodDeposit => 'Cash deposit';

  @override
  String get collectMethodCheque => 'Cheque';

  @override
  String get collectAccount => 'Received into *';

  @override
  String collectAccountMine(String name) {
    return '$name · my own cash';
  }

  @override
  String get collectTransferMode => 'Transfer mode';

  @override
  String get collectReference => 'Reference / TrxID *';

  @override
  String get collectDepositSlip => 'Deposit slip no *';

  @override
  String get collectDepositSlipMissing => 'Enter the deposit slip number.';

  @override
  String get collectValueDate => 'Value date';

  @override
  String get collectChequeDate => 'Cheque date';

  @override
  String get collectBankCharge => 'Bank charge (Tk)';

  @override
  String get collectBankChargeHint =>
      'The charge goes to the bank charge account; the customer is credited the full amount.';

  @override
  String get collectBillsTitle => 'Open invoices and credits';

  @override
  String get collectBillsAuto => 'Automatic: oldest invoices first';

  @override
  String collectBillsAutoWith(String list) {
    return 'Automatic: oldest invoices first — $list';
  }

  @override
  String get collectBillsChange => 'Change';

  @override
  String get collectBillsFold => 'Fold';

  @override
  String get collectBillsHint =>
      'Oldest first, set automatically · tick or change an amount to adjust';

  @override
  String get collectBillsOldestFirst => 'Oldest invoices first';

  @override
  String get collectBillsNone =>
      'This customer has no open invoice — the amount stays as credit in their ledger and comes off the next invoice.';

  @override
  String get collectBillsNeedCustomer =>
      'Choose a customer to see the open invoices.';

  @override
  String get collectBillsFailed =>
      'The open invoices could not be loaded — the server will apply the oldest first.';

  @override
  String collectItemDueOn(String date) {
    return 'Due $date';
  }

  @override
  String collectItemLate(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days late',
      one: '$days day late',
    );
    return '$_temp0';
  }

  @override
  String collectItemOpen(String amount) {
    return 'Open $amount';
  }

  @override
  String collectItemOriginal(String amount) {
    return 'Original amount $amount';
  }

  @override
  String get collectItemApply => 'Apply now';

  @override
  String collectItemApplyOf(String no) {
    return '$no — apply now';
  }

  @override
  String collectItemTick(String no) {
    return 'Apply $no';
  }

  @override
  String get collectItemCredit => 'Credit';

  @override
  String get collectItemCreditNote => 'Credit note';

  @override
  String get collectItemDebitNote => 'Debit note';

  @override
  String get collectAppliedTotal => 'Total applied';

  @override
  String collectOverApplied(String amount) {
    return 'Cannot apply more — at most $amount.';
  }

  @override
  String get collectCreditFloor =>
      'Cannot take less — the ticked invoices need that amount.';

  @override
  String get collectDifference => 'Discount / Rounding';

  @override
  String get collectDifferenceDiscount => 'Discount';

  @override
  String get collectDifferenceRounding => 'Rounding';

  @override
  String get collectDifferenceAmount => 'Discount / rounding figure';

  @override
  String get collectDifferenceSign =>
      'Needs the owner\'s signature — posting sends it for signing.';

  @override
  String get collectWithheldTitle => 'Withheld at source (optional)';

  @override
  String get collectVds => 'Withheld by the customer: VAT at source (VDS)';

  @override
  String get collectAit => 'Withheld by the customer: tax at source (AIT)';

  @override
  String get collectSummaryTitle => 'Calculation summary';

  @override
  String get collectSumReceived => 'Amount received';

  @override
  String get collectSumApplied => 'Applied to invoices';

  @override
  String get collectSumUnapplied =>
      'Unapplied (credit in the customer\'s ledger)';

  @override
  String get collectSumBalanced =>
      'Balanced: received + withheld + discount = applied + unapplied';

  @override
  String get collectJournalTitle => 'What goes to the books';

  @override
  String get collectJournalHint => 'See before posting';

  @override
  String get collectJournalAccount => 'Account';

  @override
  String get collectJournalBranch => 'Branch';

  @override
  String get collectJournalDr => 'Debit';

  @override
  String get collectJournalCr => 'Credit';

  @override
  String get collectJournalTotal => 'Total';

  @override
  String get collectJournalBalanced => 'Totals match';

  @override
  String get collectJournalUnbalanced =>
      'Totals do not match — do not post; tell the office.';

  @override
  String get collectControlsTitle => 'Controls';

  @override
  String get collectControlSignature =>
      'Needs a signature — after posting it is awaiting signature.';

  @override
  String get collectControlNoSignature =>
      'No signature needed — posting puts it in the books at once.';

  @override
  String get collectControlSlip => 'This company requires a slip photo.';

  @override
  String get collectControlNoUndo =>
      'A posted collection cannot be edited; to correct it, reverse it with a reason (a second person signs).';

  @override
  String get collectControlStops =>
      'Posting is blocked for the reason above — it can be kept as a draft.';

  @override
  String get collectAttachTitle => 'Attachment and narration';

  @override
  String get collectSlip => 'Slip photo';

  @override
  String get collectSlipNeeded => 'Slip photo *';

  @override
  String get collectSlipCamera => 'Camera';

  @override
  String get collectSlipGallery => 'Gallery';

  @override
  String get collectSlipRemove => 'Remove photo';

  @override
  String collectSlipChosen(String name) {
    return 'Photo chosen: $name';
  }

  @override
  String get collectSlipMissing =>
      'Add the slip photo — this company requires it.';

  @override
  String get collectSlipTooBig =>
      'The photo is over 5 MB — choose a smaller one.';

  @override
  String get collectSlipFailed =>
      'The collection is saved, but the slip photo could not be sent — add it again from the receipt.';

  @override
  String get collectNarration => 'Narration (optional)';

  @override
  String get collectDraft => 'Save draft';

  @override
  String get collectPost => 'Post';

  @override
  String get collectReferenceMissing => 'Enter the reference / TrxID.';

  @override
  String get collectDifferenceTooBig =>
      'The discount / rounding cannot be more than the amount received.';

  @override
  String get collectCheckTitle => 'Check before posting';

  @override
  String get collectCheckCustomer => 'Customer';

  @override
  String get collectCheckAmount => 'Amount';

  @override
  String get collectCheckWords => 'In words';

  @override
  String get collectCheckAccount => 'Received into';

  @override
  String get collectCheckBills => 'Applied to';

  @override
  String get collectCheckBack => 'Go back';

  @override
  String get collectCheckPost => 'Post now';

  @override
  String collectDone(String no) {
    return 'Collection saved: $no';
  }

  @override
  String collectDoneWords(String words) {
    return 'In words: $words';
  }

  @override
  String get collectOpenReceipt => 'Open the receipt';

  @override
  String get collectReceiptHint =>
      'After posting: the receipt (amount in words) · PDF · WhatsApp or email';

  @override
  String get collectNewOne => 'New collection';

  @override
  String get collectClose => 'Done';

  @override
  String get collectOtherIncome => 'Other income';

  @override
  String get collectOtherIncomeHint =>
      'From someone who is not a customer, such as rent';

  @override
  String get collectFromCustomerHint => 'From a customer — on one page';
}
