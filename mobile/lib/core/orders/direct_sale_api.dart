import '../api_client/api_client.dart';
import '../widgets/confirm_overview_sheet.dart';

/// ফোনের কাউন্টার — `/sales/direct` (0.4.9, মালিক ৪ অক্টোবর ২০২৬: *"direct sales er counter banaw app e"*)।
///
/// <p>⛔ নিয়ম সব সার্ভারের — ওয়েবের কাউন্টারের একই যাচাই আর একই দরজা: লট বাধ্যতামূলক, শূন্য দর নয়, ছাড়ে মালিকের সই,
/// ঋণসীমা (খসড়ায় নয়), স্কিমের ফ্রি। টাকা আছে, তাই কেবল অনলাইনে — অফলাইন সারিতে কখনো নয়।
class CounterLot {
  const CounterLot({required this.id, required this.no, required this.expiry, required this.qty});

  final String id;
  final String no;
  final String expiry;
  final String qty;

  factory CounterLot.fromJson(Map<String, dynamic> json) => CounterLot(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '',
        expiry: json['expiry']?.toString() ?? '',
        qty: json['qty']?.toString() ?? '0',
      );
}

class CounterChoice {
  const CounterChoice(this.id, this.label, {this.kind = ''});

  final String id;
  final String label;

  /// টাকার খাতে: cash · bank · mobile
  final String kind;
}

class CounterSetup {
  const CounterSetup({
    required this.warehouseId,
    required this.warehouses,
    required this.paymentTerms,
    required this.moneyAccounts,
    required this.lots,
  });

  final String? warehouseId;
  final List<CounterChoice> warehouses;

  /// `value` = cash · cod · credit:N · month_end · fixed — সার্ভারের নিজের তালিকা
  final List<CounterChoice> paymentTerms;
  final List<CounterChoice> moneyAccounts;

  /// পণ্যের public id → লট (FEFO ক্রমে); তালিকায় না থাকা পণ্য লট-ধরা নয়
  final Map<String, List<CounterLot>> lots;

  factory CounterSetup.fromJson(Map<String, dynamic> json) => CounterSetup(
        warehouseId: (json['warehouse'] is Map ? json['warehouse']['id'] : null)?.toString(),
        warehouses: [
          for (final w in (json['warehouses'] as List?) ?? const [])
            if (w is Map) CounterChoice(w['id'].toString(), w['name']?.toString() ?? ''),
        ],
        paymentTerms: [
          for (final t in (json['paymentTerms'] as List?) ?? const [])
            if (t is Map) CounterChoice(t['value'].toString(), t['label']?.toString() ?? ''),
        ],
        moneyAccounts: [
          for (final a in (json['moneyAccounts'] as List?) ?? const [])
            if (a is Map) CounterChoice(a['id'].toString(), a['label']?.toString() ?? '', kind: a['kind']?.toString() ?? ''),
        ],
        lots: {
          for (final e in ((json['lots'] as Map?) ?? const {}).entries)
            e.key.toString(): [
              for (final l in (e.value as List?) ?? const [])
                if (l is Map) CounterLot.fromJson(Map<String, dynamic>.from(l)),
            ],
        },
      );
}

/// কার্টের এক সারি — পণ্য, লট (লট-ধরা হলে), পরিমাণ, দর, ছাড়%, ফ্রি।
class CounterLine {
  const CounterLine({
    required this.productId,
    required this.productName,
    this.lotId,
    this.lotNo,
    required this.qty,
    required this.rate,
    this.discountPercent = 0,
    this.freeQty = 0,
  });

  final String productId;
  final String productName;
  final String? lotId;
  final String? lotNo;
  final int qty;
  final double rate;
  final double discountPercent;
  final int freeQty;

  double get total => qty * rate * (1 - discountPercent / 100);

  /// এক পণ্য-লট একবারই (মালিকের নিয়ম ৬) — একই চাবি মানে একই সারি
  String get key => '$productId|${lotId ?? ''}';

  Map<String, dynamic> toJson() => {
        'product': productId,
        if (lotId != null) 'lot': lotId,
        'qty': qty,
        'rate': rate.toStringAsFixed(2),
        if (discountPercent > 0) 'discount_percent': discountPercent.toStringAsFixed(2),
        if (freeQty > 0) 'free_qty': freeQty,
      };
}

