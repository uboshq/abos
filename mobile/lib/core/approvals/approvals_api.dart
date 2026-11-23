import '../../core/api_client/once_key.dart';
import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../sync_engine/poll_gate.dart';
import '../records/approval_record.dart';
import '../locale/app_locale.dart';

/// The three doors of docs/Contract §৫ — the first thing this app does that
/// is not sync.
///
/// <p><b>Deliberately not part of the sync engine.</b> An approval moves a
/// document forward: stock shifts, money is released. Offline that cannot be
/// done honestly — two phones out of coverage would each approve the same
/// bill once — which is the same reasoning as the owner's decision that only
/// orders may be written with no signal. So there is no queue here, no
/// watermark, and no retry: the call needs a network and says so when it does
/// not have one.
class ApprovalsApi {
  const ApprovalsApi._();

  /// ⭐ The first page (limit 50), one answer for the home's count, its "waiting" card and the launcher refresh
  /// (polling cut, 23 Nov 2026: cold start used to ask four times). [fresh] asks the server (joining a call under
  /// way); otherwise an answer of the last 30 s is reused. ⛔ Any write wipes it ([SharedAnswer.clearAll]).
  static Future<ApprovalPage> pendingShared({bool fresh = false}) =>
      fresh ? _firstPage.fresh(pending) : _firstPage.recent(pending);

  static final SharedAnswer<ApprovalPage> _firstPage = SharedAnswer<ApprovalPage>();

  /// One page of what is waiting for this person.
  ///
  /// <p>The server pages this from `pendingQueryFor()` rather than loading
  /// every row to show a few — the two were separated on the server the day
  /// before precisely because counting and showing are different jobs.
  static Future<ApprovalPage> pending({int limit = 50, String? cursor}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/approvals/pending',
      queryParameters: {
        'limit': limit,
        if (cursor != null) 'cursor': cursor,
      },
    );

    final body = response.data ?? const <String, dynamic>{};
    final rows = (body['rows'] as List?) ?? const [];

