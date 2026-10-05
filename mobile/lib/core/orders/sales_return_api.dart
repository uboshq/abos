import '../api_client/api_client.dart';
import '../widgets/confirm_overview_sheet.dart';

/// ⭐ ফোনে বিক্রি ফেরত — `/sales/returns` (মালিক, ৪ অক্টোবর ২০২৬; কাউন্টারের "ফেরত" বোতাম)।
///
/// <p>⛔ নিয়ম সব সার্ভারের — ওয়েবের ফেরতের একই যাচাই আর একই সেবা: বেচার বেশি ফেরত নয়, কারণ বাধ্যতামূলক, সই লাগলে
/// খসড়া থাকে। ফোন কেবল বিলের সারি আর পরিমাণ পাঠায় — ক্রেতা, গুদাম, দর আর লট সার্ভার বিল থেকেই নেয়।
class ReturnChoice {
  const ReturnChoice(this.id, this.label);

  final String id;
  final String label;
}

class ReturnInvoice {
  const ReturnInvoice(
      {required this.id,
      required this.no,
      required this.customer,
      required this.total,
      this.date});

  final String id;
  final String no;
  final String customer;
  final String total;
  final String? date;
}

class ReturnSetup {
  const ReturnSetup({required this.reasons, required this.invoices});

  final List<ReturnChoice> reasons;
  final List<ReturnInvoice> invoices;

  factory ReturnSetup.fromJson(Map<String, dynamic> json) => ReturnSetup(
        reasons: [
          for (final r in (json['reasons'] as List?) ?? const [])
            if (r is Map)
              ReturnChoice(r['id'].toString(), r['label']?.toString() ?? ''),
        ],
        invoices: [
          for (final i in (json['invoices'] as List?) ?? const [])
            if (i is Map)
              ReturnInvoice(
                id: i['id'].toString(),
                no: i['no']?.toString() ?? '',
                customer: i['customer']?.toString() ?? '',
                total: i['total']?.toString() ?? '0',
                date: i['date']?.toString(),
              ),
        ],
      );
}

/// বিলের একটা সারি — ফেরতের জন্য
class ReturnBillLine {
  const ReturnBillLine(
      {required this.id,
      required this.name,
      required this.qty,
      required this.rate,
      this.lotNo});

  final String id;
  final String name;
  final double qty;
  final double rate;
  final String? lotNo;
}

class ReturnBill {
  const ReturnBill(
      {required this.id,
      required this.no,
      required this.customerName,
      required this.lines});

  final String id;
  final String no;
  final String customerName;
  final List<ReturnBillLine> lines;

  factory ReturnBill.fromJson(Map<String, dynamic> json) => ReturnBill(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '',
        customerName: json['customerName']?.toString() ?? '',
        lines: [
          for (final l in (json['lines'] as List?) ?? const [])
            if (l is Map)
              ReturnBillLine(
                id: l['id'].toString(),
                name: l['name']?.toString() ?? '',
                qty: double.tryParse(l['qty']?.toString() ?? '') ?? 0,
                rate: double.tryParse(l['rate']?.toString() ?? '') ?? 0,
                lotNo: l['lotNo']?.toString(),
              ),
        ],
      );
}

abstract class SalesReturnApi {
  Future<ReturnSetup> setup({String? customerId});

  Future<ReturnBill> bill(String invoiceId);

  /// খসড়া ফেরত — `lines`: বিলের সারির id → পরিমাণ; ফেরে ফেরতের id
  Future<String> draft(
      {required String invoiceId,
      required String reasonId,
      required Map<String, double> lines,
      String? note});

  Future<ConfirmOverviewData> overview(String returnId);

  /// done · held — সঙ্গে বার্তা
  Future<(String, String)> confirm(String returnId);
}

class ServerSalesReturnApi implements SalesReturnApi {
  const ServerSalesReturnApi();

  @override
  Future<ReturnSetup> setup({String? customerId}) async {
    final response = await ApiClient.dio
        .get<Map<String, dynamic>>('/sales/returns/setup', queryParameters: {
      if (customerId != null) 'customer': customerId,
    });
    return ReturnSetup.fromJson(response.data ?? const {});
  }

  @override
  Future<ReturnBill> bill(String invoiceId) async {
    final response = await ApiClient.dio
        .get<Map<String, dynamic>>('/sales/returns/invoice/$invoiceId');
    return ReturnBill.fromJson(response.data ?? const {});
  }

  @override
  Future<String> draft(
      {required String invoiceId,
      required String reasonId,
      required Map<String, double> lines,
      String? note}) async {
    final response =
        await ApiClient.dio.post<Map<String, dynamic>>('/sales/returns', data: {
      'invoice': invoiceId,
      'reason': reasonId,
      'lines': [
        for (final e in lines.entries)
          if (e.value > 0) {'line': e.key, 'qty': e.value.toString()},
      ],
      if (note != null && note.trim().isNotEmpty) 'note': note.trim(),
    });
    return response.data?['id']?.toString() ?? '';
  }

  @override
  Future<ConfirmOverviewData> overview(String returnId) async {
    final response = await ApiClient.dio
        .get<Map<String, dynamic>>('/sales/returns/$returnId/overview');
    return ConfirmOverviewData.fromJson(response.data ?? const {});
  }

  @override
  Future<(String, String)> confirm(String returnId) async {
    final response = await ApiClient.dio
        .post<Map<String, dynamic>>('/sales/returns/$returnId/confirm');
    return (
      response.data?['status']?.toString() ?? '',
      response.data?['notice']?.toString() ?? ''
    );
  }
}
