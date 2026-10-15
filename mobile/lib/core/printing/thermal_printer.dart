import 'dart:ui' as ui;

import 'package:flutter/painting.dart';
import 'package:flutter/services.dart';
import 'package:image/image.dart' as img;
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:printing/printing.dart';

import 'escpos_raster.dart';

/// A paired Bluetooth printer, as Android lists it.
class BluetoothPrinter {
  const BluetoothPrinter({required this.name, required this.address});

  final String name;

  /// The MAC address — how the printer is reached, and stable across renames.
  final String address;

  String get label => name.isEmpty ? address : name;
}

/// Something the person can act on — Bluetooth off, permission refused,
/// printer out of reach — told apart from a programming fault, always in
/// Bengali.
class BluetoothPrintingUnavailable implements Exception {
  const BluetoothPrintingUnavailable(this.message, {this.permissionDenied = false});

  final String message;

  /// The person said no to "nearby devices" — the screen offers the way to
  /// Android's app settings rather than a retry that will be refused again.
  final bool permissionDenied;

  @override
  String toString() => message;
}

/// The Bluetooth side, behind one object so every screen and test can stand
/// in for it — under `flutter test` there is no native side at all.
abstract class BluetoothPort {
  /// Asks Android 12+ for "nearby devices" (BLUETOOTH_CONNECT/SCAN) if not
  /// yet given; true when allowed. Below Android 12 it is granted at install.
  Future<bool> ensurePermission();
  Future<bool> isEnabled();
  Future<List<BluetoothPrinter>> paired();
  Future<bool> connect(String address);
  Future<bool> write(List<int> bytes);
  Future<void> disconnect();

  static BluetoothPort instance = const PluginBluetoothPort();
}

/// print_bluetooth_thermal (Apache-2.0): Bluetooth classic SPP on Android.
class PluginBluetoothPort implements BluetoothPort {
  const PluginBluetoothPort();

  @override
  Future<bool> ensurePermission() =>
      PrintBluetoothThermal.isPermissionBluetoothGranted;

  @override
  Future<bool> isEnabled() => PrintBluetoothThermal.bluetoothEnabled;

  @override
  Future<List<BluetoothPrinter>> paired() async =>
      (await PrintBluetoothThermal.pairedBluetooths)
          .map((d) => BluetoothPrinter(name: d.name, address: d.macAdress))
          .toList();

  @override
  Future<bool> connect(String address) async {
    // A connection left over from a job that died half way makes the next
    // connect fail on some printers; start clean.
    if (await PrintBluetoothThermal.connectionStatus) {
      await PrintBluetoothThermal.disconnect;
    }
    return PrintBluetoothThermal.connect(macPrinterAddress: address);
  }

  @override
  Future<bool> write(List<int> bytes) => PrintBluetoothThermal.writeBytes(bytes);

  @override
  Future<void> disconnect() async {
    await PrintBluetoothThermal.disconnect;
  }
}

/// Turns PDF pages into images [dots] wide. Injected so a test can run
/// without the printing plugin's native rasteriser.
typedef PdfRasteriser = Future<List<img.Image>> Function(Uint8List pdf, int dots, int paperWidthMm);

/// The thermal road, end to end: permission → Bluetooth on → connect →
/// bitmap bytes → write → always disconnect.
class ThermalPrinting {
  ThermalPrinting({BluetoothPort? port, PdfRasteriser? rasterise})
      : port = port ?? BluetoothPort.instance,
        rasterise = rasterise ?? rasterisePdf;

  final BluetoothPort port;
  final PdfRasteriser rasterise;

  static const String permissionText =
      'ব্লুটুথ প্রিন্টারে ছাপতে ফোনের "আশেপাশের ডিভাইস" (Nearby devices) অনুমতি লাগে। '
      'অনুমতি দেওয়া হয়নি — ফোনের সেটিংস → অ্যাপ → ABOS → অনুমতি থেকে চালু করুন।';

  /// Permission and Bluetooth-on, in that order, each with its own sentence.
  /// Without the permission Android answers "Bluetooth off" to every
  /// question, which would send the person to a switch that is already on.
  Future<void> ready() async {
    bool allowed;
    try {
      allowed = await port.ensurePermission();
    } on PlatformException {
      allowed = false;
    }
    if (!allowed) {
      throw const BluetoothPrintingUnavailable(permissionText, permissionDenied: true);
    }
    bool on;
    try {
      on = await port.isEnabled();
    } on PlatformException {
      on = false;
    }
    if (!on) {
      throw const BluetoothPrintingUnavailable(
          'ফোনের ব্লুটুথ বন্ধ আছে। চালু করে আবার চেষ্টা করুন।');
    }
  }

