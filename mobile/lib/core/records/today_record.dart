import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../locale/app_locale.dart';
import '../sync_engine/reference_cache.dart';
import 'money.dart';
import 'working_capital_record.dart';

/// How the day is going — docs/Contract §৮.
///
/// <p>Shape agreed in writing before either half was built, the ordering this
/// app keeps since the six payload bugs of 12 September.
class TodayRecord {
  const TodayRecord(this.payload);

  final Map<String, dynamic> payload;

  /// The business day **the server** says it is. Not the phone's: a phone's
  /// clock can be changed, and a business day need not end at midnight.
  String? get date => _text('date');

  /// ⚠️ Shown on screen, always. Somebody can belong to more than one company
  /// and switches between them from the home header (0.4.2) — figures from
  /// the wrong company, unlabelled, are figures somebody acts on.
  String? get company => _text('company');

  String? get branch => _text('branch');

  /// When the server produced these numbers. A stale figure looks exactly
  /// like a fresh one, so the screen says which it is.
  DateTime? get asOf {
    final raw = _text('asOf');
    return raw == null ? null : DateTime.tryParse(raw)?.toLocal();
  }

  TodayFigure? get sales => _tile('sales');
  TodayFigure? get collections => _tile('collections');
  TodayFigure? get cashInHand => _tile('cashInHand');
  TodayFigure? get dues => _tile('dues');

  /// ⭐ The owner's home of 6 Oct 2026 (drawn on a phone screenshot): today's
  /// money in, everything we must pay, and the web home's top-right box —
  /// "হাতে ও ব্যাংকে মোট" with cash · MFS · bank · on the road. Each comes
  /// under its own key and is absent without it (rule ক); an older server
  /// sends none of them and the screen draws the old cards instead.
  TodayFigure? get inflow => _tile('inflow');

  /// ⭐ Today's money out (owner's home, 20 Oct 2026). An older server sends no key and the home draws no cell for it —
  /// not a dash: the figure is new, not withheld.
  TodayFigure? get outflow => _tile('outflow');

  /// ⭐ মজুদের মূল্য, খরচ-দামে (IAS 2) — ওয়েবের কার্ডের একই অঙ্ক; কেবল মজুদের মূল্য দেখার চাবি থাকলে আসে (SR-এর কখনো নয়)।
  /// ঘর না এলে null — শূন্য নয় (cb/fe, ২১ অক্টোবর ২০২৬)
  TodayFigure? get stockValue => _tile('stockValue');

  TodayFigure? get payable => _tile('payable');

  /// What of [payable] is hand loans we took — shown under it when not zero.
  double? get payableHandLoans {
    final block = payload['payable'];
    return block is Map ? Money.value(block['handLoans']) : null;
  }

  /// ⭐ চলতি মূলধন — টাকার কার্ডের নিচের কার্ড (মালিক, ৯ নভেম্বর ২০২৬); পুরনো সার্ভার বা অর্থের চাবি না থাকলে null
  WorkingCapitalRecord? get workingCapital => WorkingCapitalRecord.fromJson(payload['workingCapital']);

  MoneyBox? get money {
    final block = payload['money'];
    if (block is! Map) return null;
    return MoneyBox(
      amount: Money.value(block['amount']),
      cash: Money.value(block['cash']),
      mfs: Money.value(block['mfs']),
      bank: Money.value(block['bank']),
      transit: Money.value(block['transit']),
      mfsAccounts: _mfsAccounts(block['mfsAccounts']),
    );
  }

  /// `money.mfsAccounts: [{name, balance}]` — each MFS account behind the MFS figure; null from an older server.
  static List<(String name, double? balance)>? _mfsAccounts(Object? list) {
    if (list is! List) return null;
    return [
      for (final row in list)
        if (row is Map) ((row['name'] ?? '').toString().trim(), Money.value(row['balance'])),
    ];
  }

  /// ⭐ The principal commission report, each principal's current cycle —
  /// what came in, the commission, what was paid and the balance (server
  /// `PrincipalCommissionReport`). Null without `supplier.report`.
  List<PrincipalLine>? get principals {
    final list = payload['principals'];
    if (list is! List) return null;
    return [
      for (final row in list)
        if (row is Map)
          PrincipalLine(
            name: (row['name'] ?? '').toString(),
            period: (row['period'] ?? '').toString(),
            periodSoFar: row['periodSoFar']?.toString(),
            basisRate: (row['basisRate'] ?? '').toString(),
            inflow: Money.value(row['inflow']),
            commission: Money.value(row['commission']),
            commissionLabel: row['commissionLabel']?.toString(),
            paid: Money.value(row['paid']),
            balance: Money.value(row['balance']),
          ),
    ];
  }

