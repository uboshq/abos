import 'package:flutter/widgets.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:workmanager/workmanager.dart';

import '../auth/token_storage.dart';
import 'sync_engine.dart';

/// Turns on what [SyncEngine] already does on reconnect — drain the offline
/// change queue — **while the app itself is not running**.
///
/// Without this, a rep who closed the app before walking back into coverage
/// has a queue that only drains when they next open it. Which is to say: the
/// order they took at eleven reaches the office when they remember to look at
/// their phone, not when the signal comes back.
///
/// The callback below runs in its own separate Flutter engine and isolate —
/// workmanager's own contract, not this file's choice — so **nothing** from
/// main()'s already-initialised Hive boxes or Dio client carries over. It
/// re-does the minimum this task actually needs rather than assuming any of
/// that state exists.
class BackgroundSync {
  const BackgroundSync._();

  static const _taskName = 'abos-sync-flush';

  /// Called once from main(), before runApp() — the same place every other
  /// core singleton is wired up.
  ///
  /// Registered unconditionally, not only after sign-in: a device that was
  /// signed out mid-session still has a right to keep trying whatever was
  /// already queued before that happened, exactly like the connectivity
  /// listener in sync_engine.dart does.
  static Future<void> initialize() async {
    try {
      await Workmanager().initialize(callbackDispatcher);
      await Workmanager().registerPeriodicTask(
        _taskName,
        _taskName,
        // Android's WorkManager refuses anything under 15 minutes for a
        // periodic task. This is that floor, not a cadence anybody chose.
        frequency: const Duration(minutes: 15),
        constraints: Constraints(networkType: NetworkType.connected),
        existingWorkPolicy: ExistingPeriodicWorkPolicy.keep,
      );
    } catch (error) {
      // iOS/desktop, or an OEM Android build that has crippled WorkManager —
      // degrade to "background sync is off", never take the app down over it.
      // Foreground sync (the connectivity listener, a pull-to-refresh) is
      // untouched either way.
      debugPrint('ABOS background sync: could not schedule ($error)');
    }
  }
}

/// Top-level and `@pragma('vm:entry-point')`: workmanager calls this by name
/// from native code in a fresh isolate, so it cannot be a class member and
/// must survive tree-shaking.
@pragma('vm:entry-point')
void callbackDispatcher() {
  Workmanager().executeTask((task, inputData) async {
    WidgetsFlutterBinding.ensureInitialized();
    try {
      await Hive.initFlutter();

      // Nothing to push if nobody is signed in on this device right now.
      // Skipping is not laziness: letting the flush run would answer 401 on
      // every queued row and walk each one's attempt counter toward permanent
      // rejection — for a session that is simply closed, not failing.
      final refreshToken = await TokenStorage.instance.refreshToken();
      if (refreshToken == null || refreshToken.isEmpty) return true;

      await SyncEngine.instance.init();
      await SyncEngine.instance.flushAll();
    } catch (error) {
      // flush()/flushAll() already catch their own network and server failures
      // and leave the queue for the next attempt — this is the backstop for
      // everything else (Hive failing to open, say), so one bad background run
      // cannot mark the whole task permanently broken.
      debugPrint('ABOS background sync: task failed ($error)');
    }
    return true;
  });
}
