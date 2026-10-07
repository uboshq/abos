import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:hive/hive.dart';

import '../api_client/api_client.dart';
import '../api_client/network_errors.dart';
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

  /// A rejected row a person has since replaced with a corrected retry (see
  /// [markResolved]) — kept, not deleted, so a manager can still ask "how
  /// many times did this shop's order get refused today". Deleting it the
  /// moment it is dealt with would erase exactly the pattern (the same shop
  /// rejected repeatedly) that signals a pricing or stock problem worth
  /// noticing, rather than one unlucky order.
  static const String _statusResolved = 'RESOLVED';

  /// How long a resolved row is kept before [init] purges it — long enough
  /// for a same-day or same-week manager check, short enough that this
  /// device's storage does not grow forever from a queue whose whole design
  /// elsewhere is "never silently drop a row". Unlike a REJECTED or pending
  /// row, a RESOLVED one has already done its job (a person saw it and
  /// acted), so aging it out is not the same risk as aging out a change
  /// nobody has answered yet.
  static const Duration _resolvedRetention = Duration(days: 30);

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

    await _purgeOldResolved();
  }

  /// Drops resolved rows past [_resolvedRetention] — see that field's own
  /// doc comment. Run once per [init] (once per app launch) rather than on
  /// a timer: a rarely-opened phone purging a little late costs nothing, and
  /// this file already has no background timer of its own to hang one off.
  Future<void> _purgeOldResolved() async {
    final box = _queue;
    if (box == null) return;
    final cutoff = DateTime.now().subtract(_resolvedRetention);
    final toDelete = <dynamic>[];
    for (final key in box.keys) {
      final row = box.get(key);
      if (row == null || row['status'] != _statusResolved) continue;
      final resolvedAt = DateTime.tryParse(row['resolvedAt'] as String? ?? '');
      if (resolvedAt == null || resolvedAt.isBefore(cutoff)) {
        toDelete.add(key);
      }
    }
    if (toDelete.isNotEmpty) await box.deleteAll(toDelete);
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
  /// Excludes rejected and resolved rows alike: neither is waiting on a
  /// connection any more, one on a person still and the other already dealt
  /// with.
  int get pendingCount => _queue?.values
          .where((row) =>
              _isMine(row) &&
              row['status'] != _statusRejected &&
              row['status'] != _statusResolved)
          .length ??
      0;

  /// ⛔ কার সারি — অফলাইন সারি মানুষের সাথে বাঁধা (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬: একটা ফোন দুইজন চালালে প্রথম
  /// জনের না-যাওয়া অর্ডার দ্বিতীয় জনের সেশনে, তাঁর নামে বসত — সার্ভার পাঠানোর সময়ের মানুষকেই লেখক ধরে)।
  ///
  /// <p>লগইন আর সেশন ফেরানোয় [AuthController] বসায়, বেরোলে মোছে; পেছনের সিঙ্ক বসায় সংরক্ষিত প্রোফাইল থেকে। প্রতিটা নতুন
  /// সারিতে এই আইডি থাকে, আর পাঠানো, গোনা ও দেখানো হয় কেবল এখনকার মানুষের সারি। ⓘ কেউ না থাকলে কিছুই যায় না।
  /// ⓘ এই সংস্করণের আগে লেখা সারিতে আইডি নেই — সেগুলো আগের মতো এখনকার মানুষের সাথে যায়।
  String? _owner;

  /// এখন কে — `null` মানে কেউ নেই (বেরিয়ে গেছেন)
  void actAs(String? userId) {
    _owner = (userId == null || userId.isEmpty) ? null : userId;
  }

  bool _isMine(Map row) {
    final writer = row['userId'];
    return writer == null || writer == _owner;
  }

  /// অন্য কারও রেখে যাওয়া না-যাওয়া সারি — যিনি লিখেছেন তিনি ঢুকলেই যাবে
  int get heldForOthersCount => _queue?.values
          .where((row) =>
              !_isMine(row) &&
              row['status'] != _statusRejected &&
              row['status'] != _statusResolved)
          .length ??
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
            if (row == null || row['status'] != _statusRejected || !_isMine(row)) return null;
            return RejectedChange(
              key: key,
              entityType: row['entityType'] as String? ?? '',
              reason: row['reason'] as String? ?? 'অজানা কারণ',
              // Opaque here too, same as the queue row itself — this file
              // still does not decode a customer or an item out of it (see
              // the class doc comment); a screen that knows what a
              // SalesOrder payload looks like decodes it into "রহিম
              // স্টোরের অর্ডার", this file never does.
              payloadJson: row['payloadJson'] as String? ?? '{}',
              enqueuedAt: DateTime.tryParse(row['enqueuedAt'] as String? ?? '') ??
                  DateTime.now(),
            );
          })
          .whereType<RejectedChange>()
          .toList();

  int get rejectedCount => rejectedItems.length;

  /// A rep has seen the reason and dealt with it **without** a replacement
  /// this file knows about (told the shop no, handled it on paper) — clears
  /// the row outright so it does not sit in [rejectedItems] forever.
  ///
  /// For the more common case — a corrected retry was actually queued — see
  /// [markResolved] instead, which keeps the row rather than erasing it.
  Future<void> dismissRejected(dynamic key) async {
    await _queue?.delete(key);
  }

  /// A rejected change has been replaced by a corrected retry — kept, not
  /// deleted, unlike [dismissRejected]. See [_statusResolved]'s own doc
  /// comment for why: the same shop's order failing repeatedly is a signal
  /// (a stale price list, a credit-limit rule out of date) worth a manager
  /// being able to count later, and deleting the row the moment someone acts
  /// on it would erase that pattern along with the one row.
  Future<void> markResolved(dynamic key) async {
    final row = _queue?.get(key);
    if (row == null) return;
    await _queue!.put(key, <String, dynamic>{
      ...row.cast<String, dynamic>(),
      'status': _statusResolved,
      'resolvedAt': DateTime.now().toIso8601String(),
    });
  }

  /// Resolved rows not yet purged by [_purgeOldResolved] — for a future
  /// "how many orders came back today" view. Reuses [RejectedChange]'s shape
  /// since the fields that matter (which entity, the original reason, when
  /// it was queued) are identical; [RejectedChange.reason] here is still the
  /// *original* rejection reason, kept for exactly that later counting.
  List<RejectedChange> get resolvedItems => (_queue?.keys ?? const Iterable.empty())
      .map((key) {
        final row = _queue!.get(key);
        if (row == null || row['status'] != _statusResolved) return null;
        return RejectedChange(
          key: key,
          entityType: row['entityType'] as String? ?? '',
          reason: row['reason'] as String? ?? 'অজানা কারণ',
          payloadJson: row['payloadJson'] as String? ?? '{}',
          enqueuedAt:
              DateTime.tryParse(row['enqueuedAt'] as String? ?? '') ?? DateTime.now(),
        );
      })
      .whereType<RejectedChange>()
      .toList();

  int get resolvedCount => resolvedItems.length;

  /// The only kind of record this app may write with no signal.
  ///
  /// <p>The owner's decision of 2 September 2026, quoted in docs/Contract §০:
  /// *"নেট না থাকলে শুধু অর্ডার। চালান, বিল, আদায়, POS — একটাও নয়।"* The
  /// reasoning there is not convenience but honest accounting — offline a
  /// phone cannot know the next number in the series, what is on the shelf,
  /// what the shop already owes, or today's price, and a challan, bill,
  /// collection or POS sale needs all four. An order needs none of them: it
  /// is a promise, not an entry, and all four are checked on the server at
  /// the moment it syncs.
  ///
  /// <p><b>Why the phone enforces this when the server decides it anyway.</b>
  /// The server's `acceptsPush()` is the real gate, and it should stay the
  /// real gate. But this app has no screen for a collection today, so nothing
  /// stops a future screen from calling [enqueue] with one — and a queued
  /// change that the server will refuse is worse than one that was never
  /// written: the rep watches "অপেক্ষমাণ ১" sit there, and learns at sync
  /// that the money they wrote down was never going anywhere. Refusing here
  /// makes that a loud stop in front of whoever is adding the screen, at the
  /// moment they add it, rather than a rejected row in front of a rep in a
  /// shop.
  ///
  /// <p>It is the same two-layer shape the sync engine already uses for
  /// idempotency — the application check and the database's unique index,
  /// each doing its job without depending on the other.
  ///
  /// <p>⚠️ A new entry here is a business decision, never a convenience: it
  /// means someone has established that the record can be written honestly
  /// with no network, and that the server's handler accepts a push for it.
  static const Set<String> _writableOffline = {
    'SalesOrder',

    /*
     * হাজিরা — docs/Contract §৭. Not an exception to "orders only", but
     * outside it: that decision is about sales documents, which take a
     * number, move stock and post to the ledger. Attendance does none of
     * those, and the spec asks for it offline by name — a field worker marks
     * their own day from wherever they are, which is exactly where there is
     * no signal. `AttendanceSync::acceptsPush()` has been true all along.
     */
    'Attendance',
  };

  /// Queue one offline create/update, then try to push immediately.
  ///
  /// [clientVersion] is the device's local revision counter for this record.
  /// An UPDATE arriving with a version the server cannot reconcile is a
  /// CONFLICT rather than a silent overwrite of newer server data, so callers
  /// updating an existing record must pass their real version.
  ///
  /// Throws [UnsupportedError] for any [entityType] outside
  /// [_writableOffline] — see that field's own doc comment.
  /// ⓘ Returns the change's own id.
  Future<String> enqueue({
    required String module,
    required String entityType,
    required String operation,
    required Map<String, dynamic> payload,
    String? entityId,
    int clientVersion = 1,
  }) async {
    assert(operation == 'CREATE' || operation == 'UPDATE',
        'The server accepts CREATE or UPDATE only');

    // Thrown, not asserted: an assert is compiled out of a release build, and
    // a rule that only holds in debug is a rule that does not hold on the one
    // build that reaches a phone in a shop.
    if (!_writableOffline.contains(entityType)) {
      throw UnsupportedError(
        'Nothing but ${_writableOffline.join(', ')} may be queued offline; '
        'refused $entityType. docs/Contract §০ — the owner\'s decision of '
        '2 September 2026: with no network, orders only. If this record can '
        'now be written honestly offline, that is a decision to record there '
        'first, and the server handler must accept a push for it.',
      );
    }

    final changeId = _newChangeId();
    await _box.add(<String, dynamic>{
      'changeId': changeId,
      // ⛔ কার লেখা — অন্য কেউ ঢুকলে এটা তাঁর নামে যায় না ([[_owner]])
      'userId': _owner,
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
      // Local device time, for "কবে" on a rejected row — never sent to the
      // server (the push body above is built fresh from the row's own
      // fields in flush(), not from this map), so a clock a few minutes off
      // costs nothing but a slightly-off display timestamp.
      'enqueuedAt': DateTime.now().toIso8601String(),
    });

    await flush(module);
    return changeId;
  }

  /// ⭐ একটা নতুন বদলের চাবি — সারির বাইরে সরাসরি পাঠানোর জন্য ([[pushNow]])। পর্দা একবার বানিয়ে রাখে আর একই কাজ
  /// আবার পাঠালে একই চাবি দেয়, তাই দুবার চাপলে বা উত্তর হারালেও সার্ভারে একটাই বসে (সার্ভারের changeId-পাহারা)।
  String newChangeId() => _newChangeId();

  /// ⛔ নেট থাকলে এখনই, সারিতে নয় — অফিসের আদায় (৭ অক্টোবর ২০২৬; সমন্বয়কের অ্যাপ-অডিট: "নতুন আদায়" সারিতে উঠতই
  /// না, কারণ মালিকের নিয়মে নেট ছাড়া কেবল অর্ডার — [[_writableOffline]])।
  ///
  /// <p>সিঙ্কের একই দরজা (`/sync/{module}/push`), একটা বদল, এখনই। নেট না থাকলে [NoNetworkForThis] — পর্দা বলে
  /// "নেট লাগবে"; কিছুই ফোনে জমা থাকে না। সার্ভার ফেরালে কারণসহ [PushOutcome.refusal]।
  Future<PushOutcome> pushNow({
    required String module,
    required String entityType,
    required String changeId,
    required Map<String, dynamic> payload,
  }) async {
    final deviceId = await TokenStorage.instance.deviceId();
    final Response<Map<String, dynamic>> response;
    try {
      response = await ApiClient.dio.post<Map<String, dynamic>>(
        '/sync/$module/push',
        queryParameters: {'deviceId': deviceId},
        data: [
          <String, dynamic>{
            'changeId': changeId,
            'entityType': entityType,
            'entityId': null,
            'operation': 'CREATE',
            'payloadJson': jsonEncode(payload),
            'clientVersion': 1,
          },
        ],
      );
    } catch (error) {
      if (isNetworkError(error)) throw const NoNetworkForThis();
      rethrow;
    }

    final outcomes = (response.data?['outcomes'] as List?) ?? const [];
    final outcome = outcomes
        .whereType<Map>()
        .map((o) => o.cast<String, dynamic>())
        .where((o) => o['changeId'] == changeId)
        .firstOrNull;
    if (outcome == null) {
      throw StateError('The server answered without this change ($changeId).');
    }
    final refusal = reasonIfRejected(outcome);
    final landed = outcome['entityId'];
    return PushOutcome(
      refusal: refusal,
      landedId: refusal == null && landed is String && landed.isNotEmpty ? landed : null,
    );
  }

  /// The decoded payloads of everything still queued for one entity type.
  ///
  /// <p>For the one question a screen cannot answer from [pendingCount]: *is
  /// the thing I am about to write already waiting?* Attendance needs it —
  /// the server refuses a second row for the same day (CONFLICT), so a phone
  /// that let someone queue today twice would send a second change that comes
  /// back refused, and the person would read that as "my attendance did not
  /// go through" when in fact it had.
  ///
  /// <p>Excludes rejected and resolved rows, like [pendingCount]: neither is
  /// waiting on a connection any more.
  List<Map<String, dynamic>> pendingPayloadsOf(String entityType) =>
      (_queue?.values ?? const Iterable<Map>.empty())
          .where((row) =>
              row['entityType'] == entityType &&
              _isMine(row) &&
              row['status'] != _statusRejected &&
              row['status'] != _statusResolved)
          .map((row) {
            try {
              return jsonDecode(row['payloadJson'] as String? ?? '{}')
                  as Map<String, dynamic>;
            } catch (_) {
              // A row this build cannot read is still a queued row, but it
              // cannot answer the question above — skipped rather than
              // crashing a screen that only wanted to know about today.
              return null;
            }
          })
          .whereType<Map<String, dynamic>>()
          .toList();

  /// Why the last push attempt for each module did not go through.
  ///
  /// <p>⚠️ <b>"Waiting" and "refused" look identical on a queue, and they are
  /// not the same thing.</b> A phone out of coverage and a server rejecting
  /// the request outright both leave the row exactly where it was, so the
  /// screen says "N অপেক্ষমাণ" either way and the rep reads it as no signal.
  ///
  /// <p>That cost a real afternoon on 15 September: every order queued on a
  /// live server sat pending, the sync screen showed nothing wrong, and the
  /// cause — the server answering 422 to every push — was only found by
  /// reading `adb logcat`. Nobody in a shop has adb.
  ///
  /// <p>In memory rather than on disk, deliberately: this describes the last
  /// *attempt*, not the queue, and a stale reason from three days ago would
  /// be worse than none. The next attempt rewrites it within seconds.
  final Map<String, SyncAttemptFailure> _lastFailureByModule = {};

  /// Null when the last attempt succeeded, or when none has been made since
  /// the app started.
  SyncAttemptFailure? lastFailureFor(String module) =>
      _lastFailureByModule[module];

  /// Every module whose last attempt failed — for a screen that wants to say
  /// so once rather than per module.
  List<SyncAttemptFailure> get lastFailures =>
      _lastFailureByModule.values.toList();

  /// Pushes every module that has queued changes.
  Future<void> flushAll() async {
    if (_queue == null || _owner == null) return;
    final modules = _box.values
        .where(_isMine)
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
    // ⛔ কেউ না থাকলে কিছু নয় — আর থাকলে কেবল তাঁর সারি ([[_owner]])
    if (_flushing || _queue == null || _owner == null) return;
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
              return row != null &&
                  row['module'] == module &&
                  _isMine(row) &&
                  row['status'] != _statusRejected;
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
    _lastFailureByModule[module] = SyncAttemptFailure.from(error);

    for (final key in _box.keys.toList(growable: false)) {
      final row = _box.get(key);
      if (row == null || row['module'] != module || !_isMine(row)) continue;

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
/// ⭐ [SyncEngine.pushNow]-এর উত্তর — বসলে সার্ভারের আইডি, ফেরালে কারণ।
class PushOutcome {
  const PushOutcome({this.landedId, this.refusal});

  final String? landedId;
  final String? refusal;
}

/// নেট নেই — যে কাজ কেবল নেট থাকলে হয় ([[SyncEngine.pushNow]]), তার জন্য।
class NoNetworkForThis implements Exception {
  const NoNetworkForThis();
}

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
    required this.payloadJson,
    required this.enqueuedAt,
  });

  /// The Hive key — opaque to callers, needed only to pass back to
  /// [SyncEngine.dismissRejected].
  final dynamic key;
  final String entityType;
  final String reason;

  /// The change's own payload, exactly as it was queued — this is the
  /// phone's own earlier write, not new data being exposed. Still opaque
  /// here (a string, undecoded) for the same reason the queue row itself is:
  /// this file does not know what a `SalesOrder` or a `Collection` looks
  /// like. A screen that does — the "যা যায়নি" screen — decodes it to name
  /// the customer and the items, rather than showing the raw JSON to a
  /// person it means nothing to.
  final String payloadJson;

  /// When this device queued the change — device-local time (see
  /// [SyncEngine.enqueue]'s own comment on why that is fine here).
  final DateTime enqueuedAt;
}

