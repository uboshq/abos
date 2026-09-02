import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:hive/hive.dart';

import '../api_client/api_client.dart';
import '../auth/token_storage.dart';
import '../config/app_config.dart';
import '../storage/hive_encryption.dart';

/// Offline-first sync client — the push (upload) half.
///
/// Talks to `/api/v1/sync/**`; the exact contract it depends on is written
/// down in docs/Contract — মোবাইল সিঙ্ক প্রোটোকল.md. **Nothing in this file
/// knows what a sale or a delivery is** — it moves opaque `payloadJson` and
/// obeys the outcome the server returns for each change.
///
/// How it behaves, and why:
///  - [enqueue] writes to a Hive box *before* any network call, so a sale
///    recorded in a shop with no signal survives an app kill.
///  - [flush] pushes in batches and only deletes a record once the server has
///    told this device, **by changeId**, that it actually applied it. A failed
///    push leaves the record queued; a rejected one is kept and surfaced, not
///    deleted — see [rejectedItems]'s own doc comment for the bug that
///    replaced.
///  - Connectivity changes trigger a flush, which is what makes the queue
///    drain by itself when the rep walks back into coverage.
///
/// Pull side lives in reference_sync.dart, not here — see that file's class
/// doc comment for the watermark rules, which are subtler than they look.
class SyncEngine {
  SyncEngine._();

  static final SyncEngine instance = SyncEngine._();

  static const String _queueBoxName = 'abos_sync_queue';
  static const String _statusRejected = 'REJECTED';

  Box<Map>? _queue;
  StreamSubscription<List<ConnectivityResult>>? _connectivitySubscription;

  /// Guards against two flushes running at once (a connectivity event landing
  /// on top of a manual flush), which would push the same record twice.
  bool _flushing = false;

  /// Local, monotonic within one app run — combined with the wall-clock
  /// microsecond below, enough to keep two changes enqueued in the same
  /// microsecond from colliding, which the microsecond alone cannot promise.
  int _changeSeq = 0;

  /// Generated once, here, and stored on the row: the same changeId must go
  /// out on every retry of that same change, because that id is the whole
  /// basis of the server's "have I already applied this?" check. A push that
  /// invented a fresh id per attempt would post the same sale twice the first
  /// time a response was dropped on a bad connection.
  String _newChangeId() =>
      'local-${DateTime.now().microsecondsSinceEpoch}-${_changeSeq++}';

  /// Must be awaited before any screen records changes.
  ///
  /// Awaited in main(), before runApp(): **nothing this does may throw**, or
  /// the app never renders a single frame — not the login form, not even a
  /// keyboard to type into, on every launch after. A queue box left
  /// half-written by a killed process, or a connectivity plugin some OEM
  /// Android build never wires up, must degrade to "sync is off this run",
  /// never to "the app is off this run".
  Future<void> init() async {
    if (_queue == null) {
      try {
        _queue = await HiveEncryptionKey.openBox<Map>(_queueBoxName);
      } catch (error) {
        debugPrint('ABOS sync: queue box failed to open ($error) — resetting it');
        try {
          await Hive.deleteBoxFromDisk(_queueBoxName);
          _queue = await HiveEncryptionKey.openBox<Map>(_queueBoxName);
        } catch (error2) {
          debugPrint(
              'ABOS sync: queue box unusable even after reset ($error2) — sync stays off this run');
        }
      }
    }

    if (_connectivitySubscription == null) {
      try {
        _connectivitySubscription =
            Connectivity().onConnectivityChanged.listen((results) {
          final online = results.any((r) => r != ConnectivityResult.none);
          if (online) {
            // Back in coverage — drain whatever piled up.
            flushAll();
          }
        });
      } catch (error) {
        debugPrint(
            'ABOS sync: connectivity listener failed to start ($error) — auto-flush on reconnect stays off this run');
      }
    }
  }

  Future<void> dispose() async {
    await _connectivitySubscription?.cancel();
    _connectivitySubscription = null;
  }

  Box<Map> get _box {
    final box = _queue;
    if (box == null) {
      throw StateError('SyncEngine.init() must be awaited before use');
    }
    return box;
  }

  /// Changes still waiting to reach the server — for the "N pending" badge.
  /// Excludes rejected rows: those are done trying and are waiting on a
  /// person, not on a connection.
  int get pendingCount =>
      _queue?.values.where((row) => row['status'] != _statusRejected).length ??
      0;

