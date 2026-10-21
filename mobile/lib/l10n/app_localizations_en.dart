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
}
