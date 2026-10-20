import 'package:flutter/foundation.dart';
import 'package:workmanager/workmanager.dart';

/// ⭐ পেছনের উইজেট-গোল — প্রতি ৩০ মিনিটে, নেট থাকলে ([WidgetHub.refresh]); সাইন-ইনে চালু, সাইন-আউটে বন্ধ।
///
/// <p>ⓘ WorkManager-এর একটাই ডাকার জায়গা ([callbackDispatcher], `background_sync.dart`) — সেখানে কাজের নাম দেখে এই গোল।
/// কখনো ছোড়ে না: যে ফোনে WorkManager পঙ্গু, সেখানে কেবল "পেছনের উইজেট-গোল বন্ধ" — বাকি সব আগের মতো।
class WidgetHubTask {
  const WidgetHubTask._();

  static const taskName = 'abos-widget-hub';

  /// ⛔ Android-এর তলা ১৫ মিনিট; ৩০ বাছাই — সারাংশ ETag-এ সস্তা, তবু ব্যাটারি
  static const every = Duration(minutes: 30);

  @visibleForTesting
  static Future<void> Function() registrar = _register;

  @visibleForTesting
  static Future<void> Function() canceller = _cancel;

  static Future<void> start() async {
    try {
      await registrar();
    } catch (error) {
      debugPrint('ABOS widget hub task: could not schedule ($error)');
    }
  }

  static Future<void> stop() async {
    try {
      await canceller();
    } catch (error) {
      debugPrint('ABOS widget hub task: could not cancel ($error)');
    }
  }

  static Future<void> _register() => Workmanager().registerPeriodicTask(
        taskName,
        taskName,
        frequency: every,
        constraints: Constraints(networkType: NetworkType.connected),
        existingWorkPolicy: ExistingPeriodicWorkPolicy.keep,
      );

  static Future<void> _cancel() => Workmanager().cancelByUniqueName(taskName);
}
