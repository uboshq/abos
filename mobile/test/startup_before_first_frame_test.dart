import 'package:abos_mobile/core/push/push_service.dart';
import 'package:abos_mobile/core/startup/startup.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ প্রথম ছবির আগে কেবল যা প্রথম পর্দার লাগে — ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬ ([[Startup]])।
///
/// <p>⛔ আগে main() প্রথম ফ্রেমের আগে WorkManager বসানো আর Firebase চালু হওয়ার অপেক্ষা করত। দাবি: runApp-এর আগে চলে কেবল
/// Hive, অফলাইন সারি, রেফারেন্স ক্যাশ, তালার সময় আর সংরক্ষিত সেশন — কোনো নেটের বা Firebase-এর ডাক নয়; বাকিগুলো প্রথম ফ্রেমের
/// পরে, আর ওগুলোর একটা ভাঙলেও বাকিরা চলে। বার্তার শোনা ([[PushService.listenForTaps]]) Firebase পরে উঠলেও বসে।
void main() {
  StartupSteps steps(List<String> log, {bool pushFails = false}) => StartupSteps(
        storage: () async => log.add('storage'),
        queue: () async => log.add('queue'),
        referenceCache: () async => log.add('referenceCache'),
        lockDelay: () async => log.add('lockDelay'),
        restoreSession: () async {
          log.add('session');
          return true;
        },
        backgroundSync: () async => log.add('backgroundSync'),
        push: (signedIn) async {
          log.add('push:$signedIn');
          if (pushFails) throw StateError('no Play Services');
        },
        signedInExtras: () async => log.add('launcherWidgets'),
        afterFrame: () => log.add('afterFrame'),
      );

  testWidgets('before the first frame only local storage and the saved session; push and WorkManager after it',
      (tester) async {
    final log = <String>[];
    await Startup(steps(log)).boot((signedIn) => const SizedBox(), run: (_) => log.add('runApp'));

    expect(log, ['storage', 'queue', 'referenceCache', 'lockDelay', 'session', 'runApp'],
        reason: '⛔ প্রথম ছবির আগে আর কিছু চলল');

    // ⓘ runApp-এর প্রথম ফ্রেম — এখানে হাতে
    await tester.pumpWidget(const SizedBox());
    await tester.pump();
    expect(log.sublist(6), ['afterFrame', 'backgroundSync', 'push:true', 'launcherWidgets']);
  });

  testWidgets('a deferred step that fails does not stop the ones after it', (tester) async {
    final log = <String>[];
    await Startup(steps(log, pushFails: true)).boot((_) => const SizedBox(), run: (_) => log.add('runApp'));
    await tester.pumpWidget(const SizedBox());
    await tester.pump();
    expect(log.last, 'launcherWidgets', reason: '⛔ Firebase না ওঠায় উইজেট ভরা হলো না');
  });

  test('a push listener added before Firebase is up is attached once it is', () {
    final push = PushService.instance;
    push.debugReset();
    var attached = 0;
    push.whenReady(() => attached++);
    expect(attached, 0);
    push.debugMarkReady();
    expect(attached, 1, reason: '⛔ বার্তায় চেপে খোলা — শোনা বসলই না');
    push.whenReady(() => attached++);
    expect(attached, 2, reason: 'উঠে থাকলে সাথে সাথে');
    push.debugReset();
  });
}
