import '../api_client/api_client.dart';

/// ⭐ বিক্রয়ের ডেস্ক-পাতা আর দলিলের ছাঁচ, ফোনের নিজের পর্দায় — ডিপোর যাচাই, না ছাপা কাগজ, ডেলিভারির কাজ, পাইপলাইন, ছাঁচ (মালিক,
/// ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"*)।
///
/// <p>⛔ ফোনে একটাও সংখ্যা কষা হয় না — প্রতিটা ঘর সার্ভার যেভাবে লিখেছে ("1,500.00", "৮৫.০%")। সারি চেনা যায় public_id-তে।
class DeskRow {
  const DeskRow(this.payload);

  final Map<String, dynamic> payload;

  String text(String key) => (payload[key] ?? '').toString();
  int number(String key) => (payload[key] as num?)?.toInt() ?? 0;
  bool flag(String key) => payload[key] == true;
  List<String> list(String key) => ((payload[key] as List?) ?? const []).map((e) => e.toString()).toList();
  List<DeskRow> rows(String key) => ((payload[key] as List?) ?? const [])
      .whereType<Map>()
      .map((e) => DeskRow(e.cast<String, dynamic>()))
      .toList();
  int? get nextPage => (payload['next_page'] as num?)?.toInt();
}

abstract class SalesDeskApi {
  Future<DeskRow> depotCheck({String q = ''});
  Future<DeskRow> printQueue({String q = '', int page = 1});
  Future<void> settlePrint(String id);
  Future<DeskRow> deliveryPerformance({required String from, required String to, int page = 1});
  Future<DeskRow> pipeline();
  Future<DeskRow> templates({int page = 1});
  Future<DeskRow> template(String id);
}

class ServerSalesDeskApi implements SalesDeskApi {
  const ServerSalesDeskApi();

  Future<DeskRow> _get(String path, [Map<String, dynamic>? query]) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(path, queryParameters: query);
    return DeskRow(response.data ?? const {});
  }

  @override
  Future<DeskRow> depotCheck({String q = ''}) => _get('/sales/depot-check', {if (q.trim().isNotEmpty) 'q': q.trim()});

  @override
  Future<DeskRow> printQueue({String q = '', int page = 1}) =>
      _get('/sales/print-queue', {if (q.trim().isNotEmpty) 'q': q.trim(), if (page > 1) 'page': page});

  @override
  Future<void> settlePrint(String id) async {
    await ApiClient.dio.post<Map<String, dynamic>>('/sales/print-queue/$id/settle');
  }

  @override
  Future<DeskRow> deliveryPerformance({required String from, required String to, int page = 1}) =>
      _get('/sales/delivery-performance', {'from': from, 'to': to, if (page > 1) 'page': page});

  @override
  Future<DeskRow> pipeline() => _get('/sales/opportunities/pipeline');

  @override
  Future<DeskRow> templates({int page = 1}) => _get('/documents/templates', {if (page > 1) 'page': page});

  @override
  Future<DeskRow> template(String id) => _get('/documents/templates/$id');
}
