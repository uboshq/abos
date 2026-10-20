import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ রিলিজের কোড ঝাপসা — OWASP MASVS-RESILIENCE-3 (অ্যাপের নিরাপত্তা, ১৯ অক্টোবর ২০২৬; `tool/release.sh`)।
///
/// <p>⛔ আগে রিলিজ APK খুললে শ্রেণি আর মেথডের নাম পড়া যেত — দাম, সীমা, কোন দরজায় কী যায়, সব বোঝা যেত। এখন রিলিজের একমাত্র
/// পথ (`tool/release.sh`) ঝাপসা করে বানায়, আর প্রতি সংস্করণের চিহ্ন রাখে — নইলে ক্র্যাশের stack পড়াই যেত না।
void main() {
  final script = File('tool/release.sh').readAsStringSync();

  test('the release build is obfuscated and keeps its symbols per version', () {
    final build = script.split('\n').firstWhere((l) => l.trimLeft().startsWith('flutter build apk'),
        orElse: () => fail('⛔ release.sh-এ APK বানানোর লাইনই নেই'));

    expect(build, contains('--release'));
    expect(build, contains('--obfuscate'), reason: '⛔ রিলিজ ঝাপসা নয় — APK খুলে নাম পড়া যায়');
    expect(build, contains('--split-debug-info='), reason: '⛔ চিহ্ন আলাদা রাখা নেই — ঝাপসা stack পড়া যাবে না');
    expect(build, contains(r'symbols/$VERSION'), reason: '⛔ চিহ্ন সংস্করণ ধরে রাখা নয় — পুরনো সংস্করণের ক্র্যাশ পড়া যাবে না');
  });

  test('the tests still run before the build, and the symbols stay out of the repository', () {
    expect(script.indexOf('flutter test'), lessThan(script.indexOf('flutter build apk')),
        reason: '⛔ টেস্টের আগেই বিল্ড — 0.4.3+8-এর ভুল আবার হতে পারে');
    expect(File('.gitignore').readAsLinesSync().map((l) => l.trim()), contains('symbols/'),
        reason: '⛔ চিহ্নের ফাইল রিপোতে উঠতে পারে');
  });
}
