import 'dart:async';

import 'package:flutter/widgets.dart';

import 'launcher_widget_refresh.dart';

/// Fills the home-screen widgets at the moment somebody leaves the app.
///
/// <p>The background tick runs every fifteen minutes at best: Android defers
/// it under Doze and several Android builds common here kill it outright. A
/// faster timer is not the answer. <b>The instant a person leaves this app is
/// the instant before they look at their home screen</b>, and at that instant
/// the app is still alive, still signed in, and has a working connection.
/// Writing the figures there costs one round of calls nobody is waiting on
/// and makes the widget current exactly when it is about to be read.
///
/// <p>The background tick stays. It keeps the widget fresh for somebody who
/// has not opened the app all morning; this keeps it fresh for everybody who
/// has.
class WidgetSyncObserver with WidgetsBindingObserver {
  WidgetSyncObserver({
    required this.signedIn,
    Future<void> Function()? refresh,
    DateTime Function()? clock,
  })  : _refresh = refresh ?? LauncherWidgetRefresh.refresh,
        _clock = clock ?? DateTime.now;

  /// Asked at the moment of leaving. Nobody signed in means there is nothing
  /// to fetch, and a refresh then would overwrite the "signed out" line with
  /// the "not for this account" one.
  final bool Function() signedIn;

  final Future<void> Function() _refresh;
  final DateTime Function() _clock;

  /// Not more often than this. Android sends `paused` for a permission
  /// dialog, a camera, a share sheet: somebody printing six invoices would
  /// otherwise fire six rounds of calls.
  static const minimumGap = Duration(minutes: 2);

  DateTime? _lastRun;

  void start() => WidgetsBinding.instance.addObserver(this);

  void stop() => WidgetsBinding.instance.removeObserver(this);

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // `paused` is the app going to the background. `detached` is too late:
    // the engine is being torn down and an async call started there is not
    // promised to finish.
    if (state != AppLifecycleState.paused) return;
    unawaited(syncNow());
  }

  /// Refresh now, unless it was done a moment ago. Returns whether it ran.
  Future<bool> syncNow() async {
    if (!signedIn()) return false;
    final now = _clock();
    final last = _lastRun;
    if (last != null && now.difference(last) < minimumGap) return false;
    _lastRun = now;
    await _refresh();
    return true;
  }
}
