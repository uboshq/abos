import 'package:flutter_test/flutter_test.dart';

import 'hard_coded_scan.dart';

/// ⭐ হাতে-লেখা বাংলা কেবল কমে (ভাষার সুইচ, 0.4.43)।
///
/// <p>`lib/**/*.dart`-এর বাংলা-অক্ষরের স্ট্রিং-লিটারেল গোনা হয় (মন্তব্য আর `// l10n-ok: <কারণ>` লাইন বাদ) আর
/// `test/l10n/still_hard_coded.json`-এর সাথে মেলানো হয়। ⛔ লাল: কোনো ফাইলে বাড়ল, তালিকার বাইরের ফাইলে বাংলা, অথবা কমল কিন্তু
/// তালিকা কমানো হয়নি ("tighten the list") — তাই তালিকা কেবল ছোট হয়, আর খালি হলে সুইচ চালু
/// (`language_switch_ready_test.dart`)। নির্দেশিকা: E:\ABOS\app-0443-ভাষা-নির্দেশিকা.md
void main() {
  test('no file holds more hard-coded Bengali than the list allows, and the list is as tight as the code', () {
    final problems = ratchetProblems(scanLib(), readList());
    expect(problems, isEmpty, reason: problems.join('\n'));
  });

  group('the ratchet itself', () {
    test('growth, a new file and a shrink that was not written down are each caught', () {
      expect(ratchetProblems({'lib/a.dart': 3}, {'lib/a.dart': 2}), [contains('the list allows 2')]);
      expect(ratchetProblems({'lib/new.dart': 1}, const {}), [contains('not on the list')]);
      expect(ratchetProblems({'lib/a.dart': 1}, {'lib/a.dart': 2}), [contains('tighten the list')]);
      expect(ratchetProblems(const {}, {'lib/a.dart': 1}), [contains('tighten the list')]);
      expect(ratchetProblems({'lib/a.dart': 2}, {'lib/a.dart': 2, 'lib/b.dart': 0}), isEmpty);
    });

    test('the scanner counts string literals with Bengali, not comments, and honours l10n-ok', () {
      const source = '''
// মন্তব্য 'বাংলা' — গোনা নয়
/// ডক 'বাংলা'
/* ব্লক 'বাংলা' */
final a = 'এক';
final b = "দুই \$x";
final c = 'English only';
final d = 'বাংলা'; // l10n-ok: ভাষার নিজের নাম
final e = r'কাঁচা';
final f = \'\'\'তিন
লাইন\'\'\';
final g = 'বাইরে \${h('ভেতরে')} শেষ';
final i = 'url://not-a-comment'; final j = 'চার';
final k = x; // শেষের মন্তব্য 'বাংলা'
''';
      // এক, দুই, কাঁচা, তিন, বাইরে…শেষ, ভেতরে, চার
      expect(countBengaliLiterals(source), 7);
    });

    test('generated and ARB folders are not scanned', () {
      expect(skipped('lib/l10n/app_localizations_bn.dart'), isTrue);
      expect(skipped('lib/x.g.dart'), isTrue);
      expect(skipped('lib/core/navigation/top_bar/avatar_menu.dart'), isFalse);
    });
  });
}