    return ApprovalPage(
      rows: rows
          .whereType<Map>()
          .map((row) => ApprovalRecord(row.cast<String, dynamic>()))
          // Salary is approved at the desk — see ApprovalRecord.isPayroll for
          // why the phone drops it a second time even though the server's
          // query already has.
          .where((approval) => !approval.isPayroll)
          .toList(),
      nextCursor: body['nextCursor'] as String?,
    );
  }

  /// ⭐ ইনবক্স, ওয়েবের চার ট্যাব আর একই নিয়মে — `GET /approvals/inbox` (অনুমোদন ভাগ ৩, দফা ২১; পরিকল্পনার পাতা ৬, ছবি ১)।
  /// ট্যাব, কাজের চিপ, দেরি, শেষ সময়, ধাপ আর দুই মোট — সবই সার্ভারের লেখা; ফোন কেবল আঁকে। পাতা নম্বরে (`page`)।
  /// ⚠️ পুরনো সার্ভারে দরজাটা নেই (৪০৪) — পর্দা তখন [pending]-এ ফেরে ([[ApprovalInboxScreen]])।
  static Future<InboxPage> inbox({String tab = 'waiting', String? module, bool late = false, int page = 1, String q = '', String sort = 'late'}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/approvals/inbox',
      queryParameters: {
        'tab': tab,
        if (module != null) 'module': module,
        if (late) 'late': 1,
        if (page > 1) 'page': page,
        // ⭐ খোঁজা আর সাজানো — নমুনা v4 (সার্ভার 6c75e093c: `q` নম্বর/পক্ষ/যিনি চেয়েছেন, `sort` late|new|amount)
        if (q.trim().isNotEmpty) 'q': q.trim(),
        if (sort != 'late') 'sort': sort,
      },
    );
    return InboxPage.fromJson(response.data ?? const <String, dynamic>{});
  }

  /// ⭐ একসাথে অনুমোদনের আগে দেখা — `POST /approvals/bulk-preview` (পাতা ৬, ছবি ৩)। কিছু জমা পড়ে না।
  static Future<BulkPreview> bulkPreview(List<String> ids) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>('/approvals/bulk-preview', data: {'ids': ids});
    return BulkPreview.fromJson(response.data ?? const <String, dynamic>{});
  }

  /// ⭐ একসাথে অনুমোদন — `POST /approvals/bulk-approve`। ⛔ সার্ভার আবার নিজে দেখে; ফোনের বাছাই বিশ্বাস করে না।
  static Future<BulkResult> bulkApprove(List<String> ids, {String? remarks}) async {
    final response = await OnceKey.of('approval-bulk:${ids.join(',')}').send(() => ApiClient.dio.post<Map<String, dynamic>>(
          '/approvals/bulk-approve',
          data: {'ids': ids, if (remarks != null && remarks.trim().isNotEmpty) 'remarks': remarks.trim()},
        ));
    return BulkResult.fromJson(response.data ?? const <String, dynamic>{});
  }

  /// ⭐ কাগজের সংযুক্তি — `GET /approvals/{id}/attachments/{file}` (পাতা ৬, ছবি ২)। কেবল এই কাগজের ফাইল, সই-পাহারায়।
  static Future<List<int>> attachment(String id, int file) async {
    final response = await ApiClient.dio.get<List<int>>(
      '/approvals/$id/attachments/$file',
      options: Options(responseType: ResponseType.bytes),
    );
    return response.data ?? const <int>[];
  }

  /// ⭐ আমার পাঠানো অনুরোধ — `GET /approvals/mine` (মালিক, ১৭ অক্টোবর ২০২৬: "direct webpage app e bujajayna")।
  /// নতুন আগে; অবস্থা আর শেষ কথা সার্ভারের লেখা, ফোন কেবল আঁকে।
  static Future<MyRequestsPage> mine({int limit = 50, String? cursor}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/approvals/mine',
      queryParameters: {'limit': limit, if (cursor != null) 'cursor': cursor},
    );
    return MyRequestsPage.fromJson(response.data ?? const <String, dynamic>{});
  }

  /// Remarks are optional here and required on [reject] — that asymmetry is
  /// the server's (`ApprovalEngine::approve(..., ?string $remarks)` against
  /// `reject(..., string $remarks)`), kept rather than smoothed over: saying
  /// yes needs no explanation, saying no does.
  /// ⭐ সইয়ের আগে বিস্তারিত — `GET /approvals/{id}/sheet` (মালিক, ৭ অক্টোবর ২০২৬: "approval e kono kichui details
  /// dekhay na")। কাগজ নিজে যা বলে: ঘর আর মান, সারি, যোগফল — সবই লেখা, ফোন কেবল আঁকে।
  static Future<ApprovalSheet> sheet(String id) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/approvals/$id/sheet');
    return ApprovalSheet.fromJson(response.data ?? const {});
  }

  /// ⛔ [seenAmount]: কার্ডে যে অঙ্ক দেখে সই — কাগজের অঙ্ক এর মধ্যে বদলালে সার্ভার ৪১২ দেয় ([[ApprovalFailure.amountChanged]];
  /// ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)।
  static Future<void> approve(String id, {String? remarks, String? seenAmount}) async {
    await OnceKey.of('approval-approve:$id').send(() => ApiClient.dio.post<Map<String, dynamic>>(
      '/approvals/$id/approve',
      data: {
        if (remarks != null && remarks.trim().isNotEmpty)
          'remarks': remarks.trim(),
        if (seenAmount != null) 'seenAmount': seenAmount,
      },
    ));
  }

  static Future<void> reject(String id, {required String remarks, String? reasonCode}) async {
    final reason = remarks.trim();
    // Checked here as well as on the screen and on the server. A rejection
    // with no reason reaches the requester as a refusal they cannot act on,
    // and they will ask a person instead of reading the record.
    if (reason.isEmpty) {
      throw ArgumentError(AppLocale.l10n.approvalsRejectReasonRequired);
    }

    await OnceKey.of('approval-reject:$id').send(() => ApiClient.dio.post<Map<String, dynamic>>(
      '/approvals/$id/reject',
      // ⭐ কারণের কোড — সার্ভার আগে থেকেই নেয় ([[ApprovalDecision::REASONS]]), অ্যাপ এতদিন পাঠাত না (নমুনা v4, ছবি ৫)
      data: {'remarks': reason, if (reasonCode != null) 'reasonCode': reasonCode},
    ));
  }

  /// ⭐ সংশোধনে ফেরত — `POST /approvals/{id}/send-back` (কাগজ লেখকের কাছে ফেরে, ঠিক করে আবার পাঠানো যায়; RETURNED)।
  /// ⛔ এটা প্রত্যাখ্যান নয়: ০.৪.৪২ পর্যন্ত "সংশোধনে ফেরত দিন" বোতাম ভুল করে [reject] ডাকত — চূড়ান্ত না (a6 ধরেছেন,
  /// fe, ২১ অক্টোবর ২০২৬)। কারণ লেখা বাধ্যতামূলক — লেখককে জানতে হয় কী ঠিক করতে হবে।
  static Future<void> sendBack(String id, {required String remarks}) async {
    final reason = remarks.trim();
    if (reason.isEmpty) {
      throw ArgumentError(AppLocale.l10n.approvalsSendBackReasonRequired);
    }

    await OnceKey.of('approval-send-back:$id').send(() => ApiClient.dio.post<Map<String, dynamic>>(
      '/approvals/$id/send-back',
      data: {'remarks': reason},
    ));
  }
}

