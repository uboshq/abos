import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/accounts/net_worth_api.dart';
import 'package:abos_mobile/core/api_client/etag_cache.dart';
import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/launcher_widgets/widget_sync_observer.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/navigation/nav_prefs.dart';
import 'package:abos_mobile/core/push/push_service.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/sync_engine/auto_sync.dart';
import 'package:abos_mobile/core/sync_engine/poll_gate.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/home/home_shell.dart';

import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart' show ownerToday;

/// ⭐ Polling cut (plan of 23 Nov 2026): ETags for the busiest reads, one background round per 15 min, 60 s
/// between foreground rounds, no net-worth call for anyone but the owner, notifications on the notice push.

/// A server in a box: answers each request with what [answer] says, and keeps what it was sent.
class _Server implements HttpClientAdapter {
  _Server(this.answer);

  ResponseBody Function(RequestOptions options) answer;
  final List<RequestOptions> seen = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    seen.add(options);
    return answer(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _json(int status, Object? body, {String? etag}) => ResponseBody.fromString(
      body == null ? '' : jsonEncode(body),
      status,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
        if (etag != null) 'etag': [etag],
      },
    );

Dio _dio(_Server server, EtagStore store) {
  final dio = Dio(BaseOptions(
    baseUrl: 'https://erp.example/api/v1',
    contentType: Headers.jsonContentType,
    // ⓘ the same rule as ApiClient: only 2xx is a success, a 304 arrives as an error
    validateStatus: (s) => s != null && s >= 200 && s < 300,
  ));
  dio.httpClientAdapter = server;
  dio.interceptors.add(EtagInterceptor(store: store));
  return dio;
}

String? _ifNoneMatch(RequestOptions o) {
  for (final e in o.headers.entries) {
    if (e.key.toLowerCase() == 'if-none-match') return e.value?.toString();
  }
  return null;
}

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

class _CountingNetWorth implements NetWorthApi {
  int calls = 0;

  @override
  Future<NetWorthSummary?> summary() async {
    calls++;
    return const NetWorthSummary({'net': '1000.0000', 'this_year': '10.0000', 'as_of': '2026-11-23', 'agrees': true});
  }

  @override
  Future<NetWorthStatementRecord> statement() async => throw UnimplementedError();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('ETag interceptor', () {
    late MemoryEtagStore store;
    setUp(() => store = MemoryEtagStore());

    test('a 200 with an ETag is stored, and the next request sends If-None-Match', () async {
      final server = _Server((_) => _json(200, {'pending': 3}, etag: 'W/"a1"'));
      final dio = _dio(server, store);

      final first = await dio.get<Map<String, dynamic>>('/dashboard/today');
      expect(first.data, {'pending': 3});
      expect(_ifNoneMatch(server.seen.first), isNull);

      await dio.get<Map<String, dynamic>>('/dashboard/today');
      expect(_ifNoneMatch(server.seen.last), 'W/"a1"');
    });

    test('a 304 comes back to the caller as a 200 with the stored body', () async {
      final server = _Server((_) => _json(200, {'rows': [1, 2]}, etag: 'W/"b2"'));
      final dio = _dio(server, store);
      await dio.get<Map<String, dynamic>>('/approvals/pending', queryParameters: {'limit': 50});

      server.answer = (_) => _json(304, null, etag: 'W/"b2"');
      final again = await dio.get<Map<String, dynamic>>('/approvals/pending', queryParameters: {'limit': 50});

      expect(again.statusCode, 200);
      expect(again.data, {'rows': [1, 2]});
      expect(again.extra[EtagInterceptor.cachedKey], isTrue);
    });

    test('a server without ETags behaves exactly as before — nothing stored, nothing sent', () async {
      final server = _Server((_) => _json(200, {'unread': 4}));
      final dio = _dio(server, store);

      await dio.get<Map<String, dynamic>>('/notifications');
      await dio.get<Map<String, dynamic>>('/notifications');

      expect(store.entries, isEmpty);
      expect(server.seen.map(_ifNoneMatch), everyElement(isNull));
    });

    test('a 200 that stops sending an ETag drops what was stored', () async {
      final server = _Server((_) => _json(200, {'a': 1}, etag: 'W/"c3"'));
      final dio = _dio(server, store);
      await dio.get<Map<String, dynamic>>('/notices/bar');
      expect(store.entries, hasLength(1));

      server.answer = (_) => _json(200, {'a': 2});
      await dio.get<Map<String, dynamic>>('/notices/bar');
      expect(store.entries, isEmpty);
    });

    test('a 401 is never cached, and never answered from the store', () async {
      final server = _Server((_) => _json(401, {'message': 'Unauthenticated.'}, etag: 'W/"x"'));
      final dio = _dio(server, store);

      await expectLater(dio.get<Map<String, dynamic>>('/messenger/threads'), throwsA(isA<DioException>()));
      expect(store.entries, isEmpty);

      // a stored answer exists, then the server says 401: the caller hears the 401, the stored body stays put
      server.answer = (_) => _json(200, {'threads': []}, etag: 'W/"t1"');
      await dio.get<Map<String, dynamic>>('/messenger/threads');
      server.answer = (_) => _json(401, {'message': 'Unauthenticated.'});
      final error = await dio.get<Map<String, dynamic>>('/messenger/threads').then<Object?>((r) => r, onError: (Object e) => e);
      expect(error, isA<DioException>());
      expect((error as DioException).response?.statusCode, 401);
      expect(store.entries.values.single.etag, 'W/"t1"');
    });

    test('other addresses and writes are left alone', () async {
      final server = _Server((_) => _json(200, {'ok': true}, etag: 'W/"o"'));
      final dio = _dio(server, store);

      await dio.get<Map<String, dynamic>>('/customers');
      await dio.post<Map<String, dynamic>>('/notifications', data: {});
      expect(store.entries, isEmpty);
    });

    test('a caller with its own If-None-Match keeps its own 304', () async {
      final server = _Server((_) => _json(200, {'w': 1}, etag: 'W/"s1"'));
      final dio = _dio(server, store);
      await dio.get<Object?>('/widgets/summary');

      server.answer = (_) => _json(304, null);
      final reply = await dio.get<Object?>('/widgets/summary',
          options: Options(headers: {'If-None-Match': 'W/"s1"'}, validateStatus: (s) => s == 200 || s == 304));
      expect(reply.statusCode, 304);
    });

    test('the file store survives a new isolate and is wiped on clear', () async {
      final dir = await Directory.systemTemp.createTemp('abos-etag-test');
      addTearDown(() async {
        if (await dir.exists()) await dir.delete(recursive: true);
      });
      final first = FileEtagStore(directory: () async => dir);
      await first.write('https://erp.example/api/v1/dashboard/today', const EtagEntry('W/"d"', {'k': 'v'}));

      final second = FileEtagStore(directory: () async => dir);
      final read = await second.read('https://erp.example/api/v1/dashboard/today');
      expect(read?.etag, 'W/"d"');
      expect(read?.body, {'k': 'v'});

      await second.clear();
      expect(await FileEtagStore(directory: () async => dir).read('https://erp.example/api/v1/dashboard/today'), isNull);
    });
  });

