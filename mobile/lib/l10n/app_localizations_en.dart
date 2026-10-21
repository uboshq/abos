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
  String get ordersListNewOrder => 'New order';

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
  String get ordersTrackingSearch => 'Search';

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
}
