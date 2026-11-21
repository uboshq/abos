import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/voice/copilot_voice.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fakes/fake_messenger_api.dart';
import '../support/bar_fonts.dart';
import 'chat_harness.dart';

/// ⭐ চ্যাটের পাতার ছবি — নমুনা 3nuwRUdn v7 "খ"/"গ"-এর পাশে রেখে দেখার জন্য, ৪১২×৯০০, ঘড়ি স্থির।
///
/// <p>⭐ তিন রূপেই (ক্লাসিক `abos`, গ্লাস `glass`, স্মার্ট `expressive`) একই পাতা — প্রতিটা পর্দা তিন রূপে:
/// কথোপকথন (একজন), গ্রুপ, চেপে ধরার কাজের বার, মোছার বাছাই, বার্তার তথ্য, ইমোজি, বলে লেখা (শুনছি…), ভয়েস আর ফাইলের বুদবুদ।
/// সাথে ক্লাসিকের অন্ধকার, সংযুক্তি খোলা আর গ্রুপের ⋮। ছবিগুলো `test/messenger_chat/goldens/`-এ।
void main() {
  setUpAll(() async {
    await loadBarFonts();
    await loadEmojiFont();
  });

  /// নমুনার মতো — রাকিব লিখছেন…
  FakeMessengerApi typing() => FakeMessengerApi()..typingNow = [FakeMessengerApi.rakib];

  /// প্রথম টান (লিখছেন…) পর্যন্ত
  Future<void> settle(WidgetTester tester) async {
    await tester.pump(const Duration(seconds: 3));
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pump(const Duration(milliseconds: 50));
  }

  Future<void> shoot(WidgetTester tester, String name) =>
      expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/$name.png'));

  /// নমুনা v7-এর বার্তাগুলো — আমার উত্তরে রাকিবের প্রথম বার্তার উদ্ধৃতি
  FakeMessengerApi replyThread() {
    const rak = FakeMessengerApi.rakib;
    const at = FakeMessengerApi.at;
    return FakeMessengerApi(messages: {
      't-rak': [
        ChatMessage(id: '301', threadId: 't-rak', kind: MessageKind.text, at: at(10, 38), sender: rak, text: 'স্যার, রহমান এন্টারপ্রাইজ আজ ব্যাংকে টাকা জমা দিয়েছে।'),
        ChatMessage(id: '302', threadId: 't-rak', kind: MessageKind.paper, at: at(10, 40), sender: rak, text: 'রসিদ বানিয়েছি, অনুমোদন দিন।', paper: FakeMessengerApi.rcv142),
        ChatMessage(
            id: '303',
            threadId: 't-rak',
            kind: MessageKind.text,
            at: at(10, 42),
            mine: true,
            state: MessageState.read,
            text: 'কত টাকা? স্লিপের ছবি দিন।',
            replyTo: const ReplyRef(id: '301', text: 'স্যার, রহমান এন্টারপ্রাইজ আজ ব্যাংকে টাকা জমা দিয়েছে।', sender: rak)),
        ChatMessage(id: '304', threadId: 't-rak', kind: MessageKind.text, at: at(10, 43), sender: rak, text: '৳1,24,500 — স্লিপ ছবি করে দিয়েছি।'),
        ChatMessage(id: '305', threadId: 't-rak', kind: MessageKind.text, at: at(10, 45), mine: true, state: MessageState.read, text: 'ঠিক আছে, মিলিয়ে অনুমোদন দিচ্ছি।'),
      ],
    });
  }

  /// ভয়েস (দুজনের) আর ফাইল (PDF, Excel) — নীল/ধূসর টিকসহ
  FakeMessengerApi voiceAndFiles() {
    const rak = FakeMessengerApi.rakib;
    const at = FakeMessengerApi.at;
    const wave = [.2, .5, .8, .4, .9, .6, .3, .7, .5, .2, .6, .9, .4, .3, .8, .5, .2, .4, .7, .3, .6, .5, .9, .4, .2, .5, .3];
    return FakeMessengerApi(messages: {
      't-rak': [
        ChatMessage(
            id: '501', threadId: 't-rak', kind: MessageKind.voice, at: at(10, 30), sender: rak,
            attachment: const ChatAttachment(url: '', durationMs: 41000, waveform: wave)),
        ChatMessage(
            id: '502', threadId: 't-rak', kind: MessageKind.voice, at: at(10, 33), mine: true, state: MessageState.read,
            attachment: const ChatAttachment(url: '', durationMs: 12000, waveform: wave)),
        ChatMessage(
            id: '503', threadId: 't-rak', kind: MessageKind.file, at: at(10, 36), sender: rak,
            attachment: const ChatAttachment(url: '', name: 'নভেম্বর-জমার-তালিকা.pdf', mime: 'application/pdf', size: 240 * 1024)),
        ChatMessage(
            id: '504', threadId: 't-rak', kind: MessageKind.file, at: at(10, 38), mine: true, state: MessageState.delivered,
            text: 'মিলিয়ে দেখুন',
            attachment: const ChatAttachment(
                url: '', name: 'দেনাদার-তালিকা.xlsx', mime: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', size: 58 * 1024)),
        ChatMessage(
            id: '505', threadId: 't-rak', kind: MessageKind.voice, at: at(10, 40), mine: true, state: MessageState.sent,
            attachment: const ChatAttachment(url: '', durationMs: 7000, waveform: wave)),
      ],
    });
  }

  for (final look in AppLook.values) {
    final n = look.name;

    group('look $n', () {
      testWidgets('conversation (one person)', (tester) async {
        await pumpChat(tester, api: typing(), look: look);
        await settle(tester);
        await shoot(tester, 'chat_conversation_$n');
        await closeChat(tester);
      });

      testWidgets('group conversation', (tester) async {
        await pumpChat(tester, thread: 't-acc', api: FakeMessengerApi()..typingNow = [FakeMessengerApi.farzana], look: look);
        await settle(tester);
        await shoot(tester, 'chat_group_$n');
        await closeChat(tester);
      });

      testWidgets('long-press: the action bar over a selected reply bubble', (tester) async {
        await pumpChat(tester, api: replyThread(), look: look);
        await tester.pump(const Duration(milliseconds: 50));
        await tester.longPress(find.byKey(const ValueKey('msg-305')));
        await tester.pump();
        await shoot(tester, 'chat_actions_$n');
        await closeChat(tester);
      });

      testWidgets('delete choice', (tester) async {
        await pumpChat(tester, api: replyThread(), look: look);
        await tester.pump(const Duration(milliseconds: 50));
        await tester.longPress(find.byKey(const ValueKey('msg-305')));
        await tester.pump();
        await tester.tap(find.byKey(const ValueKey('chat-act-delete')));
        await tester.pumpAndSettle();
        await shoot(tester, 'chat_delete_$n');
        await closeChat(tester);
      });

      testWidgets('message info', (tester) async {
        await pumpChat(tester, api: replyThread(), look: look);
        await tester.pump(const Duration(milliseconds: 50));
        await tester.longPress(find.byKey(const ValueKey('msg-305')));
        await tester.pump();
        await tester.tap(find.byKey(const ValueKey('chat-act-info')));
        await tester.pumpAndSettle();
        await shoot(tester, 'chat_info_$n');
        await closeChat(tester);
      });

      testWidgets('emoji panel', (tester) async {
        await pumpChat(tester, api: typing(), look: look);
        await settle(tester);
        await tester.tap(find.byKey(const ValueKey('composer-emoji')));
        await tester.pump();
        await shoot(tester, 'chat_emoji_$n');
        await closeChat(tester);
      });

      testWidgets('voice typing — listening, live words, round button is send', (tester) async {
        final voice = FakeCopilotVoice(canListen: true);
        await pumpChat(tester, api: typing(), voice: voice, look: look);
        await settle(tester);
        await tester.tap(find.byKey(const ValueKey('composer-voice-type')));
        await tester.pump();
        voice.emit(const VoiceEvent(VoiceEventKind.partial, text: 'স্যার, রসিদটা অনুমোদন দিচ্ছি, বিকেলে'));
        await tester.pump();
        await shoot(tester, 'chat_voice_typing_$n');
        await closeChat(tester);
      });

      testWidgets('voice and file bubbles', (tester) async {
        await pumpChat(tester, api: voiceAndFiles(), look: look);
        await tester.pump(const Duration(milliseconds: 50));
        await shoot(tester, 'chat_voice_files_$n');
        await closeChat(tester);
      });
    });
  }

  // ── ক্লাসিকের বাড়তি ছবি ───────────────────────────────────────
  testWidgets('conversation with the attach tray open', (tester) async {
    await pumpChat(tester, api: typing());
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('composer-attach')));
    await tester.pump();
    await shoot(tester, 'chat_conversation_tray');
    await closeChat(tester);
  });

  testWidgets('conversation, dark', (tester) async {
    await pumpChat(tester, api: typing(), dark: true);
    await settle(tester);
    await shoot(tester, 'chat_conversation_dark');
    await closeChat(tester);
  });

  testWidgets('group with ⋮ open', (tester) async {
    await pumpChat(tester, thread: 't-acc', api: FakeMessengerApi()..typingNow = [FakeMessengerApi.farzana]);
    await settle(tester);
    await tester.tap(find.byKey(const ValueKey('chat-menu')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    await shoot(tester, 'chat_group_menu');
    await closeChat(tester);
  });
}