/// ইনবক্সের এক পাতা — চার ট্যাব, কাজের চিপ, সারি আর দুই মোট (`GET /approvals/inbox`)।
class InboxPage {
  const InboxPage({
    required this.tab,
    required this.tabs,
    required this.modules,
    required this.rows,
    this.module,
    this.allCount = 0,
    this.lateCount = 0,
    this.pageCount = 0,
    this.pageAmount,
    this.count = 0,
    this.amount,
    this.nextPage,
  });

  final String tab;

  /// (key, নাম, সংখ্যা) — ওয়েবের চার ট্যাব, ক্রমে
  final List<(String, String, int)> tabs;

  /// (key, নাম, সংখ্যা) — কাজের চিপ
  final List<(String, String, int)> modules;
  final String? module;
  final int allCount;
  final int lateCount;
  final List<ApprovalRecord> rows;

  /// এই পাতার আর পুরো তালিকার মোট — অঙ্ক স্ট্রিং, সার্ভারের লেখা
  final int pageCount;
  final String? pageAmount;
  final int count;
  final String? amount;
  final int? nextPage;

  static List<(String, String, int)> _named(Object? list) => [
        for (final t in (list as List?) ?? const [])
          if (t is Map) (t['key']?.toString() ?? '', t['label']?.toString() ?? '', (t['count'] as num?)?.toInt() ?? 0),
      ];

  factory InboxPage.fromJson(Map<String, dynamic> body) {
    final totals = (body['totals'] as Map?)?.cast<String, dynamic>() ?? const <String, dynamic>{};
    return InboxPage(
      tab: body['tab']?.toString() ?? 'waiting',
      tabs: _named(body['tabs']),
      modules: _named(body['modules']),
      module: body['module']?.toString(),
      allCount: (body['allCount'] as num?)?.toInt() ?? 0,
      lateCount: (body['lateCount'] as num?)?.toInt() ?? 0,
      rows: [
        for (final r in (body['rows'] as List?) ?? const [])
          if (r is Map) ApprovalRecord(r.cast<String, dynamic>()),
      ].where((a) => !a.isPayroll).toList(),
      pageCount: (totals['pageCount'] as num?)?.toInt() ?? 0,
      pageAmount: totals['pageAmount']?.toString(),
      count: (totals['count'] as num?)?.toInt() ?? 0,
      amount: totals['amount']?.toString(),
      nextPage: (body['nextPage'] as num?)?.toInt(),
    );
  }
}

/// একসাথে অনুমোদনের এক সারি — কোন কাগজ, কত; বাদ পড়লে কেন
class BulkLine {
  const BulkLine({required this.id, required this.work, this.documentNo, this.amount, this.reason});

  final String id;
  final String work;
  final String? documentNo;
  final String? amount;

  /// বাদ পড়ার কারণ — ওয়েবের একই লেখা; যেটা যাবে তাতে null
  final String? reason;

  factory BulkLine.fromJson(Map<String, dynamic> j) => BulkLine(
        id: j['id']?.toString() ?? '',
        work: j['work']?.toString() ?? '',
        documentNo: j['documentNo']?.toString(),
        amount: j['amount']?.toString(),
        reason: j['reason']?.toString(),
      );
}

