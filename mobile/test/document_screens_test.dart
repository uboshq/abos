import 'dart:typed_data';

import 'package:abos_mobile/core/documents_library/library_api.dart';
import 'package:abos_mobile/features/documents/document_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ ডকুমেন্টস, ফোনে — তালিকা, কাগজ, ফাইল আর সই (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)। ফোন কিছু ঠিক করে না: "নামান" কেবল
/// নামানোর অধিকারে, "সই দিন" কেবল সার্ভার অনুমোদনের আইডি দিলে, আর সই যায় অনুমোদনের দরজায়।
class _FakeLibrary implements DocumentLibraryApi {
  _FakeLibrary({this.approvalId, this.download = false, this.state = 'pending'});

  final String? approvalId;
  final bool download;
  final String state;
  final List<String> asked = [];
  final List<String> signed = [];
  final List<String> refused = [];
  final List<bool> files = [];

  @override
  Future<LibraryPage> list({String view = 'center', String q = '', int page = 1}) async {
    asked.add('$view|$q');
    return const LibraryPage([
      LibraryDocument(id: 'doc1', no: 'DOC-0001', name: 'ডিলার চুক্তি', typeName: 'চুক্তিপত্র', folderName: 'চুক্তি', level: 'confidential'),
    ]);
  }

  @override
  Future<LibraryDocument> one(String id) async => LibraryDocument(
        id: id,
        no: 'DOC-0001',
        name: 'ডিলার চুক্তি',
        level: 'confidential',
        status: 'published',
        file: LibraryFile(name: 'deal.pdf', mime: 'application/pdf', version: '1.0', inline: true, download: download),
        signature: SignatureState(state: signed.isEmpty ? state : 'signed', approvalId: signed.isEmpty ? approvalId : null),
      );

  @override
  Future<Uint8List> file(String id, {bool download = false}) async {
    files.add(download);
    return Uint8List.fromList([37, 80, 68, 70]);
  }

  @override
  Future<void> sign(String approvalId, {String? remarks}) async => signed.add(approvalId);

  @override
  Future<void> refuse(String approvalId, {required String remarks}) async => refused.add('$approvalId:$remarks');
}

void main() {
  testWidgets('the list shows the paper with its secrecy and the views ask the server', (tester) async {
    final api = _FakeLibrary();
    await tester.pumpWidget(MaterialApp(home: DocumentListScreen(api: api)));
    await tester.pumpAndSettle();

    expect(find.text('ডিলার চুক্তি'), findsOneWidget);
    expect(find.textContaining('গোপন'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('tab-shared')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'shared|');

    await tester.enterText(find.byKey(const ValueKey('doc-search')), 'চুক্তি');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.asked.last, 'shared|চুক্তি');
  });

  testWidgets('a signer sees the sign card and the signature goes to the approval door', (tester) async {
    final api = _FakeLibrary(approvalId: 'appr-1');
    await tester.pumpWidget(MaterialApp(home: DocumentScreen(id: 'doc1', api: api)));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('doc-sign-card')), findsOneWidget);
    expect(find.text('সইয়ের অপেক্ষায়'), findsOneWidget);

    await tester.scrollUntilVisible(find.byKey(const ValueKey('doc-sign')), 200);
    await tester.tap(find.byKey(const ValueKey('doc-sign')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('sign-confirm')));
    await tester.pumpAndSettle();

    expect(api.signed, ['appr-1']);
    expect(find.byKey(const ValueKey('doc-sign-card')), findsNothing, reason: 'after signing the paper reloads and the door closes');
    expect(find.text('সই হয়েছে — চলতি ভার্সনে'), findsOneWidget);
  });

  testWidgets('refusing needs a reason', (tester) async {
    final api = _FakeLibrary(approvalId: 'appr-1');
    await tester.pumpWidget(MaterialApp(home: DocumentScreen(id: 'doc1', api: api)));
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(find.byKey(const ValueKey('doc-refuse')), 200);
    await tester.tap(find.byKey(const ValueKey('doc-refuse')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('refuse-confirm')));
    await tester.pumpAndSettle();
    expect(api.refused, isEmpty);
    expect(find.text('সই না দেওয়ার কারণ লিখতে হবে।'), findsOneWidget);
  });

  testWidgets('nobody else sees a sign card, and download shows only with its right', (tester) async {
    final api = _FakeLibrary();
    LibraryFile? opened;
    await tester.pumpWidget(MaterialApp(
        home: DocumentScreen(
            id: 'doc1',
            api: api,
            opener: (context, file, bytes, title) async => opened = file)));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('doc-sign-card')), findsNothing);
    expect(find.byKey(const ValueKey('doc-download')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('doc-view')));
    await tester.pumpAndSettle();
    expect(api.files, [false], reason: 'viewing asks for the preview, never the download');
    expect(opened?.name, 'deal.pdf');
  });

  testWidgets('with the download right the button asks for the download', (tester) async {
    final api = _FakeLibrary(download: true, state: 'none');
    await tester.pumpWidget(MaterialApp(
        home: DocumentScreen(id: 'doc1', api: api, opener: (context, file, bytes, title) async {})));
    await tester.pumpAndSettle();

    expect(find.text('সই চাওয়া হয়নি'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('doc-download')));
    await tester.pumpAndSettle();
    expect(api.files, [true]);
  });
}
