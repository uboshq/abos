import 'dart:ui' show Brightness, Color;

/// ⭐ কাগজের অবস্থা — অ্যাপের এক তালিকা (মালিকের নকশা ৩, অংশ ৫, ২০ অক্টোবর ২০২৬: "সব পাতায় এক নিয়ম")।
///
/// <p>প্রতিটা অবস্থায় আইকন, রং আর বাংলা লেখা তিনটাই একসাথে (WCAG 2.2, ১.৪.১ — শুধু রং দিয়ে মানে নয়)। রং SAP Fiori
/// Horizon-এর অর্থবোধক রং (আলোয় sap_horizon, অন্ধকারে sap_horizon_dark):
/// https://unpkg.com/@sap-theming/theming-base-content@11.29.3/content/Base/baseLib/sap_horizon/css_variables.css
/// https://unpkg.com/@sap-theming/theming-base-content@11.29.3/content/Base/baseLib/sap_horizon_dark/css_variables.css
///
/// <p>⛔ এক উৎস: রেপোর `resources/design/paper_states.json` (ওয়েবের চিপও এটা থেকে)। এখানে বদলালে ওখানেও —
/// `test/status/paper_states_json_test.dart` একটুও অমিল পেলে লাল হয়। আইকনের নাম থেকে IconData কেবল
/// `paper_state_icons.dart`-এ।
enum PaperState {
  draft('draft'),

  /// ⭐ সংরক্ষণের আগে: এই কাগজে সই লাগবে — একটা পূর্বাভাস, এখনো সত্যি নয়, তাই ভাঙা ধার (cb 76c7d5283, ২৫ অক্টোবর ২০২৬);
  /// কেবল সার্ভারের কথায় বসে (প্রিভিউর `needs_signature`), সার্ভারের কোনো অবস্থার লেখা নয়
  willWait('will_wait'),
  waiting('waiting'),
  sentBack('sent_back'),
  posting('posting'),
  posted('posted'),
  cancelled('cancelled'),
  notPosted('not_posted'),

  /// কেবল অ্যাপের: ফোনে রাখা, এখনো সার্ভারে যায়নি (বিক্রয়কর্মীর অফলাইন আদায়; মালিকের পুরো-পাতার নমুনা, ২০ অক্টোবর ২০২৬)
  unsent('unsent'),

  /// সইদাতা "না" বলেছেন — শেষ অবস্থা; বাতিল থেকে আলাদা (বাতিল মানে কাগজটা নিজেই তুলে নেওয়া)
  rejected('rejected'),

  /// অনুমোদিত, অনুমোদিত — খরচের দাবিতে "টাকার অপেক্ষায়" ([PaperKind.claim])
  approved('approved');

  const PaperState(this.key);

  /// JSON-এর চাবি
  final String key;

  PaperStateSpec get spec => paperStateSpecs[this]!;

