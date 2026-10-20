import '../../core/api_client/once_key.dart';
import '../api_client/api_client.dart';
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
        receipt => 'আদায় ভাউচার',
        payment => 'পরিশোধ ভাউচার',
        expense => 'খরচ ভাউচার',
        journal => 'জাবেদা ভাউচার',
        contra => 'কন্ট্রা ভাউচার',
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
  const VoucherParty({required this.type, required this.id, required this.label, this.hint});

  final String type;
  final int id;
  final String label;
  final String? hint;

  /// ওয়েবের ফর্মের একটা ঘর — `type:id` ([[VoucherRequest::prepareForValidation]])
  String get key => '$type:$id';
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
                  ),
        ],
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
      if (from != null) return 'থেকে: $from';
      if (to != null) return 'জমা: $to';
      return null;
    }
    final money = _filled(moneyAccount);
    if (money == null) return null;
    return switch (_role) {
      'into' => 'জমা: $money',
      'out_of' => 'থেকে: $money',
      _ => null,
    };
  }

  /// ⭐ পাতার টাকার খাতের সারিগুলো (নাম, খাত) — সার্ভারের নাম আগে; না এলে আদায়ে "জমা হয়েছে", পরিশোধ আর খরচে "যেখান থেকে
  /// দেওয়া", কনট্রায় "কোথা থেকে" আর "কোথায় গেল"
  List<(String, String)> get moneyFacts {
    if (_role == 'contra') {
      return [
        if (_filled(fromAccount) case final from?) (_filled(fromLabel) ?? 'কোথা থেকে', from),
        if (_filled(toAccount) case final to?) (_filled(toLabel) ?? 'কোথায় গেল', to),
      ];
    }
    final money = _filled(moneyAccount);
    if (money == null) return const [];
    return [
      (
        _filled(moneyLabel) ??
            switch (_role) {
              'into' => 'জমা হয়েছে',
              'out_of' => 'যেখান থেকে দেওয়া',
              _ => 'টাকার খাত',
            },
        money,
      ),
    ];
  }

  static String? _filled(String? s) => (s == null || s.trim().isEmpty) ? null : s;

  String get partyWords => partyLabel.isNotEmpty
      ? partyLabel
      : switch (type) {
          'receipt' => 'কার কাছ থেকে',
          'payment' || 'expense' => 'কাকে',
          _ => 'পক্ষ',
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

  String get stateWords => stateLabel.isNotEmpty
      ? stateLabel
      : switch (state) {
          'draft' => 'খসড়া',
          'awaiting' => 'সইয়ের অপেক্ষায়',
          'posted' => 'পোস্ট হয়েছে',
          'cancelled' => 'বাতিল',
          _ => 'খসড়া',
        };
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
  });

  final VoucherRow row;

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
        'receipt' => 'জমা হয়েছে',
        'payment' || 'expense' => 'যেখান থেকে দেওয়া',
        _ => 'টাকার খাত',
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
  const VoucherView(this.key, this.label, {this.type, this.money});

  final String key;
  final String label;
  final String? type;
  final String? money;

  static const all = VoucherView('all', 'সব');
  static const cashReceipts = VoucherView('cash-receipt', 'নগদ রসিদ', type: 'receipt', money: 'cash');
  static const bankReceipts = VoucherView('bank-receipt', 'ব্যাংক রসিদ', type: 'receipt', money: 'bank');
  static const payments = VoucherView('payment', 'পেমেন্ট', type: 'payment');

  static const top = [all, cashReceipts, mfsReceipts, bankReceipts, payments];

  // ── ⭐ প্রতিটা ধরন আলাদা — মালিক, ১৭ অক্টোবর ২০২৬: "receipt app e cash, mfs, bank, payment, contra, journal, expense
  // egulo alada alada asena eta tik korobe"। MFS রসিদ = টাকা বিকাশ/নগদ-এর মতো MFS খাতে (`money=mfs`)। ──
  static const mfsReceipts = VoucherView('mfs-receipt', 'MFS রসিদ', type: 'receipt', money: 'mfs');
  static const contras = VoucherView('contra', 'কন্ট্রা', type: 'contra');
  static const journals = VoucherView('journal', 'জার্নাল', type: 'journal');
  static const expenses = VoucherView('expense', 'খরচ', type: 'expense');

  /// বাকি ধরন, উপরের বোতামগুলোর পরে
  static const others = [contras, journals, expenses];

  /// সব বোতাম, পর্দার ক্রমে — মেনুর আলাদা আলাদা ঘরও এগুলোই (`/home/vouchers/<key>`)
  static const kinds = [...top, ...others];

  /// ⭐ সব রসিদ একসাথে — ওয়েবের মেনুর "আদায়" সারি (`/accounts/vouchers/receipt`, মালিক, ১৭ অক্টোবর ২০২৬)। ⓘ পর্দার
  /// বোতামে নেই (নগদ/MFS/ব্যাংক আলাদা বোতাম আছে), কেবল ঐ সারি থেকে খোলে।
  static const receipts = VoucherView('receipt', 'সব রসিদ', type: 'receipt');

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

  Map<String, dynamic> toPayload() => {
        'type': type,
        'trx_date':
            '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}',
        if (narration.trim().isNotEmpty) 'narration': narration.trim(),
        if (type == VoucherTypes.journal)
          'lines': lines
        else ...{
          'from_account_id': fromAccountId,
          'to_account_id': toAccountId,
          'amount': amount,
          if (party != null && party!.isNotEmpty) 'party': party,
          if (instrument != null) 'instrument': instrument,
          if ((instrumentNo ?? '').trim().isNotEmpty) 'instrument_no': instrumentNo!.trim(),
        },
        if (keepAsDraft) 'save_as_draft': true,
      };
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

class ServerVoucherApi implements VoucherApi {
  const ServerVoucherApi();

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