  group('one background round per 15 minutes', () {
    test('a second round inside 15 minutes is refused, also from another isolate', () async {
      final dir = await Directory.systemTemp.createTemp('abos-gate-test');
      addTearDown(() async {
        if (await dir.exists()) await dir.delete(recursive: true);
      });
      var now = DateTime(2026, 11, 23, 9);
      Future<File> file() async => File('${dir.path}/gate');
      final push = RoundGate(name: 'gate', clock: () => now, file: file);
      final workManager = RoundGate(name: 'gate', clock: () => now, file: file);

      expect(await push.claim(), isTrue);
      now = now.add(const Duration(minutes: 1));
      expect(await push.claim(), isFalse, reason: 'every push used to run a full round');
      now = now.add(const Duration(minutes: 13, seconds: 59));
      expect(await workManager.claim(), isFalse);
      now = now.add(const Duration(seconds: 1));
      expect(await workManager.claim(), isTrue);
    });
  });

  group('foreground rounds at least 60 s apart', () {
    late DateTime now;
    late int rounds;
    late List<(Duration, void Function())> timers;
    late AutoSync auto;

    setUp(() {
      now = DateTime(2026, 11, 23, 10);
      rounds = 0;
      timers = [];
      AutoSync.nudgedProbe = () async => false;
      auto = AutoSync(
        signedIn: () => true,
        round: () async => rounds++,
        clock: () => now,
        timer: (d, f) {
          timers.add((d, f));
          return Timer(const Duration(days: 1), () {});
        },
      );
    });

    test('changes inside the gap ride one round at its end', () async {
      await auto.runSoon();
      expect(rounds, 1);

      now = now.add(const Duration(seconds: 20));
      await auto.runSoon();
      await auto.runSoon();
      expect(rounds, 1, reason: 'a live-poll change used to start a full round at once');
      expect(timers, hasLength(1));
      expect(timers.single.$1, const Duration(seconds: 40));

      now = now.add(const Duration(seconds: 40));
      timers.single.$2();
      await pumpEventQueue();
      expect(rounds, 2);
    });

    test('in the background nothing is scheduled; the round waits for the app to come back', () async {
      await auto.runSoon();
      auto.didChangeAppLifecycleState(AppLifecycleState.paused);
      expect(auto.foreground, isFalse);

      now = now.add(const Duration(minutes: 5));
      await auto.runSoon();
      expect(rounds, 1);
      expect(timers, isEmpty);
      expect(auto.roundWaiting, isTrue);

      auto.didChangeAppLifecycleState(AppLifecycleState.resumed);
      await pumpEventQueue();
      expect(rounds, 2);
      expect(auto.roundWaiting, isFalse);
      auto.stop();
    });
  });

