import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/widgets/day_part.dart';

/// ⭐ তারিখ আর সময় সব পর্দায় এক নিয়মে — "20/10/2026 সকাল 10:00": অঙ্ক ইংরেজিতে, শব্দ বাংলায় (fe, ২০ অক্টোবর ২০২৬:
/// "ইংরেজি AM চলবে না")।
void main() {
  test('a stamp reads date, part of day and a twelve-hour clock, never AM or PM', () {
    expect(banglaStamp(DateTime(2026, 10, 20, 10)), '20/10/2026 সকাল 10:00');
    expect(banglaStamp(DateTime(2026, 10, 20, 13, 5)), '20/10/2026 দুপুর 1:05');
    expect(banglaStamp(DateTime(2026, 10, 20, 0, 30)), '20/10/2026 রাত 12:30');
    expect(banglaStamp(DateTime(2026, 10, 20, 19, 45)), '20/10/2026 সন্ধ্যা 7:45');
    expect(banglaStamp(DateTime(2026, 10, 20, 10)), isNot(matches(RegExp('AM|PM'))));
  });

  /// ⓘ ইংরেজি ভাষায় AM/PM-ই ঠিক (0.4.43) — সেটা কেবল day_part.dart-এ ([clockText], [stampText]); বাকি সবাই ওগুলো ডাকে
  test('no screen writes a time with the English AM/PM pattern', () {
    final offenders = <String>[];
    for (final f in Directory('lib')
        .listSync(recursive: true)
        .whereType<File>()
        .where((f) => f.path.endsWith('.dart') && !f.path.replaceAll(r'\', '/').endsWith('lib/core/widgets/day_part.dart'))) {
      final lines = f.readAsLinesSync();
      for (var i = 0; i < lines.length; i++) {
        final line = lines[i];
        if (line.trimLeft().startsWith('//')) continue;
        if (RegExp(r"DateFormat\([^)]*\ba\b[^)]*\)").hasMatch(line) || line.contains('.jm(') || line.contains('add_jm')) {
          offenders.add('${f.path}:${i + 1}');
        }
      }
    }
    expect(offenders, isEmpty, reason: '⛔ ইংরেজি AM/PM — stampText বা clockText নাও (ভাষা মেনে)');
  });
}
