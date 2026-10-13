import 'package:intl/intl.dart';

import '../api_client/api_client.dart';
import '../records/money.dart';

/// ⭐ নিজের ছুটি আর নিজের বেতনের স্লিপ — ফোনে (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬; সার্ভার `LeaveApiController`,
/// `PayslipApiController`)।
///
/// <p>কর্মী সবসময় লগইন থেকে — ফোন কারও আইডি পাঠায় না। ছুটির সব যাচাই (দিন, আগের আবেদনের সাথে মেলা, বছরের সীমা) সার্ভারের
/// ওয়েবের একই সেবায়; মঞ্জুরি ওয়েবে।

String _s(dynamic v) => v?.toString() ?? '';
String? _sn(dynamic v) => v == null || v.toString().isEmpty ? null : v.toString();
List<Map<String, dynamic>> _maps(dynamic v) => [
      if (v is List)
        for (final e in v)
          if (e is Map) Map<String, dynamic>.from(e),
    ];

class LeaveKind {
  const LeaveKind({required this.id, required this.name, this.paid = true, this.left, this.allowed = '0', this.taken = '0'});

  final String id;
  final String name;
  final bool paid;

  /// null = বছরে সীমা নেই
  final String? left;
  final String allowed;
  final String taken;

  factory LeaveKind.fromJson(Map<String, dynamic> j) => LeaveKind(
        id: _s(j['id']),
        name: _s(j['name']),
        paid: j['paid'] != false,
        left: _sn(j['left']),
        allowed: _s(j['allowed']),
        taken: _s(j['taken']),
      );
}

class LeaveRequest {
  const LeaveRequest({
    required this.id,
    required this.type,
    this.fromDate,
    this.toDate,
    this.days = '',
    this.reason = '',
    required this.status,
    this.remarks = '',
  });

  final String id;
  final String type;
  final String? fromDate;
  final String? toDate;
  final String days;
  final String reason;

  /// pending · approved · rejected · cancelled
  final String status;
  final String remarks;

  String get statusWords => switch (status) {
        'pending' => 'অনুমোদনের অপেক্ষায়',
        'approved' => 'মঞ্জুর',
        'rejected' => 'নামঞ্জুর',
        'cancelled' => 'প্রত্যাহৃত',
        _ => status,
      };

  factory LeaveRequest.fromJson(Map<String, dynamic> j) => LeaveRequest(
        id: _s(j['id']),
        type: _s(j['type']),
        fromDate: _sn(j['from_date']),
        toDate: _sn(j['to_date']),
        days: _s(j['days']),
        reason: _s(j['reason']),
        status: _s(j['status']),
        remarks: _s(j['remarks']),
      );
}

class LeaveBook {
  const LeaveBook({required this.employee, this.types = const [], this.applications = const []});

  /// নিজের নামে কর্মীর খাতা আছে কি না
  final bool employee;
  final List<LeaveKind> types;
  final List<LeaveRequest> applications;
}

class LeaveDraft {
  const LeaveDraft({required this.typeId, required this.from, required this.to, required this.days, this.reason = ''});

  final String typeId;
  final DateTime from;
  final DateTime to;
  final String days;
  final String reason;
}

class PayslipLineItem {
  const PayslipLineItem({required this.name, required this.earning, required this.amount});

  final String name;
  final bool earning;
  final double amount;
}

class Payslip {
  const Payslip({
    required this.id,
    this.month,
    this.runNo = '',
    required this.gross,
    required this.deductions,
    required this.net,
    this.name = '',
    this.code = '',
    this.paymentMethod = '',
    this.lines = const [],
  });

  final String id;
  final String? month;
  final String runNo;
  final double gross;
  final double deductions;
  final double net;
  final String name;
  final String code;
  final String paymentMethod;
  final List<PayslipLineItem> lines;

  factory Payslip.fromJson(Map<String, dynamic> j) => Payslip(
        id: _s(j['id']),
        month: _sn(j['month']),
        runNo: _s(j['run_no']),
        gross: Money.valueOrZero(j['gross']),
        deductions: Money.valueOrZero(j['deductions']),
        net: Money.valueOrZero(j['net']),
        name: _s(j['name']),
        code: _s(j['code']),
        paymentMethod: _s(j['payment_method']),
        lines: [
          for (final l in _maps(j['lines']))
            PayslipLineItem(name: _s(l['name']), earning: l['earning'] == true, amount: Money.valueOrZero(l['amount'])),
        ],
      );
}

class PayslipPage {
  const PayslipPage({required this.employee, required this.rows, this.nextPage});

  final bool employee;
  final List<Payslip> rows;
  final int? nextPage;
}

abstract class SelfServiceApi {
  Future<LeaveBook> leave();
  Future<LeaveRequest> applyLeave(LeaveDraft draft);
  Future<PayslipPage> payslips({int page = 1});
  Future<Payslip> payslip(String id);
}

class ServerSelfServiceApi implements SelfServiceApi {
  const ServerSelfServiceApi();

  static final _day = DateFormat('yyyy-MM-dd');

  @override
  Future<LeaveBook> leave() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/hr/leave');
    final body = response.data ?? const <String, dynamic>{};
    return LeaveBook(
      employee: body['employee'] == true,
      types: [for (final t in _maps(body['types'])) LeaveKind.fromJson(t)],
      applications: [for (final a in _maps(body['applications'])) LeaveRequest.fromJson(a)],
    );
  }

  @override
  Future<LeaveRequest> applyLeave(LeaveDraft draft) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>('/hr/leave', data: {
      'leave_type': draft.typeId,
      'from_date': _day.format(draft.from),
      'to_date': _day.format(draft.to),
      'days': draft.days,
      if (draft.reason.trim().isNotEmpty) 'reason': draft.reason.trim(),
    });
    return LeaveRequest.fromJson(response.data ?? const <String, dynamic>{});
  }

  @override
  Future<PayslipPage> payslips({int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/hr/payslips', queryParameters: {'page': page});
    final body = response.data ?? const <String, dynamic>{};
    return PayslipPage(
      employee: body['employee'] != false,
      rows: [for (final r in _maps(body['rows'])) Payslip.fromJson(r)],
      nextPage: (body['next_page'] as num?)?.toInt(),
    );
  }

  @override
  Future<Payslip> payslip(String id) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/hr/payslips/${Uri.encodeComponent(id)}');
    return Payslip.fromJson(response.data ?? const <String, dynamic>{});
  }
}
