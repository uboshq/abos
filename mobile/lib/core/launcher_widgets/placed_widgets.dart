import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// ⭐ Which launcher widgets are on this phone's home screen — so nobody fetches figures for a widget nobody placed
/// (polling cut, 23 Nov 2026).
///
/// <p>ⓘ null means "cannot tell" (iOS, a launcher that does not answer, a test) — then callers behave as before
/// and fetch. Asked on home_widget's own channel rather than through `HomeWidget.getInstalledWidgets`, which turns
/// "no answer" into an empty list — "nothing placed" — and would stop a widget from ever being filled.
class PlacedWidgets {
  const PlacedWidgets._();

  static const MethodChannel _channel = MethodChannel('home_widget');

  /// The short Android class names of the placed widgets (`TodayWidgetProvider`, …), or null.
  static Future<Set<String>?> Function() providers = _providers;

  static Future<Set<String>?> _providers() async {
    if (defaultTargetPlatform != TargetPlatform.android) return null;
    try {
      final placed = await _channel.invokeMethod<List<Object?>>('getInstalledWidgets');
      if (placed == null) return null;
      return {
        for (final w in placed)
          if (w is Map && w['androidClassName'] is String) (w['androidClassName'] as String).split('.').last,
      };
    } catch (_) {
      return null;
    }
  }

  /// Is [provider] placed? Unknown counts as yes.
  static Future<bool> has(String provider) async => (await providers())?.contains(provider) ?? true;

  /// Is any widget placed? Unknown counts as yes.
  static Future<bool> any() async {
    final set = await providers();
    return set == null || set.isNotEmpty;
  }

  static void resetForTest() => providers = _providers;
}
