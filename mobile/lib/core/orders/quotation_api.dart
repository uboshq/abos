import '../api_client/api_client.dart';
import '../records/money.dart';
import '../records/customer_record.dart';

/// ⭐ উদ্ধৃতি (কোটেশন) — `/sales/quotations` (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)।
///
/// <p>ওয়েবের একই সেবা আর যাচাই: দর শূন্য নয়, জমায় সইয়ের ছক, পাঠানো, দোকানির উত্তর, আদেশে রূপান্তর। কোন বোতাম দেখাবে
/// সার্ভার বলে (`can`) — ফোন নিজে অবস্থা আর চাবি মেলায় না।
class QuotationLine {
  const QuotationLine({required this.product, required this.qty, required this.rate, required this.amount});

  final String product;
  final double qty;
  final double rate;
  final double amount;

  factory QuotationLine.fromJson(Map<String, dynamic> json) => QuotationLine(
        product: (json['product'] is Map ? json['product']['name'] : null)?.toString() ?? '—',
        qty: Money.valueOrZero(json['qty']),
        rate: Money.valueOrZero(json['rate']),
        amount: Money.valueOrZero(json['amount']),
      );
}

class Quotation {
  const Quotation({
    required this.id,
    required this.no,
    this.date,
    this.validUntil,
    this.customer,
    required this.status,
    required this.statusLabel,
    required this.total,
    this.can = const {},
    this.orderNo,
    this.lines = const [],
  });

  final String id;
  final String no;
  final String? date;
  final String? validUntil;
  final String? customer;
  final String status;
  final String statusLabel;
  final double total;

  /// `submit`, `send`, `answer`, `convert` — সার্ভারের হিসাবে এখন কোন কাজ খোলা।
  final Map<String, bool> can;

  /// রূপান্তরের পরে আদেশের নম্বর।
  final String? orderNo;
  final List<QuotationLine> lines;

  bool may(String action) => can[action] == true;

  factory Quotation.fromJson(Map<String, dynamic> json) => Quotation(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '—',
        date: json['date']?.toString(),
        validUntil: json['valid_until']?.toString(),
        customer: json['customer'] is Map ? withPointOrNull(json['customer']['name']?.toString(), json['customer']['point']) : null,
        status: json['status']?.toString() ?? '',
        statusLabel: json['status_label']?.toString() ?? json['status']?.toString() ?? '',
        total: Money.valueOrZero(json['total']),
        can: {
          if (json['can'] is Map)
            for (final e in (json['can'] as Map).entries) e.key.toString(): e.value == true,
        },
        orderNo: (json['order'] is Map ? json['order']['no'] : null)?.toString(),
        lines: [
          for (final row in (json['lines'] as List?) ?? const [])
            if (row is Map) QuotationLine.fromJson(Map<String, dynamic>.from(row)),
        ],
      );
}

/// একটা চাওয়া লাইন — পণ্যের public id, পরিমাণ, আর দর (না দিলে পণ্যের দাম)।
class QuotedLine {
  const QuotedLine(this.productId, this.qty, this.rate);

  final String productId;
  final int qty;
  final double? rate;
}

abstract class QuotationApi {
  Future<(List<Quotation>, int?)> list({String? status, int page = 1});
  Future<Quotation> show(String id);
  Future<Quotation> create({required String customerId, required List<QuotedLine> lines, required bool submit, String? note});

  /// `submit`, `send`, `accept`, `reject`, `convert` — `note` কেবল ফেরতে বাধ্যতামূলক।
  Future<Quotation> act(String id, String action, {String? note});
}

class ServerQuotationApi implements QuotationApi {
  const ServerQuotationApi();

  static const _base = '/sales/quotations';

  @override
  Future<(List<Quotation>, int?)> list({String? status, int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(_base, queryParameters: {
      if (status != null) 'status': status,
      if (page > 1) 'page': page,
    });
    final data = response.data ?? const {};
    return (
      [
        for (final row in (data['quotations'] as List?) ?? const [])
          if (row is Map) Quotation.fromJson(Map<String, dynamic>.from(row)),
      ],
      (data['next_page'] as num?)?.toInt(),
    );
  }

  @override
  Future<Quotation> show(String id) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('$_base/$id');
    return Quotation.fromJson(response.data ?? const {});
  }

  @override
  Future<Quotation> create({required String customerId, required List<QuotedLine> lines, required bool submit, String? note}) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>(_base, data: {
      'customer': customerId,
      'lines': [
        for (final l in lines) {'product': l.productId, 'qty': l.qty, if (l.rate != null) 'rate': l.rate!.toStringAsFixed(2)},
      ],
      if (note != null && note.trim().isNotEmpty) 'narration': note.trim(),
      'submit': submit,
    });
    return Quotation.fromJson(response.data ?? const {});
  }

  @override
  Future<Quotation> act(String id, String action, {String? note}) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>('$_base/$id/$action', data: {
      if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
    });
    return Quotation.fromJson(response.data ?? const {});
  }
}
