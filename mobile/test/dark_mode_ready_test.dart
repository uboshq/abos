import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';

/// ⭐ অন্ধকার মোডের পাহারা (মোবাইলের নকশা ৩; মালিকের "হ্যাঁ", ১৯ অক্টোবর ২০২৬)।
///
/// <p>বাঁধা আলোর রং (`AppColors.surface`, `onSurface`, `border` …, `Colors.white/black/grey`) অন্ধকারে ভাঙে: থিমের হালকা
/// লেখা সাদা পটে মিলিয়ে যায়। [remaining] আজকের গোনা — একটা ফাইলের সংখ্যা কেবল কমতে পারে, নতুন ফাইল ঢুকতে পারে না।
/// তালিকা খালি হলে [AppTheme.mode] [ThemeMode.system] হবে। রঙিন পটের উপর সাদা আইকনের মতো যেটা দুই মোডেই ঠিক, সেই
/// লাইনে `// dark-ok` লিখে ছাড়।
const remaining = <String, int>{
  'lib/features/notifications/notifications_screen.dart': 2,
  'lib/features/reports/reports_screen.dart': 1,
};

final _fixed = RegExp(r'AppColors\.(surface|surfaceMuted|onSurface|onSurfaceMuted|border|primarySoft)\b|Colors\.(white|black|grey)\b');

Map<String, int> countFixedColours() {
  final out = <String, int>{};
  for (final f in Directory('lib').listSync(recursive: true).whereType<File>()) {
    final path = f.path.replaceAll(r'\', '/');
    if (!path.endsWith('.dart') || path.startsWith('lib/core/theme/')) continue;
    var n = 0;
    for (final line in f.readAsLinesSync()) {
      if (line.contains('// dark-ok')) continue;
      n += _fixed.allMatches(line).length;
    }
    if (n > 0) out[path] = n;
  }
  return out;
}

void main() {
  test('no screen gains a fixed light colour, and none comes back', () {
    final now = countFixedColours();
    expect(now, isNotEmpty, reason: 'গোনা চলছে কিনা — খালি হলে নিচের টেস্ট অন্ধকার চালু করতে বলবে');
    for (final MapEntry(key: path, value: n) in now.entries) {
      expect(remaining.containsKey(path), isTrue, reason: '⛔ $path: নতুন বাঁধা রং ($n) — colorScheme থেকে নাও');
      expect(n, lessThanOrEqualTo(remaining[path]!), reason: '⛔ $path: বাঁধা রং বেড়েছে ($n > ${remaining[path]})');
    }
  });

  test('dark mode stays off until every fixed colour is gone', () {
    if (countFixedColours().isEmpty) {
      expect(AppTheme.mode, ThemeMode.system, reason: 'সব পর্দা তৈরি — এখন অন্ধকার মোড চালু করো');
    } else {
      expect(AppTheme.mode, ThemeMode.light, reason: '⛔ বাঁধা রং থাকতে অন্ধকার চালু করলে লেখা মিলিয়ে যায়');
    }
  });

  test('the dark theme reads: body text 4.5:1 or more on the page, the card and the field', () {
    final cs = AppTheme.dark().colorScheme;
    for (final bg in [cs.surface, cs.surfaceContainerLow, cs.surfaceContainerHighest, cs.surfaceContainer]) {
      expect(AppColors.contrast(cs.onSurface, bg), greaterThanOrEqualTo(4.5));
      expect(AppColors.contrast(cs.onSurfaceVariant, bg), greaterThanOrEqualTo(4.5));
    }
    expect(AppColors.contrast(cs.onPrimary, cs.primary), greaterThanOrEqualTo(4.5));
    expect(AppColors.contrast(cs.error, cs.surface), greaterThanOrEqualTo(4.5));
    expect(AppTheme.dark().scaffoldBackgroundColor.computeLuminance(), lessThan(0.1), reason: 'পট সত্যিই অন্ধকার');
  });
}
