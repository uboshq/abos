import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ Android বিল্ডের যে সংখ্যাগুলো ফোনের আচরণ ঠিক করে, সেগুলো `android/app/build.gradle.kts`-এ স্পষ্ট লেখা থাকতে হবে —
/// Flutter-এর ডিফল্টে ছেড়ে দিলে Flutter আপগ্রেডের দিন নীরবে বদলায় (অ্যাপ রিলিজ নিরীক্ষা, ec, ২০ অক্টোবর ২০২৬)।
String _gradle() => File('android/app/build.gradle.kts').readAsStringSync();

/// মন্তব্য বাদ দিয়ে — মন্তব্যে লেখা "compileSdk" যেন মিলে না যায়।
String _code() => _gradle()
    .replaceAll(RegExp(r'/\*.*?\*/', dotAll: true), '')
    .split('\n')
    .map((l) => l.replaceFirst(RegExp(r'//.*$'), ''))
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
}
