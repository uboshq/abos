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
