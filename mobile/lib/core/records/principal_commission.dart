import 'money.dart';

/// ⭐ প্রিন্সিপালের কমিশন — সার্ভারের এক হিসাব, অ্যাপের দুই নতুন জায়গা: পর্দা (`/home/principal-commission`) আর হোম-স্ক্রিন
/// উইজেট (মালিকের আদেশ "app e dekhate hobe", fe-র মাধ্যমে; a4-এর নকশার নিয়ম; ২০ অক্টোবর ২০২৬)।
///
/// <p>⭐ চুক্তি, সংশোধন ১ (a4, ২০ অক্টোবর ২০২৬): সারির ঘরের নাম Today-র পুরনো `principals`-এর হুবহু — `name, period,
/// periodFrom, periodTo, periodSoFar, basisRate, inflow, commission, commissionLabel, share, paid, balance` (+ `supplierId`,
/// `branch`); মোটের ঘরও একই নামে। টাকা চার দশমিকের লেখা ("12345.5000"), bcmath-এ কষা। মালিকের হোমের কার্ড a4-এর
/// (owner_home_cards, ০.৪.৪২) — এখানে নয়।
///
/// <p>ⓘ কেন [PrincipalLine] (today_record.dart) নয়: ওটা অঙ্ক `double`-এ পড়ে আর dio/ApiClient-এর ফাইলে থাকে; উইজেটের নথি
/// খাঁটি Dart আর টাকা সার্ভারের লেখাই রাখতে হয় (পয়সা পর্যন্ত, যোগ ছাড়া)। নাম, শব্দ আর নিয়ম ওটার হুবহু — "দিতে হবে: …" /
/// "কোম্পানির কাছে পাব: …", কমিশনের `commissionLabel` সার্ভার যেমন পাঠায়।
///
/// <p>⛔ অ্যাপ কখনো যোগ করে না: মোট সবসময় সার্ভারের `total` — সারির যোগফল নয়।

/// জের কোন দিকে — জেরের চিহ্ন থেকে, ওয়েবের কার্ডের `owed()`-এর নিয়মে (ধনাত্মক = আমরা দেব)
enum BalanceSide {
  /// আমরা প্রিন্সিপালকে দেব — দেনা, "দিতে হবে"
  pay,

  /// বেশি পাঠানো হয়ে গেছে — কোম্পানির কাছে পাওনা
  receive,

  /// জের নেই
  settled;

  /// ⓘ ওয়েবের মতো দুই দশমিকে গোল করে তুলনা — "0.0040" জের নেই
  static BalanceSide of(String? balance) {
    final v = Money.value(balance);
    if (v == null || v.abs() < 0.005) return BalanceSide.settled;
    return v > 0 ? BalanceSide.pay : BalanceSide.receive;
  }

  /// এক শব্দ — "দেনা/পাওনা"
  String get word => switch (this) {
        BalanceSide.pay => 'দেনা',
        BalanceSide.receive => 'পাওনা',
        BalanceSide.settled => 'জের নেই',
      };

  /// পুরো কথা — ওয়েবের কার্ডের "দিতে হবে" / "কোম্পানির কাছে পাব"
  String get sentence => switch (this) {
        BalanceSide.pay => 'দেনা · দিতে হবে',
        BalanceSide.receive => 'পাওনা · কোম্পানির কাছে পাব',
        BalanceSide.settled => 'জের নেই',
      };
}

/// "-6130.2500" → "6130.2500" — ⛔ খালি বিয়োগ চিহ্ন কখনো পর্দায় নয়; দিক বলে [BalanceSide.word]
String? unsigned(String? decimal) {
  if (decimal == null) return null;
  final t = decimal.trim();
  return t.startsWith('-') ? t.substring(1) : t;
}

String? _decimal(Object? value) {
  final t = Money.raw(value);
  if (t == null || double.tryParse(t) == null) return null;
  return t;
}

String? _text(Object? value) => value is Map || value is List ? null : Money.raw(value);

/// এক প্রিন্সিপালের চলতি চক্র
class PrincipalCommissionRow {
  const PrincipalCommissionRow({
    required this.supplierId,
    required this.name,
    this.branch,
    this.period,
    this.periodFrom,
    this.periodTo,
    this.periodSoFar,
    this.basisRate,
    this.inflow,
    this.commission,
    this.commissionLabel,
    this.share,
    this.paid,
    this.balance,
  });

  factory PrincipalCommissionRow.fromJson(Map<dynamic, dynamic> json) => PrincipalCommissionRow(
        supplierId: '${json['supplierId'] ?? json['name'] ?? ''}',
        name: _text(json['name']) ?? '—',
        branch: _text(json['branch']),
        period: _text(json['period']),
        periodFrom: _text(json['periodFrom']),
        periodTo: _text(json['periodTo']),
        periodSoFar: _text(json['periodSoFar']),
        basisRate: _text(json['basisRate']),
        inflow: _decimal(json['inflow']),
        commission: _decimal(json['commission']),
        commissionLabel: _text(json['commissionLabel']),
        share: _decimal(json['share']),
        paid: _decimal(json['paid']),
        balance: _decimal(json['balance']),
      );

  final String supplierId;

  /// সার্ভারের লেখা — "নাম · পয়েন্ট · শাখা" বা ওয়েবের কার্ডের সংক্ষিপ্ত নাম
  final String name;
  final String? branch;

  /// সার্ভারের চক্রের লেখা — "26/09/2026 – 25/10/2026"
  final String? period;
  final String? periodFrom;
  final String? periodTo;

