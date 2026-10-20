import 'dart:async';
import 'dart:math';

import 'package:abos_mobile/core/sync_engine/auto_sync.dart';
import 'package:abos_mobile/core/sync_engine/live_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ রিয়েল-টাইম সিঙ্ক — মালিক ১০ অক্টোবর "ক" (push + poll); a4-এর পরিকল্পনা সংস্করণ ২ §৪ (fe, ১৯ অক্টোবর ২০২৬)।
///
/// <p>⛔ আগে সামনে খোলা অ্যাপ নতুন খবর পেত কেবল নীরব ডাকে বা ১০ মিনিটের টাইমারে; পেছনে বা বন্ধ অবস্থার ডাক হারিয়ে যেত;
/// "শেষ সিঙ্ক" কোথাও ছিল না।
///
/// <p>⭐ দাবি: ছোট প্রশ্ন ১৫–২০ সেকেন্ডে, Battery Saver-এ ৬০, ব্যর্থতায় ২০ → ৪০ → ৮০ … সর্বোচ্চ ৫ মিনিট; প্রথম উত্তর কেবল ছাপ
/// শেখায়, "বদলেছে" হলে একটা গোল, "বদলায়নি" হলে কিছুই নয়; পুরনো সার্ভারে প্রশ্ন থামে; পেছনে গেলে প্রশ্ন নেই; পেছনের ডাক জমে
/// থাকলে সামনে আসামাত্র টান, দুই মিনিটের ফাঁক না মেনে; সিঙ্কের অবস্থা সৎ — সফল, ব্যর্থ, পুরনো, অফলাইন।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('the live poll', () {
    late List<String?> asked;
    late int rounds;
    late List<(LiveAnswer, String?)> answers;
    late bool saving;
    late bool throws;

    LivePoll poll() => LivePoll(
          signedIn: () => true,
          onChanged: () async => rounds++,
          ask: (etag) async {
            asked.add(etag);
            if (throws) throw StateError('net');
            return answers.removeAt(0);
          },
          powerSave: () async => saving,
          random: Random(1),
        );

    setUp(() {
      asked = [];
      rounds = 0;
      answers = [];
      saving = false;
      throws = false;
    });

    test('the first answer only learns the stamp; a change runs a round, no change runs nothing', () async {
      final p = poll();
      answers = [(LiveAnswer.changed, 'e1'), (LiveAnswer.unchanged, 'e1'), (LiveAnswer.changed, 'e2')];
      await p.tick();
      expect(rounds, 0, reason: 'খোলার মুহূর্তে AutoSync নিজেই গোল চালায় — দুবার নয়');
      await p.tick();
      expect(rounds, 0, reason: '⛔ কিছু বদলায়নি, তবু পুরো সিঙ্ক — ব্যাটারি আর ডেটা নষ্ট');
      await p.tick();
      expect(rounds, 1, reason: '⛔ সার্ভার বলল বদলেছে, অথচ টানা হলো না');
      expect(asked, [null, 'e1', 'e1'], reason: 'আগের ছাপ নিয়ে প্রশ্ন (If-None-Match)');
      p.stop();
    });

    test('every 15 to 20 seconds, 60 on Battery Saver, and backing off on failure up to 5 minutes', () async {
      final p = poll();
      for (var i = 0; i < 20; i++) {
        final d = await p.nextDelay();
        expect(d.inMilliseconds, inInclusiveRange(15000, 20000), reason: '⛔ ১৫–২০ সেকেন্ডের বাইরে');
      }
      saving = true;
      expect(await p.nextDelay(), const Duration(seconds: 60), reason: '⛔ Battery Saver মানা হলো না');
      saving = false;

      throws = true;
      final steps = <int>[];
      for (var i = 0; i < 6; i++) {
        await p.tick();
        steps.add((await p.nextDelay()).inSeconds);
      }
      expect(steps, [20, 40, 80, 160, 300, 300], reason: '⛔ ব্যর্থতায় বিরতি বাড়েনি, বা ৫ মিনিট ছাড়াল');

      throws = false;
      answers = [(LiveAnswer.unchanged, null)];
      await p.tick();
      expect(p.failures, 0, reason: 'সফল হলে বিরতি আবার স্বাভাবিক');
      p.stop();
    });

    test('an older server without the door stops the questions', () async {
      final p = poll();
      answers = [(LiveAnswer.unsupported, null)];
      await p.tick();
      expect(p.unsupported, isTrue);
      await p.tick();
      expect(asked, hasLength(1), reason: '⛔ ৪০৪-এর পরেও প্রশ্ন চলছে');
      p.stop();
    });

    test('no questions in the background', () async {
      final p = poll();
      p.didChangeAppLifecycleState(AppLifecycleState.paused);
      answers = [(LiveAnswer.changed, 'e1')];
      await p.tick();
      expect(asked, isEmpty, reason: '⛔ অ্যাপ পেছনে, তবু প্রশ্ন');
      p.stop();
    });
  });

  group('a nudge that came in the background', () {
    tearDown(() => AutoSync.nudgedProbe = NudgeMark.take);

    test('coming back pulls at once, even a minute after the last round', () async {
      var rounds = 0;
      var now = DateTime(2026, 10, 19, 22);
      final auto = AutoSync(signedIn: () => true, round: () async => rounds++, clock: () => now);
      await auto.runNow();
      expect(rounds, 1);

      now = now.add(const Duration(minutes: 1));
      AutoSync.nudgedProbe = () async => false;
      auto.didChangeAppLifecycleState(AppLifecycleState.resumed);
      await pumpEventQueue();
      expect(rounds, 1, reason: 'ডাক নেই — দুই মিনিটের ফাঁক মানা');

      AutoSync.nudgedProbe = () async => true;
      auto.didChangeAppLifecycleState(AppLifecycleState.resumed);
      await pumpEventQueue();
      expect(rounds, 2, reason: '⛔ পেছনে ডাক এসেছিল, সামনে এসেও টানা হলো না');
    });
  });

  group('the honest status', () {
    test('a round says ok or failed, and fifteen minutes makes it stale', () async {
      var now = DateTime(2026, 10, 19, 22);
      final s = SyncStatus(clock: () => now);
      expect(s.stale, isTrue, reason: 'এখনো সিঙ্ক হয়নি — পুরনো');

      await s.track(() async {});
      expect(s.lastOkAt, now);
      expect(s.failed, isFalse);
      expect(s.stale, isFalse);

      await expectLater(s.track(() async => throw StateError('x')), throwsStateError);
      expect(s.failed, isTrue);
      expect(s.lastOkAt, now, reason: 'ব্যর্থ গোল শেষ সফলের সময় মোছে না');

      now = now.add(const Duration(minutes: 16));
      expect(s.stale, isTrue, reason: '⛔ ১৬ মিনিট পুরনো, তবু "তাজা"');
    });

    test('the network going and coming is followed', () async {
      final net = StreamController<List<ConnectivityResult>>();
      final s = SyncStatus()..watchNetwork(changes: net.stream);
      net.add([ConnectivityResult.none]);
      await pumpEventQueue();
      expect(s.offline, isTrue);
      net.add([ConnectivityResult.wifi]);
      await pumpEventQueue();
      expect(s.offline, isFalse);
      await net.close();
      s.reset();
    });

    test('the label reads in Bengali', () {
      final now = DateTime(2026, 10, 19, 22);
      final s = SyncStatus(clock: () => now);
      expect(LastSyncLabel.words(s, now), 'এখনো সিঙ্ক হয়নি');
      unawaited(s.track(() async {}));
    });

    test('two minutes ago, in Bengali digits', () async {
      final at = DateTime(2026, 10, 19, 22);
      final s = SyncStatus(clock: () => at);
      await s.track(() async {});
      expect(LastSyncLabel.words(s, at.add(const Duration(minutes: 2))), 'শেষ সিঙ্ক: ২ মিনিট আগে');
      expect(LastSyncLabel.words(s, at.add(const Duration(seconds: 10))), 'শেষ সিঙ্ক: এইমাত্র');
    });
  });
}
