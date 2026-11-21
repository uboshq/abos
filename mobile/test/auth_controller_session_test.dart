import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_repository.dart';
import 'package:abos_mobile/core/auth/token_storage.dart';
import 'package:abos_mobile/core/privacy/phone_privacy.dart';
import 'package:abos_mobile/core/push/push_service.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// Session persistence across a restart, and a clean sign-out — the two
/// halves of "a person is not forced to log back in for no reason, and is
/// properly logged out when they choose to be" that the coordinating
/// session's briefing asked this suite to cover.
///
/// <p>The actual token-refresh network exchange lives in `ApiClient`
/// (core/api_client, outside this app's own layer) and is not re-tested
/// here; what belongs to this file is [AuthController]'s own contract with
/// it — restoring a session from what was saved at the last login, and
/// clearing every trace of one on sign-out.
///
/// <p>One Hive box (via [ReferenceCache]) for the whole file — see
/// sync_engine_queue_test.dart's own class comment for why closing it
/// between individual `test()` cases is the wrong shape here, and why
/// [ReferenceCache.clearAll] in [setUp] is the isolation instead.
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
  });

  tearDownAll(() async {
    await harness.tearDown();
  });

  setUp(() async {
    FakeSecureStorage.reset();
    await ReferenceCache.instance.clearAll();
  });

  const savedUser = AuthUser(
    id: '01a0-user',
    name: 'Salesman',
    email: 'sales@abos.test',
    roles: ['salesman'],
    permissions: ['customer.view', 'sales.order.view'],
  );

  test('restoreSession signs back in when a token and a saved profile exist',
      () async {
    await TokenStorage.instance
        .saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');
    await SessionRepository.instance.saveUser(savedUser);

    final controller = AuthController();
    await controller.restoreSession();

    expect(controller.state.status, AuthStatus.signedIn);
    expect(controller.state.user?.id, savedUser.id);
  });

  test('restoreSession signs out when nothing was saved', () async {
    final controller = AuthController();
    await controller.restoreSession();

    expect(controller.state.status, AuthStatus.signedOut);
  });

  // ⛔ রিভিউ A2, ২১ নভেম্বর ২০২৬: টোকেন আছে, প্রোফাইল নেই — আগে পুরো সেশন মুছে বের করে দিত, মানুষ না চাইতেই
  test('a token with no saved profile rebuilds the profile from /me instead of signing out', () async {
    await TokenStorage.instance.saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');
    var asked = 0;
    final controller = AuthController(fetchMe: () async {
      asked++;
      return savedUser;
    });
    await controller.restoreSession();

    expect(asked, 1, reason: '⛔ প্রোফাইল /me থেকে গড়া হলো না');
    expect(controller.state.status, AuthStatus.signedIn, reason: '⛔ প্রোফাইল হারানোয় মানুষ বের হলেন');
    expect(controller.state.user?.id, savedUser.id);
    expect((await SessionRepository.instance.readUser())?.id, savedUser.id, reason: '⛔ গড়া প্রোফাইল রাখা হলো না');
    expect(await TokenStorage.instance.refreshToken(), 'refresh-1');
  });

  test('a corrupt saved profile is rebuilt from /me too, never a sign-out', () async {
    await TokenStorage.instance.saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');
    // ⓘ নষ্ট প্রোফাইল — SessionRepository-র নিজের চাবিতে অচেনা মান
    await const FlutterSecureStorage().write(key: 'abos_session_user', value: '{not json');
    expect(await SessionRepository.instance.readUser(), isNull);

    final controller = AuthController(fetchMe: () async => savedUser);
    await controller.restoreSession();

    expect(controller.state.status, AuthStatus.signedIn);
  });

  test('no network and no profile keeps the tokens: the next open with a network comes back in', () async {
    await TokenStorage.instance.saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');
    final offline = AuthController(fetchMe: () async => throw Exception('no network'));
    await offline.restoreSession();

    expect(offline.state.status, AuthStatus.signedOut, reason: 'এবারের জন্য লগইনের পর্দা');
    expect(await TokenStorage.instance.refreshToken(), 'refresh-1', reason: '⛔ নেট নেই বলে টোকেন মুছে বের করে দিল');

    final online = AuthController(fetchMe: () async => savedUser);
    await online.restoreSession();
    expect(online.state.status, AuthStatus.signedIn, reason: '⛔ নেট ফেরার পরের খোলায় সেশন ফিরল না');
  });

  test('only the refresh door refusing ends a session with no profile', () async {
    await TokenStorage.instance.saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');
    final controller = AuthController(fetchMe: () async {
      // ⓘ আসল ApiClient-এর মতো: নবায়নে ৪০১/৪০৩ → টোকেন মোছা → onSessionExpired
      await TokenStorage.instance.clear();
      ApiClient.onSessionExpired?.call();
      throw Exception('401');
    });
    await controller.restoreSession();

    expect(controller.state.status, AuthStatus.signedOut);
    expect(await TokenStorage.instance.refreshToken(), isNull);
  });

  test('logout clears the token, the saved profile, and the reference cache',
      () async {
    await TokenStorage.instance
        .saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');
    await SessionRepository.instance.saveUser(savedUser);
    await ReferenceCache.instance.put(
      entityType: 'Customer',
      entityId: '01a0-customer',
      payload: {'name': 'পুরনো টেস্ট গ্রাহক'},
      updatedAt: DateTime.now(),
    );

    final controller = AuthController();
    await controller.restoreSession();
    expect(controller.state.status, AuthStatus.signedIn);

    // logout() posts to /auth/logout first (best-effort — see its own doc
    // comment) against an address nothing meaningful listens on in this
    // suite; the local clear below must still happen regardless of that
    // failing.
    await controller.logout();

    expect(controller.state.status, AuthStatus.signedOut);
    expect(await TokenStorage.instance.refreshToken(), isNull);
    expect(await SessionRepository.instance.readUser(), isNull);
    expect(ReferenceCache.instance.countOf('Customer'), 0);
  });

  test(
      'a session that ended without sign-out leaves nothing of the last person '
      'for the next one (phone line audit, 15 Oct 2026)', () async {
    // ⓘ আগের জনের সেশন পটভূমিতে শেষ — টোকেন মুছেছে, বাকি সব রয়ে গেছে
    await SessionRepository.instance.saveUser(savedUser);
    await ReferenceCache.instance.put(
      entityType: 'Customer',
      entityId: '01a0-customer',
      payload: {'name': 'আগের জনের গ্রাহক'},
      updatedAt: DateTime.now(),
    );

    final controller = AuthController();
    await controller.restoreSession();

    expect(controller.state.status, AuthStatus.signedOut);
    expect(ReferenceCache.instance.countOf('Customer'), 0,
        reason: '⛔ সেশন শেষ, অথচ আগের জনের গ্রাহক পরের জনের জন্য রয়ে গেল');
    expect(await SessionRepository.instance.readUser(), isNull,
        reason: '⛔ আগের জনের প্রোফাইল রয়ে গেল');
  });

  test('signing out hides the screens again, whatever the last company chose (phone line audit, 15 Oct 2026)', () async {
    final calls = <bool>[];
    PhonePrivacy.secureSetter = (on) async => calls.add(on);
    await TokenStorage.instance.saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');
    await SessionRepository.instance.saveUser(savedUser);
    final controller = AuthController();
    await controller.restoreSession();
    await PhonePrivacy.follow(secureScreens: false, widgetAmounts: false, redrawWidgets: () async {});

    await controller.logout();
    await Future<void>.delayed(Duration.zero);

    expect(calls.last, isTrue, reason: '⛔ বেরোনোর পরেও পর্দা আড়াল বন্ধ — পরের জনের পর্দা স্ক্রিনশট-যোগ্য');
  });

  test('signing out with no signal still drops the push token of this phone (phone line audit, 15 Oct 2026)', () async {
    var deleted = 0;
    PushService.tokenDeleter = () async => deleted++;
    await TokenStorage.instance.saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');
    await SessionRepository.instance.saveUser(savedUser);
    final controller = AuthController();
    await controller.restoreSession();

    await controller.logout(); // ⓘ /auth/logout এই পরীক্ষায় কোথাও পৌঁছায় না — নেট নেই-এর মতোই
    await Future<void>.delayed(Duration.zero);

    expect(deleted, 1, reason: '⛔ বেরোনোর পরেও এই ফোনে আগের জনের বার্তা আসবে');
  });
}
