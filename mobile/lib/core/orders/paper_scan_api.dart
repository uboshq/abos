import '../api_client/api_client.dart';
import '../records/money.dart';

/// এক কাগজে এক QR — `GET /sales/scan/{token}`, `POST …/gate-out`, `POST …/deliver` (0.4.3)।
///
/// <p>QR-এ কেবল সই-করা টোকেন থাকে (`https://…/q/<38 অক্ষর>`), চালানের নম্বর বা দোকানের
/// নাম নয় — তাই কাগজটা কারো হাতে পড়লেও স্ক্যান করে কিছু জানা যায় না; দেখায় সার্ভার, লগইন আর
/// চাবি দেখে।
class PaperToken {
  const PaperToken._();

  static final RegExp _shape = RegExp(r'^[A-Za-z0-9_-]{38}$');

  /// QR-এর লেখা থেকে টোকেন — ঠিকানা হোক বা খালি টোকেন। না মিললে null।
  static String? from(String raw) {
    final text = raw.trim();
    if (_shape.hasMatch(text)) return text;
    final uri = Uri.tryParse(text);
    if (uri == null) return null;
    final segments = uri.pathSegments;
    final at = segments.lastIndexOf('q');
    if (at < 0 || at + 1 >= segments.length) return null;
    final token = segments[at + 1];
    return _shape.hasMatch(token) ? token : null;
  }

  /// পুরনো ছাপা কাগজ — `/scan/{public_id}`; ফোনে খোলে না, ওয়েবে খোলে।
  static bool isOldPaper(String raw) =>
      Uri.tryParse(raw.trim())?.pathSegments.contains('scan') ?? false;
}

class ScannedLine {
  const ScannedLine(
      {this.line = 0,
      required this.product,
      required this.qty,
      required this.freeQty,
      this.lot});

  /// সারির ক্রমিক — "আংশিক পৌঁছেছে"-তে সারি চেনার জন্য (সার্ভার ধাপ ৭, ৬ অক্টোবর ২০২৬); পুরনো সার্ভারে ০
  final int line;
  final String product;
  final double qty;
  final double freeQty;
  final String? lot;
}

/// সার্ভার যা বলল — কাগজটা আর এই মানুষটা কোন বোতাম পান।
class ScannedPaper {
  const ScannedPaper({
    required this.documentNo,
    required this.saleNo,
    required this.date,
    required this.stage,
    required this.customer,
    required this.lines,
    required this.transportNamed,
    required this.vehicle,
    required this.driver,
    required this.outAt,
    required this.outBy,
    required this.billTotal,
    required this.canGateOut,
    required this.canDeliver,
  });

  final String documentNo;
  final String? saleNo;
  final String? date;
  final String stage;
  final String customer;
  final List<ScannedLine> lines;
  final bool transportNamed;
  final String? vehicle;
  final String? driver;
  final DateTime? outAt;
  final String? outBy;

  /// null — এই মানুষের টাকা দেখার চাবি নেই।
  final double? billTotal;
  final bool canGateOut;
  final bool canDeliver;

  factory ScannedPaper.fromJson(Map<String, dynamic> json) {
    final customer = (json['customer'] as Map?) ?? const {};
    final transport = (json['transport'] as Map?) ?? const {};
    final out = json['gate_out'] as Map?;
    final actions = (json['actions'] as Map?) ?? const {};
    return ScannedPaper(
      documentNo: json['document_no']?.toString() ?? '—',
      saleNo: json['sale_no']?.toString(),
      date: json['trx_date']?.toString(),
      stage: json['stage']?.toString() ?? '',
      customer: [customer['name'], customer['code']]
          .where((v) => v != null && v.toString().isNotEmpty)
          .join(' · '),
      lines: [
        for (final row in (json['lines'] as List?) ?? const [])
          if (row is Map)
            ScannedLine(
              line: (row['line'] as num?)?.toInt() ?? 0,
              product: row['product']?.toString() ?? '—',
              qty: Money.valueOrZero(row['qty']),
              freeQty: Money.valueOrZero(row['free_qty']),
              lot: row['lot']?.toString(),
            ),
      ],
      transportNamed: transport['named'] == true,
      vehicle: transport['vehicle']?.toString(),
      driver: transport['driver']?.toString(),
      outAt: out == null
          ? null
          : DateTime.tryParse(out['at']?.toString() ?? '')?.toLocal(),
      outBy: out?['by']?.toString(),
      billTotal: json['bill_total'] == null
          ? null
          : Money.valueOrZero(json['bill_total']),
      canGateOut: actions['gate_out'] == true,
      canDeliver: actions['deliver'] == true,
    );
  }
}

abstract class PaperScanApi {
  Future<ScannedPaper> open(String token);

  Future<ScannedPaper> gateOut(String token);

  /// ⭐ পৌঁছানোর প্রমাণ — নাম আর ফোন; [taken] (ক্রমিক → ভালো অবস্থায় নিলেন) আর [damaged] (ক্রমিক → ভাঙা) দিলে
  /// আর কোথাও কম বা ভাঙা থাকলে সার্ভার "আংশিক পৌঁছেছে" লেখে (ধাপ ৭)
  Future<ScannedPaper> deliver(String token,
      {required String receiver,
      required String phone,
      List<ScannedLine> lines = const [],
      Map<int, double> taken = const {},
      Map<int, double> damaged = const {}});
}

/// যা পাঠানো হবে — সব পুরো আর ভাঙা নেই হলে কেবল নাম-ফোন ("পৌঁছেছে"); নাহলে সারিগুলোও ("আংশিক")
Map<String, dynamic> deliveryPayload(
    {required String receiver,
    required String phone,
    required List<ScannedLine> lines,
    Map<int, double> taken = const {},
    Map<int, double> damaged = const {}}) {
  final partial = lines.any(
      (l) => (taken[l.line] ?? l.qty) != l.qty || (damaged[l.line] ?? 0) > 0);
  String q(double v) => v.toStringAsFixed(4);
  return {
    'receiver_name': receiver,
    'receiver_phone': phone,
    if (partial)
      'lines': {for (final l in lines) '${l.line}': q(taken[l.line] ?? l.qty)},
    if (partial)
      'damaged': {
        for (final l in lines)
          if ((damaged[l.line] ?? 0) > 0) '${l.line}': q(damaged[l.line]!)
      },
  };
}

class ServerPaperScanApi implements PaperScanApi {
  const ServerPaperScanApi();

  @override
  Future<ScannedPaper> open(String token) async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/sales/scan/$token');
    return ScannedPaper.fromJson(response.data ?? const {});
  }

  @override
  Future<ScannedPaper> gateOut(String token) async {
    final response = await ApiClient.dio
        .post<Map<String, dynamic>>('/sales/scan/$token/gate-out');
    return ScannedPaper.fromJson(response.data ?? const {});
  }

  @override
  Future<ScannedPaper> deliver(String token,
      {required String receiver,
      required String phone,
      List<ScannedLine> lines = const [],
      Map<int, double> taken = const {},
      Map<int, double> damaged = const {}}) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>(
      '/sales/scan/$token/deliver',
      data: deliveryPayload(
          receiver: receiver,
          phone: phone,
          lines: lines,
          taken: taken,
          damaged: damaged),
    );
    return ScannedPaper.fromJson(response.data ?? const {});
  }
}
