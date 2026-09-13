import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/config/app_config.dart';

/// [AppConfig.appVersion] against `pubspec.yaml`, because nothing else holds
/// them together.
///
/// <p>The constant is what the phone tells the server it is — in the login
/// body and in the `X-App-Version` header on every sync call, which is how
/// `SyncController` registers the device. Dart cannot read pubspec at runtime
/// without another package, so the number is written twice, and a number
/// written twice drifts the first time somebody bumps one of them.
///
/// <p>⚠️ A stale version constant is worse than none at all. Absent, the
/// server records "unknown" and everybody knows to ask. Wrong, it records
/// 0.1.0 for a handset running something else, and the office debugs the
/// wrong build — confidently.
void main() {
  test('the version the app reports is the version it was built at', () {
    final pubspec = File('pubspec.yaml');
    expect(pubspec.existsSync(), isTrue,
        reason: 'read from the package root, which is flutter test\'s own '
            'working directory');

    final line = pubspec
        .readAsLinesSync()
        .firstWhere((l) => l.startsWith('version:'), orElse: () => '');

    expect(line, isNotEmpty,
        reason: 'pubspec has no version: line — this test would otherwise '
            'compare against an empty string and pass while checking nothing');

    // `0.1.0+1` → `0.1.0`. The +N is Android's versionCode, a counter that
    // only ever goes up; it is not part of the name a person or a server
    // reads, and pubspec's own comment explains why it exists at all.
    final name = line.split(':').last.trim().split('+').first;

    expect(AppConfig.appVersion, name,
        reason: 'AppConfig.appVersion and pubspec.yaml have drifted. Whichever '
            'was bumped, the other must follow — the server is told this '
            'number on every request and will believe it');
  });
}
