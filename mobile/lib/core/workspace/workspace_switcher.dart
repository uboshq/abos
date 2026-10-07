import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../api_client/api_client.dart';
import '../api_client/network_errors.dart';
import '../launcher_widgets/launcher_widget_refresh.dart';
import '../sync_engine/reference_cache.dart';
import '../sync_engine/reference_sync.dart';
import '../sync_engine/sync_engine.dart';

/// A switch the server or the phone refused, in a sentence for the person
/// holding it.
class WorkspaceFailure implements Exception {
  const WorkspaceFailure(this.message);

  final String message;

  @override
  String toString() => message;
}

/// Changing company or branch from the phone — `POST /workspace`.
///
/// <p>The owner, 1 October 2026: *"app e company & branch change hoyna"*.
/// The web header has had a switcher since Phase 1; the phone had none, so a
/// person in two companies was stuck in whichever one the server picked.
///
/// <p><b>The server decides, the phone only asks.</b> It writes the choice
/// to the person's own record after checking membership and branch reach;
/// every later request reads the company from that record, never from
/// anything this app sends. So there is nothing here to trust or distrust —
/// only a refusal to turn into Bangla.
///
/// <p><b>Another company = start the phone's copy over.</b> Customers,
/// products and dues cached here belong to the company they came from, so
/// on a company change they are cleared (the same tenant boundary sign-out
/// keeps, [ReferenceCache.clearAll]) and a full pull starts at once — the
/// server resets this device's watermarks in the same call, so the pull
/// brings everything, not just what changed.
///
/// <p>⚠️ <b>A branch change clears nothing.</b> The server keeps the
/// watermarks then (a branch is a view inside the same company), so a
/// cleared cache would be refilled only with what changed since — the list
/// would go quietly short.
///
/// <p>⛔ <b>Not while orders wait.</b> A queued order is pushed into whatever
/// company and branch the person's record names *when it is sent*. Moving
/// with orders still on the phone would file them under the new place, with
/// no error anywhere. So a move that changes where work lands is refused
/// until the queue is empty; "সব শাখা" changes only what is seen, and is
/// allowed.
class WorkspaceSwitcher {
  WorkspaceSwitcher({
    Future<Map<String, dynamic>> Function(String company, String branch)? post,
    Future<void> Function()? clearCache,
    void Function()? forgetLastFailure,
    Future<void> Function()? syncAll,
    Future<void> Function()? refreshWidgets,
    int Function()? pendingCount,
  })  : _post = post ?? _postToServer,
        _clearCache = clearCache ?? ReferenceCache.instance.clearAll,
        _forgetLastFailure = forgetLastFailure ?? ReferenceSync.forgetLastFailure,
        _syncAll = syncAll ?? _pullEverything,
        _refreshWidgets = refreshWidgets ?? LauncherWidgetRefresh.refresh,
        _pendingCount = pendingCount ?? (() => SyncEngine.instance.pendingCount);

  /// "সব শাখা" — the web header's own word for it, sent as-is.
  static const String allBranches = 'all';

  final Future<Map<String, dynamic>> Function(String company, String branch) _post;
  final Future<void> Function() _clearCache;
  final void Function() _forgetLastFailure;
  final Future<void> Function() _syncAll;
  final Future<void> Function() _refreshWidgets;
  final int Function() _pendingCount;

  /// Moves this session to [companyPublicId] and [branch] (a branch
  /// public_id, or [allBranches]). [currentCompanyPublicId] is where the
  /// session is now — it decides whether this is a company change.
  ///
  /// <p>Returns true when the company changed. Throws [WorkspaceFailure]
  /// with a Bangla sentence on any refusal.
  Future<bool> switchTo({
    required String currentCompanyPublicId,
    required String companyPublicId,
    required String branch,
  }) async {
    final movesWork = companyPublicId != currentCompanyPublicId || branch != allBranches;
    final pending = _pendingCount();
    if (movesWork && pending > 0) {
      throw WorkspaceFailure(
        'এই ফোনে $pending টি অর্ডার এখনো পাঠানো হয়নি। আগে ওগুলো পাঠান '
        '(সিঙ্কের অবস্থা দেখুন), তারপর কোম্পানি বা শাখা বদলান — নইলে ওগুলো '
        'নতুন জায়গার নামে বসবে।',
      );
    }

    final Map<String, dynamic> body;
    try {
      body = await _post(companyPublicId, branch);
    } on DioException catch (error) {
      throw WorkspaceFailure(messageFor(error));
    }

    final companyChanged = body['companyChanged'] == true;
    if (companyChanged) {
      await _clearCache();
      _forgetLastFailure();
      // Not awaited: the picker closes now and the lists fill as they come.
      unawaited(_syncAll().catchError((Object error) {
        debugPrint('ABOS workspace: full sync after company change failed ($error)');
      }));
    }
    unawaited(_refreshWidgets().catchError((Object _) {}));
    return companyChanged;
  }

  /// The refusal, in Bangla. The server's own sentence when it wrote one in
  /// Bangla; otherwise one chosen here by status — an owner whose account
  /// reads in English gets English sentences from the server, which this app
  /// never shows (see [serverSentence]).
  @visibleForTesting
  static String messageFor(DioException error) {
    final status = error.response?.statusCode;
    return errorMessageFor(
      error,
      whenAbsent: 'সার্ভার এখনো অ্যাপ থেকে কোম্পানি বদলানো চেনে না। '
          'সার্ভার হালনাগাদ হলে পারবেন; ততক্ষণ ওয়েব থেকে বদলান।',
      fallback: switch (status) {
        403 => 'এই কোম্পানিতে ঢোকার অনুমতি আপনার নেই।',
        422 => 'এই শাখায় যাওয়া গেল না — শাখাটা আপনার নাগালে নেই, বা এই কোম্পানির নয়।',
        _ => 'বদলানো গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।',
      },
    );
  }

  static Future<Map<String, dynamic>> _postToServer(String company, String branch) async {
    final response = await ApiClient.dio.post<Map<String, dynamic>>(
      '/workspace',
      data: {'company': company, 'branch': branch},
    );
    return response.data ?? const {};
  }

  static Future<void> _pullEverything() async {
    await ReferenceSync.syncAll();
  }
}
