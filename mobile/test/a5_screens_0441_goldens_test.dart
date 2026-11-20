import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/sync_status.dart';

import 'support/bar_fonts.dart' show loadBarFonts;
import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ ০.৪.৪১-এর দুই পর্দার ছবি — আসল ফন্টে (লাতিনে Roboto, বাংলায় Shonar Bangla, Material আইকন), ৩৬০ আর ৪১২dp, আলো আর
/// অন্ধকার; নমুনার নাম আর অঙ্ক। a4-এর design_preview-এর একই ফন্ট-লোড। আদায়ের পাতা a4-এর আসল নীল বারসহ (সেশনে);
/// মালিকের হোম কেবল দেহ।
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
    // ⓘ a6-এর চিপের আইকন — material_symbols_icons-এর Rounded ফন্ট (a6-এর paper_state_goldens_test-এর একই পথ)
    final config = File('.dart_tool/package_config.json').readAsStringSync();
    final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
    final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
    await _load('packages/material_symbols_icons/MaterialSymbolsRounded', ['$root/lib/fonts/MaterialSymbolsRounded.ttf']);
    // ⓘ a4-এর বারের আইকন — Material Symbols Outlined (screen_bar_test-এর একই ফন্ট)
    await loadBarFonts();
  });
  setUp(SyncStatus.instance.reset);

  // ⓘ তৃতীয়টা: আসল বারে আঁকা কি না — আদায়ের পাতা ভেতরের পর্দা, তাই সেশনসহ: a4-এর নীল বার "ADI Wholesale · ময়মনসিংহ ›
  // বিক্রয় › আদায়" (fe, ২০ অক্টোবর ২০২৬)। মালিকের হোমের এই ছবি কেবল দেহ — তার বার হোমের নিজের (নিচের বারের ছবিতে পুরো পাতা)।
  final screens = <String, (Widget Function(), Future<void> Function(WidgetTester), bool)>{
    'screen_owner_home': (ownerHomeBody, (_) async {}, false),
    // ⓘ নমুনার "আজকের আদায়" — তারিখের বারে "আজ" চেপে
    'screen_sr_collect': (
      () => srCollectBody(canReportDeposit: true), // ⓘ নমুনার মতো "＋ নতুন আদায়" সহ
      (tester) async {
        await tester.tap(find.byKey(const ValueKey('range-today')));
        await tester.pumpAndSettle();
      },
      true
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
            child: s.value.$3
                ? inSession(framed(s.value.$1(), dark: dark, font: _realFonts))
                : framed(s.value.$1(), dark: dark, font: _realFonts),
          ));
          await tester.pumpAndSettle();
          await s.value.$2(tester);
          await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/screens_0441/$name.png'));
          // ⭐ FAB-ওয়ালা পাতা তালিকার একেবারে শেষেও — শেষ সারি আর মোট বোতামের ওপরে (fab_never_hides_data_test-এর দাবির ছবি)
          if (s.key == 'screen_sr_collect') {
            for (final st in tester.stateList<ScrollableState>(find.byType(Scrollable))) {
              final p = st.position;
              if (p.axis == Axis.vertical && p.hasContentDimensions) p.jumpTo(p.maxScrollExtent);
            }
            await tester.pumpAndSettle();
            await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/screens_0441/${name}_end.png'));
          }
        });
      }
    }
  }

  // ⭐ সরু ফোন (৩২০dp) আর ২০০% অক্ষরে SR-এর আদায় (০.৪.৪৮; fe, ২৪ অক্টোবর ২০২৬) — "＋ নতুন আদায়" নিচের বারে মোটের পাশে,
  // জায়গা কম বলে কেবল আইকন; কোনো সারি বা মোট ঢাকে না, কোনো টাকা কাটা নয়। স্থির অবস্থায় আর একেবারে শেষে।
  for (final (name, size, scale) in [
    ('screen_sr_collect_320_light', const Size(320, 640), 1.0),
    ('screen_sr_collect_360_light_200', const Size(360, 800), 2.0),
  ]) {
    testWidgets('$name looks as approved', (tester) async {
      setScreenSize(tester, size);
      await tester.pumpWidget(RepaintBoundary(
        key: const ValueKey('shot'),
        child: inSession(framed(srCollectBody(canReportDeposit: true), textScale: scale, font: _realFonts)),
      ));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('range-today')));
      await tester.pumpAndSettle();
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/screens_0441/$name.png'));
      for (final st in tester.stateList<ScrollableState>(find.byType(Scrollable))) {
        final p = st.position;
        if (p.axis == Axis.vertical && p.hasContentDimensions) p.jumpTo(p.maxScrollExtent);
      }
      await tester.pumpAndSettle();
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/screens_0441/${name}_end.png'));
    });
  }
}