  /// ⭐ সার্ভারের অবস্থার লেখা → চিপ — সব পর্দার এক জায়গা। না চিনলে null: পর্দা তখন সার্ভারের নিজের লেখাই দেখায়,
  /// আন্দাজে অন্য অবস্থা বসায় না।
  ///
  /// <p>যেসব লেখা পাওয়া গেছে (চাবি — কোথা থেকে):
  /// <ul>
  /// <li>`draft` — ভাউচার (core/accounts/voucher_api.dart, `state`), বিক্রয় বিল/চালান (sales_paper_screens.dart `_status`)
  /// <li>`awaiting` — ভাউচার, অনুমোদনের অপেক্ষায় (voucher_api.dart; সার্ভার VoucherApiController::row)
  /// <li>`awaiting_approval` — বিক্রয় বিল/চালান (sales_paper_screens.dart `_status`)
  /// <li>`submitted` — খরচের দাবি/অগ্রিম, অনুমোদনের অপেক্ষায় (core/hr/claims_api.dart; সার্ভার ExpenseClaim::SUBMITTED)
  /// <li>`pending` — নিজের পাঠানো সইয়ের অনুরোধ, অপেক্ষায় (approvals/my_requests_screen.dart)
  /// <li>`returned` · `sent_back` — সই না দিয়ে ফেরত (approvals/my_requests_screen.dart `returned`)
  /// <li>`posting` — শেষ সই পড়েছে, খাতায় উঠছে
  /// <li>`posted` — ভাউচার পোস্ট হয়েছে
  /// <li>`confirmed` · `closed` — বিক্রয় বিল/চালান নিশ্চিত/সম্পন্ন (sales_paper_screens.dart `_status`)
  /// <li>`paid` — খরচের দাবি টাকা দেওয়া, ভাউচার পোস্ট হয়েছে (claims_api.dart; ExpenseClaim::PAID); চিপে "পরিশোধিত"
  /// <li>`cancelled` — ভাউচার, বিক্রয় বিল/চালান
  /// <li>`rejected` — খরচের দাবি নামঞ্জুর (claims_api.dart; ExpenseClaim::REJECTED), সইয়ের অনুরোধ নামঞ্জুর
  /// (my_requests_screen.dart) — [PaperState.rejected], বাতিল নয়
  /// <li>`approved` — খরচের দাবি অনুমোদিত, টাকার অপেক্ষায় (ExpenseClaim::APPROVED; চিপে [PaperKind.claim]), সইয়ের
  /// অনুরোধ মঞ্জুর — [PaperState.approved]
  /// <li>`not_posted` · `post_failed` — খাতায় তোলা আটকে গেছে
  /// </ul>
  /// ⓘ [PaperState.unsent] সার্ভারের কোনো লেখা নয় — ফোনের সারিতে জমা থাকা কাগজ; পর্দা নিজেই বসায়।
  static PaperState? fromServer(String state) => switch (state.trim().toLowerCase()) {
        'draft' => PaperState.draft,
        // ⓘ `waiting` — এই অবস্থার নিজের চাবি; আদায়ের সিঙ্ক-উত্তর এটাই পাঠায় (a6 d5cc9657f, CollectionApiController::answer)
        'waiting' || 'awaiting' || 'awaiting_approval' || 'submitted' || 'pending' => PaperState.waiting,
        'returned' || 'sent_back' => PaperState.sentBack,
        'posting' => PaperState.posting,
        'posted' || 'confirmed' || 'closed' || 'paid' => PaperState.posted,
        'cancelled' => PaperState.cancelled,
        'rejected' => PaperState.rejected,
        'approved' => PaperState.approved,
        'not_posted' || 'post_failed' => PaperState.notPosted,
        _ => null,
      };
}

/// ⭐ কাগজের ধরন — অবস্থা একটাই, কথা ধরন মেনে (fe, ২০ অক্টোবর ২০২৬): টাকার কাগজ "পোস্ট হয়েছে · খাতায় উঠেছে", মজুদের
/// কাগজ (চালান, ডেলিভারি, মজুদ বদলি) "পোস্ট হয়েছে · মজুদে উঠেছে", খরচের দাবি "অনুমোদিত · টাকার অপেক্ষায়" আর "পরিশোধিত"।
/// ⓘ এক ধারণা, এক শব্দ — মালিকের নিজের শব্দ, ওয়েবে আর অ্যাপে একই (fe, ২১ অক্টোবর ২০২৬); চিপে "পাকা" আর নেই। JSON-এ `<নাম>_<ধরন>`
/// চাবি থাকলে সেটা, নইলে সাধারণ চাবি।
enum PaperKind { money, stock, claim }

/// Fiori-র অর্থবোধক ভূমিকা
enum PaperRole { neutral, critical, information, positive, negative }

/// এক আলোর (বা অন্ধকারের) তিন রং — লেখা ও আইকন, পট, ধার
class PaperColours {
  const PaperColours({required this.foreground, required this.background, required this.border});

  final Color foreground;
  final Color background;
  final Color border;
}

/// এক ভূমিকার রং — Fiori-র প্যারামিটারের নাম সহ
class PaperRoleSpec {
  const PaperRoleSpec(
      {required this.fiori, required this.light, required this.dark, this.adjustedForContrast = false, this.fioriOriginal = const {}});

