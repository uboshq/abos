import 'package:dio/dio.dart';

import '../../core/api_client/once_key.dart';
import '../api_client/api_client.dart';
import '../api_client/network_errors.dart';
import '../locale/app_locale.dart';
import '../records/money.dart';
import '../sync_engine/sync_engine.dart';

/// ⭐ অফিসের লোকের ভাউচার — `/accounts/vouchers…` আর লেখা সিঙ্কের দরজায়, এখনই (মালিক, ৭ অক্টোবর ২০২৬: "সব ভাউচার দেওয়ার
/// কথা ছিল"; সার্ভার 2bd0f620)।
///
/// <p>নিয়ম সব ওয়েবের — সার্ভার ওয়েবের অনুরোধ আর লেখার পথ দিয়েই যাচাই করে (ধরনের ছাঁচ, বিবরণের সুইচ, বাকিতে পক্ষ, লেখক ≠
/// পাকাকারী, সই, নিজের টিলের নগদ, লেনদেন নম্বর)। ⛔ কেবল নেট থাকলে ("নেট না থাকলে শুধু অর্ডার") — ফোনে বানানো চাবি
/// ([[SyncEngine.pushNow]]), তাই দুবার চাপলেও একটাই বসে।
class VoucherTypes {
  const VoucherTypes._();

  static const receipt = 'receipt';
  static const payment = 'payment';
  static const expense = 'expense';
  static const journal = 'journal';
  static const contra = 'contra';

  static const all = [receipt, payment, expense, journal, contra];

  static String label(String type) => switch (type) {
        receipt => AppLocale.l10n.voucherTypeReceipt,
        payment => AppLocale.l10n.voucherTypePayment,
        expense => AppLocale.l10n.voucherTypeExpense,
        journal => AppLocale.l10n.voucherTypeJournal,
        contra => AppLocale.l10n.voucherTypeContra,
        _ => type,
      };
}

class VoucherAccount {
  const VoucherAccount({required this.id, required this.name, this.code = '', this.kind});

  final int id;
  final String code;
  final String name;

  /// cash · bank · mfs — টাকার খাত হলে; নইলে null
  final String? kind;

  String get label => code.isEmpty ? name : '$code · $name';

  factory VoucherAccount.fromJson(Map<String, dynamic> j) => VoucherAccount(
        id: (j['id'] as num?)?.toInt() ?? 0,
        code: j['code']?.toString() ?? '',
        name: j['name']?.toString() ?? '',
        kind: j['kind']?.toString(),
      );

  /// ওয়েবের ধরন-মাধ্যম মেলানোর নিয়মে ([[VoucherService::assertTheWayMatchesTheAccount]]): নগদ → cash, MFS → mfs, ব্যাংক → transfer
  String? get instrument => switch (kind) {
        'cash' => 'cash',
        'mfs' => 'mfs',
        'bank' => 'transfer',
        _ => null,
      };
}

class VoucherSide {
  const VoucherSide({required this.label, required this.accounts});

  final String label;
  final List<VoucherAccount> accounts;

  static VoucherSide? fromJson(Object? raw) {
    if (raw is! Map) return null;
    return VoucherSide(
      label: raw['label']?.toString() ?? '',
      accounts: [
        for (final a in (raw['accounts'] as List?) ?? const [])
          if (a is Map) VoucherAccount.fromJson(Map<String, dynamic>.from(a)),
      ],
    );
  }
}

class VoucherParty {
  const VoucherParty({
    required this.type,
    required this.id,
    required this.label,
    this.hint,
    this.find,
    this.name,
    this.typeLabel = '',
    this.due,
    this.bank,
  });

  final String type;
  final int id;

  /// পুরনো ফর্মের লেখা — "নাম · ধরন"
  final String label;
  final String? hint;

  /// ⭐ সার্ভারের খোঁজার লেখা (`find`: নাম, কোড, মোবাইল, পয়েন্ট, শাখা …, ছোট হাতে; [[PartyRegistry::pickerFind()]]) —
  /// অফলাইনেও মোবাইল আর পয়েন্টে মেলে। ⓘ পুরনো সার্ভার পাঠায় না, তখন null আর আগের মতো নাম/পাশের লেখায় মেলে।
  final String? find;

  /// সার্ভারের নিজের নাম ("নাম · পয়েন্ট · শাখা"); না এলে [label]
  final String? name;
  final String typeLabel;

  /// ⭐ এখন পক্ষের বকেয়া/পাওনা, পয়সায় — সার্ভার পাঠালে (`due`); ⓘ 2bddefbb0 পর্যন্ত পাঠায় না, তখন null আর মাথায় "—"
  final int? due;

  /// ⭐ প্রাপকের ব্যাংক — সরবরাহকারীর রেকর্ড থেকে, কেবল পড়ার (`bank`: {state, line}); সার্ভার না পাঠালে null
  final PayeeBank? bank;

  /// ওয়েবের ফর্মের একটা ঘর — `type:id` ([[VoucherRequest::prepareForValidation]])
  String get key => '$type:$id';

  String get shownName => (name ?? '').trim().isNotEmpty ? name! : label;
}