class CounterResult {
  const CounterResult({required this.status, required this.notice, this.invoiceNo, this.challanNo});

  /// done · parked · held
  final String status;
  final String notice;
  final String? invoiceNo;
  final String? challanNo;

  factory CounterResult.fromJson(Map<String, dynamic> json) => CounterResult(
        status: json['status']?.toString() ?? '',
        notice: json['notice']?.toString() ?? '',
        invoiceNo: (json['invoice'] is Map ? json['invoice']['no'] : null)?.toString(),
        challanNo: (json['challan'] is Map ? json['challan']['no'] : null)?.toString(),
      );
}

abstract class DirectSaleApi {
  Future<CounterSetup> setup({String? warehouseId});

  /// স্কিমের ফ্রি কত — সার্ভার জানে না হলে null
  Future<int?> freeAllowed({required String productId, String? warehouseId, required int qty, String? lotId});

  Future<CounterResult> sell({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    required bool draft,
    String? depositAccountId,
    double deposit = 0,
    String? note,
  });

  /// নিশ্চিতের আগে সারাংশ — একই ঘর, কিছুই লেখা হয় না (মালিক, ৪ অক্টোবর ২০২৬)
  Future<ConfirmOverviewData> overview({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    String? depositAccountId,
    double deposit = 0,
    String? note,
  });
}

class ServerDirectSaleApi implements DirectSaleApi {
  const ServerDirectSaleApi();

  @override
  Future<CounterSetup> setup({String? warehouseId}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/sales/direct/setup', queryParameters: {
      if (warehouseId != null) 'warehouse': warehouseId,
    });
    return CounterSetup.fromJson(response.data ?? const {});
  }

  @override
  Future<int?> freeAllowed({required String productId, String? warehouseId, required int qty, String? lotId}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/sales/direct/free-allowed', queryParameters: {
      'product': productId,
      if (warehouseId != null) 'warehouse': warehouseId,
      'qty': qty,
      if (lotId != null) 'lot': lotId,
    });
    final allowed = response.data?['allowed'];
    return allowed == null ? null : double.tryParse(allowed.toString())?.floor();
  }

  /// ⓘ বিক্রি আর সারাংশ — একই ঘর; "credit:30" → শর্ত credit, মেয়াদ ৩০ দিন (ওয়েবের কাউন্টারের মতোই)
  static Map<String, dynamic> _payload({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    required bool draft,
    String? depositAccountId,
    double deposit = 0,
    String? note,
  }) {
    final parts = paymentTerm.split(':');
    return {
      'customer': customerId,
      if (warehouseId != null) 'warehouse': warehouseId,
      'payment_term': parts.first,
      if (parts.length > 1) 'credit_period_days': int.tryParse(parts[1]),
      'save_as_draft': draft ? '1' : '0',
      'lines': [for (final l in lines) l.toJson()],
      if (depositAccountId != null && deposit > 0)
        'deposits': [
          {'account': depositAccountId, 'amount': deposit.toStringAsFixed(2)},
        ],
      if (note != null && note.trim().isNotEmpty) 'narration': note.trim(),
    };
  }

  @override
  Future<CounterResult> sell({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    required bool draft,
    String? depositAccountId,
    double deposit = 0,
    String? note,
  }) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>('/sales/direct', data: _payload(
      customerId: customerId, warehouseId: warehouseId, paymentTerm: paymentTerm, lines: lines, draft: draft,
      depositAccountId: depositAccountId, deposit: deposit, note: note,
    ));
    return CounterResult.fromJson(response.data ?? const {});
  }

  @override
  Future<ConfirmOverviewData> overview({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    String? depositAccountId,
    double deposit = 0,
    String? note,
  }) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>('/sales/direct/overview', data: _payload(
      customerId: customerId, warehouseId: warehouseId, paymentTerm: paymentTerm, lines: lines, draft: false,
      depositAccountId: depositAccountId, deposit: deposit, note: note,
    ));
    return ConfirmOverviewData.fromJson(response.data ?? const {});
  }
}
