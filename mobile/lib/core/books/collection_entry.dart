import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client/api_client.dart';
import '../records/customer_record.dart';
import '../records/money.dart';
import '../sync_engine/reference_cache.dart';
import '../sync_engine/sync_engine.dart';
import '../locale/app_locale.dart';

/// ⭐ ফোনে টাকা আদায় — কেবল অফিসের লোক (মালিক, ৭ অক্টোবর ২০২৬: "এটা কেবল অফিসের লোকদের জন্য থাকবে, আর ফিল্ডের
/// জন্য থাকবে payment request")। সার্ভার `/me`-তে বলে (`mayCollect`) — আদায়ের চাবি আর খাতায় টাকা তোলার চাবি।
///
/// <p>⛔ কেবল নেট থাকলে — মালিকের নিয়ম "নেট না থাকলে শুধু অর্ডার" (৭ অক্টোবর ২০২৬, সমন্বয়কের অ্যাপ-অডিট: আগে সারিতে
/// পাঠানো হত, আর সারি আদায় নেয়ই না — একটাও পৌঁছায়নি)। সিঙ্কের দরজায় সরাসরি ([[SyncEngine.pushNow]]), ফোনে বানানো
/// চাবিসহ — দুবার চাপলেও একটাই বসে। নিয়ম সব সার্ভারের — ওয়েবের আদায়-ফর্মের একই।
///
/// <p>⭐ ০.৪.৪৫ (মালিক, ২১ অক্টোবর ২০২৬: "ok ekoni kaj kore live e daw"; চূড়ান্ত নকশা ১০০): এক পথ, এক পাতা, ওয়েবের
/// একই ঘর আর নাম — খোলা বিল ও ক্রেডিট, ছাড়/রাউন্ডিং, খাতার প্রিভিউ, স্লিপের ছবি। ⛔ পুরনো সার্ভারে নতুন দরজা নেই (404/405)
/// — তখন পর্দা আজকের মতোই চলে: বিল সার্ভার নিজে বসায়, প্রিভিউ নেই, ছবি নেই।
final mayCollectProvider = StateProvider<bool>((ref) => false);

/// একটা টাকার খাত — নগদ, ব্যাংক বা MFS (ওয়েবের আদায়-ফর্মের একই তালিকা)
class CollectionAccount {
  const CollectionAccount(
      {required this.id,
      required this.name,
      required this.kind,
      this.code = '',
      this.mine = false});

  final String id;
  final String name;
  final String code;

  /// cash · bank · mfs
  final String kind;

  /// ⭐ যিনি লিখছেন তাঁর নিজের হাতের নগদ (সার্ভারের `mine`) — আগে থেকে বাছা থাকে; পুরনো সার্ভারে false, তখন প্রথম নগদ
  final bool mine;

  String get kindLabel => switch (kind) {
        'bank' => AppLocale.l10n.booksMethodBank,
        'mfs' => AppLocale.l10n.booksMethodMobileBanking,
        _ => AppLocale.l10n.booksMethodCash,
      };

  String get label => code.isEmpty ? name : '$code · $name';

  factory CollectionAccount.fromJson(Map<String, dynamic> j) =>
      CollectionAccount(
        id: j['id']?.toString() ?? '',
        name: j['name']?.toString() ?? '',
        code: j['code']?.toString() ?? '',
        kind: j['kind']?.toString() ?? 'cash',
        mine: j['mine'] == true,
      );

  Map<String, dynamic> toJson() =>
      {'id': id, 'name': name, 'code': code, 'kind': kind, if (mine) 'mine': true};

  /// নিজের নগদ আগে, তারপর বাকি নগদ, তারপর বাকিরা — সার্ভারের ক্রম ভেতরে একই থাকে
  static List<CollectionAccount> ownCashFirst(List<CollectionAccount> list) {
    int rank(CollectionAccount a) => a.kind == 'cash' ? (a.mine ? 0 : 1) : 2;
    final indexed = list.indexed.toList()
      ..sort((x, y) {
        final r = rank(x.$2).compareTo(rank(y.$2));
        return r != 0 ? r : x.$1.compareTo(y.$1);
      });
    return [for (final (_, a) in indexed) a];
  }
}