/// ⭐ সরবরাহকারীর ব্যাংক — ওয়েবের `x-supplier::payee-bank`-এর একই তিন অবস্থা: `signed` (দুজনের সইয়ে যাচাই-করা), `pending`
/// (বদল সইয়ের অপেক্ষায়), `none`। ⛔ `signed` ছাড়া ব্যাংকে পরিশোধ নয় ([[SupplierBank::assertBankPayable()]])।
class PayeeBank {
  const PayeeBank({required this.state, this.line = ''});

  final String state;

  /// "ব্যাংক · শাখা · নাম · হিসাব ••••4521 · রাউটিং …" — সার্ভারের লেখা, পুরো নম্বর কখনো নয়
  final String line;

  bool get signed => state == 'signed';

  static PayeeBank? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final state = raw['state']?.toString() ?? '';
    if (state.isEmpty) return null;
    return PayeeBank(state: state, line: raw['line']?.toString() ?? '');
  }
}

/// ⭐ পক্ষের একটা খোলা বিল — `GET /accounts/parties/{type}/{id}/open-bills` (cb, চুক্তির §৭.১): `id` বিলের public_id (চুক্তি ২-এর
/// `bills[i][id]`), `outstanding` বাকি (পয়সায় [outstanding]) — বিল বাছায় এটাই, ওয়েবের কার্ডের একই সংখ্যা ([[PartyOpenBills]])
class VoucherBill {
  const VoucherBill(
      {required this.id, required this.no, this.date = '', this.dueDate, this.daysLate, this.total = 0, required this.outstanding, this.opening = false});

  /// ⭐ "আগের জের (ওপেনিং)" — খাতার জেরের যে অংশ কোনো খোলা বিলে নেই (মালিকের নমুনা, ২৬ নভেম্বর ২০২৬); কেবল দেখায়, টিক নয়, পাঠানো নয়
  final bool opening;

  final String id;
  final String no;
  final String date;
  final String? dueDate;

  /// শেষ দিন পেরিয়ে কত দিন — না পেরোলে null বা ০
  final int? daysLate;
  final int total;
  final int outstanding;

  static int _paisa(Object? v) => ((Money.value(v) ?? 0) * 100).round();

  static VoucherBill? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final id = raw['id']?.toString() ?? '';
    if (id.isEmpty) return null;
    return VoucherBill(
      id: id,
      no: raw['no']?.toString() ?? id,
      date: raw['date']?.toString() ?? '',
      dueDate: raw['due_date']?.toString(),
      daysLate: (raw['days_late'] as num?)?.toInt(),
      total: _paisa(raw['total']),
      outstanding: _paisa(raw['outstanding']),
    );
  }

  /// ⭐ দরজার `opening` ঘর (`label`, `date`, `outstanding`) — না থাকলে null
  static VoucherBill? openingFromJson(Object? raw) {
    if (raw is! Map) return null;
    final amount = _paisa(raw['outstanding']);
    if (amount <= 0) return null;
    return VoucherBill(id: '', no: raw['label']?.toString() ?? '', date: raw['date']?.toString() ?? '', outstanding: amount, opening: true);
  }
}

/// ⭐ সার্ভারের হিসাব — নিট আর খাতার সারি (চুক্তি ২, অংশ ৫: ফোন নিট নিজে গোনে না)
class VoucherPreview {
  const VoucherPreview({required this.netAmount, this.ledger = const [], this.totalDr, this.totalCr, this.needsSignature});

  /// ⭐ সই লাগবে কি না — সার্ভারের `needs_signature` (পোস্টের একই ApprovalEngine::requires, মোট টাকায়; চুক্তির §৫); না পাঠালে null।
  /// ⛔ ফোন কখনো আন্দাজ করে না
  final bool? needsSignature;

  final double netAmount;
  final List<VoucherLineView> ledger;
  final double? totalDr;
  final double? totalCr;

  /// খাত — সার্ভারের বস্তু {id, code, name} (চুক্তির §৭.৩) থেকে "কোড নাম"; পুরনো লেখা এলে যেমন আছে
  static String _accountWords(Object? a) {
    if (a is Map) return [a['code'], a['name']].where((x) => (x?.toString() ?? '').isNotEmpty).join(' ');
    return a?.toString() ?? '';
  }

  /// `net_amount` না থাকলে null — নিট কখনো আন্দাজে নয়
  static VoucherPreview? fromJson(Object? raw) {
    if (raw is! Map || raw['net_amount'] == null) return null;
    final net = Money.value(raw['net_amount']);
    if (net == null) return null;
    return VoucherPreview(
      netAmount: net,
      ledger: [
        for (final l in (raw['ledger'] as List?) ?? const [])
          if (l is Map)
            VoucherLineView(
              account: _accountWords(l['account']),
              debit: Money.valueOrZero(l['debit']),
              credit: Money.valueOrZero(l['credit']),
            ),
      ],
      totalDr: raw['total_dr'] == null ? null : Money.value(raw['total_dr']),
      totalCr: raw['total_cr'] == null ? null : Money.value(raw['total_cr']),
      needsSignature: raw['needs_signature'] is bool ? raw['needs_signature'] as bool : null,
    );
  }
}

/// ব্যাংক ট্রান্সফারের মোড (BEFTN · NPSB · RTGS) — `transfer_mode_id` সংখ্যায় ([[VoucherRequest]])
class VoucherTransferMode {
  const VoucherTransferMode({required this.id, required this.name});