class BulkPreview {
  const BulkPreview({required this.sign, required this.skip, this.total});

  final List<BulkLine> sign;
  final List<BulkLine> skip;
  final String? total;

  factory BulkPreview.fromJson(Map<String, dynamic> body) => BulkPreview(
        sign: [for (final r in (body['sign'] as List?) ?? const []) if (r is Map) BulkLine.fromJson(r.cast<String, dynamic>())],
        skip: [for (final r in (body['skip'] as List?) ?? const []) if (r is Map) BulkLine.fromJson(r.cast<String, dynamic>())],
        total: body['total']?.toString(),
      );
}

class BulkResult {
  const BulkResult({required this.done, required this.skipped});

  final int done;

  /// কারণ ধরে কয়টা বাদ — সার্ভার আবার দেখার পরে
  final Map<String, int> skipped;

  int get skippedCount => skipped.values.fold(0, (a, b) => a + b);

  factory BulkResult.fromJson(Map<String, dynamic> body) => BulkResult(
        done: (body['done'] as num?)?.toInt() ?? 0,
        skipped: {
          for (final e in ((body['skipped'] as Map?) ?? const {}).entries) e.key.toString(): (e.value as num?)?.toInt() ?? 0,
        },
      );
}

/// যাত্রাপথের এক ধাপ — কোন ধাপ, এখন কোথায়, কে কী বললেন
class JourneyStep {
  const JourneyStep({required this.level, required this.state, this.name, this.tookHours, this.by = const []});

  final int level;

  /// approved · rejected · returned · forwarded · now · waiting
  final String state;
  final String? name;
  final int? tookHours;

  /// (নাম, সিদ্ধান্তের লেখা, কখন, মন্তব্য, কার হয়ে)
  final List<(String, String, DateTime?, String?, String?)> by;

  factory JourneyStep.fromJson(Map<String, dynamic> j) => JourneyStep(
        level: (j['level'] as num?)?.toInt() ?? 0,
        state: j['state']?.toString() ?? 'waiting',
        name: j['name']?.toString(),
        tookHours: (j['tookHours'] as num?)?.toInt(),
        by: [
          for (final w in (j['by'] as List?) ?? const [])
            if (w is Map)
              (
                w['name']?.toString() ?? '—',
                w['label']?.toString() ?? '',
                DateTime.tryParse(w['at']?.toString() ?? '')?.toLocal(),
                switch (w['remarks']?.toString().trim()) { final r? when r.isNotEmpty => r, _ => null },
                w['for']?.toString(),
              ),
        ],
      );
}

class ApprovalSheet {
  const ApprovalSheet({
    this.facts = const [],
    this.columns = const [],
    this.rows = const [],
    this.totals = const {},
    this.requesterName,
    this.requestedAt,
    this.amountWords,
    this.deadlineText,
    this.deadlineTone,
    this.stepAt,
    this.stepOf,
    this.standInFor,
    this.journey = const [],
    this.attachments = const [],
    this.whyRule,
    this.whyStep,
    this.whyReason,
    this.impact = const [],
    this.impactChecks = const [],
  });

  /// ⭐ "অনুমোদন দিলে কী হবে" — (নাম, এখন, পরে); নমুনা v4, ছবি ২ ([[Voucher::approvalImpact()]]); পুরনো সার্ভারে খালি
  final List<(String, String, String)> impact;

  /// (ঠিক কি না, লেখা) — যেমন "✓ খাতে টাকা আছে"
  final List<(bool, String)> impactChecks;

  /// ⭐ "কেন আপনার অনুমোদন লাগছে" — নমুনা v4 (মালিক "ok", ৮ নভেম্বর ২০২৬): ইঞ্জিনের নিয়ম, ধাপ, আর যিনি চেয়েছেন তার কারণ।
  /// ⚠️ পুরনো সার্ভার পাঠায় না — তখন তিনটাই null আর বাক্সটা আসে না
  final String? whyRule;
  final String? whyStep;
  final String? whyReason;

  // ── ⭐ কাগজের পুরো পাতা — পাতা ৬, ছবি ২ (দফা ২১); পুরনো সার্ভারে খালি, তখন পাতা আগের মতো কেবল লাইন দেখায়
  final String? requesterName;
  final DateTime? requestedAt;
  final String? amountWords;
  final String? deadlineText;
  final String? deadlineTone;
  final int? stepAt;
  final int? stepOf;
  final String? standInFor;
  final List<JourneyStep> journey;

