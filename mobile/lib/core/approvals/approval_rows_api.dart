import '../../core/api_client/once_key.dart';
import '../api_client/api_client.dart';

/// ⭐ অনুমোদন-ভাগের তিনটা সারি, ফোনের নিজের পর্দায় — সইয়ের ভার, দলিলের অনুমোদনের সারি আর সই কেন্দ্র (মালিক, ১৭ অক্টোবর
/// ২০২৬: *"direct webpage app e bujajayna"*)।
///
/// <p>⛔ সই বা ফেরত এখানে নয়: দলিলের সারির সই ফোনের অনুমোদন-দরজায় ([ApprovalsApi.approve]/[ApprovalsApi.reject]), একই ইঞ্জিনে —
/// সেখান থেকেই সংস্করণের ছাপসহ সইয়ের সারি বসে। মানুষ, ভার আর অনুমোদন চেনা যায় public_id-তে।
class Delegation {
  const Delegation(this.payload);

  final Map<String, dynamic> payload;

  String get id => (payload['id'] ?? '').toString();
  String get person => (payload['person'] ?? '').toString();
  String get startsOn => (payload['starts_on'] ?? '').toString();
  String get endsOn => (payload['ends_on'] ?? '').toString();
  List<String> get modules => ((payload['modules'] as List?) ?? const []).map((e) => e.toString()).toList();
  String get reason => (payload['reason'] ?? '').toString();
  bool get revoked => payload['revoked'] == true;
  bool get active => payload['active'] == true;
}

class DelegationSetup {
  const DelegationSetup(this.payload);

  final Map<String, dynamic> payload;

  List<Delegation> _list(String key) => ((payload[key] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => Delegation(e.cast<String, dynamic>()))
      .toList();

  /// আমি যাঁদের দিয়েছি
  List<Delegation> get given => _list('given');

  /// আমার কাছে যাঁদের ভার (চালু)
  List<Delegation> get held => _list('held');

  /// কাকে দেওয়া যায় — public_id → নাম
  List<(String, String)> get people => ((payload['people'] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => ((e['id'] ?? '').toString(), (e['name'] ?? '').toString()))
      .toList();

  /// কোন কাজে — কোড → নাম (খালি বাছাই = সব কাজে)
  List<(String, String)> get modules => ((payload['modules'] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => ((e['code'] ?? '').toString(), (e['label'] ?? '').toString()))
      .toList();
}

/// দলিলের অনুমোদনের সারি, বা সই কেন্দ্রের একটা সারি — অপেক্ষমাণ অনুমোদন, বা (ইতিহাসে) বসে যাওয়া সই
class PaperQueueRow {
  const PaperQueueRow(this.payload);

  final Map<String, dynamic> payload;

  /// অনুমোদনের public_id — সই/ফেরত এই id-তে; ইতিহাসের সারিতে ফাঁকা
  String get id => (payload['id'] ?? '').toString();
  String get documentNo => (payload['document_no'] ?? '').toString();
  String get name => (payload['name'] ?? '').toString();
  String get requester => (payload['requester'] ?? '').toString();
  String get dueAt => (payload['due_at'] ?? '').toString();
  bool get overdue => payload['overdue'] == true;
  bool get canDecide => payload['can_decide'] == true;
  String get version => (payload['version'] ?? '').toString();

  // ⓘ সইয়ের ইতিহাস
  String get signer => (payload['signer'] ?? '').toString();
  String get signedAt => (payload['signed_at'] ?? '').toString();
  String get hash => (payload['hash'] ?? '').toString();
}

class PaperQueuePage {
  const PaperQueuePage(this.payload);

  final Map<String, dynamic> payload;

  List<PaperQueueRow> get rows => ((payload['rows'] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => PaperQueueRow(e.cast<String, dynamic>()))
      .toList();
  int get count => (payload['count'] as num?)?.toInt() ?? 0;
  int? get nextPage => (payload['next_page'] as num?)?.toInt();
}

abstract class ApprovalRowsApi {
  Future<DelegationSetup> delegations();

  /// নতুন ভার — তারিখ `yyyy-MM-dd`; [modules] খালি মানে সব কাজে। ⓘ একবারের চাবি ডাকার জায়গা থেকে ([OnceKey])।
  Future<Delegation> grant(
      {required String to, required String startsOn, required String endsOn, List<String> modules, String? reason});

  Future<void> revoke(String id);

  /// দলিলের অনুমোদনের সারি — `mine` হলে আমার পাঠানো
  Future<PaperQueuePage> documentQueue({bool mine = false, int page = 1});

  /// সই কেন্দ্র — `to_sign` · `asked` · `signed`
  Future<PaperQueuePage> signatures({String tab = 'to_sign', int page = 1});
}

class ServerApprovalRowsApi implements ApprovalRowsApi {
  const ServerApprovalRowsApi();

  @override
  Future<DelegationSetup> delegations() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/approvals/delegations');
    return DelegationSetup(response.data ?? const {});
  }

  @override
  Future<Delegation> grant(
      {required String to, required String startsOn, required String endsOn, List<String> modules = const [], String? reason}) async {
    final response = await OnceKey.of('delegation-grant:$to:$startsOn:$endsOn:${modules.join(',')}').send(() => ApiClient.dio.post<Map<String, dynamic>>('/approvals/delegations', data: {
      'to': to,
      'starts_on': startsOn,
      'ends_on': endsOn,
      'modules': modules,
      if (reason != null && reason.trim().isNotEmpty) 'reason': reason.trim(),
    }));
    return Delegation(((response.data?['delegation'] as Map?) ?? const {}).cast<String, dynamic>());
  }

  @override
  Future<void> revoke(String id) async {
    await OnceKey.of('delegation-revoke:$id').send(() => ApiClient.dio.delete<Map<String, dynamic>>('/approvals/delegations/$id'));
  }

  @override
  Future<PaperQueuePage> documentQueue({bool mine = false, int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/documents/approvals',
        queryParameters: {if (mine) 'mine': 1, if (page > 1) 'page': page});
    return PaperQueuePage(response.data ?? const {});
  }

  @override
  Future<PaperQueuePage> signatures({String tab = 'to_sign', int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/documents/signatures',
        queryParameters: {'tab': tab, if (page > 1) 'page': page});
    return PaperQueuePage(response.data ?? const {});
  }
}
