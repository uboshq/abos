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
  String get navTabChallans => 'Delivery challans';

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
    return '$amount sent back as credit on the next purchase';
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
    return 'Billed $billed · Sent back $returned';
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
}
