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

  /// সই শেষ, অনুমোদিত — খরচের দাবিতে "টাকার অপেক্ষায়" ([PaperKind.claim])
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
  /// <li>`awaiting` — ভাউচার, সইয়ের অপেক্ষায় (voucher_api.dart; সার্ভার VoucherApiController::row)
  /// <li>`awaiting_approval` — বিক্রয় বিল/চালান (sales_paper_screens.dart `_status`)
  /// <li>`submitted` — খরচের দাবি/অগ্রিম, সইয়ের অপেক্ষায় (core/hr/claims_api.dart; সার্ভার ExpenseClaim::SUBMITTED)
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
        'awaiting' || 'awaiting_approval' || 'submitted' || 'pending' => PaperState.waiting,
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

/// এক অবস্থার সব — লেখা, TalkBack-এর কথা, আইকনের নাম, ভূমিকা, ধারের ধরন
class PaperStateSpec {
  const PaperStateSpec({
    required this.labelBn,
    required this.labelBnWithoutLevel,
    required this.semanticsBn,
    required this.semanticsBnWithoutLevel,
    required this.labelBnShort,
    required this.labelBnShortWithoutLevel,
    required this.icon,
    required this.role,
    this.dashed = false,
    this.tappable = false,
    this.semanticsHintBn,
    this.reasonTitleBn,
    this.reasonMissingBn,
    this.reasonCloseBn,
    this.appOnly = false,
    this.byKind = const {},
  });

  /// `{level}` · `{levels}` থাকলে ডেটার সংখ্যা বসে
  final String labelBn;
  final String labelBnWithoutLevel;
  final String semanticsBn;
  final String semanticsBnWithoutLevel;

  /// তালিকার সারির ছোট লেখা ([AppStatusChip.compact]) — যেমন "2-এর 1", "পোস্ট হয়েছে"
  final String labelBnShort;
  final String labelBnShortWithoutLevel;
  final String? semanticsHintBn;

  /// Material Symbols-এর নাম (paper_state_icons.dart এটা থেকে IconData দেয়)
  final String icon;
  final PaperRole role;
  final bool dashed;
  final bool tappable;
  final String? reasonTitleBn;
  final String? reasonMissingBn;
  final String? reasonCloseBn;

  /// কেবল অ্যাপের অবস্থা — ওয়েবে নেই
  final bool appOnly;

  /// ধরনের নিজের কথা — JSON-এর চাবি হুবহু (`label_bn_stock`, `semantics_bn_claim` …)
  final Map<String, String> byKind;

  String _of(String key, String plain, PaperKind kind) =>
      kind == PaperKind.money ? plain : (byKind['${key}_${kind.name}'] ?? plain);

  PaperRoleSpec get colours => paperRoleSpecs[role]!;

  bool get _hasLevel => labelBn.contains('{level}');

  /// পর্দার লেখা — ধাপ দুটোই জানা থাকলে "2-এর 1" অংশ, নইলে ছাড়া। ⛔ অঙ্ক ইংরেজিতে, টাকা আর তারিখের মতো
  /// (fe, ২০ অক্টোবর ২০২৬); কেবল কথাগুলো বাংলা — কোনো চিপের লেখায় বা TalkBack-এর কথায় বাংলা অঙ্ক নয়
  String label({int? level, int? levels, PaperKind kind = PaperKind.money}) =>
      _fill(_of('label_bn', labelBn, kind), labelBnWithoutLevel, level, levels);

  /// তালিকার সারির ছোট লেখা
  String shortLabel({int? level, int? levels, PaperKind kind = PaperKind.money}) =>
      _fill(_of('label_bn_short', labelBnShort, kind), labelBnShortWithoutLevel, level, levels);

  /// TalkBack যা বলে — দুই মাপের চিপেই পুরো বাক্য
  String semantics({int? level, int? levels, PaperKind kind = PaperKind.money}) =>
      _fill(_of('semantics_bn', semanticsBn, kind), semanticsBnWithoutLevel, level, levels);

