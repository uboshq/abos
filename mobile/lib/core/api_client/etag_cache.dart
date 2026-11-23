import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';

import '../sync_engine/poll_gate.dart';

/// One remembered answer: the server's ETag and the body it came with.
class EtagEntry {
  const EtagEntry(this.etag, this.body);

  final String etag;

  /// The decoded JSON body (a map or a list), exactly as callers read it.
  final Object? body;
}

/// Where ETags and their bodies are kept. Tests use [MemoryEtagStore].
abstract class EtagStore {
  Future<EtagEntry?> read(String key);
  Future<void> write(String key, EtagEntry entry);
  Future<void> remove(String key);
  Future<void> clear();
}

class MemoryEtagStore implements EtagStore {
  final Map<String, EtagEntry> entries = {};

  @override
  Future<EtagEntry?> read(String key) async => entries[key];

  @override
  Future<void> write(String key, EtagEntry entry) async => entries[key] = entry;

  @override
  Future<void> remove(String key) async => entries.remove(key);

  @override
  Future<void> clear() async => entries.clear();
}

/// ⭐ On disk, one small file per address, in the app's private support folder — so the background isolates
/// (WorkManager, the silent push) send `If-None-Match` too; they start with empty memory every time.
///
/// <p>ⓘ Files, not a Hive box: the open app and a background isolate opening one box together break it (the
/// same reason as [NudgeMark]). ⛔ Cleared on sign-out and company switch ([EtagCache.forget]).
class FileEtagStore implements EtagStore {
  FileEtagStore({Future<Directory> Function()? directory}) : _directory = directory ?? _defaultDirectory;

  final Future<Directory> Function() _directory;
  final Map<String, EtagEntry?> _memory = {};

  static Future<Directory> _defaultDirectory() async =>
      Directory('${(await getApplicationSupportDirectory()).path}/abos-etag');

  Future<File> _file(String key) async => File('${(await _directory()).path}/${_name(key)}.json');

  /// FNV-1a, 64 bit, as hex — a short file name; the full key is kept inside and checked on read.
  static String _name(String key) {
    var hash = 0xcbf29ce484222325;
    for (final unit in utf8.encode(key)) {
      hash ^= unit;
      hash = (hash * 0x100000001b3) & 0xFFFFFFFFFFFFFFFF;
    }
    return hash.toUnsigned(64).toRadixString(16);
  }

  @override
  Future<EtagEntry?> read(String key) async {
    if (_memory.containsKey(key)) return _memory[key];
    EtagEntry? entry;
    try {
      final file = await _file(key);
      if (await file.exists()) {
        final saved = jsonDecode(await file.readAsString());
        if (saved is Map && saved['k'] == key && saved['e'] is String) {
          entry = EtagEntry(saved['e'] as String, saved['b']);
        }
      }
    } catch (_) {
      entry = null;
    }
    _memory[key] = entry;
    return entry;
  }

  @override
  Future<void> write(String key, EtagEntry entry) async {
    _memory[key] = entry;
    try {
      final file = await _file(key);
      await file.parent.create(recursive: true);
      await file.writeAsString(jsonEncode({'k': key, 'e': entry.etag, 'b': entry.body}), flush: true);
    } catch (error) {
      debugPrint('ABOS etag: not saved ($error)');
    }
  }

  @override
  Future<void> remove(String key) async {
    final had = _memory[key];
    _memory[key] = null;
    try {
      final file = await _file(key);
      if (had != null || await file.exists()) await file.delete();
    } catch (_) {}
  }

  @override
  Future<void> clear() async {
    _memory.clear();
    try {
      final dir = await _directory();
      if (await dir.exists()) await dir.delete(recursive: true);
    } catch (_) {}
  }
}

/// ⭐ The app's one ETag store, and what a sign-out or company switch wipes.
class EtagCache {
  const EtagCache._();

  static EtagStore store = FileEtagStore();

  /// ⛔ Sign-out or company switch — the last person's bodies leave the phone, and the short in-memory answers too.
  static Future<void> forget() async {
    SharedAnswer.clearAll();
    try {
      await store.clear();
    } catch (_) {}
  }
}

