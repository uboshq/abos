import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

import '../../tool/l10n_merge.dart';

/// ⭐ app_bn.arb আর app_en.arb কেবল ভাগগুলোর জোড়া (০.৪.৪৩; চারজন একসাথে লেখে বলে প্রত্যেকের নিজের ভাগ) — কেউ হাতে
/// app_*.arb বদলালে বা জোড়া লাগাতে ভুললে লাল; একই চাবি দুই ভাগে থাকলেও লাল।
void main() {
  for (final locale in ['bn', 'en']) {
    test('app_$locale.arb is exactly the parts merged (run: dart run tool/l10n_merge.dart)', () {
      final onDisk = jsonDecode(File('lib/l10n/app_$locale.arb').readAsStringSync()) as Map<String, dynamic>;
      final merged = mergeParts(locale);
      expect(onDisk.keys.toList(), merged.keys.toList(), reason: 'চাবি বা ক্রম মেলে না — জোড়া আবার লাগাও');
      expect(jsonEncode(onDisk), jsonEncode(merged));
    });
  }

  test('one key belongs to one part only', () {
    final dir = Directory.systemTemp.createTempSync('arb_parts');
    addTearDown(() => dir.deleteSync(recursive: true));
    File('${dir.path}/a5_bn.arb').writeAsStringSync('{"@@locale":"bn","x":"এক"}');
    File('${dir.path}/a6_bn.arb').writeAsStringSync('{"@@locale":"bn","x":"দুই"}');
    expect(() => mergeParts('bn', dir: dir.path), throwsStateError);
  });
}
