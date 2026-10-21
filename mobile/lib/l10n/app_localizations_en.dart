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
  String get booksMoneyInAllMethods => 'All';

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
  String get booksFactStatus => 'Status';

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
}
