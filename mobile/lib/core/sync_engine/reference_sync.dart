import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../api_client/api_client.dart';
import '../auth/token_storage.dart';
import 'reference_cache.dart';
import 'sync_capabilities_api.dart';
import 'sync_engine.dart';

/// The download half of sync — reference data (products, customers, prices,
/// dues) pulled into [ReferenceCache] so the pickers that need them work with
/// no signal, the way the offline queue already lets a rep record a sale with
/// none.
///
/// <p><b>The watermark rule, which is the whole difficulty of this file.</b>
/// The server tracks `since` itself, per (deviceId, module) — `GET .../pull`
/// takes no `since` parameter at all. Calling it twice with nothing in between
/// returns the identical batch, which is exactly what makes it safe to retry
/// after a dropped response.
///
/// <p>`POST .../pull-complete` moves that watermark to **now**, not to the
/// last batch's own cutoff. That distinction is the trap: if this file called
/// pull-complete after a batch that still had `hasMore`, every record between
/// that batch's cutoff and "now" — including the ones the page limit left out
/// — would be marked synced **without ever having been pulled**, and would
/// never be sent again. They would simply be missing, silently, forever.
///
/// <p>So the rule here is narrower than "loop until done": **pull-complete is
/// only ever called when a batch's `hasMore` is false and nothing was
/// unreadable.** A module whose backlog does not fit one call stays unfinished
/// and is retried whole on the next sync trigger — slower to catch up than
/// draining it in one session, but nothing is ever skipped to get there
/// faster.
class ReferenceSync {
  const ReferenceSync._();

  /// Pulls every module `GET /sync/capabilities` currently lists, once each. A
  /// module still behind after this (a large first-ever sync) is left for the
  /// next call rather than looped here, so one sync pass cannot spin forever
  /// against a catalogue too big to fit one page.
  ///
  /// <p>Left to throw if `GET /sync/capabilities` itself fails. Swallowing
  /// that looks like the safe default and is not: it makes an outright failure
  /// indistinguishable from "nothing to sync", so a "সিঙ্ক করুন" button
  /// reports **"0 records, all caught up"** for a server that never answered.
  /// A believable wrong answer, rather than an obviously wrong one. Each
  /// caller decides what a failure means to it — a fire-and-forget call at
  /// startup swallows it, a button shows it — and that only works if this file
  /// stops hiding the failure from both.
  static Future<List<ReferenceSyncOutcome>> syncAll() async {
    final List<SyncCapability> capabilities;
    try {
      capabilities = await SyncCapabilitiesApi.list();
    } catch (error) {
      // Recorded before it is rethrown. Every list screen catches this and
      // keeps showing whatever it had, which is right — but without the
      // reason kept somewhere, a phone whose token expired shows "এখনো কোনো
      // গ্রাহক সিঙ্ক হয়নি — নিচে টেনে আবার চেষ্টা করুন" and goes on saying
      // it after every pull, forever. See [lastFailure].
      _lastFailure =
          SyncAttemptFailure.from(error, direction: SyncDirection.pull);
      rethrow;
    }

    final modules = capabilities.map((c) => c.module).toSet();

    final outcomes = <ReferenceSyncOutcome>[];
    SyncAttemptFailure? failure;
    for (final module in modules) {
      try {
        outcomes.add(await _pullOnce(module));
      } catch (error) {
        // Deliberately not narrowed to DioException: a malformed payloadJson
        // (jsonDecode throwing FormatException) is exactly as real a failure
        // mode as a dropped connection, and one module's bad record must not
        // take every other module in this loop down with it.
        debugPrint('ABOS reference sync: $module pull failed ($error)');
        failure ??=
            SyncAttemptFailure.from(error, direction: SyncDirection.pull);
        outcomes.add(ReferenceSyncOutcome(
            module: module, recordCount: 0, caughtUp: false));
      }
    }

    // Cleared on a clean pass, so a reason from two pulls ago never outlives
    // the trouble it described.
    _lastFailure = failure;
    return outcomes;
  }

  static SyncAttemptFailure? _lastFailure;

  /// Why the last pull did not bring anything down, or null if it did.
  ///
  /// <p>⚠️ <b>An empty list and a failed pull look identical on screen, and
  /// they are opposite situations.</b> "No customers have synced yet" is
  /// something a pull fixes; an expired token, a 403, a server that is not
  /// answering are not, and the screen that keeps saying "নিচে টেনে আবার
  /// চেষ্টা করুন" sends somebody to do the one thing that cannot work — the
  /// pull half of the afternoon that [SyncEngine.lastFailureFor] documents.
  ///
  /// <p>In memory, not on disk, for the same reason as the push side: this
  /// describes the last attempt, and a stale reason is worse than none.
  static SyncAttemptFailure? get lastFailure => _lastFailure;

  /// The sentence an empty screen should show instead of "নিচে টেনে আবার
  /// চেষ্টা করুন", when there is one. Null means the last pull was clean and
  /// the list really is empty.
  static String? get troubleSentence => _lastFailure?.sentence;

  /// Called on sign-out, and by tests. A new account's first empty screen
  /// must not carry the previous account's 403 — the same tenant boundary
  /// ReferenceCache.clearAll draws, for the same reason.
  static void forgetLastFailure() => _lastFailure = null;

