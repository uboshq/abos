import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_exceptions.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/config/app_config.dart';
import 'package:abos_mobile/features/auth/login_screen.dart';

/// The login page in the owner's "প্রিমিয়াম ৫ · মুক্তা সাদা" look
/// (1 October 2026) — and the same behaviour as before underneath it.
class _FakeAuth extends AuthController {
  _FakeAuth(this.answer);

  /// What the next login does: null succeeds, otherwise thrown.
  Object? Function(String? code) answer;
  final calls = <(String, String, String?)>[];

  @override
  Future<void> login({required String identifier, required String password, String? code}) async {
    calls.add((identifier, password, code));
    final outcome = answer(code);
    if (outcome != null) throw outcome;
  }
}

/// 10:00 in Dhaka is 04:00 UTC.
DateTime _dhaka(int hour) => DateTime.utc(2026, 10, 1, hour - 6 < 0 ? hour + 18 : hour - 6);

void main() {
  late _FakeAuth auth;
  late List<String?> saved;

  Widget screen({String? remembered, int hour = 10, Size size = const Size(390, 844)}) {
    saved = [];
    return ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => auth)],
      child: MediaQuery(
        data: MediaQueryData(size: size),
        child: MaterialApp(
          home: LoginScreen(
            now: () => _dhaka(hour),
            readRemembered: () async => remembered,
            saveRemembered: (id) async => saved.add(id),
          ),
        ),
      ),
    );
  }

  setUp(() => auth = _FakeAuth((_) => null));

  testWidgets('the owner\'s words are on the page, the button says প্রবেশ করুন', (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    expect(find.text('শুভ সকাল'), findsOneWidget);
    expect(find.text('আজকের হিসাব আপনার অপেক্ষায়'), findsOneWidget);
    expect(find.text('প্রবেশ করুন'), findsOneWidget);
    expect(find.text('ঢুকুন'), findsNothing, reason: 'the owner: never "ঢুকুন"');
    expect(find.text('মনে রাখুন'), findsOneWidget);
    expect(find.text('পাসওয়ার্ড ভুলে গেছেন?'), findsOneWidget);
    expect(find.textContaining('সংস্করণ ${AppConfig.appVersion}'), findsOneWidget);
    expect(find.text('English'), findsOneWidget);
  });

  test('the greeting reads the Dhaka clock', () {
    expect(loginGreeting(DateTime.utc(2026, 10, 1, 5, 59), bangla: true), 'শুভ সকাল'); // 11:59
    expect(loginGreeting(DateTime.utc(2026, 10, 1, 6), bangla: true), 'শুভ দুপুর'); // 12:00
    expect(loginGreeting(DateTime.utc(2026, 10, 1, 10, 59), bangla: true), 'শুভ দুপুর'); // 16:59
    expect(loginGreeting(DateTime.utc(2026, 10, 1, 11), bangla: true), 'শুভ সন্ধ্যা'); // 17:00
    expect(loginGreeting(DateTime.utc(2026, 10, 1, 11), bangla: false), 'Good evening');
  });

  testWidgets('English on the footer turns the page to English', (tester) async {
    await tester.pumpWidget(screen(hour: 14));
    await tester.pumpAndSettle();

    await tester.tap(find.text('English'));
    await tester.pumpAndSettle();

    expect(find.text('Good afternoon'), findsOneWidget);
    expect(find.text("Today's books are waiting for you"), findsOneWidget);
    expect(find.text('Sign in'), findsOneWidget);
    expect(find.text('Remember me'), findsOneWidget);
    expect(find.text('Forgot password?'), findsOneWidget);

    await tester.tap(find.text('বাংলা'));
    await tester.pumpAndSettle();
    expect(find.text('প্রবেশ করুন'), findsOneWidget);
  });

  testWidgets('empty fields are refused before the server is asked', (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('login-submit')));
    await tester.pumpAndSettle();

    expect(find.text('নাম বা ইমেইল দিন'), findsOneWidget);
    expect(find.text('পাসওয়ার্ড দিন'), findsOneWidget);
    expect(auth.calls, isEmpty);
  });

  testWidgets('the fields reach the login, and the eye shows the password', (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const ValueKey('login-identifier')), ' karim ');
    await tester.enterText(find.byKey(const ValueKey('login-password')), 'secret');

    EditableText password() => tester.widget<EditableText>(find.descendant(
        of: find.byKey(const ValueKey('login-password')), matching: find.byType(EditableText)));
    expect(password().obscureText, isTrue);
    await tester.tap(find.byKey(const ValueKey('login-password-eye')));
    await tester.pump();
    expect(password().obscureText, isFalse);

    await tester.tap(find.byKey(const ValueKey('login-submit')));
    await tester.pumpAndSettle();

    expect(auth.calls, [('karim', 'secret', null)]);
    expect(saved, [null], reason: 'not ticked — nothing remembered');
  });

  testWidgets('মনে রাখুন keeps the ID after a sign-in that worked, and fills it next time',
      (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('login-identifier')), 'karim');
    await tester.enterText(find.byKey(const ValueKey('login-password')), 'secret');
    await tester.tap(find.byKey(const ValueKey('login-remember')));
    await tester.tap(find.byKey(const ValueKey('login-submit')));
    await tester.pumpAndSettle();
    expect(saved, ['karim']);

    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(screen(remembered: 'karim'));
    await tester.pumpAndSettle();
    expect(find.text('karim'), findsOneWidget);
    expect(tester.widget<Checkbox>(find.byType(Checkbox)).value, isTrue);
  });

  testWidgets('a refusal is shown, and a failed sign-in remembers nothing', (tester) async {
    auth = _FakeAuth((_) => const AuthFailure('পাসওয়ার্ড মেলেনি।'));
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('login-identifier')), 'karim');
    await tester.enterText(find.byKey(const ValueKey('login-password')), 'wrong');
    await tester.tap(find.byKey(const ValueKey('login-remember')));
    await tester.tap(find.byKey(const ValueKey('login-submit')));
    await tester.pumpAndSettle();

    expect(find.text('পাসওয়ার্ড মেলেনি।'), findsOneWidget);
    expect(saved, isEmpty);
  });

  testWidgets('the MFA step still follows, and its code reaches the login', (tester) async {
    auth = _FakeAuth((code) => code == null ? const MfaRequiredException(codeWasWrong: false) : null);
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('login-identifier')), 'owner');
    await tester.enterText(find.byKey(const ValueKey('login-password')), 'secret');
    await tester.tap(find.byKey(const ValueKey('login-submit')));
    await tester.pumpAndSettle();

    expect(find.text('আপনার অথেনটিকেটর অ্যাপ থেকে ৬-সংখ্যার কোডটি দিন।'), findsOneWidget);
    expect(find.text('যাচাই করুন'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('login-code')), '123456');
    await tester.tap(find.byKey(const ValueKey('login-submit')));
    await tester.pumpAndSettle();
    expect(auth.calls.last, ('owner', 'secret', '123456'));

    await tester.tap(find.text('ফিরে যান'));
    await tester.pumpAndSettle();
    expect(find.text('প্রবেশ করুন'), findsOneWidget);
  });

  testWidgets('পাসওয়ার্ড ভুলে গেছেন? says whom to ask', (tester) async {
    await tester.pumpWidget(screen());
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('login-forgot')));
    await tester.pumpAndSettle();

    expect(find.textContaining('প্রশাসককে বলুন'), findsOneWidget);
  });

  testWidgets('a small phone with the keyboard up still reaches the button', (tester) async {
    tester.view.physicalSize = const Size(320, 480);
    tester.view.devicePixelRatio = 1;
    tester.view.viewInsets = const FakeViewPadding(bottom: 220);
    addTearDown(tester.view.reset);

    await tester.pumpWidget(ProviderScope(
      overrides: [authStateProvider.overrideWith((ref) => auth)],
      child: MaterialApp(
        home: LoginScreen(
          now: () => _dhaka(10),
          readRemembered: () async => null,
          saveRemembered: (_) async {},
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull, reason: 'no overflow');
    await tester.ensureVisible(find.byKey(const ValueKey('login-submit')));
    await tester.pumpAndSettle();
    final button = tester.getRect(find.byKey(const ValueKey('login-submit')));
    expect(button.bottom, lessThanOrEqualTo(480 - 220));
    expect(button.top, greaterThanOrEqualTo(0));
  });
}
