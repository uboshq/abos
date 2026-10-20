import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/update/apk_installer.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/core/update/update_install_flow.dart';
import 'package:abos_mobile/features/update/update_download_button.dart';

/// ⛔ সার্ভারের ফাইল ঠিক আছে (SHA-256 মিলেছে) — কিন্তু ফাইলটা কি এই অ্যাপেরই, একই চাবিতে সই, আর নতুন? বসানোর পর্দার আগে
/// (অ্যাপ রিলিজ নিরীক্ষা, ec, ২০ অক্টোবর ২০২৬)। আগে debug চাবিতে সই করা বা পুরনো APK সার্ভারে থাকলে Android কেবল
/// "App not installed" বলত, কারণ ছাড়া।
void main() {
  const hash = '3f5a0c1d2e4b6a79880f1e2d3c4b5a69788796a5b4c3d2e1f00112233445c09e';
  const size = 1000;
  const release = AppRelease(
    versionCode: 43,
    url: 'https://erp.adi.com.bd/app/abos.apk',
    apkSha256: hash,
    sizeBytes: size,
  );

  late List<String> did;
  setUp(() => did = []);

  UpdateActions actions(ArchiveCheck? check) => UpdateActions(
        canInstall: () async => true,
        cached: () async => null,
        freeBytes: () async => null,
        download: (url, onProgress) async => File('downloaded.apk'),
        lengthOf: (file) async => size,
        sha256Of: (file) async => hash,
        install: (file) async => did.add('install'),
        delete: (file) async => did.add('delete'),
        inspect: (file) async {
          did.add('inspect');
          return check;
        },
      );

  test('this app, this key, a newer build: handed to the installer', () async {
    final result = await UpdateInstallFlow.run(release,
        actions: actions(const ArchiveCheck(readable: true, samePackage: true, sameSigner: true, newer: true)));
    expect(result.outcome, UpdateOutcome.handedToInstaller);
    expect(did, ['inspect', 'install'], reason: 'যাচাই বসানোর আগে');
  });

  test('another signing key: deleted, never installed', () async {
    final result = await UpdateInstallFlow.run(release,
        actions: actions(const ArchiveCheck(readable: true, samePackage: true, sameSigner: false, newer: true)));
    expect(result.outcome, UpdateOutcome.foreignPackage);
    expect(did, ['inspect', 'delete']);
  });

  test('another package: deleted, never installed', () async {
    final result = await UpdateInstallFlow.run(release,
        actions: actions(const ArchiveCheck(readable: true, samePackage: false, sameSigner: true, newer: true)));
    expect(result.outcome, UpdateOutcome.foreignPackage);
    expect(did, ['inspect', 'delete']);
  });

  test('⛔ not newer than the installed build: no downgrade', () async {
    final result = await UpdateInstallFlow.run(release,
        actions: actions(const ArchiveCheck(readable: true, samePackage: true, sameSigner: true, newer: false)));
    expect(result.outcome, UpdateOutcome.notNewer);
    expect(did, ['inspect', 'delete']);
  });

  test('a phone that cannot say leaves the installer as the judge', () async {
    for (final unknown in const [
      null,
      ArchiveCheck(readable: false),
      ArchiveCheck(readable: true, samePackage: true, newer: true),
    ]) {
      did = [];
      final result = await UpdateInstallFlow.run(release, actions: actions(unknown));
      expect(result.outcome, UpdateOutcome.handedToInstaller, reason: '$unknown');
    }
  });

  test('the native answer is read as sent', () {
    final check = ArchiveCheck.fromMap(const {'readable': true, 'samePackage': true, 'sameSigner': null, 'newer': false});
    expect(check.readable, isTrue);
    expect(check.sameSigner, isNull);
    expect(check.foreign, isFalse);
    expect(check.notNewer, isTrue);
  });

  testWidgets('each refusal is said in Bengali', (tester) async {
    for (final (check, words) in const [
      (ArchiveCheck(readable: true, samePackage: true, sameSigner: false, newer: true), 'নিজের সই করা অ্যাপ নয়'),
      (ArchiveCheck(readable: true, samePackage: true, sameSigner: true, newer: false), 'পুরনো সংস্করণে ফেরা যায় না'),
    ]) {
      await tester.pumpWidget(MaterialApp(
          home: Scaffold(body: UpdateDownloadButton(key: UniqueKey(), release: release, actions: actions(check)))));
      await tester.tap(find.text('নতুন সংস্করণ নামান'));
      await tester.pumpAndSettle();
      expect(find.textContaining(words), findsOneWidget);
      expect(find.textContaining('বসানো হয়নি'), findsOneWidget);
    }
  });

  test('the release script prints the server lines the phone checks against', () {
    final script = File('tool/release.sh').readAsStringSync();
    expect(script, contains(r'echo "ANDROID_APK_SHA256=$(sha256sum "$APK" | cut -d'));
    expect(script, contains(r'echo "ANDROID_APK_SIZE_BYTES=$(stat -c %s "$APK")"'));
  });
}