  /// Puts a failure in place without a server to fail against, so a test can
  /// pump a screen and read what it says. Only tests: production sets this by
  /// actually failing.
  @visibleForTesting
  static void rememberFailureForTest(SyncAttemptFailure failure) =>
      _lastFailure = failure;

  /// ⛔ Inventory audit গ১৮, 4 Oct 2026: a module with more than 1,000 rows never finished — every call brought the
  /// same first page back with `hasMore`, so the rest never reached the phone and the watermark was never written.
  /// ⭐ The server now returns a `cursor` with each page; sending it back (an empty one for the first page) asks for
  /// the page after it. The server keeps nothing for a phone that sends a cursor, and the same cursor brings the same
  /// page — so a lost reply is simply asked for again.
  @visibleForTesting
  static const int maxPagesPerPass = 100;

  /// One module, every page of it in one pass — `GET /sync/{module}/pull` until `hasMore` is false.
  static Future<ReferenceSyncOutcome> _pullOnce(String module) async {
    final deviceId = await TokenStorage.instance.deviceId();

    var cursor = '';
    var hasMore = false;
    var recordCount = 0;
    var unreadable = <String>[];

    for (var page = 0; page < maxPagesPerPass; page++) {
      final response = await ApiClient.dio.get<Map<String, dynamic>>(
        '/sync/$module/pull',
        queryParameters: {
          'deviceId': deviceId,
          'limit': 1000,
          'cursor': cursor
        },
      );
      final body = response.data ?? const {};
      final records = (body['records'] as List?) ?? const [];
      hasMore = body['hasMore'] as bool? ?? false;
      unreadable = ((body['unreadable'] as List?) ?? const [])
          .map((e) => e.toString())
          .toList();
      recordCount += await _store(records);

      final next = body['cursor'] as String?;
      // ⓘ Stop on a failed handler (the next pass starts over), or when the server has nothing more — or names no
      // next page, which an older server never does: then one page per pass, as before.
      if (!hasMore || unreadable.isNotEmpty || next == null || next.isEmpty) {
        break;
      }
      cursor = next;
    }

    // See the class doc comment: only a fully-caught-up module may advance the
    // watermark. A partial batch is stored (the data is still good — the push
    // side keeps unsent rows the same way) but left to be re-fetched, not
    // marked done. The server is required to withhold the watermark itself
    // when `unreadable` is non-empty, but this file checks its own copy of
    // that rule rather than trusting the server never to change.
    final fullyCaughtUp = !hasMore && unreadable.isEmpty;
    if (fullyCaughtUp) {
      await ApiClient.dio.post<void>(
        '/sync/$module/pull-complete',
        queryParameters: {'deviceId': deviceId},
      );
    }

    return ReferenceSyncOutcome(
      module: module,
      recordCount: recordCount,
      caughtUp: fullyCaughtUp,
      unreadableEntityTypes: unreadable,
    );
  }

  /// One page into the cache; returns how many records it held.
  ///
  /// <p>A pull does not fail whole when one entity handler throws — it returns
  /// what it could read and names what it could not (`unreadable`). An empty
  /// list there is the only shape that means "everything came through"; **a 200
  /// with real records in it still is not a complete delta**.
  static Future<int> _store(List<dynamic> records) async {
    await ReferenceCache.instance.init();
    for (final record in records) {
      if (record is! Map) continue;
      final entityType = record['entityType'] as String?;
      final entityId = record['entityId'] as String?;
      final payloadJson = record['payloadJson'] as String?;
      final updatedAt = record['updatedAt'] as String?;
      if (entityType == null ||
          entityId == null ||
          payloadJson == null ||
          updatedAt == null) {
        continue;
      }
      await ReferenceCache.instance.put(
        entityType: entityType,
        entityId: entityId,
        payload: (jsonDecode(payloadJson) as Map).cast<String, dynamic>(),
        updatedAt: DateTime.tryParse(updatedAt) ?? DateTime.now(),
      );
    }
    return records.length;
  }
}

/// What one module's pull attempt did, for a caller (a "সিঙ্ক করুন" button, a
/// startup log line) that wants to say something more useful than "done".
class ReferenceSyncOutcome {
  const ReferenceSyncOutcome({
    required this.module,
    required this.recordCount,
    required this.caughtUp,
    this.unreadableEntityTypes = const [],
  });

  final String module;
  final int recordCount;

  /// False means this module has more waiting than one call returned — try
  /// again (a later app launch, a manual retry) rather than assuming the
  /// catalogue is complete. Also false whenever [unreadableEntityTypes] is
  /// non-empty, even if the server said `hasMore: false` — a delta missing a
  /// whole entity type is not complete just because nothing more is queued
  /// behind it.
  final bool caughtUp;

  /// Entity types the server's own handler could not read this time (a
  /// failure isolated to just that type, not the whole module) — empty means
  /// every entity type in this response came through clean. A caller showing
  /// "সিঙ্ক হয়েছে" without checking this first repeats the "0 records, all
  /// caught up" bug for a partial success instead of an outright failure.
  final List<String> unreadableEntityTypes;
}