  /// "26/09/2026 – আজ পর্যন্ত" — চক্র চলতে থাকলে
  final String? periodSoFar;

  /// কমিশনের ভিত্তি আর হার — সার্ভারের লেখা ("2.5%")
  final String? basisRate;

  /// চক্রে এসেছে (ইনফ্লো)
  final String? inflow;
  final String? commission;

  /// সার্ভারের কমিশনের কথা (লোকসানে "লোকসান ৳… — কেনা দামের নিচে বিক্রি")
  final String? commissionLabel;

  /// প্রিন্সিপালের অংশ (ইনফ্লো − কমিশন)
  final String? share;

  /// প্রিন্সিপালকে পাঠানো
  final String? paid;

  /// অংশ − পাঠানো, চিহ্নসহ: ধনাত্মক = দিতে হবে, ঋণাত্মক = কোম্পানির কাছে পাব
  final String? balance;

  BalanceSide get side => BalanceSide.of(balance);

  /// পর্দার নাম — সার্ভারের সংক্ষিপ্ত নাম আর শাখা: "Star Line · ময়মনসিংহ" (মালিকের নিয়ম: নামের পাশে শাখা)
  String get label => branch == null || name.contains(branch!) ? name : '$name · $branch';

  /// চক্র — চলতি হলে "… – আজ পর্যন্ত", নইলে তারিখ দুটো থেকে, তাও না থাকলে সার্ভারের `period`
  String? get cycle => periodSoFar ?? cycleText(periodFrom, periodTo) ?? period;

  String get commissionShown => commissionText(commission, label: commissionLabel);
}

/// সব প্রিন্সিপালের মোট — সার্ভারের bcmath যোগফল, সারির একই ঘরের নামে
class PrincipalCommissionTotal {
  const PrincipalCommissionTotal({this.inflow, this.commission, this.commissionLabel, this.share, this.paid, this.balance});

  factory PrincipalCommissionTotal.fromJson(Object? json) {
    final m = json is Map ? json : const {};
    return PrincipalCommissionTotal(
      inflow: _decimal(m['inflow']),
      commission: _decimal(m['commission']),
      commissionLabel: _text(m['commissionLabel']),
      share: _decimal(m['share']),
      paid: _decimal(m['paid']),
      balance: _decimal(m['balance']),
    );
  }

  final String? inflow;
  final String? commission;
  final String? commissionLabel;
  final String? share;
  final String? paid;
  final String? balance;

  BalanceSide get side => BalanceSide.of(balance);

  String get commissionShown => commissionText(commission, label: commissionLabel);
}

/// `GET /supplier/principal-commission` — পর্দার এক উত্তর
class PrincipalCommissionPage {
  const PrincipalCommissionPage({required this.month, required this.rows, required this.total, this.webUrl});

  factory PrincipalCommissionPage.fromJson(Map<dynamic, dynamic> json) => PrincipalCommissionPage(
        month: _text(json['month']),
        rows: rowsOf(json['rows']),
        total: PrincipalCommissionTotal.fromJson(json['total']),
        webUrl: _text(json['web_url']),
      );

  /// সার্ভার কোন মাসের চক্র দিল — "2026-10"
  final String? month;
  final List<PrincipalCommissionRow> rows;
  final PrincipalCommissionTotal total;
  final String? webUrl;
}

List<PrincipalCommissionRow> rowsOf(Object? list) => [
      if (list is List)
        for (final r in list)
          if (r is Map) PrincipalCommissionRow.fromJson(r),
    ];

/// "2026-09-26" → "26/09/2026"; দুটো থাকলে "26/09/2026 – 25/10/2026"
String? cycleText(String? from, String? to) {
  String? d(String? iso) {
    final m = RegExp(r'^(\d{4})-(\d{2})-(\d{2})').firstMatch(iso ?? '');
    return m == null ? null : '${m[3]}/${m[2]}/${m[1]}';
  }

  final a = d(from), b = d(to);
  if (a == null && b == null) return null;
  return [a, b].whereType<String>().join(' – ');
}

/// কমিশনের লেখা — সার্ভারের `commissionLabel` থাকলে সেটাই; নইলে ঋণাত্মক হলে ওয়েবের মতো কথায় ("লোকসান ৳…"), খালি বিয়োগ নয়
String commissionText(String? commission, {String? label}) {
  if (label != null && label.trim().isNotEmpty) return label.trim();
  final c = commission?.trim();
  if (c == null) return '—';
  return c.startsWith('-') ? 'লোকসান ${Money.taka(c.substring(1))} — কেনা দামের নিচে বিক্রি' : Money.taka(c);
}

/// "2026-10" → "অক্টোবর 2026" (বছর ইংরেজি অঙ্কে, অ্যাপের নিয়ম)
String monthLabel(String month) {
  const names = [
    'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন',
    'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর',
  ];
  final m = RegExp(r'^(\d{4})-(\d{2})$').firstMatch(month);
  if (m == null) return month;
  final i = int.parse(m[2]!);
  return i < 1 || i > 12 ? month : '${names[i - 1]} ${m[1]}';
}

/// "2026-10" থেকে [delta] মাস সরে — "2026-09"
String shiftMonth(String month, int delta) {
  final m = RegExp(r'^(\d{4})-(\d{2})$').firstMatch(month);
  if (m == null) return month;
  final zero = int.parse(m[1]!) * 12 + int.parse(m[2]!) - 1 + delta;
  return '${zero ~/ 12}-${(zero % 12 + 1).toString().padLeft(2, '0')}';
}
