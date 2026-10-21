import '../../core/api_client/api_client.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';

/// ⭐ মালিকের কেন্দ্রের বাকি ছয় পাতা, ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: *"100% app e, app er nijer style e, sob
/// Bangla, protita list e total"*।
///
/// <p>সার্ভারের দরজা `GET /api/v1/executive/{compare,analysis,alerts,history,reports,sister-links}` ([[OwnerPagesApiController]]) —
/// ওয়েবের পাতার একই সেবা, একই চাবি। ⛔ ফোন কোনো সংখ্যা কষে না; কেবল যা আসে তা আঁকে। ঢাকা ঘর `"hidden"` (••••), না থাকা `null` (—)।
/// ⓘ কোম্পানি আর শাখা কেবল `public_id`-তে আসে-যায়।

/// সার্ভারের ঢাকা ঘরের চিহ্ন — "আজ"-এর দরজার একই ([[ExecutiveToday.hidden]])
const String ownerHidden = 'hidden';

List<Map<String, dynamic>> _maps(Object? raw) =>
    raw is List ? raw.whereType<Map>().map((e) => e.cast<String, dynamic>()).toList() : const [];

Map<String, String?> _cells(Object? raw) =>
    raw is Map ? {for (final e in raw.entries) e.key.toString(): e.value?.toString()} : const {};

String _text(Object? v, [String fallback = '']) => v?.toString() ?? fallback;

List<String> _strings(Object? raw) => raw is List ? raw.map((e) => e.toString()).toList() : const [];

/// একটা কোম্পানি — public_id আর নাম
class OwnerCompany {
  const OwnerCompany({required this.id, required this.name, this.current = false});

  final String id;
  final String name;

  /// চলতি কোম্পানি — হেডারের শাখা কেবল এখানে খাটে
  final bool current;
}

// ── তুলনা ──────────────────────────────────────────────────────────────────────────────────

class CompareColumn {
  const CompareColumn({required this.key, required this.label, required this.trend});

  final String key;
  final String label;

  /// সময়ের সংখ্যা — ধারা হয় (জের-এর হয় না)
  final bool trend;
}

class CompareRow {
  const CompareRow({required this.id, required this.name, this.branch, this.now = const {}, this.was = const {}, this.change = const {}});

  final String id;
  final String name;
  final String? branch;
  final Map<String, String?> now;
  final Map<String, String?> was;
  final Map<String, String?> change;
}

class TrendBucket {
  const TrendBucket({required this.label, required this.value, required this.lastYear});

  final String label;
  final String value;
  final String lastYear;
}

class OwnerCompare {
  const OwnerCompare({
    required this.mode,
    required this.against,
    this.company,
    required this.nowFrom,
    required this.nowTo,
    required this.wasFrom,
    required this.wasTo,
    this.companies = const [],
    this.columns = const [],
    this.rows = const [],
    required this.grain,
    required this.figure,
    this.trend = const [],
  });

  static const String companiesMode = 'companies';
  static const String branchesMode = 'branches';

  final String mode;
  final String against;
  final String? company;
  final String nowFrom;
  final String nowTo;
  final String wasFrom;
  final String wasTo;
  final List<OwnerCompany> companies;
  final List<CompareColumn> columns;
  final List<CompareRow> rows;
  final String grain;
  final String figure;
  final List<TrendBucket> trend;

  factory OwnerCompare.fromJson(Map<String, dynamic> j) {
    final now = j['now'] is Map ? j['now'] as Map : const {};
    final was = j['was'] is Map ? j['was'] as Map : const {};
    return OwnerCompare(
      mode: _text(j['mode'], companiesMode),
      against: _text(j['against'], 'previous_month'),
      company: j['company']?.toString(),
      nowFrom: _text(now['from']),
      nowTo: _text(now['to']),
      wasFrom: _text(was['from']),
      wasTo: _text(was['to']),
      companies: [for (final c in _maps(j['companies'])) OwnerCompany(id: _text(c['id']), name: _text(c['name']))],
      columns: [
        for (final c in _maps(j['columns']))
          CompareColumn(key: _text(c['key']), label: _text(c['label']), trend: c['trend'] == true),
      ],
      rows: [
        for (final r in _maps(j['rows']))
          CompareRow(
            id: _text(r['id']),
            name: _text(r['name']),
            branch: r['branch']?.toString(),
            now: _cells(r['now']),
            was: _cells(r['was']),
            change: _cells(r['change']),
          ),
      ],
      grain: _text(j['grain'], 'monthly'),
      figure: _text(j['figure'], 'sales'),
      trend: [
        for (final b in _maps(j['trend']))
          TrendBucket(label: _text(b['label']), value: _text(b['value'], '0'), lastYear: _text(b['last_year'], '0')),
      ],
    );
  }
}