  final int id;
  final String name;
}

class VoucherSetup {
  const VoucherSetup({
    required this.type,
    required this.today,
    this.narrationRequired = false,
    this.cashHiddenReason,
    this.from,
    this.to,
    this.accounts = const [],
    this.parties = const [],
    this.partyTypes = const [],
    this.transferModes,
    this.contract = 1,
  });

  final String type;
  final DateTime today;
  final bool narrationRequired;
  final String? cashHiddenReason;
  final VoucherSide? from;
  final VoucherSide? to;

  /// জাবেদার সারির খাত
  final List<VoucherAccount> accounts;
  final List<VoucherParty> parties;

  /// পক্ষের ধরন, সার্ভারের ক্রমে — (ধরন, নাম)
  final List<(String, String)> partyTypes;

  /// ⓘ সার্ভার `transfer_modes` পাঠালে তবেই মোডের ঘর; না পাঠালে null (2bddefbb0 পর্যন্ত ভাউচারের সেটআপে নেই)
  final List<VoucherTransferMode>? transferModes;

  /// ⭐ ভাউচারের চুক্তি — `voucher_contract` (cb, ২৫ অক্টোবর ২০২৬): ২ মানে পরিশোধে মোট টাকা (`gross_amount`), AIT/VDS আর বিল;
  /// না পাঠালে ১ (লাইভ 95a60587b), তখন সব আগের মতো
  final int contract;

  factory VoucherSetup.fromJson(Map<String, dynamic> j) => VoucherSetup(
        type: j['type']?.toString() ?? '',
        today: DateTime.tryParse(j['today']?.toString() ?? '') ?? DateTime.now(),
        narrationRequired: j['narration_required'] == true,
        cashHiddenReason: j['cash_hidden_reason']?.toString(),
        from: VoucherSide.fromJson(j['from']),
        to: VoucherSide.fromJson(j['to']),
        accounts: [
          for (final a in (j['accounts'] as List?) ?? const [])
            if (a is Map) VoucherAccount.fromJson(Map<String, dynamic>.from(a)),
        ],
        parties: [
          for (final g in (j['parties'] as List?) ?? const [])
            if (g is Map)
              for (final p in (g['options'] as List?) ?? const [])
                if (p is Map)
                  VoucherParty(
                    type: g['type']?.toString() ?? '',
                    id: (p['id'] as num?)?.toInt() ?? 0,
                    label: '${p['label'] ?? ''} · ${g['label'] ?? ''}',
                    hint: p['hint']?.toString(),
                    find: p['find']?.toString(),
                    name: p['label']?.toString(),
                    typeLabel: g['label']?.toString() ?? '',
                    due: p['due'] == null ? null : ((Money.value(p['due']) ?? 0) * 100).round(),
                    bank: PayeeBank.fromJson(p['bank']),
                  ),
        ],
        partyTypes: [
          for (final g in (j['parties'] as List?) ?? const [])
            if (g is Map && (g['type']?.toString() ?? '').isNotEmpty) (g['type'].toString(), g['label']?.toString() ?? ''),
        ],
        transferModes: j['transfer_modes'] is List
            ? [
                for (final m in j['transfer_modes'] as List)
                  if (m is Map && (m['id'] as num?) != null)
                    VoucherTransferMode(id: (m['id'] as num).toInt(), name: m['name']?.toString() ?? ''),
              ]
            : null,
        contract: (j['voucher_contract'] as num?)?.toInt() ?? 1,
      );
}

class VoucherRow {
  const VoucherRow({
    required this.id,
    required this.no,
    required this.type,
    required this.state,
    required this.amount,
    this.typeLabel = '',
    this.stateLabel = '',
    this.date,
    this.party,
    this.partyLabel = '',
    this.narration = '',
    this.writtenBy = '',
    this.moneyRole,
    this.moneyAccount,
    this.moneyLabel,
    this.fromAccount,
    this.fromLabel,
    this.toAccount,
    this.toLabel,
    this.approvalLevel,
    this.approvalLevels,
    this.postingFailed = false,
    this.postProblem,
  });

  final String id;
  final String no;
  final String type;
  final String typeLabel;

  /// ⭐ কে তুলেছেন — তালিকাতেই (মালিক, ১৫ অক্টোবর ২০২৬, ০.৪.৩৪); পুরনো সার্ভারে খালি
  final String writtenBy;

  /// draft · awaiting · posted · cancelled
  final String state;
  final String stateLabel;

  /// ⭐ সইয়ের কোন ধাপ এখন আর মোট কত ধাপ — কেবল সইয়ের অপেক্ষায়; চিপে "{levels}-এর {level}" (সার্ভার a6/paper-level-reason,
  /// ২০ অক্টোবর ২০২৬)। ⓘ পুরনো সার্ভারে null — তখন কেবল "সইয়ের অপেক্ষায়"
  final int? approvalLevel;
  final int? approvalLevels;