  /// foreground · background · border → Fiori প্যারামিটার
  final Map<String, String> fiori;
  final PaperColours light;
  final PaperColours dark;
  final bool adjustedForContrast;

  /// কোন রং Fiori-র থেকে সরানো, আর Fiori-র আসল মান — যেমন `light.border` → `#788FA6`
  final Map<String, String> fioriOriginal;

  PaperColours of(Brightness b) => b == Brightness.dark ? dark : light;
}

const paperRoleSpecs = <PaperRole, PaperRoleSpec>{
  PaperRole.neutral: PaperRoleSpec(
    fiori: {'foreground': 'sapNeutralTextColor', 'background': 'sapNeutralBackground', 'border': 'sapNeutralBorderColor'},
    // ⓘ WCAG ১.৪.১১: Fiori-র #788FA6 নিজের পটে 2.95:1 — একই রঙে আলো 56.1% থেকে 55.5%, এখন 3.03:1
    light: PaperColours(foreground: Color(0xFF131E29), background: Color(0xFFEFF1F2), border: Color(0xFF768DA5)),
    dark: PaperColours(foreground: Color(0xFFF5F6F7), background: Color(0xFF242E38), border: Color(0xFFA9B4BE)),
    adjustedForContrast: true,
    fioriOriginal: {'light.border': '#788FA6'},
  ),
  PaperRole.critical: PaperRoleSpec(
    fiori: {'foreground': 'sapCriticalTextColor', 'background': 'sapWarningBackground', 'border': 'sapWarningBorderColor'},
    light: PaperColours(foreground: Color(0xFFB44F00), background: Color(0xFFFFF8D6), border: Color(0xFFDD6100)),
    dark: PaperColours(foreground: Color(0xFFFFDF72), background: Color(0xFF382700), border: Color(0xFFF7BF00)),
  ),
  PaperRole.information: PaperRoleSpec(
    fiori: {'foreground': 'sapInformativeTextColor', 'background': 'sapInformationBackground', 'border': 'sapInformationBorderColor'},
    light: PaperColours(foreground: Color(0xFF0064D9), background: Color(0xFFE1F4FF), border: Color(0xFF0070F2)),
    dark: PaperColours(foreground: Color(0xFF4DB1FF), background: Color(0xFF00144A), border: Color(0xFF4DB1FF)),
  ),
  PaperRole.positive: PaperRoleSpec(
    fiori: {'foreground': 'sapPositiveTextColor', 'background': 'sapSuccessBackground', 'border': 'sapSuccessBorderColor'},
    light: PaperColours(foreground: Color(0xFF256F3A), background: Color(0xFFF5FAE5), border: Color(0xFF30914C)),
    dark: PaperColours(foreground: Color(0xFF97DD40), background: Color(0xFF11331A), border: Color(0xFF6DAD1F)),
  ),
  PaperRole.negative: PaperRoleSpec(
    fiori: {'foreground': 'sapNegativeTextColor', 'background': 'sapErrorBackground', 'border': 'sapErrorBorderColor'},
    light: PaperColours(foreground: Color(0xFFAA0808), background: Color(0xFFFFEAF4), border: Color(0xFFE90B0B)),
    dark: PaperColours(foreground: Color(0xFFFA6161), background: Color(0xFF350000), border: Color(0xFFFA6161)),
  ),
};

