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
}
