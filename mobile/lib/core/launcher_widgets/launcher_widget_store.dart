import 'package:flutter/foundation.dart';
import 'package:home_widget/home_widget.dart';

/// Carries a set of strings across to a home-screen widget and tells the
/// launcher to redraw it.
///
/// <p>The widget is drawn by the launcher, in its own process, and cannot ask
/// the server anything: it has no session, no token and no idea which company
/// is signed in. Giving it those would be a second copy of the whole auth
/// layer for the sake of a few numbers. So the app writes what it already
/// fetched, and the widget reads it back.
///
/// <p><b>Never throws.</b> A launcher tile that will not update must not be
/// able to disturb a sign-in, a sign-out or a sync: the app is the thing
/// somebody is looking at, and the widget is a courtesy.
class LauncherWidgetStore {
  const LauncherWidgetStore._();

  /// Seam for tests, and the one place the platform channel is touched.
  @visibleForTesting
  static Future<void> Function(String provider, Map<String, String> values)
      writer = _write;

  static Future<void> write(
      String provider, Map<String, String> values) async {
    try {
      await writer(provider, values);
    } catch (error) {
      debugPrint('ABOS launcher widget $provider not updated: $error');
    }
  }

  static Future<void> _write(
      String provider, Map<String, String> values) async {
    await Future.wait([
      for (final entry in values.entries)
        HomeWidget.saveWidgetData<String>(entry.key, entry.value),
    ]);
    await HomeWidget.updateWidget(name: provider, androidName: provider);
  }
}
