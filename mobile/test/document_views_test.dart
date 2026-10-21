import 'dart:typed_data';

import 'package:abos_mobile/core/documents_library/library_api.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/features/documents/document_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _Library implements DocumentLibraryApi {
  final asked = <String>[];

  @override
  Future<LibraryPage> list({String view = 'center', String q = '', int page = 1}) async {
    asked.add(view);
    return LibraryPage([
      LibraryDocument(id: 'd-$view', no: 'DOC-0001', name: 'কাগজ $view', deleted: view == 'recycle'),
    ], count: 42);
  }

  @override
  Future<LibraryDocument> one(String id) async => LibraryDocument(id: id, no: 'DOC-0001', name: 'কাগজ');

  @override
  Future<Uint8List> file(String id, {bool download = false}) async => Uint8List(0);

  @override
  Future<void> sign(String approvalId, {String? remarks}) async {}

  @override
  Future<void> refuse(String approvalId, {required String remarks}) async {}
}

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬: *"direct webpage app e bujajayna"* — দলিলের আটটা তালিকা এক ফোন-পর্দায়, মেনুর ঘর বাছা অবস্থায়।
void main() {
  test('each of the eight document rows opens the phone list on its own view', () {
    for (final view in DocumentListScreen.viewKeys) {
      expect(NativeForWeb.screenFor('/documents/$view').toString(), contains('ModuleGateView'), reason: '/documents/$view');
    }
    expect(NativeForWeb.screenFor('/documents/ghost'), isNull);
  });

  testWidgets('a list opens on its view, says the whole count, and a bin paper cannot be opened', (tester) async {
    final api = _Library();
    await tester.pumpWidget(MaterialApp(home: DocumentListScreen(api: api, initialView: 'recycle')));
    await tester.pumpAndSettle();

    expect(api.asked, ['recycle']);
    expect(find.text('মোট 42 টি কাগজ'), findsOneWidget);
    expect(find.text('মোছা — ফেরানো ওয়েবে'), findsOneWidget);
    expect(tester.widget<ListTile>(find.byKey(const ValueKey('doc-d-recycle'))).onTap, isNull, reason: 'বিনের কাগজ খোলা যায় না');

    await tester.tap(find.text('আর্কাইভ'));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'archive');
    expect(tester.widget<ListTile>(find.byKey(const ValueKey('doc-d-archive'))).onTap, isNotNull);
  });
}
