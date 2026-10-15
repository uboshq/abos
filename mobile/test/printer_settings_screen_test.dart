import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

import 'package:abos_mobile/core/printing/printer_settings.dart';
import 'package:abos_mobile/core/printing/thermal_printer.dart';
import 'package:abos_mobile/features/printing/printer_settings_screen.dart';

import 'fakes/fake_bluetooth_port.dart';

/// "আমি → প্রিন্টার" — pick the paired printer, the roll, print a test slip.
void main() {
  late MemoryPrinterSettingsStore store;
  late FakeBluetoothPort port;

  setUp(() {
    store = MemoryPrinterSettingsStore(
        const PrinterSettings(permissionExplained: true));
    port = FakeBluetoothPort(printers: const [
      BluetoothPrinter(name: 'POS-58', address: 'AA:BB'),
      BluetoothPrinter(name: 'XP-80', address: 'CC:DD'),
    ]);
  });

  /// The test slip is drawn by Flutter on a device; here a blank stands in,
  /// since the test harness cannot paint Bengali glyphs anyway.
  ThermalPrinting thermal() => _TestPageThermal(port);

  Widget screen() => MaterialApp(
      home: PrinterSettingsScreen(store: store, thermal: thermal()));

  testWidgets('choose a paired printer, and it is remembered', (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    expect(find.text('কোনো প্রিন্টার বাছা হয়নি'), findsOneWidget);
    await tester.tap(find.byKey(const Key('printer-choose')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('bt-printer-CC:DD')));
    await tester.pumpAndSettle();

    expect(store.settings.printerAddress, 'CC:DD');
    expect(store.settings.printerName, 'XP-80');
    expect(find.text('XP-80'), findsOneWidget);
  });

  testWidgets('the roll width is 58 by default and can be set to 80',
      (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    expect(store.settings.widthMm, 58);
    await tester.tap(find.text('৮০ মিমি'));
    await tester.pumpAndSettle();
    expect(store.settings.widthMm, 80);
    expect(store.settings.thermalPaper, '80mm');
  });

  testWidgets('test print goes to the chosen printer at its width',
      (tester) async {
    store.settings = store.settings
        .copyWith(printerAddress: 'AA:BB', printerName: 'POS-58', widthMm: 80);
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('printer-test')));
    await tester.pumpAndSettle();

    expect(port.connectedTo, 'AA:BB');
    // 80 mm → 72 bytes a row.
    expect(port.sent.single.sublist(2, 6), [0x1D, 0x76, 0x30, 0x00]);
    expect(port.sent.single[6], 72);
    expect(find.byKey(const Key('printer-note')), findsOneWidget);
    expect(find.textContaining('পাঠানো হয়েছে'), findsOneWidget);
  });

  testWidgets('test print is off until a printer is chosen', (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    final button = tester.widget<ButtonStyleButton>(
        find.byKey(const Key('printer-test')));
    expect(button.onPressed, isNull);
  });

  testWidgets('permission refused while choosing is said in Bengali',
      (tester) async {
    port.permission = false;
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('printer-choose')));
    await tester.pumpAndSettle();

    expect(find.textContaining('আশেপাশের ডিভাইস'), findsOneWidget);
    expect(store.settings.hasPrinter, isFalse);
  });

  testWidgets('a printer can be forgotten', (tester) async {
    store.settings = store.settings.copyWith(printerAddress: 'AA:BB');
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('printer-forget')));
    await tester.pumpAndSettle();
    expect(store.settings.hasPrinter, isFalse);
  });

  test('settings survive the round trip through storage', () {
    const s = PrinterSettings(
      printerAddress: 'AA:BB',
      printerName: 'POS',
      widthMm: 80,
      roads: {'Voucher': PrintRoad.normal, 'SalesInvoice': PrintRoad.thermal},
      normalPapers: {'Voucher': 'a5'},
      permissionExplained: true,
    );
    final back = PrinterSettings.fromMap(s.toMap());
    expect(back.printerAddress, 'AA:BB');
    expect(back.widthMm, 80);
    expect(back.roads['SalesInvoice'], PrintRoad.thermal);
    expect(back.normalPapers['Voucher'], 'a5');
    expect(back.permissionExplained, isTrue);
    expect(PrinterSettings.fromMap(null).widthMm, 58);
  });
}

class _TestPageThermal extends ThermalPrinting {
  _TestPageThermal(BluetoothPort port) : super(port: port);

  @override
  Future<void> testPrint(
          {required String address, required int paperWidthMm, img.Image? page}) =>
      super.testPrint(
          address: address,
          paperWidthMm: paperWidthMm,
          page: page ?? img.Image(width: paperWidthMm >= 80 ? 576 : 384, height: 4));
}
