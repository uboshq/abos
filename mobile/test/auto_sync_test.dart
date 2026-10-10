import 'dart:async';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/auto_sync.dart';

/// ⭐ নিজে থেকে সিঙ্ক — মালিক, ১০ অক্টোবর ২০২৬: "auto sync er bebosta koro"।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('signed out, the automatic round sends and pulls nothing', () async {
    var rounds = 0;
    final auto = AutoSync(signedIn: () => false, round: () async => rounds++);

    expect(await auto.tick(), isFalse);
    expect(rounds, 0);
  });

  test('signed in, it runs — and not again within two minutes', () async {
    var rounds = 0;
    var now = DateTime(2026, 10, 10, 15);
    final auto = AutoSync(
        signedIn: () => true, round: () async => rounds++, clock: () => now);

    expect(await auto.tick(), isTrue);
    expect(rounds, 1);

    now = now.add(const Duration(seconds: 30));
    expect(await auto.tick(), isFalse, reason: 'a dialog closing is no reason');
    expect(rounds, 1);

    now = now.add(AutoSync.minimumGap);
    expect(await auto.tick(), isTrue);
    expect(rounds, 2);
  });

  test('a pull during a running round joins it — the queue is not sent twice',
      () async {
    var rounds = 0;
    final gate = Completer<void>();
    final auto = AutoSync(
        signedIn: () => true,
        round: () async {
          rounds++;
          await gate.future;
        });

    final first = auto.runNow();
    final second = auto.runNow();
    gate.complete();
    await Future.wait([first, second]);

    expect(rounds, 1);
  });

  test('a failed automatic round never throws; a pull hears about it',
      () async {
    final auto = AutoSync(
        signedIn: () => true, round: () async => throw StateError('no signal'));

    expect(await auto.tick(), isTrue);
    await expectLater(auto.runNow(), throwsStateError);
  });
}
