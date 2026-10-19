import '../../core/api_client/api_client.dart';

/// ⭐ বিক্রয়ের পরিকল্পনা, ফোনের নিজের পর্দায় — লক্ষ্যমাত্রা, ডিলারের লক্ষ্য, রুটের খাতা, স্কিম, কমিশন (মালিক, ১৭ অক্টোবর ২০২৬:
/// *"100% in the app, in the app's own style, all Bangla, totals on every list"*)।
///
/// <p>⛔ ফোনে একটাও সংখ্যা কষা হয় না — প্রতিটা অঙ্ক আর মোট সার্ভারের ([SalesPlanApiController], ওয়েবের পাতার একই সেবা)। টাকা
/// কাঁচা দশমিক লেখায় আসে ("1500.00"), ফোন কেবল লাখ-কোটির কমা বসায় ([Money.taka])। সারি চেনা যায় public_id-তে।
/// ⓘ কেবল দেখা — বসানো, ছকে দেওয়া, মেটানো ওয়েবে।
class PlanRow {
  const PlanRow(this.payload);

  final Map<String, dynamic> payload;

  /// লেখা — না থাকলে ফাঁকা
  String text(String key) => (payload[key] ?? '').toString();

  /// লেখা বা null — "লক্ষ্য নেই" আর "০" আলাদা কথা
  String? maybe(String key) {
    final value = payload[key];
    if (value == null) return null;
    final text = value.toString();
    return text.isEmpty ? null : text;
  }

  int number(String key) => (payload[key] as num?)?.toInt() ?? 0;
  bool flag(String key) => payload[key] == true;
  List<String> list(String key) => ((payload[key] as List?) ?? const []).map((e) => e.toString()).toList();

  PlanRow child(String key) => PlanRow(((payload[key] as Map?) ?? const {}).cast<String, dynamic>());

  List<PlanRow> rows(String key) => ((payload[key] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => PlanRow(e.cast<String, dynamic>()))
      .toList();

  int? get nextPage => (payload['next_page'] as num?)?.toInt();
}

abstract class SalesPlanApi {
  Future<PlanRow> targets({required String month, int page = 1});
  Future<PlanRow> dealerTargets({required String month, String q = '', bool onlySet = false, int page = 1});
  Future<PlanRow> routes({required String month, String q = '', int page = 1});
  Future<PlanRow> route(String id, {required String month, int page = 1});
  Future<PlanRow> schemes({String q = '', String status = '', int page = 1});
  Future<PlanRow> scheme(String id);
  Future<PlanRow> commissions({String q = '', String status = '', int page = 1});
}

class ServerSalesPlanApi implements SalesPlanApi {
  const ServerSalesPlanApi();

  Future<PlanRow> _get(String path, Map<String, dynamic> query) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(path, queryParameters: query);
    return PlanRow(response.data ?? const {});
  }

  Map<String, dynamic> _common({String q = '', String status = '', int page = 1}) => {
        if (q.trim().isNotEmpty) 'q': q.trim(),
        if (status.isNotEmpty) 'status': status,
        if (page > 1) 'page': page,
      };

  @override
  Future<PlanRow> targets({required String month, int page = 1}) =>
      _get('/sales/targets', {'month': month, ..._common(page: page)});

  @override
  Future<PlanRow> dealerTargets({required String month, String q = '', bool onlySet = false, int page = 1}) =>
      _get('/sales/dealer-targets', {'month': month, if (onlySet) 'set': 1, ..._common(q: q, page: page)});

  @override
  Future<PlanRow> routes({required String month, String q = '', int page = 1}) =>
      _get('/sales/routes', {'month': month, ..._common(q: q, page: page)});

  @override
  Future<PlanRow> route(String id, {required String month, int page = 1}) =>
      _get('/sales/routes/$id', {'month': month, ..._common(page: page)});

  @override
  Future<PlanRow> schemes({String q = '', String status = '', int page = 1}) =>
      _get('/sales/schemes', _common(q: q, status: status, page: page));

  @override
  Future<PlanRow> scheme(String id) => _get('/sales/schemes/$id', const {});

  @override
  Future<PlanRow> commissions({String q = '', String status = '', int page = 1}) =>
      _get('/sales/commissions', _common(q: q, status: status, page: page));
}
