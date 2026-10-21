import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';

import '../support/bar_fonts.dart' show loadBarFonts;
import '../support/main_screens.dart' show setScreenSize;
import '../support/screens_0441.dart';
import 'hard_coded_scan.dart' show readList;

/// ⭐ মালিকের হোম ইংরেজিতে (ভাষার সুইচ 0.4.43, a4b-র ভাগ): অ্যাপের নিজের কোনো লেখায় বাংলা অক্ষর নেই — পর্দার লেখা আর
/// পর্দা-পাঠকের কথা দুটোই। ⓘ বাংলা থাকে কেবল নমুনা তথ্যের নিজের নামে, নিচের [sampleData]-য় এক এক করে লেখা।
///
/// <p>ছবি: ৪১২dp, আলো, ইংরেজি — `test/goldens/l10n/owner_home_412_en.png` (আসল ফন্টে, a5-এর ০.৪.৪১ ছবির একই ফন্ট-লোড)।
const _material = 'C:/tools/flutter/bin/cache/artifacts/material_fonts';

/// নমুনা তথ্যের বাংলা — সার্ভারের পাঠানো নাম, অ্যাপের লেখা নয় (test/support/screens_0441.dart); পর্দায় নাম, পয়েন্ট আর শাখা
/// আলাদা লাইনে বসে, তাই এক এক করে
const sampleData = [
  'রহিম স্টোর', // অপেক্ষার কাগজ a1-এর গ্রাহক
  'কেন্দুয়া', // a1-এর পয়েন্ট
  'ACI লিমিটেড', // a2-এর সরবরাহকারী
  'গাড়ির ভাড়া', // a3-এর খরচের খাত
  'ময়মনসিংহ', // শাখা (কাগজের পক্ষে আর শাখার বিক্রির সারিতে)
  'নেত্রকোনা', // শাখা
  'সব শাখা', // "আজ"-এর `branch`
  'আল-আমিন শুভ', // মালিকের নাম
];

/// ⚠️ অন্যের ফাইলের লেখা, এখনো ARB-তে যায়নি: কাগজের অবস্থার চিপ (`lib/core/status/paper_state.dart`, তালিকায় আছে) — চোখের
/// লেখা আর পর্দা-পাঠকের কথা। ⛔ ফাইলটা তালিকা থেকে উঠে গেলে নিচের পরীক্ষা লাল হয়, তখন এই ছাড় মুছতে হবে।
const chipNotYetConverted = ['￼সইয়ের অপেক্ষায়', 'অবস্থা: সইয়ের অপেক্ষায়'];
const chipFile = 'lib/core/status/paper_state.dart';

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

Widget _english(Widget child) => MaterialApp(
      debugShowCheckedModeBanner: false,
      locale: AppLocale.english,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      theme: _realFonts(AppTheme.light()),
      home: child,
    );

final _bengali = RegExp('[ঀ-৿]');

/// পর্দার সব লেখা আর সব Semantics-এর কথা (label, hint, value)
List<String> _allText(WidgetTester tester) => [
      for (final r in tester.widgetList<RichText>(find.byType(RichText))) r.text.toPlainText(),
      for (final s in tester.widgetList<Semantics>(find.byType(Semantics))) ...[
        if (s.properties.label != null) s.properties.label!,
        if (s.properties.hint != null) s.properties.hint!,
        if (s.properties.value != null) s.properties.value!,
      ],
    ];

/// ⓘ টাকার চিহ্ন "৳" (U+09F3) বাংলা ব্লকের অক্ষর, কিন্তু মুদ্রার নিজের চিহ্ন — সব ভাষায় একই ([Money.taka])
const takaSign = '৳';

/// নমুনার নামগুলো আর টাকার চিহ্ন বাদ দিলে যা বাংলা থাকে
List<String> bengaliLeft(Iterable<String> texts) => [
      for (final t in texts)
        if (_bengali.hasMatch(sampleData.fold(t, (s, name) => s.replaceAll(name, '')).replaceAll(takaSign, '')))
          t,
    ];

