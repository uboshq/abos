import 'dart:io';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/core/voice/copilot_voice.dart';
import 'package:abos_mobile/features/messenger/chat/chat_pages.dart';
import 'package:abos_mobile/features/messenger/chat/chat_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fakes/fake_messenger_api.dart';
import '../support/bar_fonts.dart';

/// ⭐ চ্যাটের পাতার পরীক্ষার মঞ্চ — নমুনার কথোপকথন ('t-rak') আর গ্রুপ ('t-acc'), ঘড়ি স্থির (২১ নভেম্বর ২০২৬, 10:50)।
DateTime chatClock() => DateTime(2026, 11, 21, 10, 50);

ChatThread sampleThread(String id) => FakeMessengerApi.sampleThreads().firstWhere((t) => t.id == id);

/// রঙিন ইমোজি ফন্ট (এই PC-র) — ছবিতে ইমোজি চৌকো না হয়
Future<void> loadEmojiFont() async {
  final f = File('C:/Windows/Fonts/seguiemj.ttf');
  if (!f.existsSync()) return;
  final loader = FontLoader('Emoji')..addFont(Future.value(ByteData.view(Uint8List.fromList(f.readAsBytesSync()).buffer)));
  await loader.load();
}

/// অ্যাপের রূপ অনুযায়ী থিম — main.dart-এর একই বাছাই (গ্লাস সবসময় কাচের অন্ধকার, স্মার্ট সবসময় আলো)
ThemeData chatTheme({bool dark = false, AppLook look = AppLook.abos}) {
  final t = withBarFonts(switch (look) {
    AppLook.glass => AppTheme.glass(GlassSpec.current),
    AppLook.expressive => AppTheme.expressive(),
    AppLook.abos => dark ? AppTheme.dark() : AppTheme.light(),
  });
  return t.copyWith(
    textTheme: t.textTheme.apply(fontFamily: 'Roboto', fontFamilyFallback: const ['Bangla', 'Emoji']),
  );
}

class ChatRig {
  ChatRig(this.api, this.voice);

  final FakeMessengerApi api;
  final FakeCopilotVoice voice;
  final List<PaperRef> opened = [];
}

Future<ChatRig> pumpChat(
  WidgetTester tester, {
  String thread = 't-rak',
  FakeMessengerApi? api,
  FakeCopilotVoice? voice,
  bool dark = false,
  AppLook look = AppLook.abos,
  bool calls = true,
  Size size = const Size(412, 900),
  double textScale = 1,
  Duration poll = const Duration(seconds: 3),
  Future<List<PaperChoice>> Function()? recentPapers,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  AppLooks.instance.look.value = look;
  addTearDown(() => AppLooks.instance.look.value = AppLook.abos);
  final rig = ChatRig(api ?? FakeMessengerApi(), voice ?? FakeCopilotVoice(canListen: true));
  await tester.pumpWidget(ProviderScope(
    overrides: [messengerApiProvider.overrideWithValue(rig.api)],
    child: RepaintBoundary(
      key: const ValueKey('shot'),
      child: MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: chatTheme(dark: dark, look: look),
        locale: AppLocale.locale,
        supportedLocales: AppLocale.supported,
        localizationsDelegates: AppLocale.delegates,
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(textScale), disableAnimations: true),
          child: child!,
        ),
        home: ChatScreen(
          thread: sampleThread(thread),
          me: FakeMessengerApi.shuvo,
          now: chatClock,
          voice: rig.voice,
          pollFast: poll,
          pollSlow: poll,
          loadBytes: (_) async => Uint8List(0),
          recentPapers: recentPapers,
          callsEnabled: calls,
          openPaper: (context, p) async => rig.opened.add(p),
        ),
      ),
    ),
  ));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
  return rig;
}

/// পাতা বন্ধ করে টাইমার থামানো — পরীক্ষার শেষে ঝুলে থাকা টাইমার নয়
Future<void> closeChat(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox());
  await tester.pump(const Duration(seconds: 3));
}
