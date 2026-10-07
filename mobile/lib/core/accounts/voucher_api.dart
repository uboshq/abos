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
    this.narration = '',
  });

  final String id;
  final String no;
  final String type;
  final String typeLabel;

  /// draft · awaiting · posted · cancelled
  final String state;
  final String stateLabel;
  final String? date;
  final double amount;
  final String? party;
  final String narration;

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
        narration: j['narration']?.toString() ?? '',
      );

  String get kindLabel => typeLabel.isNotEmpty ? typeLabel : VoucherTypes.label(type);

  String get stateWords => stateLabel.isNotEmpty
      ? stateLabel
      : switch (state) {
          'draft' => 'খসড়া',
          'awaiting' => 'সইয়ের অপেক্ষায়',
          'posted' => 'পাকা',
          'cancelled' => 'বাতিল',
          _ => 'খসড়া',
        };
}

class VoucherLineView {
  const VoucherLineView({required this.account, required this.debit, required this.credit, this.narration = ''});

  final String account;
  final double debit;
  final double credit;
  final String narration;
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
  });

  final VoucherRow row;
  final List<VoucherLineView> lines;
  final String? instrument;
  final String? instrumentNo;
  final String writtenBy;
  final bool canPost;
  final bool awaitsAnotherHand;

  factory VoucherPage.fromJson(Map<String, dynamic> j) => VoucherPage(
        row: VoucherRow.fromJson(j),
        instrument: j['instrument']?.toString(),
        instrumentNo: j['instrument_no']?.toString(),
        writtenBy: j['written_by']?.toString() ?? '',
        canPost: j['can_post'] == true,
        awaitsAnotherHand: j['awaits_another_hand'] == true,
        lines: [
          for (final l in (j['lines'] as List?) ?? const [])
            if (l is Map)
              VoucherLineView(
                account: l['account']?.toString() ?? '',
                debit: Money.valueOrZero(l['debit']),
                credit: Money.valueOrZero(l['credit']),
                narration: l['narration']?.toString() ?? '',
              ),
        ],
      );
}

class VoucherListPage {
  const VoucherListPage({required this.rows, this.count = 0, this.nextPage});

  final List<VoucherRow> rows;
  final int count;
  final int? nextPage;
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

  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1});

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
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1}) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/vouchers', queryParameters: {
      if (type != null) 'type': type,
      if (awaiting) 'awaiting': 1,
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
    );
  }

  @override
  Future<VoucherPage> one(String id) async {
    final r = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/vouchers/$id');
    return VoucherPage.fromJson(r.data ?? const {});
  }

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) async {
    final r = await ApiClient.dio.post<Map<String, dynamic>>('/accounts/vouchers/$id/post',
        data: {if ((instrumentNo ?? '').trim().isNotEmpty) 'instrument_no': instrumentNo!.trim()});
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