/// ⭐ খোলা বিল, ডেবিট নোট, ক্রেডিট বা ক্রেডিট নোট — এক টেবিলের এক সারি (চূড়ান্ত নকশা, বিষয় ৩; a5-এর চূড়ান্ত চুক্তি:
/// `items:[{id, kind, no, date, due_date, amount, open, overdue_days}]`, ক্রেডিটের টাকা ঋণাত্মক)।
///
/// <p>টাকা পয়সায় আর ধনাত্মক ([open], [amount]) — দিক [isCredit]-এ। যোগ-বিয়োগে ভগ্নাংশের ভুল নেই, ওয়েবের bcmath-এর একই ফল।
class OpenItem {
  const OpenItem({
    required this.id,
    required this.no,
    this.date,
    this.dueDate,
    this.overdueDays,
    required this.amount,
    required this.open,
    this.kind = 'bill',
  });

  final String id;
  final String no;
  final String? date;
  final String? dueDate;
  final int? overdueDays;

  /// মূল টাকা, পয়সায় (ধনাত্মক)
  final int amount;

  /// বাকি, পয়সায় (ধনাত্মক)
  final int open;

  /// bill · debit_note · credit_note · credit
  final String kind;

  bool get isCredit => kind == 'credit' || kind == 'credit_note';

  factory OpenItem.fromJson(Map<String, dynamic> j) => OpenItem(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        date: j['date']?.toString(),
        dueDate: j['due_date']?.toString(),
        overdueDays: (j['overdue_days'] as num?)?.toInt(),
        amount: paisaOf(j['amount'] ?? j['open']).abs(),
        open: paisaOf(j['open']).abs(),
        kind: j['kind']?.toString() ?? 'bill',
      );
}

/// সার্ভারের দশমিক লেখা → পয়সা (৪ ঘরের লেখা থেকে, গোল করে)
int paisaOf(Object? value) => ((Money.value(value) ?? 0) * 100).round();

/// পয়সা → সার্ভারে পাঠানোর লেখা ("45200.00")
String paisaText(int paisa) {
  final sign = paisa < 0 ? '-' : '';
  final p = paisa.abs();
  return '$sign${p ~/ 100}.${(p % 100).toString().padLeft(2, '0')}';
}

/// `GET /sales/collections/open-bills?customer=` — a5-এর চূড়ান্ত চুক্তি:
/// `{customer:{id,label,credit_limit,oldest_overdue_days}, due, credit, month_open, attachment_required, items:[…]}`
class OpenBills {
  const OpenBills({
    this.label,
    required this.due,
    this.credit = 0,
    this.items = const [],
    this.creditLimit,
    this.oldestOverdueDays,
    this.monthOpen,
    this.attachmentRequired = false,
  });

  final String? label;

  /// বকেয়া, পয়সায় — ওয়েবের একই বকেয়া (Customer::outstanding)
  final int due;

  /// গ্রাহকের খাতায় জমা, পয়সায়
  final int credit;

  /// এক টেবিল — সার্ভারের ক্রমে (পুরনো আগে)
  final List<OpenItem> items;

  /// বিল আর ডেবিট নোট, পুরনো আগে
  List<OpenItem> get bills => [for (final i in items) if (!i.isCredit) i];

  /// ক্রেডিট আর ক্রেডিট নোট, পুরনো আগে
  List<OpenItem> get credits => [for (final i in items) if (i.isCredit) i];

  /// ⭐ বিষয় ১ — বাকির সীমা আর সবচেয়ে পুরনো বাকির দিন; না পাঠালে null, তখন দেখায় না
  final int? creditLimit;
  final int? oldestOverdueDays;

  /// ⭐ বিষয় ১৩ — মাস খোলা কি না; না পাঠালে null, তখন কিছু বলে না (সার্ভার পোস্টে নিজেই ফেরায়)
  final bool? monthOpen;

  /// ⭐ বিষয় ১৫ — কোম্পানি স্লিপের ছবি বাধ্যতামূলক করেছে কি না; না পাঠালে ঐচ্ছিক
  final bool attachmentRequired;

