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
