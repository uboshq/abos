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

  /// Remarks are optional here and required on [reject] — that asymmetry is
  /// the server's (`ApprovalEngine::approve(..., ?string $remarks)` against
  /// `reject(..., string $remarks)`), kept rather than smoothed over: saying
  /// yes needs no explanation, saying no does.
  static Future<void> approve(String id, {String? remarks}) async {
    await ApiClient.dio.post<Map<String, dynamic>>(
      '/approvals/$id/approve',
      data: {
        if (remarks != null && remarks.trim().isNotEmpty)
          'remarks': remarks.trim(),
      },
    );
  }

  static Future<void> reject(String id, {required String remarks}) async {
    final reason = remarks.trim();
    // Checked here as well as on the screen and on the server. A rejection
    // with no reason reaches the requester as a refusal they cannot act on,
    // and they will ask a person instead of reading the record.
    if (reason.isEmpty) {
      throw ArgumentError('প্রত্যাখ্যানের কারণ লেখা বাধ্যতামূলক');
    }

    await ApiClient.dio.post<Map<String, dynamic>>(
      '/approvals/$id/reject',
      data: {'remarks': reason},
    );
  }
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
enum ApprovalFailure { notYours, alreadyDecided, network, other }

ApprovalFailure approvalFailureOf(Object error) {
  if (error is! DioException) return ApprovalFailure.other;

  return switch (error.response?.statusCode) {
    403 => ApprovalFailure.notYours,
    404 || 409 => ApprovalFailure.alreadyDecided,
    _ => error.type == DioExceptionType.connectionError ||
            error.type == DioExceptionType.connectionTimeout ||
            error.type == DioExceptionType.sendTimeout ||
            error.type == DioExceptionType.receiveTimeout
        ? ApprovalFailure.network
        : ApprovalFailure.other,
  };
}