/// এক অবস্থার সব — লেখা (বাংলা আর ইংরেজি), TalkBack-এর কথা, আইকনের নাম, ভূমিকা, ধারের ধরন।
///
/// <p>⭐ দুই ভাষা পাশাপাশি, JSON-এর মতোই (`label_bn` · `label_en` …; ভাষার সুইচ 0.4.43)। `english: true` দিলে ইংরেজি, নইলে
/// বাংলা — চিপ অ্যাপের ভাষা দেখে ঠিক করে ([AppStatusChip])।
class PaperStateSpec {
  const PaperStateSpec({
    required this.labelBn,
    required this.labelEn,
    required this.labelBnWithoutLevel,
    required this.labelEnWithoutLevel,
    required this.semanticsBn,
    required this.semanticsEn,
    required this.semanticsBnWithoutLevel,
    required this.semanticsEnWithoutLevel,
    required this.labelBnShort,
    required this.labelEnShort,
    required this.labelBnShortWithoutLevel,
    required this.labelEnShortWithoutLevel,
    required this.icon,
    required this.role,
    this.dashed = false,
    this.tappable = false,
    this.semanticsHintBn,
    this.semanticsHintEn,
    this.reasonTitleBn,
    this.reasonTitleEn,
    this.reasonMissingBn,
    this.reasonMissingEn,
    this.reasonCloseBn,
    this.reasonCloseEn,
    this.appOnly = false,
    this.byKind = const {},
  });

  /// `{level}` · `{levels}` থাকলে ডেটার সংখ্যা বসে
  final String labelBn;
  final String labelEn;
  final String labelBnWithoutLevel;
  final String labelEnWithoutLevel;
  final String semanticsBn;
  final String semanticsEn;
  final String semanticsBnWithoutLevel;
  final String semanticsEnWithoutLevel;

  /// তালিকার সারির ছোট লেখা ([AppStatusChip.compact]) — যেমন "2-এর 1", "পোস্ট হয়েছে"
  final String labelBnShort;
  final String labelEnShort;
  final String labelBnShortWithoutLevel;
  final String labelEnShortWithoutLevel;
  final String? semanticsHintBn;
  final String? semanticsHintEn;

  /// Material Symbols-এর নাম (paper_state_icons.dart এটা থেকে IconData দেয়)
  final String icon;
  final PaperRole role;
  final bool dashed;
  final bool tappable;
  final String? reasonTitleBn;
  final String? reasonTitleEn;
  final String? reasonMissingBn;
  final String? reasonMissingEn;
  final String? reasonCloseBn;
  final String? reasonCloseEn;

  /// কেবল অ্যাপের অবস্থা — ওয়েবে নেই
  final bool appOnly;

  /// ধরনের নিজের কথা — JSON-এর চাবি হুবহু (`label_bn_stock`, `label_en_stock`, `semantics_bn_claim` …)
  final Map<String, String> byKind;

  String _of(String key, String plain, PaperKind kind) =>
      kind == PaperKind.money ? plain : (byKind['${key}_${kind.name}'] ?? plain);

  PaperRoleSpec get colours => paperRoleSpecs[role]!;

  /// পর্দার লেখা — ধাপ দুটোই জানা থাকলে "2-এর 1" অংশ ("step 1 of 2"), নইলে ছাড়া। ⛔ অঙ্ক ইংরেজিতে, টাকা আর তারিখের মতো
  /// (fe, ২০ অক্টোবর ২০২৬); কোনো চিপের লেখায় বা TalkBack-এর কথায় বাংলা অঙ্ক নয়
  String label({int? level, int? levels, PaperKind kind = PaperKind.money, bool english = false}) => english
      ? _fill(_of('label_en', labelEn, kind), labelEnWithoutLevel, level, levels)
      : _fill(_of('label_bn', labelBn, kind), labelBnWithoutLevel, level, levels);

  /// তালিকার সারির ছোট লেখা
  String shortLabel({int? level, int? levels, PaperKind kind = PaperKind.money, bool english = false}) => english
      ? _fill(_of('label_en_short', labelEnShort, kind), labelEnShortWithoutLevel, level, levels)
      : _fill(_of('label_bn_short', labelBnShort, kind), labelBnShortWithoutLevel, level, levels);

  /// TalkBack যা বলে — দুই মাপের চিপেই পুরো বাক্য
  String semantics({int? level, int? levels, PaperKind kind = PaperKind.money, bool english = false}) => english
      ? _fill(_of('semantics_en', semanticsEn, kind), semanticsEnWithoutLevel, level, levels)
      : _fill(_of('semantics_bn', semanticsBn, kind), semanticsBnWithoutLevel, level, levels);

  /// TalkBack-এর ইঙ্গিত (চাপা যায় এমন চিপে)
  String? semanticsHint({bool english = false}) => english ? semanticsHintEn : semanticsHintBn;