  /// What the server would not accept, kept on the phone rather than deleted
  /// alongside the applied ones.
  ///
  /// <p>The bug this exists to prevent: delete every acknowledged row
  /// regardless of what the acknowledgement said — applied or refused — and
  /// the queue empties the same way for both. The pending badge drops to zero,
  /// and a rep reading "0 waiting" has no way to tell a delivered order from
  /// one the server just refused for being over a shop's credit limit. **The
  /// count that exists to stop people ignoring the queue is exactly the count
  /// that would be lying.**
  List<RejectedChange> get rejectedItems =>
      (_queue?.keys ?? const Iterable.empty())
          .map((key) {
            final row = _queue!.get(key);
            if (row == null || row['status'] != _statusRejected) return null;
            return RejectedChange(
              key: key,
              entityType: row['entityType'] as String? ?? '',
              reason: row['reason'] as String? ?? 'অজানা কারণ',
            );
          })
          .whereType<RejectedChange>()
          .toList();

  int get rejectedCount => rejectedItems.length;

  /// A rep has seen the reason and dealt with it (re-entered it by hand, told
  /// the shop) — clears the row so it does not sit in [rejectedItems] forever.
  Future<void> dismissRejected(dynamic key) async {
    await _queue?.delete(key);
  }

  /// Queue one offline create/update, then try to push immediately.
  ///
  /// [clientVersion] is the device's local revision counter for this record.
  /// An UPDATE arriving with a version the server cannot reconcile is a
  /// CONFLICT rather than a silent overwrite of newer server data, so callers
  /// updating an existing record must pass their real version.
  Future<void> enqueue({
    required String module,
    required String entityType,
    required String operation,
    required Map<String, dynamic> payload,
    String? entityId,
    int clientVersion = 1,
  }) async {
    assert(operation == 'CREATE' || operation == 'UPDATE',
        'The server accepts CREATE or UPDATE only');

    await _box.add(<String, dynamic>{
      'changeId': _newChangeId(),
      'module': module,
      'entityType': entityType,
      'entityId': entityId,
      'operation': operation,
      // Stored as a string, not a Map: this is exactly what goes on the wire,
      // and it keeps the queued row stable even if the in-memory model changes
      // shape in a later release while a row is still sitting in the queue.
      'payloadJson': jsonEncode(payload),
      'clientVersion': clientVersion,
      'attempts': 0,
    });

    await flush(module);
  }

  /// Pushes every module that has queued changes.
  Future<void> flushAll() async {
    if (_queue == null) return;
    final modules = _box.values
        .map((row) => row['module'] as String?)
        .whereType<String>()
        .toSet();
    for (final module in modules) {
      await flush(module);
    }
  }