  /// ⭐ শেষ সই পড়েছে কিন্তু খাতায় তোলা আটকে গেছে (বাকির সীমা, বন্ধ বছর …) — সার্ভারে অবস্থা তখনো `draft`, পাকা করার বোতাম
  /// থাকে; চিপ "পোস্ট হয়নি", চাপলে [postProblem] (পুরো বাংলা বাক্য)। পুরনো সার্ভারে false
  final bool postingFailed;
  final String? postProblem;
  final String? date;
  final double amount;
  final String? party;

  /// ⭐ পক্ষের ঘরের নাম, ধরন ধরে — আদায়ে "কার কাছ থেকে", পরিশোধ আর খরচে "কাকে" (মালিক, ৭ অক্টোবর ২০২৬: "kake dibe
  /// kar kach theke nibe seta nai")। পুরনো সার্ভারে খালি — তখন ফোন নিজে ধরন দেখে বলে ([[partyWords]])।
  final String partyLabel;
  final String narration;

  /// ⭐ টাকা কোন খাতে — তালিকাতেই (মালিক, ১৭ অক্টোবর ২০২৬: "Mobile app Voucher e kon account e recived hoto ta dekhayna,
  /// recived into, pay From")। আদায়ে যে খাতে জমা, পরিশোধ আর খরচে যে খাত থেকে; কনট্রায় দুই দিক ([[fromAccount]] →
  /// [[toAccount]])। `moneyRole` — `into` · `out_of` · `contra`। ⓘ পুরনো সার্ভার তালিকায় কিছুই পাঠায় না — তখন সব null, ফোন
  /// কিছুই দেখায় না (আন্দাজ নয়)।
  final String? moneyRole;
  final String? moneyAccount;
  final String? moneyLabel;
  final String? fromAccount;
  final String? fromLabel;
  final String? toAccount;
  final String? toLabel;

  /// টাকার দিক — সার্ভারের `money_role`, না এলে ধরন দেখে
  String get _role => moneyRole ??
      switch (type) {
        'receipt' => 'into',
        'payment' || 'expense' => 'out_of',
        'contra' => 'contra',
        _ => '',
      };

  /// ⭐ তালিকার কার্ডের এক লাইন: "জমা: <খাত>" · "থেকে: <খাত>" · "<যেখান থেকে> → <যেখানে>"; কিছু না এলে null
  String? get moneyLine {
    if (_role == 'contra') {
      final from = _filled(fromAccount);
      final to = _filled(toAccount);
      if (from != null && to != null) return '$from → $to';
      if (from != null) return AppLocale.l10n.voucherLineFrom(from);
      if (to != null) return AppLocale.l10n.voucherLineInto(to);
      return null;
    }
    final money = _filled(moneyAccount);
    if (money == null) return null;
    return switch (_role) {
      'into' => AppLocale.l10n.voucherLineInto(money),
      'out_of' => AppLocale.l10n.voucherLineFrom(money),
      _ => null,
    };
  }

  /// ⭐ পাতার টাকার খাতের সারিগুলো (নাম, খাত) — সার্ভারের নাম আগে; না এলে আদায়ে "জমা হয়েছে", পরিশোধ আর খরচে "যেখান থেকে
  /// দেওয়া", কনট্রায় "কোথা থেকে" আর "কোথায় গেল"
  List<(String, String)> get moneyFacts {
    if (_role == 'contra') {
      return [
        if (_filled(fromAccount) case final from?) (_filled(fromLabel) ?? AppLocale.l10n.voucherFactFrom, from),
        if (_filled(toAccount) case final to?) (_filled(toLabel) ?? AppLocale.l10n.voucherFactTo, to),
      ];
    }
    final money = _filled(moneyAccount);
    if (money == null) return const [];
    return [
      (
        _filled(moneyLabel) ??
            switch (_role) {
              'into' => AppLocale.l10n.voucherFactDepositedTo,
              'out_of' => AppLocale.l10n.voucherFactPaidFrom,
              _ => AppLocale.l10n.voucherFactMoneyAccount,
            },
        money,
      ),
    ];
  }

  static String? _filled(String? s) => (s == null || s.trim().isEmpty) ? null : s;

  String get partyWords => partyLabel.isNotEmpty
      ? partyLabel
      : switch (type) {
          'receipt' => AppLocale.l10n.voucherPartyReceivedFrom,
          'payment' || 'expense' => AppLocale.l10n.voucherPartyPaidTo,
          _ => AppLocale.l10n.voucherPartyParty,
        };

  factory VoucherRow.fromJson(Map<String, dynamic> j) => VoucherRow(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        type: j['type']?.toString() ?? '',
        typeLabel: j['type_label']?.toString() ?? '',
        state: j['state']?.toString() ?? '',
        stateLabel: j['state_label']?.toString() ?? '',
        date: j['date']?.toString(),
        amount: Money.valueOrZero(j['amount']),
        party: j['party']?.toString(),
        partyLabel: j['party_label']?.toString() ?? '',
        narration: j['narration']?.toString() ?? '',
        writtenBy: j['written_by']?.toString() ?? '',
        moneyRole: _filled(j['money_role']?.toString()),
        moneyAccount: _filled(j['money_account']?.toString()),
        moneyLabel: _filled(j['money_label']?.toString()),
        fromAccount: _filled(j['from_account']?.toString()),
        fromLabel: _filled(j['from_label']?.toString()),
        toAccount: _filled(j['to_account']?.toString()),
        toLabel: _filled(j['to_label']?.toString()),
        approvalLevel: _intOrNull(j['approval_level']),
        approvalLevels: _intOrNull(j['approval_levels']),
        postingFailed: j['posting_failed'] == true,
        postProblem: _filled(j['post_problem']?.toString()),
      );

