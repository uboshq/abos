import 'dart:convert';
import 'dart:io';
import 'dart:ui' show Color;

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/status/paper_state.dart';

/// ⭐ এক উৎস: রেপোর `resources/design/paper_states.json` (ওয়েবের চিপও এটা পড়ে) আর অ্যাপের `paper_state.dart` হুবহু এক।
/// কোনো লেখা (বাংলা বা ইংরেজি), আইকনের নাম, ভূমিকা, রং বা ধারের ধরন একটুও সরলে লাল।
String _hex(Color c) => '#${(c.toARGB32() & 0xFFFFFF).toRadixString(16).padLeft(6, '0').toUpperCase()}';

void main() {
  final file = File('../resources/design/paper_states.json');
  final json = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
  final states = json['states'] as Map<String, dynamic>;
  final roles = json['roles'] as Map<String, dynamic>;

  test('the JSON holds exactly the Dart states, in the same order', () {
    expect(states.keys.toList(), PaperState.values.map((s) => s.key).toList());
  });

  test('the JSON holds exactly the Dart roles', () {
    expect(roles.keys.toList(), PaperRole.values.map((r) => r.name).toList());
  });

  for (final role in PaperRole.values) {
    test('role ${role.name}: Fiori names, light and dark colours match', () {
      final j = roles[role.name] as Map<String, dynamic>;
      final d = paperRoleSpecs[role]!;
      expect(j['fiori'], d.fiori);
      expect(j['adjusted_for_contrast'], d.adjustedForContrast);
      // what was moved off Fiori, and Fiori's own value, is the same on both sides
      final adjusted = (j['adjusted'] as Map<String, dynamic>?) ?? const {};
      expect(adjusted.keys.toSet(), d.fioriOriginal.keys.toSet());
      for (final e in d.fioriOriginal.entries) {
        expect((adjusted[e.key] as Map)['fiori'], e.value);
      }
      expect(d.adjustedForContrast, d.fioriOriginal.isNotEmpty, reason: 'flag and record go together');
      for (final (mode, c) in [('light', d.light), ('dark', d.dark)]) {
        final m = j[mode] as Map<String, dynamic>;
        expect(m['foreground'], _hex(c.foreground), reason: '$mode foreground');
        expect(m['background'], _hex(c.background), reason: '$mode background');
        expect(m['border'], _hex(c.border), reason: '$mode border');
      }
    });
  }

  for (final state in PaperState.values) {
    test('state ${state.key}: every field matches', () {
      final j = states[state.key] as Map<String, dynamic>;
      final d = state.spec;
      expect(j['label_bn'], d.labelBn);
      expect(j['label_en'], d.labelEn);
      expect(j['label_bn_without_level'], d.labelBnWithoutLevel);
      expect(j['label_en_without_level'], d.labelEnWithoutLevel);
      expect(j['label_bn_short'], d.labelBnShort);
      expect(j['label_en_short'], d.labelEnShort);
      expect(j['label_bn_short_without_level'], d.labelBnShortWithoutLevel);
      expect(j['label_en_short_without_level'], d.labelEnShortWithoutLevel);
      expect(j['semantics_bn'], d.semanticsBn);
      expect(j['semantics_en'], d.semanticsEn);
      expect(j['semantics_bn_without_level'], d.semanticsBnWithoutLevel);
      expect(j['semantics_en_without_level'], d.semanticsEnWithoutLevel);
      expect(j['semantics_hint_bn'], d.semanticsHintBn);
      expect(j['semantics_hint_en'], d.semanticsHintEn);
      expect(j['icon'], d.icon);
      expect(j['role'], d.role.name);
      expect(j['border_style'], d.dashed ? 'dashed' : 'solid');
      expect(j['tappable'], d.tappable);
      expect(j['app_only'] ?? false, d.appOnly);
      expect(j['reason_title_bn'], d.reasonTitleBn);
      expect(j['reason_title_en'], d.reasonTitleEn);
      expect(j['reason_missing_bn'], d.reasonMissingBn);
      expect(j['reason_missing_en'], d.reasonMissingEn);
      expect(j['reason_close_bn'], d.reasonCloseBn);
      expect(j['reason_close_en'], d.reasonCloseEn);
      final kindKeys = RegExp(r'^(label_(bn|en)|label_(bn|en)_short|semantics_(bn|en))_(stock|claim)$');
      expect({for (final k in j.keys.where(kindKeys.hasMatch)) k: j[k]}, d.byKind, reason: 'words by kind');
      const known = {
        'label_bn', 'label_bn_without_level', 'label_bn_short', 'label_bn_short_without_level', 'semantics_bn',
        'semantics_bn_without_level', 'semantics_hint_bn', 'icon', 'role', 'border_style', 'tappable', 'app_only',
        'reason_title_bn', 'reason_missing_bn', 'reason_close_bn',
        'label_en', 'label_en_without_level', 'label_en_short', 'label_en_short_without_level', 'semantics_en',
        'semantics_en_without_level', 'semantics_hint_en', 'reason_title_en', 'reason_missing_en', 'reason_close_en',
        // ⓘ লেখকের টীকা — চিপে আঁকা হয় না, তাই অ্যাপে আয়না নেই (cb-র will_wait-এ প্রথম, 76c7d5283)
        'about',
      };
      expect(j.keys.where((k) => !known.contains(k) && !kindKeys.hasMatch(k)), isEmpty,
          reason: 'a JSON key the Dart side does not mirror');
    });
  }

  // ⭐ ভাষার সুইচ (0.4.43): প্রতিটা বাংলা লেখার পাশে তার ইংরেজি, ইংরেজিতে বাংলা অক্ষর নেই, আর {level}/{levels} দুই দিকে এক
  for (final state in PaperState.values) {
    test('state ${state.key}: every Bengali text has its English, with the same placeholders', () {
      final j = states[state.key] as Map<String, dynamic>;
      final names = RegExp(r'\{[a-z]+\}');
      for (final key in j.keys.where((k) => k.contains('_bn'))) {
        final en = j[key.replaceFirst('_bn', '_en')];
        expect(en, isA<String>(), reason: '$key has no English beside it');
        expect(RegExp('[ঀ-৿]').hasMatch(en as String), isFalse, reason: '$key: Bengali letters in the English');
        expect(names.allMatches(en).map((m) => m[0]).toSet(), names.allMatches(j[key] as String).map((m) => m[0]).toSet(),
            reason: '$key: placeholders differ');
      }
      expect(j.keys.where((k) => k.contains('_en')).length, j.keys.where((k) => k.contains('_bn')).length,
          reason: 'an English text with no Bengali');
    });
  }

  test('the English reads in English: the level as step N of M, the claim words as paid/approved', () {
    final w = PaperState.waiting.spec;
    expect(w.label(level: 1, levels: 2, english: true), 'Awaiting approval · step 1 of 2');
    expect(w.shortLabel(level: 1, levels: 2, english: true), 'Step 1 of 2');
    expect(w.label(english: true), 'Awaiting approval');
    expect(PaperState.posted.spec.label(english: true), 'Posted · in the books');
    expect(PaperState.posted.spec.label(kind: PaperKind.stock, english: true), 'Posted · in stock');
    expect(PaperState.posted.spec.label(kind: PaperKind.claim, english: true), 'Paid');
    expect(PaperState.approved.spec.semantics(kind: PaperKind.claim, english: true), 'Status: approved, awaiting payment');
    expect(PaperState.notPosted.spec.reasonClose(english: true), 'Close');
    expect(PaperState.posted.spec.label(), 'পোস্ট হয়েছে · খাতায় উঠেছে', reason: 'Bengali stays the default');
  });

  test('the JSON names exactly the Dart paper kinds', () {
    final kinds = (json['kinds'] as Map<String, dynamic>).keys.where((k) => k != 'about').toList();
    expect(kinds, PaperKind.values.map((k) => k.name).toList());
  });

  test('the JSON cites the Fiori Horizon sources and says levels use latin digits', () {
    final source = json['source'] as Map<String, dynamic>;
    expect(source['light'], contains('sap_horizon/css_variables.css'));
    expect(source['dark'], contains('sap_horizon_dark/css_variables.css'));
    expect((json['placeholders'] as Map)['digits'], startsWith('latin'));
  });
}
