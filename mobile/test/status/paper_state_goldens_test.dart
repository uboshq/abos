import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';

/// ⭐ আট অবস্থার চিপের ছবি — পুরো আর ছোট দুই মাপ, আলো আর অন্ধকারে (`test/status/goldens/`)।
///
/// <p>আসল ফন্টে আঁকা, যাতে ছবিতে বাংলা আর আইকন সত্যিই দেখা যায় (পরীক্ষার ফন্টে সব চৌকো): Roboto Flutter-এর নিজের
/// material_fonts থেকে, বাংলা Windows-এর Shonar.ttf থেকে, আইকন material_symbols_icons প্যাকেজের Rounded ফন্ট থেকে।
/// ⓘ ফন্টের ফাইল না পেলে পরীক্ষা থামে না, বাদ যায় — অন্য যন্ত্রে ছবি অন্যরকম হত।
const _flutterFonts = r'C:\tools\flutter\bin\cache\artifacts\material_fonts';
const _bengali = r'C:\Windows\Fonts\Shonar.ttf';
const _bengaliBold = r'C:\Windows\Fonts\Shonarb.ttf';

String? _symbolsFont() {
  final config = File('.dart_tool/package_config.json');
  if (!config.existsSync()) return null;
  final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config.readAsStringSync());
  if (m == null) return null;
  final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
  final f = File('$root${Platform.pathSeparator}lib${Platform.pathSeparator}fonts${Platform.pathSeparator}MaterialSymbolsRounded.ttf');
  return f.existsSync() ? f.path : null;
}

Future<ByteData> _bytes(String path) async => ByteData.sublistView(Uint8List.fromList(await File(path).readAsBytes()));

Future<void> _load(String family, List<String> paths) async {
  final loader = FontLoader(family);
  for (final p in paths) {
    loader.addFont(_bytes(p));
  }
  await loader.load();
}

ThemeData _withFonts(ThemeData t) => t.copyWith(
      textTheme: t.textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Shonar']),
    );

void main() {
  final symbols = _symbolsFont();
  final ready = symbols != null &&
      File(_bengali).existsSync() &&
      File('$_flutterFonts\\roboto-regular.ttf').existsSync();

  setUpAll(() async {
    if (!ready) return;
    await _load('Roboto', ['$_flutterFonts\\roboto-regular.ttf', '$_flutterFonts\\roboto-medium.ttf', '$_flutterFonts\\roboto-bold.ttf']);
    await _load('Shonar', [_bengali, if (File(_bengaliBold).existsSync()) _bengaliBold]);
    await _load('packages/material_symbols_icons/MaterialSymbolsRounded', [symbols]);
  });

  for (final dark in [false, true]) {
    final mode = dark ? 'dark' : 'light';
    testWidgets('all paper-state chips ($mode) look as approved', (tester) async {
      tester.view.physicalSize = const Size(420, 580);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: _withFonts(AppTheme.light()),
        darkTheme: _withFonts(AppTheme.dark()),
        themeMode: dark ? ThemeMode.dark : ThemeMode.light,
        home: Scaffold(
          body: RepaintBoundary(
            key: const ValueKey('shot'),
            child: Builder(
              builder: (context) => ColoredBox(
                color: Theme.of(context).scaffoldBackgroundColor,
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    for (final s in PaperState.values)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Row(children: [
                          AppStatusChip(state: s, level: 1, levels: 2, reason: 'বাকির সীমা পার'),
                          const Spacer(),
                          AppStatusChip.compact(state: s, level: 1, levels: 2),
                        ]),
                      ),
                    // ধরনের কথা: মজুদের কাগজ পাকা, দাবি অনুমোদিত
                    for (final (s, k) in const [(PaperState.posted, PaperKind.stock), (PaperState.approved, PaperKind.claim)])
                      Padding(
                        padding: const EdgeInsets.only(bottom: 6),
                        child: Row(children: [
                          AppStatusChip(state: s, kind: k),
                          const Spacer(),
                          AppStatusChip.compact(state: s, kind: k),
                        ]),
                      ),
                  ]),
                ),
              ),
            ),
          ),
        ),
      ));
      await tester.pumpAndSettle();
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/paper_states_$mode.png'));
    }, skip: !ready);
  }
}
