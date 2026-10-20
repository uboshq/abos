import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/status/paper_state_icons.dart';

/// ⭐ প্রতিটা অবস্থার আইকন Material Symbols-এর Rounded ফন্টের (মালিকের নিয়ম: কেবল Material Symbols, ইমোজি নয়), আর
/// আইকনের নাম থেকে IconData কেবল এক জায়গায়।
void main() {
  for (final state in PaperState.values) {
    test('${state.key}: icon "${state.spec.icon}" is a Material Symbols Rounded glyph', () {
      final icon = paperStateIcon(state);
      expect(icon.fontFamily, 'MaterialSymbolsRounded');
      expect(icon.fontPackage, 'material_symbols_icons');
    });
  }

  test('the map holds exactly the icons the states name, no more', () {
    expect(paperStateIcons.keys.toSet(), PaperState.values.map((s) => s.spec.icon).toSet());
  });

  test('no two states share an icon (shape tells them apart without colour)', () {
    final icons = PaperState.values.map(paperStateIcon).toList();
    expect(icons.toSet().length, icons.length);
  });
}
