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
  String get salesDeskComingSoonTitle => 'শীঘ্রই আসছে';

  @override
  String get salesDeskComingSoonMessage =>
      'এই পর্দাটা এখনো তৈরি হচ্ছে। তৈরি হলে এখানেই খুলবে।';

  @override
  String get deliveriesTodayLoadFailed =>
      'ডেলিভারির তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get deliveriesTodayNotOnServer =>
      'সার্ভারে আজকের ডেলিভারি এখনো আসেনি — অফিসে জানান।';

  @override
  String get deliveriesTodayMoreFailed =>
      'পরের পাতা আনা গেল না। আবার চেষ্টা করুন।';

  @override
  String deliveriesTodayPartlyDelivered(String no) {
    return '$no: আংশিক পৌঁছেছে — কম আর ভাঙা মাল ফেরতের কাগজে উঠেছে।';
  }

  @override
  String deliveriesTodayDelivered(String no, String receiver) {
    return '$no: পৌঁছেছে — $receiver বুঝে নিলেন।';
  }

  @override
  String get deliveriesTodaySaveFailed =>
      'পৌঁছানো লেখা গেল না। আবার চেষ্টা করুন।';

  @override
  String get deliveriesTodayTitle => 'আজকের ডেলিভারি';

  @override
  String get deliveriesTodayTotalLabel => 'পথে মোট চালান';

  @override
  String deliveriesTodayTotalValue(String count) {
    return '$count টি';
  }

  @override
  String get deliveriesTodayEmpty => 'পথে কোনো চালান নেই।';

  @override
  String deliveriesTodayVehicle(String vehicle) {
    return 'গাড়ি $vehicle';
  }

  @override
  String deliveriesTodayDriver(String driver) {
    return 'চালক $driver';
  }

  @override
  String get deliveriesTodayHandOver => 'বুঝিয়ে দিন';

  @override
  String get deliveriesTodayMore => 'আরও দেখুন';

  @override
  String routeMyWeekday(String day) {
    String _temp0 = intl.Intl.selectLogic(
      day,
      {
        'mon': 'সোমবার',
        'tue': 'মঙ্গলবার',
        'wed': 'বুধবার',
        'thu': 'বৃহস্পতিবার',
        'fri': 'শুক্রবার',
        'sat': 'শনিবার',
        'other': 'রবিবার',
      },
    );
    return '$_temp0';
  }

  @override
  String get routeMyLoadFailed => 'রুট আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get routeMyNotOnServer =>
      'সার্ভারে রুটের দরজা এখনো আসেনি — অফিসে জানান।';

  @override
  String get routeMyPickDay => 'কোন দিনের রুট';

  @override
  String get routeMyTitle => 'আজকের রুট';

  @override
  String get routeMyErrorTitle => 'আনা গেল না';

  @override
  String get routeMyNoRouteTitle => 'এই দিনে কোনো রুট নেই';

  @override
  String get routeMyNoRouteMessage =>
      'সাপ্তাহিক ছকে এই দিনে আপনার নামে রুট বসানো নেই — অফিসে জানান।';

  @override
  String routeMyMonthSales(String amount) {
    return 'এ মাসে বিক্রি: $amount';
  }

  @override
  String routeMyMonthCollections(String amount) {
    return 'এ মাসে আদায়: $amount';
  }

  @override
  String routeMyOutstanding(String amount) {
    return 'বকেয়া: $amount';
  }

  @override
  String get routeMyMoreShops => 'আরও দোকান';

  @override
  String get salesDashboardsListLoadFailed => 'ড্যাশবোর্ডের তালিকা আনা গেল না।';

  @override
  String get salesDashboardsTitle => 'ব্যবসার ড্যাশবোর্ড';

  @override
  String get salesDashboardsModule => 'ড্যাশবোর্ড';

  @override
  String get salesDashboardsNoneTitle => 'আপনার জন্য কোনো ড্যাশবোর্ড নেই';

  @override
  String get salesDashboardsNoneMessage =>
      'কোনো মডিউলের ড্যাশবোর্ড দেখার অনুমতি পেলে এখানে আসবে।';

  @override
  String get salesDashboardsNoKey => 'চাবি নেই';

  @override
  String get salesDashboardsLoadFailed => 'ড্যাশবোর্ড আনা গেল না।';

  @override
  String get salesDashboardsEmptyTitle => 'এই ড্যাশবোর্ডে এখনো কিছু নেই';

  @override
  String get salesDashboardsEmptyMessage =>
      'কাজ শুরু হলে বা অনুমতি পেলে সংখ্যাগুলো এখানে আসবে।';

  @override
  String get salesDashboardsReports => 'এই মডিউলের রিপোর্ট';

  @override
  String salesDashboardsReportsTitle(String name) {
    return '$name — রিপোর্ট';
  }

  @override
  String get salesDashboardsOverviewLoadFailed =>
      'বিক্রয়ের হালচাল আনা গেল না।';

  @override
  String get salesDashboardsOverviewTitle => 'বিক্রয়ের হালচাল';

  @override
  String get salesDashboardsSalesModule => 'বিক্রয়';

  @override
  String get salesDashboardsOverviewNoAccess =>
      'এই পর্দার কোনো সংখ্যা দেখার অনুমতি আপনার নেই।';

  @override
  String get salesDashboardsNoKeyLong => 'চাবি নেই — দেখার অনুমতি লাগে';

  @override
  String get salesDashboardsRefresh => 'নতুন করে দেখুন';

  @override
  String get salesDashboardsGrandTotal => 'সর্বমোট';

  @override
  String get quotationsCompareListLoadFailed => 'উদ্ধৃতির তালিকা আনা গেল না।';

  @override
  String get quotationsCompareLoadFailed => 'তুলনা আনা গেল না।';

  @override
  String get quotationsCompareNotOnServer =>
      'তুলনা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get quotationsCompareTitle => 'উদ্ধৃতির তুলনা';

  @override
  String get quotationsCompareRepick => 'আবার বাছুন';

  @override
  String get quotationsCompareNeedTwoTitle => 'তুলনার জন্য অন্তত দুটো লাগে';

  @override
  String get quotationsCompareNeedTwoMessage => 'আরেকটা উদ্ধৃতি বাছুন।';

  @override
  String get quotationsCompareNoChoices => 'বাছার মতো কোনো উদ্ধৃতি নেই';

  @override
  String quotationsComparePickHint(String max) {
    return 'দুই থেকে $maxটা উদ্ধৃতি বাছুন — প্রথমটা ভিত্তি';
  }

  @override
  String quotationsCompareGo(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা তুলনা করুন',
    );
    return '$_temp0';
  }

  @override
  String quotationsCompareRevision(String revision) {
    return ' (সং $revision)';
  }

  @override
  String get quotationsRevisionsLoadFailed => 'সংস্করণের তালিকা আনা গেল না।';

  @override
  String get quotationsRevisionsNotOnServer =>
      'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get quotationsRevisionsTitle => 'উদ্ধৃতির সংস্করণ';

  @override
  String get quotationsRevisionsSearch => 'নম্বর বা দোকানের নাম';

  @override
  String get quotationsRevisionsEmptyTitle => 'কোনো উদ্ধৃতির সংস্করণ নেই';

  @override
  String get quotationsRevisionsEmptyMessage => 'নিচে টেনে আবার দেখুন।';

  @override
  String quotationsRevisionsCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটা সংস্করণ',
    );
    return '$_temp0';
  }

  @override
  String get quotationsRevisionsMore => 'আরও';

  @override
  String get salesDeskNothing => 'কিছু নেই';

  @override
  String get salesDeskLoadFailed => 'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get salesDeskNotOnServer =>
      'সার্ভারে ফোনের এই পর্দা এখনো আসেনি — অফিসে জানান।';

  @override
  String get salesDeskErrorTitle => 'আনা গেল না';

  @override
  String get salesDeskDepotTitle => 'ডিপোর যাচাই';

  @override
  String get salesDeskDepotEmpty => 'যাচাইয়ের অপেক্ষায় কিছু নেই';

  @override
  String get salesDeskDepotSearch => 'নম্বর, দোকানের নাম বা কোড';

  @override
  String get salesDeskDepotTotal => 'যাচাইয়ের অপেক্ষায় DO';

  @override
  String get salesDeskDepotWebNote =>
      'ⓘ যাচাই করে বিক্রয়ে খোলা এখনো ওয়েবের কাউন্টারে।';

  @override
  String salesDeskDepotLine(String date, int lines, String status) {
    String _temp0 = intl.Intl.pluralLogic(
      lines,
      locale: localeName,
      other: '$linesটি পণ্য',
    );
    return '$date · $_temp0 · $status';
  }

  @override
  String get salesDeskDepotHeld => 'ধরে রাখা বিক্রয় আদেশ';

  @override
  String get salesDeskPrintTitle => 'না ছাপা কাগজ';

  @override
  String get salesDeskPrintEmpty => 'সব কাগজ ছাপা হয়েছে';

  @override
  String salesDeskTotalCount(String count) {
    return 'মোট $count টি';
  }

  @override
  String salesDeskPrintPaper(String paper) {
    return 'কাগজ: $paper';
  }

  @override
  String salesDeskPrintReason(String reason) {
    return 'কারণ: $reason';
  }

  @override
  String get salesDeskPrintSettled => 'ছাপা হয়েছে বলে সরানো হলো।';

  @override
  String get salesDeskPrintSettleFailed => 'সরানো গেল না।';

  @override
  String get salesDeskPrintSettle => 'হাতে ছাপা হলো';

  @override
  String get salesDeskDeliveryTitle => 'ডেলিভারির কাজ';

  @override
  String salesDeskDeliveryLate(String count) {
    return 'দেরির চালান · $count টি';
  }

  @override
  String salesDeskDeliveryDaysLate(String days) {
    return '$days দিন দেরি';
  }

  @override
  String salesDeskDeliveryPromised(String promised, String arrived) {
    return 'কথা ছিল $promised · পৌঁছেছে $arrived';
  }

  @override
  String salesDeskDeliveryOrder(String no) {
    return 'আদেশ $no';
  }

  @override
  String get salesDeskPipelineTitle => 'পাইপলাইন';

  @override
  String get salesDeskPipelineEmpty => 'কোনো সম্ভাবনা নেই';

  @override
  String get salesDeskPipelineOpen => 'চলমান সম্ভাবনা (ওজন-করা)';

  @override
  String salesDeskPipelineStage(String stage, String count) {
    return '$stage · $count টি';
  }

  @override
  String salesDeskPipelineTotals(String total, String weighted) {
    return 'মোট $total · ওজন-করা $weighted';
  }

  @override
  String salesDeskPipelineCloses(String date) {
    return 'শেষ $date';
  }

  @override
  String get salesDeskTemplatesTitle => 'দলিলের ছাঁচ';

  @override
  String get salesDeskTemplatesEmpty => 'কোনো ছাঁচ নেই';

  @override
  String salesDeskTemplatesCount(String count) {
    return 'মোট $count টি ছাঁচ · লেখা ও বদল ওয়েবে';
  }

  @override
  String get salesDeskTemplatesInactive => 'বন্ধ';

  @override
  String salesPapersKindTitle(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'বিক্রয় বিল',
        'other': 'চালান',
      },
    );
    return '$_temp0';
  }

  @override
  String salesPapersListLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'বিক্রয় বিলের তালিকা আনা গেল না।',
        'other': 'চালানের তালিকা আনা গেল না।',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersNotOnServer =>
      'এই তালিকা এখনো এই সার্ভারে নেই — অ্যাপটা সার্ভারের চেয়ে নতুন।';

  @override
  String get salesPapersSearch => 'নম্বর বা দোকানের নাম';

  @override
  String salesPapersTotal(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'মোট বিক্রয় বিল',
        'other': 'মোট চালান',
      },
    );
    return '$_temp0';
  }

  @override
  String salesPapersEmptyTitle(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'এই সময়ে কোনো বিক্রয় বিল নেই',
        'other': 'এই সময়ে কোনো চালান নেই',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersEmptyMessage => 'তারিখ বদলে বা নিচে টেনে আবার দেখুন।';

  @override
  String salesPapersDueAmount(String amount) {
    return 'বাকি $amount';
  }

  @override
  String get salesPapersPaid => 'শোধ';

  @override
  String get salesPapersMore => 'আরও';

  @override
  String salesPapersLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'invoice': 'বিক্রয় বিলটা আনা গেল না।',
        'other': 'চালানটা আনা গেল না।',
      },
    );
    return '$_temp0';
  }

  @override
  String get salesPapersPrint => 'ছাপা / পাঠান';

  @override
  String get salesPapersDate => 'তারিখ';

  @override
  String get salesPapersSaleNo => 'বিক্রির নম্বর';

  @override
  String get salesPapersDueOn => 'মেয়াদ';

  @override
  String get salesPapersWarehouse => 'গুদাম';

  @override
  String get salesPapersVehicle => 'গাড়ি';

  @override
  String get salesPapersCreatedBy => 'লিখেছেন';

  @override
  String get salesPapersProducts => 'পণ্য';

  @override
  String salesPapersFreeQty(String qty) {
    return 'ফ্রি $qty';
  }

  @override
  String salesPapersLineDiscount(String amount) {
    return 'ছাড় $amount';
  }

  @override
  String get salesPapersDiscount => 'ছাড়';

  @override
  String get salesPapersTax => 'কর';

  @override
  String get salesPapersGrandTotal => 'মোট';

  @override
  String get salesPapersCollected => 'আদায়';

  @override
  String get salesPapersDue => 'বাকি';

  @override
  String get salesPapersInvoiceChallans => 'এই বিলের চালান';

  @override
  String get salesPapersChallanInvoices => 'এই চালানের বিল';

  @override
  String salesPapersStatus(String status) {
    String _temp0 = intl.Intl.selectLogic(
      status,
      {
        'draft': 'খসড়া',
        'confirmed': 'নিশ্চিত',
        'cancelled': 'বাতিল',
        'closed': 'সম্পন্ন',
        'awaiting_approval': 'অনুমোদনের অপেক্ষায়',
        'other': 'অজানা',
      },
    );
    return '$_temp0';
  }

  @override
  String quotationsListTab(String tab) {
    String _temp0 = intl.Intl.selectLogic(
      tab,
      {
        'all': 'সব',
        'draft': 'খসড়া',
        'approved': 'অনুমোদিত',
        'sent': 'পাঠানো',
        'accepted': 'গৃহীত',
        'other': 'আদেশ হয়েছে',
      },
    );
    return '$_temp0';
  }

  @override
  String get quotationsListLoadFailed =>
      'উদ্ধৃতি আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get quotationsListNotOnServer =>
      'সার্ভারে উদ্ধৃতির দরজা এখনো আসেনি — অফিসে জানান।';

  @override
  String get quotationsListTitle => 'উদ্ধৃতি';

  @override
  String get quotationsListNew => 'নতুন উদ্ধৃতি';

  @override
  String get quotationsListTotal => 'মোট উদ্ধৃতি';

  @override
  String get quotationsListErrorTitle => 'আনা গেল না';

  @override
  String get quotationsListEmpty => 'কোনো উদ্ধৃতি নেই';

  @override
  String get quotationsListMore => 'আরও উদ্ধৃতি';

  @override
  String get quotationsNewPickProduct => 'পণ্য বাছুন';

  @override
  String get quotationsNewNeedShop => 'দোকান বাছুন।';

  @override
  String get quotationsNewNeedLine => 'অন্তত একটা পণ্য দিন।';

  @override
  String get quotationsNewSaveFailed =>
      'উদ্ধৃতি রাখা গেল না। আবার চেষ্টা করুন।';

  @override
  String get quotationsNewPickShop => 'দোকান বাছুন';

  @override
  String quotationsQty(String qty) {
    return 'পরিমাণ: $qty';
  }

  @override
  String quotationsRate(String rate) {
    return 'দর: $rate';
  }

  @override
  String get quotationsNewProductPrice => 'পণ্যের দাম';

  @override
  String get quotationsNewRemoveLine => 'বাদ দিন';

  @override
  String get quotationsNewAddLine => 'পণ্য যোগ করুন';

  @override
  String get quotationsNewNote => 'মন্তব্য (ঐচ্ছিক)';

  @override
  String get quotationsSubmit => 'জমা দিন';

  @override
  String get quotationsNewKeepDraft => 'খসড়া রাখুন';

  @override
  String get quotationsActFailed => 'কাজটা হলো না। আবার চেষ্টা করুন।';

  @override
  String quotationsStatus(String status) {
    return 'অবস্থা: $status';
  }

  @override
  String quotationsShop(String shop) {
    return 'দোকান: $shop';
  }

  @override
  String quotationsDate(String date) {
    return 'তারিখ: $date';
  }

  @override
  String quotationsValidUntil(String date) {
    return 'মেয়াদ: $date';
  }

  @override
  String quotationsTotal(String amount) {
    return 'মোট: $amount';
  }

  @override
  String quotationsOrder(String no) {
    return 'আদেশ: $no';
  }

  @override
  String get quotationsSubmitted => 'জমা হলো।';

  @override
  String get quotationsSentDone => 'দোকানিকে পাঠানো হলো।';

  @override
  String get quotationsSend => 'দোকানিকে পাঠানো হলো';

  @override
  String get quotationsAcceptedDone => 'দোকানি রাজি — এখন আদেশ বানানো যায়।';

  @override
  String get quotationsAccept => 'দোকানি রাজি';

  @override
  String get quotationsRejectReason => 'রাজি না হওয়ার কারণ';

  @override
  String get quotationsRejectNeedReason => 'রাজি না হওয়ার কারণ লিখুন।';

  @override
  String get quotationsRejectedDone => 'দোকানি রাজি নন — লেখা হলো।';

  @override
  String get quotationsReject => 'দোকানি রাজি নন';

  @override
  String get quotationsConvertedDone => 'বিক্রয় আদেশ হলো।';

  @override
  String get quotationsConvert => 'আদেশ বানান';

  @override
  String get quotationsLineQty => 'পরিমাণ';

  @override
  String get quotationsLineRate => 'দর';

  @override
  String get quotationsLineCancel => 'বাদ';

  @override
  String get quotationsLineOk => 'ঠিক আছে';

  @override
  String deliveryOrdersPaper(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ',
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
        'order': 'বিক্রয় আদেশ',
        'other': 'ডেলিভারি অর্ডার',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersListLoadFailed =>
      'তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String deliveryOrdersNotOnServer(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'সার্ভারে বিক্রয় আদেশ এখনো আসেনি — অফিসে জানান।',
        'other': 'সার্ভারে ডেলিভারি অর্ডার এখনো আসেনি — অফিসে জানান।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersModule => 'বিক্রয়';

  @override
  String deliveryOrdersNew(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'নতুন আদেশ',
        'other': 'নতুন DO',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersFilterAll => 'সব';

  @override
  String get deliveryOrdersFilterMine => 'আমার সইয়ের অপেক্ষায়';

  @override
  String deliveryOrdersEmpty(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'কোনো আদেশ নেই।',
        'other': 'কোনো DO নেই।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersEmptyMine => 'এখন আপনার সইয়ের অপেক্ষায় কিছু নেই।';

  @override
  String get deliveryOrdersNoCustomer => 'গ্রাহক নেই';

  @override
  String get deliveryOrdersPickProduct => 'পণ্য বাছুন';

  @override
  String get deliveryOrdersNeedDealer => 'ডিলার বাছুন।';

  @override
  String get deliveryOrdersNeedLine => 'অন্তত একটা পণ্য দিন।';

  @override
  String deliveryOrdersSaveFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ রাখা গেল না। আবার চেষ্টা করুন।',
        'other': 'DO রাখা গেল না। আবার চেষ্টা করুন।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersPickDealer => 'ডিলার বাছুন';

  @override
  String deliveryOrdersQty(String qty) {
    return 'পরিমাণ: $qty';
  }

  @override
  String get deliveryOrdersRemoveLine => 'বাদ দিন';

  @override
  String get deliveryOrdersAddLine => 'পণ্য যোগ করুন';

  @override
  String get deliveryOrdersNote => 'মন্তব্য (ঐচ্ছিক)';

  @override
  String get deliveryOrdersSubmit => 'জমা দিন';

  @override
  String get deliveryOrdersKeepDraft => 'খসড়া রাখুন';

  @override
  String get deliveryOrdersActFailed => 'কাজটা হলো না। আবার চেষ্টা করুন।';

  @override
  String deliveryOrdersLoadFailed(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ আনা গেল না।',
        'other': 'DO আনা গেল না।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersPrint => 'ছাপুন / পাঠান';

  @override
  String deliveryOrdersPrintTitle(String no) {
    return 'বিক্রয় আদেশ · $no';
  }

  @override
  String deliveryOrdersStatus(String status) {
    return 'অবস্থা: $status';
  }

  @override
  String deliveryOrdersTotal(String amount) {
    return 'মোট: $amount';
  }

  @override
  String deliveryOrdersCreditShort(String amount) {
    return 'বাকির সীমায় আটকে — কম $amount';
  }

  @override
  String deliveryOrdersAsked(String qty) {
    return 'চাওয়া: $qty';
  }

  @override
  String get deliveryOrdersApprovedQtyField => 'অনুমোদিত পরিমাণ';

  @override
  String deliveryOrdersApprovedQty(String qty) {
    return 'অনুমোদিত: $qty';
  }

  @override
  String deliveryOrdersSubmitted(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ জমা হলো — সুপারভাইজার দেখবেন।',
        'other': 'DO জমা হলো — সুপারভাইজার দেখবেন।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersQtySaved => 'অনুমোদিত পরিমাণ রাখা হলো।';

  @override
  String get deliveryOrdersSaveQty => 'পরিমাণ রাখুন';

  @override
  String get deliveryOrdersApproved => 'অনুমোদন দেওয়া হলো।';

  @override
  String get deliveryOrdersApprove => 'অনুমোদন দিন';

  @override
  String get deliveryOrdersRejectReason => 'ফেরতের কারণ';

  @override
  String get deliveryOrdersRejectNeedReason => 'ফেরতের কারণ লিখুন।';

  @override
  String deliveryOrdersRejected(String kind) {
    String _temp0 = intl.Intl.selectLogic(
      kind,
      {
        'order': 'আদেশ ফেরত পাঠানো হলো।',
        'other': 'DO ফেরত পাঠানো হলো।',
      },
    );
    return '$_temp0';
  }

  @override
  String get deliveryOrdersReject => 'ফেরত পাঠান';

  @override
  String get deliveryOrdersDialogQty => 'পরিমাণ';

  @override
  String get deliveryOrdersDialogCancel => 'বাদ';

  @override
  String get deliveryOrdersDialogOk => 'ঠিক আছে';

  @override
  String get deliveryOrdersStockTitle => 'মজুদ এখন — ধরা হয়নি';

  @override
  String get deliveryOrdersStockNote =>
      'মাল আটকায় ডিপোর চালান নিশ্চিত হলে; এখানে কেবল কত আছে আর কত চাই।';

  @override
  String deliveryOrdersStockHaveWant(String have, String want) {
    return 'আছে $have · চাই $want';
  }

  @override
  String deliveryOrdersStockWant(String want) {
    return 'চাই $want';
  }

  @override
  String deliveryOrdersStockShort(String qty) {
    return 'কম $qty';
  }

  @override
  String get deliveryOrdersStockNotEnough => 'মজুদে কুলোয় না';

  @override
  String get salesPlanLoadFailed => 'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';

  @override
  String get salesPlanNotOnServer =>
      'সার্ভারে ফোনের এই পর্দা এখনো আসেনি — অফিসে জানান।';

  @override
  String get salesPlanPreviousMonth => 'আগের মাস';

  @override
  String get salesPlanNextMonth => 'পরের মাস';

  @override
  String salesPlanPeople(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count জন',
    );
    return '$_temp0';
  }

  @override
  String get salesPlanTargetsTitle => 'লক্ষ্যমাত্রা';

  @override
  String get salesPlanTargetsEmpty => 'এই মাসে কোনো বিক্রয়কর্মী নেই';

  @override
  String get salesPlanTargetsNote =>
      'ⓘ লক্ষ্য বসানো ওয়েবে। অর্জন নিশ্চিত বিলের, ভ্যাট আর ছাড় বাদে।';

  @override
  String get salesPlanTotalTarget => 'মোট লক্ষ্য';

  @override
  String get salesPlanTotalAchieved => 'মোট অর্জন';

  @override
  String get salesPlanTargetHoldersAchieved => 'লক্ষ্যধারীদের অর্জন';

  @override
  String get salesPlanTargetSet => 'লক্ষ্য বসানো';

  @override
  String salesPlanTargetAchieved(String target, String achieved) {
    return 'লক্ষ্য $target · অর্জন $achieved';
  }

  @override
  String get salesPlanDealerTargetsTitle => 'ডিলারের লক্ষ্য';

  @override
  String get salesPlanDealerTargetsNoneSet => 'এই মাসে কারো লক্ষ্য বসানো নেই';

  @override
  String get salesPlanDealerTargetsEmpty => 'কোনো ডিলার নেই';

  @override
  String get salesPlanDealerTargetsSearch => 'ডিলারের নাম বা কোড';

  @override
  String get salesPlanDealerTargetsOnlySet => 'কেবল যাঁদের লক্ষ্য আছে';

  @override
  String get salesPlanDealerTargetsNote =>
      'ⓘ লক্ষ্য বসানো ওয়েবে। অর্জন মানে আদায়, রসিদ আর পাশ হওয়া চেক।';

  @override
  String get salesPlanTotalDue => 'মোট বাকি';

  @override
  String salesPlanAchieved(String amount) {
    return 'অর্জন $amount';
  }

  @override
  String salesPlanDue(String amount) {
    return 'বাকি $amount';
  }

  @override
  String salesPlanEnds(String date) {
    return 'শেষ $date';
  }

  @override
  String get salesPlanRouteBookTitle => 'রুটের খাতা';

  @override
  String get salesPlanRouteBookEmpty => 'কোনো রুট নেই';

  @override
  String get salesPlanRouteBookSearch => 'রুটের নাম বা কোড';

  @override
  String get salesPlanTotalSales => 'মোট বিক্রি';

  @override
  String get salesPlanTotalCollections => 'মোট আদায়';

  @override
  String get salesPlanTargetAchievement => 'লক্ষ্যের অর্জন';

  @override
  String get salesPlanDealers => 'ডিলার';

  @override
  String salesPlanUnrouted(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '⚠ $count জন ডিলার কোনো রুটে বসানো নেই — তাঁদের অঙ্ক এখানে নেই।',
    );
    return '$_temp0';
  }

  @override
  String salesPlanDealerCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count জন ডিলার',
    );
    return '$_temp0';
  }

  @override
  String salesPlanSalesCollections(String sales, String collections) {
    return 'বিক্রি $sales · আদায় $collections';
  }

  @override
  String salesPlanTargetPercent(String target, String percent) {
    return 'লক্ষ্য $target · $percent';
  }

  @override
  String get salesPlanRouteEmpty => 'এই রুটে কোনো ডিলার নেই';

  @override
  String get salesPlanClosingBalance => 'শেষ জের (বাকি)';

  @override
  String get salesPlanOpeningBalance => 'আগের জের';

  @override
  String get salesPlanSales => 'বিক্রি';

  @override
  String get salesPlanReturns => 'ফেরত';

  @override
  String get salesPlanCollections => 'আদায়';

  @override
  String get salesPlanOther => 'অন্যান্য';

  @override
  String get salesPlanTarget => 'লক্ষ্য';

  @override
  String get salesPlanWeeklyPlan => 'সাপ্তাহিক ছক';

  @override
  String get salesPlanWeeklyPlanEmpty => 'ছকে কেউ বসানো নেই।';

  @override
  String get salesPlanVisitActive => 'চালু';

  @override
  String get salesPlanVisitEnded => 'শেষ';

  @override
  String salesPlanFromTo(String from, String to) {
    return '$from থেকে $to';
  }

  @override
  String get salesPlanOngoing => 'চলছে';

  @override
  String get salesPlanRouteNote => 'ⓘ ছক আর লক্ষ্য বসানো ওয়েবে।';

  @override
  String get salesPlanStatusAll => 'সব';

  @override
  String get salesPlanSchemesTitle => 'স্কিম';

  @override
  String get salesPlanSchemesEmpty => 'কোনো স্কিম নেই';

  @override
  String get salesPlanSchemesSearch => 'স্কিমের কোড বা নাম';

  @override
  String salesPlanSchemesCount(String count) {
    return 'মোট $count টি স্কিম · বসানো ও বদল ওয়েবে';
  }

  @override
  String salesPlanSchemeLine(String basis, String appliesTo, int rules) {
    String _temp0 = intl.Intl.pluralLogic(
      rules,
      locale: localeName,
      other: '$rulesটি ধাপ',
    );
    return '$basis · $appliesTo · $_temp0';
  }

  @override
  String get salesPlanSchemeLive => ' · আজ চলছে';

  @override
  String get salesPlanSchemeCode => 'কোড';

  @override
  String get salesPlanSchemeStatus => 'অবস্থা';

  @override
  String get salesPlanSchemeBasis => 'ভিত্তি';

  @override
  String get salesPlanSchemeAppliesTo => 'কীসের উপর';

  @override
  String get salesPlanSchemeValidity => 'মেয়াদ';

  @override
  String get salesPlanSchemeNotes => 'নোট';

  @override
  String salesPlanSchemeSteps(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: 'ধাপ · $countটি',
    );
    return '$_temp0';
  }

  @override
  String get salesPlanSchemeNoSteps => 'কোনো ধাপ বসানো নেই';

  @override
  String salesPlanSchemeRoleLevel(String role, String level) {
    return '$role · স্তর $level';
  }

  @override
  String salesPlanSchemeFromUp(String from) {
    return '$from থেকে উপরে';
  }

  @override
  String get salesPlanSchemeNote => 'ⓘ ধাপ বসানো, চালু আর থামানো ওয়েবে।';

  @override
  String get salesPlanCommissionTitle => 'কমিশন';

  @override
  String get salesPlanCommissionEmpty => 'কোনো কমিশন নেই';

  @override
  String get salesPlanCommissionSearch => 'নম্বর, ডিলার বা কোম্পানি';

  @override
  String get salesPlanCommissionGrandTotal => 'সর্বমোট';

  @override
  String get salesPlanCommissionPending => 'কোম্পানির কাছে দাবি, এখনো বাকি';

  @override
  String salesPlanCommissionByStatus(String label, int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$countটি',
    );
    return '$label · $_temp0';
  }

  @override
  String get salesPlanCommissionTotalNote =>
      'ⓘ বাতিল দাবি মোটে নেই; ফেরত দেওয়া দাবি ডিপোর খরচ হিসেবে মোটে আছে';

  @override
  String get salesPlanCommissionWebNote =>
      'ⓘ নতুন দাবি, মেটানো আর বাতিল ওয়েবে।';

  @override
  String salesPlanCommissionInvoice(String no) {
    return 'বিল $no';
  }

  @override
  String salesPlanCommissionBase(String amount) {
    return 'ভিত্তি $amount';
  }

  @override
  String salesPlanCommissionRate(String rate) {
    return 'হার $rate';
  }

  @override
  String salesPlanCommissionLump(String amount) {
    return 'থোক $amount';
  }

  @override
  String salesPlanCommissionReason(String reason) {
    return 'কারণ: $reason';
  }
}
