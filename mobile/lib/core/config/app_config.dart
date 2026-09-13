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
  /// <p><b>Moved off abos.univer.com.bd on 13 September 2026</b>, and the
  /// reason is worth keeping: that host was the office Mac mini behind the
  /// office router, and for several days nothing outside the office could
  /// reach it — every inbound port timed out while the machine itself served
  /// perfectly on the LAN. A server a rep cannot reach from a shop is not a
  /// server, however healthy it looks from the next desk. ABOS now runs on
  /// shared hosting, which is reachable from anywhere by construction.
  ///
  /// <p>Verified from this machine the same day, and deliberately not from
  /// inside the office network that used to flatter the old host:
  /// `GET /up` → 200 in 0.31s, `/` → 302, and `POST /api/v1/auth/login` with
  /// a bad password → 422 carrying ABOS's own Bengali message, which is what
  /// actually proves it is this application answering and not a parked page.
  /// <p><b>`erp.` and not `os.`</b>, though both answer today: the server's
  /// own `APP_URL` is `erp.adi.com.bd`, so that is where its mail links and
  /// CLI-generated URLs point, and an app on a second name would be the one
  /// thing disagreeing with everything else.
  ///
  /// <p>⚠️ This is a one-way door. There is no screen inside the app for
  /// changing the address, deliberately — a handset given a wrong one could
  /// not be corrected from outside — so the domain must stay put once builds
  /// are in people's hands.
  static const String _live = 'https://erp.adi.com.bd/api/v1';

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

  /// What this build calls itself, sent on every request.
  ///
  /// <p>The server asks for it in two places and has been told nothing in
  /// either: `POST /auth/login` accepts an `appVersion` field, and
  /// `SyncController` reads an `X-App-Version` header on every pull and push
  /// to register the device. Both were arriving null, so the device registry
  /// recorded every handset as running an unknown build.
  ///
  /// <p>That only matters on the day it matters, and then it matters a lot: a
  /// rep reports that a screen is wrong, and the first question — *which
  /// build are they running* — has no answer, so the office cannot tell a bug
  /// from a phone that never updated.
  ///
  /// ⚠️ Must match `pubspec.yaml`'s `version:`. Nothing in Dart can read
  /// pubspec at runtime without another package, so the two are kept in step
  /// by `test/app_version_test.dart`, which reads the file and fails when
  /// they drift. A version constant that silently lies is worse than none.
  static const String appVersion = '0.1.0';

  /// The counter Android actually compares, and the one `GET /app/version`
  /// answers with — see docs/Contract §৬.
  ///
  /// <p>⚠️ Never compare version *names*. `"0.2.0" > "0.10.0"` is true as a
  /// string and false as a version, and a phone that believed it would tell
  /// a rep they were up to date for as long as the mistake lasted.
  ///
  /// <p>Must match the `+N` in `pubspec.yaml`'s `version:` — pinned by
  /// `test/app_version_test.dart` for the same reason as [appVersion].
  static const int appVersionCode = 1;

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
