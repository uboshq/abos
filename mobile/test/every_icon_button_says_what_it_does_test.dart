import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// ⭐ প্রতিটা আইকন-বোতাম বাংলায় বলে সে কী করে (পূর্ণ নিরীক্ষা §৯ ⓘ২০, ১৮ অক্টোবর ২০২৬; WCAG 2.1 AA)।
///
/// <p>⛔ আগে ২৫টার ৪টায় `tooltip` ছিল না — চেপে ধরলে কিছু বলত না, আর পর্দা-পাঠক (TalkBack) কেবল "বোতাম" বলত। ⓘ পাহারা
/// সব `lib/`-এ: নতুন বোতাম tooltip ছাড়া এলে লাল।
void main() {
  test('every IconButton in the app carries a tooltip', () {
    final missing = <String>[];
    for (final file in Directory('lib').listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.dart'))) {
      final s = file.readAsStringSync();
      for (final m in RegExp(r'IconButton(\.filled|\.outlined)?\(').allMatches(s)) {
        var i = m.end, depth = 1;
        while (depth > 0 && i < s.length) {
          final c = s[i];
          if (c == '(') depth++;
          if (c == ')') depth--;
          i++;
        }
        if (!s.substring(m.start, i).contains('tooltip:')) {
          missing.add('${file.path}:${'\n'.allMatches(s.substring(0, m.start)).length + 1}');
        }
      }
    }
    expect(missing, isEmpty, reason: '⛔ tooltip ছাড়া আইকন-বোতাম: ${missing.join(', ')}');
  });
}
