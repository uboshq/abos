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
  String get salesPlanReturns => 'Returns (goods sent back)';

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
}
