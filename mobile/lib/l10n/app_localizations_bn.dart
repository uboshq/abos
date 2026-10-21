// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Bengali Bangla (`bn`).
class AppL10nBn extends AppL10n {
  AppL10nBn([String locale = 'bn']) : super(locale);

  @override
  String get commonOpen => 'খুলুন';

  @override
  String get commonCancel => 'বাতিল';

  @override
  String get commonSave => 'রাখুন';

  @override
  String get commonRetry => 'আবার চেষ্টা করুন';

  @override
  String commonPaperCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা কাগজ',
      zero: 'কোনো কাগজ নেই',
    );
    return '$_temp0';
  }

  @override
  String commonPaperState(String state) {
    String _temp0 = intl.Intl.selectLogic(
      state,
      {
        'draft': 'খসড়া',
        'posted': 'পোস্ট হয়েছে',
        'cancelled': 'বাতিল',
        'other': 'অজানা',
      },
    );
    return '$_temp0';
  }

  @override
  String get topBarBell => 'বিজ্ঞপ্তি';

  @override
  String topBarBellUnread(String count) {
    return 'বিজ্ঞপ্তি, $countটা না-পড়া';
  }

  @override
  String get topBarBellNews => 'বিজ্ঞপ্তি, নতুন খবর আছে';

  @override
  String get avatarMenuClose => 'মেনু বন্ধ';

  @override
  String get avatarMenuSwitchCompany => 'কোম্পানি বদল';

  @override
  String get avatarMenuColour => 'রং';

  @override
  String get avatarMenuThemePhone => 'ফোনের মতো';

  @override
  String get avatarMenuThemeLight => 'আলো';

  @override
  String get avatarMenuThemeDark => 'অন্ধকার';

  @override
  String get avatarMenuLanguage => 'ভাষা';

  @override
  String get avatarMenuLanguagePhone => 'ফোনের মতো';

  @override
  String get avatarMenuPinAndFingerprint => 'পিন ও আঙুলের ছাপ';

  @override
  String avatarMenuLockAfter(String delay) {
    return 'তালার সময়: $delay';
  }

  @override
  String get avatarMenuMyPhones => 'আমার ফোনগুলো';

  @override
  String avatarMenuSettingsVersion(String version) {
    return 'সেটিংস · সংস্করণ $version';
  }

  @override
  String get avatarMenuSignOut => 'বের হওয়া';

  @override
  String get ownerCentreTitle => 'মালিকের কেন্দ্র';

  @override
  String get ownerTodayFailed => 'মালিকের কেন্দ্রের সংখ্যা আনা গেল না।';

  @override
  String get ownerTodayAbsent =>
      'মালিকের কেন্দ্র এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get ownerPageAbsent =>
      'মালিকের কেন্দ্রের এই পাতা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন। অফিসে জানান।';

  @override
  String get ownerNoCompanyTitle => 'দেখার মতো কোনো কোম্পানি নেই';

  @override
  String get ownerNoCompanyMessage =>
      'কোনো কোম্পানিতে আপনার মালিকের কেন্দ্র দেখার অনুমতি নেই।';

  @override
  String get ownerCompaniesAndBranches => 'কোম্পানি ও শাখা';

  @override
  String get ownerAlerts => 'সতর্কতা';

  @override
  String get ownerAwaitingSignature => 'সইয়ের অপেক্ষায়';

  @override
  String get ownerNothingAwaitingNow => 'এখন কোনো কাগজ সইয়ের অপেক্ষায় নেই।';

  @override
  String get ownerSomeCompaniesLeftOut => 'কিছু কোম্পানি বাদ — অনুমতি নেই';

  @override
  String get ownerCredit => 'বাকি';

  @override
  String get ownerCreditExposure => 'ব্যবহৃত বাকি';

  @override
  String get ownerCreditOverdue => 'মেয়াদ পেরোনো পাওনা';

  @override
  String get ownerCreditBlocked => 'বাকি বন্ধ গ্রাহক';

  @override
  String ownerCreditBlockedCount(int count) {
    return '$count জন';
  }

  @override
  String get ownerCreditRequests => 'সীমা বাড়ানোর অনুরোধ';

  @override
  String ownerCount(int count) {
    return '$countটা';
  }

  @override
  String ownerCompanySummary(String sales, String collections) {
    return 'বিক্রি $sales · আদায় $collections';
  }

  @override
  String get ownerUnsplit => 'শাখায় ভাগ হয়নি';

  @override
  String get ownerNotAllowed => 'অনুমতি নেই';

  @override
  String get ownerNone => 'কিছু নেই';

  @override
  String get ownerPeriodToday => 'আজ';

  @override
  String get ownerPeriodWeek => 'এই সপ্তাহ';

  @override
  String get ownerPeriodMonth => 'এই মাস';

  @override
  String get ownerFigureSales => 'বিক্রি';

  @override
  String get ownerFigureCollections => 'আদায়';

  @override
  String get ownerFigureReceivable => 'পাওনা (বাকি)';

  @override
  String get ownerFigurePayable => 'সরবরাহকারীর দেনা';

  @override
  String get ownerFigureFund => 'নগদ + ব্যাংক';

  @override
  String get ownerFigureStock => 'মজুদের মূল্য';

  @override
  String get ownerFigureProfit => 'লাভ';

  @override
  String get ownerFigureExpenses => 'খরচ';

  @override
  String get ownerFigureCashFlow => 'নগদ প্রবাহ (নিট)';

  @override
  String get ownerAlertOverLimit => 'বাকির সীমা ছাড়িয়েছে';

  @override
  String get ownerAlertOverdue => 'মেয়াদ পেরোনো বাকি';

  @override
  String get ownerAlertStockLow => 'মজুদ পুনঃঅর্ডারের নিচে';

  @override
  String get ownerAlertStockNegative => 'মজুদ শূন্যের নিচে';

  @override
  String get ownerAlertStockDead => 'অচল মাল';

  @override
  String ownerAlertLateDelivery(int days) {
    return 'দেরির চালান ($days দিন)';
  }

  @override
  String ownerAlertFailedLogin(int days) {
    return 'ব্যর্থ লগইন ($days দিন)';
  }

  @override
  String get ownerAlertBackdated => 'পিছনের তারিখের কাগজ (এ মাসে)';

  @override
  String get ownerAlertsFailed => 'সতর্কতার তালিকা আনা গেল না।';

  @override
  String ownerFiguresMayBeOld(int minutes) {
    return 'সংখ্যাগুলো $minutes মিনিট পর্যন্ত পুরনো হতে পারে। টেনে নামালে নতুন করে আনে।';
  }

  @override
  String ownerAwaitingSignatureCount(int count) {
    return 'সইয়ের অপেক্ষায় ($countটা)';
  }

  @override
  String get ownerNothingAwaiting => 'কোনো কাগজ সইয়ের অপেক্ষায় নেই।';

  @override
  String get ownerGroupTotal => 'গ্রুপের মোট';

  @override
  String get ownerHistoryTitle => 'আগের দিনের হিসাব';

  @override
  String get ownerHistoryFailed => 'আগের দিনের হিসাব আনা গেল না।';

  @override
  String ownerHistoryDay(String day) {
    return 'কোন দিন: $day';
  }

  @override
  String ownerHistoryAsKept(String date, String time) {
    return '$date — ঐ দিন রাত $time-এ পর্দায় যা ছিল, ঠিক তাই। আজকের খাতা থেকে নতুন করে গোনা নয়।';
  }

  @override
  String get ownerHistoryNoneTitle => 'ঐ দিনের রাতের হিসাব নেই';

  @override
  String get ownerHistoryNoneMessage =>
      'হিসাব রাখা শুরু হওয়ার আগের দিন, বা সেই রাতে লেখা হয়নি।';

  @override
  String ownerHistoryTrend(String figure, int days) {
    return '$figure — গত $days দিন, রাতের হিসাব থেকে';
  }

  @override
  String get ownerHistoryMonthBefore => 'গত মাসের একই দিন';

  @override
  String get ownerReportProfitByCustomer => 'ক্রেতা ধরে লাভ';

  @override
  String get ownerReportProfitByCustomerHint =>
      'প্রতিটা বিলের সারির আসল FIFO খরচ — বিল পাকা হওয়ার সময় স্তর থেকে যা টানা হয়েছিল; ফেরত তার নিজের খরচে বাদ।';

  @override
  String get ownerReportsTitle => 'রিপোর্ট';

  @override
  String get ownerReportsFailed => 'রিপোর্টের পাতা আনা গেল না।';

  @override
  String get ownerReportsCentre => 'রিপোর্ট সেন্টার — সব রিপোর্ট';

  @override
  String get ownerReportsOwn => 'মালিকের কেন্দ্রের রিপোর্ট';

  @override
  String get ownerReportNotAllowed => 'এই রিপোর্ট দেখার অনুমতি নেই।';

  @override
  String get ownerReportsExportNote =>
      'রিপোর্টের পাতায় তারিখ বাছা, PDF আর ভাগ করা যায়; প্রতিটা নামানো রপ্তানির খাতায় লেখা থাকে।';

  @override
  String ownerSavedViews(int count) {
    return 'আমার সংরক্ষিত দৃশ্য ($countটা)';
  }

  @override
  String get ownerNoSavedViews =>
      'কোনো সংরক্ষিত দৃশ্য নেই — রিপোর্টের পাতায় \"দৃশ্য\" থেকে রাখা যায়।';

  @override
  String get ownerScheduledReports => 'নির্ধারিত রিপোর্ট';

  @override
  String get ownerSchedulesOnWeb => 'সূচি বসানো আর বদলানো ওয়েবের পাতায়।';

  @override
  String get ownerNoScheduleKey => 'নির্ধারিত রিপোর্ট বসানোর অনুমতি নেই।';

  @override
  String get ownerReportsInAppOnly =>
      'তৈরি হওয়া রিপোর্ট অ্যাপের ভিতরেই আসে, ই-মেইলে নয়।';

  @override
  String get ownerLinksTitle => 'ভাই-কোম্পানির পক্ষ';

  @override
  String get ownerLinksFailed => 'ভাই-কোম্পানির পক্ষের তালিকা আনা গেল না।';

  @override
  String get ownerLinksExplain =>
      'এক কোম্পানির কোনো ক্রেতা বা সরবরাহকারী যদি আসলে আমাদেরই আরেকটা কোম্পানি হয়, জোড়া থাকলে গ্রুপের মোট থেকে দুইয়ের মাঝের বিক্রি, পাওনা আর দেনা বাদ যায়। নাম মিলিয়ে কিছু আন্দাজ করা হয় না।';

  @override
  String get ownerLinksWebOnly =>
      'জোড়া দেওয়া আর তোলা ওয়েবের পাতায় — এখানে কেবল দেখা।';

  @override
  String get ownerLinksTotal => 'মোট জোড়া';

  @override
  String get ownerLinksNoneTitle => 'কোনো জোড়া নেই';

  @override
  String get ownerLinksNoneMessage => 'গ্রুপের মোট থেকে কিছু বাদ যাচ্ছে না।';

  @override
  String ownerLinksIsSister(String company, String sister) {
    return '$company → আসলে আমাদের $sister';
  }

  @override
  String get ownerPartyCustomer => 'ক্রেতা';

  @override
  String get ownerPartySupplier => 'সরবরাহকারী';

  @override
  String get ownerAnalysisTitle => 'বিশ্লেষণ';

  @override
  String get ownerAnalysisFailed => 'বিশ্লেষণের তালিকা আনা গেল না।';

  @override
  String get ownerPeriodThreeMonths => 'গত তিন মাস';

  @override
  String get ownerPeriodYear => 'এই বছর';

  @override
  String ownerListTopCustomers(int count) {
    return 'সেরা $count ক্রেতা';
  }

  @override
  String ownerListTopProducts(int count) {
    return 'সেরা $count পণ্য';
  }

  @override
  String ownerListTopAreas(int count) {
    return 'সেরা $count রুট';
  }

  @override
  String ownerListTopSalespeople(int count) {
    return 'সেরা $count বিক্রয়কর্মী';
  }

  @override
  String get ownerListLosing => 'সবচেয়ে কম লাভের ক্রেতা';

  @override
  String get ownerListThin => 'সবচেয়ে কম মার্জিনের পণ্য';

  @override
  String get ownerListDead => 'অচল মাল (দাম ধরে)';

  @override
  String get ownerBucketCurrent => 'চলতি বাকি';

  @override
  String ownerBucketOver(int days) {
    return '$days দিনের বেশি';
  }

  @override
  String get ownerOutstanding => 'মোট বাকি';

  @override
  String get ownerAgeing => 'বাকির বয়স — সব কোম্পানি মিলিয়ে';

  @override
  String ownerRefused(String companies) {
    return 'দেখার অনুমতি নেই: $companies';
  }

  @override
  String get ownerNothingInPeriod => 'এই সময়ে কিছু নেই।';

  @override
  String get ownerNoCostKey =>
      'খরচ দেখার অনুমতি নেই — লাভ আর মার্জিনের ঘর ঢাকা থাকবে।';

  @override
  String get ownerCompareTitle => 'তুলনা';

  @override
  String get ownerCompareFailed => 'তুলনার সংখ্যা আনা গেল না।';

  @override
  String get ownerModeCompanies => 'কোম্পানি বনাম কোম্পানি';

  @override
  String get ownerModeBranches => 'শাখা বনাম শাখা';

  @override
  String get ownerAgainstPreviousMonth => 'আগের মাসের একই দিনগুলো';

  @override
  String get ownerAgainstLastYear => 'গত বছরের একই সময়';

  @override
  String get ownerGrainDaily => 'দিন ধরে';

  @override
  String get ownerGrainWeekly => 'সপ্তাহ ধরে';

  @override
  String get ownerGrainMonthly => 'মাস ধরে';

  @override
  String get ownerGrainQuarterly => 'তিন মাস ধরে';

  @override
  String get ownerGrainYearly => 'বছর ধরে';

  @override
  String get ownerAllCompanies => 'সব কোম্পানি';

  @override
  String ownerNowThen(String now, String was) {
    return 'এখন: $now · তখন: $was';
  }

  @override
  String get ownerNothingToShow => 'দেখার মতো কিছু নেই';

  @override
  String get ownerScreenNotAllowed =>
      'কোনো কোম্পানিতে আপনার এই পর্দা দেখার অনুমতি নেই।';

  @override
  String get ownerReceivableNote =>
      'বাকি একটা জের: \"তখন\" মানে ঐ দিনের রাতে রাখা হিসাব। রাতের হিসাব না থাকলে \"—\"।';

  @override
  String ownerTrendOf(String figure) {
    return '$figure — সময়ের সাথে';
  }

  @override
  String get ownerNotAllowedToSee => 'দেখার অনুমতি নেই';

  @override
  String get ownerLastYear => 'গত বছর';

  @override
  String ownerThen(String value) {
    return 'তখন $value';
  }

  @override
  String get documentsLevelPublic => 'সবার জন্য';

  @override
  String get documentsLevelInternal => 'অভ্যন্তরীণ';

  @override
  String get documentsLevelConfidential => 'গোপন';

  @override
  String get documentsLevelHighlyConfidential => 'অতি গোপন';

  @override
  String get documentsLevelRestricted => 'সংরক্ষিত';

  @override
  String get documentsStatusDraft => 'খসড়া';

  @override
  String get documentsStatusSubmitted => 'জমা হয়েছে';

  @override
  String get documentsStatusUnderReview => 'পর্যালোচনায়';

  @override
  String get documentsStatusChangesRequested => 'বদল চাওয়া হয়েছে';

  @override
  String get documentsStatusApproved => 'অনুমোদিত';

  @override
  String get documentsStatusRejected => 'প্রত্যাখ্যাত';

  @override
  String get documentsStatusPublished => 'প্রকাশিত';

  @override
  String get documentsStatusPublishedUnapproved => 'অনুমোদন ছাড়া প্রকাশিত';

  @override
  String get documentsStatusExpired => 'মেয়াদোত্তীর্ণ';

  @override
  String get documentsStatusArchived => 'আর্কাইভে';

  @override
  String get documentsSignaturePending => 'সইয়ের অপেক্ষায়';

  @override
  String get documentsSignatureSigned => 'সই হয়েছে — চলতি ভার্সনে';

  @override
  String get documentsSignatureStale => 'সই পুরনো ভার্সনে — চলতিটায় সই নেই';

  @override
  String get documentsSignatureNone => 'সই চাওয়া হয়নি';

  @override
  String get documentsViewAll => 'সব';

  @override
  String get documentsViewMine => 'আমার';

  @override
  String get documentsViewShared => 'আমার সাথে শেয়ার';

  @override
  String get documentsViewRecent => 'সাম্প্রতিক';

  @override
  String get documentsViewExpiry => 'মেয়াদ';

  @override
  String get documentsViewArchive => 'আর্কাইভ';

  @override
  String get documentsViewRecycle => 'রিসাইকেল বিন';

  @override
  String get documentsViewSearch => 'খোঁজ (আর্কাইভসহ)';

  @override
  String get documentsTitle => 'ডকুমেন্টস';

  @override
  String get documentsListFailed =>
      'কাগজের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get documentsListAbsent =>
      'সার্ভারে ডকুমেন্টস এখনো আসেনি — অফিসে জানান।';

  @override
  String get documentsListEmpty => 'এখানে দেখার মতো কোনো কাগজ নেই';

  @override
  String get documentsSearchHint => 'নাম, নম্বর বা ট্যাগ';

  @override
  String documentsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'মোট $count টি কাগজ',
    );
    return '$_temp0';
  }

  @override
  String documentsExpires(String date) {
    return 'মেয়াদ $date';
  }

  @override
  String get documentsDeleted => 'মোছা — ফেরানো ওয়েবে';

  @override
  String get documentsFileOpenFailed => 'ফাইলটা খোলা গেল না।';

  @override
  String get documentsSignAsk => 'এই কাগজে সই দেবেন?';

  @override
  String get documentsRemarksOptional => 'মন্তব্য (ইচ্ছা হলে)';

  @override
  String get documentsNo => 'না';

  @override
  String get documentsSign => 'সই দিন';

  @override
  String get documentsSigned => 'সই দেওয়া হয়েছে।';

  @override
  String get documentsRefuseAsk => 'সই দেবেন না?';

  @override
  String get documentsReasonField => 'কারণ লিখুন';

  @override
  String get documentsGoBack => 'ফিরে যান';

  @override
  String get documentsRefuse => 'সই দেব না';

  @override
  String get documentsReasonNeeded => 'সই না দেওয়ার কারণ লিখতে হবে।';

  @override
  String get documentsRefused => 'সই দেওয়া হয়নি — কারণসহ জানানো হয়েছে।';

  @override
  String get documentsDecisionFailed =>
      'সিদ্ধান্তটা পাঠানো গেল না। আবার চেষ্টা করুন।';

  @override
  String get documentsPaper => 'কাগজ';

  @override
  String get documentsOneFailed => 'কাগজটা আনা গেল না।';

  @override
  String get documentsNumber => 'নম্বর';

  @override
  String get documentsType => 'ধরন';

  @override
  String get documentsFolder => 'ফোল্ডার';

  @override
  String get documentsLevel => 'গোপনীয়তা';

  @override
  String get documentsStatus => 'অবস্থা';

  @override
  String get documentsDate => 'তারিখ';

  @override
  String get documentsExpiry => 'মেয়াদ';

  @override
  String get documentsOwner => 'মালিক';

  @override
  String get documentsTags => 'ট্যাগ';

  @override
  String get documentsDescription => 'বিবরণ';

  @override
  String get documentsFile => 'ফাইল';

  @override
  String get documentsNoFile => 'এই কাগজে এখনো কোনো ফাইল নেই।';

  @override
  String get documentsFileName => 'নাম';

  @override
  String get documentsVersion => 'ভার্সন';

  @override
  String get documentsViewFile => 'দেখুন';

  @override
  String get documentsDownload => 'নামান / পাঠান';

  @override
  String get documentsCannotOpen =>
      'এই ফাইলটা ফোনে দেখা যায় না, আর নামানোর অধিকার আপনার নেই।';

  @override
  String get documentsSignature => 'সই';

  @override
  String documentsSignedOld(String date) {
    return '$date · পুরনো ভার্সনে';
  }

  @override
  String get documentsSignWanted =>
      'এই কাগজে আপনার সই চাওয়া হয়েছে। ফাইলটা দেখে সই দিন।';

  @override
  String get documentsUploadFormFailed =>
      'তোলার ফর্ম আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get documentsUploadAbsent =>
      'সার্ভারে ফোন থেকে তোলা এখনো আসেনি — অফিসে জানান।';

  @override
  String get documentsUploadTakePhotoFirst => 'আগে কাগজটার ছবি তুলুন।';

  @override
  String get documentsUploadPickFirst => 'আগে অন্তত একটা ছবি বা ফাইল বাছুন।';

  @override
  String get documentsUploadPickFields => 'ফোল্ডার, ধরন আর গোপনীয়তা বাছুন।';

  @override
  String documentsUploaded(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি কাগজ তোলা হলো।',
    );
    return '$_temp0';
  }

  @override
  String get documentsUploadFailed => 'কাগজ তোলা গেল না।';

  @override
  String get documentsScanTitle => 'ক্যামেরায় স্ক্যান';

  @override
  String get documentsUploadTitle => 'কাগজ তোলা';

  @override
  String get documentsLoadFailed => 'আনা গেল না';

  @override
  String get documentsTakePhoto => 'ছবি তুলুন';

  @override
  String get documentsPickPhotos => 'ছবি বাছুন';

  @override
  String get documentsPickFiles => 'ফাইল বাছুন (PDF ইত্যাদি)';

  @override
  String documentsUploadFileCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count টি ফাইল — প্রতিটা আলাদা কাগজ হবে',
    );
    return '$_temp0';
  }

  @override
  String get documentsUploadRulesNote =>
      'ⓘ কোন ধরনের ফাইল চলে আর কত বড়, সার্ভার ওয়েবের নিয়মেই দেখে। লেখা পড়া (OCR) ওয়েবে।';

  @override
  String get documentsUploadName => 'নাম (ফাঁকা রাখলে ফাইলের নাম)';

  @override
  String get documentsBranch => 'শাখা';

  @override
  String get documentsWholeCompany => 'গোটা কোম্পানি';

  @override
  String get documentsDescriptionOptional => 'বিবরণ (ঐচ্ছিক)';

  @override
  String get documentsUploading => 'তোলা হচ্ছে…';

  @override
  String get documentsUpload => 'তুলুন';

  @override
  String get leadsFailed => 'লিড আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get leadsAbsent => 'সার্ভারে লিডের দরজা এখনো আসেনি — অফিসে জানান।';

  @override
  String get leadsTitle => 'লিড';

  @override
  String get leadsNew => 'নতুন লিড';

  @override
  String get leadsSearchHint => 'নাম, ফোন বা নম্বর';

  @override
  String get leadsAll => 'সব';

  @override
  String get leadsTotal => 'মোট লিড';

  @override
  String leadsCount(int count) {
    return '$count টি';
  }

  @override
  String get leadsLoadFailed => 'আনা গেল না';

  @override
  String get leadsNone => 'কোনো লিড নেই';

  @override
  String get leadsMore => 'আরও লিড';

  @override
  String get leadsNameNeeded => 'দোকানের নাম লিখুন।';

  @override
  String get leadsSourceNeeded => 'কীভাবে খোঁজ পেলেন, বাছুন।';

  @override
  String get leadsSaveFailed => 'লিড রাখা গেল না। আবার চেষ্টা করুন।';

  @override
  String leadsStatusNow(String status) {
    return 'অবস্থা: $status';
  }

  @override
  String leadsOwner(String name) {
    return 'মালিক: $name';
  }

  @override
  String get leadsConverted => 'গ্রাহক হয়ে গেছেন — আর বদলানো যায় না।';

  @override
  String get leadsShopName => 'দোকানের নাম';

  @override
  String get leadsContactPerson => 'যাঁর সাথে কথা';

  @override
  String get leadsPhone => 'ফোন';

  @override
  String get leadsAddress => 'ঠিকানা';

  @override
  String get leadsSource => 'কীভাবে খোঁজ পেলেন';

  @override
  String get leadsStatus => 'অবস্থা';

  @override
  String get leadsLostReason => 'কেন হারালাম';

  @override
  String get leadsNotes => 'টুকিটাকি';

  @override
  String papersListFailed(String title) {
    return '$title-এর তালিকা আনা গেল না।';
  }

  @override
  String get papersListAbsent =>
      'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get papersAllBranches => 'সব শাখা';

  @override
  String get papersAll => 'সব';

  @override
  String get papersSearchHint => 'নম্বর বা নাম';

  @override
  String get papersGrandTotal => 'সর্বমোট';

  @override
  String papersPageTotal(int count) {
    return 'এই পাতার মোট ($countটি)';
  }

  @override
  String papersCount(int count) {
    return '$countটি';
  }

  @override
  String papersNone(String title) {
    return 'কোনো $title নেই';
  }

  @override
  String get papersNoneDated =>
      'তারিখ বা ছাঁকনি বদলে, বা নিচে টেনে আবার দেখুন।';

  @override
  String get papersNoneUndated => 'নিচে টেনে আবার দেখুন।';

  @override
  String get papersMore => 'আরও';

  @override
  String papersOneFailed(String title) {
    return '$title-টা আনা গেল না।';
  }

  @override
  String get papersPrint => 'ছাপা / পাঠান';

  @override
  String get papersStatus => 'অবস্থা';

  @override
  String get papersBranch => 'শাখা';

  @override
  String get papersLines => 'সারি';

  @override
  String papersTotalOf(String column) {
    return 'মোট $column';
  }

  @override
  String get papersSignatures => 'সই';

  @override
  String papersRequestedBy(String name) {
    return 'চেয়েছেন: $name';
  }

  @override
  String papersSteps(String steps) {
    return 'ধাপ: $steps';
  }

  @override
  String get papersSign => 'সই দিন';
}
