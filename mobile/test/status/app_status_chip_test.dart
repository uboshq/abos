import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/core/status/paper_state_icons.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';

/// ⭐ কাগজের অবস্থার চিপ — আইকন + রং + বাংলা লেখা একসাথে (WCAG ১.৪.১), TalkBack বাংলায়, "পোস্ট হয়নি" চাপলে কারণ,
/// ৪৮dp চাপের জায়গা, ২০০% লেখায় ভাঙে না, ধাপের অঙ্ক ইংরেজিতে।
Widget _app(Widget child, {bool dark = false, double scale = 1, double width = 390}) => MaterialApp(
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: dark ? ThemeMode.dark : ThemeMode.light,
      home: MediaQuery(
        data: MediaQueryData(size: Size(width, 844), textScaler: TextScaler.linear(scale)),
        child: Scaffold(body: Center(child: SizedBox(width: width, child: child))),
      ),
    );

final _bnDigit = RegExp('[০-৯]');

/// চিপের লেখা — আইকন লেখার ভেতরেই বসে (WidgetSpan, U+FFFC), তাই সেটা বাদ দিয়ে মেলায়
Finder _words(String words) => find.byWidgetPredicate(
    (w) => w is RichText && w.text.toPlainText().replaceAll('￼', '') == words,
    description: 'chip text "$words"');

Color? _textColour(WidgetTester tester, Finder chip) {
  final rich = tester.widget<Text>(find.descendant(of: chip, matching: find.byType(Text)).first);
  return rich.style?.color;
}

