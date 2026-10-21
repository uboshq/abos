import 'dart:convert';
import 'dart:io';

/// ⭐ বাংলা লেখা কোথায় এখনো হাতে লেখা — `lib/**/*.dart`-এর প্রতিটা স্ট্রিং-লিটারেল যার ভেতরে বাংলা অক্ষর (U+0980–U+09FF)।
///
/// <p>ছোট একটা Dart-লেক্সার: মন্তব্য (`//`, `///`, `/* */`) বাদ, `'…'`, `"…"`, `'''…'''`, `r'…'` আর `${…}`-এর ভেতরের
/// লিটারেলও ধরে। ⓘ যে লাইনে লিটারেল শুরু, তাতে `// l10n-ok: <কারণ>` থাকলে গোনা হয় না (যেমন ভাষার নিজের নাম "বাংলা")।
/// ⛔ বাদ: `lib/l10n/` (ARB থেকে বানানো) আর `.g.dart`/`.freezed.dart`।
///
/// <p>`test/l10n/no_hard_coded_text_test.dart` আর তালিকা লেখার ছোট লিপি দুটোই এটাই ডাকে, তাই দুজন এক হিসাব করে।
final RegExp bengali = RegExp('[ঀ-৿]');

/// পথ ('lib/…', '/' দিয়ে) → কয়টা বাংলা লিটারেল; ০টা হলে পথটা নেই
Map<String, int> scanLib([String root = 'lib']) {
  final out = <String, int>{};
  final files = Directory(root)
      .listSync(recursive: true)
      .whereType<File>()
      .where((f) => f.path.endsWith('.dart'))
      .toList();
  for (final f in files) {
    final path = f.path.replaceAll('\\', '/');
    if (skipped(path)) continue;
    final n = countBengaliLiterals(f.readAsStringSync());
    if (n > 0) out[path] = n;
  }
  return Map.fromEntries(out.entries.toList()..sort((a, b) => a.key.compareTo(b.key)));
}

bool skipped(String path) =>
    path.startsWith('lib/l10n/') || path.endsWith('.g.dart') || path.endsWith('.freezed.dart');

/// এক ফাইলের বাংলা লিটারেল কয়টা (`// l10n-ok:` লাইন বাদ)
int countBengaliLiterals(String source) {
  final lines = source.split('\n');
  return literals(source).where((l) => bengali.hasMatch(l.text) && !lines[l.line - 1].contains('// l10n-ok:')).length;
}

class Literal {
  const Literal(this.line, this.text);

  /// যে লাইনে শুরু (১ থেকে)
  final int line;

  /// লিটারেলের নিজের লেখা — `${…}`-এর ভেতরটা বাদ (সেটা আলাদা লিটারেল হলে আলাদা গোনা)
  final String text;
}

/// ফাইলের সব স্ট্রিং-লিটারেল, মন্তব্য বাদে
List<Literal> literals(String s) {
  final out = <Literal>[];
  final lineStarts = <int>[0];
  for (var i = 0; i < s.length; i++) {
    if (s.codeUnitAt(i) == 0x0A) lineStarts.add(i + 1);
  }
  int lineOf(int index) {
    var lo = 0, hi = lineStarts.length - 1;
    while (lo < hi) {
      final mid = (lo + hi + 1) >> 1;
      if (lineStarts[mid] <= index) {
        lo = mid;
      } else {
        hi = mid - 1;
      }
    }
    return lo + 1;
  }

  bool ident(int i) {
    if (i < 0) return false;
    final c = s.codeUnitAt(i);
    return (c >= 0x30 && c <= 0x39) || (c >= 0x41 && c <= 0x5A) || (c >= 0x61 && c <= 0x7A) || c == 0x5F || c == 0x24;
  }

  late int Function(int i, bool raw) string;

  // কোড পড়া; [interp] হলে `${`-এর মিলের `}`-এ ফেরে
  int code(int i, bool interp) {
    var depth = 0;
    while (i < s.length) {
      final c = s[i];
      final next = i + 1 < s.length ? s[i + 1] : '';
      if (c == '/' && next == '/') {
        final end = s.indexOf('\n', i);
        i = end < 0 ? s.length : end;
        continue;
      }
      if (c == '/' && next == '*') {
        var nest = 1;
        i += 2;
        while (i < s.length && nest > 0) {
          if (s.startsWith('/*', i)) {
            nest++;
            i += 2;
          } else if (s.startsWith('*/', i)) {
            nest--;
            i += 2;
          } else {
            i++;
          }
        }
        continue;
      }
      if (c == 'r' && (next == "'" || next == '"') && !ident(i - 1)) {
        i = string(i + 1, true);
        continue;
      }
      if (c == "'" || c == '"') {
        i = string(i, false);
        continue;
      }
      if (c == '{') depth++;
      if (c == '}') {
        if (interp && depth == 0) return i + 1;
        depth--;
      }
      i++;
    }
    return i;
  }

  string = (int i, bool raw) {
    final q = s[i];
    final start = i;
    final triple = s.startsWith(q * 3, i);
    i += triple ? 3 : 1;
    final text = StringBuffer();
    while (i < s.length) {
      if (triple && s.startsWith(q * 3, i)) {
        i += 3;
        break;
      }
      final c = s[i];
      if (!triple && c == q) {
        i++;
        break;
      }
      if (!triple && c == '\n') break;
      if (!raw && c == r'\' && i + 1 < s.length) {
        text.write(s[i + 1]);
        i += 2;
        continue;
      }
      if (!raw && c == r'$' && i + 1 < s.length && s[i + 1] == '{') {
        i = code(i + 2, true);
        continue;
      }
      text.write(c);
      i++;
    }
    out.add(Literal(lineOf(start), text.toString()));
    return i;
  };

  code(0, false);
  return out;
}

/// তালিকার ফাইল
const String listPath = 'test/l10n/still_hard_coded.json';

Map<String, int> readList([String path = listPath]) =>
    (jsonDecode(File(path).readAsStringSync()) as Map<String, dynamic>).map((k, v) => MapEntry(k, v as int));

String encodeList(Map<String, int> list) => '${const JsonEncoder.withIndent('  ').convert(list)}\n';

/// ⭐ তালিকা কেবল ছোট হয় — প্রতিটা ভুল এক লাইনে:
/// বাড়ল (নতুন হাতে-লেখা বাংলা), তালিকায় না থাকা ফাইলে বাংলা, আর কমল কিন্তু তালিকা কমানো হয়নি ("tighten the list")।
List<String> ratchetProblems(Map<String, int> actual, Map<String, int> listed) {
  final problems = <String>[];
  for (final e in actual.entries) {
    final allowed = listed[e.key];
    if (allowed == null) {
      problems.add('${e.key}: ${e.value} hard-coded Bengali string(s) in a file not on the list — use context.l10n (ARB)');
    } else if (e.value > allowed) {
      problems.add('${e.key}: ${e.value} hard-coded Bengali strings, the list allows $allowed — use context.l10n (ARB)');
    }
  }
  for (final e in listed.entries) {
    final real = actual[e.key] ?? 0;
    if (real < e.value) problems.add('${e.key}: now $real, listed ${e.value} — tighten the list ($listPath)');
  }
  return problems;
}