  String get kindLabel => typeLabel.isNotEmpty ? typeLabel : VoucherTypes.label(type);

  String get stateWords => stateLabel.isNotEmpty ? stateLabel : AppLocale.l10n.voucherState(state);
}

/// সার্ভারের সংখ্যা → int; না এলে বা অচেনা হলে null (পুরনো সার্ভার এই ঘর পাঠায় না)
int? _intOrNull(Object? v) => v is num ? v.toInt() : int.tryParse('${v ?? ''}');

class VoucherLineView {
  const VoucherLineView({required this.account, required this.debit, required this.credit, this.narration = '', this.party});

  final String account;
  final double debit;
  final double credit;
  final String narration;

  /// সারির নিজের পক্ষ — জাবেদায় প্রতিটা সারির; না থাকলে null
  final String? party;
}

class VoucherPage {
  const VoucherPage({
    required this.row,
    this.lines = const [],
    this.instrument,
    this.instrumentNo,
    this.writtenBy = '',
    this.canPost = false,
    this.awaitsAnotherHand = false,
    this.moneyAccount,
    this.moneyLabel,
    this.netAmount,
    this.grossAmount,
  });

  final VoucherRow row;

  /// ⭐ মোট — পক্ষের খাতা যতটা কমল (চুক্তি ২, `gross_amount`); পুরনো সার্ভারে null
  final double? grossAmount;

  /// ⭐ নিট — সার্ভারের হিসাব (চুক্তি ২, `net_amount`); পুরনো সার্ভারে null, তখন কিছুই দেখানো নয়
  final double? netAmount;

  /// ⭐ টাকার খাত, পক্ষের উল্টো দিক — আদায়ে "কোথায় জমা হলো", পরিশোধে "কোথা থেকে গেল" (মালিক, ৭ অক্টোবর ২০২৬)।
  /// পুরনো সার্ভারে null।
  final String? moneyAccount;
  final String? moneyLabel;
  final List<VoucherLineView> lines;
  final String? instrument;
  final String? instrumentNo;
  final String writtenBy;
  final bool canPost;
  final bool awaitsAnotherHand;

  /// ⭐ পাতার টাকার খাতের সারি — আদায়ে "জমা হয়েছে", পরিশোধ আর খরচে "যেখান থেকে দেওয়া", কনট্রায় দুই দিক
  /// (মালিক, ১৭ অক্টোবর ২০২৬)। পাতার নিজের [[moneyAccount]] আগে (০.৪.৩৪-এর আগের সার্ভারও পাতায় এটা পাঠাত), নইলে সারির।
  List<(String, String)> get moneyFacts {
    final own = moneyAccount == null || moneyAccount!.trim().isEmpty ? null : moneyAccount;
    if (own == null || row.type == 'contra') return row.moneyFacts;
    final fromRow = row.moneyFacts;
    return [(moneyLabel?.trim().isNotEmpty == true ? moneyLabel! : (fromRow.isNotEmpty ? fromRow.first.$1 : _wordsFor(row.type)), own)];
  }

  static String _wordsFor(String type) => switch (type) {
        'receipt' => AppLocale.l10n.voucherFactDepositedTo,
        'payment' || 'expense' => AppLocale.l10n.voucherFactPaidFrom,
        _ => AppLocale.l10n.voucherFactMoneyAccount,
      };

  factory VoucherPage.fromJson(Map<String, dynamic> j) => VoucherPage(
        row: VoucherRow.fromJson(j),
        instrument: j['instrument']?.toString(),
        instrumentNo: j['instrument_no']?.toString(),
        writtenBy: j['written_by']?.toString() ?? '',
        canPost: j['can_post'] == true,
        awaitsAnotherHand: j['awaits_another_hand'] == true,
        moneyAccount: j['money_account']?.toString(),
        moneyLabel: j['money_label']?.toString(),
        netAmount: j['net_amount'] == null ? null : Money.value(j['net_amount']),
        grossAmount: j['gross_amount'] == null ? null : Money.value(j['gross_amount']),
        lines: [
          for (final l in (j['lines'] as List?) ?? const [])
            if (l is Map)
              VoucherLineView(
                account: l['account']?.toString() ?? '',
                debit: Money.valueOrZero(l['debit']),
                credit: Money.valueOrZero(l['credit']),
                narration: l['narration']?.toString() ?? '',
                party: (l['party']?.toString().trim().isEmpty ?? true) ? null : l['party'].toString(),
              ),
        ],
      );
}

class VoucherListPage {
  const VoucherListPage({required this.rows, this.count = 0, this.nextPage, this.total});

  final List<VoucherRow> rows;
  final int count;
  final int? nextPage;

  /// ⭐ ছাঁকা তালিকার মোট টাকা — সার্ভার গোনে, পুরো ফলের (পাতার নয়), বাতিল বাদ; পুরনো সার্ভারে `null`
  final double? total;
}

