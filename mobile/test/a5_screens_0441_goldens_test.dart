import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ ০.৪.৪১-এর দুই পর্দার ছবি — আসল ফন্টে (লাতিনে Roboto, বাংলায় Shonar Bangla, Material আইকন), ৩৬০ আর ৪১২dp, আলো আর
/// অন্ধকার; নমুনার নাম আর অঙ্ক। a4-এর design_preview-এর একই ফন্ট-লোড। ⓘ মাথার বার আর নিচের বার এই ছবিতে নেই (a4-এর আর
/// আরেক a5-এর) — বার মিললে a5 ছবি আবার তুলবে।
const _material = 'C:/tools/flutter/bin/cache/artifacts/material_fonts';

Future<void> _load(String family, List<String> files) async {
  final loader = FontLoader(family);
  for (final f in files) {
    loader.addFont(Future.value(ByteData.view(Uint8List.fromList(File(f).readAsBytesSync()).buffer)));
  }
  await loader.load();
}

ThemeData _realFonts(ThemeData theme) => theme.copyWith(
      textTheme: theme.textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla']),
      primaryTextTheme: theme.primaryTextTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla']),
    );

void main() {
  setUpAll(() async {
    await _load('Roboto', ['$_material/roboto-regular.ttf', '$_material/roboto-medium.ttf', '$_material/roboto-bold.ttf']);
    await _load('Bangla', ['C:/Windows/Fonts/Shonar.ttf', 'C:/Windows/Fonts/Shonarb.ttf']);
    await _load('MaterialIcons', ['$_material/materialicons-regular.otf']);
  });

  final screens = <String, (Widget Function(), Future<void> Function(WidgetTester))>{
    'screen_owner_home': (ownerHomeBody, (_) async {}),
    // ⓘ নমুনার "আজকের আদায়" — তারিখের বারে "আজ" চেপে
    'screen_sr_collect': (
      srCollectBody,
      (tester) async {
        await tester.tap(find.byKey(const ValueKey('range-today')));
        await tester.pumpAndSettle();
      }
    ),
  };

  for (final (width, height) in [(360.0, 800.0), (412.0, 892.0)]) {
    for (final dark in [false, true]) {
      final mode = dark ? 'dark' : 'light';
      for (final s in screens.entries) {
        final name = '${s.key}_${width.toInt()}_$mode';
        testWidgets('$name looks as approved', (tester) async {
          setScreenSize(tester, Size(width, height));
          await tester.pumpWidget(RepaintBoundary(
            key: const ValueKey('shot'),
            child: framed(s.value.$1(), dark: dark, font: _realFonts),
          ));
          await tester.pumpAndSettle();
          await s.value.$2(tester);
          await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/screens_0441/$name.png'));
        });
      }
    }
  }
}