  factory OpenBills.fromJson(Map<String, dynamic> j) {
    final customer = j['customer'] is Map ? Map<String, dynamic>.from(j['customer'] as Map) : const <String, dynamic>{};
    final items = [
      for (final r in (j['items'] as List?) ?? const [])
        if (r is Map) OpenItem.fromJson(Map<String, dynamic>.from(r)),
    ];
    return OpenBills(
      label: customer['label']?.toString(),
      due: paisaOf(j['due']),
      credit: paisaOf(j['credit']),
      // ⓘ সার্ভার পুরনো আগে পাঠায় — ক্রম সার্ভারের, ফোনে আবার সাজানো নয়
      items: items,
      creditLimit: customer['credit_limit'] == null ? null : paisaOf(customer['credit_limit']),
      oldestOverdueDays: (customer['oldest_overdue_days'] as num?)?.toInt(),
      monthOpen: j['month_open'] is bool ? j['month_open'] as bool : null,
      attachmentRequired: j['attachment_required'] == true,
    );
  }
}

/// খাতার এক লাইন — প্রিভিউয়ের
class JournalLine {
  const JournalLine({required this.account, this.branch = '', required this.dr, required this.cr});

  final String account;
  final String branch;

  /// পয়সায়
  final int dr;
  final int cr;
}

/// `POST /sales/collections/preview` — কিছুই লেখে না। a5-এর চূড়ান্ত চুক্তি: `{lines:[{account,branch,dr,cr}], total_dr,
/// total_cr, applied, unapplied, summary{…, balanced, amount_words}, needs_signature, stops:[…]}`
class CollectionPreview {
  const CollectionPreview({
    this.lines = const [],
    int? totalDr,
    int? totalCr,
    this.amountWords,
    this.balanced,
    this.needsSignature,
    this.stops = const [],
  })  : _totalDr = totalDr,
        _totalCr = totalCr;

  final List<JournalLine> lines;
  final int? _totalDr;
  final int? _totalCr;

  /// ⭐ বিষয় ১৭ — সার্ভারের কথায় টাকা, রসিদের হুবহু
  final String? amountWords;
  final bool? balanced;

  /// ⭐ বিষয় ৪/১১ — সইয়ে যাবে কি না
  final bool? needsSignature;

  /// ⛔ যা পোস্ট আটকায় (যেমন বন্ধ মাস) — খালি না হলে "পোস্ট" বন্ধ
  final List<String> stops;

  int get totalDr => _totalDr ?? lines.fold(0, (s, l) => s + l.dr);
  int get totalCr => _totalCr ?? lines.fold(0, (s, l) => s + l.cr);

  factory CollectionPreview.fromJson(Map<String, dynamic> j) {
    final summary = j['summary'] is Map ? Map<String, dynamic>.from(j['summary'] as Map) : const <String, dynamic>{};
    final words = summary['amount_words']?.toString().trim() ?? '';
    return CollectionPreview(
      lines: [
        for (final l in (j['lines'] as List?) ?? const [])
          if (l is Map)
            JournalLine(
              account: l['account']?.toString() ?? '',
              branch: l['branch']?.toString() ?? '',
              dr: paisaOf(l['dr']),
              cr: paisaOf(l['cr']),
            ),
      ],
      totalDr: j.containsKey('total_dr') ? paisaOf(j['total_dr']) : null,
      totalCr: j.containsKey('total_cr') ? paisaOf(j['total_cr']) : null,
      amountWords: words.isEmpty ? null : words,
      balanced: summary['balanced'] is bool ? summary['balanced'] as bool : null,
      needsSignature: j['needs_signature'] is bool ? j['needs_signature'] as bool : null,
      stops: [
        for (final s in (j['stops'] as List?) ?? const [])
          if (s.toString().trim().isNotEmpty) s.toString().trim(),
      ],
    );
  }
}

/// এক লাইন — কোন কাগজে কত (ক্রেডিটে ঋণাত্মক)
typedef CollectionLine = ({String kind, String id, String amount});

/// যা পাঠানো হবে — সার্ভারের [CollectionSync] এই ঘরগুলোই পড়ে
class CollectionDraft {
  const CollectionDraft({
    required this.customerId,
    required this.amount,
    required this.date,
    this.accountId,
    this.instrument,
    this.instrumentNo,
    this.narration,
    this.confirm = true,
    this.method,
    this.valueDate,
    this.bankCharge,
    this.vds,
    this.ait,
    this.lines,
    this.difference,
  });

