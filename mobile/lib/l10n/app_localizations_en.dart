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
  String get approvalsRejectReasonRequired => 'A reason is required to reject';

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
  String get approvalsQueueSendBackTitle => 'Why send back for correction?';

  @override
  String get approvalsQueueSendBackDismiss => 'Cancel';

  @override
  String get approvalsQueueSendBack => 'Send back for correction';

  @override
  String get approvalsQueueSendBackReasonNeeded =>
      'Write the reason for sending it back.';

  @override
  String get approvalsQueueSentBack => 'Sent back for correction.';

  @override
  String get approvalsQueueSendBackFailed =>
      'Could not send back for correction.';

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
}
