// ⓘ উপরের বারের ছবি-টেস্টের আসল ফন্ট — Roboto (লাতিন), Shonar Bangla (বাংলা), Material Symbols (আইকন); নইলে ছবিতে সব চৌকো
// বাক্স। ফন্ট এই PC-র; ছবি এক PC-তে বানানো, একই ফন্টে বারবার একই।
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

const _material = 'C:/tools/flutter/bin/cache/artifacts/material_fonts';
const _symbols =
    'C:/Users/alami/AppData/Local/Pub/Cache/hosted/pub.dev/material_symbols_icons-4.2960.0/lib/fonts/MaterialSymbolsOutlined.ttf';

Future<void> _load(String family, List<String> files) async {
  final loader = FontLoader(family);
  var any = false;
  for (final f in files) {
    final file = File(f);
    if (!file.existsSync()) continue;
    any = true;
    final bytes = file.readAsBytesSync();
    loader.addFont(Future.value(ByteData.view(Uint8List.fromList(bytes).buffer)));
  }
  if (any) await loader.load();
}

/// Roboto (লাতিন), Shonar Bangla (বাংলা), Material Symbols (আইকন) — নইলে ছবিতে সব চৌকো বাক্স
Future<void> loadBarFonts() async {
  await _load('Roboto', ['$_material/roboto-regular.ttf', '$_material/roboto-medium.ttf', '$_material/roboto-bold.ttf']);
  await _load('Bangla', ['C:/Windows/Fonts/Shonar.ttf', 'C:/Windows/Fonts/Shonarb.ttf']);
  await _load('MaterialIcons', ['$_material/materialicons-regular.otf']);
  await _load('packages/material_symbols_icons/MaterialSymbolsOutlined', [_symbols]);
}

/// অ্যাপের থিম, কেবল লেখার ফন্ট আসল
ThemeData withBarFonts(ThemeData t) => t.copyWith(
      textTheme: t.textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla']),
      primaryTextTheme: t.primaryTextTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla']),
      // ⓘ রূপের নিজের মাথার লেখা (Material 3-এর মোটা শিরোনাম) — নইলে ছবিতে চৌকো বাক্স; ফোনে দেশি ফন্ট নিজেই আসে
      appBarTheme: t.appBarTheme.copyWith(
          titleTextStyle: t.appBarTheme.titleTextStyle?.copyWith(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla'])),
    );
