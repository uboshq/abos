import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিকের শব্দের নিয়ম: কাগজ খাতায় ওঠে "পোস্ট" দিয়ে — "পাকা" কখনো নয় (fe, ২১ অক্টোবর ২০২৬; ২৫ অক্টোবর ভাউচারের পাতার
/// বোতাম "পাকা করুন" মালিকের ছবিতে ধরা পড়ল)। প্রতিটা বাংলা ARB অংশের প্রতিটা দেখানো লেখা পড়ে দেখা — `@` বর্ণনা বাদ, ওগুলো
/// নিয়মটাই লেখে।
void main() {
  test('no Bengali text shown in the app says "পাকা"; a paper is posted ("পোস্ট")', () {
    final parts = Directory('lib/l10n/parts').listSync().whereType<File>().where((f) => f.path.endsWith('_bn.arb')).toList();
    expect(parts, isNotEmpty, reason: 'বাংলা ARB অংশ পাওয়া গেল না — দাবি ফাঁপা হত');
    final offenders = <String>[];
    var seen = 0;
    for (final file in parts) {
      final map = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
      for (final e in map.entries) {
        if (e.key.startsWith('@') || e.value is! String) continue;
        seen++;
        if ((e.value as String).contains('পাকা')) offenders.add('${file.uri.pathSegments.last}: ${e.key} = ${e.value}');
      }
    }
    expect(seen, greaterThan(1000), reason: 'লেখা প্রায় পাওয়াই গেল না — দাবি কিছু দেখছে না');
    expect(offenders, isEmpty, reason: '⛔ "পাকা" নয়, "পোস্ট":\n${offenders.join('\n')}');
  });
}