  /// ⭐ `principalsTotal` — the server's bcmath sum of every principal's row, the web's own total row (a4, 20 Oct 2026).
  /// Null from an older server: the home then sums the rows itself.
  PrincipalLine? get principalsTotal {
    final row = payload['principalsTotal'];
    if (row is! Map) return null;
    return PrincipalLine(
      name: AppLocale.l10n.todayTotal,
      period: '',
      basisRate: '',
      inflow: Money.value(row['inflow']),
      commission: Money.value(row['commission']),
      commissionLabel: row['commissionLabel']?.toString(),
      paid: Money.value(row['paid']),
      balance: Money.value(row['balance']),
    );
  }

  int? get pendingApprovals {
    final block = payload['approvals'];
    if (block is! Map) return null;
    return (block['pending'] as num?)?.toInt();
  }

  /// <p><b>Absent is not zero.</b> A person without `accounts.till.view` gets
  /// no `cashInHand` key at all — not null, not "0" — exactly as
  /// `purchasePrice` is omitted from the product payload. Zero is a number
  /// somebody acts on: "no cash today" and "you may not see the cash" are
  /// different sentences, and drawing the first for the second is a lie the
  /// screen tells confidently.
  TodayFigure? _tile(String key) {
    final block = payload[key];
    if (block is! Map) return null;
    return TodayFigure(
      amount: Money.value(block['amount']),
      count: (block['count'] as num?)?.toInt(),
      shops: (block['shops'] as num?)?.toInt(),
      label: (block['label'] as String?)?.trim().isEmpty ?? true ? null : (block['label'] as String).trim(),
      billed: block['billed'] == null ? null : Money.value(block['billed']),
      returned: block['returned'] == null ? null : Money.value(block['returned']),
      vat: block['vat'] == null ? null : Money.value(block['vat']),
    );
  }

  String? _text(String key) {
    final value = payload[key];
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }
}

/// One number on the page, and whatever comes with it — a count of documents
/// for sales and collections, a count of shops for dues, nothing for cash.
class TodayFigure {
  const TodayFigure({this.amount, this.count, this.shops, this.label, this.billed, this.returned, this.vat});

  final double? amount;
  final int? count;
  final int? shops;

  /// সার্ভারের নিজের নাম এই অঙ্কের — "নিট বিক্রি" (IFRS 15: বিল − ফেরত, ফেরতের তারিখ ধরে; fe, ২০ অক্টোবর ২০২৬)। ⓘ পুরনো
  /// সার্ভার পাঠায় না — তখন অঙ্কটা মোট বিল, ফেরত বাদ নয়, তাই নাম "আজ বিল হয়েছে" ([salesHeading])
  final String? label;

  /// নিটের দুই ভাগ — আজ বিল হয়েছে আর আজ ফেরত এসেছে (নতুন সার্ভার)
  final double? billed;
  final double? returned;

  /// ⭐ বিলের VAT — নিট বিক্রি VAT বাদে (IFRS 15.47: তৃতীয় পক্ষের হয়ে তোলা টাকা আয় নয়; fe, ২০ অক্টোবর ২০২৬); পুরনো সার্ভারে নেই
  final double? vat;

  /// বিক্রির ঘরের নাম — সার্ভার যা বলে; না বললে "আজ বিল হয়েছে", কারণ তখন অঙ্কটা ফেরত বাদ দেওয়া নয় (কখনো হাতে লেখা "আজকের বিক্রি" নয়)
  String get salesHeading => label ?? AppLocale.l10n.todayBilledToday;

