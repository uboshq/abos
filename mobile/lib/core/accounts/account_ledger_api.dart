import '../api_client/api_client.dart';

/// ⭐ হিসাবের ছক আর খাতের খাতা — `GET /accounts/chart`, `/accounts/chart/{id}/ledger` (মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta
/// mobile app style e zate bujazay"*)।
///
/// <p>⛔ ফোনে একটাও সংখ্যা কষা হয় না: জের, চলমান জের আর সর্বমোট ওয়েবের পাতার একই সেবা থেকে, সার্ভার যেভাবে লিখেছে সেভাবেই
/// ("(Dr) 4,250.00")। খাত চেনা যায় public_id-তে।
class ChartAccount {
  const ChartAccount(this.payload);

  final Map<String, dynamic> payload;

  String get id => (payload['id'] ?? '').toString();
  String get code => (payload['code'] ?? '').toString();
  String get name => (payload['name'] ?? '').toString();
  bool get isGroup => payload['is_group'] == true;
  String? get parent => payload['parent']?.toString();
  int get depth => (payload['depth'] as num?)?.toInt() ?? 0;
  String get balance => (payload['balance'] ?? '').toString();
}

class LedgerLine {
  const LedgerLine(this.payload);

  final Map<String, dynamic> payload;

  String get date => (payload['date'] ?? '').toString();
  String get documentNo => (payload['document_no'] ?? '').toString();
  String get narration => (payload['narration'] ?? '').toString();
  String get debit => (payload['debit'] ?? '').toString();
  String get credit => (payload['credit'] ?? '').toString();
  String get balance => (payload['balance'] ?? '').toString();
}

class AccountLedgerPage {
  const AccountLedgerPage(this.payload);

  final Map<String, dynamic> payload;

  Map<String, dynamic> get _account =>
      (payload['account'] as Map?)?.cast<String, dynamic>() ?? const {};
  Map<String, dynamic> get _totals =>
      (payload['totals'] as Map?)?.cast<String, dynamic>() ?? const {};

  String get code => (_account['code'] ?? '').toString();
  String get name => (_account['name'] ?? '').toString();
  bool get isGroup => _account['is_group'] == true;
  String get balance => (_account['balance'] ?? '').toString();

  List<LedgerLine> get entries => ((payload['entries'] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => LedgerLine(e.cast<String, dynamic>()))
      .toList();

  /// সব পাতার — পাতার নয়
  String get totalDebit => (_totals['debit'] ?? '').toString();
  String get totalCredit => (_totals['credit'] ?? '').toString();
  String get closing => (_totals['closing'] ?? '').toString();
  int get count => (payload['count'] as num?)?.toInt() ?? 0;
  int? get nextPage => (payload['next_page'] as num?)?.toInt();
}

abstract class AccountLedgerApi {
  Future<List<ChartAccount>> chart({String q = ''});
  Future<AccountLedgerPage> ledger(String id, {int page = 1});
}

class ServerAccountLedgerApi implements AccountLedgerApi {
  const ServerAccountLedgerApi();

  @override
  Future<List<ChartAccount>> chart({String q = ''}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/chart',
        queryParameters: {if (q.trim().isNotEmpty) 'q': q.trim()});
    return ((response.data?['accounts'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => ChartAccount(e.cast<String, dynamic>()))
        .toList();
  }

  @override
  Future<AccountLedgerPage> ledger(String id, {int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/accounts/chart/$id/ledger',
        queryParameters: {if (page > 1) 'page': page});
    return AccountLedgerPage(response.data ?? const {});
  }
}