void main() {
  for (final dense in [false, true]) {
    final size = dense ? 'compact' : 'full';
    for (final state in PaperState.values) {
      testWidgets('${state.key} ($size): its icon and its Bengali text are both on screen', (tester) async {
        final chip = AppStatusChip(key: const ValueKey('chip'), state: state, level: 1, levels: 2, dense: dense);
        await tester.pumpWidget(_app(Align(alignment: Alignment.centerLeft, child: chip)));
        expect(find.descendant(of: find.byKey(const ValueKey('chip')), matching: find.byIcon(paperStateIcon(state))),
            findsOneWidget);
        final words = dense ? state.spec.shortLabel(level: 1, levels: 2) : state.spec.label(level: 1, levels: 2);
        expect(_words(words), findsOneWidget);
        expect(words.trim(), isNotEmpty);
      });

      testWidgets('${state.key} ($size): TalkBack says the full Bengali sentence', (tester) async {
        final handle = tester.ensureSemantics();
        await tester.pumpWidget(
            _app(Align(alignment: Alignment.centerLeft, child: AppStatusChip(state: state, level: 1, levels: 2, dense: dense))));
        final spoken = state.spec.semantics(level: 1, levels: 2);
        expect(spoken, startsWith('অবস্থা: '));
        expect(find.bySemanticsLabel(spoken), findsOneWidget);
        expect(find.bySemanticsLabel(RegExp('[A-Za-z]')), findsNothing, reason: 'no English words spoken');
        handle.dispose();
      });
    }
  }

  testWidgets('posted speaks "অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে"', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(_app(const Align(child: AppStatusChip.compact(state: PaperState.posted))));
    expect(find.bySemanticsLabel('অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে'), findsOneWidget);
    expect(_words('পোস্ট হয়েছে'), findsOneWidget);
    handle.dispose();
  });

  testWidgets('waiting shows "2-এর 1" from the data, latin digits, and falls back without a level', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(_app(const Column(children: [
      AppStatusChip(state: PaperState.waiting, level: 1, levels: 2),
      AppStatusChip.compact(state: PaperState.waiting, level: 3, levels: 4),
      AppStatusChip(state: PaperState.waiting),
    ])));
    expect(_words('অনুমোদনের অপেক্ষায় · 2-এর 1'), findsOneWidget);
    expect(_words('4-এর 3'), findsOneWidget);
    expect(_words('অনুমোদনের অপেক্ষায়'), findsOneWidget);
    expect(find.bySemanticsLabel('অবস্থা: অনুমোদনের অপেক্ষায়, 2-এর 1'), findsOneWidget);
    expect(find.bySemanticsLabel('অবস্থা: অনুমোদনের অপেক্ষায়, 4-এর 3'), findsOneWidget);
    expect(find.bySemanticsLabel('অবস্থা: অনুমোদনের অপেক্ষায়'), findsOneWidget);
    handle.dispose();
  });

  test('no chip text or spoken sentence carries a Bengali digit (U+09E6–U+09EF), at any level', () {
    for (final state in PaperState.values) {
      for (final (level, levels) in [(null, null), (1, 2), (2, 2), (3, 10), (12, 15)]) {
        final s = state.spec;
        for (final text in [
          s.label(level: level, levels: levels),
          s.shortLabel(level: level, levels: levels),
          s.semantics(level: level, levels: levels),
          if (s.semanticsHintBn != null) s.semanticsHintBn!,
        ]) {
          expect(_bnDigit.hasMatch(text), isFalse, reason: '${state.key} "$text"');
        }
      }
    }
    expect(PaperState.waiting.spec.label(level: 12, levels: 15), 'অনুমোদনের অপেক্ষায় · 15-এর 12');
  });

  testWidgets('no Bengali digit reaches the screen or TalkBack', (tester) async {
    final handle = tester.ensureSemantics();
    // ⓘ every state in both sizes is taller than one screen — scroll, the test is about digits, not layout
    await tester.pumpWidget(_app(SingleChildScrollView(
      child: Column(children: [
        for (final s in PaperState.values) ...[
          AppStatusChip(state: s, level: 2, levels: 3),
          AppStatusChip.compact(state: s, level: 2, levels: 3),
        ],
      ]),
    )));
    for (final t in tester.widgetList<RichText>(find.byType(RichText))) {
      expect(_bnDigit.hasMatch(t.text.toPlainText()), isFalse, reason: t.text.toPlainText());
    }
    expect(find.bySemanticsLabel(_bnDigit), findsNothing);
    handle.dispose();
  });

  testWidgets('the colours follow the theme: light uses sap_horizon, dark uses sap_horizon_dark', (tester) async {
    for (final dark in [false, true]) {
      await tester.pumpWidget(_app(const Align(child: AppStatusChip(key: ValueKey('c'), state: PaperState.posted)), dark: dark));
      await tester.pumpAndSettle(); // the theme animates from light to dark
      final c = PaperState.posted.spec.colours.of(dark ? Brightness.dark : Brightness.light);
      expect(_textColour(tester, find.byKey(const ValueKey('c'))), c.foreground);
      final icon = tester.widget<Icon>(find.byIcon(paperStateIcon(PaperState.posted)));
      expect(icon.color, c.foreground);
      final box = tester.widget<Container>(
          find.descendant(of: find.byKey(const ValueKey('c')), matching: find.byType(Container)).first);
      expect((box.decoration as BoxDecoration).color, c.background);
    }
  });

  testWidgets('not posted: TalkBack hears a button that shows the reason', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(_app(const Align(child: AppStatusChip(state: PaperState.notPosted, reason: 'বাকির সীমা পার'))));
    final node = tester.getSemantics(find.bySemanticsLabel('অবস্থা: পোস্ট হয়নি'));
    expect(node.hint, 'কারণ দেখতে চাপুন');
    expect(node.flagsCollection.isButton, isTrue);
    expect(node.getSemanticsData().hasAction(SemanticsAction.tap), isTrue);
    handle.dispose();
  });

  testWidgets('not posted: tapping shows the server\'s reason in Bengali, and the close button closes it', (tester) async {
    await tester.pumpWidget(_app(
        const Align(child: AppStatusChip.compact(state: PaperState.notPosted, reason: 'হিসাবের বছর বন্ধ — ২০২৫-২৬'))));
    await tester.tap(find.byType(AppStatusChip));
    await tester.pumpAndSettle();
    expect(find.text('কেন পোস্ট হয়নি'), findsOneWidget);
    expect(find.text('হিসাবের বছর বন্ধ — ২০২৫-২৬'), findsOneWidget);
    await tester.tap(find.text('বন্ধ করুন'));
    await tester.pumpAndSettle();
    expect(find.text('কেন পোস্ট হয়নি'), findsNothing);
  });

  testWidgets('not posted with no reason from the server says so in Bengali, never a blank sheet', (tester) async {
    await tester.pumpWidget(_app(const Align(child: AppStatusChip(state: PaperState.notPosted))));
    await tester.tap(find.byType(AppStatusChip));
    await tester.pumpAndSettle();
    expect(find.text('কারণ জানা যায়নি — হিসাব বিভাগে জিজ্ঞেস করুন।'), findsOneWidget);
  });

  testWidgets('not posted: a screen\'s own tap wins over the sheet', (tester) async {
    var taps = 0;
    await tester.pumpWidget(_app(Align(child: AppStatusChip(state: PaperState.notPosted, onReasonTap: () => taps++))));
    await tester.tap(find.byType(AppStatusChip));
    await tester.pumpAndSettle();
    expect(taps, 1);
    expect(find.text('কেন পোস্ট হয়নি'), findsNothing);
  });

  testWidgets('the tappable chip gives at least 48 x 48 dp to the finger, in both sizes', (tester) async {
    for (final dense in [false, true]) {
      await tester.pumpWidget(_app(Align(child: AppStatusChip(state: PaperState.notPosted, dense: dense))));
      final size = tester.getSize(find.byKey(const ValueKey('paper-state-not_posted-tap')));
      expect(size.height, greaterThanOrEqualTo(48));
      expect(size.width, greaterThanOrEqualTo(48));
      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
    }
  });

  testWidgets('the other chips are not buttons (nothing to tap, nothing promised)', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(_app(const Align(child: AppStatusChip(state: PaperState.cancelled))));
    final node = tester.getSemantics(find.bySemanticsLabel('অবস্থা: বাতিল'));
    expect(node.flagsCollection.isButton, isFalse);
    handle.dispose();
  });

  for (final dense in [false, true]) {
    testWidgets('200% text in a narrow list cell: every chip wraps, nothing overflows (${dense ? 'compact' : 'full'})',
        (tester) async {
      tester.view.physicalSize = const Size(390 * 3, 2400 * 3);
      tester.view.devicePixelRatio = 3;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(_app(
        scale: 2,
        width: 160,
        SingleChildScrollView(
          child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            for (final s in PaperState.values) AppStatusChip(state: s, level: 2, levels: 3, dense: dense),
          ]),
        ),
      ));
      expect(tester.takeException(), isNull);
      for (final s in PaperState.values) {
        final words = dense ? s.spec.shortLabel(level: 2, levels: 3) : s.spec.label(level: 2, levels: 3);
        expect(_words(words), findsOneWidget);
        // the whole text is inside the chip's box: wrapped, never cut, faded or ellipsised
        final p = tester.renderObject<RenderParagraph>(_words(words));
        expect(p.size.width, lessThanOrEqualTo(160));
        expect(p.didExceedMaxLines, isFalse, reason: '${s.key} cut short');
        final end = p.getOffsetForCaret(TextPosition(offset: p.text.toPlainText().length), Rect.zero);
        expect(end.dx, lessThanOrEqualTo(p.size.width + 0.5), reason: '${s.key} runs past the right edge');
        expect(end.dy, lessThan(p.size.height), reason: '${s.key} runs past the bottom');
      }
    });
  }

  testWidgets('a list row: number · party on the left, the compact chip on the right, one line', (tester) async {
    await tester.pumpWidget(_app(const Padding(
      padding: EdgeInsets.all(12),
      child: Row(children: [
        Expanded(child: Text('RCV-0190 · রহিম স্টোর', key: ValueKey('left'))),
        SizedBox(width: 8),
        AppStatusChip.compact(key: ValueKey('chip'), state: PaperState.waiting, level: 1, levels: 2),
      ]),
    )));
    expect(tester.takeException(), isNull);
    final left = tester.getRect(find.byKey(const ValueKey('left')));
    final chip = tester.getRect(find.byKey(const ValueKey('chip')));
    expect(chip.left, greaterThan(left.right - 1));
    expect(chip.height, lessThan(40), reason: 'one line at normal size');
  });

  testWidgets('rejected is its own red state, not cancelled: own icon, own words', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(_app(const Column(children: [
      AppStatusChip(state: PaperState.rejected),
      AppStatusChip(state: PaperState.cancelled),
    ])));
    expect(_words('প্রত্যাখ্যাত'), findsOneWidget);
    expect(_words('বাতিল'), findsOneWidget);
    expect(paperStateIcon(PaperState.rejected), isNot(paperStateIcon(PaperState.cancelled)));
    expect(find.bySemanticsLabel('অবস্থা: প্রত্যাখ্যাত'), findsOneWidget);
    expect(PaperState.rejected.spec.role, PaperRole.negative);
    handle.dispose();
  });

  testWidgets('approved: "অনুমোদিত" in general, "অনুমোদিত · টাকার অপেক্ষায়" on a claim, blue', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(_app(const Column(children: [
      AppStatusChip(state: PaperState.approved),
      AppStatusChip(state: PaperState.approved, kind: PaperKind.claim),
      AppStatusChip.compact(state: PaperState.approved, kind: PaperKind.claim),
    ])));
    expect(_words('অনুমোদিত'), findsOneWidget);
    expect(_words('অনুমোদিত · টাকার অপেক্ষায়'), findsNWidgets(2));
    expect(find.bySemanticsLabel('অবস্থা: অনুমোদিত'), findsOneWidget);
    expect(find.bySemanticsLabel('অবস্থা: অনুমোদিত, টাকার অপেক্ষায়'), findsNWidgets(2));
    expect(PaperState.approved.spec.role, PaperRole.information);
    handle.dispose();
  });

  testWidgets('posted: a money paper went to the books, a stock paper to the stock; the short chip says "পোস্ট হয়েছে" for both',
      (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(_app(const Column(children: [
      AppStatusChip(state: PaperState.posted),
      AppStatusChip(state: PaperState.posted, kind: PaperKind.stock),
      AppStatusChip.compact(state: PaperState.posted, kind: PaperKind.stock),
      AppStatusChip(state: PaperState.posting, kind: PaperKind.stock),
    ])));
    expect(_words('পোস্ট হয়েছে · খাতায় উঠেছে'), findsOneWidget);
    expect(_words('পোস্ট হয়েছে · মজুদে উঠেছে'), findsOneWidget);
    expect(_words('পোস্ট হয়েছে'), findsOneWidget);
    expect(_words('অনুমোদিত · মজুদে উঠছে'), findsOneWidget);
    expect(find.bySemanticsLabel('অবস্থা: পোস্ট হয়েছে, মজুদে উঠেছে'), findsNWidgets(2));
    expect(find.bySemanticsLabel('অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে'), findsOneWidget);
    handle.dispose();
  });

  testWidgets('a paid claim says "পরিশোধিত" in both sizes and to TalkBack; sent back says "সংশোধনে ফেরত"',
      (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(_app(const Column(children: [
      AppStatusChip(state: PaperState.posted, kind: PaperKind.claim),
      AppStatusChip.compact(state: PaperState.posted, kind: PaperKind.claim),
      AppStatusChip(state: PaperState.sentBack),
      AppStatusChip.compact(state: PaperState.sentBack),
    ])));
    expect(_words('পরিশোধিত'), findsNWidgets(2));
    expect(find.bySemanticsLabel('অবস্থা: পরিশোধিত'), findsNWidgets(2));
    expect(_words('সংশোধনে ফেরত'), findsNWidgets(2));
    expect(find.bySemanticsLabel('অবস্থা: সংশোধনে ফেরত, ঠিক করে আবার পাঠান'), findsNWidgets(2));
    handle.dispose();
  });

  test('"পাকা" is gone from every chip word, for every kind', () {
    for (final s in PaperState.values) {
      for (final k in PaperKind.values) {
        for (final t in [s.spec.label(kind: k), s.spec.shortLabel(kind: k), s.spec.semantics(kind: k)]) {
          expect(t, isNot(contains('পাকা')), reason: '${s.key}/${k.name}: "$t"');
        }
      }
    }
  });

  test('a kind without its own words uses the plain ones (stock draft is still "খসড়া")', () {
    for (final s in PaperState.values) {
      for (final k in PaperKind.values) {
        expect(s.spec.label(kind: k), isNotEmpty);
      }
    }
    expect(PaperState.draft.spec.label(kind: PaperKind.stock), 'খসড়া');
    expect(PaperState.posted.spec.label(kind: PaperKind.claim), 'পরিশোধিত');
    expect(PaperState.cancelled.spec.label(kind: PaperKind.claim), 'বাতিল');
  });

  group('PaperState.fromServer — every server state string the app receives', () {
    const cases = <String, PaperState?>{
      // vouchers: lib/core/accounts/voucher_api.dart (state), server VoucherApiController::row
      'draft': PaperState.draft,
      'awaiting': PaperState.waiting,
      'posted': PaperState.posted,
      'cancelled': PaperState.cancelled,
      // sales invoices and challans: lib/features/sales_papers/sales_paper_screens.dart _status
      'awaiting_approval': PaperState.waiting,
      'confirmed': PaperState.posted,
      'closed': PaperState.posted,
      // expense claims and advances: lib/core/hr/claims_api.dart, server ExpenseClaim::STATES
      'submitted': PaperState.waiting,
      'paid': PaperState.posted,
      'rejected': PaperState.rejected,
      'approved': PaperState.approved,
      // approval requests: lib/features/approvals/my_requests_screen.dart (pending, approved, rejected, returned)
      'pending': PaperState.waiting,
      // sent back, posting, not posted
      'returned': PaperState.sentBack,
      'sent_back': PaperState.sentBack,
      'posting': PaperState.posting,
      'not_posted': PaperState.notPosted,
      'post_failed': PaperState.notPosted,
      // unknown: the screen keeps the server's own words
      '': null,
      'somethingNew': null,
      'unsent': null,
    };
    for (final c in cases.entries) {
      test('"${c.key}" → ${c.value?.key ?? 'null (server words)'}', () {
        expect(PaperState.fromServer(c.key), c.value);
      });
    }
    test('case and spaces do not matter', () {
      expect(PaperState.fromServer(' Posted '), PaperState.posted);
    });
  });

  testWidgets('forServer: a known state is a chip, an unknown one keeps the server\'s own words', (tester) async {
    await tester.pumpWidget(_app(Column(children: [
      AppStatusChip.forServer('paid', fallbackLabel: 'পরিশোধিত'),
      AppStatusChip.forServer('somethingNew', fallbackLabel: 'নতুন কিছু'),
    ])));
    expect(find.byType(AppStatusChip), findsOneWidget);
    expect(_words('পোস্ট হয়েছে · খাতায় উঠেছে'), findsOneWidget);
    expect(find.text('নতুন কিছু'), findsOneWidget);
  });

  // ⭐ ভাষার সুইচ (0.4.43): অ্যাপ ইংরেজিতে চললে চিপ JSON-এর `_en` পড়ে — লেখা, TalkBack আর "পোস্ট হয়নি"-এর পাতা
  testWidgets('in an English app the chip reads, speaks and explains in English', (tester) async {
    final handle = tester.ensureSemantics();
    await tester.pumpWidget(MaterialApp(
      locale: AppLocale.english,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      theme: AppTheme.light(),
      home: const Scaffold(
        body: Column(children: [
          AppStatusChip(state: PaperState.waiting, level: 1, levels: 2),
          AppStatusChip.compact(state: PaperState.posted, kind: PaperKind.stock),
          AppStatusChip(state: PaperState.notPosted),
          PaperStateFact(chip: SizedBox()),
        ]),
      ),
    ));
    await tester.pumpAndSettle();
    expect(_words('Awaiting approval · step 1 of 2'), findsOneWidget);
    expect(_words('Posted'), findsOneWidget);
    expect(find.bySemanticsLabel('Status: awaiting approval, step 1 of 2'), findsOneWidget);
    expect(find.bySemanticsLabel('Status: posted, in stock'), findsOneWidget);
    expect(find.text('Status'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('paper-state-not_posted-tap')));
    await tester.pumpAndSettle();
    expect(find.text('Why it was not posted'), findsOneWidget);
    expect(find.text('The reason is not known — ask the accounts department.'), findsOneWidget);
    expect(find.text('Close'), findsOneWidget);
    handle.dispose();
  });
}