  /// Pushes queued changes for one module. Safe to call when offline — it
  /// simply does nothing and leaves the queue intact.
  Future<void> flush(String module) async {
    if (_flushing || _queue == null) return;
    _flushing = true;
    try {
      final deviceId = await TokenStorage.instance.deviceId();

      // Loops until the module's queue is empty: a rep who was offline all
      // morning can have far more than one batch waiting.
      while (true) {
        // Keys, not values: a key is what lets us delete exactly the rows the
        // server confirmed, while other rows may be added concurrently.
        // Rejected rows are excluded — they are done trying; re-sending a
        // change the server already refused would either be refused again for
        // the same reason or, worse, applied a second time if this file ever
        // regenerated the changeId.
        final keys = _box.keys
            .where((key) {
              final row = _box.get(key);
              return row?['module'] == module &&
                  row?['status'] != _statusRejected;
            })
            .take(AppConfig.syncBatchSize)
            .toList(growable: false);
        if (keys.isEmpty) return;

        final changes = <Map<String, dynamic>>[];
        for (final key in keys) {
          final row = _box.get(key);
          if (row == null) continue;
          changes.add(<String, dynamic>{
            'changeId': row['changeId'],
            'entityType': row['entityType'],
            'entityId': row['entityId'],
            'operation': row['operation'],
            'payloadJson': row['payloadJson'],
            'clientVersion': row['clientVersion'],
          });
        }
        if (changes.isEmpty) return;

        final response = await ApiClient.dio.post<Map<String, dynamic>>(
          '/sync/$module/push',
          queryParameters: {'deviceId': deviceId},
          data: changes,
        );

        // Matched by changeId, not by position. The contract says outcomes
        // come back in the order the changes arrived, but matching by the id
        // this file itself generated does not depend on that order surviving a
        // retry, a partial response, or a future change on the server.
        final outcomes = (response.data?['outcomes'] as List?) ?? const [];
        final outcomeByChangeId = <String, Map<String, dynamic>>{
          for (final o in outcomes)
            if (o is Map && o['changeId'] != null)
              o['changeId'] as String: o.cast<String, dynamic>(),
        };

        final toDelete = <dynamic>[];
        for (final key in keys) {
          final row = _box.get(key);
          if (row == null) continue;
          final outcome = outcomeByChangeId[row['changeId']];
          final reason = reasonIfRejected(outcome);
          if (reason != null) {
            // Refused, not lost — kept on the phone with why, instead of
            // vanishing the same way an applied change does.
            await _box.put(key, <String, dynamic>{
              ...row.cast<String, dynamic>(),
              'status': _statusRejected,
              'reason': reason,
            });
          } else if (outcome != null) {
            // APPLIED or DUPLICATE — genuinely done.
            toDelete.add(key);
          }
          // No outcome for this changeId at all (should not happen, but a
          // response is not something to trust blindly) — left exactly as it
          // was, retried next flush.
        }
        if (toDelete.isNotEmpty) await _box.deleteAll(toDelete);
        // Something is still pending or unresolved — stop rather than spin.
        if (toDelete.length < keys.length) return;
      }
    } catch (error) {
      // Offline or a server-side failure: keep the records and count the try.
      //
      // Deliberately NOT narrowed to DioException: `o['changeId'] as String` a
      // few lines up throws a TypeError, not a DioException, on a malformed
      // response — and this method is called in a loop from flushAll(), once
      // per module. A narrow catch here would let that escape flush() entirely
      // and break flushAll()'s loop before it reached every other module
      // queued behind this one.
      await _recordFailedAttempt(module, error);
    } finally {
      _flushing = false;
    }
  }

  Future<void> _recordFailedAttempt(String module, Object error) async {
    debugPrint('ABOS sync: $module push failed ($error) — kept in queue');

    for (final key in _box.keys.toList(growable: false)) {
      final row = _box.get(key);
      if (row == null || row['module'] != module) continue;

      final attempts = ((row['attempts'] as int?) ?? 0) + 1;
      if (attempts >= AppConfig.syncMaxAttempts) {
        // Kept, not dropped — same reasoning as a server-side rejection: a
        // queue that quietly deletes a change nobody could send is a queue
        // that told the rep it was sent.
        await _box.put(key, <String, dynamic>{
          ...row.cast<String, dynamic>(),
          'status': _statusRejected,
          'reason':
              'বারবার পাঠানো ব্যর্থ হয়েছে ($attempts বার) — নিজে থেকে আবার লিখুন।',
        });
        continue;
      }

      await _box.put(key,
          <String, dynamic>{...row.cast<String, dynamic>(), 'attempts': attempts});
    }
  }
}

/// Whether one change's outcome (`status`, `message`) is a terminal refusal,
/// and if so, why.
///
/// <p>Pure and separate from [SyncEngine.flush] so the exact bug it prevents
/// is testable without a Hive box or a mocked Dio. There are four outcome
/// statuses — APPLIED, DUPLICATE, CONFLICT, REJECTED — and **only the first
/// two mean the server is done with this change**.
///
/// <p>The trap: it is tempting to look only at a `conflicts` list, because a
/// conflict is the case everybody thinks of first. But a CREATE refused for a
/// business reason (a shop over its credit limit, a closed month) is a
/// REJECTED outcome and never appears in a conflicts list — and deleting it
/// from the queue on the grounds that the server "acknowledged" it is exactly
/// how a refused sale disappears without anybody being told.
///
/// <p>Null for APPLIED, DUPLICATE, or a changeId with no outcome at all (kept
/// pending by the caller, treated as neither done nor refused).
String? reasonIfRejected(Map<String, dynamic>? outcome) {
  if (outcome == null) return null;
  final status = outcome['status'] as String?;
  if (status != 'REJECTED' && status != 'CONFLICT') return null;
  return outcome['message'] as String? ?? 'কারণ জানানো হয়নি।';
}

/// One queued change the server would not accept — see
/// [SyncEngine.rejectedItems].
class RejectedChange {
  const RejectedChange({
    required this.key,
    required this.entityType,
    required this.reason,
  });

  /// The Hive key — opaque to callers, needed only to pass back to
  /// [SyncEngine.dismissRejected].
  final dynamic key;
  final String entityType;
  final String reason;
}