// ── বিশ্লেষণ ────────────────────────────────────────────────────────────────────────────────

class RankRow {
  const RankRow({required this.name, required this.company, this.value});

  final String name;
  final String company;
  final String? value;
}

class RankList {
  const RankList({required this.key, required this.label, required this.percent, this.rows = const [], this.refused = const []});

  final String key;
  final String label;
  final bool percent;
  final List<RankRow> rows;

  /// যে কোম্পানিগুলোতে এই রিপোর্টের চাবি নেই — খালি তালিকা মানে "কেউ কেনেনি" নয়
  final List<String> refused;
}

/// ফোনের সাধারণ রিপোর্টের পর্দা যা চায় ([ReportSummary]-এর payload)
class OwnerReportCard {
  const OwnerReportCard({required this.key, required this.title, required this.hint, required this.module, this.filters = const [], this.allowed = false});

  final String key;
  final String title;
  final String hint;
  final String module;
  final List<String> filters;
  final bool allowed;

  /// ⓘ সার্ভারের লেখা মানুষটার ভাষায় (ইংরেজিও হতে পারে) — চেনা রিপোর্টের নাম ফোন নিজে বলে, অ্যাপের ভাষায় (ARB, 0.4.43)
  static Map<String, ({String title, String hint})> known(AppL10n w) => {
        'executive.profit_by_customer': (title: w.ownerReportProfitByCustomer, hint: w.ownerReportProfitByCustomerHint),
      };

  String get shownTitle => known(AppLocale.l10n)[key]?.title ?? title;
  String get shownHint => known(AppLocale.l10n)[key]?.hint ?? hint;

  Map<String, dynamic> get summary => {'key': key, 'title': shownTitle, 'module': module, 'filters': filters};

  static OwnerReportCard? fromJson(Object? raw) {
    if (raw is! Map) return null;
    return OwnerReportCard(
      key: _text(raw['key']),
      title: _text(raw['title']),
      hint: _text(raw['hint']),
      module: _text(raw['module'], 'executive'),
      filters: _strings(raw['filters']),
      allowed: raw['allowed'] == true,
    );
  }
}

class OwnerAnalysis {
  const OwnerAnalysis({
    required this.period,
    required this.from,
    required this.to,
    this.many = false,
    this.buckets = const [],
    this.ageingRefused = const [],
    this.lists = const [],
    this.canSeeCost = true,
    this.report,
  });

  final String period;
  final String from;
  final String to;
  final bool many;

  /// বাকির বয়সের খোপ — শেষটা (`outstanding`) মোট বাকি
  final List<({String key, String label, String value})> buckets;
  final List<String> ageingRefused;
  final List<RankList> lists;
  final bool canSeeCost;
  final OwnerReportCard? report;

  factory OwnerAnalysis.fromJson(Map<String, dynamic> j) {
    final ageing = j['ageing'] is Map ? (j['ageing'] as Map).cast<String, dynamic>() : const <String, dynamic>{};
    return OwnerAnalysis(
      period: _text(j['period'], 'month'),
      from: _text(j['from']),
      to: _text(j['to']),
      many: j['many'] == true,
      buckets: [
        for (final b in _maps(ageing['buckets'])) (key: _text(b['key']), label: _text(b['label']), value: _text(b['value'], '0')),
      ],
      ageingRefused: _strings(ageing['refused']),
      lists: [
        for (final l in _maps(j['lists']))
          RankList(
            key: _text(l['key']),
            label: _text(l['label']),
            percent: l['kind'] == 'percent',
            rows: [
              for (final r in _maps(l['rows']))
                RankRow(name: _text(r['name'], '—'), company: _text(r['company_name']), value: r['value']?.toString()),
            ],
            refused: _strings(l['refused']),
          ),
      ],
      canSeeCost: j['can_see_cost'] != false,
      report: OwnerReportCard.fromJson(j['report']),
    );
  }
}

