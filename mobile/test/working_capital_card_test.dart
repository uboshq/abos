import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/records/working_capital_record.dart';
import 'package:abos_mobile/features/home/working_capital_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/real_fonts.dart';

/// ⭐ চলতি মূলধনের কার্ড — হোমে টাকার কার্ডের নিচে (মালিক, ৯ নভেম্বর ২০২৬: "sudu working capital er card ti bosabe"); সার্ভারের
/// `workingCapital` থেকে, তারিখ আর ৬/১২ মাসের গড় বদলালে সার্ভার থেকে নতুন অঙ্ক।
Map<String, dynamic> _payload({String date = '2026-11-09', int span = 6, String capital = '2948820.0000', String? salesPct = '90'}) => {
      'date': date,
      'receivable': '1284500.0000',
      'stock': '3260000.0000',
      'money': '1874320.0000',
      'payable': '2210000.0000',
      'other': '1260000.0000',
      'capital': capital,
      'ratio': '1.85',
      'previous': {'date': '2026-10-31', 'capital': '2819000.0000'},
      'changePct': '4.6',
      'span': span,
      'avgSales': '3280000.0000',
      'salesPct': salesPct,
    };

class _FakeApi implements WorkingCapitalApi {
  final List<(String?, int)> asked = [];
  bool fail = false;

  @override
  Future<WorkingCapitalRecord?> load({String? date, int span = 6}) async {
    asked.add((date, span));
    if (fail) throw Exception('offline');
    return WorkingCapitalRecord.fromJson(
        _payload(date: date ?? '2026-11-09', span: span, capital: span == 12 ? '2800000.0000' : '2700000.0000', salesPct: span == 12 ? '97' : '85'));
  }
}

void main() {
  setUpAll(() async => loadRealFonts());

  Future<_FakeApi> pump(WidgetTester tester, {bool realFonts = false}) async {
    tester.view.physicalSize = const Size(412, 1400) * 2;
    tester.view.devicePixelRatio = 2;
    addTearDown(tester.view.reset);
    final api = _FakeApi();
    await tester.pumpWidget(MaterialApp(
      theme: realFonts ? withRealFonts(ThemeData()) : null,
      home: Scaffold(
        body: SingleChildScrollView(
          child: WorkingCapitalCard(initial: WorkingCapitalRecord.fromJson(_payload())!, api: api, today: DateTime(2026, 11, 9), onOpenReport: () {}),
        ),
      ),
    ));
    await tester.pumpAndSettle();
    return api;
  }

  testWidgets('the card shows the server figures — capital, ratio, the share of average sales and the five parts', (tester) async {
    await pump(tester);
    expect(find.byKey(const ValueKey('wc-card')), findsOneWidget);
    expect(tester.widget<Text>(find.byKey(const ValueKey('wc-capital'))).data, contains('29,48,820'), reason: '⛔ চলতি মূলধনের অঙ্ক সার্ভারেরটা নয়');
    expect(tester.widget<Text>(find.byKey(const ValueKey('wc-ratio'))).data, '1.85');
    expect(tester.widget<Text>(find.byKey(const ValueKey('wc-sales-pct'))).data, '90%', reason: '⛔ গড় মাসিক বিক্রির % নেই');
    expect(tester.widget<Text>(find.byKey(const ValueKey('wc-part-3'))).data, startsWith('−'), reason: '⛔ দেনা বাদের চিহ্নে নয়');
    expect(find.byKey(const ValueKey('wc-chart')), findsOneWidget, reason: '⛔ ছোট waterfall নেই');
  });

  testWidgets('last month end and the 12-month average ask the server again and redraw', (tester) async {
    final api = await pump(tester);

    await tester.tap(find.byKey(const ValueKey('wc-preset-lastMonth')));
    await tester.pumpAndSettle();
    expect(api.asked.last, ('2026-10-31', 6), reason: '⛔ "গত মাসের শেষ" ৩১ অক্টোবর চায়নি');
    expect(tester.widget<Text>(find.byKey(const ValueKey('wc-capital'))).data, contains('27,00,000'));

    await tester.ensureVisible(find.byKey(const ValueKey('wc-span-12')));
    await tester.tap(find.byKey(const ValueKey('wc-span-12')));
    await tester.pumpAndSettle();
    expect(api.asked.last, ('2026-10-31', 12), reason: '⛔ ১২ মাসের গড় একই তারিখে চায়নি');
    expect(tester.widget<Text>(find.byKey(const ValueKey('wc-sales-pct'))).data, '97%');
  });

  testWidgets('a failed load keeps the old figures and says so', (tester) async {
    final api = await pump(tester);
    api.fail = true;
    await tester.tap(find.byKey(const ValueKey('wc-preset-lastYear')));
    await tester.pumpAndSettle();
    expect(tester.widget<Text>(find.byKey(const ValueKey('wc-capital'))).data, contains('29,48,820'), reason: '⛔ ব্যর্থ ডাকে অঙ্ক মুছে গেল');
    expect(find.textContaining('আনা গেল না').evaluate().isNotEmpty || find.textContaining('Could not load').evaluate().isNotEmpty, isTrue,
        reason: '⛔ ব্যর্থ হলে কারণ লেখা নেই');
  });

  // ⓘ আসল বাংলা অক্ষরে — মালিকের নমুনার পাশে রাখার ছবি
  testWidgets('the card looks as the sample (real fonts)', (tester) async {
    await pump(tester, realFonts: true);
    await expectLater(find.byKey(const ValueKey('wc-card')), matchesGoldenFile('goldens/home/working_capital_card.png'));
  });

  test('no workingCapital from the server — no card', () {
    expect(
        const TodayRecord({
          'money': {'amount': '1'}
        }).workingCapital,
        isNull,
        reason: '⛔ ঘর না এলেও কার্ড আঁকা হত');
    expect(TodayRecord({'workingCapital': _payload()}).workingCapital?.capital, 2948820);
  });
}