/// ⭐ তালিকার উপরের বোতাম — মালিক, ১৫ অক্টোবর ২০২৬ (০.৪.৩৪): সব · নগদ রসিদ · ব্যাংক রসিদ · পেমেন্ট; ১৭ অক্টোবর ২০২৬:
/// নগদ রসিদের পরে MFS রসিদ, তারপর কন্ট্রা · জার্নাল · খরচ ([others])।
/// নগদ রসিদ = টাকা নগদ বা ক্যাশ-বাক্সে জমা (`money=cash`), ব্যাংক রসিদ = টাকা ব্যাংকে (`money=bank`)।
class VoucherView {
  const VoucherView(this.key, this._label, {this.type, this.money});

  final String key;

  /// ⓘ নিজের নাম না দিলে চাবি ধরে ARB থেকে, এখনকার ভাষায়
  final String? _label;
  String get label => _label ?? _labelOf(key);

  static String _labelOf(String key) {
    final w = AppLocale.l10n;
    return switch (key) {
      'cash-receipt' => w.voucherViewCashReceipts,
      'bank-receipt' => w.voucherViewBankReceipts,
      'payment' => w.voucherViewPayments,
      'mfs-receipt' => w.voucherViewMfsReceipts,
      'contra' => w.voucherViewContras,
      'journal' => w.voucherViewJournals,
      'expense' => w.voucherViewExpenses,
      'receipt' => w.voucherViewAllReceipts,
      _ => w.voucherViewAll,
    };
  }
  final String? type;
  final String? money;

  static const all = VoucherView('all', null);
  static const cashReceipts = VoucherView('cash-receipt', null, type: 'receipt', money: 'cash');
  static const bankReceipts = VoucherView('bank-receipt', null, type: 'receipt', money: 'bank');
  static const payments = VoucherView('payment', null, type: 'payment');

  static const top = [all, cashReceipts, mfsReceipts, bankReceipts, payments];

  // ── ⭐ প্রতিটা ধরন আলাদা — মালিক, ১৭ অক্টোবর ২০২৬: "receipt app e cash, mfs, bank, payment, contra, journal, expense
  // egulo alada alada asena eta tik korobe"। MFS রসিদ = টাকা বিকাশ/নগদ-এর মতো MFS খাতে (`money=mfs`)। ──
  static const mfsReceipts = VoucherView('mfs-receipt', null, type: 'receipt', money: 'mfs');
  static const contras = VoucherView('contra', null, type: 'contra');
  static const journals = VoucherView('journal', null, type: 'journal');
  static const expenses = VoucherView('expense', null, type: 'expense');

  /// বাকি ধরন, উপরের বোতামগুলোর পরে
  static const others = [contras, journals, expenses];

  /// সব বোতাম, পর্দার ক্রমে — মেনুর আলাদা আলাদা ঘরও এগুলোই (`/home/vouchers/<key>`)
  static const kinds = [...top, ...others];

  /// ⭐ সব রসিদ একসাথে — ওয়েবের মেনুর "আদায়" সারি (`/accounts/vouchers/receipt`, মালিক, ১৭ অক্টোবর ২০২৬)। ⓘ পর্দার
  /// বোতামে নেই (নগদ/MFS/ব্যাংক আলাদা বোতাম আছে), কেবল ঐ সারি থেকে খোলে।
  static const receipts = VoucherView('receipt', null, type: 'receipt');

  /// মেনু বা পুরনো লিংকের চাবি থেকে — অচেনা বা খালি হলে null (তখন "সব")
  static VoucherView? byKey(String? key) {
    for (final v in [...kinds, receipts]) {
      if (v.key == key) return v;
    }
    return null;
  }
}

/// পাকা করার উত্তর — হলো কি না, আর সার্ভারের কথা
class PostAnswer {
  const PostAnswer({required this.posted, required this.message, this.page});

  final bool posted;
  final String message;
  final VoucherPage? page;
}

/// ফোনের লেখা একটা ভাউচার — ওয়েবের ফর্মের ঘরগুলো ([[VoucherRequest]])
class VoucherDraft {
  const VoucherDraft({
    required this.type,
    required this.date,
    this.narration = '',
    this.fromAccountId,
    this.toAccountId,
    this.amount,
    this.party,
    this.instrument,
    this.instrumentNo,
    this.lines = const [],
    this.keepAsDraft = false,
    this.partyType,
    this.partyId,
    this.transferModeId,
    this.valueDate,
    this.chargeAmount,
    this.instrumentDate,
    this.fromBank,
    this.expenseAccountId,
    this.payeeName,
    this.billNo,
    this.grossAmount,
    this.aitAmount,
    this.vdsAmount,
    this.bills,
  });

  final String type;
  final DateTime date;
  final String narration;
  final int? fromAccountId;
  final int? toAccountId;
  final String? amount;
  final String? party;
  final String? instrument;
  final String? instrumentNo;

  /// জাবেদা: {account_id, debit, credit, narration?, party?}
  final List<Map<String, dynamic>> lines;
  final bool keepAsDraft;

  // ── ⭐ টাকা দেওয়ার পর্দার ঘর — ওয়েবের পরিশোধ আর খরচের একই নাম (cb 2bddefbb0: simple-form, party-fields,
  // expense-fields, x-money.method; [[VoucherRequest::rules()]]) ──

