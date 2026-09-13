import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/printing/document_delivery.dart';
import 'package:abos_mobile/features/printing/document_actions_sheet.dart';

/// The print/share sheet, driven the way a person drives it.
///
/// <p>Every road out of this sheet is a platform channel with no native side
/// under `flutter test` — the system print dialog, the share sheet, the file
/// system, Bluetooth. So they are injected, and what is tested is the part
/// that actually decides anything: which paper was asked for, which road the
/// bytes went down, and which choices are offered at all.
void main() {
  final pdfBytes = Uint8List.fromList(const [37, 80, 68, 70]); // "%PDF"

  Widget sheet({
    List<String> papers = const ['a4', '80mm'],
    Future<Uint8List> Function(String)? loadPdf,
    DocumentDeliveryPorts? delivery,
  }) =>
      MaterialApp(
        home: Scaffold(
          body: DocumentActionsSheet(
            type: 'sales.invoice',
            id: '01a0c3f0-0000-7000-8000-0000000000b2',
            title: 'বিক্রয় বিল · SI-2609-0031',
            fileStem: 'SI-2609-0031',
            loadPapers: () async => papers,
            loadPdf: loadPdf ?? (_) async => pdfBytes,
            delivery: delivery ?? const DocumentDeliveryPorts(),
          ),
        ),
      );

  testWidgets('the papers offered are the ones the server allows',
      (tester) async {
    await tester.pumpWidget(sheet(papers: ['a4', '58mm']));
    await tester.pumpAndSettle();

    expect(find.text('A4 কাগজ'), findsOneWidget);
    expect(find.text('রসিদ ৫৮mm'), findsOneWidget);
    // Never offered, because this template cannot make it — a hardcoded list
    // on the phone would hand somebody a metre of unreadable paper.
    expect(find.text('রসিদ ৮০mm'), findsNothing);
  });

  testWidgets('a template with no thermal paper offers no Bluetooth printer',
      (tester) async {
    await tester.pumpWidget(sheet(papers: ['a4']));
    await tester.pumpAndSettle();

    expect(find.text('প্রিন্ট করুন'), findsOneWidget);
    expect(find.text('শেয়ার করুন'), findsOneWidget);
    // An A4 invoice sent to a receipt printer is not a thing anybody wants.
    expect(find.text('ব্লুটুথ রসিদ প্রিন্টার'), findsNothing);
  });

  testWidgets('choosing a receipt roll brings out the Bluetooth road',
      (tester) async {
    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();

    expect(find.text('ব্লুটুথ রসিদ প্রিন্টার'), findsNothing);

    await tester.tap(find.text('রসিদ ৮০mm'));
    await tester.pumpAndSettle();

    expect(find.text('ব্লুটুথ রসিদ প্রিন্টার'), findsOneWidget);
  });

  testWidgets('printing asks for the paper that is selected', (tester) async {
    var askedFor = '';
    var printed = Uint8List(0);

    await tester.pumpWidget(sheet(
      loadPdf: (paper) async {
        askedFor = paper;
        return pdfBytes;
      },
      delivery: DocumentDeliveryPorts(
        systemPrint: ({required pdf, required documentName}) async {
          printed = pdf;
          return true;
        },
      ),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('রসিদ ৮০mm'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('প্রিন্ট করুন'));
    await tester.pumpAndSettle();

    expect(askedFor, '80mm',
        reason: 'the sheet must fetch the paper the person chose — printing '
            'an A4 render on a receipt roll is the whole failure this avoids');
    expect(printed, pdfBytes);
  });

  testWidgets('a shared file carries the document number as its name',
      (tester) async {
    var sharedAs = '';

    await tester.pumpWidget(sheet(
      delivery: DocumentDeliveryPorts(
        sharePdf: ({required pdf, required fileName, subject}) async {
          sharedAs = fileName;
        },
      ),
    ));
    await tester.pumpAndSettle();
    await tester.tap(find.text('শেয়ার করুন'));
    await tester.pumpAndSettle();

    // A shopkeeper sent three files called document.pdf cannot tell which
    // bill is which.
    expect(sharedAs, 'SI-2609-0031.pdf');
  });

  testWidgets('no paired printer sends the person to the phone settings',
      (tester) async {
    await tester.pumpWidget(sheet(
      delivery: DocumentDeliveryPorts(pairedPrinters: () async => const []),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('রসিদ ৮০mm'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ব্লুটুথ রসিদ প্রিন্টার'));
    await tester.pumpAndSettle();

    expect(find.textContaining('জোড়া দেওয়া নেই'), findsOneWidget);
  });

  testWidgets('bluetooth switched off is said plainly, not as a failure',
      (tester) async {
    await tester.pumpWidget(sheet(
      delivery: DocumentDeliveryPorts(
        pairedPrinters: () async => throw const BluetoothPrintingUnavailable(
            'ব্লুটুথ বন্ধ আছে। চালু করে আবার চেষ্টা করুন।'),
      ),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('রসিদ ৮০mm'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ব্লুটুথ রসিদ প্রিন্টার'));
    await tester.pumpAndSettle();

    expect(find.textContaining('ব্লুটুথ বন্ধ আছে'), findsOneWidget);
  });

  testWidgets('one paired printer needs no choosing', (tester) async {
    var sentWidth = 0;

    await tester.pumpWidget(sheet(
      delivery: DocumentDeliveryPorts(
        pairedPrinters: () async =>
            const [BluetoothPrinter(name: 'POS-58', address: 'AA:BB')],
        bluetoothPrint: ({required pdf, required printer, paperWidthMm = 58}) async {
          sentWidth = paperWidthMm;
        },
      ),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.text('রসিদ ৮০mm'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ব্লুটুথ রসিদ প্রিন্টার'));
    await tester.pumpAndSettle();

    // The width follows the chosen paper, not a default — an 80mm render
    // squeezed onto 58mm loses the right edge of every line.
    expect(sentWidth, 80);
    expect(find.textContaining('পাঠানো হয়েছে'), findsOneWidget);
  });
}
