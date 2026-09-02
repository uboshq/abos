import 'dart:convert';

import 'package:flutter/foundation.dart';

import '../api_client/api_client.dart';
import '../auth/token_storage.dart';
import 'reference_cache.dart';
import 'sync_capabilities_api.dart';

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
    final capabilities = await SyncCapabilitiesApi.list();
    final modules = capabilities.map((c) => c.module).toSet();

    final outcomes = <ReferenceSyncOutcome>[];
    for (final module in modules) {
      try {
        outcomes.add(await _pullOnce(module));
      } catch (error) {
        // Deliberately not narrowed to DioException: a malformed payloadJson
        // (jsonDecode throwing FormatException) is exactly as real a failure
        // mode as a dropped connection, and one module's bad record must not
        // take every other module in this loop down with it.
        debugPrint('ABOS reference sync: $module pull failed ($error)');
        outcomes.add(ReferenceSyncOutcome(
            module: module, recordCount: 0, caughtUp: false));
      }
    }
    return outcomes;
  }

  /// One module, one call to `GET /sync/{module}/pull`.
  static Future<ReferenceSyncOutcome> _pullOnce(String module) async {
    final deviceId = await TokenStorage.instance.deviceId();

    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/sync/$module/pull',
      queryParameters: {'deviceId': deviceId, 'limit': 1000},
    );
    final body = response.data ?? const {};
    final records = (body['records'] as List?) ?? const [];
    final hasMore = body['hasMore'] as bool? ?? false;

    // A pull does not fail whole when one entity handler throws — it returns
    // what it could read and names what it could not, here. An empty list is
    // the only shape that means "everything came through"; **a 200 with real
    // records in it still is not a complete delta**, and treating it as one is
    // the same "0 records, all caught up" trap in a second disguise.
    final unreadable = ((body['unreadable'] as List?) ?? const [])
        .map((e) => e.toString())
        .toList();

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
      recordCount: records.length,
      caughtUp: fullyCaughtUp,
      unreadableEntityTypes: unreadable,
    );
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
