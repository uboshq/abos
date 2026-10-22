import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/printing/printer_settings.dart';
import 'package:abos_mobile/core/printing/thermal_printer.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/printing/printer_settings_screen.dart';

import 'navigation/bar_harness.dart' show loadRealFonts, realFontTheme;
import 'fakes/fake_bluetooth_port.dart';

/// ⭐ এক পর্দা দুই ভাষায় (0.4.43, cb): প্রিন্টারের সেটিংস বাংলায় আর ইংরেজিতে, আসল ফন্টে (Roboto, Shonar Bangla, Material
/// Symbols), ৪১২dp ফোনে। ছবি `test/goldens/l10n/printer_settings_bn.png` আর `_en.png`।
///
/// <p>বাংলার ছবি আজকের পর্দাই (ARB-এর বাংলা আগের হাতে-লেখা লেখার হুবহু); ইংরেজির ছবি দেখায় লেখা কোথাও কাটে না বা উপচে পড়ে না।
/// সাথে: ইংরেজি পর্দায় কোনো লেখায় বাংলা অক্ষর নেই।
void main() {
  setUpAll(loadRealFonts);
  tearDown(() => AppLocale.current = AppLocale.locale);

  Widget app(Locale locale) => MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: realFontTheme(AppTheme.light()),
        locale: locale,
        supportedLocales: AppLocale.supported,
        localizationsDelegates: AppLocale.delegates,
        home: RepaintBoundary(
          key: const ValueKey('shot'),
          child: PrinterSettingsScreen(
            store: MemoryPrinterSettingsStore(const PrinterSettings(permissionExplained: true)),
            thermal: ThermalPrinting(
                port: FakeBluetoothPort(printers: const [BluetoothPrinter(name: 'POS-58', address: 'AA:BB')])),
          ),
        ),
      );

  Future<void> shoot(WidgetTester tester, Locale locale) async {
    tester.view.physicalSize = const Size(412, 915);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    AppLocale.current = locale;
    await tester.pumpWidget(app(locale));
    await tester.pumpAndSettle();
  }

  testWidgets('in Bangla, as it always looked', (tester) async {
    await shoot(tester, AppLocale.locale);
    expect(find.text('প্রিন্টার'), findsWidgets);
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/l10n/printer_settings_bn.png'));
  });

  testWidgets('in English, every word English', (tester) async {
    await shoot(tester, AppLocale.english);
    final bengali = RegExp('[ঀ-৲৴-৿]');
    final texts = [
      for (final w in tester.widgetList<Text>(find.byType(Text))) w.data ?? w.textSpan?.toPlainText() ?? '',
      for (final w in tester.widgetList<RichText>(find.byType(RichText))) w.text.toPlainText(),
    ];
    expect(texts.where(bengali.hasMatch), isEmpty);
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/l10n/printer_settings_en.png'));
  });
}
