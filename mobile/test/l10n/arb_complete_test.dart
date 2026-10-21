import 'package:flutter_test/flutter_test.dart';

import 'arb_checks.dart';

/// ⭐ দুই ভাষার ARB সম্পূর্ণ আর মেলে (0.4.43): প্রতিটা চাবি দুই দিকেই, ইংরেজিতে বাংলা অক্ষর নেই, বাংলা খালি নয়, নামের জায়গা
/// দুই ভাষায় একই, ICU পড়া যায়, আর অঙ্ক ইংরেজি অক্ষরে (মালিকের নিয়ম: টাকা আর সংখ্যা ইংরেজি অঙ্কে)।
void main() {
  final bn = arbMessages('lib/l10n/app_bn.arb');
  final en = arbMessages('lib/l10n/app_en.arb');

  test('the two ARB files match, key for key, placeholder for placeholder', () {
    expect(bn, isNotEmpty);
    final problems = arbProblems(bn, en);
    expect(problems, isEmpty, reason: problems.join('\n'));
  });

  test('both files say which language they are', () {
    expect(arbLocale('lib/l10n/app_bn.arb'), 'bn');
    expect(arbLocale('lib/l10n/app_en.arb'), 'en');
  });

  group('each rule catches its own mistake', () {
    test('a key missing on either side', () {
      expect(arbProblems({'a': 'ক', 'b': 'খ'}, {'a': 'A'}), [contains('b: missing in app_en.arb')]);
      expect(arbProblems({'a': 'ক'}, {'a': 'A', 'c': 'C'}), [contains('c: missing in app_bn.arb')]);
    });

    test('Bengali in English, an empty Bengali text, Bengali digits', () {
      expect(arbProblems({'a': 'ক'}, {'a': 'A ক'}), [contains('Bengali letters in the English text')]);
      expect(arbProblems({'a': ' '}, {'a': 'A'}), [contains('empty Bengali text')]);
      expect(arbProblems({'a': '৩টা'}, {'a': '3'}), [contains('(bn): Bengali digits')]);
    });

    test('placeholders that differ, and ICU that does not parse', () {
      expect(arbProblems({'a': 'ক {n}'}, {'a': 'A {m}'}), [contains('placeholders differ')]);
      expect(arbProblems({'a': '{n, plural, =0{ক} one{খ}}'}, {'a': '{n, plural, other{A}}'}), [contains('without other')]);
      expect(arbProblems({'a': 'ক {n'}, {'a': 'A {n}'}), [contains('(bn): ICU does not parse')]);
      expect(arbProblems({'a': 'ক'}, {'a': 'A }'}), [contains('(en): ICU does not parse')]);
    });

    test('plural and select read their names, nested ones too', () {
      expect(icuPlaceholders('{count, plural, =0{কিছু নেই} other{{count}টা {what}}}'), {'count', 'what'});
      expect(icuPlaceholders('{state, select, draft{খসড়া} other{অজানা}}'), {'state'});
      expect(() => icuPlaceholders('{state, select, =1{x} other{y}}'), throwsFormatException);
    });
  });
}
