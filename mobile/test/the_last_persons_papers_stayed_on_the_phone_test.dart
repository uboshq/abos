import 'dart:io';

import 'package:abos_mobile/core/printing/paper_files.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ বেরোলে নামানো কাগজ মোছে (পূর্ণ নিরীক্ষা §৯ ⚠️১৩, ১৮ অক্টোবর ২০২৬)।
///
/// <p>⛔ আগে বিল-চালান-রিপোর্টের PDF আর দলিলের ফাইল documents আর অস্থায়ী ফোল্ডারের গোড়ায় থাকত, বেরোনোর পরেও — আগের জনের কাগজ
/// পরের জনের সেশনে। ⓘ documents ফোল্ডারে Hive-এর বাক্সও থাকে, তাই মোছা হয় কেবল কাগজের নিজের ফোল্ডার।
void main() {
  late Directory temp;
  late Directory docs;

  setUp(() {
    temp = Directory.systemTemp.createTempSync('abos-temp');
    docs = Directory.systemTemp.createTempSync('abos-docs');
    PaperFiles.temporaryRoot = () async => temp;
    PaperFiles.documentsRoot = () async => docs;
  });

  tearDown(() {
    temp.deleteSync(recursive: true);
    docs.deleteSync(recursive: true);
  });

  test('signing out wipes every downloaded paper and leaves the offline queue alone', () async {
    final shared = File('${(await PaperFiles.cache()).path}/INV-0001.pdf')..writeAsBytesSync([1, 2, 3]);
    final kept = File('${(await PaperFiles.kept()).path}/CH-0001.pdf')..writeAsBytesSync([4, 5, 6]);
    final hive = File('${docs.path}/sync_queue.hive')..writeAsBytesSync([7]);

    await PaperFiles.wipe();

    expect(shared.existsSync(), isFalse, reason: '⛔ শেয়ারের কপি থেকে গেল');
    expect(kept.existsSync(), isFalse, reason: '⛔ ফোনে রাখা বিল পরের জনের জন্য থেকে গেল');
    expect(hive.existsSync(), isTrue, reason: '⛔ অফলাইনের সারিও মুছে গেল');
  });

  test('every paper writer and the sign-out use the paper folder', () {
    for (final path in [
      'lib/core/printing/document_delivery.dart',
      'lib/features/documents/document_screens.dart',
      'lib/features/web_inside/web_inside_screen.dart',
    ]) {
      final s = File(path).readAsStringSync();
      expect(s, contains('PaperFiles.'), reason: '$path কাগজের ফোল্ডারে লেখে না');
      expect(s, isNot(contains('getTemporaryDirectory()')), reason: '⛔ $path এখনো গোড়ার অস্থায়ী ফোল্ডারে লেখে');
      expect(s, isNot(contains('getApplicationDocumentsDirectory()')), reason: '⛔ $path এখনো documents-এর গোড়ায় লেখে');
    }
    expect(File('lib/core/auth/auth_controller.dart').readAsStringSync(), contains('PaperFiles.wipe()'),
        reason: '⛔ বেরোলে কাগজ মোছা হয় না');
  });

  test('a name with path signs cannot climb out of the folder', () {
    expect(PaperFiles.safeName('../../etc/x.pdf'), '.._.._etc_x.pdf');
    expect(PaperFiles.safeName(''), 'document');
  });
}
