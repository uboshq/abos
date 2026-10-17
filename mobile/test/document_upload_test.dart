import 'package:abos_mobile/core/documents_library/upload_api.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/documents/document_upload_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeUpload implements DocumentUploadApi {
  _FakeUpload({this.fail = false});

  final bool fail;
  final sent = <UploadDraft>[];

  @override
  Future<UploadSetup> setup() async {
    if (fail) throw Exception('offline');
    return const UploadSetup({
      'folders': [
        {'code': 'contracts', 'label': 'চুক্তি'},
      ],
      'types': [
        {'code': 'contract', 'label': 'চুক্তিপত্র'},
      ],
      'levels': [
        {'code': 'public', 'label': 'সবার জন্য'},
        {'code': 'internal', 'label': 'ভিতরের'},
      ],
      'branches': [
        {'id': 'b-main', 'label': 'প্রধান', 'default': true},
      ],
      'company_wide': false,
      'max_files': 20,
    });
  }

  @override
  Future<(int, String)> upload(UploadDraft draft) async {
    sent.add(draft);
    return (draft.paths.length, '');
  }
}

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬ — ফোন থেকে কাগজ তোলা আর ক্যামেরায় স্ক্যান; Intelligence এখনো ওয়েবে।
void main() {
  testWidgets('the scan form has only the camera, no file button', (tester) async {
    await tester.pumpWidget(MaterialApp(home: DocumentUploadScreen(scan: true, api: _FakeUpload())));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('upload-pick')), findsOneWidget);
    expect(find.byKey(const ValueKey('upload-pick-file')), findsNothing);
  });

  test('upload and scan open the phone form; intelligence stays on the web for now', () {
    expect(NativeForWeb.screenFor('/documents/upload').toString(), contains('ModuleGateView'));
    expect(NativeForWeb.screenFor('/documents/scan').toString(), contains('ModuleGateView'));
    expect(NativeForWeb.screenFor('/documents/intelligence'), isNull);
  });

  testWidgets('the form asks for a photo first, then sends the photos with the chosen folder, type, level and branch', (tester) async {
    tester.view.physicalSize = const Size(800, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = _FakeUpload();
    await tester.pumpWidget(MaterialApp(
      home: DocumentUploadScreen(api: api, picker: () async => ['/tmp/a.jpg'], filePicker: () async => ['/tmp/b.pdf']),
    ));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('upload-save')));
    await tester.pumpAndSettle();
    expect(find.text('আগে অন্তত একটা ছবি বা ফাইল বাছুন।'), findsOneWidget);
    expect(api.sent, isEmpty);

    await tester.tap(find.byKey(const ValueKey('upload-pick')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('upload-pick-file')));
    await tester.pumpAndSettle();
    expect(find.text('2 টি ফাইল — প্রতিটা আলাদা কাগজ হবে'), findsOneWidget, reason: 'ছবি আর PDF একসাথে');
    await tester.enterText(find.byKey(const ValueKey('upload-name')), 'দোকানের ছবি');
    await tester.tap(find.byKey(const ValueKey('upload-save')));
    await tester.pumpAndSettle();

    final d = api.sent.single;
    expect(d.paths, ['/tmp/a.jpg', '/tmp/b.pdf']);
    expect([d.name, d.folder, d.type, d.level, d.branch], ['দোকানের ছবি', 'contracts', 'contract', 'internal', 'b-main'],
        reason: 'ডিফল্ট: প্রথম ফোল্ডার ও ধরন, "ভিতরের" গোপনীয়তা, নিজের শাখা');
  });

  testWidgets('a form that could not load can be pulled to refresh', (tester) async {
    await tester.pumpWidget(MaterialApp(home: DocumentUploadScreen(api: _FakeUpload(fail: true))));
    await tester.pumpAndSettle();
    expect(find.byType(EmptyState), findsOneWidget);
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
  });
}
