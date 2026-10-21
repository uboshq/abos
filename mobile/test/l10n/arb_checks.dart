import 'dart:convert';
import 'dart:io';

/// ⭐ ARB-এর পাহারা — `arb_complete_test.dart` আর `glossary_test.dart` এগুলো ডাকে; প্রতিটা নিয়ম একটা ফাংশন, যাতে পরীক্ষা
/// আসল ফাইলেও চালায় আর বানানো ভুল ফাইলেও (নিয়মটা সত্যিই ধরে কি না)।
final RegExp bengaliChar = RegExp('[ঀ-৿]');
final RegExp bengaliDigit = RegExp('[০-৯]');

/// চাবি → লেখা; `@@locale` আর `@চাবি` (বর্ণনা) বাদ
Map<String, String> arbMessages(String path) {
  final raw = jsonDecode(File(path).readAsStringSync()) as Map<String, dynamic>;
  return {
    for (final e in raw.entries)
      if (!e.key.startsWith('@')) e.key: e.value as String,
  };
}

/// ARB-এর `@@locale`
String? arbLocale(String path) => (jsonDecode(File(path).readAsStringSync()) as Map<String, dynamic>)['@@locale'] as String?;

// ── ICU ──────────────────────────────────────────────────────────────

/// ⭐ ICU বার্তা পড়া — `{name}`, `{n, plural, =0{…} one{…} other{…}}`, `{s, select, a{…} other{…}}`; ভুল হলে [FormatException]।
/// ফেরত: বার্তায় যত নামের জায়গা (ভেতরের ঘরসহ)। ⓘ intl প্যাকেজে ICU পড়ার কিছু নেই (সেটা intl_translation-এ), তাই নিজের।
Set<String> icuPlaceholders(String message) {
  final names = <String>{};
  var i = 0;

  Never fail(String why) => throw FormatException('$why at $i', message);

  String word() {
    final m = RegExp(r'\s*([A-Za-z_][A-Za-z0-9_]*|=\d+)\s*').matchAsPrefix(message, i);
    if (m == null) fail('expected a name');
    i = m.end;
    return m.group(1)!;
  }

  late void Function(bool nested) body;

  void argument() {
    // i একটা '{'-এর ঠিক পরে
    final name = word();
    names.add(name);
    if (i < message.length && message[i] == '}') {
      i++;
      return;
    }
    if (i >= message.length || message[i] != ',') fail('expected , or }');
    i++;
    final kind = word();
    if (!const {'plural', 'select', 'selectordinal'}.contains(kind)) fail('unknown kind $kind');
    if (i >= message.length || message[i] != ',') fail('expected , after $kind');
    i++;
    final cases = <String>{};
    while (true) {
      i = RegExp(r'\s*').matchAsPrefix(message, i)!.end;
      if (i < message.length && message[i] == '}') {
        i++;
        break;
      }
      final key = word();
      if (kind == 'select' && key.startsWith('=')) fail('select cannot have $key');
      if (!cases.add(key)) fail('case $key twice');
      if (i >= message.length || message[i] != '{') fail('expected { after $key');
      i++;
      body(true);
    }
    if (!cases.contains('other')) fail('$kind without other');
  }

  body = (bool nested) {
    while (i < message.length) {
      final c = message[i];
      if (c == '{') {
        i++;
        argument();
      } else if (c == '}') {
        if (!nested) fail('unbalanced }');
        i++;
        return;
      } else {
        i++;
      }
    }
    if (nested) fail('unclosed {');
  };

  body(false);
  return names;
}

/// ⭐ দুই ভাষার ARB মেলে কি না — প্রতিটা ভুল এক লাইনে
List<String> arbProblems(Map<String, String> bn, Map<String, String> en) {
  final problems = <String>[];
  for (final key in bn.keys) {
    if (!en.containsKey(key)) problems.add('$key: missing in app_en.arb');
  }
  for (final key in en.keys) {
    if (!bn.containsKey(key)) problems.add('$key: missing in app_bn.arb');
  }
  for (final e in en.entries) {
    if (bengaliChar.hasMatch(e.value)) problems.add('${e.key}: Bengali letters in the English text');
  }
  for (final e in bn.entries) {
    if (e.value.trim().isEmpty) problems.add('${e.key}: empty Bengali text');
  }
  for (final (lang, map) in [('bn', bn), ('en', en)]) {
    for (final e in map.entries) {
      if (bengaliDigit.hasMatch(e.value)) problems.add('${e.key} ($lang): Bengali digits — numbers are written in English digits');
    }
  }
  for (final key in bn.keys.where(en.containsKey)) {
    Set<String>? a, b;
    try {
      a = icuPlaceholders(bn[key]!);
    } on FormatException catch (e) {
      problems.add('$key (bn): ICU does not parse — ${e.message}');
    }
    try {
      b = icuPlaceholders(en[key]!);
    } on FormatException catch (e) {
      problems.add('$key (en): ICU does not parse — ${e.message}');
    }
    if (a != null && b != null && !(a.length == b.length && a.containsAll(b))) {
      problems.add('$key: placeholders differ — bn $a, en $b');
    }
  }
  return problems;
}

// ── শব্দকোষ ─────────────────────────────────────────────────────────

