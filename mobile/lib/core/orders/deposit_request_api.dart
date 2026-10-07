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

  factory DepositRequestRow.fromJson(Map<String, dynamic> json) => DepositRequestRow(
        date: json['claimed_on']?.toString(),
        amount: Money.valueOrZero(json['amount']),
        method: json['method']?.toString() ?? '',
        status: json['status']?.toString() ?? '',
        reference: json['reference']?.toString(),
        reason: json['decision_reason']?.toString(),
        hasSlip: json['has_slip'] == true,
        serverLabel: json['status_label']?.toString() ?? '',
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

  Future<DepositRequestRow> send({
    required String customerId,
    required DateTime date,
    required String amount,
    required String method,
    int? bankAccountId,
    String? reference,
    String? note,
    String? slipPath,
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
  Future<DepositRequestRow> send({
    required String customerId,
    required DateTime date,
    required String amount,
    required String method,
    int? bankAccountId,
    String? reference,
    String? note,
    String? slipPath,
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
    });
    final response = await ApiClient.dio.post<Map<String, dynamic>>('/sales/deposit-requests', data: form);
    return DepositRequestRow.fromJson(response.data ?? const {});
  }
}
