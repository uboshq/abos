// ⭐ ভাষার ফাইল জোড়া লাগানো (০.৪.৪৩): চারজন একসাথে লেখে, তাই প্রত্যেকের নিজের ভাগ `lib/l10n/parts/<কে>_bn.arb` আর
// `<কে>_en.arb`; এটা সব ভাগ মিলিয়ে `lib/l10n/app_bn.arb` আর `app_en.arb` লেখে। একই চাবি দুই ভাগে থাকলে থামে।
// চালানো: `dart run tool/l10n_merge.dart`, তারপর `flutter gen-l10n`। ⛔ app_*.arb হাতে বদলাবে না —
// test/l10n/arb_parts_test.dart মিলিয়ে দেখে।
import 'dart:convert';
import 'dart:io';

/// সব ভাগ জুড়ে একটা ভাষার পুরো ARB — `@@locale` প্রথমে, তারপর ভাগের নাম ধরে (a4, a5, a6, cb …), ভাগের ভেতরে লেখার ক্রমে
Map<String, dynamic> mergeParts(String locale, {String dir = 'lib/l10n/parts'}) {
  final files = Directory(dir)
      .listSync()
      .whereType<File>()
      .where((f) => f.path.endsWith('_$locale.arb'))
      .toList()
    ..sort((a, b) => a.path.compareTo(b.path));
  final merged = <String, dynamic>{'@@locale': locale};
  final from = <String, String>{};
  for (final f in files) {
    final part = jsonDecode(f.readAsStringSync()) as Map<String, dynamic>;
    final name = f.uri.pathSegments.last;
    for (final e in part.entries) {
      if (e.key == '@@locale') continue;
      if (merged.containsKey(e.key)) {
        throw StateError('"${e.key}" দুই ভাগে আছে: ${from[e.key]} আর $name — একটা চাবি একজনের');
      }
      merged[e.key] = e.value;
      from[e.key] = name;
    }
  }
  return merged;
}

String render(Map<String, dynamic> arb) => '${const JsonEncoder.withIndent('  ').convert(arb)}\n';

void main() {
  for (final locale in ['bn', 'en']) {
    File('lib/l10n/app_$locale.arb').writeAsStringSync(render(mergeParts(locale)));
    stdout.writeln('lib/l10n/app_$locale.arb লেখা হলো');
  }
}