  /// পক্ষ দুই ঘরে, ওয়েবের মতো (`party_type`, `party_id`) — [party]-র `type:id`-এর বদলে
  final String? partyType;
  final int? partyId;
  final int? transferModeId;
  final DateTime? valueDate;

  /// ব্যাংক বা MFS-এর চার্জ — আমাদের খরচ, চার্জের খাতে ("কে দিল" নেই)
  final String? chargeAmount;
  final DateTime? instrumentDate;

  /// চেকের ব্যাংক
  final String? fromBank;

  /// খরচের খাত — ওয়েব একই খাত `to_account_id`-তেও পাঠায়
  final int? expenseAccountId;
  final String? payeeName;
  final String? billNo;
  final String? grossAmount;
  final String? aitAmount;
  final String? vdsAmount;

  /// ⭐ চুক্তি ২-এর বিল — [{id: বিলের public_id, amount}]; null হলে ঘরটাই যায় না
  final List<Map<String, String>>? bills;

  static String _day(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  static bool _filled(String? s) => (s ?? '').trim().isNotEmpty;

  Map<String, dynamic> toPayload() => {
        'type': type,
        'trx_date': _day(date),
        if (narration.trim().isNotEmpty) 'narration': narration.trim(),
        if (type == VoucherTypes.journal)
          'lines': lines
        else ...{
          'from_account_id': fromAccountId,
          'to_account_id': toAccountId,
          if (amount != null) 'amount': amount,
          if (party != null && party!.isNotEmpty) 'party': party,
          if (_filled(partyType) && partyId != null) ...{'party_type': partyType, 'party_id': partyId},
          if (instrument != null) 'instrument': instrument,
          if (_filled(instrumentNo)) 'instrument_no': instrumentNo!.trim(),
          if (transferModeId != null) 'transfer_mode_id': transferModeId,
          if (valueDate != null) 'value_date': _day(valueDate!),
          if (_filled(chargeAmount)) 'charge_amount': chargeAmount!.trim(),
          if (instrumentDate != null) 'instrument_date': _day(instrumentDate!),
          if (_filled(fromBank)) 'from_bank': fromBank!.trim(),
          if (expenseAccountId != null) 'expense_account_id': expenseAccountId,
          if (_filled(payeeName)) 'payee_name': payeeName!.trim(),
          if (_filled(billNo)) 'bill_no': billNo!.trim(),
          if (_filled(grossAmount)) 'gross_amount': grossAmount!.trim(),
          if (_filled(aitAmount)) 'ait_amount': aitAmount!.trim(),
          if (_filled(vdsAmount)) 'vds_amount': vdsAmount!.trim(),
          if (bills != null && bills!.isNotEmpty) 'bills': bills,
        },
        if (keepAsDraft) 'save_as_draft': true,
      };
}

/// ⭐ সার্ভারের হিসাব আগে দেখা — `POST /accounts/vouchers/preview` (একই payload; net_amount, ledger, total_dr, total_cr)। ⓘ a4 চেয়েছে,
/// cb এখনো বানায়নি: দরজা না থাকলে (৪০৪/৪০৫) null, পর্দা তখন নিট দেখায় না; ৪২২ হলে DioException, পর্দা ঘরে ঘরে বসায়
abstract class VoucherPreviewer {
  Future<VoucherPreview?> preview(VoucherDraft draft);
}

/// ⭐ পক্ষের খোলা বিল — `GET /accounts/parties/{type}/{id}/open-bills` (cb, চুক্তির §৭.১); দরজা না থাকলে (৪০৪/৪০৫) null, পর্দা তখন বিল দেখায় না
abstract class VoucherBillsSource {
  Future<List<VoucherBill>?> openBills(String type, int id);
}

abstract class VoucherApi {
  Future<VoucherSetup> setup(String type);

  Future<VoucherListPage> list({
    String? type,
    bool awaiting = false,
    int page = 1,
    String? money,
    String? from,
    String? to,
    String? branch,
  });

  Future<VoucherPage> one(String id);

  Future<PostAnswer> post(String id, {String? instrumentNo});

  /// এখনই সার্ভারে — [changeId] একই কাজে একই থাকে; নেট না থাকলে [NoNetworkForThis]
  Future<PushOutcome> send(VoucherDraft draft, String changeId);
}

class ServerVoucherApi implements VoucherApi, VoucherPreviewer, VoucherBillsSource {
  const ServerVoucherApi();

  @override
  Future<List<VoucherBill>?> openBills(String type, int id) async {
    try {
      final r = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/parties/$type/$id/open-bills');
      return [
        // ⭐ আগের জের সবার আগে — পর্দা আলাদা করে আঁকে, টিক দেয় না
        if (VoucherBill.openingFromJson(r.data?['opening']) case final opening?) opening,
        for (final b in (r.data?['bills'] as List?) ?? const [])
          if (VoucherBill.fromJson(b) case final bill?) bill,
      ];
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      if (status == 404 || status == 405) return null;
      rethrow;
    }
  }

