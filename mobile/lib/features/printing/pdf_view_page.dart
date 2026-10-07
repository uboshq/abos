import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:printing/printing.dart';

/// ⭐ কাগজটা ফোনের পর্দায় — মালিক, ৭ অক্টোবর ২০২৬: *"app e voucher approvale asle view hoyna"*। লাইভে সার্ভার PDF
/// দিয়েছিল (২০০, ১৯৪ KB), কিন্তু কাগজের শিটে দেখার কোনো পথ ছিল না — কেবল প্রিন্ট, শেয়ার আর সেভ। এখন সার্ভারের
/// সেই PDF-ই পাতা ধরে আঁকা হয়; প্রিন্ট আর শেয়ার শিটেই থাকে, তাই এখানে কেবল দেখা।
class PdfViewPage extends StatelessWidget {
  const PdfViewPage({super.key, required this.pdf, required this.title});

  final Uint8List pdf;
  final String title;

  static Future<void> open(BuildContext context, {required Uint8List pdf, required String title}) =>
      Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => PdfViewPage(pdf: pdf, title: title)));

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(title, overflow: TextOverflow.ellipsis)),
        body: PdfPreview(
          build: (_) async => pdf,
          allowPrinting: false,
          allowSharing: false,
          canChangePageFormat: false,
          canChangeOrientation: false,
          canDebug: false,
          useActions: false,
        ),
      );
}
