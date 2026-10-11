import 'dart:async';

import 'package:flutter/widgets.dart';

import 'reference_sync.dart';
import 'sync_engine.dart';

/// ⭐ নিজে থেকে সিঙ্ক — মালিক, ১০ অক্টোবর ২০২৬: "auto sync er bebosta koro"।
///
/// <p>What was already there: the queue drains when the signal comes back
/// ([SyncEngine.init]'s connectivity listener) and every fifteen minutes with
/// the app closed ([BackgroundSync]). What was not: anything coming **down** —
/// products, customers, dues, stock — moved only when somebody pulled a list
/// or pressed the sync screen. A rep could sell all afternoon from the
/// morning's prices.
///
/// <p>So while the app is open: one full round (push the queue, then pull
/// every module) when it comes back to the front and every [interval] after
/// that. [runNow] is the same round for the home's pull-to-refresh.
class AutoSync with WidgetsBindingObserver {
  AutoSync({
    required this.signedIn,
    Future<void> Function()? round,
    DateTime Function()? clock,
    this.interval = const Duration(minutes: 10),
  })  : _round = round ?? fullRound,
        _clock = clock ?? DateTime.now;

  /// Nobody signed in — nothing to send and nothing this person may pull; a
  /// pull then would only answer 401 and leave a "token expired" line on
  /// every list.
  final bool Function() signedIn;

  final Future<void> Function() _round;
  final DateTime Function() _clock;

  /// How often while the app stays open.
  final Duration interval;

  /// Not again within this after a round — Android sends `resumed` after a
  /// permission dialog, a camera, a share sheet.
  static const minimumGap = Duration(minutes: 2);

  Timer? _timer;
  DateTime? _lastRun;
  Future<void>? _running;

  /// The one the app runs ([start]) — the home's pull-to-refresh reaches it
  /// here, so a pull and the timer share one round.
  static AutoSync? current;

  /// Push the queue, then pull every module.
  static Future<void> fullRound() async {
    await SyncEngine.instance.flushAll();
    await ReferenceSync.syncAll();
  }

  void start() {
    current = this;
    WidgetsBinding.instance.addObserver(this);
    _timer ??= Timer.periodic(interval, (_) => unawaited(_quietly()));
  }

  void stop() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _timer = null;
    if (identical(current, this)) current = null;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) unawaited(_quietly());
  }

  /// The automatic round: skipped when signed out or when one ran a moment
  /// ago, and never throws — nobody is looking at it.
  Future<bool> _quietly() async {
    if (!signedIn()) return false;
    final last = _lastRun;
    if (last != null && _clock().difference(last) < minimumGap) return false;
    try {
      await runNow();
    } catch (error) {
      debugPrint('ABOS auto sync: round failed ($error)');
    }
    return true;
  }

  /// One full round now — and if one is already under way, the same one, so
  /// a pull during the timer's round does not send the queue twice. Throws
  /// what the round threw: the pull-to-refresh says so.
  Future<void> runNow() {
    final running = _running;
    if (running != null) return running;
    _lastRun = _clock();
    final round = _round().whenComplete(() => _running = null);
    _running = round;
    return round;
  }

  /// For tests: the automatic round as the timer and `resumed` call it.
  @visibleForTesting
  Future<bool> tick() => _quietly();
}