  /// ⭐ বিক্রির নিচের লাইন — নিট হলে তার দুই ভাগ "বিল ৳… · ফেরত ৳…" (VAT এলে " · VAT ৳…"); পুরনো সার্ভারে (ভাগ নেই) কেবল
  /// বিলের গোনা "N টি বিক্রয়"। আজকের পাতা আর মালিকের হোমের শাখার কার্ড — দুই জায়গায় এই এক লেখা।
  String? get salesDetail => billed != null && returned != null
      // ⓘ বিল আর ফেরত VAT বাদে — VAT আলাদা দেখায়, যাতে বিলের কাগজের মোটের সাথে মেলানো যায়
      ? '${AppLocale.l10n.todaySalesBilledReturned(Money.taka(billed), Money.taka(returned))}'
          '${vat == null ? '' : ' · VAT ${Money.taka(vat)}'}'
      : count == null
          ? null
          : AppLocale.l10n.todaySalesCount(count!);
}

/// "হাতে ও ব্যাংকে মোট" — the web home's top-right box, the same figures: the
/// total is cash + MFS + bank, and what is on the road is its own part.
class MoneyBox {
  const MoneyBox({this.amount, this.cash, this.mfs, this.bank, this.transit, this.mfsAccounts});

  final double? amount;
  final double? cash;
  final double? mfs;
  final double? bank;
  final double? transit;

  /// Each MFS account behind [mfs] (server `money.mfsAccounts`); null from an older server.
  final List<(String name, double? balance)>? mfsAccounts;
}

/// One principal's cycle in the commission report.
class PrincipalLine {
  const PrincipalLine({
    required this.name,
    required this.period,
    this.periodSoFar,
    required this.basisRate,
    this.inflow,
    this.commission,
    this.commissionLabel,
    this.paid,
    this.balance,
  });

  final String name;
  final String period;

  /// "26/09/2026 – আজ পর্যন্ত" while today is inside the cycle (server
  /// `PrincipalCommission::soFar()`); an older server sends none and [period]
  /// is shown instead.
  final String? periodSoFar;

  String get periodLabel => (periodSoFar != null && periodSoFar!.trim().isNotEmpty) ? periodSoFar! : period;

  final String basisRate;
  final double? inflow;
  final double? commission;

  /// The web box's own words for the commission (server
  /// `SupplierDashboard::earned()`): on the "actual" basis a sale below the
  /// purchase cost makes it negative, and the web says "লোকসান ৳… — কেনা
  /// দামের নিচে বিক্রি" rather than a bare minus. An older server sends none.
  final String? commissionLabel;

  bool get isLoss => (commission ?? 0) < 0;

  /// What the phone shows: the server's words, else the same words made here.
  String get commissionText {
    final said = commissionLabel?.trim() ?? '';
    if (said.isNotEmpty) return said;
    final value = commission ?? 0;
    return value < 0 ? AppLocale.l10n.commissionLoss(Money.taka(-value)) : Money.taka(value);
  }

  final double? paid;

  /// The principal's share less what was paid: positive means we still have
  /// to pay them, negative means the company owes us — the report's own words.
  final double? balance;

  String get balanceLabel {
    final value = balance ?? 0;
    if (value == 0) return AppLocale.l10n.commissionSideSettled;
    return value > 0 ? AppLocale.l10n.todayBalanceToPay(Money.taka(value)) : AppLocale.l10n.todayBalanceOwedToUs(Money.taka(-value));
  }
}

/// `GET /dashboard/today`, with the last answer kept on the phone.
///
/// <p>⚠️ **The endpoint does not exist yet** — requested, with the shape
/// written down first rather than guessed.
///
/// <p>Not part of sync: no queue, no watermark, and nothing here is ever
/// pushed. But the last answer is cached, because on a phone with no signal
/// "this is how the morning looked" beats an empty screen — provided it says
/// *morning*, which is what `asOf` is for.
class TodayApi {
  const TodayApi._();

  static const String _cacheType = 'TodaySummary';
  static const String _cacheId = 'latest';

  static Future<TodayRecord> fetch() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/dashboard/today',
      options: Options(extra: const {'abosAllowStale': true}),
    );
    final body = response.data ?? const <String, dynamic>{};

    await ReferenceCache.instance.put(
      entityType: _cacheType,
      entityId: _cacheId,
      payload: body,
      updatedAt: DateTime.now(),
    );

    return TodayRecord(body);
  }

  /// What the phone last heard, or null if it has never heard anything.
  static TodayRecord? lastKnown() {
    final payload = ReferenceCache.instance.get(_cacheType, _cacheId);
    return payload == null ? null : TodayRecord(payload);
  }
}
