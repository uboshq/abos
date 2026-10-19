import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/navigation/recent_papers.dart';
import 'package:abos_mobile/features/sales_papers/sales_paper_screens.dart';

/// ⭐ নম্বরের পাশে পক্ষ — মালিক, ১৯ অক্টোবর ২০২৬: "অনেক জায়গায় তালিকায় শুধু ভাউচার নম্বর দেওয়া। সেখানে নম্বরের পাশে
/// গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়।" — the home's "সাম্প্রতিক" line reads "নম্বর · গ্রাহক · পয়েন্ট"
/// (the party the paper's own screen already holds); without a party it
/// keeps "নম্বর · ধরন", and a line stored by an older build still shows.
void main() {
  group('the line\'s words', () {
    test('number, then the party with its point', () {
      expect(RecentPapers.labelOf(type: 'বিক্রয় বিল', number: 'INV-0007', party: 'রহিম স্টোর · মিরপুর'),
          'INV-0007 · রহিম স্টোর · মিরপুর');
    });

    test('no party (or only spaces) keeps the paper\'s type', () {
      expect(RecentPapers.labelOf(type: 'ডেলিভারি অর্ডার', number: 'DO-0412'), 'DO-0412 · ডেলিভারি অর্ডার');
      expect(RecentPapers.labelOf(type: 'ডেলিভারি অর্ডার', number: 'DO-0412', party: '  '), 'DO-0412 · ডেলিভারি অর্ডার');
    });

    test('no number: the type, then the party', () {
      expect(RecentPapers.labelOf(type: 'চালান', number: ' '), 'চালান');
      expect(RecentPapers.labelOf(type: 'চালান', number: '', party: 'রহিম স্টোর'), 'চালান · রহিম স্টোর');
    });
  });

  group('noting a paper', () {
    late NavPrefs prefs;

    setUp(() {
      prefs = NavPrefs(MemoryNavPrefsStore(), 'u1');
      RecentPapers.active = prefs;
    });
    tearDown(() => RecentPapers.active = null);

    test('the stored line carries the party; the route is unchanged', () async {
      await RecentPapers.note(kind: 'do', id: 'd1', number: 'DO-0412', party: 'রহিম স্টোর · মিরপুর');
      final recents = await prefs.recents();
      expect(recents.single.path, 'paper/do/d1');
      expect(recents.single.label, 'DO-0412 · রহিম স্টোর · মিরপুর');
    });

    test('an old line ("number · type") still reads, and reopening rewrites it with the party', () async {
      await prefs.remember(RecentEntry(path: RecentPapers.path('do', 'd1'), label: 'DO-0412 · ডেলিভারি অর্ডার'));
      expect((await prefs.recents()).single.label, 'DO-0412 · ডেলিভারি অর্ডার');

      await RecentPapers.note(kind: 'do', id: 'd1', number: 'DO-0412', party: 'রহিম স্টোর · মিরপুর');
      final recents = await prefs.recents();
      expect(recents, hasLength(1), reason: '⛔ একই কাগজ দুই লাইনে বসল');
      expect(recents.single.label, 'DO-0412 · রহিম স্টোর · মিরপুর');
    });

    testWidgets('an opened bill notes its customer and point', (tester) async {
      await tester.pumpWidget(
          MaterialApp(home: SalesPaperScreen(kind: SalesPaperKind.invoice, id: 'inv-7', api: _OneBill())));
      await tester.pumpAndSettle();

      final recents = await tester.runAsync(prefs.recents);
      expect(recents!.single.path, 'paper/invoice/inv-7');
      expect(recents.single.label, 'INV-0007 · রহিম স্টোর · মিরপুর', reason: '⛔ সাম্প্রতিক-এ বিলের পাশে গ্রাহক আর পয়েন্ট নেই');
    });
  });
}

class _OneBill implements SalesPaperApi {
  @override
  Future<SalesPaperDetail> one(SalesPaperKind kind, String id) async =>
      SalesPaperDetail(id: id, no: 'INV-0007', customer: 'রহিম স্টোর', point: 'মিরপুর', total: '1000');

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}
