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
}
