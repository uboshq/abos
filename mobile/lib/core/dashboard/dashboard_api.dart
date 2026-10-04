import '../api_client/api_client.dart';

/// ⭐ The modules' own dashboards on the phone — owner, 4 Oct 2026: *"mobile app e inventory desborad … Sales Account
/// er gulo dorkar … egulo nadile bujbo kikore kihocche"*.
///
/// <p><b>Not one figure is computed here.</b> `GET /dashboard/{module}` sends the web's own `DashboardEngine` output —
/// the same stock value, the same sales total. A second calculation on the phone would one day disagree with the web,
/// and nobody could say which was right.
class DashboardApi {
  const DashboardApi._();

  /// The modules this person may open, each with its first figure.
  static Future<List<DashboardEntry>> list() async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/dashboard');
    return ((response.data?['modules'] as List?) ?? const [])
        .whereType<Map>()
        .map((e) => DashboardEntry(e.cast<String, dynamic>()))
        .toList();
  }

  static Future<ModuleDashboard> module(String code) async {
    final response =
        await ApiClient.dio.get<Map<String, dynamic>>('/dashboard/$code');
    return ModuleDashboard(response.data ?? const {});
  }
}

class DashboardEntry {
  const DashboardEntry(this.payload);

  final Map<String, dynamic> payload;

  String get module => (payload['module'] ?? '').toString();
  String get name => (payload['name'] ?? module).toString();
  DashboardStat? get stat => payload['stat'] is Map
      ? DashboardStat((payload['stat'] as Map).cast<String, dynamic>())
      : null;
}

class DashboardStat {
  const DashboardStat(this.payload);

  final Map<String, dynamic> payload;

  String get label => (payload['label'] ?? '').toString();

  /// Null when the figure is hidden from this person — see [hidden].
  String? get value => payload['value']?.toString();

  /// ⚠️ The server keeps a covered figure covered (stock value without the cost key). The phone says so in words
  /// rather than showing a blank that reads like zero.
  bool get hidden => payload['hidden'] == true;

  String get hint => (payload['hint'] ?? '').toString();
  String get tone => (payload['tone'] ?? 'neutral').toString();
  String? get previous => payload['previous']?.toString();
  String? get previousLabel => payload['previousLabel']?.toString();
}

class ModuleDashboard {
  const ModuleDashboard(this.payload);

  final Map<String, dynamic> payload;

  String get title => (payload['title'] ?? '').toString();
  String get subtitle => (payload['subtitle'] ?? '').toString();

  List<DashboardStat> get stats =>
      _maps('stats').map(DashboardStat.new).toList();
  List<Map<String, dynamic>> get panels => _maps('panels');
  List<Map<String, dynamic>> get listings => _maps('listings');

  List<Map<String, dynamic>> _maps(String key) =>
      ((payload[key] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => e.cast<String, dynamic>())
          .toList();
}

/// A figure as the server wrote it ("1,23,456.00") turned into a number for drawing bars only — never shown.
double barValue(Object? raw) =>
    double.tryParse((raw ?? '').toString().replaceAll(',', '').trim()) ?? 0;