/// What stopped the last push — in the terms a person can act on.
///
/// <p>The distinction that matters is not the status code but who has to do
/// something: a rep who walks to a window, or an office that has to fix a
/// build. A queue that cannot tell those apart sends the rep to the window
/// forever.
class SyncAttemptFailure {
  const SyncAttemptFailure({
    required this.isNetwork,
    this.statusCode,
    this.serverMessage,
    this.direction = SyncDirection.push,
  });

  /// Which half of the sync this was. The status codes and the parsing are
  /// identical; only [sentence] differs, and it has to — "সংযোগ পেলেই চলে
  /// যাবে" is a true sentence about a queued order and a false one about a
  /// catalogue that failed to arrive, where nothing is waiting to go anywhere.
  final SyncDirection direction;

  /// No signal, a timeout, an unreachable host — ordinary, and the queue is
  /// doing exactly what it was built for.
  final bool isNetwork;

  /// The server answered, and refused. ⚠️ Not a rejected *change* — those come
  /// back per change in `outcomes` and land in [SyncEngine.rejectedItems].
  /// This is the request itself being turned away, which no amount of waiting
  /// or retrying will fix.
  final int? statusCode;

  final String? serverMessage;

  bool get isServerRefusal => !isNetwork && statusCode != null;

  factory SyncAttemptFailure.from(
    Object error, {
    SyncDirection direction = SyncDirection.push,
  }) {
    if (error is! DioException) {
      return SyncAttemptFailure(isNetwork: false, direction: direction);
    }

    final network = error.type == DioExceptionType.connectionError ||
        error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.sendTimeout ||
        error.type == DioExceptionType.receiveTimeout;

    final body = error.response?.data;

    return SyncAttemptFailure(
      isNetwork: network,
      statusCode: error.response?.statusCode,
      serverMessage: body is Map ? body['message']?.toString() : null,
      direction: direction,
    );
  }