  final String customerId;

  /// দুই দশমিক পর্যন্ত, লেখা যেমন — সার্ভার কঠোরভাবে মাপে
  final String amount;
  final DateTime date;
  final String? accountId;

  /// মাধ্যম — পুরনো সার্ভারও পড়ে (ট্রান্সফারে মোড, যেমন NPSB; চেকে "cheque")
  final String? instrument;

  /// রেফারেন্স / TrxID — নতুন সার্ভারে `reference`, পুরনোতে `instrumentNo`; দুটোতেই একই লেখা
  final String? instrumentNo;
  final String? narration;

  /// "পোস্ট" (true) নাকি "খসড়া রাখুন" (false) — সই লাগলে সার্ভার সইয়ে পাঠায়, ওয়েবের একই নিয়মে
  final bool confirm;

  /// cash · mfs · bank_transfer · cash_deposit · cheque — ওয়েবের পদ্ধতি
  final String? method;
  final DateTime? valueDate;

  /// "0.00" ধরনের লেখা; খালি বা শূন্যে পাঠানো হয় না
  final String? bankCharge;

  /// গ্রাহক কেটে রেখেছে: উৎসে ভ্যাট আর উৎসে কর
  final String? vds;
  final String? ait;

  /// ⓘ null = বিল সার্ভার নিজে বসায়, পুরনো বিল আগে (পুরনো সার্ভার, বা খোলা বিল আনা যায়নি)
  final List<CollectionLine>? lines;

  /// ছাড় বা রাউন্ডিং — `kind`: discount · rounding
  final ({String kind, String amount})? difference;

  static String _day(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  static bool _some(String? v) => v != null && paisaOf(v) > 0;

  Map<String, dynamic> toPayload() => {
        'customerId': customerId,
        'amount': amount,
        'trxDate': _day(date),
        if (accountId != null) 'accountId': accountId,
        if ((instrument ?? '').trim().isNotEmpty)
          'instrument': instrument!.trim(),
        if ((instrumentNo ?? '').trim().isNotEmpty) ...{
          'instrumentNo': instrumentNo!.trim(),
          'reference': instrumentNo!.trim(),
        },
        if ((narration ?? '').trim().isNotEmpty) 'narration': narration!.trim(),
        'confirm': confirm,
        if (method != null) 'method': method,
        if (valueDate != null) 'value_date': _day(valueDate!),
        if (_some(bankCharge)) 'bank_charge': bankCharge,
        if (_some(vds)) 'vds': vds,
        if (_some(ait)) 'ait': ait,
        if (lines != null) 'lines': [for (final l in lines!) {'kind': l.kind, 'id': l.id, 'amount': l.amount}],
        if (difference != null && _some(difference!.amount))
          'difference': {'kind': difference!.kind, 'amount': difference!.amount},
      };
}

/// ⭐ বসে যাওয়া আদায় — নতুন সার্ভার নম্বর, অবস্থা, কথায় টাকা আর রসিদ দেয়; পুরনো সার্ভারে কেবল আইডি
class CollectionSaved {
  const CollectionSaved({required this.id, this.no, this.state, this.amountWords, this.receipt, this.applied = const []});

  final String id;
  final String? no;

  /// paper_states-এর চাবি (draft · awaiting · posted …)
  final String? state;
  final String? amountWords;

  /// রসিদের PDF-এর পথ (`receipt_pdf`)
  final String? receipt;

  /// (নম্বর, পয়সা)
  final List<(String, int)> applied;

  /// ⓘ নম্বর আর অবস্থা দুটোই এলে নতুন পথ; নইলে আজকের মতো ([[MoneyInScreen]])
  bool get rich => (no ?? '').isNotEmpty && (state ?? '').isNotEmpty;