void main() {
  setUpAll(() async {
    await _load('Roboto', ['$_material/roboto-regular.ttf', '$_material/roboto-medium.ttf', '$_material/roboto-bold.ttf']);
    await _load('Bangla', ['C:/Windows/Fonts/Shonar.ttf', 'C:/Windows/Fonts/Shonarb.ttf']);
    await _load('MaterialIcons', ['$_material/materialicons-regular.otf']);
    final config = File('.dart_tool/package_config.json').readAsStringSync();
    final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
    final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
    await _load('packages/material_symbols_icons/MaterialSymbolsRounded', ['$root/lib/fonts/MaterialSymbolsRounded.ttf']);
    await loadBarFonts();
  });
  setUp(() {
    SyncStatus.instance.reset();
    AppLocale.current = AppLocale.english;
  });
  tearDown(() => AppLocale.current = AppLocale.locale);

  // ⓘ পুরো অঙ্কের "আজ": টাকার কার্ডের সব ভাগ, বাইরে যাওয়া, মজুদের মূল্য, দেনা আর হাতধার, প্রিন্সিপাল, MFS-এর খাত, নিট বিক্রি
  Map<String, dynamic> fullPayload() => ownerToday(
        outflow: '25000.0000',
        stockValue: '3250000.0000',
        mfs: '12000.0000',
        transit: '5000.0000',
        payable: {'amount': '410000.0000', 'handLoans': '50000.0000'},
        mfsAccounts: [
          {'name': 'bKash', 'balance': '12000.0000'},
        ],
        principals: [
          {'name': 'ACI', 'period': '2026-10', 'basisRate': '2%', 'inflow': '100000.0000', 'commission': '2000.0000', 'paid': '0', 'balance': '98000.0000'},
          {'name': 'Square', 'period': '2026-10', 'basisRate': '2%', 'inflow': '50000.0000', 'commission': '-500.0000', 'paid': '0', 'balance': '-1500.0000'},
        ],
        principalsTotal: {'inflow': '150000.0000', 'commission': '1500.0000', 'paid': '0', 'balance': '96500.0000'},
        sales: {'amount': '150000.0000', 'count': 9, 'billed': '162700.0000', 'returned': '12700.0000'},
      );

  Future<void> pump(WidgetTester tester) async {
    setScreenSize(tester, const Size(412, 2400));
    await tester.pumpWidget(RepaintBoundary(
      key: const ValueKey('shot'),
      child: _english(ownerHomeBody(today: fullPayload())),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('in English the owner home has no Bengali letter in any app-owned text', (tester) async {
    await pump(tester);
    final texts = _allText(tester);
    expect(texts.join(' '), contains('Total in hand and bank'), reason: 'ইংরেজিতেই আঁকা');
    expect(texts.join(' '), contains('Awaiting signature'));
    expect(texts.join(' '), contains('Stock value'));
    final left = bengaliLeft(texts).where((t) => !chipNotYetConverted.contains(t)).toList();
    expect(left, isEmpty, reason: 'অ্যাপের লেখায় বাংলা:\n${left.join('\n')}');
    // ⓘ চিপের ছাড় কেবল যতক্ষণ তার ফাইল তালিকায় (হাতে-লেখা বাংলা নিয়ে) আছে
    expect(readList().containsKey(chipFile), isTrue,
        reason: '$chipFile ARB-তে গেছে — chipNotYetConverted ছাড় মুছে দিন, চিপও ইংরেজিতে পড়বে');
  });

  test('the check itself: an app word in Bengali is caught, a sample name is not', () {
    expect(bengaliLeft(['Branch ময়মনসিংহ', 'RCV-0190 · রহিম স্টোর', 'কেন্দুয়া · ময়মনসিংহ · ৳50,000.00']), isEmpty);
    expect(bengaliLeft(['মোট']), ['মোট']);
    expect(bengaliLeft(['ময়মনসিংহ · মোট']), isNotEmpty);
    expect(bengaliLeft(['Total ৳1,000.00']), isEmpty, reason: 'টাকার চিহ্ন লেখা নয়');
  });

  testWidgets('owner home, 412dp, light, English', (tester) async {
    setScreenSize(tester, const Size(412, 2000));
    await tester.pumpWidget(RepaintBoundary(
      key: const ValueKey('shot'),
      child: _english(ownerHomeBody(today: fullPayload())),
    ));
    await tester.pumpAndSettle();
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/l10n/owner_home_412_en.png'));
  });
}
