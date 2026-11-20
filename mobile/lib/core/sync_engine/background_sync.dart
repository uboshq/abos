import 'package:flutter/widgets.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:workmanager/workmanager.dart';

import '../api_client/api_client.dart';
import '../auth/session_repository.dart';
import '../auth/token_storage.dart';
import '../launcher_widgets/hub/widget_hub.dart';
import '../launcher_widgets/hub/widget_hub_task.dart';
import '../launcher_widgets/launcher_widget_refresh.dart';
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
    // ⭐ উইজেটের ৩০ মিনিটের কাজ — কেবল উইজেট, সারি ছোঁয় না ([WidgetHubTask])
    if (task == WidgetHubTask.taskName) {
      await widgetRound();
      return true;
    }
    await backgroundRound();
    return true;
  });
}

/// ⭐ পেছনের উইজেট-গোল — সাইন-ইন না থাকলে কিছু নয় (৪০১-এ কিছু মোছা হত না, শুধু নষ্ট ডাক)।
/// ⛔ Hive-এর সারি এখানে খোলা হয় না — খোলা অ্যাপের বাক্সের সাথে টক্কর নয়।
Future<void> widgetRound() async {
  WidgetsFlutterBinding.ensureInitialized();
  try {
    final refreshToken = await TokenStorage.instance.refreshToken();
    if (refreshToken == null || refreshToken.isEmpty) return;
    await WidgetHub.refresh();
  } catch (error) {
    debugPrint('ABOS widget hub task: failed ($error)');
  }
}

/// কত পুরনো জোড়া পেছনে আগেভাগে নতুন হয় ([[renewIfStale]])
const Duration renewTokensAfter = Duration(days: 3);

/// ⭐ অ্যাপ না খুললেও ঢোকা থাকে (মালিক, ২০ নভেম্বর ২০২৬: "লগআউট না চাপা পর্যন্ত ঢোকা; ৩০ দিন না খুললেও বের নয়") —
/// জোড়া [renewTokensAfter]-এর বেশি পুরনো (বা কবে রাখা জানা নেই) হলে পেছনেই নবায়ন ([[ApiClient.renewNow]])। সার্ভারের
/// নবায়ন-টোকেন ৩৬৫ দিন, প্রতি নবায়নে নতুন করে গোনা — তাই ফোন মাঝে মাঝে নেট পেলেই সেশন চিরকাল চলে।
/// ⛔ নেট নেই, সার্ভার ব্যস্ত — টোকেন থাকে, পরের গোলে আবার; কেবল সার্ভারের নিশ্চিত "না"-তে মোছে (ApiClient-এর নিয়ম)।
/// ⓘ ঢোকা না থাকলে কিছু নয়। ফেরে নবায়ন হলো কি না; কখনো ছোড়ে না।
Future<bool> renewIfStale({DateTime? now, Future<bool> Function()? renew}) async {
  try {
    final refreshToken = await TokenStorage.instance.refreshToken();
    if (refreshToken == null || refreshToken.isEmpty) return false;
    final savedAt = await TokenStorage.instance.tokensSavedAt();
    final current = (now ?? DateTime.now()).toUtc();
    if (savedAt != null && current.difference(savedAt) < renewTokensAfter) return false;
    return await (renew ?? ApiClient.renewNow)();
  } catch (error) {
    debugPrint('ABOS background renew: skipped ($error)');
    return false;
  }
}

/// ⭐ পেছনের একটা গোল — জমা কাজ পাঠানো আর ঘরের পর্দার উইজেট; WorkManager-এর ১৫ মিনিটের কাজ আর পেছনের নীরব ডাক
/// ([onBackgroundNudge]) দুইয়েরই একই শরীর।
/// ⛔ টানা (pull) এখানে নয়: খোলা অ্যাপ পেছনে থাকলে তার Hive বাক্স খোলা — দুই isolate একই বাক্স খুললে বাক্স ভাঙে;
/// টানা হয় অ্যাপ সামনে এলেই ([NudgeMark], [AutoSync])।
Future<void> backgroundRound() async {
    WidgetsFlutterBinding.ensureInitialized();
    try {
      await Hive.initFlutter();

      // Nothing to push if nobody is signed in on this device right now.
      // Skipping is not laziness: letting the flush run would answer 401 on
      // every queued row and walk each one's attempt counter toward permanent
      // rejection — for a session that is simply closed, not failing.
      final refreshToken = await TokenStorage.instance.refreshToken();
      if (refreshToken == null || refreshToken.isEmpty) return;

      // ⭐ চুপচাপ নবায়ন — সারি পাঠানোর আগে, যাতে পাঠানোও নতুন টোকেনে ([[renewIfStale]]); কখনো ছোড়ে না
      await renewIfStale();

      // ⛔ খোলা অ্যাপ বাক্স ধরে থাকলে পেছনে কিছুই খোলা হয় না, কিছুই মোছা হয় না — অ্যাপ নিজেই পাঠায় ([[QueueLock]])
      await SyncEngine.instance.init(background: true);
      // ⛔ পেছনেও কেবল এখনকার মানুষের সারি — সংরক্ষিত প্রোফাইল থেকে; পড়া না গেলে কিছুই যায় না ([[SyncEngine.actAs]])
      try {
        SyncEngine.instance.actAs((await SessionRepository.instance.readUser())?.id);
      } catch (_) {
        SyncEngine.instance.actAs(null);
      }
      await SyncEngine.instance.flushAll();

      // The home-screen widgets ride the same tick. Somebody who has not
      // opened the app all morning still reads the launcher, and this is
      // the only thing that runs for them. After the flush, so the figures
      // include the orders that just went up.
      await LauncherWidgetRefresh.refresh();
      // ⭐ নতুন উইজেটগুলোও একই গোলে — কখনো ছোড়ে না ([WidgetHub])
      await WidgetHub.refresh();
    } catch (error) {
      // ⓘ নিচে finally — ব্যর্থ হলেও তালা ছাড়া হয়
      // flush()/flushAll() already catch their own network and server failures
      // and leave the queue for the next attempt — this is the backstop for
      // everything else (Hive failing to open, say), so one bad background run
      // cannot mark the whole task permanently broken.
      debugPrint('ABOS background sync: task failed ($error)');
    } finally {
      // ⛔ বাক্স বন্ধ আর তালা ছাড়া — নইলে পরে খোলা অ্যাপ "busy" দেখত, যতক্ষণ এই isolate বেঁচে
      try {
        await SyncEngine.instance.closeQueue();
      } catch (_) {}
    }
}
