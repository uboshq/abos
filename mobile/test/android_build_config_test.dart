import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ Android বিল্ডের যে সংখ্যাগুলো ফোনের আচরণ ঠিক করে, সেগুলো `android/app/build.gradle.kts`-এ স্পষ্ট লেখা থাকতে হবে —
/// Flutter-এর ডিফল্টে ছেড়ে দিলে Flutter আপগ্রেডের দিন নীরবে বদলায় (অ্যাপ রিলিজ নিরীক্ষা, ec, ২০ অক্টোবর ২০২৬)।
String _gradle() => File('android/app/build.gradle.kts').readAsStringSync();

/// মন্তব্য বাদ দিয়ে — মন্তব্যে লেখা "compileSdk" যেন মিলে না যায়।
String _code() => _gradle()
    // ⓘ কেবল লাইনের শুরুর /* … */ — "lib/x86_64/**"-এর ভিতরের /* মন্তব্য নয়
    .replaceAll(RegExp(r'^\s*/\*.*?\*/', multiLine: true, dotAll: true), '')
    .split('\n')
    .map((l) => l.replaceFirst(RegExp(r'^\s*//.*$'), ''))
    .join('\n');

void main() {
  test('compileSdk and targetSdk are pinned to 36, not left to Flutter', () {
    final code = _code();
    expect(RegExp(r'^\s*compileSdk\s*=\s*36\s*$', multiLine: true).hasMatch(code), isTrue,
        reason: 'compileSdk স্পষ্ট ৩৬ নয়');
    expect(RegExp(r'^\s*targetSdk\s*=\s*36\s*$', multiLine: true).hasMatch(code), isTrue,
        reason: 'targetSdk স্পষ্ট ৩৬ নয় — বদলানো মানে ফোনে আচরণ বদল, রিলিজ-চেকলিস্ট দেখুন');
    expect(code.contains('flutter.targetSdkVersion'), isFalse);
    expect(code.contains('flutter.compileSdkVersion'), isFalse);
  });

  test('one universal APK for ARM phones only: x86_64 left out, no per-ABI split', () {
    // ⭐ x86_64-এর অংশ ছিল ~৩০ MB (৮৬.৫ MB-র APK) — কোনো দোকানের ফোন x86_64 নয়
    final code = _code();
    expect(
        RegExp(r'packaging\s*\{\s*jniLibs\s*\{\s*excludes\s*\+=\s*setOf\("lib/x86_64/\*\*"\)\s*\}\s*\}')
            .hasMatch(code),
        isTrue,
        reason: 'x86_64-এর নেটিভ লাইব্রেরি আবার APK-তে যাচ্ছে');
    // ⛔ split হলে versionCode-এ ১০০০×ABI যোগ হয়, আর পরের universal আর বসে না — একটাই APK, একটাই SHA-256
    expect(RegExp(r'splits\s*\{').hasMatch(code), isFalse);
    expect(RegExp(r'abiFilters').hasMatch(code), isFalse,
        reason: 'abiFilters Flutter নিজে মুছে দেয়; বাদ দেওয়া packaging দিয়ে');
    // ⛔ R8 আর অব্যবহৃত রিসোর্স ছাঁটা Flutter release-এ নিজে চালু করে — কেউ বন্ধ করলে APK আবার বাড়ে
    expect(RegExp(r'isMinifyEnabled\s*=\s*false|isShrinkResources\s*=\s*false').hasMatch(code), isFalse);
  });

  // ⭐ দুই বিলি-পথ (মালিকের সিদ্ধান্ত, ২০ নভেম্বর ২০২৬): side = আজকের APK, play = Google Play-র univer.abos
  group('two flavors: side (sideload, default) and play (Google Play)', () {
    String flavor(String name) {
      final m = RegExp('create\\("$name"\\)\\s*\\{(.*?)\\n\\s*\\}', dotAll: true).firstMatch(_code());
      expect(m, isNotNull, reason: '⛔ "$name" ফ্লেভার নেই');
      return m!.group(1)!;
    }

    test('exactly two flavors on one dimension', () {
      final code = _code();
      expect(code, contains('flavorDimensions += "channel"'));
      expect(RegExp(r'productFlavors\s*\{').hasMatch(code), isTrue);
      final names = RegExp(r'create\("(\w+)"\)\s*\{\s*dimension').allMatches(code).map((m) => m.group(1)).toSet();
      expect(names, {'side', 'play'});
    });

    test('side keeps today\'s app id, today\'s key, and is the default', () {
      final side = flavor('side');
      expect(side, contains('applicationId = "com.abos.abos_mobile"'),
          reason: '⛔ side-এর applicationId বদলালে মাঠের কোনো ফোন আর আপডেট নেবে না');
      expect(side, contains('isDefault = true'));
      expect(side, contains('hasReleaseKey) "release"'));
    });

    test('play is univer.abos, signed with the upload key from key-play.properties', () {
      final play = flavor('play');
      expect(play, contains('applicationId = "univer.abos"'));
      expect(play, contains('hasPlayKey) "playUpload"'));
      expect(play, isNot(contains('isDefault')));
      final code = _code();
      expect(code, contains('rootProject.file("key-play.properties")'));
      // ⛔ namespace একটাই — Kotlin প্যাকেজ আর MethodChannel-এর নাম applicationId-এর সাথে বদলায় না
      expect(RegExp(r'^\s*namespace\s*=\s*"com\.abos\.abos_mobile"\s*$', multiLine: true).hasMatch(code), isTrue);
      // ⓘ versionCode একই নিয়মে, ফ্লেভারে আলাদা কিছু নয়
      expect(code, contains('versionCode = 20000 + flutter.versionCode'));
      expect(play, isNot(contains('versionCode')));
      expect(flavor('side'), isNot(contains('versionCode')));
    });

    test('the release build type no longer pins a signing config over the flavors', () {
      final release = RegExp(r'buildTypes\s*\{\s*release\s*\{(.*?)\n    \}', dotAll: true).firstMatch(_code())!.group(1)!;
      expect(release, isNot(contains('signingConfig')),
          reason: '⛔ buildType-এর signingConfig ফ্লেভারের চাবি ঢেকে দেয়');
    });

    test('the signing files stay out of git', () {
      final ignore = File('android/.gitignore').readAsLinesSync().map((l) => l.trim());
      expect(ignore, containsAll(<String>['key.properties', 'key-play.properties', '**/*.jks']));
    });

    Set<String> permsOf(String manifest, {required bool removed}) => RegExp(r'<uses-permission\b([^>]*)>')
        .allMatches(manifest.replaceAll(RegExp(r'<!--.*?-->', dotAll: true), ''))
        .where((m) => m.group(1)!.contains('tools:node="remove"') == removed)
        .map((m) => RegExp(r'android:name="([^"]+)"').firstMatch(m.group(1)!)!.group(1)!)
        .toSet();

    test('the play manifest removes self-update and the SMS gateway, nothing else', () {
      final play = File('android/app/src/play/AndroidManifest.xml').readAsStringSync();
      final body = play.replaceAll(RegExp(r'<!--.*?-->', dotAll: true), '');
      expect(permsOf(play, removed: true), {
        'android.permission.REQUEST_INSTALL_PACKAGES',
        'android.permission.SEND_SMS',
        'android.permission.FOREGROUND_SERVICE_SPECIAL_USE',
      });
      expect(permsOf(play, removed: false), isEmpty, reason: 'play কোনো অনুমতি যোগ করে না');
      // ⓘ WorkManager নিজেও এ দুটো চায় — সরালে পটভূমির সিঙ্কও যেত
      expect(body, isNot(contains('RECEIVE_BOOT_COMPLETED')));
      expect(body, isNot(contains('"android.permission.FOREGROUND_SERVICE"')));
      // ⓘ কোপাইলটের মাইক দুটোতেই
      expect(body, isNot(contains('RECORD_AUDIO')));
      for (final c in [
        '<service android:name=".sms.SmsGatewayService" tools:node="remove" />',
        '<receiver android:name=".sms.SmsResultReceiver" tools:node="remove" />',
        '<receiver android:name=".sms.SmsBootReceiver" tools:node="remove" />',
      ]) {
        expect(body, contains(c));
      }
    });

    test('the main manifest (side) still has self-update and the SMS gateway', () {
      final main = File('android/app/src/main/AndroidManifest.xml').readAsStringSync();
      expect(permsOf(main, removed: false), containsAll(<String>[
        'android.permission.REQUEST_INSTALL_PACKAGES',
        'android.permission.SEND_SMS',
        'android.permission.FOREGROUND_SERVICE_SPECIAL_USE',
        'android.permission.RECORD_AUDIO',
      ]));
      for (final c in ['android:name=".sms.SmsGatewayService"', 'android:name=".sms.SmsResultReceiver"', 'android:name=".sms.SmsBootReceiver"']) {
        expect(main, contains(c));
      }
      expect(Directory('android/app/src/side').existsSync(), isFalse,
          reason: 'side-এর নিজের ম্যানিফেস্ট নেই — সে main-ই, আজকের মতো');
    });

    test('the build scripts name their flavor and the side APK path', () {
      final side = File('tool/release.sh').readAsStringSync();
      expect(side, contains('--flavor side'));
      expect(side, contains('APK=build/app/outputs/flutter-apk/app-side-release.apk'));
      final play = File('tool/release-play.sh').readAsStringSync();
      final build = play.split('\n').firstWhere((l) => l.trimLeft().startsWith('flutter build appbundle'));
      for (final part in ['--release', '--flavor play', '--dart-define=ABOS_CHANNEL=play', '--obfuscate', r'--split-debug-info="symbols/$VERSION-play"']) {
        expect(build, contains(part));
      }
      expect(play.indexOf('flutter test'), lessThan(play.indexOf('flutter build appbundle')));
      expect(play, contains('CN=Android Debug'), reason: '⛔ debug চাবিতে সই হলে থামার পাহারা নেই');
    });
  });
}
