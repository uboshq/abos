import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/core/messenger/messenger_push.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/theme_choice.dart';
import 'package:abos_mobile/core/voice/copilot_voice.dart';
import 'package:abos_mobile/features/messenger/chat/chat_pages.dart';
import 'package:abos_mobile/features/messenger/chat/wa_glyphs.dart';
import 'package:abos_mobile/features/messenger/chat/wa_palette.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fakes/fake_messenger_api.dart';
import '../support/bar_fonts.dart';
import 'chat_harness.dart';

/// ⭐ চ্যাটের পাতার কাজ — নমুনা 3nuwRUdn v7 "খ"/"গ": পাঠানো, টানা, মিউট, সদস্য, কাগজ খোলা, গ্রুপ কল "শীঘ্রই", সংযুক্তি,
/// চেপে ধরা (উত্তর, মোছা, তথ্য, ফরোয়ার্ড), খোঁজা, ইমোজি, বলে লেখা, শান্ত ভুল।
void main() {
  setUpAll(loadBarFonts);

  // ⓘ বুদবুদের লেখা Text.rich-এ (সময়ের জায়গাসহ) — তাই "ধারণ করে"; সময় আর টিক দুবার আঁকা (একটা অদৃশ্য জায়গা রাখে)
  Finder text(String s) => find.textContaining(s, findRichText: true);

  testWidgets('a typed message shows at once with a clock, then the server copy replaces it', (tester) async {
    final rig = await pumpChat(tester);
    await tester.enterText(find.byKey(const ValueKey('composer-text')), 'স্লিপ পেয়েছি');
    await tester.pump();
    expect(find.bySemanticsLabel('পাঠান'), findsOneWidget, reason: 'লেখা থাকলে মাইকের জায়গায় পাঠান');
    await tester.tap(find.byKey(const ValueKey('composer-mic')));
    await tester.pump();
    expect(rig.api.calls, contains('sendText:t-rak:স্লিপ পেয়েছি'));
    await tester.pump(const Duration(milliseconds: 10));
    expect(text('স্লিপ পেয়েছি'), findsOneWidget, reason: 'একবারই — ফোনের কপি সার্ভারের কপিতে বদলায়, দুটো হয় না');
    expect(find.byKey(const ValueKey('msg-1000')), findsOneWidget);
    await closeChat(tester);
  });

  testWidgets('a failed send shows the red mark and goes again on tap', (tester) async {
    final api = FakeMessengerApi()..failNextSend = true;
    final rig = await pumpChat(tester, api: api);
    await tester.enterText(find.byKey(const ValueKey('composer-text')), 'আবার');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('composer-mic')));
    await tester.pump(const Duration(milliseconds: 10));
    expect(find.byIcon(Icons.error), findsWidgets);
    expect(find.bySemanticsLabel(RegExp('যায়নি — আবার পাঠাতে চাপুন')), findsWidgets);
    await tester.tap(find.byIcon(Icons.error).last);
    await tester.pump(const Duration(milliseconds: 10));
    expect(rig.api.calls.where((c) => c.startsWith('sendText:t-rak:আবার')).length, 2);
    expect(find.byIcon(Icons.error), findsNothing);
    await closeChat(tester);
  });

  testWidgets('the 3 s pull brings new messages, typing, ticks and online, and marks them read', (tester) async {
    final api = FakeMessengerApi();
    final rig = await pumpChat(tester, api: api);
    int blueTicks() => tester
        .widgetList<GlyphIcon>(find.byType(GlyphIcon))
        .where((g) => g.glyph == WaGlyphs.tk2 && g.color == WaPalette.read)
        .length;
    expect(blueTicks(), 2);
    expect(api.calls, contains('markRead:t-rak:107'));
    api.incoming.add(ChatMessage(
        id: '108', threadId: 't-rak', kind: MessageKind.text, at: FakeMessengerApi.at(10, 49), sender: FakeMessengerApi.rakib, text: 'মিলেছে?'));
    api.typingNow = [FakeMessengerApi.rakib];
    api.states.add(const MessageStateUpdate(id: '107', state: MessageState.read));
    api.peerOnline = false;
    expect(find.text('অনলাইন'), findsOneWidget);
    await tester.pump(const Duration(seconds: 3));
    await tester.pump(const Duration(milliseconds: 10));
    expect(api.calls, contains('messages:t-rak::107'));
    expect(text('মিলেছে?'), findsOneWidget);
    expect(find.text('রাকিব লিখছেন…'), findsOneWidget);
    // ⓘ নীল দুই টিক: আগে 102 একা, এখন 107-ও (প্রতিটা দুবার আঁকা — একটা অদৃশ্য জায়গা রাখে)
    expect(blueTicks(), 4);
    expect(find.text('লিখছেন…'), findsOneWidget, reason: 'মাথায়ও — অবস্থার জায়গায় "লিখছেন…"');
    expect(rig.api.calls, contains('markRead:t-rak:108'));
    api.typingNow = [];
    await tester.pump(const Duration(seconds: 3));
    await tester.pump(const Duration(milliseconds: 10));
    expect(find.text('লিখছেন…'), findsNothing);
    expect(find.text('হিসাবরক্ষক · TD শাখা'), findsOneWidget, reason: 'peer_online মিথ্যা — অনলাইনের জায়গায় পদ');
    await closeChat(tester);
  });

  testWidgets('a chat push pulls at once', (tester) async {
    final api = FakeMessengerApi();
    await pumpChat(tester, api: api, poll: const Duration(minutes: 5));
    api.incoming.add(ChatMessage(id: '108', threadId: 't-rak', kind: MessageKind.text, at: FakeMessengerApi.at(10, 49), sender: FakeMessengerApi.rakib, text: 'push'));
    MessengerPush.instance.emit(const MessengerEvent('chat', {'thread_id': 't-rak'}));
    await tester.pump(const Duration(milliseconds: 10));
    expect(text('push'), findsOneWidget);
    await closeChat(tester);
  });

  testWidgets('a message deleted for everyone in the window turns into the deleted line', (tester) async {
    final api = FakeMessengerApi();
    await pumpChat(tester, api: api);
    api.states.add(const MessageStateUpdate(id: '107', state: MessageState.sent, deleted: true));
    await tester.pump(const Duration(seconds: 3));
    await tester.pump(const Duration(milliseconds: 10));
    expect(text('ঠিক আছে, স্লিপ মিলিয়ে দেখছি।'), findsNothing);
    expect(text('এই বার্তাটি মুছে ফেলা হয়েছে'), findsOneWidget);
    await closeChat(tester);
  });

  testWidgets('⋮ mutes and unmutes the chat', (tester) async {
    final rig = await pumpChat(tester);
    await tester.tap(find.byKey(const ValueKey('chat-menu')));
    await tester.pumpAndSettle();
    expect(find.text('সদস্যের তালিকা'), findsNothing, reason: 'একজনের সাথে — সদস্য নেই');
    await tester.tap(find.text('নোটিফিকেশন বন্ধ (মিউট)'));
    await tester.pumpAndSettle();
    expect(rig.api.calls, contains('mute:t-rak:true'));
    expect(find.text('নোটিফিকেশন বন্ধ হলো'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('chat-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('নোটিফিকেশন চালু করুন'));
    await tester.pumpAndSettle();
    expect(rig.api.calls, contains('mute:t-rak:false'));
    await closeChat(tester);
  });

  testWidgets('a group: members page, and the call buttons say "soon"', (tester) async {
    final rig = await pumpChat(tester, thread: 't-acc');
    expect(find.text('5 জন সদস্য · 3 জন অনলাইন'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('chat-video')));
    await tester.pump();
    expect(find.text('শীঘ্রই আসছে — গ্রুপ ভিডিও সভা দ্বিতীয় ধাপে'), findsOneWidget);
    await tester.pump(const Duration(seconds: 3));
    await tester.tap(find.byKey(const ValueKey('chat-call')));
    await tester.pump();
    expect(find.text('শীঘ্রই আসছে — গ্রুপ কল দ্বিতীয় ধাপে'), findsOneWidget);
    expect(rig.api.calls.where((c) => c.startsWith('startCall')), isEmpty);
    await tester.pump(const Duration(seconds: 3));

    await tester.tap(find.byKey(const ValueKey('chat-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('সদস্যের তালিকা'));
    await tester.pumpAndSettle();
    expect(rig.api.calls, contains('members:t-acc'));
    expect(find.text('মিতু'), findsOneWidget);
    expect(find.text('ফারজানা'), findsOneWidget);
    await closeChat(tester);
  });

  testWidgets('the group shows the sender name inside the bubble and "4 জন পড়েছেন" on mine', (tester) async {
    await pumpChat(tester, thread: 't-acc');
    expect(find.text('ফারজানা'), findsOneWidget);
    expect(find.textContaining('4 জন পড়েছেন · 10:18'), findsWidgets);
    expect(find.text('ফারজানা নাসিরকে গ্রুপে যোগ করেছেন'), findsOneWidget);
    await closeChat(tester);
  });

  testWidgets('খুলুন opens the paper with permission; without it a short message, nothing opens', (tester) async {
    const denied = PaperRef(type: 'MoneyReceipt', id: '9', no: 'RCV-0009', title: 'রসিদ', canOpen: false);
    final api = FakeMessengerApi(messages: {
      ...FakeMessengerApi.sampleMessages(),
      't-rak': [
        ...FakeMessengerApi.sampleMessages()['t-rak']!,
        ChatMessage(id: '120', threadId: 't-rak', kind: MessageKind.paper, at: FakeMessengerApi.at(10, 48), sender: FakeMessengerApi.rakib, paper: denied),
      ],
    });
    final rig = await pumpChat(tester, api: api);
    expect(find.text('রসিদ RCV-0009'), findsOneWidget, reason: 'অনুমতি না থাকলেও নম্বর আর নাম থাকে');
    expect(find.textContaining('যাঁর অনুমতি আছে'), findsNothing, reason: 'নমুনা v6: তালার লাইন নেই');
    await tester.tap(find.byKey(const ValueKey('paper-open-MoneyReceipt-9')));
    await tester.pump();
    expect(find.text('এই কাগজ খোলার অনুমতি আপনার নেই'), findsOneWidget);
    expect(rig.opened, isEmpty);
    await tester.pump(const Duration(seconds: 3));

    await tester.dragUntilVisible(find.byKey(const ValueKey('paper-open-MoneyReceipt-142')), find.byKey(const ValueKey('chat-list')), const Offset(0, 200));
    await tester.tap(find.byKey(const ValueKey('paper-open-MoneyReceipt-142')));
    await tester.pump();
    expect(rig.opened.single.no, 'RCV-0142');
    await closeChat(tester);
  });

  testWidgets('the clip opens the tray with its five items; ERP কাগজ sends a recent paper to this chat', (tester) async {
    const paper = PaperRef(type: 'SalesInvoice', id: 'p1', no: 'INV-0517', title: 'বিক্রয় চালান');
    final rig = await pumpChat(tester, recentPapers: () async => [const PaperChoice(paper: paper, label: 'INV-0517 · রহমান এন্টারপ্রাইজ')]);
    expect(find.byKey(const ValueKey('attach-tray')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('composer-attach')));
    await tester.pump();
    expect(find.byKey(const ValueKey('attach-tray')), findsOneWidget);
    for (final s in ['ফাইল', 'ক্যামেরা', 'ছবি', 'ERP কাগজ', 'অবস্থান']) {
      expect(find.descendant(of: find.byKey(const ValueKey('attach-tray')), matching: find.text(s)), findsOneWidget);
    }
    await tester.tap(find.byKey(const ValueKey('tray-paper')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('INV-0517 · রহমান এন্টারপ্রাইজ'));
    await tester.pumpAndSettle();
    expect(rig.api.calls, contains('sendPaper:SalesInvoice:p1:thread:t-rak:'));
    expect(find.byKey(const ValueKey('attach-tray')), findsNothing);
    await closeChat(tester);
  });

  testWidgets('long-press → reply: the composer shows the quote, the sent bubble carries it', (tester) async {
    final rig = await pumpChat(tester);
    await tester.longPress(find.byKey(const ValueKey('msg-106')));
    await tester.pump();
    expect(find.byKey(const ValueKey('chat-action-bar')), findsOneWidget);
    for (final s in ['উত্তর দিন', 'ফরোয়ার্ড', 'কপি', 'মুছুন', 'তথ্য']) {
      expect(find.text(s), findsOneWidget);
    }
    await tester.tap(find.byKey(const ValueKey('chat-act-reply')));
    await tester.pump();
    expect(find.byKey(const ValueKey('composer-reply')), findsOneWidget);
    await tester.enterText(find.byKey(const ValueKey('composer-text')), 'পেয়েছি');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('composer-mic')));
    await tester.pump(const Duration(milliseconds: 10));
    expect(rig.api.calls, contains('sendText:t-rak:পেয়েছি:reply=106'));
    expect(find.byKey(const ValueKey('composer-reply')), findsNothing);
    expect(find.text('রাকিব হাসান'), findsWidgets, reason: 'বুদবুদের উদ্ধৃতিতে নাম');
    await closeChat(tester);
  });

  testWidgets('delete: mine offers everyone and me; another\'s only me; a refused "everyone" says the server\'s sentence', (tester) async {
    final api = FakeMessengerApi()..deleteEveryoneRefused = true;
    await pumpChat(tester, api: api);
    await tester.longPress(find.byKey(const ValueKey('msg-106')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('chat-act-delete')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('delete-everyone')), findsNothing);
    expect(find.byKey(const ValueKey('delete-me')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('delete-me')));
    await tester.pumpAndSettle();
    expect(api.calls, contains('delete:106:me'));
    expect(find.byKey(const ValueKey('msg-106')), findsNothing);

    await tester.longPress(find.byKey(const ValueKey('msg-107')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('chat-act-delete')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('delete-everyone')));
    await tester.pump();
    await tester.pump();
    expect(api.calls, contains('delete:107:everyone'));
    expect(find.text('এক ঘণ্টা পেরিয়ে গেছে — এখন কেবল নিজের কাছ থেকে মোছা যায়'), findsOneWidget);
    await tester.pump(const Duration(seconds: 3));
    await closeChat(tester);
  });

  testWidgets('delete for everyone only within 60 minutes of sending; after that only "for me"', (tester) async {
    const at = FakeMessengerApi.at;
    final api = FakeMessengerApi(messages: {
      't-rak': [
        ChatMessage(id: '401', threadId: 't-rak', kind: MessageKind.text, at: at(9, 40), mine: true, state: MessageState.read, text: 'পুরনো'),
        ChatMessage(id: '402', threadId: 't-rak', kind: MessageKind.text, at: at(9, 55), mine: true, state: MessageState.read, text: 'নতুন'),
      ],
    });
    await pumpChat(tester, api: api);
    await tester.longPress(find.byKey(const ValueKey('msg-401')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('chat-act-delete')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('delete-everyone')), findsNothing, reason: '10:50-এ 9:40-এর বার্তা — ৭০ মিনিট পেরিয়েছে');
    await tester.tap(find.byKey(const ValueKey('delete-cancel')));
    await tester.pumpAndSettle();

    await tester.longPress(find.byKey(const ValueKey('msg-402')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('chat-act-delete')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('delete-everyone')));
    await tester.pumpAndSettle();
    expect(api.calls, contains('delete:402:everyone'));
    expect(text('এই বার্তাটি মুছে ফেলা হয়েছে'), findsOneWidget);
    await closeChat(tester);
  });

  testWidgets('without the call doors (/me features.calls false) there are no call or video buttons', (tester) async {
    await pumpChat(tester, calls: false);
    expect(find.byKey(const ValueKey('chat-call')), findsNothing);
    expect(find.byKey(const ValueKey('chat-video')), findsNothing);
    expect(find.byKey(const ValueKey('chat-menu')), findsOneWidget);
    await closeChat(tester);
  });

  testWidgets('typing tells the server at most once every 3 s', (tester) async {
    final rig = await pumpChat(tester, poll: const Duration(minutes: 5));
    await tester.enterText(find.byKey(const ValueKey('composer-text')), 'স');
    await tester.enterText(find.byKey(const ValueKey('composer-text')), 'স্লি');
    await tester.enterText(find.byKey(const ValueKey('composer-text')), 'স্লিপ');
    await tester.pump();
    expect(rig.api.calls.where((c) => c == 'typing:t-rak').length, 1);
    await closeChat(tester);
  });

  testWidgets('info shows delivered and read times; copy and forward work', (tester) async {
    final api = FakeMessengerApi();
    await pumpChat(tester, api: api);
    final copied = <String>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, (call) async {
      if (call.method == 'Clipboard.setData') copied.add('${(call.arguments as Map)['text']}');
      return null;
    });
    addTearDown(() => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(SystemChannels.platform, null));

    await tester.longPress(find.byKey(const ValueKey('msg-107')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('chat-act-info')));
    await tester.pumpAndSettle();
    expect(find.text('বার্তার তথ্য'), findsOneWidget);
    expect(find.text('আজ 10:45'), findsOneWidget);
    expect(find.text('আজ 10:46'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('info-ok')));
    await tester.pumpAndSettle();

    await tester.longPress(find.byKey(const ValueKey('msg-107')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('chat-act-copy')));
    await tester.pump();
    expect(copied, ['ঠিক আছে, স্লিপ মিলিয়ে দেখছি।']);
    await tester.pump(const Duration(seconds: 3));

    await tester.longPress(find.byKey(const ValueKey('msg-107')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('chat-act-forward')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('forward-t-nas')));
    await tester.pump();
    // মানুষ — যাঁর সাথে কথোপকথন নেই (মিতু); যাঁর আছে (রাকিব) তিনি "মানুষ"-এ আবার আসেন না
    expect(find.byKey(const ValueKey('forward-person-rak')), findsNothing);
    await tester.scrollUntilVisible(find.byKey(const ValueKey('forward-person-mit')), 100,
        scrollable: find.descendant(of: find.byType(BottomSheet), matching: find.byType(Scrollable)).first);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('forward-person-mit')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('forward-send')));
    await tester.pumpAndSettle();
    expect(api.calls, contains('forward:107:thread:t-nas,person:mit'));
    await closeChat(tester);
  });

  testWidgets('search in the chat finds and counts the matches', (tester) async {
    final api = FakeMessengerApi();
    await pumpChat(tester, api: api);
    await tester.tap(find.byKey(const ValueKey('chat-menu')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('কথোপকথনে খুঁজুন'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('chat-search-field')), 'স্লিপ');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.calls, contains('search:t-rak:স্লিপ'));
    expect(find.text('2/2'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('chat-search-prev')));
    await tester.pumpAndSettle();
    expect(find.text('1/2'), findsOneWidget);
    await closeChat(tester);
  });

  testWidgets('emoji: the button opens the panel, becomes the keyboard, and a tap types the emoji', (tester) async {
    await pumpChat(tester);
    await tester.tap(find.byKey(const ValueKey('composer-emoji')));
    await tester.pump();
    expect(find.byKey(const ValueKey('emoji-panel')), findsOneWidget);
    expect(find.bySemanticsLabel('কিবোর্ড'), findsOneWidget);
    expect(find.text('ইমোজি খুঁজুন'), findsOneWidget);
    await tester.tap(find.text('👍').first);
    await tester.pump();
    expect(find.text('👍'), findsWidgets);
    expect(tester.widget<TextField>(find.byKey(const ValueKey('composer-text'))).controller!.text, '👍');
    expect(find.bySemanticsLabel('পাঠান'), findsOneWidget);
    await closeChat(tester);
  });

  group('voice typing (বলে লিখুন)', () {
    testWidgets('hidden when the phone has no recogniser', (tester) async {
      await pumpChat(tester, voice: FakeCopilotVoice(canListen: false));
      expect(find.byKey(const ValueKey('composer-voice-type')), findsNothing);
      await closeChat(tester);
    });

    testWidgets('partials fill the box after what was typed; the round button becomes send; tap stops', (tester) async {
      final voice = FakeCopilotVoice(canListen: true);
      final rig = await pumpChat(tester, voice: voice);
      await tester.enterText(find.byKey(const ValueKey('composer-text')), 'স্যার,');
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('composer-voice-type')));
      await tester.pump();
      expect(voice.calls, contains('listen:bn-BD'));
      expect(find.text('শুনছি…'), findsOneWidget);
      voice.emit(const VoiceEvent(VoiceEventKind.partial, text: 'রসিদটা অনুমোদন'));
      await tester.pump();
      final field = tester.widget<TextField>(find.byKey(const ValueKey('composer-text')));
      expect(field.controller!.text, 'স্যার, রসিদটা অনুমোদন');
      expect(find.bySemanticsLabel('পাঠান'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('composer-voice-type')));
      await tester.pump();
      expect(voice.calls, contains('stopListening'));
      expect(find.text('শুনছি…'), findsNothing);
      expect(field.controller!.text, 'স্যার, রসিদটা অনুমোদন', reason: 'থামলে লেখা থাকে, নিজে থেকে যায় না');
      expect(rig.api.calls.where((c) => c.startsWith('sendText')), isEmpty);
      await closeChat(tester);
    });

    testWidgets('a refused microphone says so briefly', (tester) async {
      final voice = FakeCopilotVoice(canListen: true);
      await pumpChat(tester, voice: voice);
      await tester.tap(find.byKey(const ValueKey('composer-voice-type')));
      await tester.pump();
      voice.emit(const VoiceEvent(VoiceEventKind.error, code: 'permission'));
      await tester.pump();
      expect(find.text('মাইকের অনুমতি দেওয়া হয়নি — বলে লেখা যাবে না'), findsOneWidget);
      await tester.pump(const Duration(seconds: 3));
      await closeChat(tester);
    });
  });

  testWidgets('doors missing or not allowed: a calm sentence, no composer, no crash', (tester) async {
    await pumpChat(tester, api: FakeMessengerApi(doorsMissing: true));
    await tester.pump();
    expect(find.text('বার্তা এখনো চালু হয়নি'), findsOneWidget);
    expect(find.byKey(const ValueKey('composer-text')), findsNothing);
    await closeChat(tester);
  });

  testWidgets('glass and smart looks follow the person\'s light/dark choice, not the look\'s own theme', (tester) async {
    Color wall() => tester.widget<Scaffold>(find.byType(Scaffold).first).backgroundColor!;
    addTearDown(() => ThemeChoices.instance.current.value = ThemeChoice.phone);

    ThemeChoices.instance.current.value = ThemeChoice.light;
    await pumpChat(tester, look: AppLook.glass);
    expect(wall(), WaPalette.light.wall, reason: 'গ্লাসের থিম অন্ধকার, তবু মানুষ আলো বেছেছেন');
    await closeChat(tester);

    ThemeChoices.instance.current.value = ThemeChoice.dark;
    await pumpChat(tester, look: AppLook.expressive);
    expect(wall(), WaPalette.darkPalette.wall, reason: 'স্মার্টের থিম আলো, তবু মানুষ অন্ধকার বেছেছেন');
    await closeChat(tester);
  });

  testWidgets('at 200% text the chat lays out without overflow', (tester) async {
    await pumpChat(tester, textScale: 2);
    await tester.pump();
    expect(tester.takeException(), isNull);
    await tester.tap(find.byKey(const ValueKey('composer-attach')));
    await tester.pump();
    expect(tester.takeException(), isNull);
    await closeChat(tester);
  });
}
