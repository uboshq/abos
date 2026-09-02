/// Where the app finds the ABOS server, per build.
///
/// Override at build/run time — no code change, no rebuild of the source:
///   flutter run --dart-define=ABOS_API_BASE_URL=http://192.168.0.10:8090/api/v1
///
/// <p><b>The default is the live server, and that is deliberate.</b> The
/// obvious-looking default is the emulator's host-loopback alias
/// (`10.0.2.2`), and it is wrong for everybody who is not a developer: on a
/// real phone that address is nothing at all, so the first build anybody
/// installs cannot log in — and reports only "login failed", because from the
/// app's side an unreachable host and a wrong password look much the same.
///
/// So the build that leaves this machine points at the real server, and the
/// emulator is the case that opts out:
///
///   flutter run --dart-define=ABOS_API_BASE_URL=http://10.0.2.2:8090/api/v1
class AppConfig {
  const AppConfig._();

  static const String _baseUrlOverride =
      String.fromEnvironment('ABOS_API_BASE_URL');

  /// https, not http. Android has blocked cleartext traffic by default since
  /// API 28, and it fails with a network error that names no cause — which
  /// reads on the phone exactly like "the server is down".
  ///
  /// Verified reachable from outside the office network on 2 September 2026:
  /// GET https://abos.univer.com.bd/up answered 200.
  static const String _live = 'https://abos.univer.com.bd/api/v1';

  static String get apiBaseUrl {
    if (_baseUrlOverride.isNotEmpty) {
      return _stripTrailingSlash(_baseUrlOverride);
    }
    return _live;
  }

  /// Guards against `.../api/v1/` + `/auth/login` producing a double slash,
  /// which some reverse proxies treat as a different (404) path.
  static String _stripTrailingSlash(String value) =>
      value.endsWith('/') ? value.substring(0, value.length - 1) : value;

  static const Duration connectTimeout = Duration(seconds: 15);
  static const Duration receiveTimeout = Duration(seconds: 30);

  /// How many queued offline changes are pushed in one request.
  static const int syncBatchSize = 50;

  /// After this many failed attempts a queued change stops being retried and
  /// is surfaced to the person instead — see SyncEngine.rejectedItems. It is
  /// not deleted: a queue that quietly drops a change nobody could send is a
  /// queue that told the rep it was sent.
  static const int syncMaxAttempts = 5;
}