// ── সতর্কতা ─────────────────────────────────────────────────────────────────────────────────

class AlertPart {
  const AlertPart({required this.name, this.count});

  final String name;

  /// `null` = ঐ কোম্পানিতে দেখার চাবি নেই (শূন্য নয়)
  final int? count;
}

class OwnerAlert {
  const OwnerAlert({required this.kind, required this.label, required this.total, this.parts = const []});

  final String kind;
  final String label;

  /// ওয়েবের "গ্রুপের মোট" কলাম
  final int total;
  final List<AlertPart> parts;
}

class WaitingPaper {
  const WaitingPaper({required this.company, required this.label, this.amount, this.requestedAt});

  final String company;
  final String label;
  final String? amount;
  final String? requestedAt;
}

class OwnerAlerts {
  const OwnerAlerts({this.companies = const [], this.alerts = const [], this.waiting = const []});

  final List<OwnerCompany> companies;
  final List<OwnerAlert> alerts;
  final List<WaitingPaper> waiting;

  factory OwnerAlerts.fromJson(Map<String, dynamic> j) => OwnerAlerts(
        companies: [
          for (final c in _maps(j['companies'])) OwnerCompany(id: _text(c['id']), name: _text(c['name']), current: c['current'] == true),
        ],
        alerts: [
          for (final a in _maps(j['alerts']))
            OwnerAlert(
              kind: _text(a['kind']),
              label: _text(a['label']),
              total: (a['total'] as num?)?.toInt() ?? 0,
              parts: [
                for (final p in _maps(a['companies'])) AlertPart(name: _text(p['name']), count: (p['count'] as num?)?.toInt()),
              ],
            ),
        ],
        waiting: [
          for (final w in _maps(j['waiting']))
            WaitingPaper(
              company: _text(w['company']),
              label: _text(w['label']),
              amount: w['amount']?.toString(),
              requestedAt: w['requested_at']?.toString(),
            ),
        ],
      );
}

// ── আগের দিনের হিসাব ─────────────────────────────────────────────────────────────────────────

class HistoryRow {
  const HistoryRow({required this.company, required this.companyName, this.branch, required this.name, this.whole = false, this.values = const {}});

  final String company;
  final String companyName;
  final String? branch;
  final String name;

  /// গোটা কোম্পানির সারি — ঐ কোম্পানির মোট
  final bool whole;
  final Map<String, String?> values;
}

class HistoryPoint {
  const HistoryPoint({required this.day, required this.value, this.monthBefore});

  final String day;
  final String value;

  /// গত মাসের একই দিন — রাতের হিসাব না থাকলে `null` (ফাঁক, ৳০ নয়)
  final String? monthBefore;
}

class OwnerHistory {
  const OwnerHistory({required this.date, required this.figure, this.figures = const [], this.rows = const [], this.series = const []});

  final String date;
  final String figure;
  final List<({String key, String label, bool money})> figures;
  final List<HistoryRow> rows;
  final List<HistoryPoint> series;

  factory OwnerHistory.fromJson(Map<String, dynamic> j) => OwnerHistory(
        date: _text(j['date']),
        figure: _text(j['figure'], 'receivable'),
        figures: [
          for (final f in _maps(j['figures'])) (key: _text(f['key']), label: _text(f['label']), money: f['money'] != false),
        ],
        rows: [
          for (final r in _maps(j['rows']))
            HistoryRow(
              company: _text(r['company']),
              companyName: _text(r['company_name']),
              branch: r['branch']?.toString(),
              name: _text(r['name']),
              whole: r['whole'] == true,
              values: _cells(r['values']),
            ),
        ],
        series: [
          for (final p in _maps(j['series']))
            HistoryPoint(day: _text(p['day']), value: _text(p['value'], '0'), monthBefore: p['month_before']?.toString()),
        ],
      );
}