  /// "পোস্ট হয়নি"-এর নিচের পাতা: শিরোনাম, কারণ না জানলে লেখা, বন্ধের বোতাম
  String? reasonTitle({bool english = false}) => english ? reasonTitleEn : reasonTitleBn;
  String? reasonMissing({bool english = false}) => english ? reasonMissingEn : reasonMissingBn;
  String? reasonClose({bool english = false}) => english ? reasonCloseEn : reasonCloseBn;

  String _fill(String withLevel, String without, int? level, int? levels) {
    if (!withLevel.contains('{level}')) return withLevel;
    if (level == null || levels == null || level < 1 || levels < level) return without;
    return withLevel.replaceAll('{levels}', '$levels').replaceAll('{level}', '$level');
  }
}

const paperStateSpecs = <PaperState, PaperStateSpec>{
  PaperState.draft: PaperStateSpec(
    labelBn: 'খসড়া', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Draft',
    labelBnWithoutLevel: 'খসড়া', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Draft',
    semanticsBn: 'অবস্থা: খসড়া', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: draft',
    semanticsBnWithoutLevel: 'অবস্থা: খসড়া', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: draft',
    labelBnShort: 'খসড়া', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Draft',
    labelBnShortWithoutLevel: 'খসড়া', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Draft',
    icon: 'edit_note',
    role: PaperRole.neutral,
  ),
  PaperState.willWait: PaperStateSpec(
    labelBn: 'অনুমোদনের অপেক্ষায় হবে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Will await approval',
    labelBnWithoutLevel: 'অনুমোদনের অপেক্ষায় হবে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Will await approval',
    semanticsBn: 'অবস্থা: খসড়া, পাঠালে অনুমোদনের অপেক্ষায় যাবে', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: draft, goes to awaiting approval when sent',
    semanticsBnWithoutLevel: 'অবস্থা: খসড়া, পাঠালে অনুমোদনের অপেক্ষায় যাবে', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: draft, goes to awaiting approval when sent',
    labelBnShort: 'অনুমোদনের অপেক্ষায় হবে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Will await approval',
    labelBnShortWithoutLevel: 'অনুমোদনের অপেক্ষায় হবে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Will await approval',
    icon: 'hourglass_top',
    role: PaperRole.critical,
    dashed: true,
  ),
  PaperState.waiting: PaperStateSpec(
    labelBn: 'অনুমোদনের অপেক্ষায় · {levels}-এর {level}', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Awaiting approval · step {level} of {levels}',
    labelBnWithoutLevel: 'অনুমোদনের অপেক্ষায়', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Awaiting approval',
    semanticsBn: 'অবস্থা: অনুমোদনের অপেক্ষায়, {levels}-এর {level}', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: awaiting approval, step {level} of {levels}',
    semanticsBnWithoutLevel: 'অবস্থা: অনুমোদনের অপেক্ষায়', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: awaiting approval',
    labelBnShort: '{levels}-এর {level}', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Step {level} of {levels}',
    labelBnShortWithoutLevel: 'অনুমোদনের অপেক্ষায়', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Awaiting approval',
    icon: 'hourglass_top',
    role: PaperRole.critical,
  ),
  PaperState.sentBack: PaperStateSpec(
    labelBn: 'সংশোধনে ফেরত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Sent back for correction',
    labelBnWithoutLevel: 'সংশোধনে ফেরত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Sent back for correction',
    semanticsBn: 'অবস্থা: সংশোধনে ফেরত, ঠিক করে আবার পাঠান', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: sent back for correction, correct it and send it again',
    semanticsBnWithoutLevel: 'অবস্থা: সংশোধনে ফেরত, ঠিক করে আবার পাঠান', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: sent back for correction, correct it and send it again',
    labelBnShort: 'সংশোধনে ফেরত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Sent back for correction',
    labelBnShortWithoutLevel: 'সংশোধনে ফেরত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Sent back for correction',
    icon: 'undo',
    role: PaperRole.critical,
  ),
  PaperState.posting: PaperStateSpec(
    labelBn: 'অনুমোদিত · খাতায় উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Approved · posting to the books',
    labelBnWithoutLevel: 'অনুমোদিত · খাতায় উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Approved · posting to the books',
    semanticsBn: 'অবস্থা: অনুমোদিত, খাতায় উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: fully approved, posting to the books',
    semanticsBnWithoutLevel: 'অবস্থা: অনুমোদিত, খাতায় উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: fully approved, posting to the books',
    labelBnShort: 'খাতায় উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Posting to the books',
    labelBnShortWithoutLevel: 'খাতায় উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Posting to the books',
    icon: 'draw',
    role: PaperRole.information,
    byKind: {
      'label_bn_stock': 'অনুমোদিত · মজুদে উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
      'label_en_stock': 'Approved · posting to stock',
      'semantics_bn_stock': 'অবস্থা: অনুমোদিত, মজুদে উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
      'semantics_en_stock': 'Status: fully approved, posting to stock',
      'label_bn_short_stock': 'মজুদে উঠছে', // l10n-ok: mirror of paper_states.json, English beside it
      'label_en_short_stock': 'Posting to stock',
    },
  ),
  PaperState.posted: PaperStateSpec(
    labelBn: 'পোস্ট হয়েছে · খাতায় উঠেছে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Posted · in the books',
    labelBnWithoutLevel: 'পোস্ট হয়েছে · খাতায় উঠেছে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Posted · in the books',
    semanticsBn: 'অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: posted, in the books',
    semanticsBnWithoutLevel: 'অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: posted, in the books',
    labelBnShort: 'পোস্ট হয়েছে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Posted',
    labelBnShortWithoutLevel: 'পোস্ট হয়েছে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Posted',
    icon: 'check_circle',
    role: PaperRole.positive,
    byKind: {
      // খরচের দাবি পোস্ট মানে টাকা দেওয়া — ক্যাশিয়ারের ভাউচার পোস্ট হয়েছে
      'label_bn_stock': 'পোস্ট হয়েছে · মজুদে উঠেছে', // l10n-ok: mirror of paper_states.json, English beside it
      'label_en_stock': 'Posted · in stock',
      'label_bn_claim': 'পরিশোধিত', // l10n-ok: mirror of paper_states.json, English beside it
      'label_en_claim': 'Paid',
      'semantics_bn_stock': 'অবস্থা: পোস্ট হয়েছে, মজুদে উঠেছে', // l10n-ok: mirror of paper_states.json, English beside it
      'semantics_en_stock': 'Status: posted, in stock',
      'semantics_bn_claim': 'অবস্থা: পরিশোধিত', // l10n-ok: mirror of paper_states.json, English beside it
      'semantics_en_claim': 'Status: paid',
      'label_bn_short_claim': 'পরিশোধিত', // l10n-ok: mirror of paper_states.json, English beside it
      'label_en_short_claim': 'Paid',
    },
  ),
  PaperState.cancelled: PaperStateSpec(
    labelBn: 'বাতিল', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Cancelled',
    labelBnWithoutLevel: 'বাতিল', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Cancelled',
    semanticsBn: 'অবস্থা: বাতিল', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: cancelled',
    semanticsBnWithoutLevel: 'অবস্থা: বাতিল', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: cancelled',
    labelBnShort: 'বাতিল', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Cancelled',
    labelBnShortWithoutLevel: 'বাতিল', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Cancelled',
    icon: 'do_not_disturb_on',
    role: PaperRole.negative,
  ),
  PaperState.notPosted: PaperStateSpec(
    labelBn: 'পোস্ট হয়নি: কারণ দেখুন', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Not posted: see why',
    labelBnWithoutLevel: 'পোস্ট হয়নি: কারণ দেখুন', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Not posted: see why',
    semanticsBn: 'অবস্থা: পোস্ট হয়নি', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: not posted',
    semanticsBnWithoutLevel: 'অবস্থা: পোস্ট হয়নি', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: not posted',
    labelBnShort: 'পোস্ট হয়নি', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Not posted',
    labelBnShortWithoutLevel: 'পোস্ট হয়নি', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Not posted',
    icon: 'warning',
    role: PaperRole.negative,
    dashed: true,
    tappable: true,
    semanticsHintBn: 'কারণ দেখতে চাপুন', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsHintEn: 'Tap to see why',
    reasonTitleBn: 'কেন পোস্ট হয়নি', // l10n-ok: mirror of paper_states.json, English beside it
    reasonTitleEn: 'Why it was not posted',
    reasonMissingBn: 'কারণ জানা যায়নি — হিসাব বিভাগে জিজ্ঞেস করুন।', // l10n-ok: mirror of paper_states.json, English beside it
    reasonMissingEn: 'The reason is not known — ask the accounts department.',
    reasonCloseBn: 'বন্ধ করুন', // l10n-ok: mirror of paper_states.json, English beside it
    reasonCloseEn: 'Close',
  ),
  PaperState.unsent: PaperStateSpec(
    labelBn: 'পাঠানো বাকি · নেট এলে নিজেই যাবে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Not sent yet · goes by itself when online',
    labelBnWithoutLevel: 'পাঠানো বাকি · নেট এলে নিজেই যাবে', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Not sent yet · goes by itself when online',
    semanticsBn: 'অবস্থা: পাঠানো বাকি, নেট এলে নিজেই যাবে', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: not sent yet, it goes by itself when the phone is online',
    semanticsBnWithoutLevel: 'অবস্থা: পাঠানো বাকি, নেট এলে নিজেই যাবে', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: not sent yet, it goes by itself when the phone is online',
    labelBnShort: 'পাঠানো বাকি', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Not sent yet',
    labelBnShortWithoutLevel: 'পাঠানো বাকি', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Not sent yet',
    icon: 'cloud_upload',
    role: PaperRole.information,
    appOnly: true,
  ),
  PaperState.rejected: PaperStateSpec(
    labelBn: 'প্রত্যাখ্যাত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Rejected',
    labelBnWithoutLevel: 'প্রত্যাখ্যাত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Rejected',
    semanticsBn: 'অবস্থা: প্রত্যাখ্যাত', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: rejected',
    semanticsBnWithoutLevel: 'অবস্থা: প্রত্যাখ্যাত', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: rejected',
    labelBnShort: 'প্রত্যাখ্যাত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Rejected',
    labelBnShortWithoutLevel: 'প্রত্যাখ্যাত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Rejected',
    icon: 'cancel',
    role: PaperRole.negative,
  ),
  PaperState.approved: PaperStateSpec(
    labelBn: 'অনুমোদিত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEn: 'Approved',
    labelBnWithoutLevel: 'অনুমোদিত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnWithoutLevel: 'Approved',
    semanticsBn: 'অবস্থা: অনুমোদিত', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEn: 'Status: approved',
    semanticsBnWithoutLevel: 'অবস্থা: অনুমোদিত', // l10n-ok: mirror of paper_states.json, English beside it
    semanticsEnWithoutLevel: 'Status: approved',
    labelBnShort: 'অনুমোদিত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShort: 'Approved',
    labelBnShortWithoutLevel: 'অনুমোদিত', // l10n-ok: mirror of paper_states.json, English beside it
    labelEnShortWithoutLevel: 'Approved',
    icon: 'verified',
    role: PaperRole.information,
    byKind: {
      'label_bn_claim': 'অনুমোদিত · টাকার অপেক্ষায়', // l10n-ok: mirror of paper_states.json, English beside it
      'label_en_claim': 'Approved · awaiting payment',
      'semantics_bn_claim': 'অবস্থা: অনুমোদিত, টাকার অপেক্ষায়', // l10n-ok: mirror of paper_states.json, English beside it
      'semantics_en_claim': 'Status: approved, awaiting payment',
      'label_bn_short_claim': 'অনুমোদিত · টাকার অপেক্ষায়', // l10n-ok: mirror of paper_states.json, English beside it
      'label_en_short_claim': 'Approved · awaiting payment',
    },
  ),
};
