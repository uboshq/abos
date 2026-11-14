import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/navigation/top_bar/abos_screen_bar.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';

import 'support/bar_fonts.dart';
import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ ০.৪.৪১ — a5-এর পর্দাগুলোয় a4-এর ভেতরের পর্দার বার ([AbosScreenBar]): সব পাতায় "কোম্পানি · শাখা › মডিউল › পাতা"
/// (মালিকের নিয়ম: "প্রতিটা পাতায়, সবসময়"; পুরো-পাতার নমুনা, ২০ অক্টোবর ২০২৬, পাতা ২)।
void main() {
  setUp(SyncStatus.instance.reset);

  Future<void> pump(WidgetTester tester, Widget screen, {Size size = const Size(360, 800), bool realFonts = false}) async {
    setScreenSize(tester, size);
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(inSession(framed(screen, font: realFonts ? withBarFonts : null)));
    await tester.pumpAndSettle();
  }

  group('আদায়ের পাতা (SR)', () {
    testWidgets('the bar reads "ADI Wholesale · ময়মনসিংহ › বিক্রয় › আদায়"', (tester) async {
      final handle = tester.ensureSemantics();
      // ⓘ ১৩ নভেম্বর ২০২৬ (মালিক, "ছবি নিচের বারে"): ছবি সরায় "কোম্পানি · শাখা" এখন আইকনের সারিতেই বসে — জায়গা মাপা হয় লেখার
      // চওড়ায়, তাই আসল ফন্টে (Roboto + Shonar Bangla); পরীক্ষার চৌকো ফন্টে প্রতিটা অক্ষর ১৫dp, নামটা অকারণে ছোট কোডে নামত
      await tester.runAsync(loadBarFonts);
      await pump(tester, srCollectBody(), size: const Size(412, 892), realFonts: true);
      final bar = tester.widget<AbosScreenBar>(find.byType(AbosScreenBar));
      expect(bar.title, 'আদায়');
      expect(bar.module, 'বিক্রয়');
      expect(find.byKey(const ValueKey('screen-bar')), findsOneWidget, reason: 'সেশনে আসল নীল বার, সাদামাটা নয়');
      expect(tester.widget<Text>(find.byKey(const ValueKey('top-company'))).data, 'ADI Wholesale');
      expect(find.byKey(const ValueKey('top-branch')), findsOneWidget);
      expect(tester.widget<Text>(find.byKey(const ValueKey('top-crumbs'))).data, '› বিক্রয় › আদায়');
      expect(tester.getSemantics(find.byKey(const ValueKey('top-title'))).label,
          'ADI Wholesale, ময়মনসিংহ শাখা, বিক্রয়, আদায়');
      handle.dispose();
    });

    testWidgets('"＋ নতুন আদায়" is the shared BarFab, and only with the collect permission', (tester) async {
      await pump(tester, srCollectBody());
      expect(find.byType(BarFab), findsNothing, reason: 'চাবি নেই → বোতাম নেই');
      expect(find.byType(FloatingActionButton), findsNothing);

      await pump(tester, srCollectBody(canCollect: true));
      final fab = find.byKey(const ValueKey('money-in-new'));
      expect(tester.widget(fab), isA<BarFab>());
      expect(tester.widget<BarFab>(fab).label, 'নতুন আদায়');
    });

    testWidgets('BarFab: 48dp or more, and TalkBack says "নতুন আদায়" in Bengali', (tester) async {
      final handle = tester.ensureSemantics();
      await pump(tester, srCollectBody(canCollect: true));
      final size = tester.getSize(find.byKey(const ValueKey('money-in-new')));
      expect(size.height, greaterThanOrEqualTo(48));
      expect(size.width, greaterThanOrEqualTo(48));
      final spoken = find.descendant(
          of: find.byKey(const ValueKey('money-in-new')), matching: find.bySemanticsLabel('নতুন আদায়'));
      expect(spoken, findsOneWidget);
      // ⓘ বোতাম আর তার নাম এক কথায় — "নতুন আদায়, বোতাম"
      expect(tester.getSemantics(spoken), isSemantics(label: 'নতুন আদায়', isButton: true, hasTapAction: true));
      handle.dispose();
    });
  });
}
