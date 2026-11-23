import 'live_sync.dart';
import 'sync_status.dart';
import 'dart:async';

import 'package:flutter/widgets.dart';

import '../launcher_widgets/hub/widget_hub.dart';
import '../crash/crash_reporter.dart';
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
    Timer Function(Duration, void Function())? timer,
  })  : _round = round ?? fullRound,
        _clock = clock ?? DateTime.now,
        _timerFactory = timer ?? Timer.new;

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

  /// ⛔ Never two rounds closer than this when the server says "something changed" ([runSoon]; polling cut,
  /// 23 Nov 2026) — changes inside the gap are merged into one round at its end.
  static const roundGap = Duration(seconds: 60);

  final Timer Function(Duration, void Function()) _timerFactory;

  Timer? _timer;
  DateTime? _lastRun;
  Future<void>? _running;

  /// The merged round waiting for [roundGap] to pass.
  Timer? _soon;

  /// A change arrived while the app was in the background — one round when it comes back.
  bool _owed = false;
  bool _foreground = true;
  bool _afterRunning = false;

  @visibleForTesting
  bool get foreground => _foreground;

  @visibleForTesting
  bool get roundWaiting => _soon != null || _owed;

  /// The one the app runs ([start]) — the home's pull-to-refresh reaches it
  /// here, so a pull and the timer share one round.
  static AutoSync? current;

  /// Push the queue, then pull every module.
  static Future<void> fullRound() async {
    // ⓘ জমা ক্র্যাশের খবরও — নেট ফিরলে বা সামনে এলে; অপেক্ষা নয়, ভুলও ছোড়ে না ([[CrashReporter.flush]])
    unawaited(CrashReporter.instance.flush());
    await SyncEngine.instance.flushAll();
    await ReferenceSync.syncAll();
    // ⭐ সফল গোলের পরে নতুন উইজেটগুলো — অপেক্ষা নয়, কখনো ছোড়ে না ([WidgetHub])
    unawaited(WidgetHub.refresh());
  }

  void start() {
    current = this;
    WidgetsBinding.instance.addObserver(this);
    _startTimer();
  }

  /// ⛔ The ten-minute timer runs only while the app is in front — in the background the silent push and the
  /// fifteen-minute task carry the load ([BackgroundSync]).
  void _startTimer() {
    _timer ??= Timer.periodic(interval, (_) => unawaited(_quietly()));
  }

  void _stopTimers() {
    _timer?.cancel();
    _timer = null;
    if (_soon != null) {
      _soon!.cancel();
      _soon = null;
      _owed = true;
    }
  }

  void stop() {
    WidgetsBinding.instance.removeObserver(this);
    _stopTimers();
    _owed = false;
    if (identical(current, this)) current = null;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _foreground = true;
      _startTimer();
      unawaited(_onResume());
    } else if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      _foreground = false;
      _stopTimers();
    }
  }

  /// ⭐ "Something changed" (the live poll, a silent push with the app open) — a round now, or, if one ran less than
  /// [roundGap] ago, one round when the gap is over; every change in between rides that same round. In the
  /// background nothing is scheduled: the round waits for the app to come back. Never throws.
  Future<void> runSoon() async {
    if (!signedIn()) return;
    final running = _running;
    if (running != null) {
      // ⓘ the round under way may have started before this change — one more after it, within the gap rule
      if (!_afterRunning) {
        _afterRunning = true;
        unawaited(running.then((_) {}, onError: (Object _) {}).whenComplete(() {
          _afterRunning = false;
          unawaited(runSoon());
        }));
      }
      return;
    }
    final last = _lastRun;
    final wait = last == null ? Duration.zero : roundGap - _clock().difference(last);
    if (!_foreground) {
      _owed = true;
      return;
    }
    if (wait > Duration.zero && wait <= roundGap) {
      _soon ??= _timerFactory(wait, () {
        _soon = null;
        unawaited(runSoon());
      });
      return;
    }
    _owed = false;
    try {
      await runNow();
    } catch (error) {
      debugPrint('ABOS auto sync: change round failed ($error)');
    }
  }

  /// ⭐ পেছনে নীরব ডাক এসে থাকলে ([NudgeMark]) — দুই মিনিটের ফাঁক না মেনে এখনই টানা; নইলে আগের মতো
  Future<void> _onResume() async {
    if (_owed) {
      _owed = false;
      await runSoon();
      return;
    }
    if (signedIn() && await _nudged()) {
      try {
        await runNow();
      } catch (error) {
        debugPrint('ABOS auto sync: nudged round failed ($error)');
      }
      return;
    }
    await _quietly();
  }

  /// Injected in tests — the real one reads the background mark.
  @visibleForTesting
  static Future<bool> Function() nudgedProbe = NudgeMark.take;

  Future<bool> _nudged() => nudgedProbe();

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
    // ⭐ প্রতিটা গোল সিঙ্কের অবস্থায় — মাথার "শেষ সিঙ্ক" এটাই পড়ে ([SyncStatus])
    final round = SyncStatus.instance.track(_round).whenComplete(() => _running = null);
    _running = round;
    return round;
  }

  /// For tests: the automatic round as the timer and `resumed` call it.
  @visibleForTesting
  Future<bool> tick() => _quietly();
}