  group('return from a screen asks again only after 60 s', () {
    test('fresh for 60 s, stale after, and stale at once after a write', () {
      var now = DateTime(2026, 11, 23, 11);
      var writes = 0;
      final fresh = Freshness(clock: () => now, writes: () => writes);

      expect(fresh.claim(), isTrue);
      now = now.add(const Duration(seconds: 59));
      expect(fresh.claim(), isFalse);
      now = now.add(const Duration(seconds: 1));
      expect(fresh.claim(), isTrue);

      now = now.add(const Duration(seconds: 5));
      writes++;
      expect(fresh.claim(), isTrue, reason: 'an approval just made must show on the badge');
      expect(fresh.claim(force: true), isTrue);
      expect(fresh.olderThan(const Duration(minutes: 5)), isFalse);
      now = now.add(const Duration(minutes: 5));
      expect(fresh.olderThan(const Duration(minutes: 5)), isTrue);
    });

    test('one shared answer: joined while under way, reused for 30 s, failures not kept, wiped by a write', () async {
      var now = DateTime(2026, 11, 23, 12);
      var calls = 0;
      final shared = SharedAnswer<int>(clock: () => now);
      Future<int> fetch() async => ++calls;

      final a = shared.fresh(fetch);
      final b = shared.recent(fetch);
      expect(await a, 1);
      expect(await b, 1);
      now = now.add(const Duration(seconds: 29));
      expect(await shared.recent(fetch), 1);
      now = now.add(const Duration(seconds: 1));
      expect(await shared.recent(fetch), 2);

      SharedAnswer.clearAll();
      expect(shared.holdsAnswer, isFalse);

      await expectLater(shared.fresh(() async => throw StateError('offline')), throwsStateError);
      expect(shared.holdsAnswer, isFalse);
    });

    test('a write through the interceptor wipes the shared answers', () async {
      final shared = SharedAnswer<int>();
      await shared.fresh(() async => 1);
      final server = _Server((_) => _json(200, {'ok': true}));
      await _dio(server, MemoryEtagStore()).post<Object?>('/approvals/x/approve', data: {});
      expect(shared.holdsAnswer, isFalse);
    });
  });

  group('the widgets on leaving the app', () {
    test('no widget on the home screen — nothing fetched', () async {
      var runs = 0;
      final observer = WidgetSyncObserver(signedIn: () => true, refresh: () async => runs++, anyPlaced: () async => false);
      expect(await observer.syncNow(), isFalse);
      expect(runs, 0);
    });
  });

  group('notice push', () {
    test('{abos: notice} is a notice, not a sync nudge', () {
      expect(isNoticePush({'abos': 'notice'}), isTrue);
      expect(isSyncNudge({'abos': 'notice'}), isFalse);
      expect(isNoticePush({'abos': 'sync'}), isFalse);
      expect(isNoticePush(null), isFalse);
    });
  });

  group('net worth', () {
    test('only the owner role asks', () {
      expect(mayAskNetWorth(null), isFalse);
      expect(mayAskNetWorth(const AuthUser(id: 'u', name: 'a', email: '', roles: ['Manager'], permissions: ['accounts.report'])),
          isFalse);
      expect(mayAskNetWorth(const AuthUser(id: 'u', name: 'a', email: '', roles: [netWorthOwnerRole], permissions: [])), isTrue);
    });

    Widget shell(AuthUser user, NetWorthApi api) => ProviderScope(
          overrides: [authStateProvider.overrideWith((ref) => _SignedIn(user))],
          child: MaterialApp(
            home: HomeShell(
              loadHome: (_) async => const HomeMenu(
                items: [],
                profile: SessionProfile(
                  company: OrgRef(publicId: 'c1', code: 'TCL', name: 'Test Co'),
                  branch: OrgRef(publicId: 'b1', code: 'HO', name: 'Head Office'),
                  locale: 'bn',
                ),
              ),
              fetchToday: () async => TodayRecord(ownerToday()),
              lastKnownToday: () => null,
              readCachedOrg: () async => null,
              cacheOrg: (_) async {},
              checkUpdate: () async => UpdateStatus.fine,
              now: () => DateTime(2026, 11, 23, 10, 30),
              fetchNoticeBar: () async => const [],
              fetchPending: () async => const ApprovalPage(rows: []),
              syncNow: () async {},
              prefs: MemoryNavPrefsStore(),
              openPath: (_) async {},
              netWorth: api,
            ),
          ),
        );

    testWidgets('a non-owner home never asks for the net worth', (tester) async {
      setScreenSize(tester, const Size(400, 3000));
      final api = _CountingNetWorth();
      await tester.pumpWidget(shell(
          const AuthUser(id: 'u2', name: 'করিম', email: '', roles: ['Manager'], permissions: ['sales.order.view']), api));
      await tester.pumpAndSettle();
      expect(api.calls, 0, reason: 'a non-owner used to cost the server a 404 on every home load');
    });

    testWidgets('the owner home asks once', (tester) async {
      setScreenSize(tester, const Size(400, 3000));
      final api = _CountingNetWorth();
      await tester.pumpWidget(shell(
          const AuthUser(id: 'u1', name: 'মালিক', email: '', roles: [netWorthOwnerRole], permissions: []), api));
      await tester.pumpAndSettle();
      expect(api.calls, 1);
    });
  });
}
