import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import 'arb_checks.dart';

/// ⭐ এক বাংলা শব্দ, এক ইংরেজি — শব্দকোষ মানা (0.4.43; a6-এর তালিকা, fe-র সিদ্ধান্ত ২১ অক্টোবর ২০২৬)।
///
/// <p>`test/l10n/glossary.csv`-এর যে সারিতে `proposed_app_en` আছে, তার বাংলা শব্দ কোনো চাবির বাংলায় পুরো শব্দ হিসেবে থাকলে সেই
/// চাবির ইংরেজিতে শব্দকোষের ইংরেজি থাকতেই হবে (ছোট-বড় হাতের অক্ষর মানে না)। ⓘ ডেটা থেকে চলে — নতুন শব্দ মানে CSV-তে নতুন
/// সারি, পরীক্ষায় হাত নয়।
void main() {
  final glossary = glossaryFrom(parseCsv(File('test/l10n/glossary.csv').readAsStringSync()));
  final bn = arbMessages('lib/l10n/app_bn.arb');
  final en = arbMessages('lib/l10n/app_en.arb');

  test('the glossary is read: its terms and their English', () {
    expect(glossary.length, greaterThan(50));
    expect(glossary['সই'], containsAll(['sign', 'signature']));
    expect(glossary['বিজ্ঞপ্তি'], {'notification'});
    expect(glossary['প্রত্যাখ্যাত'], {'rejected'}, reason: '"ক / খ" দুই দিকে সমান হলে ঘরে ঘরে মেলে');
    expect(glossary.keys.where((t) => t.contains('(') || t.contains('{')), isEmpty, reason: 'এক প্রসঙ্গের শব্দ সবখানে খাটে না');
  });

  test('every ARB key that uses a glossary term says its English', () {
    final problems = glossaryProblems(bn, en, glossary);
    expect(problems, isEmpty, reason: problems.join('\n'));
  });

  group('the rule itself', () {
    final g = {'কাগজ': {'paper', 'document'}, 'টাকা': {'amount'}, 'টাকা স্থানান্তর': {'money transfer'}};

    test('a term whose English is missing is caught', () {
      expect(glossaryProblems({'a': '৩টা কাগজ'}, {'a': '3 sheets'}, g), [contains('"কাগজ"')]);
      expect(glossaryProblems({'a': 'কাগজ খুলুন'}, {'a': 'Open the Paper'}, g), isEmpty);
    });

    test('whole words only, and the longer term first', () {
      expect(glossaryTermsIn('কাগজগুলো', g.keys), isEmpty, reason: 'শব্দের অংশ শব্দ নয়');
      expect(glossaryTermsIn('টাকা স্থানান্তর', g.keys), ['টাকা স্থানান্তর']);
      expect(glossaryProblems({'a': 'টাকা স্থানান্তর'}, {'a': 'Money transfer'}, g), isEmpty);
    });

    test('quoted CSV cells keep their commas, quotes and new lines', () {
      final rows = parseCsv('﻿"term_bn_app","proposed_app_en"\n"ক, খ","A ""B""\nC"\n');
      expect(rows, [
        ['term_bn_app', 'proposed_app_en'],
        ['ক, খ', 'A "B"\nC'],
      ]);
    });
  });
}
