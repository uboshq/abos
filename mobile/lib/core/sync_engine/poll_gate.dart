import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';

/// ⭐ At most one background round per [gap] on this phone — WorkManager's task, the widget task and the silent
/// sync push all ask here first (polling cut, plan 23 Nov 2026: before, every push ran a full round).
///
/// <p>ⓘ The last run is a small file holding a time, not a Hive box: each background run is its own isolate, and two
/// isolates opening one box break it (the same reason as [NudgeMark]). A file that cannot be read counts as
/// "never ran"; one that cannot be written still lets this run go ahead.
class RoundGate {
  RoundGate({
    required this.name,
    this.gap = const Duration(minutes: 15),
    DateTime Function()? clock,
    Future<File> Function()? file,
  })  : _clock = clock ?? DateTime.now,
        _file = file ?? (() async => File('${(await getApplicationSupportDirectory()).path}/$name'));

  final String name;
  final Duration gap;
  final DateTime Function() _clock;
  final Future<File> Function() _file;

  /// The last round's start, or null.
  Future<DateTime?> lastRun() async {
    try {
      final file = await _file();
      if (!await file.exists()) return null;
      return DateTime.tryParse((await file.readAsString()).trim());
    } catch (_) {
      return null;
    }
  }

  /// True — go ahead, the time is written now; false — a round ran less than [gap] ago.
  /// ⓘ A last run "in the future" (the clock was set back) does not block for ever.
  Future<bool> claim() async {
    final now = _clock();
    final last = await lastRun();
    if (last != null && !last.isAfter(now) && now.difference(last) < gap) return false;
    try {
      final file = await _file();
      await file.parent.create(recursive: true);
      await file.writeAsString(now.toIso8601String(), flush: true);
    } catch (_) {}
    return true;
  }
}

/// ⭐ "Fetched less than [maxAge] ago?" — in memory, for the open app (return from a screen, resume).
///
/// <p>ⓘ A write made since the last fetch (an approval, a notice marked read — [SharedAnswer.writes]) makes it
/// stale at once: the badge must not show what the person just changed.
class Freshness {
  Freshness({this.maxAge = const Duration(seconds: 60), DateTime Function()? clock, int Function()? writes})
      : _clock = clock ?? DateTime.now,
        _writes = writes ?? (() => SharedAnswer.writes);

  final Duration maxAge;
  final DateTime Function() _clock;
  final int Function() _writes;
  DateTime? _at;
  int _writesAt = 0;

  Duration? get _age {
    final at = _at;
    if (at == null || _writes() != _writesAt) return null;
    final age = _clock().difference(at);
    return age.isNegative ? null : age;
  }

  bool get isFresh {
    final age = _age;
    return age != null && age < maxAge;
  }

  /// Never fetched, a write since, or the last fetch older than [age].
  bool olderThan(Duration age) {
    final current = _age;
    return current == null || current >= age;
  }

  /// True — fetch now (and the time is noted); false — the last fetch is still fresh. [force] always fetches.
  bool claim({bool force = false}) {
    if (!force && isFresh) return false;
    _at = _clock();
    _writesAt = _writes();
    return true;
  }

  void reset() => _at = null;
}

/// ⭐ One answer shared by everyone asking within [maxAge] — the home, its cards and the launcher refresh ask for
/// the same thing at the same moment; one call answers them all. A call already under way is joined, not repeated.
///
/// <p>⛔ Failures are never kept. ⛔ Every instance is wiped on sign-out, company switch and any write
/// ([clearAll]) — a paper just approved must not come back from here.
class SharedAnswer<T> {
  SharedAnswer({this.maxAge = const Duration(seconds: 30), DateTime Function()? clock}) : _clock = clock ?? DateTime.now {
    _all.add(WeakReference(this));
  }

  static final List<WeakReference<SharedAnswer<dynamic>>> _all = [];

  /// How many times everything was wiped — a write, a sign-out, a company switch ([Freshness] reads it).
  static int writes = 0;

  /// Wipes every instance.
  static void clearAll() {
    writes++;
    _all.removeWhere((ref) => ref.target == null);
    for (final ref in _all) {
      ref.target?.clear();
    }
  }

  final Duration maxAge;
  final DateTime Function() _clock;

  Future<T>? _inFlight;
  T? _value;
  DateTime? _at;
  int _epoch = 0;

  /// A fresh fetch (joining one under way); its answer is kept for the others.
  Future<T> fresh(Future<T> Function() fetch) {
    final running = _inFlight;
    if (running != null) return running;
    final epoch = _epoch;
    final started = fetch().then((value) {
      if (epoch == _epoch) {
        _value = value;
        _at = _clock();
      }
      return value;
    });
    _inFlight = started;
    started.then((_) {}, onError: (Object _) {}).whenComplete(() {
      if (identical(_inFlight, started)) _inFlight = null;
    });
    return started;
  }

  /// The kept answer if younger than [maxAge], the one under way, or else a fresh fetch.
  Future<T> recent(Future<T> Function() fetch) {
    final at = _at;
    if (at != null) {
      final age = _clock().difference(at);
      if (!age.isNegative && age < maxAge) return Future<T>.value(_value as T);
    }
    return fresh(fetch);
  }

  void clear() {
    _epoch++;
    _inFlight = null;
    _value = null;
    _at = null;
  }

  @visibleForTesting
  bool get holdsAnswer => _at != null;
}
