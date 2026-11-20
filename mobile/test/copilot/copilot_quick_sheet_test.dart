import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/voice/copilot_voice.dart';
import 'package:abos_mobile/features/copilot/copilot_mark.dart';
import 'package:abos_mobile/features/copilot/copilot_quick_sheet.dart';
import 'package:abos_mobile/features/copilot/copilot_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:abos_mobile/features/home/nav_bottom_bar.dart';
import 'package:abos_mobile/core/navigation/role_tabs.dart';

/// ⭐ মালিক, ১২ নভেম্বর ২০২৬: "সিরি এবং জেমিনাইয়ের মতো … মোবাইল অ্যাপের ডিজাইনটাও … যেভাবে চ্যাট করে চ্যাট বক্সে";
/// নমুনা 3uJhK92DAMhwyELt63mSmr, ফোনের তিন ধাপ — খুলল, শুনছে, চ্যাট।
///
/// <p>দাবি: প্রশ্ন ডানে আর উত্তর বাঁয়ে; লিখে জিজ্ঞেস করলে চুপ, বলে জিজ্ঞেস করলে উত্তরও শোনায়; বলা কথা সাথে সাথে লেখা হয়;
/// মাইকের অনুমতি না পেলে লিখে জিজ্ঞেস করতে বলে; উপরের বারে মগজ কেবল কোপাইলটের চাবিওয়ালার।
void main() {
  Widget app(Widget home) => MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light(),
        locale: AppLocale.locale,
        supportedLocales: AppLocale.supported,
        localizationsDelegates: AppLocale.delegates,
        home: home,
      );

  Future<(_FakeApi, FakeCopilotVoice)> open(WidgetTester tester, {bool canListen = true}) async {
    final api = _FakeApi();
    final voice = FakeCopilotVoice(canListen: canListen);
    await tester.pumpWidget(app(CopilotQuickSheet(page: 'বিক্রয়', api: api, voice: voice, onFull: () {})));
    await tester.pumpAndSettle();
    return (api, voice);
  }

  testWidgets('a typed question sits on the right, its answer on the left beside the brain, and stays silent', (tester) async {
    final (api, voice) = await open(tester);

    expect(find.text('আপনি আছেন: বিক্রয়'), findsOneWidget, reason: '⛔ কোন পাতা থেকে খোলা, লেখা নেই');
    expect(find.byKey(const ValueKey('copilot-quick-example-আজ কত বিক্রি হলো?')), findsOneWidget, reason: '⛔ উদাহরণ নেই');

    await tester.enterText(find.byKey(const ValueKey('copilot-quick-input')), 'এ মাসে কত বিক্রি');
    await tester.tap(find.byKey(const ValueKey('copilot-quick-send')));
    await tester.pumpAndSettle();

    expect(api.asked, ['এ মাসে কত বিক্রি']);
    final question = tester.getCenter(find.text('এ মাসে কত বিক্রি'));
    final summary = tester.getCenter(find.byKey(const ValueKey('copilot-quick-summary')));
    expect(question.dx, greaterThan(summary.dx), reason: '⛔ প্রশ্ন ডানে নয়, উত্তর বাঁয়ে নয়');
    expect(find.text('এ মাসে নিট বিক্রি ৳৪৮,৬৫০'), findsOneWidget);
    expect(find.text('মাহাবুব এন্টারপ্রাইজ'), findsOneWidget, reason: '⛔ উত্তরের সারি নেই');
    expect(find.descendant(of: find.byKey(const ValueKey('copilot-quick')), matching: find.byType(CopilotMark)), findsWidgets);
    expect(voice.spoken, isEmpty, reason: '⛔ লিখে জিজ্ঞেস করা উত্তর নিজে নিজে পড়ে শোনাল');

    // ⓘ "শুনুন" চাপলে শোনায়, আবার চাপলে থামে
    await tester.tap(find.byKey(const ValueKey('copilot-quick-speak')));
    await tester.pump();
    expect(voice.spoken, ['এ মাসে নিট বিক্রি ৳৪৮,৬৫০']);
    expect(voice.calls, contains('speak:bn-BD'), reason: '⛔ বাংলার কণ্ঠে নয়');
    expect(find.text('থামান'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('copilot-quick-speak')));
    await tester.pump();
    expect(voice.calls.last, 'stopSpeaking');
  });

  // ⭐ মালিক, ১৩ নভেম্বর ২০২৬: "Claude অ্যাপের মতো — যতক্ষণ মন চায় কথা বলা, শেষ হলে সেন্ড"; ফোন চুপ থাকলে থামলেও শোনা চলে
  testWidgets('a pause does not end the question: the phone listens again and the words pile up until Send', (tester) async {
    final (api, voice) = await open(tester);

    await tester.tap(find.byKey(const ValueKey('copilot-quick-mic')));
    await tester.pump();
    expect(voice.calls, contains('listen:bn-BD'));
    expect(find.byKey(const ValueKey('copilot-quick-listening')), findsOneWidget, reason: '⛔ শোনার পর্দা খোলেনি');

    voice.emit(const VoiceEvent(VoiceEventKind.partial, text: 'আজ কত'));
    await tester.pump();
    expect(find.text('আজ কত'), findsOneWidget, reason: '⛔ বলা কথা সাথে সাথে লেখা হয় না');

    // ⓘ মানুষ থামলেন, ফোনের কথা-চেনা শেষ করল — প্রশ্ন যাবে না, আবার শুনবে
    voice.emit(const VoiceEvent(VoiceEventKind.done, text: 'আজ কত বিক্রি হলো'));
    await tester.pump();
    expect(api.asked, isEmpty, reason: '⛔ একটু থামতেই আধা প্রশ্ন চলে গেল');
    expect(voice.calls.where((c) => c == 'listen:bn-BD').length, 2, reason: '⛔ থামার পরে আবার শোনা শুরু হয়নি');
    voice.emit(const VoiceEvent(VoiceEventKind.error, code: 'no_speech'));
    await tester.pump();
    expect(find.byKey(const ValueKey('copilot-quick-listening')), findsOneWidget, reason: '⛔ চুপ থাকায় শোনা বন্ধ হলো');

    voice.emit(const VoiceEvent(VoiceEventKind.partial, text: 'আর কার কাছে বাকি'));
    await tester.pumpAndSettle();
    expect(find.text('আজ কত বিক্রি হলো আর কার কাছে বাকি'), findsOneWidget, reason: '⛔ আগের কথা মুছে নতুনটা — জমছে না');

    await tester.tap(find.byKey(const ValueKey('copilot-quick-send-now')));
    await tester.pump();
    expect(voice.calls, contains('stopListening'));
    voice.emit(const VoiceEvent(VoiceEventKind.done, text: 'আর কার কাছে বাকি'));
    await tester.pumpAndSettle();
    expect(api.asked, ['আজ কত বিক্রি হলো আর কার কাছে বাকি'], reason: '⛔ "পাঠান"-এ পুরো প্রশ্নটা যায়নি');
    expect(voice.spoken, ['এ মাসে নিট বিক্রি ৳৪৮,৬৫০'], reason: '⛔ বলে জিজ্ঞেস করা উত্তর শোনাল না');
  });

  testWidgets('"Done" puts the words in the box to check, and sending them still reads the answer aloud', (tester) async {
    final (api, voice) = await open(tester);

    await tester.tap(find.byKey(const ValueKey('copilot-quick-mic')));
    await tester.pump();
    voice.emit(const VoiceEvent(VoiceEventKind.partial, text: 'চাল কত আছে'));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('copilot-quick-done')));
    await tester.pump();
    // ⓘ ফোন শেষ টুকরো না পাঠালেও একটু পরে নিজে শেষ — বোতাম চেপে আটকে থাকা নয়
    await tester.pump(const Duration(seconds: 2));

    expect(api.asked, isEmpty, reason: '⛔ "বলা শেষ"-এ দেখার আগেই চলে গেল');
    final box = tester.widget<TextField>(find.byKey(const ValueKey('copilot-quick-input')));
    expect(box.controller!.text, 'চাল কত আছে', reason: '⛔ বলা কথা লেখার ঘরে আসেনি');

    await tester.tap(find.byKey(const ValueKey('copilot-quick-send')));
    await tester.pumpAndSettle();
    expect(api.asked, ['চাল কত আছে']);
    expect(voice.spoken, isNotEmpty, reason: '⛔ মুখে বলা প্রশ্ন ঘর থেকে পাঠালে উত্তর শোনাল না');
  });

  testWidgets('a forgotten open microphone stops by itself after two minutes and keeps what was said', (tester) async {
    final (api, voice) = await open(tester);

    await tester.tap(find.byKey(const ValueKey('copilot-quick-mic')));
    await tester.pump();
    voice.emit(const VoiceEvent(VoiceEventKind.partial, text: 'বকেয়া'));
    await tester.pump();
    await tester.pump(const Duration(minutes: 2, seconds: 3));

    expect(voice.calls, contains('stopListening'), reason: '⛔ ভুলে খোলা মাইক থামল না');
    expect(find.byKey(const ValueKey('copilot-quick-listening')), findsNothing);
    final box = tester.widget<TextField>(find.byKey(const ValueKey('copilot-quick-input')));
    expect(box.controller!.text, 'বকেয়া', reason: '⛔ নিজে থামলে বলা কথা হারাল');
    expect(api.asked, isEmpty);
  });

  testWidgets('without the microphone permission it says so and the typing box comes back', (tester) async {
    final (_, voice) = await open(tester);

    await tester.tap(find.byKey(const ValueKey('copilot-quick-mic')));
    await tester.pump();
    voice.emit(const VoiceEvent(VoiceEventKind.error, code: 'permission'));
    await tester.pumpAndSettle();

    expect(find.text('মাইকের অনুমতি নেই — লিখে জিজ্ঞেস করুন।'), findsOneWidget);
    expect(find.byKey(const ValueKey('copilot-quick-input')), findsOneWidget, reason: '⛔ লেখার ঘর ফেরেনি');
  });

  testWidgets('a phone that cannot recognise speech shows no microphone at all', (tester) async {
    await open(tester, canListen: false);
    expect(find.byKey(const ValueKey('copilot-quick-mic')), findsNothing, reason: '⛔ কথা-চেনা নেই, তবু মাইক');
    expect(find.byKey(const ValueKey('copilot-quick-input')), findsOneWidget);
  });

  // ⭐ ২০ নভেম্বর ২০২৬ (মালিকের "Ok", নমুনা v8): Copilot উপরের বার থেকে নিচের বারের দ্বিতীয় ঘরে — কেবল যাঁর চাবি আছে
  testWidgets('Copilot is the bottom bar\'s second tab, only for a person with the Copilot key; never on the top bar', (tester) async {
    await tester.pumpWidget(app(Scaffold(
      appBar: AbosTopBar(company: 'ADI', branch: 'সব শাখা', avatarName: 'আল-আমিন', onAvatar: (_) {}, onSearch: () {}, onCopilot: () {}),
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('top-copilot')), findsNothing, reason: 'উপরের বারে আর নেই');

    const perms = ['sales.order.view', 'sales.order.create'];
    expect(RoleTabs.lookTabsFor(perms), isNot(contains(BarTab.copilot)), reason: '⛔ চাবি ছাড়া Copilot');
    final tabs = RoleTabs.lookTabsFor(perms, copilot: true);
    expect(tabs.indexOf(BarTab.copilot), 1, reason: 'হোমের ঠিক পরে');

    var opened = 0;
    await tester.pumpWidget(app(Scaffold(
      bottomNavigationBar: RoleBottomBar(
        tabs: [for (final t in tabs) if (t != BarTab.me) t], // ⓘ "আমি"-র ছবি সেশন চায় — এখানে লাগে না
        selected: BarTab.home,
        badges: const {},
        onSelect: (t) {
          if (t == BarTab.copilot) opened++;
        },
      ),
    )));
    await tester.pumpAndSettle();
    final tab = find.byKey(const ValueKey('nav-copilot'));
    expect(find.descendant(of: tab, matching: find.byType(CopilotMark)), findsOneWidget, reason: 'নতুন AI চিহ্ন');
    expect(tester.getSize(tab).height, greaterThanOrEqualTo(48), reason: '⛔ আঙুলের জন্য ছোট');
    await tester.tap(tab);
    expect(opened, 1);
  });
}

class _FakeApi implements CopilotApi {
  final asked = <String>[];

  @override
  Future<Map<String, dynamic>> today() async => {
        'name': 'Copilot',
        'examples': ['আজ কত বিক্রি হলো?', 'কার কাছে বেশি বাকি?'],
      };

  @override
  Future<Map<String, dynamic>> ask(String question) async {
    asked.add(question);
    return {
      'state': 'answered',
      'answer_id': 7,
      'answer': {
        'summary': 'এ মাসে নিট বিক্রি ৳৪৮,৬৫০',
        'columns': ['name', 'amount'],
        'rows': [
          {'name': 'মাহাবুব এন্টারপ্রাইজ', 'amount': '৳১২,০০০'},
        ],
        'sources': [
          {'label': 'বিক্রয়ের ড্যাশবোর্ড'},
        ],
      },
    };
  }

  @override
  Future<void> feedback(int answerId, int value) async {}

  @override
  Future<void> dismiss(String finding, String reason) async {}
}
