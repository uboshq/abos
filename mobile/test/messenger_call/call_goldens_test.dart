import 'package:abos_mobile/core/messenger/messenger_alerts.dart';
import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/messenger/call/calls.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../fakes/fake_messenger_api.dart';
import '../support/bar_fonts.dart';
import 'call_fakes.dart';

/// ⭐ কলের ছবি — নমুনা 3nuwRUdn v7 "ঘ"-এর পাশে রেখে দেখার জন্য, ৪১২×৯০০, তিন রূপেই (ক্লাসিক, লিকুইড গ্লাস, ম্যাটেরিয়াল):
/// আসা ভয়েস কল (ফোন ১), ভয়েস কল চলছে (বড় ছবি আর ঘড়ি 03:12), একজনের সাথে ভিডিও (ফোন ২, ঘড়ি 03:12), আর গ্রুপের
/// চ্যাটে কল বোতাম চাপলে "শীঘ্রই"। ⓘ কলের পাতা সবসময় অন্ধকার — তিন রূপে একই থাকার কথা। ছবি `test/messenger_call/goldens/`-এ।
ThemeData _themeOf(AppLook look) {
  final t = withBarFonts(switch (look) {
    AppLook.glass => AppTheme.glass(GlassSpec.of(AppLooks.instance.blur.value)),
    AppLook.expressive => AppTheme.expressive(),
    AppLook.abos => AppTheme.light(),
  });
  // ⓘ রূপের নিজের ছোট বার্তার লেখা — নইলে ছবিতে বাংলা চৌকো বাক্স (ফোনে দেশি ফন্ট নিজেই আসে)
  final snack = t.snackBarTheme.contentTextStyle ?? const TextStyle();
  return t.copyWith(
      snackBarTheme: t.snackBarTheme.copyWith(contentTextStyle: snack.copyWith(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla'])));
}

const _group = ChatThread(id: 't-acc', kind: ThreadKind.group, title: 'হিসাব বিভাগ', subtitle: 'গ্রুপ · 5 জন', memberCount: 5);

/// গ্রুপের চ্যাটের মাথা — ভিডিও আর কল বোতাম (নমুনার মতো), দুটোই [Calls.forThread]
class _GroupHeader extends StatelessWidget {
  const _GroupHeader();

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.transparent,
        appBar: AppBar(
          title: const Text('হিসাব বিভাগ'),
          actions: [
            Builder(
              builder: (context) => IconButton(
                key: const Key('group-video'),
                icon: const Icon(Symbols.videocam),
                onPressed: () => Calls.forThread(context, _group, video: true),
              ),
            ),
            Builder(
              builder: (context) => IconButton(
                key: const Key('group-call'),
                icon: const Icon(Symbols.call),
                onPressed: () => Calls.forThread(context, _group, video: false),
              ),
            ),
          ],
        ),
      );
}

void main() {
  setUpAll(loadBarFonts);

  setUp(() {
    AppLooks.instance.motion.value = false; // ⓘ কাচের পটের ছোপ স্থির
    Calls.forgetActiveCall();
  });
  tearDown(() => AppLooks.instance.look.value = AppLook.abos);

  Future<FakeCallEngine> shoot(WidgetTester tester, AppLook look, Future<void> Function(BuildContext) open, {Widget? home}) async {
    tester.view.physicalSize = const Size(412, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    AppLooks.instance.look.value = look;
    final e = FakeCallEngine();
    await tester.pumpWidget(RepaintBoundary(
      key: const ValueKey('shot'),
      child: CallHarness(
        api: FakeMessengerApi(),
        engine: e,
        device: FakeCallDevice(),
        open: open,
        theme: _themeOf(look),
        frame: (context, inner) => look == AppLook.glass ? GlassBackdrop(child: inner!) : inner!,
        home: home,
      ),
    ));
    if (home == null) {
      await tester.tap(find.byKey(const Key('open-call')));
      await tester.pumpAndSettle();
    }
    return e;
  }

  Future<void> finish(WidgetTester tester) async {
    final end = find.byKey(const Key('call-end'));
    await tester.tap(end.evaluate().isNotEmpty ? end : find.byKey(const Key('call-decline')));
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
  }

  Future<void> golden(String name) =>
      expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/$name.png'));

  for (final look in AppLook.values) {
    testWidgets('incoming voice call — ${look.name}', (tester) async {
      await shoot(tester, look, (c) => Calls.incoming(c, const CallTarget(callId: 'c9', video: false, from: 'rak', threadId: 't-rak')));
      await golden('incoming_voice_${look.name}');
      await finish(tester);
    });

    testWidgets('voice call talking, big avatar and the clock — ${look.name}', (tester) async {
      final engine = await shoot(tester, look, (c) => Calls.start(c, FakeMessengerApi.rakib, video: false));
      engine.peerJoins();
      await tester.pump(const Duration(seconds: 192));
      await tester.pump();
      expect(find.text('03:12'), findsOneWidget);
      await golden('voice_call_${look.name}');
      await finish(tester);
    });

    testWidgets('one-to-one video call — ${look.name}', (tester) async {
      final engine = await shoot(tester, look, (c) => Calls.start(c, FakeMessengerApi.rakib, video: true));
      engine.peerJoins();
      await tester.pump(const Duration(seconds: 192));
      await tester.pump();
      expect(find.byKey(const Key('call-self-view')), findsOneWidget);
      await golden('video_one_to_one_${look.name}');
      await finish(tester);
    });

    testWidgets('a group chat call button says শীঘ্রই — ${look.name}', (tester) async {
      await shoot(tester, look, (_) async {}, home: const _GroupHeader());
      await tester.tap(find.byKey(const Key('group-call')));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.text('শীঘ্রই আসছে — গ্রুপ কল দ্বিতীয় ধাপে'), findsOneWidget);
      await golden('group_soon_${look.name}');
      await tester.pump(const Duration(seconds: 3));
      await tester.pumpAndSettle();
    });
  }
}
