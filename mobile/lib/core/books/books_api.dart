import '../api_client/api_client.dart';
import '../records/money.dart';

/// ⭐ টাকা আদায়, প্রিন্সিপালের তালিকা আর ক্রয়ের তালিকা — কেবল পড়া (মালিক, ৬ অক্টোবর ২০২৬: "অ্যাপে payment received,
/// principal list আর purchase list দরকার")।
///
/// <p>সার্ভারের দরজা: `/sales/collections`, `/purchase/principals`, `/purchase/purchases`। সব দেয়াল সার্ভারের — চাবি,
/// শাখা, SR-এর নিজের ডিলার, কেনা দর; ফোন কেবল দেখায়। টাকা সার্ভারের চার ঘরের লেখা, এখানে কেবল দেখানোর জন্য সংখ্যা।
class MoneyInRow {
  const MoneyInRow({
    required this.id,
    required this.no,
    required this.date,
    required this.customer,
    required this.account,
    required this.method,
    required this.amount,
  });

  final String id;
  final String no;
  final String? date;
  final String customer;
  final String account;

  /// cash · bank · mfs · cheque · other
  final String method;
  final double amount;

  factory MoneyInRow.fromJson(Map<String, dynamic> j) => MoneyInRow(
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        date: j['date']?.toString(),
        customer: j['customer']?.toString() ?? '',
        account: j['account']?.toString() ?? '',
        method: j['method']?.toString() ?? 'other',
        amount: Money.valueOrZero(j['amount']),
      );

  static const methods = ['cash', 'bank', 'mfs', 'cheque'];

  static String methodLabel(String method) => switch (method) {
        'cash' => 'নগদ',
        'bank' => 'ব্যাংক',
        'mfs' => 'MFS',
        'cheque' => 'চেক',
        _ => 'অন্য',
      };
}

class MoneyInPage {
  const MoneyInPage(
      {required this.rows,
      required this.total,
      required this.count,
      this.nextPage});

  final List<MoneyInRow> rows;
  final double total;
  final int count;
  final int? nextPage;
}

class MoneyInDetail {
  const MoneyInDetail({
    required this.row,
    this.instrument = '',
    this.instrumentNo = '',
    this.instrumentDate,
    this.narration = '',
    this.by = '',
    this.status = '',
    this.lines = const [],
  });

  final MoneyInRow row;
  final String instrument;
  final String instrumentNo;
  final String? instrumentDate;
  final String narration;
  final String by;

  /// ⭐ পাকা (`confirmed`) নাকি খসড়া — অনুমোদন বা নিশ্চিতের অপেক্ষায় (ফোনের আদায়ের রসিদ, ৭ অক্টোবর ২০২৬); পুরনো সার্ভারে ''
  final String status;

  String get statusLabel => switch (status) {
        'confirmed' || 'closed' => 'পাকা — খাতায় উঠেছে',
        'draft' => 'অপেক্ষায় — অনুমোদন বা নিশ্চিত বাকি',
        'cancelled' => 'বাতিল',
        _ => '',
      };

  /// কোন বিলে কত — (বিলের নম্বর, টাকা)
  final List<(String, double)> lines;
}

/// একজন প্রিন্সিপাল — জের ধনাত্মক মানে আমাদের দিতে হবে, ঋণাত্মক মানে আমরা পাব (ওয়েবের সরবরাহকারীর পাতার কথা)
class PrincipalRow {
  const PrincipalRow({
    required this.id,
    required this.name,
    this.fullName = '',
    this.phone = '',
    required this.balance,
    this.lastPurchaseOn,
  });

  final String id;
  final String name;
  final String fullName;
  final String phone;
  final double balance;
  final String? lastPurchaseOn;

  factory PrincipalRow.fromJson(Map<String, dynamic> j) => PrincipalRow(
        id: j['id']?.toString() ?? '',
        name: j['name']?.toString() ?? '',
        fullName: j['full_name']?.toString() ?? '',
        phone: j['phone']?.toString() ?? '',
        balance: Money.valueOrZero(j['balance']),
        lastPurchaseOn: j['last_purchase_on']?.toString(),
      );

