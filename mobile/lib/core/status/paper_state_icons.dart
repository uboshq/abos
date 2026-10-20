import 'package:flutter/widgets.dart';
import 'package:material_symbols_icons/symbols.dart';

import 'paper_state.dart';

/// ⭐ অবস্থার আইকন — Material Symbols, Rounded (মালিকের নিয়ম: কেবল Material Symbols, ইমোজি নয়)।
///
/// <p>⛔ এক জায়গা: নাম থেকে IconData কেবল এই ফাইলে। নামগুলো `resources/design/paper_states.json`-এর `icon` —
/// `test/status/paper_state_icons_test.dart` মিলিয়ে দেখে, আর দেখে প্রতিটা আইকন Symbols-এর Rounded ফন্টের।
const paperStateIcons = <String, IconData>{
  'edit_note': Symbols.edit_note_rounded,
  'hourglass_top': Symbols.hourglass_top_rounded,
  'undo': Symbols.undo_rounded,
  'draw': Symbols.draw_rounded,
  'check_circle': Symbols.check_circle_rounded,
  'do_not_disturb_on': Symbols.do_not_disturb_on_rounded,
  'warning': Symbols.warning_rounded,
  'cloud_upload': Symbols.cloud_upload_rounded,
  'cancel': Symbols.cancel_rounded,
  'verified': Symbols.verified_rounded,
};

/// এক অবস্থার আইকন
IconData paperStateIcon(PaperState state) => paperStateIcons[state.spec.icon]!;
