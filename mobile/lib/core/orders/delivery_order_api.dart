import '../api_client/api_client.dart';
import '../approvals/approvals_api.dart';
import '../records/money.dart';

/// ডেলিভারি অর্ডার (DO) — `/sales/delivery-orders` (0.4.8, মালিকের বিক্রয়-ধারা §২ক-খ)।
///
/// <p>⭐ SR আর তাঁর উপরের সবাই লেখেন ও জমা দেন; সুপারভাইজার মজুদ দেখে পরিমাণ কমান, তারপর সই — অনুমোদন-বাক্সের
/// একই দরজায় ([[ApprovalsApi]]), আলাদা কোনো সই-পথ নয়। দাম পণ্যের, ফোন দাম পাঠায় না। সব নিয়ম সার্ভারের।
class DeliveryOrderLine {
  const DeliveryOrderLine({
    required this.id,
    required this.product,
    required this.qty,
    required this.approvedQty,
    required this.finalQty,
    required this.lineTotal,
  });

  final int id;
  final String product;
  final double qty;
  final double? approvedQty;
  final double finalQty;
  final double lineTotal;

  factory DeliveryOrderLine.fromJson(Map<String, dynamic> json) => DeliveryOrderLine(
        id: (json['id'] as num?)?.toInt() ?? 0,
        product: (json['product'] is Map ? json['product']['name'] : null)?.toString() ?? '—',
        qty: Money.valueOrZero(json['qty']),
        approvedQty: json['approved_qty'] == null ? null : Money.valueOrZero(json['approved_qty']),
        finalQty: Money.valueOrZero(json['final_qty']),
        lineTotal: Money.valueOrZero(json['line_total']),
      );
}

class DeliveryOrder {
  const DeliveryOrder({
    required this.id,
    required this.no,
    required this.date,
    required this.customer,
    required this.status,
    required this.statusLabel,
    required this.total,
    required this.editable,
    required this.awaitingMe,
    this.approvalId,
    this.lines = const [],
  });

  final String id;
  final String no;
  final String? date;
  final String? customer;
  final String status;
  final String statusLabel;
  final double total;

  /// লেখক নিজে, আর এখনো খসড়া — তবেই "জমা দিন"।
  final bool editable;

  /// এখনকার স্তরের সই আমার হাতে — তবেই পরিমাণের ঘর আর সইয়ের বোতাম।
  final bool awaitingMe;

  /// সইয়ের দরজা — `/approvals/{id}/approve|reject`; আমার হাতে না থাকলে null।
  final String? approvalId;

  final List<DeliveryOrderLine> lines;

  factory DeliveryOrder.fromJson(Map<String, dynamic> json) => DeliveryOrder(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '—',
        date: json['date']?.toString(),
        customer: (json['customer'] is Map ? json['customer']['name'] : null)?.toString(),
        status: json['status']?.toString() ?? '',
        statusLabel: json['status_label']?.toString() ?? json['status']?.toString() ?? '',
        total: Money.valueOrZero(json['total']),
        editable: json['editable'] == true,
        awaitingMe: json['awaiting_me'] == true,
        approvalId: json['approval_id']?.toString(),
        lines: [
          for (final row in (json['lines'] as List?) ?? const [])
            if (row is Map) DeliveryOrderLine.fromJson(Map<String, dynamic>.from(row)),
        ],
      );
}

/// একটা চাওয়া — পণ্যের public id আর পরিমাণ; দাম নয়।
class WantedLine {
  const WantedLine(this.productId, this.qty);

  final String productId;
  final int qty;
}

abstract class DeliveryOrderApi {
  /// `awaitingMe` সত্যি হলে কেবল যেগুলো এখন আমার সইয়ের অপেক্ষায়।
  Future<List<DeliveryOrder>> list({bool awaitingMe = false});

  Future<DeliveryOrder> show(String id);

  Future<DeliveryOrder> create({required String customerId, required List<WantedLine> lines, required bool submit, String? note});

  Future<DeliveryOrder> submit(String id);

  /// সুপারভাইজারের পরিমাণ — লাইনের id → নতুন পরিমাণ (০ থেকে চাওয়া পর্যন্ত; সার্ভার যাচাই করে)।
  Future<DeliveryOrder> setQuantities(String id, Map<int, int> qtyByLine);

  Future<void> approve(String approvalId);

  Future<void> reject(String approvalId, String reason);
}

class ServerDeliveryOrderApi implements DeliveryOrderApi {
  const ServerDeliveryOrderApi();

  static const _base = '/sales/delivery-orders';

  @override
  Future<List<DeliveryOrder>> list({bool awaitingMe = false}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(_base, queryParameters: {
      if (awaitingMe) 'scope': 'awaiting_me',
    });
    return [
      for (final row in (response.data?['orders'] as List?) ?? const [])
        if (row is Map) DeliveryOrder.fromJson(Map<String, dynamic>.from(row)),
    ];
  }

  @override
  Future<DeliveryOrder> show(String id) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('$_base/$id');
    return DeliveryOrder.fromJson(response.data ?? const {});
  }

  @override
  Future<DeliveryOrder> create({required String customerId, required List<WantedLine> lines, required bool submit, String? note}) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>(_base, data: {
      'customer': customerId,
      'lines': [for (final l in lines) {'product': l.productId, 'qty': l.qty}],
      if (note != null && note.trim().isNotEmpty) 'narration': note.trim(),
      'submit': submit,
    });
    return DeliveryOrder.fromJson(response.data ?? const {});
  }

  @override
  Future<DeliveryOrder> submit(String id) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>('$_base/$id/submit');
    return DeliveryOrder.fromJson(response.data ?? const {});
  }

  @override
  Future<DeliveryOrder> setQuantities(String id, Map<int, int> qtyByLine) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>('$_base/$id/approved-quantities', data: {
      'lines': {for (final e in qtyByLine.entries) '${e.key}': e.value},
    });
    return DeliveryOrder.fromJson(response.data ?? const {});
  }

  @override
  Future<void> approve(String approvalId) => ApprovalsApi.approve(approvalId);

  @override
  Future<void> reject(String approvalId, String reason) => ApprovalsApi.reject(approvalId, remarks: reason);
}
