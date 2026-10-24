import 'dart:io';

import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/bar_fonts.dart';

/// ⭐ "সইয়ের অপেক্ষায় হবে" (will_wait, cb 76c7d5283) — সংরক্ষণের আগের পূর্বাভাস: সত্যিকারের "সইয়ের অপেক্ষায়"-এর একই অ্যাম্বার, কিন্তু
/// ভাঙা ধার, যাতে কখনো গুলিয়ে না যায়; পর্দা-পাঠক পুরো কথা বলে।

/// চিপের ভাঙা ধার আঁকা হয় কি না — শেয়ার করা চিপের নিজের আঁকিয়ে
bool _dashed(WidgetTester tester, Key key) => tester
    .widgetList<CustomPaint>(find.descendant(of: find.byKey(key), matching: find.byType(CustomPaint)))
    .any((p) => p.foregroundPainter?.runtimeType.toString() == '_DashedBorder');

BoxDecoration _box(WidgetTester tester, Key key) => tester
    .widgetList<Container>(find.descendant(of: find.byKey(key), matching: find.byType(Container)))
    .map((c) => c.decoration)
    .whereType<BoxDecoration>()
    .first;

Future<void> _pair(WidgetTester tester, {bool dark = false, ThemeData Function(ThemeData)? fonts}) async {
  final f = fonts ?? (ThemeData t) => t;
  await tester.pumpWidget(MaterialApp(
    debugShowCheckedModeBanner: false,
    theme: f(AppTheme.light()),
    darkTheme: f(AppTheme.dark()),
    themeMode: dark ? ThemeMode.dark : ThemeMode.light,
    home: Scaffold(
      body: RepaintBoundary(
        key: const ValueKey('shot'),
        child: Builder(
          builder: (context) => ColoredBox(
            color: Theme.of(context).scaffoldBackgroundColor,
            child: const Padding(
              padding: EdgeInsets.all(12),
              child: Wrap(spacing: 12, runSpacing: 8, children: [
                AppStatusChip(key: ValueKey('will'), state: PaperState.willWait),
                AppStatusChip(key: ValueKey('wait'), state: PaperState.waiting),
              ]),
            ),
          ),
        ),
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

Future<void> _loadRounded() async {
  final config = File('.dart_tool/package_config.json').readAsStringSync();
  final m = RegExp(r'"rootUri":\s*"([^"]*material_symbols_icons-[^"]*)"').firstMatch(config)!;
  final root = Uri.parse(m.group(1)!).toFilePath(windows: Platform.isWindows);
  final loader = FontLoader('packages/material_symbols_icons/MaterialSymbolsRounded')
    ..addFont(Future.value(ByteData.view(Uint8List.fromList(File('$root/lib/fonts/MaterialSymbolsRounded.ttf').readAsBytesSync()).buffer)));
  await loader.load();
}

void main() {
  setUpAll(() async {
    await loadBarFonts();
    await _loadRounded();
  });

  for (final dark in [false, true]) {
    final mode = dark ? 'dark' : 'light';
    testWidgets('will_wait ($mode): the same amber as "awaiting", but a dashed border where "awaiting" is solid', (tester) async {
      await _pair(tester, dark: dark);
      final critical = paperRoleSpecs[PaperRole.critical]!;
      final amber = dark ? critical.dark : critical.light;
      final will = _box(tester, const ValueKey('will'));
      final wait = _box(tester, const ValueKey('wait'));
      expect(will.color, amber.background, reason: '⛔ "সইয়ের অপেক্ষায় হবে" অ্যাম্বার নয়');
      expect(wait.color, amber.background);
      expect(_dashed(tester, const ValueKey('will')), isTrue, reason: '⛔ "সইয়ের অপেক্ষায় হবে"-র ধার ভাঙা নয়');
      expect(will.border, isNull, reason: '⛔ "সইয়ের অপেক্ষায় হবে"-র ধার টানা — "সইয়ের অপেক্ষায়"-এর সাথে গুলিয়ে যায়');
      expect(_dashed(tester, const ValueKey('wait')), isFalse);
      expect(wait.border, isNotNull, reason: '"সইয়ের অপেক্ষায়"-এর ধার টানা');
    });
  }

  testWidgets('TalkBack reads the forecast in full, bn and en', (tester) async {
    final handle = tester.ensureSemantics();
    await _pair(tester);
    expect(tester.getSemantics(find.byKey(const ValueKey('will'))).label, 'অবস্থা: খসড়া, পাঠালে সইয়ের অপেক্ষায় যাবে');
    expect(PaperState.willWait.spec.semantics(english: true), 'Status: draft, goes to awaiting signature when sent');
    expect(find.textContaining('সইয়ের অপেক্ষায় হবে', findRichText: true), findsOneWidget);
    handle.dispose();
  });

  for (final dark in [false, true]) {
    final mode = dark ? 'dark' : 'light';
    testWidgets('golden: will_wait beside awaiting ($mode)', (tester) async {
      tester.view.physicalSize = const Size(420, 80);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await _pair(tester, dark: dark, fonts: withBarFonts);
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/will_wait_beside_waiting_$mode.png'));
    });
  }
}
