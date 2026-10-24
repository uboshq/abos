// ⓘ মাপের দাবির আসল ফন্ট — পরীক্ষার নিজের ফন্টে (FlutterTest) প্রতিটা অক্ষর ১em চওড়া, তাই "৳1,20,000.00" সেখানে আসল ফোনের
// প্রায় দ্বিগুণ চওড়া; "অঙ্ক এক লাইনে ধরে কি না" সেখানে মাপা অর্থহীন। ⛔ নিজের নামে ('AbosRealLatin', 'AbosRealBangla') — 'Roboto'
// নামে বসালে ফাইলের বাকি সব পরীক্ষার লেখাও বদলে যেত (থিমের ডিফল্ট ফন্ট Roboto)।
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

const _material = 'C:/tools/flutter/bin/cache/artifacts/material_fonts';

Future<void> _load(String family, List<String> files) async {
  final loader = FontLoader(family);
  for (final f in files) {
    final file = File(f);
    if (!file.existsSync()) continue;
    loader.addFont(Future.value(ByteData.view(Uint8List.fromList(file.readAsBytesSync()).buffer)));
  }
  await loader.load();
}

/// Roboto আর Shonar Bangla, নিজের নামে — `setUpAll`-এ একবার
Future<void> loadRealFonts() async {
  await _load('AbosRealLatin', ['$_material/roboto-regular.ttf', '$_material/roboto-medium.ttf', '$_material/roboto-bold.ttf']);
  await _load('AbosRealBangla', ['C:/Windows/Fonts/Shonar.ttf', 'C:/Windows/Fonts/Shonarb.ttf']);
}

/// থিমের লেখা আসল ফন্টে
ThemeData withRealFonts(ThemeData t) => t.copyWith(
      textTheme: t.textTheme.apply(fontFamily: 'AbosRealLatin', fontFamilyFallback: const ['AbosRealBangla']),
      primaryTextTheme: t.primaryTextTheme.apply(fontFamily: 'AbosRealLatin', fontFamilyFallback: const ['AbosRealBangla']),
    );
