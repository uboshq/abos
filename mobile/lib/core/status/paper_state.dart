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
  unsent('unsent');

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
  /// <li>`returned` · `sent_back` — সই না দিয়ে ফেরত (approvals/my_requests_screen.dart `returned`)
  /// <li>`posting` — শেষ সই পড়েছে, খাতায় উঠছে
  /// <li>`posted` — ভাউচার পাকা
  /// <li>`confirmed` · `closed` — বিক্রয় বিল/চালান নিশ্চিত/সম্পন্ন (sales_paper_screens.dart `_status`)
  /// <li>`paid` — খরচের দাবি টাকা দেওয়া, ভাউচার পাকা (claims_api.dart; ExpenseClaim::PAID)
  /// <li>`cancelled` — ভাউচার, বিক্রয় বিল/চালান
  /// <li>`rejected` — খরচের দাবি নামঞ্জুর, শেষ অবস্থা (claims_api.dart; ExpenseClaim::REJECTED)
  /// <li>`not_posted` · `post_failed` — খাতায় তোলা আটকে গেছে
  /// </ul>
  /// ⓘ [PaperState.unsent] সার্ভারের কোনো লেখা নয় — ফোনের সারিতে জমা থাকা কাগজ; পর্দা নিজেই বসায়।
  /// ⓘ খরচের দাবির `approved` (সই শেষ, ক্যাশিয়ারের টাকার অপেক্ষায় — দিনের পর দিন থাকতে পারে) সাতটার কোনোটা নয়: নীল
  /// "খাতায় উঠছে" কয়েক সেকেন্ডের অবস্থা (নকশা ৩)। তাই null — পর্দা সার্ভারের লেখা দেখায়।
  static PaperState? fromServer(String state) => switch (state.trim().toLowerCase()) {
        'draft' => PaperState.draft,
        'awaiting' || 'awaiting_approval' || 'submitted' => PaperState.waiting,
        'returned' || 'sent_back' => PaperState.sentBack,
        'posting' => PaperState.posting,
        'posted' || 'confirmed' || 'closed' || 'paid' => PaperState.posted,
        'cancelled' || 'rejected' => PaperState.cancelled,
        'not_posted' || 'post_failed' => PaperState.notPosted,
        _ => null,
      };
}

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
  const PaperRoleSpec({required this.fiori, required this.light, required this.dark, this.adjustedForContrast = false});

  /// foreground · background · border → Fiori প্যারামিটার
  final Map<String, String> fiori;
  final PaperColours light;
  final PaperColours dark;
  final bool adjustedForContrast;

  PaperColours of(Brightness b) => b == Brightness.dark ? dark : light;
}

const paperRoleSpecs = <PaperRole, PaperRoleSpec>{
  PaperRole.neutral: PaperRoleSpec(
    fiori: {'foreground': 'sapNeutralTextColor', 'background': 'sapNeutralBackground', 'border': 'sapNeutralBorderColor'},
    light: PaperColours(foreground: Color(0xFF131E29), background: Color(0xFFEFF1F2), border: Color(0xFF788FA6)),
    dark: PaperColours(foreground: Color(0xFFF5F6F7), background: Color(0xFF242E38), border: Color(0xFFA9B4BE)),
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
  });

  /// `{level}` · `{levels}` থাকলে ডেটার সংখ্যা বসে
  final String labelBn;
  final String labelBnWithoutLevel;
  final String semanticsBn;
  final String semanticsBnWithoutLevel;

  /// তালিকার সারির ছোট লেখা ([AppStatusChip.compact]) — যেমন "2-এর 1", "পাকা"
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

  PaperRoleSpec get colours => paperRoleSpecs[role]!;

  bool get _hasLevel => labelBn.contains('{level}');

  /// পর্দার লেখা — ধাপ দুটোই জানা থাকলে "2-এর 1" অংশ, নইলে ছাড়া। ⛔ অঙ্ক ইংরেজিতে, টাকা আর তারিখের মতো
  /// (fe, ২০ অক্টোবর ২০২৬); কেবল কথাগুলো বাংলা — কোনো চিপের লেখায় বা TalkBack-এর কথায় বাংলা অঙ্ক নয়
  String label({int? level, int? levels}) => _fill(labelBn, labelBnWithoutLevel, level, levels);

  /// তালিকার সারির ছোট লেখা
  String shortLabel({int? level, int? levels}) => _fill(labelBnShort, labelBnShortWithoutLevel, level, levels);

  /// TalkBack যা বলে — দুই মাপের চিপেই পুরো বাক্য
  String semantics({int? level, int? levels}) => _fill(semanticsBn, semanticsBnWithoutLevel, level, levels);

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
    labelBn: 'ফেরত: ঠিক করুন',
    labelBnWithoutLevel: 'ফেরত: ঠিক করুন',
    semanticsBn: 'অবস্থা: ফেরত পাঠানো, ঠিক করুন',
    semanticsBnWithoutLevel: 'অবস্থা: ফেরত পাঠানো, ঠিক করুন',
    labelBnShort: 'ফেরত',
    labelBnShortWithoutLevel: 'ফেরত',
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
  ),
  PaperState.posted: PaperStateSpec(
    labelBn: 'পাকা · খাতায় উঠেছে',
    labelBnWithoutLevel: 'পাকা · খাতায় উঠেছে',
    semanticsBn: 'অবস্থা: পাকা, খাতায় উঠেছে',
    semanticsBnWithoutLevel: 'অবস্থা: পাকা, খাতায় উঠেছে',
    labelBnShort: 'পাকা',
    labelBnShortWithoutLevel: 'পাকা',
    icon: 'check_circle',
    role: PaperRole.positive,
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
};