  /// (id, নাম, আকার)
  final List<(int, String, String)> attachments;

  /// ঘর আর মান — তারিখ, পক্ষ, মাধ্যম, বর্ণনা …
  final List<(String, String)> facts;

  /// কলাম: key, নাম, অঙ্ক কি না
  final List<(String, String, bool)> columns;
  final List<Map<String, String?>> rows;
  final Map<String, String> totals;

  factory ApprovalSheet.fromJson(Map<String, dynamic> json) => ApprovalSheet(
        facts: [
          for (final f in (json['facts'] as List?) ?? const [])
            if (f is Map) (f['label']?.toString() ?? '', f['value']?.toString() ?? ''),
        ],
        columns: [
          for (final c in (json['columns'] as List?) ?? const [])
            if (c is Map) (c['key']?.toString() ?? '', c['label']?.toString() ?? '', c['numeric'] == true),
        ],
        rows: [
          for (final r in (json['rows'] as List?) ?? const [])
            if (r is Map) {for (final e in r.entries) e.key.toString(): e.value?.toString()},
        ],
        totals: {
          for (final e in ((json['totals'] as Map?) ?? const {}).entries) e.key.toString(): e.value?.toString() ?? '',
        },
        requesterName: (json['summary'] as Map?)?['requesterName']?.toString(),
        requestedAt: DateTime.tryParse((json['summary'] as Map?)?['requestedAt']?.toString() ?? '')?.toLocal(),
        amountWords: (json['summary'] as Map?)?['amountWords']?.toString(),
        deadlineText: ((json['summary'] as Map?)?['deadline'] as Map?)?['text']?.toString(),
        deadlineTone: ((json['summary'] as Map?)?['deadline'] as Map?)?['tone']?.toString(),
        stepAt: (((json['summary'] as Map?)?['step'] as Map?)?['at'] as num?)?.toInt(),
        stepOf: (((json['summary'] as Map?)?['step'] as Map?)?['of'] as num?)?.toInt(),
        standInFor: (json['summary'] as Map?)?['standInFor']?.toString(),
        journey: [
          for (final s in (json['journey'] as List?) ?? const [])
            if (s is Map) JourneyStep.fromJson(s.cast<String, dynamic>()),
        ],
        attachments: [
          for (final a in (json['attachments'] as List?) ?? const [])
            if (a is Map && a['id'] is num) ((a['id'] as num).toInt(), a['name']?.toString() ?? '', a['size']?.toString() ?? ''),
        ],
        whyRule: _blankless((json['why'] as Map?)?['rule']),
        whyStep: _blankless((json['why'] as Map?)?['step']),
        whyReason: _blankless((json['why'] as Map?)?['reason']),
        impact: [
          for (final r in ((json['impact'] as Map?)?['rows'] as List?) ?? const [])
            if (r is Map) (r['label']?.toString() ?? '', r['before']?.toString() ?? '', r['after']?.toString() ?? ''),
        ],
        impactChecks: [
          for (final c in ((json['impact'] as Map?)?['checks'] as List?) ?? const [])
            if (c is Map) (c['ok'] == true, c['text']?.toString() ?? ''),
        ],
      );

  static String? _blankless(Object? value) {
    final text = value?.toString().trim();
    return text == null || text.isEmpty ? null : text;
  }

  /// এক সারি এক লাইনে — মালিকের নিয়ম, টেবিল নয়: "খাত · পক্ষ · বর্ণনা — ডেবিট ৳…"
  String lineOf(Map<String, String?> row) {
    final words = [
      for (final c in columns)
        if (!c.$3 && (row[c.$1] ?? '').trim().isNotEmpty) row[c.$1]!.trim(),
    ];
    final money = [
      for (final c in columns)
        if (c.$3 && (row[c.$1] ?? '').trim().isNotEmpty) '${c.$2} ${row[c.$1]!.trim()}',
    ];
    return [words.join(' · '), money.join(' · ')].where((s) => s.isNotEmpty).join(' — ');
  }