// ── রিপোর্ট ─────────────────────────────────────────────────────────────────────────────────

class OwnerReports {
  const OwnerReports({this.own = const [], this.views = const [], this.canSchedule = false});

  final List<OwnerReportCard> own;
  final List<({String name, String company})> views;
  final bool canSchedule;

  factory OwnerReports.fromJson(Map<String, dynamic> j) => OwnerReports(
        own: [for (final r in _maps(j['own'])) OwnerReportCard.fromJson(r)!],
        views: [for (final v in _maps(j['views'])) (name: _text(v['name']), company: _text(v['company']))],
        canSchedule: j['can_schedule'] == true,
      );
}

// ── ভাই-কোম্পানির পক্ষ ──────────────────────────────────────────────────────────────────────

class SisterLinkRow {
  const SisterLinkRow({required this.id, required this.company, required this.sister, required this.type, required this.typeLabel, required this.party});

  final String id;
  final String company;
  final String sister;
  final String type;
  final String typeLabel;
  final String party;
}

class SisterLinksPage {
  const SisterLinksPage({this.links = const [], this.total, this.nextPage});

  final List<SisterLinkRow> links;

  /// গোটা তালিকার গোনা, কেবল এই পাতার নয় — পুরনো সার্ভার না পাঠালে `null` (তখন পট্টিই নেই)
  final int? total;
  final int? nextPage;

  factory SisterLinksPage.fromJson(Map<String, dynamic> j) => SisterLinksPage(
        links: [
          for (final l in _maps(j['links']))
            SisterLinkRow(
              id: _text(l['id']),
              company: _text(l['company']),
              sister: _text(l['sister']),
              type: _text(l['type']),
              typeLabel: _text(l['type_label']),
              party: _text(l['party'], '—'),
            ),
        ],
        total: (j['total'] as num?)?.toInt(),
        nextPage: (j['next_page'] as num?)?.toInt(),
      );
}

// ── দরজা ────────────────────────────────────────────────────────────────────────────────────

abstract class OwnerPagesApi {
  Future<OwnerCompare> compare({required String mode, String? company, required String against, required String grain, required String figure});

  Future<OwnerAnalysis> analysis(String period);

  Future<OwnerAlerts> alerts();

  Future<OwnerHistory> history({String? date, required String figure});

  Future<OwnerReports> reports();

  Future<SisterLinksPage> links(int page);
}

class ServerOwnerPagesApi implements OwnerPagesApi {
  const ServerOwnerPagesApi();

  Future<Map<String, dynamic>> _get(String path, [Map<String, Object?> query = const {}]) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(path,
        queryParameters: {for (final e in query.entries) if (e.value != null) e.key: e.value});
    return response.data ?? const <String, dynamic>{};
  }

  @override
  Future<OwnerCompare> compare({required String mode, String? company, required String against, required String grain, required String figure}) async =>
      OwnerCompare.fromJson(await _get('/executive/compare',
          {'mode': mode, 'company': company, 'against': against, 'grain': grain, 'figure': figure}));

  @override
  Future<OwnerAnalysis> analysis(String period) async =>
      OwnerAnalysis.fromJson(await _get('/executive/analysis', {'period': period}));

  @override
  Future<OwnerAlerts> alerts() async => OwnerAlerts.fromJson(await _get('/executive/alerts'));

  @override
  Future<OwnerHistory> history({String? date, required String figure}) async =>
      OwnerHistory.fromJson(await _get('/executive/history', {'date': date, 'figure': figure}));

  @override
  Future<OwnerReports> reports() async => OwnerReports.fromJson(await _get('/executive/reports'));

  @override
  Future<SisterLinksPage> links(int page) async =>
      SisterLinksPage.fromJson(await _get('/executive/sister-links', {'page': page}));
}
