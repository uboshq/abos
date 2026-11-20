import 'dart:io';
import 'dart:math';

import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/web_inside/web_inside.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ ফোনের চারটা খোলা দরজা বন্ধ (পূর্ণ নিরীক্ষা §৯, ১৮ অক্টোবর ২০২৬):
///   ⚠️১১ ব্যাকআপ — তালাবদ্ধ বাক্স আর তার চাবি আলাদা হয়ে নতুন ফোনে যেত, বাক্স খুলত না
///   ⚠️১৪ চাবি ছাড়া release — debug চাবিতে সই হয়ে বিলি হত, কোনো ফোনে আপডেট বসত না
///   ⓘ১৬ বদলের চাবি — সময় আর ক্রম থেকে, এলোমেলো নয়; ঘড়ি পিছোলে দুইটা বদল এক চাবি পেত
///   ⓘ১৭ ওয়েব-জানালা — একই হোস্টে http ঠিকানাও ভিতরে খুলত
void main() {
  test('nothing of the app goes to a backup or to a new phone', () {
    final manifest = File('android/app/src/main/AndroidManifest.xml').readAsStringSync();
    expect(manifest, contains('android:allowBackup="false"'));
    expect(manifest, contains('android:fullBackupContent="false"'));
    expect(manifest, contains('android:dataExtractionRules="@xml/data_extraction_rules"'));

    final rules = File('android/app/src/main/res/xml/data_extraction_rules.xml').readAsStringSync();
    for (final part in ['cloud-backup', 'device-transfer']) {
      final section = RegExp('<$part>(.*?)</$part>', dotAll: true).firstMatch(rules)?.group(1) ?? '';
      for (final domain in ['root', 'file', 'database', 'sharedpref', 'external']) {
        expect(section, contains('<exclude domain="$domain" />'), reason: '⛔ $part-এ $domain বাদ নেই');
      }
    }
  });

  test('a release package without the release key stops instead of signing with the debug key', () {
    final gradle = File('android/app/build.gradle.kts').readAsStringSync();
    // ⓘ ২০ নভেম্বর ২০২৬ থেকে দুই ফ্লেভার — side-এর পাহারা key.properties-এ, play-এর key-play.properties-এ
    final guard = RegExp(r'if \(!hasReleaseKey\) \{\s*tasks\.matching \{[^}]*packageSideRelease[^}]*bundleSideRelease[^}]*\}\.configureEach \{\s*doFirst \{\s*throw GradleException',
        dotAll: true);
    expect(guard.hasMatch(gradle), isTrue, reason: '⛔ চাবি ছাড়া release প্যাকেজ থামে না');
    final playGuard = RegExp(r'if \(!hasPlayKey\) \{\s*tasks\.matching \{[^}]*packagePlayRelease[^}]*bundlePlayRelease[^}]*\}\.configureEach \{\s*doFirst \{\s*throw GradleException',
        dotAll: true);
    expect(playGuard.hasMatch(gradle), isTrue, reason: '⛔ আপলোড-চাবি ছাড়া Play-র .aab থামে না');
  });

  test('a change id is a random version-4 uuid, never the clock', () {
    final ids = {for (var i = 0; i < 2000; i++) uuidV4(Random.secure())};
    expect(ids, hasLength(2000), reason: '⛔ দুইটা বদল একই চাবি পেল');
    for (final id in ids.take(50)) {
      expect(RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$').hasMatch(id), isTrue, reason: id);
    }
    final source = File('lib/core/sync_engine/sync_engine.dart').readAsStringSync();
    expect(source, isNot(contains('microsecondsSinceEpoch}-')), reason: '⛔ চাবি এখনো ঘড়ি থেকে');
    expect(source, contains('Random.secure()'));
  });

  test('the web window opens only https on a live build', () {
    const host = 'erp.example';
    expect(WebPaths.staysInside(Uri.parse('https://$host/accounts'), host: host, scheme: 'https'), isTrue);
    expect(WebPaths.staysInside(Uri.parse('http://$host/accounts'), host: host, scheme: 'https'), isFalse,
        reason: '⛔ লাইভে http ঠিকানা ভিতরে খুলল');
    // ⓘ ডেভেলপারের যন্ত্রে অ্যাপ নিজেই http সার্ভারে বাঁধা — তখনই কেবল
    expect(WebPaths.staysInside(Uri.parse('http://$host/accounts'), host: host, scheme: 'http'), isTrue);
  });
}
