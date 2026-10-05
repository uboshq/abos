import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import '../api_client/network_errors.dart';
import '../approvals/approvals_api.dart';
import '../records/today_record.dart';
import 'approvals_launcher_widget.dart';
import 'today_launcher_widget.dart';

/// Fills both home-screen widgets, from anywhere.
///
/// <p>Called after sign-in, when the app is left for the home screen, and on
/// the background tick. Each widget is fetched and handled on its own: one
/// door being shut for this account must not cost the other widget its
/// figures.
///
/// <p><b>Never throws</b> and nothing a caller depends on waits for it. A
/// launcher tile that will not update must not hold up a sign-in.
class LauncherWidgetRefresh {
  const LauncherWidgetRefresh._();

  static Future<void> refresh({
    Future<TodayRecord> Function()? fetchToday,
    Future<ApprovalPage> Function()? loadApprovals,
    DateTime Function()? now,
  }) async {
    final clock = now ?? DateTime.now;
    await _today(fetchToday ?? TodayApi.fetch, clock);
    await _approvals(loadApprovals ?? () => ApprovalsApi.pending(), clock);
  }

  /// Both widgets wiped. For sign-out and for a session the server ended.
  static Future<void> clear() async {
    await TodayLauncherWidget.clear();
    await ApprovalsLauncherWidget.clear();
  }

  static Future<void> _today(
    Future<TodayRecord> Function() fetch,
    DateTime Function() clock,
  ) async {
    try {
      await TodayLauncherWidget.publish(await fetch(), at: clock());
    } catch (error) {
      switch (_kind(error)) {
        case _Failure.offline:
          // Leave whatever is there. Stale figures with their own hour on
          // them are more use than a row of dashes, and the hour is what
          // stops them being mistaken for current.
          return;
        case _Failure.notAllowed:
          await TodayLauncherWidget.unavailable();
        case _Failure.absent:
          await TodayLauncherWidget.absentOnServer();
        case _Failure.other:
          debugPrint('ABOS today widget refresh failed: $error');
      }
    }
  }

  static Future<void> _approvals(
    Future<ApprovalPage> Function() load,
    DateTime Function() clock,
  ) async {
    try {
      final page = await load();
      await ApprovalsLauncherWidget.publish(
        page.rows,
        hasMore: page.nextCursor != null,
        at: clock(),
      );
    } catch (error) {
      switch (_kind(error)) {
        case _Failure.offline:
          return;
        case _Failure.notAllowed:
          // An ordinary state, not a failure: most roles have no approval
          // inbox at all. Nothing is waiting for them, because for them
          // nothing ever is.
          await ApprovalsLauncherWidget.publish(const [], at: clock());
        case _Failure.absent:
        case _Failure.other:
          debugPrint('ABOS approvals widget refresh failed: $error');
      }
    }
  }

  static _Failure _kind(Object error) {
    if (isNetworkError(error)) return _Failure.offline;
    if (isRouteAbsent(error)) return _Failure.absent;
    if (error is DioException) {
      final status = error.response?.statusCode;
      if (status == 401 || status == 403) return _Failure.notAllowed;
    }
    return _Failure.other;
  }
}

enum _Failure { offline, notAllowed, absent, other }