  @override
  Future<VoucherPreview?> preview(VoucherDraft draft) async {
    try {
      final r = await ApiClient.dio.post<Map<String, dynamic>>('/accounts/vouchers/preview', data: draft.toPayload());
      return VoucherPreview.fromJson(r.data);
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      if (status == 404 || status == 405) return null;
      rethrow;
    }
  }

  @override
  Future<VoucherSetup> setup(String type) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/vouchers/setup', queryParameters: {'type': type});
    return VoucherSetup.fromJson(r.data ?? const {});
  }

  @override
  Future<VoucherListPage> list({
    String? type,
    bool awaiting = false,
    int page = 1,
    String? money,
    String? from,
    String? to,
    String? branch,
  }) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/vouchers', queryParameters: {
      if (type != null) 'type': type,
      if (awaiting) 'awaiting': 1,
      if (money != null) 'money': money,
      if (from != null) 'from': from,
      if (to != null) 'to': to,
      if (branch != null) 'branch': branch,
      'page': page,
    });
    final body = r.data ?? const <String, dynamic>{};
    return VoucherListPage(
      rows: [
        for (final row in (body['rows'] as List?) ?? const [])
          if (row is Map) VoucherRow.fromJson(Map<String, dynamic>.from(row)),
      ],
      count: (body['count'] as num?)?.toInt() ?? 0,
      nextPage: (body['next_page'] as num?)?.toInt(),
      total: body['total'] == null ? null : Money.valueOrZero(body['total']),
    );
  }

  @override
  Future<VoucherPage> one(String id) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/vouchers/$id');
    return VoucherPage.fromJson(r.data ?? const {});
  }

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) async {
    final r = await OnceKey.of('voucher-post:$id').send(() => ApiClient.dio.post<Map<String, dynamic>>('/accounts/vouchers/$id/post',
        data: {if ((instrumentNo ?? '').trim().isNotEmpty) 'instrument_no': instrumentNo!.trim()}));
    final body = r.data ?? const <String, dynamic>{};
    final page = body['voucher'];
    return PostAnswer(
      posted: body['posted'] == true,
      message: body['message']?.toString() ?? '',
      page: page is Map ? VoucherPage.fromJson(Map<String, dynamic>.from(page)) : null,
    );
  }

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) => SyncEngine.instance.pushNow(
        module: 'accounts',
        entityType: 'Voucher',
        changeId: changeId,
        payload: draft.toPayload(),
      );
}

/// ⭐ সার্ভারের ফেরানোর পুরো কথা — `errors`-এর প্রতিটা চাবির প্রতিটা বাক্য, যে চাবিই হোক (cb 81674fcc2, ২৫ অক্টোবর ২০২৬: সই-না-পাওয়া
/// ব্যাংকে পরিশোধ ৪২২ `errors.payee_bank[0]`, সরবরাহকারীর নাম, করণীয় আর পাতার ঠিকানাসহ)।
///
/// <p>⛔ কোনো চাবি ফর্মের ঘর না হলেও বাদ নয় — `payee_bank`-এর মতো কথা কোনো ঘরের নিচে বসে না, তাই পর্দার উপরে পুরোটা দেখানো হয়;
/// কাটা নয়, ছাঁটা নয়। `errors` না থাকলে বা বাংলা কিছু না থাকলে আগের মতো সার্ভারের `message` ([serverSentence]), নইলে null।
String? voucherRefusal(Object error) {
  if (error is DioException) {
    final status = error.response?.statusCode ?? 0;
    final body = error.response?.data;
    final errors = body is Map ? body['errors'] : null;
    if (status >= 400 && status < 500 && errors is Map) {
      final lines = <String>[];
      for (final entry in errors.entries) {
        final said = entry.value;
        for (final line in said is List ? said : [said]) {
          final text = line?.toString().trim() ?? '';
          if (text.isNotEmpty && !lines.contains(text)) lines.add(text);
        }
      }
      final joined = lines.join('\n');
      // ⓘ বাংলায় বলা কথা মানুষের জন্য; ইংরেজিতে চলা অ্যাপে সার্ভার মানুষের কথাও ইংরেজিতে বলে (মানুষের ভাষায় উত্তর) — তখন সেটাও
      if (joined.isNotEmpty && (AppLocale.isEnglish() || joined.runes.any((r) => r >= 0x0980 && r <= 0x09FF))) return joined;
    }
  }
  return serverSentence(error);
}

/// ⭐ ৪২২-এর ঘর ধরে কথা — `errors`-এর চাবি → তার প্রথম বাক্য (চুক্তি ২, অংশ ৪: gross_amount, bills.{i}.amount, bills, amount …)।
/// ⓘ ডিও-র ৪২২ বা সিঙ্কের ফেরানো সারির `errors` (সার্ভার পাঠালে); কিছু না থাকলে খালি
Map<String, String> voucherFieldErrors(Object source) {
  Object? errors;
  if (source is DioException) {
    final status = source.response?.statusCode ?? 0;
    final body = source.response?.data;
    if (status >= 400 && status < 500 && body is Map) errors = body['errors'];
  } else if (source is Map) {
    errors = source['errors'];
  }
  if (errors is! Map) return const {};
  return {
    for (final e in errors.entries)
      if ((e.value is List ? (e.value as List).firstOrNull : e.value)?.toString().trim() case final text? when text.isNotEmpty)
        e.key.toString(): text,
  };
}
