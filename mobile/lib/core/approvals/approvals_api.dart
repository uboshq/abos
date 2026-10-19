import '../../core/api_client/once_key.dart';
import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../records/approval_record.dart';

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

  static Future<void> reject(String id, {required String remarks}) async {
    final reason = remarks.trim();
    // Checked here as well as on the screen and on the server. A rejection
    // with no reason reaches the requester as a refusal they cannot act on,
    // and they will ask a person instead of reading the record.
    if (reason.isEmpty) {
      throw ArgumentError('প্রত্যাখ্যানের কারণ লেখা বাধ্যতামূলক');
    }

    await OnceKey.of('approval-reject:$id').send(() => ApiClient.dio.post<Map<String, dynamic>>(
      '/approvals/$id/reject',
      data: {'remarks': reason},
    ));
  }
}

class ApprovalSheet {
  const ApprovalSheet({this.facts = const [], this.columns = const [], this.rows = const [], this.totals = const {}});

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
      );

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
    return parts.isEmpty ? null : 'মোট — ${parts.join(' · ')}';
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