/// RFC 4180 CSV — উদ্ধৃতির ভেতরে কমা, নতুন লাইন আর "" চলে; শুরুর BOM বাদ
List<List<String>> parseCsv(String text) {
  if (text.startsWith('﻿')) text = text.substring(1);
  final rows = <List<String>>[];
  var row = <String>[];
  final cell = StringBuffer();
  var quoted = false;
  for (var i = 0; i < text.length; i++) {
    final c = text[i];
    if (quoted) {
      if (c == '"') {
        if (i + 1 < text.length && text[i + 1] == '"') {
          cell.write('"');
          i++;
        } else {
          quoted = false;
        }
      } else {
        cell.write(c);
      }
    } else if (c == '"') {
      quoted = true;
    } else if (c == ',') {
      row.add(cell.toString());
      cell.clear();
    } else if (c == '\n' || c == '\r') {
      if (c == '\r' && i + 1 < text.length && text[i + 1] == '\n') i++;
      row.add(cell.toString());
      cell.clear();
      if (row.any((v) => v.isNotEmpty)) rows.add(row);
      row = <String>[];
    } else {
      cell.write(c);
    }
  }
  if (cell.isNotEmpty || row.isNotEmpty) {
    row.add(cell.toString());
    if (row.any((v) => v.isNotEmpty)) rows.add(row);
  }
  return rows;
}

/// ⭐ শব্দকোষ: বাংলা শব্দ → ইংরেজিতে যে কোনো একটা থাকতে হবে (ছোট-বড় হাতের অক্ষর মানে না)।
///
/// <p>নিয়ম (ডেটা থেকে, সারি জুড়লেই খাটে): `proposed_app_en` খালি হলে সারি বাদ। "ক / খ" দুই দিকেই সমান সংখ্যায় হলে ঘর
/// ঘরে মেলে ("প্রত্যাখ্যান / প্রত্যাখ্যাত" → "Reject / Rejected"); নইলে প্রতিটা বাংলা শব্দ যেকোনো ইংরেজিতে চলে। বন্ধনী বা
/// `{`-ওয়ালা বাংলা শব্দ ("ফেরান (ভার)") কেবল এক প্রসঙ্গের — বাদ। ইংরেজির "(s)" ঐচ্ছিক ("Notification(s)")।
Map<String, Set<String>> glossaryFrom(List<List<String>> rows) {
  final header = rows.first;
  final bnAt = header.indexOf('term_bn_app');
  final enAt = header.indexOf('proposed_app_en');
  if (bnAt < 0 || enAt < 0) throw const FormatException('glossary.csv needs term_bn_app and proposed_app_en');
  final out = <String, Set<String>>{};
  for (final r in rows.skip(1)) {
    if (r.length <= enAt || r.length <= bnAt) continue;
    final en = r[enAt].trim();
    if (en.isEmpty) continue;
    final bnTerms = r[bnAt].split(' / ').map((t) => t.trim()).toList();
    final enAlts = en.split(' / ').map((t) => t.replaceAll('(s)', '').trim().toLowerCase()).where((t) => t.isNotEmpty).toList();
    final paired = bnTerms.length > 1 && bnTerms.length == enAlts.length;
    for (var k = 0; k < bnTerms.length; k++) {
      final term = bnTerms[k];
      if (term.isEmpty || term.contains('(') || term.contains('{')) continue;
      out.putIfAbsent(term, () => <String>{}).addAll(paired ? [enAlts[k]] : enAlts);
    }
  }
  return out;
}

bool _wordChar(String c) => bengaliChar.hasMatch(c) || RegExp('[A-Za-z0-9]').hasMatch(c);

/// [text]-এ পুরো শব্দ হিসেবে যে শব্দকোষের শব্দগুলো আছে — লম্বাটা আগে, একই জায়গা দুবার নয় ("টাকা স্থানান্তর"-এর ভেতরের
/// "টাকা" আলাদা নয়)
List<String> glossaryTermsIn(String text, Iterable<String> terms) {
  final sorted = terms.toList()..sort((a, b) => b.length.compareTo(a.length));
  final taken = List<bool>.filled(text.length, false);
  final found = <String>[];
  for (final term in sorted) {
    var from = 0;
    while (true) {
      final at = text.indexOf(term, from);
      if (at < 0) break;
      from = at + 1;
      final end = at + term.length;
      final before = at == 0 ? '' : text[at - 1];
      final after = end >= text.length ? '' : text[end];
      if ((before.isNotEmpty && _wordChar(before)) || (after.isNotEmpty && _wordChar(after))) continue;
      if (taken.sublist(at, end).any((t) => t)) continue;
      for (var k = at; k < end; k++) {
        taken[k] = true;
      }
      found.add(term);
    }
  }
  return found;
}

/// ⭐ শব্দকোষের নিয়ম ভাঙা চাবিগুলো — বাংলায় শব্দকোষের শব্দ থাকলে ইংরেজিতে তার ইংরেজি থাকতেই হবে
List<String> glossaryProblems(Map<String, String> bn, Map<String, String> en, Map<String, Set<String>> glossary) {
  final problems = <String>[];
  for (final e in bn.entries) {
    final english = (en[e.key] ?? '').toLowerCase();
    for (final term in glossaryTermsIn(e.value, glossary.keys)) {
      final wanted = glossary[term]!;
      if (!wanted.any(english.contains)) {
        problems.add('${e.key}: "$term" must read as ${wanted.map((w) => '"$w"').join(' or ')} in English — got "${en[e.key]}"');
      }
    }
  }
  return problems;
}
