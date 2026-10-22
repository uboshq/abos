import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ Android-এর লেখার ফাইলে (res/values*/*.xml) ' আর " লিখতে হয় \' আর \" দিয়ে — নইলে aapt2 release build থামায়
/// ("unescaped apostrophe in string"), আর " নীরবে মুছে যায়। 1fdc1c23f-এর release build widget_words.xml-এর চারটা
/// "Today's …"-তে ভেঙেছিল (fe, ২২ অক্টোবর ২০২৬); Kotlin compile পাস করেছিল, resource compile দেখা হয়নি — তাই এই পাহারা
/// flutter test-এই, build-এর আগে। ⓘ পুরো মান "…"-এ মোড়া হলে ভেতরের ' চলে — Android-এর নিজের নিয়ম।
void main() {
  final res = Directory('android/app/src/main/res');

  /// একটা মানের ভুল — এখনই ফাঁকা নয় এমন তালিকা মানে লাল
  List<String> faults(String value) {
    final out = <String>[];
    final quoted = value.length >= 2 && value.startsWith('"') && value.endsWith('"');
    final body = quoted ? value.substring(1, value.length - 1) : value;
    for (var i = 0; i < body.length; i++) {
      final c = body[i];
      if (c == r'\') {
        i++; // পরের অক্ষর escape করা
        continue;
      }
      if (c == "'" && !quoted) out.add("unescaped ' at $i");
      if (c == '"') out.add('unescaped " at $i');
    }
    return out;
  }

  test('the guard itself: what aapt2 refuses is caught, what it accepts passes', () {
    expect(faults("Today's sales"), isNotEmpty);
    expect(faults(r"Today\'s sales"), isEmpty);
    expect(faults('"Today\'s sales"'), isEmpty, reason: 'পুরো মান উদ্ধৃতিতে — Android মানে');
    expect(faults('say "hi"'), isNotEmpty);
    expect(faults(r'say \"hi\"'), isEmpty);
    expect(faults('আজকের বিক্রি'), isEmpty);
  });

  test("every <string> and <item> under res/values*/ escapes ' and \" (aapt2 would stop the release build)", () {
    expect(res.existsSync(), isTrue, reason: 'res ফোল্ডার নেই — পাহারা ফাঁপা হতো');
    final files = res
        .listSync()
        .whereType<Directory>()
        .where((d) => d.uri.pathSegments.where((s) => s.isNotEmpty).last.startsWith('values'))
        .expand((d) => d.listSync().whereType<File>().where((f) => f.path.endsWith('.xml')))
        .toList();
    expect(files, isNotEmpty);
    final entry = RegExp(r'<(string|item)\b[^>]*>(.*?)</\1>', dotAll: true);
    final bad = <String>[];
    var seen = 0;
    for (final f in files) {
      final text = f.readAsStringSync();
      for (final m in entry.allMatches(text)) {
        final value = m.group(2)!;
        if (value.contains('<![CDATA[')) continue;
        seen++;
        for (final fault in faults(value)) {
          final line = '\n'.allMatches(text.substring(0, m.start)).length + 1;
          bad.add('${f.path}:$line "$value" — $fault');
        }
      }
    }
    expect(seen, greaterThan(50), reason: 'লেখা প্রায় পাওয়াই গেল না — পাহারা কিছু দেখছে না');
    expect(bad, isEmpty, reason: "⛔ Android-এর লেখায় escape ছাড়া ' বা \" — release build ভাঙবে:\n${bad.join('\n')}");
  });
}