  factory CollectionSaved.of(String id, Map<String, dynamic> j) {
    String? text(String k) {
      final v = j[k]?.toString().trim() ?? '';
      return v.isEmpty ? null : v;
    }

    return CollectionSaved(
      id: id,
      no: text('no'),
      state: text('state'),
      amountWords: text('amount_words'),
      receipt: text('receipt_pdf') ?? text('receipt'),
      applied: [
        for (final l in (j['applied'] as List?) ?? const [])
          if (l is Map) (l['no']?.toString() ?? '', paisaOf(l['amount'])),
      ],
    );
  }
}

/// ⓘ এই দরজা এই সার্ভারে নেই — 404 বা 405 (পুরনো সার্ভার)
bool isDoorMissing(Object error) =>
    error is DioException && const {404, 405}.contains(error.response?.statusCode);

abstract class CollectionEntryApi {
  /// সার্ভারের তালিকা; নেট না থাকলে শেষবার পাওয়া তালিকা
  Future<List<CollectionAccount>> accounts();

  /// ⭐ বিষয় ১৮ — যাঁদের কাছ থেকে নেওয়া যায়; SR-এর ফোনে কেবল তাঁর নিজের ডিলার (সার্ভারের দেয়াল, ফোনের ক্যাশে)
  List<CustomerRecord> customers();

  /// খোলা বিল আর ক্রেডিট; ⛔ পুরনো সার্ভারে null (404/405) — পর্দা তখনো চলে
  Future<OpenBills?> openBills(String customerId);

  /// খাতার প্রিভিউ; ⛔ দরজা না থাকলে null
  Future<CollectionPreview?> preview(CollectionDraft draft);

  /// এখনই সার্ভারে — [changeId] একই কাজে একই থাকে। নেট না থাকলে [NoNetworkForThis]।
  Future<PushOutcome> send(CollectionDraft draft, String changeId);

  /// স্লিপের ছবি, বসার পরে
  Future<void> attachSlip(String collectionId, String path);
}

class ServerCollectionEntryApi implements CollectionEntryApi {
  const ServerCollectionEntryApi();

  static const _cacheType = 'CollectionSetup';
  static const _cacheId = 'accounts';

  @override
  Future<List<CollectionAccount>> accounts() async {
    await ReferenceCache.instance.init();
    try {
      final response = await ApiClient.dio
          .get<Map<String, dynamic>>('/sales/collections/setup');
      final list = [
        for (final a in (response.data?['accounts'] as List?) ?? const [])
          if (a is Map)
            CollectionAccount.fromJson(Map<String, dynamic>.from(a)),
      ];
      await ReferenceCache.instance.put(
        entityType: _cacheType,
        entityId: _cacheId,
        payload: {
          'accounts': [for (final a in list) a.toJson()]
        },
        updatedAt: DateTime.now(),
      );
      return list;
    } catch (_) {
      final cached = ReferenceCache.instance.get(_cacheType, _cacheId);
      if (cached == null) rethrow;
      return [
        for (final a in (cached['accounts'] as List?) ?? const [])
          if (a is Map)
            CollectionAccount.fromJson(Map<String, dynamic>.from(a)),
      ];
    }
  }

  @override
  List<CustomerRecord> customers() => CustomerRecord.all();

  @override
  Future<OpenBills?> openBills(String customerId) async {
    try {
      final response = await ApiClient.dio.get<Map<String, dynamic>>('/sales/collections/open-bills',
          queryParameters: {'customer': customerId});
      return OpenBills.fromJson(response.data ?? const {});
    } catch (e) {
      if (isDoorMissing(e)) return null;
      rethrow;
    }
  }

  @override
  Future<CollectionPreview?> preview(CollectionDraft draft) async {
    try {
      final response =
          await ApiClient.dio.post<Map<String, dynamic>>('/sales/collections/preview', data: draft.toPayload());
      return CollectionPreview.fromJson(response.data ?? const {});
    } catch (e) {
      if (isDoorMissing(e)) return null;
      rethrow;
    }
  }

  @override
  Future<PushOutcome> send(CollectionDraft draft, String changeId) =>
      SyncEngine.instance.pushNow(
        module: 'sales',
        entityType: 'Collection',
        changeId: changeId,
        payload: draft.toPayload(),
      );

  @override
  Future<void> attachSlip(String collectionId, String path) async {
    final form = FormData.fromMap({
      'file': await MultipartFile.fromFile(path, filename: path.split(RegExp(r'[\\/]')).last),
    });
    await ApiClient.dio.post<Map<String, dynamic>>('/sales/collections/$collectionId/attachment', data: form);
  }
}