  String get balanceLabel => balanceWords(balance);

  static String balanceWords(double value) {
    if (value.abs() < 0.005) return 'জের নেই';
    return value > 0
        ? 'দিতে হবে: ${Money.taka(value)}'
        : 'পাব: ${Money.taka(-value)}';
  }
}

class LedgerLine {
  const LedgerLine({
    this.date,
    this.no = '',
    this.narration = '',
    required this.debit,
    required this.credit,
    required this.balance,
  });

  final String? date;
  final String no;
  final String narration;
  final double debit;
  final double credit;
  final double balance;
}

class PrincipalLedger {
  const PrincipalLedger(
      {required this.principal, required this.entries, this.nextPage});

  final PrincipalRow principal;
  final List<LedgerLine> entries;
  final int? nextPage;
}

class PurchaseRow {
  const PurchaseRow({
    required this.kind,
    required this.id,
    required this.no,
    this.date,
    required this.principal,
    required this.statusLabel,
    required this.total,
    this.paid,
    this.due,
  });

  /// bill · receipt
  final String kind;
  final String id;
  final String no;
  final String? date;
  final String principal;
  final String statusLabel;
  final double total;

  /// কেবল বিলে
  final double? paid;
  final double? due;

  factory PurchaseRow.fromJson(Map<String, dynamic> j) => PurchaseRow(
        kind: j['kind']?.toString() ?? 'bill',
        id: j['id']?.toString() ?? '',
        no: j['no']?.toString() ?? '',
        date: j['date']?.toString(),
        principal: j['principal']?.toString() ?? '',
        statusLabel: j['status_label']?.toString() ?? '',
        total: Money.valueOrZero(j['total']),
        paid: j.containsKey('paid') ? Money.valueOrZero(j['paid']) : null,
        due: j.containsKey('due') ? Money.valueOrZero(j['due']) : null,
      );
}

class PurchasePage {
  const PurchasePage(
      {required this.rows,
      required this.total,
      required this.count,
      this.nextPage});

  final List<PurchaseRow> rows;
  final double total;
  final int count;
  final int? nextPage;
}

class PurchaseLine {
  const PurchaseLine({
    required this.product,
    required this.qty,
    this.free = 0,
    this.batch = '',
    this.rate,
    this.amount,
  });

  final String product;
  final double qty;
  final double free;
  final String batch;

  /// ⛔ কেনা দর আর অঙ্ক — খরচ দেখার চাবি ছাড়া সার্ভার পাঠায় না; null মানে "দেখার অনুমতি নেই"
  final double? rate;
  final double? amount;
}

class PurchaseDetail {
  const PurchaseDetail(
      {required this.row,
      this.supplierNo = '',
      this.narration = '',
      this.lines = const []});

  final PurchaseRow row;
  final String supplierNo;
  final String narration;
  final List<PurchaseLine> lines;
}

abstract class BooksApi {
  Future<MoneyInPage> moneyIn(
      {required String from,
      required String to,
      String? q,
      String? method,
      int page = 1});

  Future<MoneyInDetail> moneyInDetail(String id);

  Future<(List<PrincipalRow>, int?)> principals({String? q, int page = 1});

  Future<PrincipalLedger> principal(String id, {int page = 1});

  Future<PurchasePage> purchases(
      {required String kind,
      required String from,
      required String to,
      String? principal,
      int page = 1});

  Future<PurchaseDetail> purchase(String kind, String id);
}

class ServerBooksApi implements BooksApi {
  const ServerBooksApi();

