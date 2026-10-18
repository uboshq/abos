import 'paper_files.dart';
import 'dart:io';
import 'dart:typed_data';

import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

export 'thermal_printer.dart' show BluetoothPrinter, BluetoothPrintingUnavailable;

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
/// server rendered is rasterised on the phone and sent as a bitmap
/// (thermal_printer.dart, escpos_raster.dart). That
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
    final dir = await PaperFiles.kept();
    final file = File('${dir.path}/${PaperFiles.safeName(fileName)}');
    await file.writeAsBytes(pdf, flush: true);
    return file.path;
  }

  static Future<File> _writeToCache(Uint8List bytes, String fileName) async {
    final dir = await PaperFiles.cache();
    final file = File('${dir.path}/${PaperFiles.safeName(fileName)}');
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }
}
