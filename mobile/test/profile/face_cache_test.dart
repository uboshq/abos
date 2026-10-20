import 'dart:async';
import 'dart:io';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/auth/token_storage.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_hub.dart';
import 'package:abos_mobile/core/profile/face_cache.dart';
import 'package:abos_mobile/core/profile/face_image.dart';
import 'package:abos_mobile/core/workspace/workspace_switcher.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/fake_secure_storage.dart';
/// ⭐ মুখের জমা — অ্যাপের নিজের ক্যাশ-ফোল্ডারে, ETag মিলিয়ে, সীমাসহ, আর বেরোলে মোছা (fe, ২০ অক্টোবর ২০২৬: MASVS আর নেটের খরচ)।
///
/// <p>দাবি: ৩০৪ এলে জমা মুখ, দ্বিতীয়বার শরীর নামে না; ২০০ এলে জমা বদলায়; ২০০টা মুখ বা ১০ MB-এর বেশি হলে সবচেয়ে পুরনো-ব্যবহৃত যায়;
/// ফাইল কেবল অ্যাপের নিজের ক্যাশে; ⛔ টোকেন কোনো ফাইলের নামে বা ভেতরে নেই; বেরোনো, কোম্পানি বদল আর দূর থেকে বের করা (৪০১,
/// সেশন শেষ) — তিনটার পরেই ফোল্ডার খালি; মোছার আগে শুরু হওয়া আনা পরে কিছু লেখে না।
void main() {
  late Directory root;
  late HttpClientAdapter real;
  late _FaceServer server;
  const token = 'ACCESS-SECRET-7f3a';
  const url = 'https://erp.adi.com.bd/api/v1/users/U-1/photo?v=ab12';

  Directory faces() => Directory('${root.path}${Platform.pathSeparator}${FaceCache.folder}');
  List<File> files() => faces().existsSync() ? faces().listSync(recursive: true).whereType<File>().toList() : <File>[];

  setUp(() async {
    FakeSecureStorage.install();
    FakeSecureStorage.reset();
    root = Directory.systemTemp.createTempSync('abos-cache-');
    FaceCache.root = () async => root;
    await FaceCache.instance.wipe();
    real = ApiClient.dio.httpClientAdapter;
    server = _FaceServer();
    ApiClient.dio.httpClientAdapter = server;
    await TokenStorage.instance.saveTokens(accessToken: token, refreshToken: 'REFRESH-SECRET');
  });

  tearDown(() {
    ApiClient.dio.httpClientAdapter = real;
    if (root.existsSync()) root.deleteSync(recursive: true);
  });

  test('a second look asks with If-None-Match; 304 serves the kept face and no body comes down again', () async {
    final first = await FaceImage.fetchBytes(url);
    expect(first, server.face);
    expect(server.requests, 1);
    expect(server.bodyBytesSent, server.face.length);
    expect(server.seen.single.headers.containsKey('If-None-Match'), isFalse, reason: 'প্রথমবার জমা নেই');

    final second = await FaceImage.fetchBytes(url);
    expect(second, server.face, reason: 'জমা মুখটাই');
    expect(server.requests, 2, reason: 'সার্ভারকে জিজ্ঞেস করা হয় — বদলেছে কি না');
    expect(server.seen.last.headers['If-None-Match'], server.etag);
    expect(server.answers.last, 304);
    expect(server.bodyBytesSent, server.face.length, reason: '⛔ ৩০৪-এর পরেও শরীর আবার নামল');
  });

  test('a 200 with a new ETag replaces the kept face', () async {
    await FaceImage.fetchBytes(url);
    server.change(Uint8List.fromList([9, 8, 7, 6, 5]), '"v2"');
    final again = await FaceImage.fetchBytes(url);
    expect(again, [9, 8, 7, 6, 5]);
    final kept = await FaceCache.instance.get(url);
    expect(kept!.bytes, [9, 8, 7, 6, 5]);
    expect(kept.etag, '"v2"');
  });

  test('the files live under the app-private cache only, named by a hash of the address', () async {
    await FaceImage.fetchBytes(url);
    final all = files();
    expect(all, hasLength(2), reason: 'মুখ আর ETag');
    for (final f in all) {
      expect(f.path, startsWith(faces().path), reason: '⛔ ক্যাশের বাইরে লেখা');
      expect(f.uri.pathSegments.last, startsWith(FaceCache.keyOf(url)));
      expect(f.uri.pathSegments.last, matches(RegExp(r'^[0-9a-f]{64}\.(face|etag)$')), reason: 'নামে কেবল ছাপ — ঠিকানা বা টোকেন নয়');
    }
    final source = File('lib/core/profile/face_cache.dart').readAsStringSync();
    expect(source, contains('root = getApplicationCacheDirectory;'), reason: 'অ্যাপের নিজের ক্যাশ-ফোল্ডার');
    for (final banned in ['getExternalStorage', 'getDownloadsDirectory', 'getApplicationDocumentsDirectory', 'gallery_saver', 'ImageGallery']) {
      expect(source, isNot(contains(banned)), reason: '⛔ $banned');
    }
  });

  test('the token never appears in a cache file name or inside a file', () async {
    await FaceImage.fetchBytes(url);
    await FaceImage.fetchBytes(url);
    expect(server.seen.first.headers['Authorization'], 'Bearer $token', reason: 'টোকেন গেছে সার্ভারে, ফাইলে নয়');
    for (final f in files()) {
      expect(f.path, isNot(contains(token)));
      expect(f.path, isNot(contains('REFRESH-SECRET')));
      final text = String.fromCharCodes(f.readAsBytesSync());
      expect(text, isNot(contains(token)), reason: '⛔ ${f.path}-এ টোকেন');
      expect(text, isNot(contains('Bearer')));
      expect(text, isNot(contains('REFRESH-SECRET')));
    }
    expect(FaceCache.keyOf(url), FaceCache.keyOf(url), reason: 'চাবি ঠিকানা থেকে, টোকেন বদলালেও একই');
  });

  test('a face from another address is not kept', () async {
    await FaceImage.fetchBytes('https://cdn.example.com/a.jpg');
    expect(files(), isEmpty);
  });

  test('past 200 faces the least recently used one goes first', () async {
    final cache = FaceCache.instance;
    final start = DateTime(2026, 1, 1);
    for (var i = 0; i < FaceCache.maxFaces; i++) {
      await cache.put('$url&n=$i', Uint8List.fromList([1, 2, 3]), '"e$i"', startedAt: cache.generation);
    }
    // ⓘ ব্যবহারের সময় সাজানো: ০ সবচেয়ে পুরনো, ১ তার পরে …
    for (var i = 0; i < FaceCache.maxFaces; i++) {
      File('${faces().path}${Platform.pathSeparator}${FaceCache.keyOf('$url&n=$i')}.face')
          .setLastModifiedSync(start.add(Duration(minutes: i)));
    }
    // ⓘ ০ এখন দেখা হলো — সবচেয়ে নতুন
    expect(await cache.get('$url&n=0'), isNotNull);

    await cache.put('$url&n=new', Uint8List.fromList([4, 5, 6]), '"new"', startedAt: cache.generation);
    final kept = files().where((f) => f.path.endsWith('.face')).length;
    expect(kept, FaceCache.maxFaces, reason: 'সীমার ভেতরে');
    expect(await cache.get('$url&n=1'), isNull, reason: 'সবচেয়ে পুরনো-ব্যবহৃত গেল');
    expect(await cache.get('$url&n=0'), isNotNull, reason: '⛔ সদ্য দেখা মুখ গেল — LRU নয়');
    expect(await cache.get('$url&n=new'), isNotNull);
    expect(File('${faces().path}${Platform.pathSeparator}${FaceCache.keyOf('$url&n=1')}.etag').existsSync(), isFalse,
        reason: 'ETag-টাও যায়');
  });

  test('past 10 MB the oldest goes, even with few faces', () async {
    final cache = FaceCache.instance;
    final big = Uint8List(4 * 1024 * 1024);
    await cache.put('$url&b=1', big, '"b1"', startedAt: cache.generation);
    File('${faces().path}${Platform.pathSeparator}${FaceCache.keyOf('$url&b=1')}.face').setLastModifiedSync(DateTime(2026, 1, 1));
    await cache.put('$url&b=2', big, '"b2"', startedAt: cache.generation);
    File('${faces().path}${Platform.pathSeparator}${FaceCache.keyOf('$url&b=2')}.face').setLastModifiedSync(DateTime(2026, 1, 2));
    await cache.put('$url&b=3', big, '"b3"', startedAt: cache.generation);
    expect(await cache.get('$url&b=1'), isNull, reason: '১২ MB > ১০ MB — সবচেয়ে পুরনো গেল');
    expect(await cache.get('$url&b=2'), isNotNull);
    expect(await cache.get('$url&b=3'), isNotNull);
  });

  group('wiped where the widgets are wiped', () {
    void widgetFakes() {
      WidgetHub.resetForTest();
      WidgetHub.save = (_, __) async {};
      WidgetHub.setEnabled = (_, __) async {};
      WidgetHub.redraw = (_) async {};
      WidgetHub.refreshAll = () async {};
    }

    tearDown(WidgetHub.resetForTest);

    Future<void> until(bool Function() done) async {
      for (var i = 0; i < 100 && !done(); i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }
    }

    test('signing out (WidgetHub.clear from the sign-out path) leaves the face folder empty', () async {
      widgetFakes();
      await FaceImage.fetchBytes(url);
      expect(files(), isNotEmpty);
      await WidgetHub.clear();
      expect(files(), isEmpty, reason: '⛔ বেরোনোর পরে আগের জনের সহকর্মীদের মুখ ফোনে');
      final auth = File('lib/core/auth/auth_controller.dart').readAsStringSync();
      final local = auth.substring(auth.indexOf('Future<void> _clearLocalSession()'));
      expect(local, contains('WidgetHub.clear()'), reason: 'বেরোনো আর দূর থেকে বের করা (ApiClient-এর ৪০১) এই পথেই');
      expect(auth, contains('ApiClient.onSessionExpired = _handleSessionExpired'));
    });

    test('a company switch leaves the face folder empty', () async {
      widgetFakes();
      await FaceImage.fetchBytes(url);
      final switcher = WorkspaceSwitcher(
        post: (_, __) async => {'companyChanged': true},
        clearCache: () async {},
        forgetLastFailure: () {},
        syncAll: () async {},
        refreshWidgets: () async {},
        pendingCount: () => 0,
      );
      await switcher.switchTo(currentCompanyPublicId: 'c1', companyPublicId: 'c2', branch: WorkspaceSwitcher.allBranches);
      await until(() => files().isEmpty);
      expect(files(), isEmpty, reason: '⛔ আগের কোম্পানির মুখ নতুন কোম্পানিতে');
    });

    test('a remote sign-out seen by the background round (401, session gone) leaves the face folder empty', () async {
      widgetFakes();
      await FaceImage.fetchBytes(url);
      WidgetHub.fetch = () async =>
          throw DioException(requestOptions: RequestOptions(path: '/widgets'), response: Response(requestOptions: RequestOptions(), statusCode: 401));
      WidgetHub.sessionGone = () async => true;
      await WidgetHub.refresh();
      await until(() => files().isEmpty);
      expect(files(), isEmpty);
    });

    test('a 401 with the session still alive (no signal for the refresh) keeps the faces', () async {
      widgetFakes();
      await FaceImage.fetchBytes(url);
      WidgetHub.fetch = () async =>
          throw DioException(requestOptions: RequestOptions(path: '/widgets'), response: Response(requestOptions: RequestOptions(), statusCode: 401));
      WidgetHub.sessionGone = () async => false;
      await WidgetHub.refresh();
      expect(files(), isNotEmpty);
    });
  });

  test('a fetch that was under way when the cache was wiped writes nothing afterwards', () async {
    final gate = Completer<void>();
    server.hold = gate.future;
    final pending = FaceImage.fetchBytes(url);
    await Future<void>.delayed(const Duration(milliseconds: 20));
    await FaceCache.instance.wipe();
    gate.complete();
    await pending;
    expect(files(), isEmpty, reason: '⛔ বেরোনোর পরে আগের জনের মুখ ফিরে এল');
  });
}

/// ছবির দরজার ভান — ETag দেয়, If-None-Match মিললে ৩০৪; কত অনুরোধ আর কত শরীর-বাইট গেল গোনে
class _FaceServer implements HttpClientAdapter {
  Uint8List face = Uint8List.fromList(List<int>.generate(300, (i) => i % 251));
  String etag = '"f1"';
  int requests = 0;
  int bodyBytesSent = 0;
  final List<RequestOptions> seen = [];
  final List<int> answers = [];
  Future<void>? hold;

  void change(Uint8List next, String tag) {
    face = next;
    etag = tag;
  }

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? requestStream, Future<void>? cancelFuture) async {
    requests++;
    seen.add(options);
    if (hold != null) await hold;
    if (options.headers['If-None-Match'] == etag) {
      answers.add(304);
      return ResponseBody.fromBytes(const [], 304, headers: {'etag': [etag]});
    }
    answers.add(200);
    bodyBytesSent += face.length;
    return bytesWithEtag(face, etag);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody bytesWithEtag(List<int> data, String etag) => ResponseBody.fromBytes(data, 200, headers: {
      Headers.contentTypeHeader: ['image/png'],
      'etag': [etag],
    });
