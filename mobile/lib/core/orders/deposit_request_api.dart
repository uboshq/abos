import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../records/money.dart';

/// জমার বিজ্ঞপ্তি (Payment Advice) — `/sales/deposit-requests` (0.4.3, মালিক ১ অক্টোবর ২০২৬; নাম ও চার অবস্থা
/// টাকা-আসা-যাওয়ার পরিকল্পনা, ৭ অক্টোবর ২০২৬)।
///
/// <p>⭐ এটা বিজ্ঞপ্তি, টাকা নয়: হিসাবরক্ষক স্লিপ মিলিয়ে গ্রহণ করলে তবেই বকেয়া কমে। তাই ফোনে "জমা হয়েছে"
/// কখনো লেখা হয় না — লেখা হয় "পাঠানো"। ব্যাংকে জমায় স্লিপ ছাড়া সার্ভার ফেরায়।
class BankChoice {
  const BankChoice(this.id, this.name);

  final int id;
  final String name;
}

/// ⭐ ডিলারের খোলা বিল — বিজ্ঞপ্তির "কোন বিলের বিপরীতে" (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬; সার্ভার
/// `GET /sales/deposit-requests/bills`)। বকেয়া সার্ভারের, আদায়ের পর্দার একই অঙ্ক।
class OpenBill {
  const OpenBill({required this.id, required this.no, this.date, required this.due});

  final String id;
  final String no;
  final String? date;
  final double due;

  factory OpenBill.fromJson(Map<String, dynamic> json) => OpenBill(
        id: json['id']?.toString() ?? '',
        no: json['no']?.toString() ?? '',
        date: json['date']?.toString(),
        due: Money.valueOrZero(json['due']),
      );
}

/// একটা বিলে কত — পাঠানোর সময়; গ্রহণের মুহূর্তে সার্ভার তখনকার বকেয়া মেপে মেলায়
class BillShare {
  const BillShare(this.invoiceId, this.amount);

  final String invoiceId;
  final String amount;
}

class DepositRequestRow {
  const DepositRequestRow({
    required this.date,
    required this.amount,
    required this.method,
    required this.status,
    this.reference,
    this.reason,
    required this.hasSlip,
    this.serverLabel = '',
    this.bills = const [],
  });

  final String? date;
  final double amount;
  final String method;
  final String status;
  final String? reference;
  final String? reason;
  final bool hasSlip;

  /// সার্ভারের নিজের লেখা (`status_label`) — নতুন কোনো অবস্থা এলে এটাই দেখায়; পুরনো সার্ভারে ''
  final String serverLabel;

  /// বাছা বিল — নম্বর আর অঙ্ক; পুরনো সার্ভারে বা না বাছলে খালি
  final List<(String, double)> bills;

  factory DepositRequestRow.fromJson(Map<String, dynamic> json) => DepositRequestRow(
        date: json['claimed_on']?.toString(),
        amount: Money.valueOrZero(json['amount']),
        method: json['method']?.toString() ?? '',
        status: json['status']?.toString() ?? '',
        reference: json['reference']?.toString(),
        reason: json['decision_reason']?.toString(),
        hasSlip: json['has_slip'] == true,
        serverLabel: json['status_label']?.toString() ?? '',
        bills: [
          for (final b in (json['bills'] as List?) ?? const [])
            if (b is Map) (b['no']?.toString() ?? '', Money.valueOrZero(b['amount'])),
        ],
      );

  /// ⭐ চার অবস্থা: পাঠানো · যাচাই চলছে · গৃহীত · প্রত্যাখ্যাত (কারণসহ, [reason])
  String get statusLabel => switch (status) {
        'pending' => 'পাঠানো',
        'accepted' => 'গৃহীত',
        'rejected' => 'প্রত্যাখ্যাত',
        _ => serverLabel.isNotEmpty ? serverLabel : 'যাচাই চলছে',
      };
}

abstract class DepositRequestApi {
  Future<List<BankChoice>> banks();

  Future<List<DepositRequestRow>> forCustomer(String customerId);

  /// এই দোকানের খোলা বিল, পুরনো আগে
  Future<List<OpenBill>> openBills(String customerId);

  Future<DepositRequestRow> send({
    required String customerId,
    required DateTime date,
    required String amount,
    required String method,
    int? bankAccountId,
    String? reference,
    String? note,
    String? slipPath,
    List<BillShare> bills = const [],
  });
}

class ServerDepositRequestApi implements DepositRequestApi {
  const ServerDepositRequestApi();

  @override
  Future<List<BankChoice>> banks() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/sales/deposit-requests/accounts');
    return [
      for (final row in (response.data?['accounts'] as List?) ?? const [])
        if (row is Map) BankChoice((row['id'] as num).toInt(), row['name']?.toString() ?? ''),
    ];
  }

  @override
  Future<List<DepositRequestRow>> forCustomer(String customerId) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/sales/deposit-requests',
      queryParameters: {'customer': customerId},
    );
    return [
      for (final row in (response.data?['requests'] as List?) ?? const [])
        if (row is Map) DepositRequestRow.fromJson(Map<String, dynamic>.from(row)),
    ];
  }

  @override
  Future<List<OpenBill>> openBills(String customerId) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/sales/deposit-requests/bills',
      queryParameters: {'customer': customerId},
    );
    return [
      for (final row in (response.data?['bills'] as List?) ?? const [])
        if (row is Map) OpenBill.fromJson(Map<String, dynamic>.from(row)),
    ];
  }

  @override
  Future<DepositRequestRow> send({
    required String customerId,
    required DateTime date,
    required String amount,
    required String method,
    int? bankAccountId,
    String? reference,
    String? note,
    String? slipPath,
    List<BillShare> bills = const [],
  }) async {
    final form = FormData.fromMap({
      'customer': customerId,
      'claimed_on': '${date.year.toString().padLeft(4, '0')}-${date.month.toString().padLeft(2, '0')}-${date.day.toString().padLeft(2, '0')}',
      'amount': amount,
      'method': method,
      if (bankAccountId != null) 'bank_account_id': '$bankAccountId',
      if (reference != null && reference.isNotEmpty) 'reference': reference,
      if (note != null && note.isNotEmpty) 'note': note,
      if (slipPath != null) 'slip': await MultipartFile.fromFile(slipPath, filename: slipPath.split(RegExp(r'[\\/]')).last),
      // ⓘ ঘরের নাম হাতে — ফর্মের ভেতরের তালিকা dio নিজে যেভাবে লেখে তার উপর ভরসা নয়
      for (var i = 0; i < bills.length; i++) ...{
        'bills[$i][invoice]': bills[i].invoiceId,
        'bills[$i][amount]': bills[i].amount,
      },
    });
    final response = await ApiClient.dio.post<Map<String, dynamic>>('/sales/deposit-requests', data: form);
    return DepositRequestRow.fromJson(response.data ?? const {});
  }
}
