import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';

import 'navigation/bar_harness.dart' show loadRealFonts, realFontTheme;
import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart';

/// ⭐ টাকার অঙ্ক কখনো ছোট হয় না, লেখার মাপের সাথে বাড়ে, আর মাঝখানে ভাঙে না (WCAG 1.4.4; fe, ২২ অক্টোবর ২০২৬)।
///
/// <p>গ্রাহকের তালিকা, খাতা আর বকেয়ার তালিকা — ১০০%, ১৩০% আর ২০০% লেখায়, ৪১২dp ফোনে:
/// - প্রতিটা অঙ্কের আঁকা মাপ সেই সারির লেখার (দ্বিতীয় লাইনের) চেয়ে ছোট নয়, আর ২০০%-এ ১০০%-এর চেয়ে বড়;
/// - কোনো অঙ্ক দুই লাইনে যায় না, কাটাও পড়ে না;
/// - খাতার কথার ঘর সারির অন্তত ৪০% (আগে ~৯০px, তারিখ তিন লাইনে ভাঙত);
/// - প্রিন্টারের সারির কথার ঘর সারির অন্তত অর্ধেক (৩৬০ আর ৪১২dp), বোতাম পর্দার ভিতরে।
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
    // ⓘ আসল ফন্টে (Roboto, Shonar Bangla) — ফোনে যা দেখায়; পরীক্ষার চৌকো ফন্ট প্রতিটা অক্ষর এক em চওড়া আঁকে
    await loadRealFonts();
    appFrameFont = realFontTheme;
  });

  tearDownAll(() async {
    appFrameFont = null;
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  RenderParagraph paragraphIn(WidgetTester tester, Finder of) =>
      tester.renderObject<RenderParagraph>(find.descendant(of: of, matching: find.byType(RichText)).first);

  /// আঁকা অক্ষরের মাপ — লেখার নিজের মাপ × ফোনের লেখার মাপ
  double drawnSize(RenderParagraph p) => p.textScaler.scale(p.text.style!.fontSize!);

  /// RenderParagraph যেভাবে সাজায় সেভাবে সাজিয়ে লাইন গোনা
  int linesOf(RenderParagraph p) {
    final painter = TextPainter(text: p.text, textDirection: p.textDirection, textScaler: p.textScaler, maxLines: p.maxLines)
      ..layout(maxWidth: p.softWrap ? p.constraints.maxWidth : double.infinity);
    final lines = painter.computeLineMetrics().length;
    painter.dispose();
    return lines;
  }

  /// এক লাইনে, কাটা ছাড়া
  void expectOneWholeLine(RenderParagraph p, String what) {
    final text = p.text.toPlainText();
    expect(linesOf(p), 1, reason: '$what "$text" spans more than one line');
    expect(p.didExceedMaxLines, isFalse, reason: '$what "$text" is cut');
    expect(p.getMaxIntrinsicWidth(double.infinity), lessThanOrEqualTo(p.size.width + 0.5),
        reason: '$what "$text" is wider than its box (${p.size.width})');
  }

  final screens = <String, Widget Function(double scale)>{
    'customer list': (scale) => customerList(textScale: scale),
    'ledger': (scale) => customerLedger(textScale: scale),
    'due list': (scale) => appFrame(DueListScreen(refresh: () async {}), textScale: scale),
  };

  /// সারি, তার অঙ্কের key আর সারির লেখার (দ্বিতীয় লাইনের) খোঁজ
  ({Finder row, List<String> amounts, Finder body}) rowOf(String screen) => switch (screen) {
        'customer list' => (
            row: find.ancestor(of: find.byKey(const ValueKey('customer-row-$shopId')), matching: find.byType(Card)).first,
            amounts: ['due-amount'],
            body: find.text('01711000001 · মিরপুর ১০, ঢাকা'),
          ),
        'ledger' => (
            row: find.ancestor(of: find.byKey(const ValueKey('ledger-row-amount')).first, matching: find.byType(Card)).first,
            amounts: ['ledger-row-amount', 'ledger-row-balance'],
            body: find.textContaining('RCV-0002'),
          ),
        _ => (
            row: find.byKey(const ValueKey('due-$shopId')),
            amounts: ['row-amount'],
            body: find.textContaining('CUS-0001'),
          ),
      };

  for (final screen in screens.entries) {
    testWidgets('${screen.key}: every amount is at least the row\'s text, one whole line, and grows with the text size',
        (tester) async {
      setScreenSize(tester, const Size(412, 915));
      await tester.runAsync(seedMainData);
      final sizes = <double, double>{};
      for (final scale in [1.0, 1.3, 2.0]) {
        await tester.pumpWidget(screen.value(scale));
        await tester.pumpAndSettle();
        final r = rowOf(screen.key);
        expect(r.row, findsOneWidget, reason: '${screen.key} at ${scale * 100}%');
        final body = drawnSize(paragraphIn(tester, find.descendant(of: r.row, matching: r.body).first));
        for (final k in r.amounts) {
          final amount = find.descendant(of: r.row, matching: find.byKey(ValueKey(k)));
          expect(amount, findsOneWidget, reason: '$k at ${scale * 100}%');
          final p = paragraphIn(tester, amount);
          expect(drawnSize(p), greaterThanOrEqualTo(body),
              reason: '$k "${p.text.toPlainText()}" at ${scale * 100}% is smaller than the row\'s text');
          expectOneWholeLine(p, '$k at ${scale * 100}%');
          // ⓘ কোনো পূর্বপুরুষ অঙ্ক ছোট করে আঁকে না (FittedBox/Transform)
          final shown = tester.getRect(amount).size;
          expect(shown.height, greaterThanOrEqualTo(p.size.height - 0.5), reason: '$k is drawn smaller than laid out');
          if (k == r.amounts.first) sizes[scale] = drawnSize(p) * tester.getRect(amount).height / p.size.height;
        }
      }
      expect(sizes[2.0]!, greaterThan(sizes[1.0]!), reason: 'the amount must grow with the text size: $sizes');
    });
  }

  testWidgets('ledger at 412dp: the words column keeps at least 40% of the row, at 100%, 130% and 200%', (tester) async {
    setScreenSize(tester, const Size(412, 915));
    await tester.runAsync(seedMainData);
    for (final scale in [1.0, 1.3, 2.0]) {
      await tester.pumpWidget(customerLedger(textScale: scale));
      await tester.pumpAndSettle();
      final row = find.ancestor(of: find.byKey(const ValueKey('ledger-row-amount')).first, matching: find.byType(Card)).first;
      final date = paragraphIn(tester, find.descendant(of: row, matching: find.textContaining('RCV-0002')).first);
      final rowWidth = tester.getSize(row).width;
      expect(date.constraints.maxWidth, greaterThanOrEqualTo(rowWidth * 0.4),
          reason: 'at ${scale * 100}% the words get ${date.constraints.maxWidth} of $rowWidth');
      // ⓘ "06/10/2026 · RCV-0002" আগে তিন লাইনে ভাঙত
      if (scale < 2) {
        expect(linesOf(date), 1, reason: '"${date.text.toPlainText()}" breaks over ${linesOf(date)} lines at ${scale * 100}%');
      }
    }
  });

  // ⓘ আসল ফন্টে আর পরীক্ষার চওড়া ফন্টে — চওড়া ফন্ট লম্বা অনুবাদের মতো: বোতামের লেখা চওড়া হলেও কথার ঘর চাপা পড়ে না
  for (final (font, realFont) in [('real font', true), ('wide test font', false)]) {
  for (final width in [360.0, 412.0]) {
    testWidgets('printer at ${width.toInt()}dp, $font: the words keep at least half the row and the button stays on screen',
        (tester) async {
      setScreenSize(tester, Size(width, 915));
      appFrameFont = realFont ? realFontTheme : null;
      addTearDown(() => appFrameFont = realFontTheme);
      for (final scale in [1.0, 1.3, 2.0]) {
        await tester.pumpWidget(printerSettings(textScale: scale));
        await tester.pumpAndSettle();
        final row = find.byKey(const Key('printer-current'));
        final title = paragraphIn(tester, find.descendant(of: row, matching: find.byType(Text)).first);
        // ⓘ আগে ডানের বোতাম কথার ঘর ~৯০px-এ চাপত: ১৩০%-এ ১৬ লাইন
        expect(title.constraints.maxWidth, greaterThanOrEqualTo(tester.getSize(row).width * 0.5),
            reason: 'at ${scale * 100}% the printer words get ${title.constraints.maxWidth} of ${tester.getSize(row).width}');
        final button = tester.getRect(find.byKey(const Key('printer-choose')));
        expect(Rect.fromLTWH(0, 0, width, 915).contains(button.bottomRight - const Offset(0.5, 0.5)), isTrue,
            reason: 'at ${scale * 100}% the button is at $button');
      }
    });
  }
  }
}
