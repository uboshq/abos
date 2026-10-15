import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

import 'package:abos_mobile/core/printing/escpos_raster.dart';
import 'package:abos_mobile/core/printing/thermal_printer.dart';

import 'fakes/fake_bluetooth_port.dart';

/// The bytes that reach the receipt printer, checked one by one — a wrong
/// header byte prints a metre of noise, and nobody at a counter can tell why.
void main() {
  img.Image white(int w, int h) =>
      img.Image(width: w, height: h)..clear(img.ColorRgb8(255, 255, 255));

  test('the head width is 384 dots on 58 mm and 576 on 80 mm', () {
    expect(EscPosRaster.dotsFor(58), 384);
    expect(EscPosRaster.dotsFor(80), 576);
  });

  test('GS v 0 header: width in bytes and height in dots, little-endian', () {
    expect(EscPosRaster.rasterHeader(widthBytes: 48, height: 128),
        [0x1D, 0x76, 0x30, 0x00, 48, 0, 128, 0]);
    expect(EscPosRaster.rasterHeader(widthBytes: 72, height: 300),
        [0x1D, 0x76, 0x30, 0x00, 72, 0, 300 & 0xFF, 1]);
  });

  test('a 58 mm job: reset, bitmap 48 bytes wide, feed, cut', () {
    final bytes = EscPosRaster.receipt([white(384, 10)], dots: 384);

    expect(bytes.sublist(0, 2), [0x1B, 0x40]);
    expect(bytes.sublist(2, 10), [0x1D, 0x76, 0x30, 0x00, 48, 0, 10, 0]);
    expect(bytes.length, 2 + 8 + 48 * 10 + 3 + 4);
    expect(bytes.sublist(bytes.length - 7), [0x1B, 0x64, 4, 0x1D, 0x56, 0x42, 0x00]);
  });

  test('an 80 mm job is 72 bytes wide and a wider page is scaled down to fit', () {
    final bytes = EscPosRaster.receipt([white(1152, 20)], dots: 576);
    // 1152 → 576 halves the height too.
    expect(bytes.sublist(2, 10), [0x1D, 0x76, 0x30, 0x00, 72, 0, 10, 0]);
    expect(bytes.length, 2 + 8 + 72 * 10 + 3 + 4);
  });

  test('a long receipt is sent in bands the printer buffer can hold', () {
    final bytes = EscPosRaster.bitmap(white(384, 300), dots: 384);
    // 128 + 128 + 44 rows → three headers.
    expect(bytes.length, 3 * 8 + 48 * 300);
    expect(bytes.sublist(0, 8), [0x1D, 0x76, 0x30, 0x00, 48, 0, 128, 0]);
    expect(bytes.sublist(8 + 48 * 128, 16 + 48 * 128),
        [0x1D, 0x76, 0x30, 0x00, 48, 0, 128, 0]);
    expect(bytes.sublist(16 + 48 * 256, 24 + 48 * 256),
        [0x1D, 0x76, 0x30, 0x00, 48, 0, 44, 0]);
  });

  test('black ink burns, white paper and transparency do not', () {
    final page = white(384, 1)
      ..setPixel(0, 0, img.ColorRgb8(0, 0, 0))
      ..setPixel(9, 0, img.ColorRgb8(10, 10, 10));
    final bytes = EscPosRaster.bitmap(page, dots: 384);
    final row = bytes.sublist(8);
    expect(row[0], 0x80, reason: 'leftmost dot is the most significant bit');
    expect(row[1], 0x40);
    expect(row.skip(2).every((b) => b == 0), isTrue);

    final clear = img.Image(width: 384, height: 1, numChannels: 4); // all alpha 0
    expect(EscPosRaster.bitmap(clear, dots: 384).skip(8).every((b) => b == 0),
        isTrue,
        reason: 'a transparent PDF background must print as paper, not a black bar');
  });

  group('ThermalPrinting', () {
    test('permission refused: nothing connects, the sentence is Bengali', () async {
      final port = FakeBluetoothPort(permission: false);
      final printing = ThermalPrinting(
          port: port, rasterise: (_, dots, __) async => [white(dots, 2)]);

      await expectLater(
        printing.testPrint(address: 'AA:BB', paperWidthMm: 58, page: white(384, 2)),
        throwsA(isA<BluetoothPrintingUnavailable>()
            .having((e) => e.permissionDenied, 'permissionDenied', isTrue)
            .having((e) => e.message, 'message', contains('অনুমতি'))),
      );
      expect(port.calls, ['permission']);
    });

    test('Bluetooth off is told apart from a refused permission', () async {
      final port = FakeBluetoothPort(enabled: false);
      final printing = ThermalPrinting(port: port);
      await expectLater(
        printing.pairedPrinters(),
        throwsA(isA<BluetoothPrintingUnavailable>()
            .having((e) => e.message, 'message', contains('ব্লুটুথ বন্ধ'))),
      );
    });

    test('a failed write is an error and the printer is still let go', () async {
      final port = FakeBluetoothPort(writes: false);
      final printing = ThermalPrinting(port: port);
      await expectLater(
        printing.testPrint(address: 'AA:BB', paperWidthMm: 80, page: white(576, 2)),
        throwsA(isA<BluetoothPrintingUnavailable>()),
      );
      expect(port.calls.last, 'disconnect');
    });

    test('a PDF is rasterised at the head width of the roll', () async {
      final port = FakeBluetoothPort();
      final asked = <List<int>>[];
      final printing = ThermalPrinting(
          port: port,
          rasterise: (_, dots, mm) async {
            asked.add([dots, mm]);
            return [white(dots, 3)];
          });
      await printing.printPdf(
          pdf: Uint8List.fromList(const [1, 2, 3]),
          address: 'AA:BB',
          paperWidthMm: 58);
      expect(asked, [
        [384, 58]
      ]);
      expect(port.sent.single.sublist(2, 10), [0x1D, 0x76, 0x30, 0x00, 48, 0, 3, 0]);
    });
  });
}
