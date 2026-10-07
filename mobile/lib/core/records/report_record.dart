import 'package:intl/intl.dart';

import '../api_client/api_client.dart';
import 'money.dart';

/// One report this person may run — docs/Contract §৯.
class ReportSummary {
  const ReportSummary(this.payload);

  final Map<String, dynamic> payload;

  String get key => (payload['key'] ?? '').toString();
  String? get module => _text('module');
  String get title => _text('title') ?? key;

  List<String> get filters =>
      ((payload['filters'] as List?) ?? const []).map((e) => e.toString()).toList();

  bool get takesDateRange => filters.contains('date_range');

  String? _text(String k) {
    final v = payload[k];
    if (v == null) return null;
    final t = v.toString().trim();
    return t.isEmpty ? null : t;
  }
}

/// One column of a report, as the server describes it.
///
/// <p><b>Never hardcoded on the phone.</b> The engine's `columnsFor($user)`
/// already drops the columns a person may not see, so a hidden figure is
/// *absent from the response* rather than blank in it — the `purchasePrice`
/// rule (docs/Contract §৩ ঙ) generalised to thirty-three reports at once.
/// Hardcoding columns would mean hardcoding them thirty-three times and each
/// one going stale against the server on its own schedule.
class ReportColumn {
  const ReportColumn(this.payload);

  final Map<String, dynamic> payload;

  String get key => (payload['key'] ?? '').toString();
  String get label => (payload['label'] ?? key).toString();

  /// `text` · `money` · `quantity` · `date` · `document` · `percent`.
  String get type => (payload['type'] ?? 'text').toString();

  bool get totalled => payload['total'] == true;

  bool get isNumeric => type == 'money' || type == 'quantity' || type == 'percent';

  /// How one cell of this column reads.
  ///
  /// <p>⚠️ An unfamiliar type is printed raw rather than hidden. A build that
  /// met a type added after it shipped should show the figure and look plain,
  /// not show an empty cell and look broken.
  String format(Object? value) {
    if (value == null) return '';
    switch (type) {
      case 'money':
        return Money.taka(value);
      case 'quantity':
      case 'percent':
        final n = Money.plain(value);
        return type == 'percent' ? '$n%' : n;
      case 'date':
        final parsed = DateTime.tryParse(value.toString());
        return parsed == null
            ? value.toString()
            : DateFormat('dd/MM/yyyy').format(parsed);
      default:
        return value.toString();
    }
  }
}

/// A page of one report.
class ReportPage {
  const ReportPage(this.payload);

  final Map<String, dynamic> payload;

  String get title => (payload['title'] ?? '').toString();

  List<ReportColumn> get columns =>
      ((payload['columns'] as List?) ?? const [])
          .whereType<Map>()
          .map((c) => ReportColumn(c.cast<String, dynamic>()))
          .toList();

  List<Map<String, dynamic>> get rows => ((payload['rows'] as List?) ?? const [])
      .whereType<Map>()
      .map((r) => r.cast<String, dynamic>())
      .toList();

  /// <p>⛔ <b>The server's totals, never the phone's.</b> Adding up the rows
  /// on screen would put the sum of one hundred rows under a report of four
  /// hundred and twelve and call it the total — a number that looks credible
  /// and is simply false. `ReportResult` sends totals separately for exactly
  /// this reason.
  Map<String, dynamic> get totals =>
      ((payload['totals'] as Map?) ?? const {}).cast<String, dynamic>();

  int get page => (payload['page'] as num?)?.toInt() ?? 1;
  int get perPage => (payload['perPage'] as num?)?.toInt() ?? 0;
  int get totalRows => (payload['totalRows'] as num?)?.toInt() ?? rows.length;
  int get lastPage => (payload['lastPage'] as num?)?.toInt() ?? 1;

  bool get hasMore => page < lastPage;

  /// "৪১২টির মধ্যে ১–১০০" — so nobody reads one page as the whole thing.
  String get rangeSentence {
    if (rows.isEmpty) return 'কোনো সারি নেই';
    final first = (page - 1) * (perPage == 0 ? rows.length : perPage) + 1;
    final last = first + rows.length - 1;
    return '$totalRows টির মধ্যে $first–$last';
  }
}

/// docs/Contract §৯. ⚠️ **Neither endpoint exists yet** — this is the app's
/// half of an agreement written down first, not a guess at one.
class ReportsApi {
  const ReportsApi._();

  /// Only the reports this person may run — the server filters by each
  /// definition's own permission. Discovered rather than hardcoded, the same
  /// reasoning as `GET /sync/capabilities`: a report registered on the server
  /// appears on the phone without a mobile release.
  static Future<List<ReportSummary>> list() async {
    final response = await ApiClient.dio.get<List<dynamic>>('/reports');
    return (response.data ?? const [])
        .whereType<Map>()
        .map((e) => ReportSummary(e.cast<String, dynamic>()))
        .toList();
  }

  static Future<ReportPage> run(
    String key, {
    int page = 1,
    Map<String, dynamic> filters = const {},
  }) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/reports/$key',
      queryParameters: {'page': page, ...filters},
    );
    return ReportPage(response.data ?? const {});
  }
}
