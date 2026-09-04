import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_repository.dart';
import 'package:abos_mobile/core/auth/token_storage.dart';
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

  test(
      'restoreSession signs out on a token with no saved profile, rather '
      'than showing an identity-less user', () async {
    // A state this app's own login flow never produces — see
    // AuthController.restoreSession's own doc comment — but one an older
    // build (from before SessionRepository existed) could leave behind.
    await TokenStorage.instance
        .saveTokens(accessToken: 'access-1', refreshToken: 'refresh-1');

    final controller = AuthController();
    await controller.restoreSession();

    expect(controller.state.status, AuthStatus.signedOut);
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
}
