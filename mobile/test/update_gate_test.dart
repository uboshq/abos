import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/update/update_gate.dart';

/// The version check — docs/Contract §৬.
///
/// <p>Two questions that must not be answered with one flag: *is there a
/// newer build* and *is this one still fit to use*. And one rule that matters
/// more than either: a check that could not be made changes nothing.
void main() {
  const release = AppRelease(
    versionCode: 5,
    versionName: '0.5.0',
    url: 'https://erp.adi.com.bd/app/abos-arm64.apk',
    minimumCode: 3,
    noteBn: 'নতুন সংস্করণ এসেছে।',
    noteEn: 'A new version is available.',
  );

  group('the decision', () {
    test('current build, nothing to say', () {
      expect(UpdateStatus.of(release, current: 5).verdict, UpdateVerdict.fine);
      // Ahead of the server — a developer build, or a rollback on the server.
      // Not a reason to nag anybody.
      expect(UpdateStatus.of(release, current: 9).verdict, UpdateVerdict.fine);
    });

    test('behind, but usable — a notice', () {
      expect(UpdateStatus.of(release, current: 4).verdict,
          UpdateVerdict.available);
      expect(UpdateStatus.of(release, current: 3).verdict,
          UpdateVerdict.available);
    });

    test('below the minimum — a wall', () {
      // Not a stronger notice. A build under minimumCode is one whose screens
      // may draw wrong figures rather than none, which is the state the
      // twelfth of September's bugs lived in for a month.
      expect(UpdateStatus.of(release, current: 2).verdict,
          UpdateVerdict.blocked);
    });

    test('a server with no minimum set blocks nobody', () {
      const noMinimum = AppRelease(versionCode: 5);
      expect(UpdateStatus.of(noMinimum, current: 1).verdict,
          UpdateVerdict.available);
    });

    test('⛔ an unreachable server changes nothing at all', () async {
      // The most consequential line in the feature. Treating "could not ask"
      // as "out of date" would lock every handset that lost signal — in an
      // app whose whole purpose is working without one.
      final status = await UpdateStatus.check(
        fetch: () async => throw Exception('no network'),
        current: 1,
      );

      expect(status.verdict, UpdateVerdict.fine);
      expect(status.release, isNull);
    });

    test('the note is read in Bangla first, English as the fallback', () {
      expect(release.note, 'নতুন সংস্করণ এসেছে।');
      expect(const AppRelease(versionCode: 1, noteEn: 'Only English').note,
          'Only English');
    });

    test('the code is read as a number, never as a name', () {
      // "0.2.0" > "0.10.0" is true as a string and false as a version.
      final parsed = AppRelease.fromJson(const {
        'versionCode': 10,
        'versionName': '0.10.0',
        'minimumCode': 2,
        'note': {'bn': 'বাংলা', 'en': 'English'},
      });

      expect(parsed.versionCode, 10);
      expect(parsed.minimumCode, 2);
      expect(parsed.noteBn, 'বাংলা');
    });
  });

  group('what a person sees', () {
    Widget gate(UpdateStatus status) => MaterialApp(
          home: Scaffold(
            body: UpdateGate(
              check: () async => status,
              child: const Text('অ্যাপের ভেতর'),
            ),
          ),
        );

    testWidgets('a notice sits above the work, and the work still shows',
        (tester) async {
      await tester.pumpWidget(gate(const UpdateStatus(
          verdict: UpdateVerdict.available, release: release)));
      await tester.pumpAndSettle();

      expect(find.text('নতুন সংস্করণ এসেছে।'), findsOneWidget);
      expect(find.text('অ্যাপের ভেতর'), findsOneWidget,
          reason: 'a notice is not a wall — the app underneath is still '
              'correct, which is the whole difference between the two');
    });

    testWidgets('the notice can be put aside', (tester) async {
      await tester.pumpWidget(gate(const UpdateStatus(
          verdict: UpdateVerdict.available, release: release)));
      await tester.pumpAndSettle();

      await tester.tap(find.byIcon(Icons.close));
      await tester.pumpAndSettle();

      expect(find.text('নতুন সংস্করণ এসেছে।'), findsNothing);
      expect(find.text('অ্যাপের ভেতর'), findsOneWidget);
    });

    testWidgets('a wall replaces the app entirely', (tester) async {
      await tester.pumpWidget(gate(
          const UpdateStatus(verdict: UpdateVerdict.blocked, release: release)));
      await tester.pumpAndSettle();

      expect(find.text('এই সংস্করণটি আর ব্যবহার করা যাবে না'), findsOneWidget);
      expect(find.text('অ্যাপের ভেতর'), findsNothing);
      expect(find.text('নতুন সংস্করণ নামান'), findsOneWidget);
    });

    testWidgets('nothing is drawn when the check found nothing out',
        (tester) async {
      await tester.pumpWidget(gate(UpdateStatus.fine));
      await tester.pumpAndSettle();

      expect(find.text('অ্যাপের ভেতর'), findsOneWidget);
      expect(find.byIcon(Icons.system_update_outlined), findsNothing);
    });
  });
}
