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

  // ⓘ cb-র will_wait (76c7d5283) "সইয়ের অপেক্ষায়"-এর একই বালুঘড়ি নেয় — একই কথার পূর্বাভাস; তখন ধারটা আলাদা (ভাঙা বনাম টানা),
  // তাই রং ছাড়াও আকারে চেনা যায়। আইকন আর ধার — দুটো মিলে কখনো একই নয়।
  test('no two states share an icon and a border (shape tells them apart without colour)', () {
    final looks = PaperState.values.map((s) => (paperStateIcon(s), s.spec.dashed)).toList();
    expect(looks.toSet().length, looks.length);
  });
}