  /// Every printer already paired with this phone. Pairing itself is done
  /// once in Android's own Bluetooth settings.
  Future<List<BluetoothPrinter>> pairedPrinters() async {
    await ready();
    try {
      return await port.paired();
    } on PlatformException {
      throw const BluetoothPrintingUnavailable(
          'জোড়া দেওয়া প্রিন্টারের তালিকা পাওয়া গেল না। আবার চেষ্টা করুন।');
    }
  }

  /// The server's 58/80 mm PDF to the printer, as a picture.
  Future<void> printPdf({
    required Uint8List pdf,
    required String address,
    required int paperWidthMm,
  }) async {
    final dots = EscPosRaster.dotsFor(paperWidthMm);
    final List<img.Image> pages;
    try {
      pages = await rasterise(pdf, dots, paperWidthMm);
    } catch (_) {
      throw const BluetoothPrintingUnavailable(
          'কাগজটা ছবিতে রূপান্তর করা গেল না। নরমাল প্রিন্টারে চেষ্টা করুন।');
    }
    if (pages.isEmpty) {
      throw const BluetoothPrintingUnavailable('কাগজটায় ছাপার মতো কোনো পাতা নেই।');
    }
    await sendBytes(EscPosRaster.receipt(pages, dots: dots), address: address);
  }

  /// A short Bengali page drawn on the phone — proves the printer, the
  /// width and the Bengali-as-picture road without fetching anything.
  Future<void> testPrint({required String address, required int paperWidthMm, img.Image? page}) async {
    final dots = EscPosRaster.dotsFor(paperWidthMm);
    final image = page ?? await drawTestPage(dots, paperWidthMm);
    await sendBytes(EscPosRaster.receipt([image], dots: dots), address: address);
  }

  Future<void> sendBytes(List<int> bytes, {required String address}) async {
    await ready();
    bool connected;
    try {
      connected = await port.connect(address);
    } on PlatformException {
      connected = false;
    }
    if (!connected) {
      throw const BluetoothPrintingUnavailable(
          'প্রিন্টারে সংযোগ হলো না। প্রিন্টার চালু আছে, কাছে আছে আর অন্য ফোনে যুক্ত নেই কিনা দেখুন।');
    }
    try {
      bool sent;
      try {
        sent = await port.write(bytes);
      } on PlatformException {
        sent = false;
      }
      if (!sent) {
        throw const BluetoothPrintingUnavailable(
            'প্রিন্টারে পাঠানো গেল না — সংযোগ কেটে গেছে। প্রিন্টারের কাগজ ও চার্জ দেখে আবার চেষ্টা করুন।');
      }
    } finally {
      // Left disconnected on every path: a held connection is a printer the
      // next phone cannot reach.
      try {
        await port.disconnect();
      } catch (_) {}
    }
  }
}

/// printing's own rasteriser (pdfium on Android) at the dpi that makes the
/// PDF page exactly as wide as the printer head, then trimmed to [dots].
Future<List<img.Image>> rasterisePdf(Uint8List pdf, int dots, int paperWidthMm) async {
  final dpi = dots * 25.4 / paperWidthMm;
  final pages = <img.Image>[];
  await for (final page in Printing.raster(pdf, dpi: dpi)) {
    pages.add(page.asImage());
  }
  return pages;
}

/// The test page: Bengali text painted by Flutter onto a white bitmap.
Future<img.Image> drawTestPage(int dots, int paperWidthMm) async {
  final recorder = ui.PictureRecorder();
  final canvas = Canvas(recorder);
  final painter = TextPainter(
    textDirection: TextDirection.ltr,
    text: TextSpan(
      style: const TextStyle(color: Color(0xFF000000), fontSize: 24, height: 1.4),
      children: [
        const TextSpan(text: 'ABOS\n', style: TextStyle(fontSize: 32, fontWeight: FontWeight.w700)),
        const TextSpan(text: 'পরীক্ষামূলক ছাপা\n'),
        TextSpan(text: 'কাগজ $paperWidthMm মিমি · $dots ডট\n'),
        const TextSpan(text: 'বাংলা লেখা ঠিকমতো এলে প্রিন্টার তৈরি।\n'),
        const TextSpan(text: '১২৩৪৫৬৭৮৯০ ৳'),
      ],
    ),
  )..layout(maxWidth: dots - 16.0);
  final height = painter.height.ceil() + 24;
  canvas.drawRect(Rect.fromLTWH(0, 0, dots.toDouble(), height.toDouble()),
      Paint()..color = const Color(0xFFFFFFFF));
  painter.paint(canvas, const Offset(8, 12));
  final picture = recorder.endRecording();
  final image = await picture.toImage(dots, height);
  final data = await image.toByteData(format: ui.ImageByteFormat.rawRgba);
  return img.Image.fromBytes(
      width: dots, height: height, bytes: data!.buffer, numChannels: 4);
}
