import 'dart:io';
import 'dart:typed_data';

import 'package:esc_pos_utils_plus/esc_pos_utils_plus.dart' as escpos;
import 'package:image/image.dart' as img;
import 'package:path_provider/path_provider.dart';
import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

/// Gets a finished document out of the phone and onto paper, or to a person.
///
/// <p><b>The document is never built here.</b> `app/Core/Engines/Print`
/// renders it — mPDF, and it already knows all three papers (A4, 80mm, 58mm),
/// the company logo, VAT, the amount in words, the terms, and Bangla
/// typography. This file takes the bytes it is handed and delivers them.
///
/// <p>Laying a challan out a second time in Dart would give two documents
/// that agree until the day they do not, and the disagreement would first
/// appear on a customer's copy — the most expensive place to find it, and the
/// exact shape of the bug this codebase spent 12 September removing.
///
/// <p><b>Two roads to paper, because shops have two kinds of printer.</b>
///
/// <p>A printer Android can see — WiFi, USB, network, anything with a print
/// service — takes the PDF unchanged, through the system print dialog, and
/// the person picks the printer the way they do in every other app.
///
/// <p>A cheap Bluetooth receipt printer cannot: it speaks ESC/POS over a
/// serial link and has no print service at all. ⭐ So the same 58/80mm PDF the
/// server rendered is rasterised on the phone and sent as a bitmap. That
/// keeps one layout for both roads. The alternative — rebuilding the receipt
/// from data in ESC/POS text commands — is a second layout that would drift
/// from the first.
class DocumentDelivery {
  const DocumentDelivery._();

  /// The system print dialog: WiFi, USB, network, save-to-PDF, whatever the
  /// phone has. Returns false when the person backed out, which is not an
  /// error and must not be reported as one.
  static Future<bool> toSystemPrinter({
    required Uint8List pdf,
    required String documentName,
  }) =>
      Printing.layoutPdf(
        onLayout: (_) async => pdf,
        name: documentName,
      );

  /// WhatsApp, email, Drive — the sheet the phone already has.
  ///
  /// <p>The file is written under a real name first. A share that arrives as
  /// `document.pdf` tells the person receiving it nothing, and a shopkeeper
  /// sent three of those cannot tell which bill is which.
  static Future<void> share({
    required Uint8List pdf,
    required String fileName,
    String? subject,
  }) async {
    final file = await _writeToCache(pdf, fileName);
    await Share.shareXFiles(
      [XFile(file.path, mimeType: 'application/pdf', name: fileName)],
      subject: subject,
    );
  }

  /// Keeps a copy on the phone and hands back where it went, so a screen can
  /// say the path rather than "saved" into the void.
  static Future<String> save({
    required Uint8List pdf,
    required String fileName,
  }) async {
    final dir = await getApplicationDocumentsDirectory();
    final file = File('${dir.path}/$fileName');
    await file.writeAsBytes(pdf, flush: true);
    return file.path;
  }

  /// Every Bluetooth printer already paired with this phone.
  ///
  /// <p>Pairing itself stays in Android's own settings — deliberately. A
  /// shop's printer is paired once, by whoever set the counter up, and an
  /// app that runs its own scan-and-pair flow is a second place for that to
  /// go wrong for no gain.
  static Future<List<BluetoothPrinter>> pairedPrinters() async {
    if (!await PrintBluetoothThermal.bluetoothEnabled) {
      throw const BluetoothPrintingUnavailable(
        'ব্লুটুথ বন্ধ আছে। চালু করে আবার চেষ্টা করুন।',
      );
    }

    final paired = await PrintBluetoothThermal.pairedBluetooths;
    return paired
        .map((device) =>
            BluetoothPrinter(name: device.name, address: device.macAdress))
        .toList();
  }

  /// Sends the server's own thermal PDF to a Bluetooth receipt printer.
  ///
  /// <p>[paperWidthMm] must match the paper the server rendered — ask for a
  /// 58mm document and print it 58mm. Rendering A4 to a receipt printer
  /// produces a metre of unreadable paper, so the caller is expected to have
  /// fetched the right one rather than this file rescaling something that
  /// was never meant for it.
  static Future<void> toBluetoothPrinter({
    required Uint8List pdf,
    required BluetoothPrinter printer,
    int paperWidthMm = 58,
  }) async {
    final connected =
        await PrintBluetoothThermal.connect(macPrinterAddress: printer.address);
    if (!connected) {
      throw const BluetoothPrintingUnavailable(
        'প্রিন্টারে সংযোগ হয়নি। যন্ত্রটি চালু আছে কিনা দেখুন।',
      );
    }

    try {
      final profile = await escpos.CapabilityProfile.load();
      final size = paperWidthMm >= 80
          ? escpos.PaperSize.mm80
          : escpos.PaperSize.mm58;
      final generator = escpos.Generator(size, profile);

      final bytes = <int>[];
      // 203 dpi is what these printers actually are; rasterising finer only
      // makes a larger bitmap for the same dots on paper, over a slow link.
      await for (final page in Printing.raster(pdf, dpi: 203)) {
        final image = page.asImage();
        bytes.addAll(generator.image(_toPrinterWidth(image, size)));
      }
      bytes.addAll(generator.cut());

      await PrintBluetoothThermal.writeBytes(bytes);
    } finally {
      // Left disconnected on every path. A held connection is a printer the
      // next person's phone cannot reach, and in a shop that reads as broken.
      await PrintBluetoothThermal.disconnect;
    }
  }

  /// ESC/POS raster width is fixed by the head: 384 dots on 58mm, 576 on
  /// 80mm. A wider bitmap is silently truncated by the printer — the right
  /// edge of every line simply missing — so it is scaled here instead.
  static img.Image _toPrinterWidth(img.Image image, escpos.PaperSize size) {
    final dots = size == escpos.PaperSize.mm80 ? 576 : 384;
    if (image.width == dots) return image;
    return img.copyResize(image, width: dots);
  }

  static Future<File> _writeToCache(Uint8List bytes, String fileName) async {
    final dir = await getTemporaryDirectory();
    final file = File('${dir.path}/$fileName');
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }
}

class BluetoothPrinter {
  const BluetoothPrinter({required this.name, required this.address});

  final String name;

  /// The MAC address — how the printer is reached, and stable across
  /// renames, so it is what a remembered "default printer" should be keyed on.
  final String address;
}

/// Bluetooth is off, or the printer did not answer — something the person can
/// act on, told apart from a programming fault so a screen can say which.
class BluetoothPrintingUnavailable implements Exception {
  const BluetoothPrintingUnavailable(this.message);

  final String message;

  @override
  String toString() => message;
}