/// ⭐ Conditional GETs for the app's busiest reads (polling cut, plan 23 Nov 2026).
///
/// <p>For a GET on one of [paths]: a stored ETag goes out as `If-None-Match`; a 200 with an ETag is stored with its
/// body; a 304 comes back to the caller as a 200 carrying the stored body, so no screen knows the difference.
/// A 200 without an ETag (an older server) drops what was stored — then everything behaves exactly as before.
///
/// <p>⛔ Only a 200 is ever stored — never a 401, 403, 404 or anything else. ⓘ A caller that sends its own
/// `If-None-Match` ([WidgetSummaryApi] in the open app) keeps its own 304. ⓘ Any write (POST, PUT, PATCH, DELETE)
/// drops the short in-memory answers ([SharedAnswer]), since it may have changed them.
class EtagInterceptor extends Interceptor {
  EtagInterceptor({EtagStore? store}) : _store = store;

  final EtagStore? _store;
  EtagStore get store => _store ?? EtagCache.store;

  /// The reads that carry an ETag — paths under the API base.
  static const Set<String> paths = {
    '/dashboard/today',
    '/approvals/pending',
    '/accounts/net-worth/summary',
    '/notifications',
    '/notices/bar',
    '/messenger/threads',
    '/widgets/summary',
  };

  static const _sentKey = 'abosEtagSent';

  /// Set on a response that was served from the stored body after a 304.
  static const cachedKey = 'abosEtagCached';

  /// The path under the API base, or null when [options] is not one of [paths].
  @visibleForTesting
  static String? matchedPath(RequestOptions options) {
    if (options.method.toUpperCase() != 'GET') return null;
    var path = options.uri.path;
    final base = Uri.tryParse(options.baseUrl)?.path ?? '';
    final prefix = base.endsWith('/') ? base.substring(0, base.length - 1) : base;
    if (prefix.isNotEmpty && path.startsWith(prefix)) path = path.substring(prefix.length);
    if (path.length > 1 && path.endsWith('/')) path = path.substring(0, path.length - 1);
    return paths.contains(path) ? path : null;
  }

  static String _key(RequestOptions options) => options.uri.toString();

  @override
  Future<void> onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    if (const {'POST', 'PUT', 'PATCH', 'DELETE'}.contains(options.method.toUpperCase())) {
      SharedAnswer.clearAll();
    }
    if (matchedPath(options) != null && !_callerSentOwn(options)) {
      try {
        final entry = await store.read(_key(options));
        if (entry != null) {
          options.headers['If-None-Match'] = entry.etag;
          options.extra[_sentKey] = true;
        }
      } catch (_) {}
    }
    handler.next(options);
  }

  bool _callerSentOwn(RequestOptions options) =>
      options.extra[_sentKey] != true &&
      options.headers.keys.any((k) => k.toLowerCase() == 'if-none-match');

  @override
  Future<void> onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) async {
    final options = response.requestOptions;
    if (matchedPath(options) == null) return handler.next(response);
    if (response.statusCode == 304) {
      final served = await _fromStore(response);
      return handler.next(served ?? response);
    }
    if (response.statusCode == 200) await _remember(response);
    handler.next(response);
  }

  @override
  Future<void> onError(DioException err, ErrorInterceptorHandler handler) async {
    final response = err.response;
    if (response != null && response.statusCode == 304 && matchedPath(err.requestOptions) != null) {
      final served = await _fromStore(response);
      if (served != null) return handler.resolve(served);
    }
    handler.next(err);
  }

  /// The stored body as a 200 — only when this interceptor sent the ETag.
  Future<Response<dynamic>?> _fromStore(Response<dynamic> response) async {
    final options = response.requestOptions;
    if (options.extra[_sentKey] != true) return null;
    try {
      final entry = await store.read(_key(options));
      if (entry == null) return null;
      return Response<dynamic>(
        requestOptions: options,
        data: entry.body,
        statusCode: 200,
        statusMessage: 'OK',
        headers: response.headers,
        extra: {...response.extra, cachedKey: true},
      );
    } catch (_) {
      return null;
    }
  }

  Future<void> _remember(Response<dynamic> response) async {
    try {
      final key = _key(response.requestOptions);
      final etag = response.headers.value('etag');
      final body = response.data;
      if (etag != null && etag.isNotEmpty && (body is Map || body is List)) {
        await store.write(key, EtagEntry(etag, body));
      } else {
        await store.remove(key);
      }
    } catch (_) {}
  }
}
