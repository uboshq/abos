import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../auth/auth_user.dart';
import '../sync_engine/poll_gate.dart';

/// ⭐ নিট সম্পদ বিবরণী (Net Worth Statement) — `GET /accounts/net-worth` আর `/accounts/net-worth/summary` (মালিক, ২৫ অক্টোবর
/// ২০২৬: "eta banaw r desboard e ekta card daw sudu maliker jonno, mobile app eo diba"; সার্ভার a5-এর NetWorthApiController)।
///
/// <p>⛔ কেবল কোম্পানির মালিক: অন্য কারো কাছে সার্ভার ৪০৪ দেয় — তখন [NetWorthApi.summary] null, আর কার্ডটাই থাকে না।
/// ⛔ ফোনে একটাও অঙ্ক কষা হয় না: নিট, মোট আর দুই দিকের মিল সার্ভারের; ফোন কেবল লাখের কমা বসায় ([Money.taka])।
class NetWorthSummary {
  const NetWorthSummary(this.payload);

  final Map<String, dynamic> payload;

  dynamic get net => payload['net'];
  dynamic get thisYear => payload['this_year'];
  String get asOf => (payload['as_of'] ?? '').toString();
  bool get agrees => payload['agrees'] == true;
}

/// এক খাত — বিবরণীর ঘরের নিচের সারি
class NetWorthAccount {
  const NetWorthAccount(this.payload);

  final Map<String, dynamic> payload;

  String get code => (payload['code'] ?? '').toString();
  String get name => (payload['name'] ?? '').toString();
  dynamic get amount => payload['amount'];
}

class NetWorthStatementRecord {
  const NetWorthStatementRecord(this.payload);

  /// ঘরগুলো, ওয়েবের পাতার একই ক্রমে
  static const List<String> tiles = ['cash', 'receivable', 'stock', 'other_assets'];

  final Map<String, dynamic> payload;

  Map<String, dynamic> get _figures => (payload['figures'] as Map?)?.cast<String, dynamic>() ?? const {};
  Map<String, dynamic> get _accounts => (payload['accounts'] as Map?)?.cast<String, dynamic>() ?? const {};

  String get companyName => ((payload['company'] as Map?)?['name'] ?? '').toString();
  String get asOf => (payload['as_of'] ?? '').toString();
  bool get agrees => payload['agrees'] == true;

  /// সার্ভারের কাঁচা দশমিক; না-এলে null — "—", কখনো ৳0.00 নয়
  dynamic figure(String line) => _figures[line];

  List<NetWorthAccount> accounts(String line) => ((_accounts[line] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => NetWorthAccount(e.cast<String, dynamic>()))
      .toList();
}

/// ⭐ The server shows the statement only to the company's owner — the `super_admin` role in this company
/// (Ownership::role(), NetWorthStatement::mayView()); there is no key. Anyone else is not asked at all, so a
/// non-owner's home costs no 404 (polling cut, 23 Nov 2026).
bool mayAskNetWorth(AuthUser? user) => user != null && user.hasRole(netWorthOwnerRole);

/// The owner role's name, as the server sends it in `roles`.
const String netWorthOwnerRole = 'super_admin';

abstract class NetWorthApi {
  /// null = মালিক নন (সার্ভারের ৪০৪)
  Future<NetWorthSummary?> summary();
  Future<NetWorthStatementRecord> statement();
}

class ServerNetWorthApi implements NetWorthApi {
  const ServerNetWorthApi();

  /// ⭐ One answer per minute — the card is rebuilt on every new "today" figure, the call is not repeated
  /// (polling cut, 23 Nov 2026). ⛔ Failures are not kept; sign-out and any write wipe it ([SharedAnswer]).
  static final SharedAnswer<NetWorthSummary?> _shared = SharedAnswer<NetWorthSummary?>(maxAge: const Duration(seconds: 60));

  @override
  Future<NetWorthSummary?> summary() => _shared.recent(_summary);

  static Future<NetWorthSummary?> _summary() async {
    try {
      final response = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/net-worth/summary');
      return NetWorthSummary(response.data ?? const {});
    } on DioException catch (e) {
      if (e.response?.statusCode == 404) return null;
      rethrow;
    }
  }

  @override
  Future<NetWorthStatementRecord> statement() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/net-worth');
    return NetWorthStatementRecord(response.data ?? const {});
  }
}
