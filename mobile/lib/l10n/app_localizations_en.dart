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
  String get ordersTrackingAll => 'All';

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
}