  String _fill(String withLevel, String without, int? level, int? levels) {
    if (!_hasLevel) return withLevel;
    if (level == null || levels == null || level < 1 || levels < level) return without;
    return withLevel.replaceAll('{levels}', '$levels').replaceAll('{level}', '$level');
  }
}

const paperStateSpecs = <PaperState, PaperStateSpec>{
  PaperState.draft: PaperStateSpec(
    labelBn: 'খসড়া',
    labelBnWithoutLevel: 'খসড়া',
    semanticsBn: 'অবস্থা: খসড়া',
    semanticsBnWithoutLevel: 'অবস্থা: খসড়া',
    labelBnShort: 'খসড়া',
    labelBnShortWithoutLevel: 'খসড়া',
    icon: 'edit_note',
    role: PaperRole.neutral,
  ),
  PaperState.waiting: PaperStateSpec(
    labelBn: 'সইয়ের অপেক্ষায় · {levels}-এর {level}',
    labelBnWithoutLevel: 'সইয়ের অপেক্ষায়',
    semanticsBn: 'অবস্থা: সইয়ের অপেক্ষায়, {levels}-এর {level}',
    semanticsBnWithoutLevel: 'অবস্থা: সইয়ের অপেক্ষায়',
    labelBnShort: '{levels}-এর {level}',
    labelBnShortWithoutLevel: 'সইয়ের অপেক্ষায়',
    icon: 'hourglass_top',
    role: PaperRole.critical,
  ),
  PaperState.sentBack: PaperStateSpec(
    labelBn: 'সংশোধনে ফেরত',
    labelBnWithoutLevel: 'সংশোধনে ফেরত',
    semanticsBn: 'অবস্থা: সংশোধনে ফেরত, ঠিক করে আবার পাঠান',
    semanticsBnWithoutLevel: 'অবস্থা: সংশোধনে ফেরত, ঠিক করে আবার পাঠান',
    labelBnShort: 'সংশোধনে ফেরত',
    labelBnShortWithoutLevel: 'সংশোধনে ফেরত',
    icon: 'undo',
    role: PaperRole.critical,
  ),
  PaperState.posting: PaperStateSpec(
    labelBn: 'সই শেষ · খাতায় উঠছে',
    labelBnWithoutLevel: 'সই শেষ · খাতায় উঠছে',
    semanticsBn: 'অবস্থা: সই শেষ, খাতায় উঠছে',
    semanticsBnWithoutLevel: 'অবস্থা: সই শেষ, খাতায় উঠছে',
    labelBnShort: 'খাতায় উঠছে',
    labelBnShortWithoutLevel: 'খাতায় উঠছে',
    icon: 'draw',
    role: PaperRole.information,
    byKind: {
      'label_bn_stock': 'সই শেষ · মজুদে উঠছে',
      'semantics_bn_stock': 'অবস্থা: সই শেষ, মজুদে উঠছে',
      'label_bn_short_stock': 'মজুদে উঠছে',
    },
  ),
  PaperState.posted: PaperStateSpec(
    labelBn: 'পোস্ট হয়েছে · খাতায় উঠেছে',
    labelBnWithoutLevel: 'পোস্ট হয়েছে · খাতায় উঠেছে',
    semanticsBn: 'অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে',
    semanticsBnWithoutLevel: 'অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে',
    labelBnShort: 'পোস্ট হয়েছে',
    labelBnShortWithoutLevel: 'পোস্ট হয়েছে',
    icon: 'check_circle',
    role: PaperRole.positive,
    byKind: {
      'label_bn_stock': 'পোস্ট হয়েছে · মজুদে উঠেছে',
      'semantics_bn_stock': 'অবস্থা: পোস্ট হয়েছে, মজুদে উঠেছে',
      // খরচের দাবি পোস্ট মানে টাকা দেওয়া — ক্যাশিয়ারের ভাউচার পোস্ট হয়েছে
      'label_bn_claim': 'পরিশোধিত',
      'semantics_bn_claim': 'অবস্থা: পরিশোধিত',
      'label_bn_short_claim': 'পরিশোধিত',
    },
  ),
  PaperState.cancelled: PaperStateSpec(
    labelBn: 'বাতিল',
    labelBnWithoutLevel: 'বাতিল',
    semanticsBn: 'অবস্থা: বাতিল',
    semanticsBnWithoutLevel: 'অবস্থা: বাতিল',
    labelBnShort: 'বাতিল',
    labelBnShortWithoutLevel: 'বাতিল',
    icon: 'do_not_disturb_on',
    role: PaperRole.negative,
  ),
  PaperState.notPosted: PaperStateSpec(
    labelBn: 'পোস্ট হয়নি: কারণ দেখুন',
    labelBnWithoutLevel: 'পোস্ট হয়নি: কারণ দেখুন',
    semanticsBn: 'অবস্থা: পোস্ট হয়নি',
    semanticsBnWithoutLevel: 'অবস্থা: পোস্ট হয়নি',
    labelBnShort: 'পোস্ট হয়নি',
    labelBnShortWithoutLevel: 'পোস্ট হয়নি',
    semanticsHintBn: 'কারণ দেখতে চাপুন',
    icon: 'warning',
    role: PaperRole.negative,
    dashed: true,
    tappable: true,
    reasonTitleBn: 'কেন পোস্ট হয়নি',
    reasonMissingBn: 'কারণ জানা যায়নি — হিসাব বিভাগে জিজ্ঞেস করুন।',
    reasonCloseBn: 'বন্ধ করুন',
  ),
  PaperState.unsent: PaperStateSpec(
    labelBn: 'পাঠানো বাকি · নেট এলে নিজেই যাবে',
    labelBnWithoutLevel: 'পাঠানো বাকি · নেট এলে নিজেই যাবে',
    semanticsBn: 'অবস্থা: পাঠানো বাকি, নেট এলে নিজেই যাবে',
    semanticsBnWithoutLevel: 'অবস্থা: পাঠানো বাকি, নেট এলে নিজেই যাবে',
    labelBnShort: 'পাঠানো বাকি',
    labelBnShortWithoutLevel: 'পাঠানো বাকি',
    icon: 'cloud_upload',
    role: PaperRole.information,
    appOnly: true,
  ),
  PaperState.rejected: PaperStateSpec(
    labelBn: 'প্রত্যাখ্যাত',
    labelBnWithoutLevel: 'প্রত্যাখ্যাত',
    semanticsBn: 'অবস্থা: প্রত্যাখ্যাত',
    semanticsBnWithoutLevel: 'অবস্থা: প্রত্যাখ্যাত',
    labelBnShort: 'প্রত্যাখ্যাত',
    labelBnShortWithoutLevel: 'প্রত্যাখ্যাত',
    icon: 'cancel',
    role: PaperRole.negative,
  ),
  PaperState.approved: PaperStateSpec(
    labelBn: 'অনুমোদিত',
    labelBnWithoutLevel: 'অনুমোদিত',
    semanticsBn: 'অবস্থা: অনুমোদিত',
    semanticsBnWithoutLevel: 'অবস্থা: অনুমোদিত',
    labelBnShort: 'অনুমোদিত',
    labelBnShortWithoutLevel: 'অনুমোদিত',
    icon: 'verified',
    role: PaperRole.information,
    byKind: {
      'label_bn_claim': 'অনুমোদিত · টাকার অপেক্ষায়',
      'semantics_bn_claim': 'অবস্থা: অনুমোদিত, টাকার অপেক্ষায়',
      'label_bn_short_claim': 'অনুমোদিত · টাকার অপেক্ষায়',
    },
  ),
};