  /// যোগফল এক লাইনে: "মোট — ডেবিট … · ক্রেডিট …"
  String? get totalLine {
    final parts = [
      for (final c in columns)
        if (totals[c.$1] != null && totals[c.$1]!.trim().isNotEmpty) '${c.$2} ${totals[c.$1]}',
    ];
    return parts.isEmpty ? null : AppLocale.l10n.approvalsSheetTotalLine(parts.join(' · '));
  }
}

/// আমার পাঠানো একটা অনুরোধ — কোন কাগজ, কত, কোথায় দাঁড়িয়ে, আর শেষ কথা
class MyRequest {
  const MyRequest({
    required this.id,
    required this.documentNo,
    required this.documentType,
    required this.amount,
    required this.status,
    required this.statusLabel,
    required this.requestedAt,
    required this.summary,
    this.party,
    this.lastBy,
    this.lastLabel,
    this.lastRemarks,
  });

  final String id;
  final String? documentNo;
  final String documentType;
  final String? amount;
  final String status;
  final String statusLabel;
  final String? requestedAt;
  final String? summary;

  /// ⭐ কার কাগজ — নম্বরের লাইনে (মালিক, ১৯ অক্টোবর ২০২৬); পুরনো সার্ভারে null
  final String? party;
  final String? lastBy;
  final String? lastLabel;
  final String? lastRemarks;

  factory MyRequest.fromJson(Map<String, dynamic> j) {
    final last = j['lastDecision'] is Map ? (j['lastDecision'] as Map).cast<String, dynamic>() : null;
    return MyRequest(
      id: j['id']?.toString() ?? '',
      documentNo: j['documentNo']?.toString(),
      documentType: j['documentType']?.toString() ?? '',
      amount: j['amount']?.toString(),
      status: j['status']?.toString() ?? '',
      statusLabel: j['statusLabel']?.toString() ?? '',
      requestedAt: j['requestedAt']?.toString(),
      summary: j['summary']?.toString(),
      party: switch (j['party']?.toString().trim()) { final p? when p.isNotEmpty => p, _ => null },
      lastBy: last?['by']?.toString(),
      lastLabel: last?['label']?.toString(),
      lastRemarks: last?['remarks']?.toString(),
    );
  }
}

class MyRequestsPage {
  const MyRequestsPage({required this.rows, this.nextCursor, this.count, this.pendingCount});

  final List<MyRequest> rows;
  final String? nextCursor;

  /// পুরো তালিকার গোনা আর এখনো অপেক্ষমাণের গোনা — পাতার নয় (পুরনো সার্ভারে null)
  final int? count;
  final int? pendingCount;

  factory MyRequestsPage.fromJson(Map<String, dynamic> body) => MyRequestsPage(
        rows: [
          for (final r in (body['rows'] as List?) ?? const [])
            if (r is Map) MyRequest.fromJson(r.cast<String, dynamic>()),
        ],
        nextCursor: body['nextCursor'] as String?,
        count: (body['count'] as num?)?.toInt(),
        pendingCount: (body['pendingCount'] as num?)?.toInt(),
      );
}

class ApprovalPage {
  const ApprovalPage({required this.rows, this.nextCursor});

  final List<ApprovalRecord> rows;
  final String? nextCursor;

  bool get hasMore => nextCursor != null;
}

/// Why a decision did not go through, in the terms a person can act on.
///
/// <p>The one worth separating is [alreadyDecided]. Two people can be looking
/// at the same inbox, and the second to tap is not seeing an error — they are
/// seeing that their colleague got there first. docs/Contract §৫ rule খ: that
/// row leaves the list quietly, it does not turn red.
enum ApprovalFailure { notYours, alreadyDecided, amountChanged, network, other }

ApprovalFailure approvalFailureOf(Object error) {
  if (error is! DioException) return ApprovalFailure.other;

  return switch (error.response?.statusCode) {
    403 => ApprovalFailure.notYours,
    404 || 409 => ApprovalFailure.alreadyDecided,
    412 => ApprovalFailure.amountChanged,
    _ => error.type == DioExceptionType.connectionError ||
            error.type == DioExceptionType.connectionTimeout ||
            error.type == DioExceptionType.sendTimeout ||
            error.type == DioExceptionType.receiveTimeout
        ? ApprovalFailure.network
        : ApprovalFailure.other,
  };
}