  static Future<Map<String, dynamic>> _get(
      String path, Map<String, dynamic> query) async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>(path, queryParameters: {
      for (final e in query.entries)
        if (e.value != null && e.value.toString().trim().isNotEmpty)
          e.key: e.value,
    });
    return response.data ?? const <String, dynamic>{};
  }

  static List<Map<String, dynamic>> _rows(Object? list) => [
        if (list is List)
          for (final r in list)
            if (r is Map) Map<String, dynamic>.from(r),
      ];

  static int? _next(Object? value) => (value as num?)?.toInt();

  @override
  Future<MoneyInPage> moneyIn(
      {required String from,
      required String to,
      String? q,
      String? method,
      int page = 1}) async {
    final body = await _get('/sales/collections',
        {'from': from, 'to': to, 'q': q, 'method': method, 'page': page});
    return MoneyInPage(
      rows: _rows(body['rows']).map(MoneyInRow.fromJson).toList(),
      total: Money.valueOrZero(body['total']),
      count: (body['count'] as num?)?.toInt() ?? 0,
      nextPage: _next(body['next_page']),
    );
  }

  @override
  Future<MoneyInDetail> moneyInDetail(String id) async {
    final body = await _get('/sales/collections/$id', const {});
    return MoneyInDetail(
      row: MoneyInRow.fromJson(body),
      instrument: body['instrument']?.toString() ?? '',
      instrumentNo: body['instrument_no']?.toString() ?? '',
      instrumentDate: body['instrument_date']?.toString(),
      narration: body['narration']?.toString() ?? '',
      by: body['by']?.toString() ?? '',
      status: body['status']?.toString() ?? '',
      lines: [
        for (final l in _rows(body['lines']))
          (l['invoice']?.toString() ?? '', Money.valueOrZero(l['amount'])),
      ],
    );
  }

  @override
  Future<(List<PrincipalRow>, int?)> principals(
      {String? q, int page = 1}) async {
    final body = await _get('/purchase/principals', {'q': q, 'page': page});
    return (
      _rows(body['rows']).map(PrincipalRow.fromJson).toList(),
      _next(body['next_page'])
    );
  }

  @override
  Future<PrincipalLedger> principal(String id, {int page = 1}) async {
    final body = await _get('/purchase/principals/$id', {'page': page});
    return PrincipalLedger(
      principal: PrincipalRow.fromJson(body),
      entries: [
        for (final e in _rows(body['entries']))
          LedgerLine(
            date: e['date']?.toString(),
            no: e['no']?.toString() ?? '',
            narration: e['narration']?.toString() ?? '',
            debit: Money.valueOrZero(e['debit']),
            credit: Money.valueOrZero(e['credit']),
            balance: Money.valueOrZero(e['balance']),
          ),
      ],
      nextPage: _next(body['next_page']),
    );
  }

  @override
  Future<PurchasePage> purchases(
      {required String kind,
      required String from,
      required String to,
      String? principal,
      int page = 1}) async {
    final body = await _get('/purchase/purchases', {
      'kind': kind,
      'from': from,
      'to': to,
      'principal': principal,
      'page': page
    });
    return PurchasePage(
      rows: _rows(body['rows']).map(PurchaseRow.fromJson).toList(),
      total: Money.valueOrZero(body['total']),
      count: (body['count'] as num?)?.toInt() ?? 0,
      nextPage: _next(body['next_page']),
    );
  }

  @override
  Future<PurchaseDetail> purchase(String kind, String id) async {
    final body = await _get('/purchase/purchases/$kind/$id', const {});
    return PurchaseDetail(
      row: PurchaseRow.fromJson(body),
      supplierNo: body['supplier_no']?.toString() ?? '',
      narration: body['narration']?.toString() ?? '',
      lines: [
        for (final l in _rows(body['lines']))
          PurchaseLine(
            product: l['product']?.toString() ?? '',
            qty: Money.valueOrZero(l['qty']),
            free: Money.valueOrZero(l['free']),
            batch: l['batch']?.toString() ?? '',
            rate: l.containsKey('rate') ? Money.valueOrZero(l['rate']) : null,
            amount:
                l.containsKey('amount') ? Money.valueOrZero(l['amount']) : null,
          ),
      ],
    );
  }
}
