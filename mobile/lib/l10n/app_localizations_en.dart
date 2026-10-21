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
      'Each bill line\'s actual FIFO cost, as drawn from the layers when the bill was posted; goods sent back come off at their own cost.';

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
}
