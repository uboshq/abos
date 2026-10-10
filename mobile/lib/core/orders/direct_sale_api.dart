import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart' show visibleForTesting;

import '../api_client/api_client.dart';
import '../widgets/confirm_overview_sheet.dart';
import '../records/customer_record.dart';

/// ফোনের কাউন্টার — `/sales/direct` (0.4.9, মালিক ৪ অক্টোবর ২০২৬: *"direct sales er counter banaw app e"*)।
///
/// <p>⛔ নিয়ম সব সার্ভারের — ওয়েবের কাউন্টারের একই যাচাই আর একই দরজা: লট বাধ্যতামূলক, শূন্য দর নয়, ছাড়ে মালিকের সই,
/// ঋণসীমা (খসড়ায় নয়), স্কিমের ফ্রি। টাকা আছে, তাই কেবল অনলাইনে — অফলাইন সারিতে কখনো নয়।
class CounterLot {
  const CounterLot(
      {required this.id,
      required this.no,
      required this.expiry,
      required this.qty});

  final String id;
  final String no;
  final String expiry;
  final String qty;

  factory CounterLot.fromJson(Map<String, dynamic> json) => CounterLot(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '',
        expiry: json['expiry']?.toString() ?? '',
        // ⛔ মজুদ দেখার চাবি ছাড়া সার্ভার পরিমাণ পাঠায় না — তখন '' ("আছে ০" নয়)
        qty: json['qty']?.toString() ?? '',
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
    this.depositMethods = const [],
    this.carriers = const [],
    this.farePayers = const [],
    this.voidReasons = const [],
  });

  /// বিল বাতিলের কারণ — ওয়েবের পপ-আপের একই তালিকা
  final List<String> voidReasons;

  /// ⭐ টাকা নেওয়ার পদ্ধতি আর বাহক — ওয়েবের কাউন্টারের একই তালিকা (৪ অক্টোবর ২০২৬)
  final List<CounterMethod> depositMethods;
  final List<CounterChoice> carriers;

  /// ভাড়া কে দিলেন — ব্যাংক বা MFS-এ বাছার তালিকা (a5, ১০ অক্টোবর ২০২৬; সার্ভার 272141b9); নগদে সার্ভার নিজেই লগইন করা মানুষ
  final List<CounterChoice> farePayers;

  final String? warehouseId;
  final List<CounterChoice> warehouses;

  /// `value` = cash · cod · credit:N · month_end · fixed — সার্ভারের নিজের তালিকা
  final List<CounterChoice> paymentTerms;
  final List<CounterChoice> moneyAccounts;

  /// পণ্যের public id → লট (FEFO ক্রমে); তালিকায় না থাকা পণ্য লট-ধরা নয়
  final Map<String, List<CounterLot>> lots;

  factory CounterSetup.fromJson(Map<String, dynamic> json) => CounterSetup(
        warehouseId: (json['warehouse'] is Map ? json['warehouse']['id'] : null)
            ?.toString(),
        warehouses: [
          for (final w in (json['warehouses'] as List?) ?? const [])
            if (w is Map)
              CounterChoice(w['id'].toString(), w['name']?.toString() ?? ''),
        ],
        paymentTerms: [
          for (final t in (json['paymentTerms'] as List?) ?? const [])
            if (t is Map)
              CounterChoice(
                  t['value'].toString(), t['label']?.toString() ?? ''),
        ],
        moneyAccounts: [
          for (final a in (json['moneyAccounts'] as List?) ?? const [])
            if (a is Map)
              CounterChoice(a['id'].toString(), a['label']?.toString() ?? '',
                  kind: a['kind']?.toString() ?? ''),
        ],
        depositMethods: [
          for (final m in (json['depositMethods'] as List?) ?? const [])
            if (m is Map) CounterMethod.fromJson(Map<String, dynamic>.from(m)),
        ],
        voidReasons: [
          for (final r in (json['voidReasons'] as List?) ?? const [])
            r.toString(),
        ],
        farePayers: [
          for (final p in (json['farePayers'] as List?) ?? const [])
            if (p is Map) CounterChoice(p['id'].toString(), p['label']?.toString() ?? ''),
        ],
        carriers: [
          for (final c in (json['carriers'] as List?) ?? const [])
            if (c is Map)
              CounterChoice(c['id'].toString(), c['label']?.toString() ?? ''),
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

/// টাকা নেওয়ার একটা পদ্ধতি — নগদ, ব্যাংক, বিকাশ …; `accountId` থাকলে খাত নিজে বসে
class CounterMethod {
  const CounterMethod(
      {required this.id,
      required this.label,
      this.kind,
      this.accountId,
      this.needsReference = false});

  final String id;
  final String label;
  final String? kind;
  final String? accountId;
  final bool needsReference;

  factory CounterMethod.fromJson(Map<String, dynamic> json) => CounterMethod(
        id: json['id']?.toString() ?? '',
        label: json['label']?.toString() ?? '',
        kind: json['kind']?.toString(),
        accountId: json['account']?.toString(),
        needsReference: json['needsReference'] == true,
      );
}

/// ⭐ "টাকা নেওয়া" — এক বিলে কয়েক রকম টাকা: নগদ + ব্যাংক + বিকাশ (ওয়েবের কাউন্টারের মতো)
class CounterDeposit {
  const CounterDeposit(
      {required this.accountId,
      required this.amount,
      this.methodId,
      this.reference});

  final String accountId;
  final double amount;
  final String? methodId;
  final String? reference;

  Map<String, dynamic> toJson() => {
        'account': accountId,
        'amount': amount.toStringAsFixed(2),
        if (methodId != null) 'method': methodId,
        if (reference != null && reference!.trim().isNotEmpty)
          'reference': reference!.trim(),
      };
}

/// ⭐ "ডেলিভারি" আর "গাড়ি ও ভাড়া" — মাল কীভাবে যাবে, কার গাড়ি, ভাড়া কত আর কে দেবে (মালিক, ৪ অক্টোবর ২০২৬)
class CounterDelivery {
  const CounterDelivery({
    this.mode,
    this.shipTo,
    this.shipDate,
    this.vehicleOwner,
    this.carrierId,
    this.carrierName,
    this.fare = 0,
    this.farePaidBy,
    this.fareWhen,
    this.fareAccount,
    this.fareReference,
    this.farePayer,
  });

  /// take_now · send_later · pickup_later
  final String? mode;
  final String? shipTo;
  final String? shipDate;

  /// own · hired · customer · none
  final String? vehicleOwner;
  final String? carrierId;
  final String? carrierName;
  final double fare;

  /// us · us_add_to_bill · customer · none
  final String? farePaidBy;

  /// ⭐ ভাড়া আমরা দিলে — কখন (now · later), কোন খাত থেকে (moneyAccounts-এর id), লেনদেন নম্বর আর কে দিলেন (ব্যাংক বা
  /// MFS-এ) — মালিক, ৭ অক্টোবর ২০২৬: ভাড়া সব জায়গায় এক নিয়মে; Main Counter থেকে নিজে থেকে আর কাটে না (সার্ভার 272141b9)
  final String? fareWhen;
  final String? fareAccount;
  final String? fareReference;
  final String? farePayer;

  bool get wePayFare => farePaidBy == 'us' || farePaidBy == 'us_add_to_bill';

  /// একই ঘর, একটা বদলে — পপ-আপগুলো যার যার অংশ বদলায়, বাকিটা রাখে
  CounterDelivery copyWith({
    String? mode,
    String? shipTo,
    String? shipDate,
  }) =>
      CounterDelivery(
        mode: mode ?? this.mode,
        shipTo: shipTo,
        shipDate: shipDate,
        vehicleOwner: vehicleOwner,
        carrierId: carrierId,
        carrierName: carrierName,
        fare: fare,
        farePaidBy: farePaidBy,
        fareWhen: fareWhen,
        fareAccount: fareAccount,
        fareReference: fareReference,
        farePayer: farePayer,
      );

  bool get isEmpty =>
      mode == null &&
      vehicleOwner == null &&
      carrierId == null &&
      fare <= 0 &&
      farePaidBy == null;

  Map<String, dynamic> toJson() => {
        if (mode != null) 'delivery_mode': mode,
        if (mode == 'send_later' && shipTo != null) 'ship_to': shipTo,
        if (mode == 'send_later' && shipDate != null) 'ship_date': shipDate,
        if (vehicleOwner != null) 'vehicle_owner': vehicleOwner,
        // ⓘ ক্রেতার নিজের গাড়ি বা গাড়িই লাগবে না — পরিবহন আমাদের নয় (ওয়েবের `own_transport`)
        if (vehicleOwner == 'customer' || vehicleOwner == 'none')
          'own_transport': '1',
        if (carrierId != null) 'carrier': carrierId,
        if (carrierName != null && carrierName!.trim().isNotEmpty)
          'carrier_name': carrierName!.trim(),
        if (fare > 0) 'transport_cost': fare.toStringAsFixed(2),
        if (farePaidBy != null) 'fare_paid_by': farePaidBy,
        if (wePayFare && fareWhen != null) 'fare_when': fareWhen,
        if (wePayFare && fareWhen == 'now' && fareAccount != null) 'fare_account': fareAccount,
        if (wePayFare && fareWhen == 'now' && fareReference != null && fareReference!.trim().isNotEmpty)
          'fare_reference': fareReference!.trim(),
        if (wePayFare && fareWhen == 'now' && farePayer != null) 'fare_payer': farePayer,
      };
}

/// বিক্রির বাড়তি ঘর — জমা, ডেলিভারি, খোলা খসড়া, বিবরণ
class CounterExtras {
  const CounterExtras(
      {this.deposits = const [],
      this.delivery = const CounterDelivery(),
      this.resumeId,
      this.note,
      this.confirmDuplicate = false});

  final List<CounterDeposit> deposits;
  final CounterDelivery delivery;

  /// কাউন্টারে খোলা রাখা খসড়া — পাঠালে নতুন বিল নয়, এটাই পাকা হয়
  final String? resumeId;
  final String? note;

  /// ⭐ "জেনেশুনে আবার" — একই ক্রেতার একই বিল সার্ভার একবার আটকায়; মানুষ টিক দিলে তবেই এটা পাঠানো (ওয়েবের একই ঘর)
  final bool confirmDuplicate;

  CounterExtras repeating() => CounterExtras(
      deposits: deposits,
      delivery: delivery,
      resumeId: resumeId,
      note: note,
      confirmDuplicate: true);
}

/// ⭐ একই বিল দুবারের সতর্কতা — সার্ভারের ৪২২-এর `errors.confirm_duplicate` (ওয়েবের কাউন্টারের একই দেয়াল)।
/// অন্য যেকোনো ভুলে null: তখন ফোন সাধারণ বার্তাই দেখায়, "আবার করুন" টিক নয়।
String? duplicateWarning(Object error) {
  if (error is! DioException || error.response?.statusCode != 422) return null;
  final body = error.response?.data;
  final errors = body is Map ? body['errors'] : null;
  final field = errors is Map ? errors['confirm_duplicate'] : null;
  final first = field is List && field.isNotEmpty ? field.first : field;
  final text = first?.toString().trim() ?? '';
  return text.isEmpty ? null : text;
}

/// ⭐ "দাম দেখুন" — বিলে না তুলে দর, বিক্রয়যোগ্য মজুদ আর লট (ওয়েবের পপ-আপের একই উৎস)।
/// `available` null মানে মজুদ দেখার চাবি নেই — SR-এর ফোনে মজুদ নয় (মালিক, ১ অক্টোবর ২০২৬)।
class CounterPrice {
  const CounterPrice(
      {required this.name,
      required this.rate,
      this.unit = '',
      this.code = '',
      this.available,
      this.lots = const [],
      this.priceLabel = ''});

  final String name;
  final String rate;
  final String unit;
  final String code;
  final String? available;
  final List<CounterLot> lots;

  /// দামটা কোথা থেকে — সার্ভারের নিজের কথায় ("দামের তালিকা: …"); পুরনো সার্ভারে ফাঁকা
  final String priceLabel;

  factory CounterPrice.fromJson(Map<String, dynamic> json) => CounterPrice(
        name: json['name']?.toString() ?? '',
        rate: json['rate']?.toString() ?? '0',
        priceLabel: json['priceLabel']?.toString() ?? '',
        unit: json['unit']?.toString() ?? '',
        code: json['code']?.toString() ?? '',
        available: json['available']?.toString(),
        lots: [
          for (final l in (json['lots'] as List?) ?? const [])
            if (l is Map)
              CounterLot(
                id: l['id']?.toString() ?? '',
                no: l['no']?.toString() ?? '',
                expiry: l['expiry']?.toString() ?? '',
                qty: l['qty']?.toString() ?? '',
              ),
        ],
      );
}

/// রাখা খসড়ার তালিকার একটা সারি
class CounterDraftSummary {
  const CounterDraftSummary(
      {required this.id,
      required this.no,
      required this.customer,
      required this.total,
      this.date,
      this.lines = 0});

  final String id;
  final String no;
  final String customer;
  final String total;
  final String? date;
  final int lines;

  factory CounterDraftSummary.fromJson(Map<String, dynamic> json) =>
      CounterDraftSummary(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '',
        customer: withPoint(json['customer']?.toString() ?? '', json['customer_point']),
        total: json['total']?.toString() ?? '0',
        date: json['date']?.toString(),
        lines: (json['lines'] as num?)?.toInt() ?? 0,
      );
}

/// খোলা খসড়া — ক্রেতা আর সারি, কার্টে বসানোর জন্য
class CounterDraft {
  const CounterDraft(
      {required this.id,
      required this.no,
      required this.customerId,
      required this.customerName,
      required this.lines});

  final String id;
  final String no;
  final String customerId;
  final String customerName;
  final List<CounterLine> lines;

  factory CounterDraft.fromJson(Map<String, dynamic> json) => CounterDraft(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '',
        customerId: json['customer']?.toString() ?? '',
        customerName: withPoint(json['customerName']?.toString() ?? '', json['customerPoint']),
        lines: [
          for (final l in (json['lines'] as List?) ?? const [])
            if (l is Map)
              CounterLine(
                productId: l['product']?.toString() ?? '',
                productName: l['name']?.toString() ?? '',
                lotId: (l['lot']?.toString().isEmpty ?? true)
                    ? null
                    : l['lot'].toString(),
                lotNo: (l['lotNo']?.toString().isEmpty ?? true)
                    ? null
                    : l['lotNo'].toString(),
                qty: (double.tryParse(l['qty']?.toString() ?? '') ?? 0).round(),
                rate: double.tryParse(l['rate']?.toString() ?? '') ?? 0,
                discountPercent:
                    double.tryParse(l['discountPercent']?.toString() ?? '') ??
                        0,
                freeQty: (double.tryParse(l['freeQty']?.toString() ?? '') ?? 0)
                    .round(),
              ),
        ],
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
        if (discountPercent > 0)
          'discount_percent': discountPercent.toStringAsFixed(2),
        if (freeQty > 0) 'free_qty': freeQty,
      };
}

class CounterResult {
  const CounterResult(
      {required this.status,
      required this.notice,
      this.invoiceNo,
      this.challanNo,
      this.invoiceId});

  /// done · parked · held
  final String status;
  final String notice;
  final String? invoiceNo;
  final String? challanNo;

  /// বিলের public_id — "আবার ছাপা"-র জন্য
  final String? invoiceId;

  factory CounterResult.fromJson(Map<String, dynamic> json) => CounterResult(
        status: json['status']?.toString() ?? '',
        notice: json['notice']?.toString() ?? '',
        invoiceNo:
            (json['invoice'] is Map ? json['invoice']['no'] : null)?.toString(),
        challanNo:
            (json['challan'] is Map ? json['challan']['no'] : null)?.toString(),
        invoiceId:
            (json['invoice'] is Map ? json['invoice']['id'] : null)?.toString(),
      );
}

abstract class DirectSaleApi {
  Future<CounterSetup> setup({String? warehouseId});

  /// স্কিমের ফ্রি কত — সার্ভার জানে না হলে null
  Future<int?> freeAllowed(
      {required String productId,
      String? warehouseId,
      required int qty,
      String? lotId});

  Future<CounterResult> sell({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    required bool draft,
    CounterExtras extras = const CounterExtras(),
  });

  /// নিশ্চিতের আগে সারাংশ — একই ঘর, কিছুই লেখা হয় না (মালিক, ৪ অক্টোবর ২০২৬)
  Future<ConfirmOverviewData> overview({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    CounterExtras extras = const CounterExtras(),
  });

  /// রাখা খসড়া — খোলার জন্য
  Future<List<CounterDraftSummary>> drafts();

  /// দাম দেখুন — একটা পণ্যের দর, মজুদ আর লট; [customerId] দিলে সেই ক্রেতার দামের তালিকার দর
  Future<CounterPrice> price(String productId,
      {String? warehouseId, String? customerId});

  Future<CounterDraft> openDraft(String id);

  /// বিল বাতিল, কারণসহ — খোলা খসড়া হলে খসড়াটাই, নইলে না-জমা বিলটা অডিটে
  Future<void> voidBill(
      {required String reason,
      String? customerId,
      String? resumeId,
      int lines = 0,
      double total = 0});
}

class ServerDirectSaleApi implements DirectSaleApi {
  const ServerDirectSaleApi();

  @override
  Future<CounterSetup> setup({String? warehouseId}) async {
    final response = await ApiClient.dio
        .get<Map<String, dynamic>>('/sales/direct/setup', queryParameters: {
      if (warehouseId != null) 'warehouse': warehouseId,
    });
    return CounterSetup.fromJson(response.data ?? const {});
  }

  @override
  Future<int?> freeAllowed(
      {required String productId,
      String? warehouseId,
      required int qty,
      String? lotId}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
        '/sales/direct/free-allowed',
        queryParameters: {
          'product': productId,
          if (warehouseId != null) 'warehouse': warehouseId,
          'qty': qty,
          if (lotId != null) 'lot': lotId,
        });
    final allowed = response.data?['allowed'];
    return allowed == null
        ? null
        : double.tryParse(allowed.toString())?.floor();
  }

  /// ⓘ বিক্রি আর সারাংশ — একই ঘর; "credit:30" → শর্ত credit, মেয়াদ ৩০ দিন (ওয়েবের কাউন্টারের মতোই)
  @visibleForTesting
  static Map<String, dynamic> payload({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    required bool draft,
    CounterExtras extras = const CounterExtras(),
  }) {
    final parts = paymentTerm.split(':');
    final note = extras.note;
    return {
      'customer': customerId,
      if (warehouseId != null) 'warehouse': warehouseId,
      'payment_term': parts.first,
      if (parts.length > 1) 'credit_period_days': int.tryParse(parts[1]),
      'save_as_draft': draft ? '1' : '0',
      'lines': [for (final l in lines) l.toJson()],
      if (extras.deposits.isNotEmpty)
        'deposits': [for (final d in extras.deposits) d.toJson()],
      ...extras.delivery.toJson(),
      if (extras.resumeId != null) 'resume': extras.resumeId,
      if (note != null && note.trim().isNotEmpty) 'narration': note.trim(),
      if (extras.confirmDuplicate) 'confirm_duplicate': '1',
    };
  }

  @override
  Future<CounterResult> sell({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    required bool draft,
    CounterExtras extras = const CounterExtras(),
  }) async {
    final response =
        await ApiClient.dio.post<Map<String, dynamic>>('/sales/direct',
            data: payload(
              customerId: customerId,
              warehouseId: warehouseId,
              paymentTerm: paymentTerm,
              lines: lines,
              draft: draft,
              extras: extras,
            ));
    return CounterResult.fromJson(response.data ?? const {});
  }

  @override
  Future<ConfirmOverviewData> overview({
    required String customerId,
    String? warehouseId,
    required String paymentTerm,
    required List<CounterLine> lines,
    CounterExtras extras = const CounterExtras(),
  }) async {
    final response =
        await ApiClient.dio.post<Map<String, dynamic>>('/sales/direct/overview',
            data: payload(
              customerId: customerId,
              warehouseId: warehouseId,
              paymentTerm: paymentTerm,
              lines: lines,
              draft: false,
              extras: extras,
            ));
    return ConfirmOverviewData.fromJson(response.data ?? const {});
  }

  @override
  Future<List<CounterDraftSummary>> drafts() async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/sales/direct/drafts');
    return [
      for (final d in (response.data?['drafts'] as List?) ?? const [])
        if (d is Map)
          CounterDraftSummary.fromJson(Map<String, dynamic>.from(d)),
    ];
  }

  @override
  Future<CounterPrice> price(String productId,
      {String? warehouseId, String? customerId}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
        '/sales/direct/price/$productId',
        queryParameters: {
          if (warehouseId != null) 'warehouse': warehouseId,
          // ⭐ এই ক্রেতার দামের তালিকা (সার্ভার, ৫ অক্টোবর ২০২৬: [[SalesPrice]])
          if (customerId != null) 'customer': customerId,
        });
    return CounterPrice.fromJson(response.data ?? const {});
  }

  @override
  Future<CounterDraft> openDraft(String id) async {
    final response = await ApiClient.dio
        .get<Map<String, dynamic>>('/sales/direct/drafts/$id');
    return CounterDraft.fromJson(response.data ?? const {});
  }

  @override
  Future<void> voidBill(
      {required String reason,
      String? customerId,
      String? resumeId,
      int lines = 0,
      double total = 0}) async {
    await ApiClient.dio.post<Map<String, dynamic>>('/sales/direct/void', data: {
      'reason': reason,
      if (customerId != null) 'customer': customerId,
      if (resumeId != null) 'resume': resumeId,
      'lines': lines,
      'total': total.toStringAsFixed(2),
    });
  }
}
