import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/update/apk_installer.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/core/update/storage_check.dart';
import 'package:abos_mobile/core/update/update_install_flow.dart';
import 'package:abos_mobile/features/update/update_download_button.dart';

/// The app updating itself — docs/Contract §৬ rule খ.
///
/// <p>The owner's decision of 27 September 2026 opened a path the contract
/// had kept shut: an installer route inside the app is a new way to put the
/// wrong file on a phone. These are the walls that came with it.
void main() {
  const hash =
      '3f5a0c1d2e4b6a79880f1e2d3c4b5a69788796a5b4c3d2e1f00112233445c09e';
  const size = 74213888;

  const release = AppRelease(
    versionCode: 6,
    versionName: '0.5.0',
    url: 'https://erp.adi.com.bd/app/abos.apk',
    apkSha256: hash,
    sizeBytes: size,
  );

  /// Everything the flow did, in order, so a test can say not only what
  /// happened but what did not.
  late List<String> did;

  UpdateActions actions({
    bool canInstall = true,
    File? cached,
    int? free = 500 * 1024 * 1024,
    int downloadedLength = size,
    String downloadedHash = hash,
    Object? downloadFails,
  }) =>
      UpdateActions(
        canInstall: () async {
          did.add('canInstall');
          return canInstall;
        },
        cached: () async => cached,
        freeBytes: () async => free,
        download: (url, onProgress) async {
          did.add('download');
          if (downloadFails != null) throw downloadFails;
          onProgress(size ~/ 2, size);
          onProgress(size, size);
          return File('downloaded.apk');
        },
        lengthOf: (file) async =>
            file.path == 'leftover.apk' ? size : downloadedLength,
        sha256Of: (file) async {
          did.add('sha256:${file.path}');
          return downloadedHash;
        },
        install: (file) async => did.add('install:${file.path}'),
        delete: (file) async => did.add('delete:${file.path}'),
      );

  setUp(() => did = []);

  group('what may be installed at all', () {
    test('an https address, a whole hash and a real size: all three', () {
      expect(release.installable, isTrue);
    });

    test('plain http is refused before a byte moves', () async {
      const insecure = AppRelease(
        versionCode: 6,
        url: 'http://erp.adi.com.bd/app/abos.apk',
        apkSha256: hash,
        sizeBytes: size,
      );

      expect(insecure.installable, isFalse);
      final result = await UpdateInstallFlow.run(insecure, actions: actions());
      expect(result.outcome, UpdateOutcome.notInstallable);
      expect(did, isEmpty);
    });

    test('no hash, a short hash or no size: nothing is downloaded', () async {
      for (final broken in const [
        AppRelease(versionCode: 6, url: 'https://x/a.apk', sizeBytes: size),
        AppRelease(
            versionCode: 6,
            url: 'https://x/a.apk',
            apkSha256: '3f5a',
            sizeBytes: size),
        AppRelease(versionCode: 6, url: 'https://x/a.apk', apkSha256: hash),
        AppRelease(
            versionCode: 6,
            url: 'https://x/a.apk',
            apkSha256: hash,
            sizeBytes: 0),
      ]) {
        expect(broken.installable, isFalse);
        final result =
            await UpdateInstallFlow.run(broken, actions: actions());
        expect(result.outcome, UpdateOutcome.notInstallable);
      }
      // A download that cannot be checked must not be started.
      expect(did, isEmpty);
    });

    test('the size and the hash are read from the payload as sent', () {
      final parsed = AppRelease.fromJson(const {
        'versionCode': 5,
        'url': 'https://erp.adi.com.bd/app/abos.apk',
        'apkSha256': hash,
        'sizeBytes': size,
      });

      expect(parsed.apkSha256, hash);
      expect(parsed.sizeBytes, size);
      expect(parsed.installable, isTrue);
    });
  });

  group('the flow', () {
    test('download, size, hash, then the installer: in that order',
        () async {
      final steps = <UpdateStep>[];
      final result = await UpdateInstallFlow.run(
        release,
        actions: actions(),
        onStep: (step, _) => steps.add(step),
      );

      expect(result.outcome, UpdateOutcome.handedToInstaller);
      expect(did, [
        'canInstall',
        'download',
        'sha256:downloaded.apk',
        'install:downloaded.apk',
      ]);
      expect(steps.first, UpdateStep.checking);
      expect(steps, contains(UpdateStep.downloading));
      expect(steps.last, UpdateStep.verifying);
    });

    test('the permission is asked before a single byte moves', () async {
      // Android refuses at the END otherwise: after the download, in
      // English, on a rep's mobile data.
      final result = await UpdateInstallFlow.run(
        release,
        actions: actions(canInstall: false),
      );

      expect(result.outcome, UpdateOutcome.needsPermission);
      expect(did, ['canInstall']);
    });

    test('⛔ a file whose hash does not match is deleted, never installed',
        () async {
      final result = await UpdateInstallFlow.run(
        release,
        actions: actions(downloadedHash: 'f' * 64),
      );

      expect(result.outcome, UpdateOutcome.mismatch);
      expect(did, contains('delete:downloaded.apk'));
      expect(did.where((step) => step.startsWith('install')), isEmpty);
    });

    test('a truncated download is caught by its size, before it is hashed',
        () async {
      final result = await UpdateInstallFlow.run(
        release,
        actions: actions(downloadedLength: size - 1),
      );

      expect(result.outcome, UpdateOutcome.incomplete);
      expect(did, contains('delete:downloaded.apk'));
      expect(did.where((step) => step.startsWith('sha256')), isEmpty);
      expect(did.where((step) => step.startsWith('install')), isEmpty);
    });

    test('a full phone is told how much to free, before downloading',
        () async {
      final result = await UpdateInstallFlow.run(
        release,
        actions: actions(free: size),
      );

      expect(result.outcome, UpdateOutcome.notEnoughSpace);
      expect(result.megabytesNeeded, megabytesNeeded(size));
      expect(did, isNot(contains('download')));
    });

    test('a phone that cannot measure its space is not stopped', () async {
      final result =
          await UpdateInstallFlow.run(release, actions: actions(free: null));
      expect(result.outcome, UpdateOutcome.handedToInstaller);
    });

    test('a dropped connection is said to be one', () async {
      final result = await UpdateInstallFlow.run(
        release,
        actions: actions(
          downloadFails: DioException(
            requestOptions: RequestOptions(path: '/app/abos.apk'),
            type: DioExceptionType.connectionError,
          ),
        ),
      );

      expect(result.outcome, UpdateOutcome.offline);
      expect(did.where((step) => step.startsWith('install')), isEmpty);
    });

    test('a verified leftover is installed without a second download',
        () async {
      // What makes granting the permission cost nothing the second time.
      final result = await UpdateInstallFlow.run(
        release,
        actions: actions(cached: File('leftover.apk')),
      );

      expect(result.outcome, UpdateOutcome.handedToInstaller);
      expect(did, isNot(contains('download')));
      expect(did, contains('install:leftover.apk'));
    });

    test('a leftover that does not verify is deleted and fetched afresh',
        () async {
      var hashes = 0;
      final base = actions(cached: File('leftover.apk'));
      final result = await UpdateInstallFlow.run(
        release,
        actions: UpdateActions(
          canInstall: base.canInstall,
          cached: base.cached,
          freeBytes: base.freeBytes,
          download: base.download,
          lengthOf: base.lengthOf,
          // Yesterday's build: right size, wrong contents.
          sha256Of: (file) async {
            hashes++;
            return file.path == 'leftover.apk' ? 'a' * 64 : hash;
          },
          install: base.install,
          delete: base.delete,
        ),
      );

      expect(result.outcome, UpdateOutcome.handedToInstaller);
      expect(hashes, 2);
      expect(did, contains('delete:leftover.apk'));
      expect(did, contains('download'));
      expect(did, contains('install:downloaded.apk'));
      expect(did, isNot(contains('install:leftover.apk')));
    });
  });

  /// ⛔ ২ অক্টোবর ২০২৬, মালিকের ফোন: "এখনই আপডেট করুন" চাপলে কিছুই হল না। ফাইল ঠিক ছিল, কিন্তু
  /// বসানোর পর্দা খোলেনি — আর OpenFilex ফেলে না, ফল ফেরায়; ফলটা পড়া হত না, তাই পর্দায় কোনো কথাই নেই।
  group('when the installer does not open', () {
    UpdateActions refusing(Object error) {
      final base = actions();
      return UpdateActions(
        canInstall: base.canInstall,
        cached: base.cached,
        freeBytes: base.freeBytes,
        download: base.download,
        lengthOf: base.lengthOf,
        sha256Of: base.sha256Of,
        install: (file) async => throw error,
        delete: base.delete,
      );
    }

    test('a refusal is said, with what Android said, never read as success', () async {
      final result = await UpdateInstallFlow.run(release,
          actions: refusing(const InstallerRefused('noAppToOpen: No APP found which can open this file')));
      expect(result.outcome, UpdateOutcome.installerRefused);
      expect(result.detail, contains('noAppToOpen'));
    });

    test('a missing install permission sends the person to the switch', () async {
      final result = await UpdateInstallFlow.run(release, actions: refusing(const InstallPermissionMissing()));
      expect(result.outcome, UpdateOutcome.needsPermission);
    });

    testWidgets('the button shows the sentence, not silence', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: UpdateDownloadButton(
            release: release,
            actions: refusing(const InstallerRefused('error: boom')),
          ),
        ),
      ));
      await tester.tap(find.text('নতুন সংস্করণ নামান'));
      await tester.pumpAndSettle();
      expect(find.textContaining('ফোন বসানোর পর্দা খুলতে দিল না'), findsOneWidget);
      expect(find.textContaining('error: boom'), findsOneWidget);
    });
  });

  group('the comparisons', () {
    test('a hash is the same hash in upper or lower hex', () {
      expect(sha256Matches(hash.toUpperCase(), hash), isTrue);
      expect(sha256Matches(' $hash ', hash), isTrue);
      expect(sha256Matches('f' * 64, hash), isFalse);
    });

    test('⛔ nothing to compare against never counts as a match', () {
      expect(sha256Matches(hash, null), isFalse);
      expect(sha256Matches(hash, ''), isFalse);
      expect(sha256Matches('', ''), isFalse);
      expect(sizeMatches(size, null), isFalse);
      expect(sizeMatches(0, 0), isFalse);
    });

    test('room for the installer as well as the file', () {
      expect(hasEnoughSpace(size + updateSpaceMargin, size), isTrue);
      expect(hasEnoughSpace(size + updateSpaceMargin - 1, size), isFalse);
    });

    test('only https', () {
      expect(isHttps('https://erp.adi.com.bd/app/abos.apk'), isTrue);
      expect(isHttps('http://erp.adi.com.bd/app/abos.apk'), isFalse);
      expect(isHttps('file:///sdcard/abos.apk'), isFalse);
      expect(isHttps('not an address'), isFalse);
    });
  });

  group('what a person sees', () {
    Widget button(AppRelease release, UpdateActions actions) => MaterialApp(
          home: Scaffold(
            body: UpdateDownloadButton(
              release: release,
              actions: actions,
              openInstallSettings: () async => did.add('settings'),
            ),
          ),
        );

    testWidgets('a file that does not match: said plainly, in Bangla',
        (tester) async {
      await tester
          .pumpWidget(button(release, actions(downloadedHash: 'f' * 64)));
      await tester.tap(find.text('নতুন সংস্করণ নামান'));
      await tester.pumpAndSettle();

      expect(find.textContaining('মেলেনি'), findsOneWidget);
      expect(find.textContaining('বসানো হয়নি'), findsOneWidget);
      expect(find.textContaining('মুছে ফেলা হয়েছে'), findsOneWidget);
      expect(did.where((step) => step.startsWith('install')), isEmpty);
      // And it can be tried again.
      expect(find.text('নতুন সংস্করণ নামান'), findsOneWidget);
    });

    testWidgets('no permission: what to switch on, and a way to get there',
        (tester) async {
      await tester.pumpWidget(button(release, actions(canInstall: false)));
      await tester.tap(find.text('নতুন সংস্করণ নামান'));
      await tester.pumpAndSettle();

      expect(find.textContaining('অনুমতি'), findsWidgets);
      await tester.tap(find.text('সেটিংস খুলুন'));
      await tester.pumpAndSettle();
      expect(did, contains('settings'));
      expect(find.text('আবার চেষ্টা করুন'), findsOneWidget);
    });

    testWidgets('a full phone: how many megabytes to free', (tester) async {
      await tester.pumpWidget(button(release, actions(free: 1024)));
      await tester.tap(find.text('নতুন সংস্করণ নামান'));
      await tester.pumpAndSettle();

      expect(find.textContaining('${megabytesNeeded(size)}MB'), findsOneWidget);
    });

    testWidgets('once the installer has it, the app says nothing over it',
        (tester) async {
      await tester.pumpWidget(button(release, actions()));
      await tester.tap(find.text('নতুন সংস্করণ নামান'));
      await tester.pumpAndSettle();

      expect(did.last, 'install:downloaded.apk');
      expect(find.textContaining('বসানো হয়নি'), findsNothing);
      expect(find.textContaining('গেল না'), findsNothing);
    });

    testWidgets('a release that cannot be verified offers no button',
        (tester) async {
      await tester.pumpWidget(button(
        const AppRelease(versionCode: 6, url: 'https://x/a.apk'),
        actions(),
      ));
      await tester.pumpAndSettle();

      expect(find.text('নতুন সংস্করণ নামান'), findsNothing);
      expect(find.textContaining('অফিসে জানান'), findsOneWidget);
    });
  });
}
