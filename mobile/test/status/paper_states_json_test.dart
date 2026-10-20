import 'dart:convert';
import 'dart:io';
import 'dart:ui' show Color;

import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/status/paper_state.dart';

/// ⭐ এক উৎস: রেপোর `resources/design/paper_states.json` (ওয়েবের চিপও এটা পড়ে) আর অ্যাপের `paper_state.dart` হুবহু এক।
/// কোনো লেখা, আইকনের নাম, ভূমিকা, রং বা ধারের ধরন একটুও সরলে লাল।
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
      expect(j['label_bn_without_level'], d.labelBnWithoutLevel);
      expect(j['label_bn_short'], d.labelBnShort);
      expect(j['label_bn_short_without_level'], d.labelBnShortWithoutLevel);
      expect(j['semantics_bn'], d.semanticsBn);
      expect(j['semantics_bn_without_level'], d.semanticsBnWithoutLevel);
      expect(j['semantics_hint_bn'], d.semanticsHintBn);
      expect(j['icon'], d.icon);
      expect(j['role'], d.role.name);
      expect(j['border_style'], d.dashed ? 'dashed' : 'solid');
      expect(j['tappable'], d.tappable);
      expect(j['app_only'] ?? false, d.appOnly);
      expect(j['reason_title_bn'], d.reasonTitleBn);
      expect(j['reason_missing_bn'], d.reasonMissingBn);
      expect(j['reason_close_bn'], d.reasonCloseBn);
      const known = {
        'label_bn', 'label_bn_without_level', 'label_bn_short', 'label_bn_short_without_level', 'semantics_bn',
        'semantics_bn_without_level', 'semantics_hint_bn', 'icon', 'role', 'border_style', 'tappable', 'app_only',
        'reason_title_bn', 'reason_missing_bn', 'reason_close_bn',
      };
      expect(j.keys.where((k) => !known.contains(k)), isEmpty, reason: 'a JSON key the Dart side does not mirror');
    });
  }

  test('the JSON cites the Fiori Horizon sources and says levels use latin digits', () {
    final source = json['source'] as Map<String, dynamic>;
    expect(source['light'], contains('sap_horizon/css_variables.css'));
    expect(source['dark'], contains('sap_horizon_dark/css_variables.css'));
    expect((json['placeholders'] as Map)['digits'], startsWith('latin'));
  });
}
