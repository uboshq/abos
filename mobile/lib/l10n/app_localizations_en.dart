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
  String get financeLoadFailed => 'Could not load. Pull down to try again.';

  @override
  String get financeNotOnServer =>
      'This part of finance is not on the server yet — tell the office.';

  @override
  String get financeDaysToday => 'Today';

  @override
  String financeDaysLeft(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days left',
      one: '$days day left',
    );
    return '$_temp0';
  }

  @override
  String financeDaysOverdue(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days overdue',
      one: '$days day overdue',
    );
    return '$_temp0';
  }

  @override
  String get financeStatusActive => 'Active';

  @override
  String get financeStatusAwaitingSign => 'Awaiting signature';

  @override
  String get financeStatusEnded => 'Ended';

  @override
  String get financeStatusCancelled => 'Cancelled';

  @override
  String get financeStatusClosed => 'Closed';

  @override
  String get financeStatusDraft => 'Draft';

  @override
  String get financeDepositMoveOpened => 'Amount deposited';

  @override
  String get financeDepositMovePayout => 'Profit withdrawn';

  @override
  String get financeDepositMoveClosed => 'Encashed / matured';

  @override
  String get financeLoanKindCc => 'Cash credit (CC)';

  @override
  String get financeLoanKindTerm => 'Term loan';

  @override
  String get financeLoanKindLease => 'Lease';

  @override
  String get financeLoanKindGuarantee => 'Bank guarantee';

  @override
  String get financeInstalmentPaid => 'Paid';

  @override
  String get financeInstalmentOverdue => 'Overdue';

  @override
  String get financeInstalmentToday => 'Due today';

  @override
  String get financeInstalmentUpcoming => 'Upcoming';

  @override
  String get financeInstalmentNoDate => 'No date';

  @override
  String get financeRepaymentLump => 'Lump sum';

  @override
  String get financeRepaymentMonthly => 'Monthly';

  @override
  String get financeRepaymentWhenever => 'When able';

  @override
  String get financeDepositListTitle => 'Deposits & savings';

  @override
  String get financeDepositListEmpty => 'No deposits in this tab';

  @override
  String get financeDepositTabMaturing => 'Expires soon';

  @override
  String financeDepositMatures(String date) {
    return 'Expires $date';
  }

  @override
  String get financeDepositTitle => 'Deposit';

  @override
  String get financeDepositLoadFailed => 'Could not load the deposit.';

  @override
  String get financeDepositAmount => 'Deposit amount';

  @override
  String get financeFactType => 'Type';

  @override
  String get financeFactStatus => 'Status';

  @override
  String get financeDepositInstitution => 'Institution';

  @override
  String get financeDepositBankRef => 'Bank reference';

  @override
  String get financeDepositHeldBy => 'Held in the name of';

  @override
  String get financeDepositHeldOwner => 'Owner';

  @override
  String financeDepositHeldOwnerPerson(String person) {
    return 'Owner — $person';
  }

  @override
  String get financeDepositHeldBusiness => 'Business';

  @override
  String get financeDepositProfitRate => 'Profit rate';

  @override
  String get financeDepositOpened => 'Opened';

  @override
  String get financeDepositMaturity => 'Expires';

  @override
  String get financeDepositMonthlyInstalment => 'Monthly instalment';

  @override
  String financeDayOfMonth(String day) {
    return 'Day $day of each month';
  }

  @override
  String get financeDepositClosedOn => 'Closed on';

  @override
  String get financeDepositPledged => 'Pledged';

  @override
  String get financeDepositPledgedNote =>
      'Pledged against a loan — cannot be encashed';

  @override
  String get financeNote => 'Note';

  @override
  String get financeDepositMovements => 'Movements';

  @override
  String get financeLoanListTitle => 'Bank loans & instalments';

  @override
  String get financeLoanListEmpty => 'No loans in this tab';

  @override
  String financeLoanLimitLine(String amount) {
    return 'Limit $amount';
  }

  @override
  String financeLoanNextLine(String date, String amount, String state) {
    return 'Next instalment $date · $amount · $state';
  }

  @override
  String get financeLoanTitle => 'Bank loan';

  @override
  String get financeLoanLoadFailed => 'Could not load the loan.';

  @override
  String get financeLoanOutstanding => 'Outstanding now';

  @override
  String get financeLoanBank => 'Bank';

  @override
  String get financeLoanLimit => 'Limit';

  @override
  String get financeLoanAvailable => 'Limit available';

  @override
  String get financeInterestRate => 'Interest rate';

  @override
  String get financeLoanSanctionNo => 'Sanction no.';

  @override
  String get financeLoanSanctioned => 'Sanctioned';

  @override
  String get financeLoanRenewal => 'Renewal';

  @override
  String get financeInstalment => 'Instalment';

  @override
  String financeLoanInstalmentCounts(String paid, String left, String total) {
    return '$paid paid · $left left · $total in all';
  }

  @override
  String get financeLoanInstalmentAmount => 'Instalment amount';

  @override
  String get financeLoanOverdueInstalments => 'Overdue instalments';

  @override
  String financeLoanOverdueCount(String count) {
    return '$count';
  }

  @override
  String get financeLoanNextInstalment => 'Next instalment';

  @override
  String get financeLoanSchedule => 'Upcoming instalments';

  @override
  String financeLoanScheduleTitle(String no, String date) {
    return 'Instalment $no · $date';
  }

  @override
  String financeLoanScheduleSplit(String principal, String interest) {
    return 'Principal $principal · Interest $interest';
  }

  @override
  String get financeRentTitle => 'Rent';

  @override
  String get financeRentListEmpty => 'No rent agreements here';

  @override
  String get financeRentSidePay => 'We pay rent';

  @override
  String get financeRentSideLet => 'We receive rent';

  @override
  String financeRentNextLine(String date) {
    return 'Next rent $date';
  }

  @override
  String financeRentTenantOwes(String amount) {
    return 'Tenant owes $amount';
  }

  @override
  String financeRentMonthsDue(int months, String amount) {
    String _temp0 = intl.Intl.pluralLogic(
      months,
      locale: localeName,
      other: '$months months\' rent due · $amount',
      one: '$months month\'s rent due · $amount',
    );
    return '$_temp0';
  }

  @override
  String get financeRentLoadFailed => 'Could not load the rent agreement.';

  @override
  String get financeRentMonthlyWeLet => 'Monthly rent (we receive)';

  @override
  String get financeRentMonthlyWePay => 'Monthly rent (we pay)';

  @override
  String get financeRentTenant => 'Tenant';

  @override
  String get financeRentLandlord => 'Landlord';

  @override
  String get financeRentPhone => 'Phone';

  @override
  String get financeRentPlace => 'Premises';

  @override
  String get financeRentAgreementNo => 'Agreement no.';

  @override
  String get financeRentDay => 'Rent day';

  @override
  String get financeRentNext => 'Next rent';

  @override
  String get financeRentStarts => 'Starts';

  @override
  String get financeRentEnds => 'Ends';

  @override
  String get financeRentSecurity => 'Security deposit';

  @override
  String get financeRentSecurityHeld => 'Security deposit held';

  @override
  String get financeRentSecurityLeft => 'Security deposit left';

  @override
  String get financeRentOldestUnpaid => 'Oldest unpaid month';

  @override
  String get financeRentUnpaidMonths => 'Unpaid months';

  @override
  String get financeHandLoanListTitle => 'Hand loans';

  @override
  String get financeHandLoanListEmpty => 'No open hand loans';

  @override
  String get financeHandLoanToUs => 'We will receive';

  @override
  String get financeHandLoanWeOwe => 'We will pay';

  @override
  String financeHandLoanDueLine(String date) {
    return 'Repayment date $date';
  }

  @override
  String get financeHandLoanTheyOwe => 'They owe us';

  @override
  String get financeHandLoanWeOweThem => 'We owe them';

  @override
  String get financeHandLoanSettled => 'Settled';

  @override
  String get financeHandLoanTitle => 'Hand loan';

  @override
  String get financeHandLoanLoadFailed => 'Could not load the hand loan.';

  @override
  String get financeHandLoanMobile => 'Mobile';

  @override
  String get financeHandLoanPrincipal => 'Principal';

  @override
  String get financeHandLoanRepaymentType => 'Repayment type';

  @override
  String get financeHandLoanDueDate => 'Repayment date';

  @override
  String get financeHandLoanStatusSettled => 'Settled';

  @override
  String get financeHandLoanMoveButton => 'Amount given / sent back';

  @override
  String get financeHandLoanTransactions => 'Transactions';

  @override
  String get financeHandLoanGiven => 'Amount given';

  @override
  String get financeHandLoanReceived => 'Amount received';

  @override
  String financeHandLoanReturnLine(String date) {
    return 'Due back $date';
  }

  @override
  String get accountsChartLoadFailed =>
      'Could not load the chart of accounts. Pull down to try again.';

  @override
  String get accountsChartNotOnServer =>
      'The phone chart of accounts is not on the server yet — tell the office.';

  @override
  String get accountsChartTitle => 'Chart of accounts';

  @override
  String get accountsChartSearchHint => 'Search by code or name';

  @override
  String accountsChartCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count accounts in all',
      one: '$count account in all',
    );
    return '$_temp0';
  }

  @override
  String get accountsChartFetchFailed => 'Could not load';

  @override
  String get accountsChartNoMatch => 'No accounts found';

  @override
  String get accountsChartLedgerLoadFailed =>
      'Could not load the ledger. Pull down to try again.';

  @override
  String get accountsChartGroupAccount =>
      'This is a group account — its entries are in the accounts under it. Go back to the chart and open one of them.';

  @override
  String get accountsChartNoEntries => 'No entries in this account';

  @override
  String get accountsChartMoreEntries => 'More entries';

  @override
  String accountsChartLedgerTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'Grand total · $count entries',
      one: 'Grand total · $count entry',
    );
    return '$_temp0';
  }

  @override
  String get accountsChartDebit => 'Debit';

  @override
  String get accountsChartCredit => 'Credit';

  @override
  String get accountsChartClosing => 'Closing balance';

  @override
  String accountsChartDebitLine(String amount) {
    return 'Debit $amount';
  }

  @override
  String accountsChartCreditLine(String amount) {
    return 'Credit $amount';
  }

  @override
  String get vouchersKindSuffix => ' voucher';

  @override
  String get vouchersListLoadFailed =>
      'Could not load the voucher list. Pull down to try again.';

  @override
  String get vouchersListNotOnServer =>
      'Phone vouchers are not on the server yet — tell the office.';

  @override
  String get vouchersListTitle => 'Vouchers';

  @override
  String get vouchersListAllTitle => 'All vouchers';

  @override
  String get vouchersListNew => 'New voucher';

  @override
  String get vouchersListAwaitingSign => 'Awaiting signature';

  @override
  String get vouchersListAllBranches => 'All branches';

  @override
  String vouchersListTotal(String kind) {
    return 'Total · $kind';
  }

  @override
  String get vouchersListEmpty => 'No vouchers.';

  @override
  String vouchersListWrittenBy(String name) {
    return 'Entered by: $name';
  }

  @override
  String get vouchersPageLoadFailed => 'Could not load the voucher.';

  @override
  String get vouchersPagePostFailed => 'Could not be posted. Try again.';

  @override
  String get vouchersPagePrint => 'Print or share';

  @override
  String get vouchersPageType => 'Type';

  @override
  String get vouchersAmount => 'Amount';

  @override
  String get vouchersDate => 'Date';

  @override
  String get vouchersTrxNo => 'Transaction no.';

  @override
  String get vouchersNarration => 'Narration';

  @override
  String get vouchersPageWriter => 'Written by';

  @override
  String vouchersDebitLine(String amount) {
    return 'Debit $amount';
  }

  @override
  String vouchersCreditLine(String amount) {
    return 'Credit $amount';
  }

  @override
  String get vouchersPageAnotherHand =>
      'A voucher you wrote cannot be posted by you — someone else opens it and taps \"Post\".';

  @override
  String get vouchersPageTrxLabel => 'Transaction no. (TrxID)';

  @override
  String get vouchersPagePost => 'Post';

  @override
  String get vouchersFormSetupFailed =>
      'Could not load the account list. Check the internet and open again.';

  @override
  String get vouchersFormSetupMissing => 'The account list has not loaded yet.';

  @override
  String get vouchersFormNarrationRequired =>
      'Write a narration — this company requires one.';

  @override
  String get vouchersFormTwoLines => 'Choose accounts on at least two lines.';

  @override
  String get vouchersFormAmountShape =>
      'The amount is not valid — up to two decimals.';

  @override
  String get vouchersFormBothSides =>
      'A line cannot have both debit and credit.';

  @override
  String get vouchersFormUnbalanced => 'Debit and credit do not match.';

  @override
  String get vouchersFormPickAccounts => 'Choose the accounts on both sides.';

  @override
  String get vouchersFormSameAccount =>
      'Both sides cannot be the same account.';

  @override
  String get vouchersFormAmountInvalid =>
      'The amount is not valid — above zero, up to two decimals.';

  @override
  String get vouchersFormNotSaved => 'The voucher was not saved. Try again.';

  @override
  String get vouchersFormMayHaveLanded =>
      'No reply for the voucher — it may have reached the server — tap again without changing anything; if the first one was saved it will show, it will not be saved twice.';

  @override
  String get vouchersFormNeedsNet =>
      'Writing a voucher needs the internet. Turn it on and tap again — nothing was kept on the phone.';

  @override
  String get vouchersFormSendFailed => 'Could not send. Try again.';

  @override
  String get vouchersFormPartyOptional =>
      'Party (optional — needed for an expense on credit)';

  @override
  String get vouchersFormClear => 'Clear';

  @override
  String get vouchersFormParty => 'Party';

  @override
  String get vouchersFormFrom => 'From';

  @override
  String get vouchersFormTo => 'To';

  @override
  String get vouchersFormTrxLabel =>
      'Transaction no. (TrxID) — needed before it is posted';

  @override
  String get vouchersFormAccount => 'Account';

  @override
  String get vouchersFormDebit => 'Debit';

  @override
  String get vouchersFormCredit => 'Credit';

  @override
  String get vouchersFormAddLine => 'Add line';

  @override
  String vouchersFormTotals(String debit, String credit) {
    return 'Debit $debit · Credit $credit';
  }

  @override
  String get vouchersFormNarrationMust => 'Narration (required)';

  @override
  String get vouchersFormKeepDraft => 'Keep as draft';

  @override
  String get vouchersFormKeepDraftHint =>
      'When off, it is posted on save — it stays a draft if signatures are needed or you wrote it';

  @override
  String get vouchersFormSave => 'Save';

  @override
  String get vouchersPickNothing => 'Nothing found.';

  @override
  String get moneyResultDone => 'Saved.';

  @override
  String get moneyResultPending => 'Awaiting signature.';

  @override
  String moneyLoadFailed(String title) {
    return 'Could not load $title.';
  }

  @override
  String get moneyPageMissing =>
      'This page is not on this server yet — the app is newer than the server.';

  @override
  String get moneyCustodyTitle => 'Who holds the money';

  @override
  String get moneyCustodyOnRoad => 'Amount in transit';

  @override
  String get moneyCustodyEmpty => 'No money accounts';

  @override
  String get moneyCustodyInTransit => 'In transit';

  @override
  String get moneyTransferListTitle => 'Money handover';

  @override
  String get moneyTransferSend => 'Send';

  @override
  String get moneyTransferTotal => 'Total handed over';

  @override
  String get moneyTransferAwaitingMe => 'Waiting for me to receive';

  @override
  String get moneyTransferEmpty => 'No handovers';

  @override
  String get moneyPullToRefresh => 'Pull down to refresh.';

  @override
  String get moneyTransferAll => 'All';

  @override
  String get moneyActionFailed => 'Could not do that.';

  @override
  String get moneyTransferCancelWhy => 'Why cancel?';

  @override
  String get moneyTransferKeep => 'Keep';

  @override
  String get moneyTransferCancelConfirm => 'Cancel handover';

  @override
  String get moneyTransferTitle => 'Handover';

  @override
  String get moneyFactStatus => 'Status';

  @override
  String get moneyFactDate => 'Date';

  @override
  String get moneyTransferFrom => 'From';

  @override
  String get moneyTransferTo => 'To';

  @override
  String get moneyFactAmount => 'Amount';

  @override
  String get moneyTransferGiver => 'Given by';

  @override
  String get moneyTransferReceiver => 'To be received by';

  @override
  String get moneyTransferNarration => 'Description';

  @override
  String get moneyTransferCancelReason => 'Cancellation reason';

  @override
  String get moneyTransferReceived => 'Amount received';

  @override
  String get moneyTransferCancel => 'Cancel';

  @override
  String get moneyTillsLoadFailed => 'Could not load the tills.';

  @override
  String get moneyTransferMissing =>
      'From, to and how much — all are required.';

  @override
  String get moneyTransferSendFailed => 'Could not send the handover.';

  @override
  String get moneySendTitle => 'Send money';

  @override
  String get moneyTransferFromTill => 'From which till';

  @override
  String get moneyTransferToLabel => 'To — till or bank';

  @override
  String get moneyTransferReceiverLabel => 'Received by (optional)';

  @override
  String get moneyTransferNoteLabel => 'Description (optional)';

  @override
  String get moneyCashCountTitle => 'Cash count';

  @override
  String get moneyCashCountNew => 'Count';

  @override
  String get moneyCashCountTotal => 'Total counts';

  @override
  String moneyCashCountTotalValue(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count counts',
      one: '$count count',
    );
    return '$_temp0';
  }

  @override
  String get moneyCashCountEmpty => 'No counts';

  @override
  String moneyCashCountDifference(String difference) {
    return 'Difference $difference';
  }

  @override
  String get moneyFactTill => 'Till';

  @override
  String get moneyFactCounted => 'Counted';

  @override
  String get moneyFactExpected => 'In the books';

  @override
  String get moneyFactDifference => 'Difference';

  @override
  String moneyCashCountNoteRow(String note) {
    return '$note-taka notes';
  }

  @override
  String moneyCashCountNoteQty(String qty) {
    return '$qty pcs';
  }

  @override
  String get moneyCashCountBy => 'Counted by';

  @override
  String get moneyCashCountApprovedBy => 'Accepted by';

  @override
  String get moneyCashCountAdjustment => 'Adjustment voucher';

  @override
  String get moneyCashCountApproveFailed => 'Could not accept.';

  @override
  String get moneyCashCountApprove => 'Accept';

  @override
  String get moneyCashCountPickTill => 'Choose a till.';

  @override
  String get moneyCashCountSaveFailed => 'Could not save the count.';

  @override
  String get moneyCashCountNewTitle => 'Count cash';

  @override
  String get moneyCashCountTillLabel => 'Which till';

  @override
  String moneyCashCountTillOption(String name, String balance) {
    return '$name · in the books $balance';
  }

  @override
  String moneyCashCountNoteLabel(String note) {
    return '$note-taka notes — how many';
  }

  @override
  String get moneyCashCountSave => 'Save count';

  @override
  String get moneyCashCountHint =>
      'Saving a count does not touch the books — the difference reaches the books only when someone else accepts it.';

  @override
  String get moneyAccountsLoadFailed => 'Could not load the money accounts.';

  @override
  String get moneyHandLoanMissing => 'Money account and amount are required.';

  @override
  String get moneyHandLoanSaveFailed => 'Could not save the hand loan.';

  @override
  String moneyHandLoanTitle(String name) {
    return 'Hand loan · $name';
  }

  @override
  String get moneyHandLoanOut => 'Amount given';

  @override
  String get moneyHandLoanIn => 'Got back';

  @override
  String get moneyHandLoanFromAccount => 'From which account';

  @override
  String get moneyHandLoanToAccount => 'Into which account';

  @override
  String get moneyHandLoanNoteLabel => 'Note (optional)';

  @override
  String get moneyHandLoanSave => 'Save';

  @override
  String get moneyCfoTitle => 'CFO page';

  @override
  String moneyCfoIn30Days(String days) {
    return 'In hand after $days days';
  }

  @override
  String get moneyBudgetExpenseMonth => 'This month\'s expense budget';

  @override
  String get moneyBudgetMonth => 'This month\'s budget';

  @override
  String get moneyBudgetLabel => 'Budget';

  @override
  String get moneyBudgetSpent => 'Spent';

  @override
  String get moneyBudgetUsed => 'Used';

  @override
  String get moneyForecastTitle => 'Cash forecast';

  @override
  String get moneyForecastToday => 'In hand today';

  @override
  String moneyForecastPeriod(String when, String until) {
    return '$when (until $until)';
  }

  @override
  String get moneyForecastReceivables => 'In — receivables';

  @override
  String get moneyForecastLoansIn => 'In — loans';

  @override
  String get moneyForecastPayables => 'Out — payables';

  @override
  String get moneyForecastLoansOut => 'Out — loan repayment';

  @override
  String get moneyForecastNet => 'Net';

  @override
  String get moneyIncomeTitle => 'Earnings';

  @override
  String get moneyIncomeTotal => 'Gross earnings · this month';

  @override
  String get moneyIncomeSales => 'Sales';

  @override
  String get moneyIncomeOther => 'Other earnings';

  @override
  String get moneyIncomeByHead => 'By head';

  @override
  String get moneyIncomeEmpty => 'No earnings this month';

  @override
  String moneyIncomeBefore(String amount) {
    return 'Previous period $amount';
  }

  @override
  String get moneyBudgetTitle => 'Budget';

  @override
  String get moneyBudgetEmpty => 'No budget for this month';

  @override
  String get moneyBudgetSetOnWeb => 'Set the budget on the web.';

  @override
  String get moneyPurchaseBudget => 'Purchase budget';

  @override
  String get moneyPurchaseBought => 'Purchased';

  @override
  String get creditListHighRisk => 'High risk';

  @override
  String get creditListOverdueBills => 'Overdue bills';

  @override
  String get creditOverLimit => 'Over limit';

  @override
  String get creditListAll => 'All customers';

  @override
  String get creditDeskLoadFailed => 'Could not load the credit figures.';

  @override
  String get creditDeskMissing =>
      'Credit control is not on this server yet — the app is newer than the server.';

  @override
  String get creditDeskTitle => 'Credit control';

  @override
  String get creditDeskSearchHint => 'Customer name or code';

  @override
  String get creditDeskEmptyHighRisk => 'No high-risk customers';

  @override
  String get creditDeskEmptyOverdue => 'No overdue bills';

  @override
  String get creditDeskEmptyOverLimit => 'No one is over the limit';

  @override
  String get creditDeskEmptyAll => 'No customers';

  @override
  String creditDeskShowingFirst(String shown, String total) {
    return 'Showing the first $shown of $total — search to find more.';
  }

  @override
  String get creditDashUsed => 'Credit used';

  @override
  String get creditDashCheckOff =>
      'Limit checks are off in this company — figures are for viewing only.';

  @override
  String get creditDashTotalLimit => 'Total limit';

  @override
  String get creditAvailable => 'Available limit';

  @override
  String get creditDashUsedShare => 'Limit used';

  @override
  String get creditDashOverdue => 'Overdue receivables';

  @override
  String get creditDashOverLimit => 'Customers over limit';

  @override
  String get creditOverdueStopped => 'Stopped for overdue credit';

  @override
  String get creditDashBlocked => 'Customers on credit hold';

  @override
  String get creditDashPending =>
      'Limit increase requests (awaiting signature)';

  @override
  String creditPeople(String count) {
    return '$count';
  }

  @override
  String creditDashPendingCount(String count) {
    return '$count';
  }

  @override
  String creditRowBill(String no) {
    return 'Bill $no';
  }

  @override
  String creditRowOverdueDays(String days) {
    return 'Overdue by $days days';
  }

  @override
  String creditRowRisk(String level) {
    return 'Risk: $level';
  }

  @override
  String creditRowLimit(String limit, String available) {
    return 'Limit $limit · available $available';
  }

  @override
  String get creditCustomerLoadFailed =>
      'Could not load the customer\'s credit page.';

  @override
  String get creditCustomerTitle => 'Credit page';

  @override
  String get creditBlocked => 'Credit hold';

  @override
  String get creditBouncedOwnerOnly =>
      'Bounced-cheque hold — only the owner can lift it';

  @override
  String get creditLift => 'Lift credit hold';

  @override
  String creditUsedPercent(String percent) {
    return '$percent% of limit used';
  }

  @override
  String get creditLimit => 'Limit';

  @override
  String get creditTempLimit => 'Temporary limit';

  @override
  String creditTempLimitValue(String amount, String date) {
    return '$amount (until $date)';
  }

  @override
  String get creditCurrentLimit => 'Current limit';

  @override
  String get creditDays => 'Credit days';

  @override
  String creditDaysValue(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days',
      one: '$days day',
    );
    return '$_temp0';
  }

  @override
  String get creditDue => 'Due in the books';

  @override
  String get creditHeld => 'Held';

  @override
  String get creditUncleared => 'Uncleared cheques';

  @override
  String get creditUsed => 'Used';

  @override
  String get creditRiskTitle => 'Risk level';

  @override
  String get creditRiskNone => 'No warning signs';

  @override
  String get creditRiskNote =>
      'The level only informs — it does not change the limit or the wall.';

  @override
  String get creditRequest => 'Limit increase request';

  @override
  String get creditOpenBills => 'Open bills';

  @override
  String get creditNoOpenBills => 'No open bills';

  @override
  String creditBillDueOverdue(String date, int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days',
      one: '$days day',
    );
    return 'Due $date — $_temp0 overdue';
  }

  @override
  String creditBillDue(String date) {
    return 'Due $date';
  }

  @override
  String get creditNoRequests => 'No requests';

  @override
  String creditRequestTemporaryUntil(String date) {
    return 'Temporary, until $date';
  }

  @override
  String get creditStandardLimit => 'Standard limit';

  @override
  String get creditSheetNeedAmount => 'Enter the requested limit.';

  @override
  String get creditNeedReason => 'Enter a reason.';

  @override
  String get creditSheetNeedUntil =>
      'Choose the last day of the temporary limit.';

  @override
  String get creditSheetSendFailed => 'Could not send the request.';

  @override
  String creditSheetTitle(String name) {
    return 'Limit increase request — $name';
  }

  @override
  String creditSheetCurrent(String amount) {
    return 'Current limit $amount';
  }

  @override
  String get creditSheetAmount => 'Requested limit (Tk)';

  @override
  String get creditSheetPickUntil => 'Choose the last day';

  @override
  String creditSheetUntil(String date) {
    return 'Last day $date';
  }

  @override
  String get creditReason => 'Reason';

  @override
  String get creditSheetOwnerNote =>
      'It takes effect only once the owner signs; until then the old limit applies.';

  @override
  String get creditSheetSending => 'Sending…';

  @override
  String get creditSheetSend => 'Send for signature';

  @override
  String get creditLiftFailed => 'Could not lift the credit hold.';

  @override
  String get creditLiftKeep => 'Keep';

  @override
  String get creditLiftSend => 'Lift';

  @override
  String get creditStateActive => 'Active';

  @override
  String get creditStateUnchecked => 'Limit check off';

  @override
  String get creditRiskLow => 'Low';

  @override
  String get creditRiskMedium => 'Medium';

  @override
  String get creditRiskHigh => 'High';

  @override
  String get creditRiskCritical => 'Critical';

  @override
  String get creditRequestPending => 'Awaiting signature';

  @override
  String get creditRequestApproved => 'Signed — applied';

  @override
  String get creditRequestRejected => 'Rejected';

  @override
  String get creditRequestWithdrawn => 'Withdrawn';

  @override
  String get creditRequestExpired => 'Expired';

  @override
  String get creditRequestVoid => 'No owner signature — not applied';

  @override
  String get creditApiRequestSent => 'Request sent.';

  @override
  String get creditApiLifted => 'Credit hold lifted.';

  @override
  String get printingPaperA4 => 'A4 paper';

  @override
  String get printingPaperA5 => 'A5 paper';

  @override
  String printingPaperRoll(String width) {
    return 'Receipt ${width}mm';
  }

  @override
  String get printingBluetoothPermission =>
      'Printing on a Bluetooth printer needs the phone\'s \"Nearby devices\" permission. It was not given — turn it on in the phone\'s Settings → Apps → ABOS → Permissions.';

  @override
  String get printingBluetoothOff =>
      'The phone\'s Bluetooth is off. Turn it on and try again.';

  @override
  String get printingPairedListFailed =>
      'Could not get the list of paired printers. Try again.';

  @override
  String get printingRasteriseFailed =>
      'Could not turn the document into an image. Try the normal printer.';

  @override
  String get printingNoPages => 'The document has no pages to print.';

  @override
  String get printingConnectFailed =>
      'Could not connect to the printer. Check that it is on, nearby and not connected to another phone.';

  @override
  String get printingSendFailed =>
      'Could not send to the printer — the connection dropped. Check the printer\'s paper and battery and try again.';

  @override
  String get printingTestPageTitle => 'Test print';

  @override
  String printingTestPageSize(String width, String dots) {
    return 'Paper $width mm · $dots dots';
  }

  @override
  String get printingTestPageCheck =>
      'If the Bengali sample below prints correctly, the printer is ready.';

  @override
  String get printingActionsLoadPapersFailed =>
      'Could not load the print sizes for this document. Try again.';

  @override
  String get printingActionsFetchFailed =>
      'Could not get the document from the server — nothing was printed. Check the internet connection and try again.';

  @override
  String get printingActionsFailed => 'That did not work.';

  @override
  String printingActionsSaved(String path) {
    return 'Saved: $path';
  }

  @override
  String printingActionsSentTo(String printer) {
    return 'Sent to $printer.';
  }

  @override
  String get printingActionsRetry => 'Try again';

  @override
  String get printingActionsNormalPrinter => 'Normal printer';

  @override
  String get printingActionsNoSheetLayout =>
      'No A4/A5 layout for this document';

  @override
  String get printingActionsNormalSubtitle => 'A4/A5 · Wi-Fi, USB, PDF';

  @override
  String get printingActionsThermalPrinter => 'Thermal printer';

  @override
  String get printingActionsNoReceiptLayout =>
      'No receipt layout for this document';

  @override
  String printingActionsThermalSubtitle(String narrow, String wide) {
    return '$narrow/$wide mm · Bluetooth';
  }

  @override
  String get printingActionsView => 'View on screen';

  @override
  String get printingActionsShare => 'Share';

  @override
  String get printingActionsShareHint => 'WhatsApp, email, Drive';

  @override
  String get printingActionsSave => 'Save to phone';

  @override
  String get printingActionsPrint => 'Print';

  @override
  String get printingActionsNoPrinter => 'No printer chosen';

  @override
  String get printingActionsConnectsWhenPrinting => 'Connects when printing';

  @override
  String get printingActionsChooseOnFirstPrint =>
      'You will pick from the paired printers the first time you print';

  @override
  String get printingActionsSettings => 'Settings';

  @override
  String get printingActionsPrintThermal => 'Print on thermal';

  @override
  String printingSettingsSaved(String printer) {
    return 'Printer saved: $printer';
  }

  @override
  String get printingSettingsTestSent =>
      'Test print sent. If the Bengali prints correctly, the printer is ready.';

  @override
  String get printingSettingsTestFailed => 'Test print failed. Try again.';

  @override
  String get printingSettingsTitle => 'Printer';

  @override
  String get printingSettingsThermalHeading =>
      'Thermal (Bluetooth receipt) printer';

  @override
  String get printingSettingsNoPrinter => 'No printer chosen';

  @override
  String get printingSettingsPairFirst =>
      'First pair the printer in the phone\'s Settings → Bluetooth';

  @override
  String get printingSettingsChange => 'Change';

  @override
  String get printingSettingsChoose => 'Choose';

  @override
  String get printingSettingsForget => 'Forget this printer';

  @override
  String get printingSettingsRollWidth => 'Roll width';

  @override
  String printingSettingsWidthMm(String width) {
    return '$width mm';
  }

  @override
  String get printingSettingsTestButton => 'Test print';

  @override
  String get printingSettingsWhereHeading => 'Which document prints where';

  @override
  String get printingSettingsWhereBody =>
      'Each document (bill, delivery challan, voucher …) starts on the printer it was last printed on. The normal printer is the phone\'s own print system — a Wi-Fi/USB printer or Save as PDF.';

  @override
  String get printingSettingsResetRoads => 'Clear remembered choices';

  @override
  String get printingThermalNotPaired =>
      'No Bluetooth printer is paired. Turn the printer on and pair it once in the phone\'s Settings → Bluetooth (the PIN is usually 0000 or 1234), then try again.';

  @override
  String get printingThermalExplainTitle => 'Bluetooth printer permission';

  @override
  String get printingThermalExplainBody =>
      'To print on a receipt printer, ABOS connects to the printer paired with the phone over Bluetooth. The phone will then ask for the \"Nearby devices\" permission — tap \"Allow\". Your location is not taken.';

  @override
  String get printingThermalNotNow => 'Not now';

  @override
  String get printingThermalOk => 'OK';

  @override
  String get printingThermalChoosePrinter => 'Choose a printer';

  @override
  String get scanPaperOldPaper =>
      'This is an old printout. It does not open on the phone — open it on the web, or print the document again.';

  @override
  String get scanPaperNotOurs => 'This is not an ABOS document QR.';

  @override
  String get scanPaperNotFound =>
      'Document not found — cancelled, or not in your branch.';

  @override
  String get scanPaperForbidden =>
      'You do not have permission to view or change this document.';

  @override
  String get scanPaperAlreadyOut => 'The goods have already gone out.';

  @override
  String get scanPaperBlocked =>
      'Not done — vehicle details are missing, or the shop is over its credit limit. Tell the office.';

  @override
  String get scanPaperFailed => 'Not done. Try again.';

  @override
  String get scanPaperGateOutTitle => 'Goods out?';

  @override
  String get scanPaperGateOutBody =>
      'The gate pass will be issued and the bill raised. This cannot be undone.';

  @override
  String get scanPaperNo => 'No';

  @override
  String get scanPaperGateOutYes => 'Yes, out';

  @override
  String get scanPaperTitle => 'Scan document';

  @override
  String get scanPaperNoCamera => 'The QR cannot be read without the camera.';

  @override
  String get scanPaperAskCamera => 'Turn on camera';

  @override
  String get scanPaperHint =>
      'Hold the delivery challan or gate pass QR in front of the camera.';

  @override
  String scanPaperSaleNo(String number) {
    return 'Sale no. $number';
  }

  @override
  String scanPaperDate(String date) {
    return 'Date $date';
  }

  @override
  String scanPaperStatus(String stage) {
    return 'Status: $stage';
  }

  @override
  String scanPaperOutAt(String when) {
    return 'Out $when';
  }

  @override
  String scanPaperQty(String qty) {
    return 'Qty $qty';
  }

  @override
  String scanPaperFreeQty(String qty) {
    return 'Free $qty';
  }

  @override
  String scanPaperLot(String lot) {
    return 'Lot $lot';
  }

  @override
  String get scanPaperVehicleSet => 'Vehicle set';

  @override
  String get scanPaperVehicleMissing => '⚠ No vehicle set';

  @override
  String scanPaperVehicle(String vehicle) {
    return 'Vehicle $vehicle';
  }

  @override
  String scanPaperDriver(String driver) {
    return 'Driver $driver';
  }

  @override
  String scanPaperBillTotal(String amount) {
    return 'Bill total $amount';
  }

  @override
  String get scanPaperGateOut => 'Goods out';

  @override
  String get scanPaperConfirmDelivery => 'Confirm delivery';

  @override
  String get scanPaperScanAgain => 'Scan another';

  @override
  String get scanPaperStageAllocated => 'Allocated';

  @override
  String get scanPaperStagePicking => 'Picking';

  @override
  String get scanPaperStagePacked => 'Packed';

  @override
  String get scanPaperStageDispatched => 'Through the gate';

  @override
  String get scanPaperStageDelivered => 'Delivered';

  @override
  String get scanReceiveNameMissing =>
      'Enter the name of the person who received the goods.';

  @override
  String get scanReceivePhoneMissing =>
      'Enter the receiver\'s phone number — it is how we ask later.';

  @override
  String scanReceiveQtyInvalid(String product) {
    return '$product: the quantity is not valid.';
  }

  @override
  String scanReceiveQtyTooMuch(String product) {
    return '$product: taken plus damaged is more than the delivery challan.';
  }

  @override
  String scanReceiveTitle(String number) {
    return 'Hand over — $number';
  }

  @override
  String get scanReceiveNameLabel => 'Received by';

  @override
  String get scanReceivePhoneLabel => 'Their phone';

  @override
  String get scanReceiveLinesHint =>
      'Each product — how much was taken, how much damaged; the rest is short (it will be sent back)';

  @override
  String scanReceiveLineHeader(String product, String qty) {
    return '$product · $qty on the delivery challan';
  }

  @override
  String get scanReceiveTaken => 'Taken';

  @override
  String get scanReceiveDamaged => 'Damaged';

  @override
  String scanReceiveShort(String qty) {
    return 'Short: $qty';
  }

  @override
  String get scanReceiveDone => 'Confirm — received';

  @override
  String stockWriteCountLine(
      String product, String book, String counted, String difference) {
    return '$product: books $book → counted $counted (difference $difference)';
  }

  @override
  String get stockWriteFormAdjust => 'Count adjustment';

  @override
  String get stockWriteFormIssue => 'Stock issue';

  @override
  String get stockWriteFormOpening => 'Opening stock';

  @override
  String get stockWriteFormLot => 'Assign lot';

  @override
  String get stockWriteQtyAdjust => 'Counted';

  @override
  String get stockWriteQtyIssue => 'Qty to issue';

  @override
  String get stockWriteQtyOpening => 'Quantity';

  @override
  String get stockWriteQtyLot => 'Qty to put in the lot';

  @override
  String get stockWriteLoadFailed => 'Could not load stock work.';

  @override
  String get stockWriteNotOnServer =>
      'This task is not on this server yet — the app is newer than the server.';

  @override
  String get stockWriteWorkTitle => 'Stock work';

  @override
  String get stockWritePlaceTitle => 'Put away';

  @override
  String get stockWritePlaceHint => 'Shelve goods that have arrived';

  @override
  String get stockWriteAdjustHint => 'Count and match the books';

  @override
  String get stockWriteIssueHint => 'Take goods out other than by sale';

  @override
  String get stockWriteOpeningHint => 'Enter the starting stock';

  @override
  String get stockWriteLotHint => 'Give unlotted goods a lot';

  @override
  String get stockWriteCountsTitle => 'Counts awaiting acceptance';

  @override
  String get stockWriteCountsHint => 'Accept counts made by others';

  @override
  String get stockWriteNoAccessTitle => 'No permission for any stock entry';

  @override
  String get stockWriteNoAccessMessage =>
      'If needed, ask the person who grants permissions.';

  @override
  String get stockWriteResultDone => 'Recorded.';

  @override
  String get stockWriteResultWaiting => 'Kept pending.';

  @override
  String get stockWriteNeedFieldsWithReason =>
      'Product, warehouse, reason and quantity are required.';

  @override
  String get stockWriteNeedFields =>
      'Product, warehouse and quantity are required.';

  @override
  String stockWriteSendFailed(String form) {
    return 'Could not record: $form.';
  }

  @override
  String get stockWritePickProduct => 'Choose a product';

  @override
  String get stockWriteWarehouse => 'Warehouse';

  @override
  String get stockWriteReason => 'Reason';

  @override
  String get stockWriteCountingFree => 'Counting free goods';

  @override
  String get stockWriteFreeOptional => 'Free (optional)';

  @override
  String get stockWriteUnitCost => 'Unit cost';

  @override
  String get stockWriteExtraCostOptional => 'Cost of surplus goods (optional)';

  @override
  String get stockWriteLotNo => 'Lot number';

  @override
  String get stockWriteLotNoIfLotted => 'Lot number (lot-tracked products)';

  @override
  String get stockWriteExpiryOptional => 'Expires on (optional)';

  @override
  String get stockWriteNoteOptional => 'Description (optional)';

  @override
  String stockWriteSend(String form) {
    return 'Send: $form';
  }

  @override
  String get stockWriteProductSearchHint => 'Name, code or barcode';

  @override
  String get stockWriteNoProductMatch => 'No product matches';

  @override
  String get stockWritePlaceLoadFailed =>
      'Could not load goods waiting to be put away.';

  @override
  String get stockWritePlaceFailed => 'Could not put the goods away.';

  @override
  String get stockWritePlaceEmpty => 'Nothing waiting to be put away';

  @override
  String get stockWritePullAgain => 'Pull down to check again.';

  @override
  String stockWriteLot(String lot) {
    return 'Lot $lot';
  }

  @override
  String stockWriteWaitingQty(String qty) {
    return 'Waiting $qty';
  }

  @override
  String stockWriteFreeQty(String qty) {
    return 'Free $qty';
  }

  @override
  String get stockWritePlacingLabel => 'Putting away';

  @override
  String get stockWritePlaceSpot => 'Location';

  @override
  String get stockWritePlaceButton => 'Put away';

  @override
  String get stockWriteCountsLoadFailed => 'Could not load the pending counts.';

  @override
  String get stockWriteChooseReason =>
      'Choose a reason — the reason decides where the difference is booked.';

  @override
  String get stockWriteAcceptFailed => 'Could not accept the count.';

  @override
  String get stockWriteCountsTotal => 'Total pending';

  @override
  String stockWriteCountValue(int count) {
    return '$count';
  }

  @override
  String get stockWriteCountsEmpty => 'No counts pending';

  @override
  String get stockWriteAccept => 'Accept';

  @override
  String get stockQualityLoadFailed => 'Could not load the quality checks.';

  @override
  String get stockQualityNotOnServer =>
      'This list is not on this server yet — the app is newer than the server.';

  @override
  String get stockQualityTitle => 'Quality check';

  @override
  String get stockQualityNew => 'New check';

  @override
  String get stockQualityAll => 'All';

  @override
  String get stockQualityTotal => 'Total checks';

  @override
  String stockQualityCountValue(int count) {
    return '$count';
  }

  @override
  String get stockQualityEmpty => 'No quality checks';

  @override
  String get stockQualityMore => 'More';

  @override
  String get stockQualityOneLoadFailed => 'Could not load the check.';

  @override
  String get stockQualitySaveFailed => 'Could not record it.';

  @override
  String stockQualityTitleNo(String no) {
    return 'Quality check · $no';
  }

  @override
  String get stockQualityStatus => 'Status';

  @override
  String get stockQualityDate => 'Date';

  @override
  String get stockQualityLot => 'Lot';

  @override
  String get stockQualityInspected => 'Inspected';

  @override
  String get stockQualityAccepted => 'Accepted';

  @override
  String get stockQualityRejected => 'Rejected (cancelled)';

  @override
  String get stockQualityDisposed => 'Disposed of';

  @override
  String get stockQualityCriteria => 'Checked for';

  @override
  String get stockQualityRemarks => 'Remarks';

  @override
  String get stockQualityVerdict => 'Verdict';

  @override
  String get stockQualityResult => 'Result';

  @override
  String get stockQualityRemarksOptional => 'Remarks (optional)';

  @override
  String get stockQualityGiveVerdict => 'Give verdict';

  @override
  String get stockQualityDisposeTitle => 'Dispose of rejected goods';

  @override
  String get stockQualityDisposeQty => 'How much';

  @override
  String get stockQualityDispose => 'Dispose';

  @override
  String get stockQualityNeedFields => 'Product and quantity are required.';

  @override
  String get stockQualityOpenFailed => 'Could not open the check.';

  @override
  String get stockQualityNewTitle => 'New quality check';

  @override
  String get stockQualityQtyToCheck => 'Qty to check';

  @override
  String get stockQualityLotOptional => 'Lot number (optional)';

  @override
  String get stockQualityCriteriaOptional => 'What to check (optional)';

  @override
  String get stockQualityOpen => 'Open check';

  @override
  String get stockSerialLoadFailed => 'Could not load the serials.';

  @override
  String get stockSerialTitle => 'Serial numbers';

  @override
  String get stockSerialReceive => 'Receive pieces';

  @override
  String get stockSerialIssue => 'Issue pieces';

  @override
  String get stockSerialSearchHint => 'Serial number';

  @override
  String get stockSerialTotal => 'Total serials';

  @override
  String get stockSerialEmpty => 'No serials found';

  @override
  String get stockSerialNeedProductAndOne =>
      'A product and at least one serial are required.';

  @override
  String get stockSerialNeedOne => 'At least one serial is required.';

  @override
  String get stockSerialSaveFailed => 'Could not record the serials.';

  @override
  String get stockSerialNumbersLabel => 'Serials — one per line';

  @override
  String get stockSerialSoldToOptional => 'To whom (optional)';

  @override
  String get stockSerialWarrantyOptional => 'Warranty, months (optional)';

  @override
  String get stockSerialReceiveButton => 'Receive';

  @override
  String get stockSerialIssueButton => 'Issue';

  @override
  String get stockListTitle => 'Stock on hand';

  @override
  String get stockListWithValue => 'List with value';

  @override
  String get stockListValueReportTitle => 'Stock value';

  @override
  String get stockListReports => 'Stock reports';

  @override
  String get stockListSearchHint => 'Search by product name';

  @override
  String get stockListTotalAvailable => 'Total available';

  @override
  String get stockListNothingSynced => 'No stock synced yet';

  @override
  String get stockListLoadFailed => 'Could not fetch the stock list';

  @override
  String get stockListPullRetry => 'Pull down to try again.';

  @override
  String get stockListNoMatch => 'No matches found';

  @override
  String get stockListClearFilters => 'Clear the filters and look again.';

  @override
  String get stockListProductNotSynced => 'Product not synced yet';

  @override
  String get stockListAvailable => 'Available';

  @override
  String stockListOnShelf(String qty) {
    return 'On shelf $qty';
  }

  @override
  String stockListOnOrder(String qty) {
    return 'In orders $qty';
  }

  @override
  String stockListHeld(String qty) {
    return 'On hold $qty';
  }

  @override
  String get productsListTitle => 'Products';

  @override
  String get productsListSearchHint => 'Search by product name';

  @override
  String productsListTotal(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count products in all',
      one: '$count product in all',
    );
    return '$_temp0';
  }

  @override
  String get productsListNothingSynced => 'No products synced yet';

  @override
  String get productsListLoadFailed => 'Could not fetch the product list';

  @override
  String get productsListPullRetry => 'Pull down to try again.';

  @override
  String get productsListNoMatch => 'No matches found';

  @override
  String get productsListClearFilters => 'Clear the filters and look again.';

  @override
  String productsListAvailable(String qty) {
    return 'Available $qty';
  }

  @override
  String productsListPurchase(String amount) {
    return 'Purchase: $amount';
  }

  @override
  String loadingTripVehicle(String vehicle) {
    return 'Vehicle $vehicle';
  }

  @override
  String loadingTripDriver(String driver) {
    return 'Driver $driver';
  }

  @override
  String get loadingListLoadFailed =>
      'Could not load the trips. Pull down to try again.';

  @override
  String get loadingListNotOnServer =>
      'Loading sheets are not on the server yet — tell the office.';

  @override
  String get loadingListTitle => 'Loading sheet';

  @override
  String get loadingListTotal => 'Total loading sheets';

  @override
  String loadingListCountValue(int count) {
    return '$count';
  }

  @override
  String get loadingListEmpty => 'No open trips to load.';

  @override
  String loadingListChallans(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count delivery challans',
      one: '$count delivery challan',
    );
    return '$_temp0';
  }

  @override
  String get loadingListMore => 'See more';

  @override
  String get loadingSheetLoadFailed =>
      'Could not load the loading sheet. Pull down to try again.';

  @override
  String get loadingSheetPackedQuestion => 'Packed?';

  @override
  String get loadingSheetPackedExplain =>
      'The trip\'s delivery challans not yet loaded will move to the \"Packed\" step.';

  @override
  String get loadingSheetNo => 'No';

  @override
  String get loadingSheetYesPacked => 'Yes, packed';

  @override
  String loadingSheetPackedNotice(String doc, int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count delivery challans moved to the \"Packed\" step.',
      one: '$count delivery challan moved to the \"Packed\" step.',
    );
    return '$doc — $_temp0';
  }

  @override
  String get loadingSheetPackFailed =>
      'Could not record \"Packed\". Try again.';

  @override
  String loadingSheetTitle(String no) {
    return 'Loading sheet $no';
  }

  @override
  String get loadingSheetByProduct => 'By product — how much to load';

  @override
  String loadingSheetLots(String lots) {
    return 'Lots: $lots';
  }

  @override
  String get loadingSheetByChallan => 'By delivery challan — for whom';

  @override
  String get loadingSheetPacked => 'Packed';

  @override
  String get loadingSheetAllPacked =>
      'All delivery challans packed — waiting for the vehicle to leave.';

  @override
  String get promoOffersTitle => 'Running offers';

  @override
  String get promoOffersLoadFailed =>
      'Could not load the offers. Pull down to try again.';

  @override
  String get promoOffersNotOnServer =>
      'Offers are not on the server yet — tell the office.';

  @override
  String get promoOffersNoneRunning => 'No offers running now';

  @override
  String get promoOffersOffHere => 'Offers are off in this branch now';

  @override
  String promoOffersNoMatch(String query) {
    return '\"$query\" — no offer matches this name.';
  }

  @override
  String get promoOffersSearchHint => 'Type a product name';

  @override
  String promoOffersCountValue(int count) {
    return '$count';
  }

  @override
  String get promoOffersGoods => 'On which goods';

  @override
  String get promoOffersForWhom => 'For whom';

  @override
  String get promoOffersUntil => 'Until';

  @override
  String get promoOffersEndsToday => 'Ends today';

  @override
  String promoOffersDaysLeft(int days) {
    String _temp0 = intl.Intl.pluralLogic(
      days,
      locale: localeName,
      other: '$days days left',
      one: '$days day left',
    );
    return '$_temp0';
  }

  @override
  String launcherWidgetMore(String count) {
    return '$count more';
  }

  @override
  String launcherWidgetTotal(String value) {
    return 'Total $value';
  }

  @override
  String launcherWidgetAsOf(String time) {
    return 'As of $time';
  }

  @override
  String get launcherWidgetSignedOut => 'Signed out';

  @override
  String get launcherWidgetCash => 'Cash';

  @override
  String get launcherWidgetBank => 'Bank';

  @override
  String get launcherWidgetCashBankMfs => 'Cash + Bank + MFS';

  @override
  String get launcherWidgetNoStaffExpenses => 'Excluding staff expenses';

  @override
  String get launcherWidgetBilledToday => 'Billed today';

  @override
  String get launcherWidgetCollectionsToday => 'Today\'s collections';

  @override
  String get launcherWidgetNotices => 'Notices';

  @override
  String get launcherWidgetApprovalsName => 'Waiting for signature';

  @override
  String get launcherWidgetApprovalsTitle => 'Awaiting signature from you';

  @override
  String get launcherWidgetApprovalsEmpty => 'No signatures pending';

  @override
  String get launcherWidgetApprovalsNothingNow => 'Nothing to sign right now.';

  @override
  String launcherWidgetApprovalsMore(String count) {
    return '$count more';
  }

  @override
  String get launcherWidgetAttendanceTitle => 'My attendance';

  @override
  String get launcherWidgetAttendanceNoRecord => 'You have no employee record';

  @override
  String launcherWidgetAttendanceInAt(String time) {
    return 'In today at $time';
  }

  @override
  String get launcherWidgetAttendancePresent => 'Present today';

  @override
  String get launcherWidgetAttendanceAbsent => 'Absent today';

  @override
  String get launcherWidgetAttendanceLeave => 'On leave today';

  @override
  String get launcherWidgetAttendanceHoliday => 'Holiday today';

  @override
  String get launcherWidgetAttendanceNotMarked => 'Not marked today';

  @override
  String get launcherWidgetAttendanceLate => 'Late';

  @override
  String launcherWidgetAttendanceLeaveLeft(String days) {
    return 'Leave left: $days days';
  }

  @override
  String launcherWidgetAttendanceMonth(String present, String absent) {
    return 'This month: present $present · absent $absent';
  }

  @override
  String launcherWidgetAttendanceMonthMore(String leave, String late) {
    return 'Leave $leave · late $late';
  }

  @override
  String get launcherWidgetBankReconcileTitle => 'Bank reconciliation due';

  @override
  String launcherWidgetBankReconcileOpen(String count) {
    return 'Unreconciled lines: $count';
  }

  @override
  String get launcherWidgetBankReconcileNever => 'Never reconciled yet';

  @override
  String launcherWidgetBankReconcileLast(String day) {
    return 'Last reconciled: $day';
  }

  @override
  String get launcherWidgetBankReconcileAllDone => 'All reconciled';

  @override
  String launcherWidgetBankReconcileAllDoneLast(String day) {
    return 'All reconciled · last: $day';
  }

  @override
  String get launcherWidgetBigDuesName => 'Big dues';

  @override
  String get launcherWidgetBigDuesTitle => 'Biggest dues';

  @override
  String get launcherWidgetBigDuesAdvance => 'Advance on account';

  @override
  String launcherWidgetBigDuesOverdue(String days) {
    return '$days days overdue';
  }

  @override
  String get launcherWidgetBigDuesEmpty => 'Nobody owes anything';

  @override
  String get launcherWidgetChallansTitle => 'Today\'s delivery challans';

  @override
  String get launcherWidgetChallansGatePending => 'Gate pass pending';

  @override
  String get launcherWidgetChallansMoving => 'On the way';

  @override
  String get launcherWidgetChallansDelivered => 'Delivered';

  @override
  String get launcherWidgetChallansReturned => 'Returned';

  @override
  String launcherWidgetChallansLatest(String customer) {
    return 'Latest: $customer';
  }

  @override
  String launcherWidgetChallansVehicle(String vehicle) {
    return 'Vehicle $vehicle';
  }

  @override
  String get launcherWidgetChallansEmpty => 'No delivery challans yet today';

  @override
  String get launcherWidgetChequesName => 'Cheques — deposit today';

  @override
  String get launcherWidgetChequesTitle =>
      'Cheques to deposit in the bank today';

  @override
  String get launcherWidgetChequesTotal => 'Total to deposit today';

  @override
  String get launcherWidgetChequesEmpty => 'No cheques to deposit today';

  @override
  String launcherWidgetChequesCheque(String bank, String number) {
    return '$bank cheque $number';
  }

  @override
  String launcherWidgetChequesBounced(String name) {
    return 'Returned: $name';
  }

  @override
  String launcherWidgetChequesDueAgain(String cheque) {
    return '$cheque · due again on the customer\'s ledger';
  }

  @override
  String launcherWidgetChequesDated(String cheque, String day) {
    return '$cheque · dated $day';
  }

  @override
  String get launcherWidgetCreditLimitsTitle => 'Near the credit limit';

  @override
  String launcherWidgetCreditLimitsCaption(String percent) {
    return 'Customers over $percent% of their limit';
  }

  @override
  String get launcherWidgetCreditLimitsEmpty => 'No customer is near the limit';

  @override
  String get launcherWidgetExpensesTitle => 'This month\'s expenses';

  @override
  String launcherWidgetExpensesMonth(String month) {
    return '$month expenses';
  }

  @override
  String get launcherWidgetExpensesEmpty => 'No expenses yet this month';

  @override
  String get launcherWidgetGoodsName => 'Goods incoming';

  @override
  String get launcherWidgetGoodsTitle => 'Goods incoming — purchase orders';

  @override
  String launcherWidgetGoodsSupplier(String supplier, String branch) {
    return '$supplier (supplier)$branch';
  }

  @override
  String launcherWidgetGoodsSupplierLate(String supplier, String branch) {
    return 'Late: $supplier (supplier)$branch';
  }

  @override
  String launcherWidgetGoodsWasDue(String day) {
    return 'Expected $day';
  }

  @override
  String launcherWidgetGoodsWeek(String count) {
    return 'This week $count';
  }

  @override
  String launcherWidgetGoodsWeekLate(String count, String late) {
    return 'This week $count · late $late';
  }

  @override
  String get launcherWidgetGoodsEmpty => 'No goods due this week';

  @override
  String launcherWidgetGoodsProducts(String count) {
    return '$count products';
  }

  @override
  String get launcherWidgetGoodsToday => 'Arriving today';

  @override
  String get launcherWidgetGoodsTomorrow => 'Arriving tomorrow';

  @override
  String launcherWidgetGoodsOn(String day) {
    return 'Arriving $day';
  }

  @override
  String get launcherWidgetMoneyTitle => 'Money in hand';

  @override
  String get launcherWidgetMoneyCashBox => 'Cash box';

  @override
  String get launcherWidgetMoneyTransit => 'In transit (to the bank)';

  @override
  String get launcherWidgetNoticesUnread => 'Unread';

  @override
  String get launcherWidgetNoticesEmpty => 'No notices';

  @override
  String get launcherWidgetNoticesNew => 'New';

  @override
  String get launcherWidgetOffersName => 'Offers and schemes';

  @override
  String get launcherWidgetOffersTitle => 'Current offers and schemes';

  @override
  String launcherWidgetOffersActive(String count) {
    return 'Active $count';
  }

  @override
  String get launcherWidgetOffersEmpty => 'No active offers now';

  @override
  String get launcherWidgetOffersEndsToday => 'Ends today';

  @override
  String get launcherWidgetOffersEndsTomorrow => 'Ends tomorrow';

  @override
  String launcherWidgetOffersEndsInDays(String days) {
    return 'Ends in $days days';
  }

  @override
  String launcherWidgetOffersEndsOn(String day) {
    return 'Ends $day';
  }

  @override
  String get launcherWidgetOwnerName => 'Owner\'s full board';

  @override
  String get launcherWidgetOwnerAllBranches => 'All branches';

  @override
  String launcherWidgetOwnerReceipts(String count) {
    return 'Receipts: $count';
  }

  @override
  String launcherWidgetOwnerCashAmount(String amount) {
    return 'Cash $amount';
  }

  @override
  String get launcherWidgetOwnerDues => 'Total dues';

  @override
  String launcherWidgetOwnerShops(String count) {
    return 'Shops: $count';
  }

  @override
  String get launcherWidgetOwnerProfit => 'Gross profit this month';

  @override
  String launcherWidgetOwnerMargin(String margin) {
    return 'Margin $margin%';
  }

  @override
  String get launcherWidgetOwnerExpenses => 'Expenses this month';

  @override
  String launcherWidgetOwnerTarget(String month, String days) {
    return '$month target · $days days left';
  }

  @override
  String launcherWidgetOwnerToSign(String count) {
    return '$count to sign';
  }

  @override
  String launcherWidgetOwnerCheques(String count) {
    return '$count cheques';
  }

  @override
  String launcherWidgetOwnerLowStock(String count) {
    return '$count low stock';
  }

  @override
  String launcherWidgetOwnerChallansMoving(String count) {
    return '$count challans on the way';
  }

  @override
  String launcherWidgetOwnerNotices(String count) {
    return '$count notices';
  }

  @override
  String launcherWidgetOwnerBills(String label, String count) {
    return '$label · $count bills';
  }

  @override
  String get launcherWidgetOwnerEmpty => 'Nothing to show yet';

  @override
  String get launcherWidgetOwnerMonth => 'Month';

  @override
  String get launcherWidgetCommissionName => 'Principal commission';

  @override
  String launcherWidgetCommissionTotal(String side) {
    return 'Total · $side';
  }

  @override
  String get launcherWidgetCommissionEmpty => 'No commission this cycle';

  @override
  String get launcherWidgetProfitTitle => 'Gross profit this month';

  @override
  String get launcherWidgetProfitLossThisMonth => 'Gross loss this month';

  @override
  String launcherWidgetProfitMonth(String month) {
    return '$month gross profit';
  }

  @override
  String launcherWidgetProfitLossMonth(String month) {
    return '$month gross loss';
  }

  @override
  String launcherWidgetProfitMargin(String margin) {
    return 'Gross margin $margin%';
  }

  @override
  String get launcherWidgetProfitSales =>
      'Sales (VAT excluded, net of returns)';

  @override
  String get launcherWidgetProfitCogs => 'Cost of goods sold';

  @override
  String get launcherWidgetQuickName => 'Quick actions';

  @override
  String get launcherWidgetQuickOrder => 'New order';

  @override
  String get launcherWidgetQuickCollect => 'Collect money';

  @override
  String get launcherWidgetQuickVoucher => 'Voucher';

  @override
  String get launcherWidgetQuickScan => 'Scan';

  @override
  String get launcherWidgetQuickSign => 'Sign';

  @override
  String get launcherWidgetQuickEmpty => 'No quick actions for you';

  @override
  String launcherWidgetSalesBills(String count) {
    return 'Bills $count';
  }

  @override
  String get launcherWidgetSalesCollection => 'Collection';

  @override
  String get launcherWidgetSalesSameAsYesterday => 'Same as yesterday';

  @override
  String launcherWidgetSalesUp(String percent) {
    return '▲ $percent% more than yesterday';
  }

  @override
  String launcherWidgetSalesDown(String percent) {
    return '▼ $percent% less than yesterday';
  }

  @override
  String get launcherWidgetSrName => 'Sales reps';

  @override
  String get launcherWidgetSrMine => 'My sales today';

  @override
  String get launcherWidgetSrYours => 'Your sales today';

  @override
  String launcherWidgetSrRank(String rank) {
    return 'You are number $rank today';
  }

  @override
  String launcherWidgetSrOutOf(String of) {
    return 'out of $of';
  }

  @override
  String get launcherWidgetSrTitle => 'Top sales today — sales reps';

  @override
  String launcherWidgetSrBranch(String branch) {
    return 'Branch: $branch';
  }

  @override
  String get launcherWidgetSrEmpty => 'No sales yet today';

  @override
  String get launcherWidgetStockName => 'Stock alerts';

  @override
  String get launcherWidgetStockTitle => 'Stock running low';

  @override
  String get launcherWidgetStockEmpty => 'All stock above reorder level';

  @override
  String get launcherWidgetStockAllWarehouses => 'All warehouses';

  @override
  String launcherWidgetStockWarehouse(String warehouse) {
    return 'Warehouse: $warehouse';
  }

  @override
  String get launcherWidgetSyncName => 'Sync status';

  @override
  String get launcherWidgetSyncRunning => 'Syncing';

  @override
  String get launcherWidgetSyncOffline => 'No internet';

  @override
  String get launcherWidgetSyncFailed => 'Sync failed';

  @override
  String get launcherWidgetSyncStale => 'Sync out of date';

  @override
  String get launcherWidgetSyncOk => 'Sync OK';

  @override
  String get launcherWidgetSyncNever => 'Last sync: not yet';

  @override
  String launcherWidgetSyncLast(String ago) {
    return 'Last sync: $ago';
  }

  @override
  String launcherWidgetSyncQueued(String count) {
    return 'Queued work: $count';
  }

  @override
  String get launcherWidgetSyncKeptOffline => 'Kept while offline';

  @override
  String get launcherWidgetTargetName => 'Target vs achieved';

  @override
  String get launcherWidgetTargetMineThisMonth => 'My target this month';

  @override
  String get launcherWidgetTargetThisMonth => 'This month\'s target';

  @override
  String launcherWidgetTargetMineMonth(String month) {
    return 'My target for $month';
  }

  @override
  String launcherWidgetTargetMonth(String month) {
    return '$month target';
  }

  @override
  String get launcherWidgetTargetNotSet => 'No target set for this month';

  @override
  String launcherWidgetTargetDaysLeft(String days) {
    return '$days days left';
  }

  @override
  String get launcherWidgetTargetMonthOver => 'Month over';

  @override
  String launcherWidgetTargetCrore(String amount) {
    return '$amount crore';
  }

  @override
  String launcherWidgetTargetLakh(String amount) {
    return '$amount lakh';
  }

  @override
  String get launcherWidgetTodayNotForAccount =>
      'This account does not show these figures';

  @override
  String get launcherWidgetTodayNotOnServer =>
      'The server does not have these figures yet';
}
