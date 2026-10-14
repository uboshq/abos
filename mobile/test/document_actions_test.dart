import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

import 'package:abos_mobile/core/printing/printer_settings.dart';
import 'package:abos_mobile/core/printing/thermal_printer.dart';
import 'package:abos_mobile/features/printing/document_actions_sheet.dart';

import 'fakes/fake_bluetooth_port.dart';

/// The print sheet — two printers (owner, 14 Oct 2026: "mobile app theke
/// thermal & normal print zate dewa zay"), driven the way a person drives it.
///
/// <p>Every road out is a platform channel with no native side under
/// `flutter test`, so each is injected; what is tested is what decides
/// anything — which paper is fetched, which road the bytes take, what the
/// person is told when it fails.
void main() {
  final pdfBytes = Uint8List.fromList(const [37, 80, 68, 70]); // "%PDF"

  late MemoryPrinterSettingsStore store;
  late FakeBluetoothPort port;
  late List<int> rasterisedAt;

  setUp(() {
    store = MemoryPrinterSettingsStore(
        const PrinterSettings(permissionExplained: true));
    port = FakeBluetoothPort();
    rasterisedAt = [];
  });

  ThermalPrinting thermal() => ThermalPrinting(
        port: port,
        rasterise: (pdf, dots, mm) async {
          rasterisedAt.add(dots);
          return [img.Image(width: dots, height: 10)];
        },
      );

  Widget sheet({
    List<String> papers = const ['a4', 'a5', '80mm', '58mm'],
    Future<Uint8List> Function(String)? loadPdf,
    DocumentDeliveryPorts? delivery,
  }) =>
      MaterialApp(
        home: Scaffold(
          body: DocumentActionsSheet(
            type: 'SalesInvoice',
            id: '01a0c3f0-0000-7000-8000-0000000000b2',
            title: 'বিক্রয় বিল · SI-2609-0031',
            fileStem: 'SI-2609-0031',
            loadPapers: () async => papers,
            loadPdf: loadPdf ?? (_) async => pdfBytes,
            delivery: delivery ?? const DocumentDeliveryPorts(),
            thermal: thermal(),
            settingsStore: store,
          ),
        ),
      );

  Future<void> pressThermal(WidgetTester tester) async {
    await tester.tap(find.byKey(const Key('road-thermal')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('print-thermal')));
    await tester.pumpAndSettle();
  }

  testWidgets('the sheet offers both printers, normal first', (tester) async {
    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();

    expect(find.text('নরমাল প্রিন্টার'), findsOneWidget);
    expect(find.text('থার্মাল প্রিন্টার'), findsOneWidget);
    expect(find.byKey(const Key('print-normal')), findsOneWidget);
    expect(find.text('A4 কাগজ'), findsOneWidget);
    expect(find.text('A5 কাগজ'), findsOneWidget);
    // Rolls belong to the thermal side, not among the sheet sizes.
    expect(find.text('রসিদ ৮০mm'), findsNothing);
  });

  testWidgets('a paper with no roll design cannot go to the thermal printer',
      (tester) async {
    await tester.pumpWidget(sheet(papers: ['a4']));
    await tester.pumpAndSettle();

    expect(find.text('এই কাগজের রসিদ নকশা নেই'), findsOneWidget);
    await tester.tap(find.byKey(const Key('road-thermal')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('print-thermal')), findsNothing);
    expect(find.byKey(const Key('print-normal')), findsOneWidget);
  });

  testWidgets('normal print fetches the chosen sheet and opens the system dialog',
      (tester) async {
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

    await tester.tap(find.text('A5 কাগজ'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('print-normal')));
    await tester.pumpAndSettle();

    expect(askedFor, 'a5');
    expect(printed, pdfBytes);
    expect(port.calls, isEmpty, reason: 'the normal road never touches Bluetooth');
    // Remembered for the next bill.
    expect(store.settings.roads['SalesInvoice'], PrintRoad.normal);
    expect(store.settings.normalPapers['SalesInvoice'], 'a5');
  });

  testWidgets('thermal print fetches the roll of the printer width and sends a bitmap',
      (tester) async {
    store.settings = store.settings
        .copyWith(printerAddress: 'AA:BB', printerName: 'POS-80', widthMm: 80);
    var askedFor = '';

    await tester.pumpWidget(sheet(loadPdf: (paper) async {
      askedFor = paper;
      return pdfBytes;
    }));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('road-thermal')));
    await tester.pumpAndSettle();
    expect(find.text('POS-80'), findsOneWidget);
    await tester.tap(find.byKey(const Key('print-thermal')));
    await tester.pumpAndSettle();

    expect(askedFor, '80mm');
    expect(rasterisedAt, [576]);
    expect(port.connectedTo, 'AA:BB');
    expect(port.sent, hasLength(1));
    // GS v 0 inside the job: the receipt went as a picture, not as text.
    expect(_indexOf(port.sent.single, const [0x1D, 0x76, 0x30, 0x00]),
        greaterThanOrEqualTo(0));
    expect(port.calls.last, 'disconnect');
    expect(find.textContaining('পাঠানো হয়েছে'), findsOneWidget);
    expect(store.settings.roads['SalesInvoice'], PrintRoad.thermal);
  });

  testWidgets('the remembered road opens on the next sheet', (tester) async {
    store.settings = store.settings.copyWith(
        roads: {'SalesInvoice': PrintRoad.thermal},
        printerAddress: 'AA:BB',
        printerName: 'POS-58');

    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('print-thermal')), findsOneWidget);
    expect(find.byKey(const Key('print-normal')), findsNothing);
  });

  testWidgets('first thermal print picks the only paired printer and remembers it',
      (tester) async {
    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('road-thermal')));
    await tester.pumpAndSettle();
    expect(find.text('প্রিন্টার বাছা হয়নি'), findsOneWidget);
    await tester.tap(find.byKey(const Key('print-thermal')));
    await tester.pumpAndSettle();

    expect(store.settings.printerAddress, 'AA:BB');
    expect(rasterisedAt, [384]);
    expect(port.sent, hasLength(1));
  });

  testWidgets('two paired printers: the person chooses', (tester) async {
    port.printers = const [
      BluetoothPrinter(name: 'POS-58', address: 'AA:BB'),
      BluetoothPrinter(name: 'Counter-2', address: 'CC:DD'),
    ];
    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();
    await pressThermal(tester);

    expect(find.text('প্রিন্টার বাছুন'), findsOneWidget);
    await tester.tap(find.byKey(const Key('bt-printer-CC:DD')));
    await tester.pumpAndSettle();

    expect(store.settings.printerAddress, 'CC:DD');
    expect(port.connectedTo, 'CC:DD');
  });

  testWidgets('no paired printer says where to pair it', (tester) async {
    port.printers = const [];
    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();
    await pressThermal(tester);

    expect(find.textContaining('জোড়া দেওয়া নেই'), findsOneWidget);
    expect(port.sent, isEmpty);
  });

  testWidgets('permission refused is said in Bengali and nothing is sent',
      (tester) async {
    port.permission = false;
    store.settings = store.settings.copyWith(printerAddress: 'AA:BB');
    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();
    await pressThermal(tester);

    expect(find.textContaining('আশেপাশের ডিভাইস'), findsOneWidget);
    expect(port.calls, isNot(contains('connect')));
    expect(port.sent, isEmpty);
  });

  testWidgets('the Bengali explanation comes before Android asks, once',
      (tester) async {
    store.settings = const PrinterSettings(printerAddress: 'AA:BB');
    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();
    await pressThermal(tester);

    expect(find.byKey(const Key('bt-explain')), findsOneWidget);
    expect(port.calls, isEmpty,
        reason: 'nothing is asked before the person has read why');
    await tester.tap(find.byKey(const Key('bt-explain-ok')));
    await tester.pumpAndSettle();

    expect(store.settings.permissionExplained, isTrue);
    expect(port.sent, hasLength(1));
  });

  testWidgets('a PDF that cannot be fetched is said, and nothing is printed',
      (tester) async {
    var printed = false;
    await tester.pumpWidget(sheet(
      loadPdf: (_) async => throw DioException(
          requestOptions: RequestOptions(path: '/documents'),
          type: DioExceptionType.connectionError),
      delivery: DocumentDeliveryPorts(
        systemPrint: ({required pdf, required documentName}) async =>
            printed = true,
      ),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('print-normal')));
    await tester.pumpAndSettle();

    expect(printed, isFalse);
    expect(find.byKey(const Key('print-note')), findsOneWidget);
    expect(find.textContaining('সংযোগ নেই'), findsOneWidget);
  });

  testWidgets('a server failure fetching the PDF says the paper was not printed',
      (tester) async {
    store.settings = store.settings.copyWith(printerAddress: 'AA:BB');
    await tester.pumpWidget(sheet(
      loadPdf: (_) async => throw DioException(
          requestOptions: RequestOptions(path: '/documents'),
          response: Response(
              requestOptions: RequestOptions(path: '/documents'),
              statusCode: 500),
          type: DioExceptionType.badResponse),
    ));
    await tester.pumpAndSettle();
    await pressThermal(tester);

    expect(find.textContaining('ছাপা হয়নি'), findsOneWidget);
    expect(port.sent, isEmpty);
  });

  testWidgets('an empty PDF is a failure, not a blank print', (tester) async {
    store.settings = store.settings.copyWith(printerAddress: 'AA:BB');
    await tester.pumpWidget(sheet(loadPdf: (_) async => Uint8List(0)));
    await tester.pumpAndSettle();
    await pressThermal(tester);

    expect(find.textContaining('আনা গেল না'), findsOneWidget);
    expect(port.sent, isEmpty);
  });

  testWidgets('a printer that does not answer is said plainly', (tester) async {
    port.connects = false;
    store.settings = store.settings.copyWith(printerAddress: 'AA:BB');
    await tester.pumpWidget(sheet());
    await tester.pumpAndSettle();
    await pressThermal(tester);

    expect(find.textContaining('সংযোগ হলো না'), findsOneWidget);
  });

  testWidgets('a shared file carries the paper number as its name',
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
    await tester.tap(find.byKey(const Key('document-share')));
    await tester.pumpAndSettle();

    expect(sharedAs, 'SI-2609-0031.pdf');
  });

  testWidgets('the paper can be looked at on the screen (owner, 7 Oct 2026: "view hoyna")',
      (tester) async {
    String? asked;
    Uint8List? shown;
    await tester.pumpWidget(sheet(
      loadPdf: (paper) async {
        asked = paper;
        return pdfBytes;
      },
      delivery: DocumentDeliveryPorts(
          viewPdf: (context, {required pdf, required title}) async =>
              shown = pdf),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('document-view')));
    await tester.pumpAndSettle();
    expect(asked, 'a4');
    expect(shown, pdfBytes);
  });
}

int _indexOf(List<int> haystack, List<int> needle) {
  for (var i = 0; i + needle.length <= haystack.length; i++) {
    var ok = true;
    for (var j = 0; j < needle.length; j++) {
      if (haystack[i + j] != needle[j]) {
        ok = false;
        break;
      }
    }
    if (ok) return i;
  }
  return -1;
}