  /// One sentence, and it names who acts.
  String get sentence {
    final pulling = direction == SyncDirection.pull;

    if (isNetwork) {
      return pulling
          // ⚠️ Not "চলে যাবে". Nothing is queued on a failed pull — the list
          // stays empty until somebody pulls again, and promising it will
          // arrive on its own is how an empty catalogue gets waited on all
          // morning.
          ? 'সংযোগ নেই — সংযোগ পেয়ে আবার নিচে টানুন।'
          : 'সংযোগ নেই — সংযোগ পেলেই নিজে থেকে চলে যাবে।';
    }
    if (isServerRefusal) {
      final what = serverMessage == null
          ? 'সার্ভার অনুরোধটাই নিচ্ছে না (কোড $statusCode)'
          : 'সার্ভার বলছে: $serverMessage (কোড $statusCode)';
      return '$what — অফিসে জানান, ${pulling ? 'বারবার টেনে' : 'অপেক্ষা করে'} '
          'ঠিক হবে না।';
    }
    return pulling
        ? 'তালিকা আনা যায়নি — কারণ জানা যায়নি। অফিসে জানান।'
        : 'পাঠানো যায়নি — কারণ জানা যায়নি। অফিসে জানান।';
  }
}

/// Which way a failed sync attempt was going.
enum SyncDirection {
  /// Changes made on this phone, going up. A failure leaves them queued.
  push,

  /// The catalogue coming down. A failure leaves a screen empty, and there is
  /// nothing queued that will fix it later by itself.
  pull,
}
