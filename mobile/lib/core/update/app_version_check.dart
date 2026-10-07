import 'package:dio/dio.dart';

import '../api_client/api_client.dart';
import '../config/app_config.dart';

/// What the server says the newest build is — docs/Contract §৬.
///
/// <p><b>The one door that needs no token.</b> A build old enough to be
/// refused may not be able to sign in at all, and that is exactly the moment
/// someone needs to be told they are running something stale. Behind auth,
/// the message would never reach the state it was written for.
class AppVersionApi {
  const AppVersionApi._();

  static Future<AppRelease> latest() async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
      '/app/version',
      options: Options(extra: ApiClient.skipAuthRefresh),
    );
    return AppRelease.fromJson(response.data ?? const {});
  }
}

class AppRelease {
  const AppRelease({
    required this.versionCode,
    this.versionName,
    this.url,
    this.apkSha256,
    this.sizeBytes,
    this.minimumCode = 0,
    this.noteBn,
    this.noteEn,
  });

  /// A number, never a string. `"0.2.0" > "0.10.0"` is true as a string and
  /// false as a version — a phone that compared names would tell a rep they
  /// were up to date for as long as the mistake lasted.
  ///
  /// <p>⚠️ This is not the "money travels as a string" rule in reverse. That
  /// rule exists because decimal places must not be lost. This is an ordinal,
  /// and an ordinal compared as text is simply wrong.
  final int versionCode;

  final String? versionName;
  final String? url;

  /// SHA-256 of the APK at [url], 64 lowercase hex characters. What the
  /// downloaded file is checked against before it is ever offered to the
  /// installer: docs/Contract section 6, rule kha.
  final String? apkSha256;

  /// The APK's exact byte count. Checked first because it is cheap: a
  /// truncated download shows in its length long before its digest has been
  /// computed. Also what the free-space check measures against.
  final int? sizeBytes;

  /// Whether this release can be installed from inside the app at all.
  ///
  /// <p>All three, or none: an https address, a well-formed hash and a real
  /// size. The server refuses to publish a release missing any of them
  /// (`503 {configured:false}`), so this is the second lock. A release that
  /// fails it is still announced; it just offers no button, because a
  /// download that cannot be verified must not be started.
  bool get installable {
    final address = url;
    final hash = apkSha256?.trim() ?? '';
    final size = sizeBytes ?? 0;
    return address != null &&
        Uri.tryParse(address)?.scheme == 'https' &&
        _sha256Shape.hasMatch(hash) &&
        size > 0;
  }

  static final RegExp _sha256Shape = RegExp(r'^[0-9a-fA-F]{64}$');

  /// Below this, the build must not be used at all.
  final int minimumCode;

  final String? noteBn;
  final String? noteEn;

  /// Bengali first — the app has no language switch and every label around
  /// this one is Bangla; English is the fallback rather than the reverse.
  String? get note => noteBn ?? noteEn;

  /// ⭐ নতুন কী এল — এক লাইনে এক জিনিস। সার্ভারের নোট `|` বা নতুন লাইনে ভাগ করা
  /// (`.env`-এর ANDROID_NOTE_BN এক লাইনের, তাই `|`)। নোট না থাকলে ফাঁকা।
  List<String> get whatsNew => (note ?? '')
      .split(RegExp(r'\s*[|\n]\s*'))
      .map((line) => line.trim())
      .where((line) => line.isNotEmpty)
      .toList(growable: false);

  factory AppRelease.fromJson(Map<String, dynamic> json) {
    final note = json['note'];
    return AppRelease(
      versionCode: (json['versionCode'] as num?)?.toInt() ?? 0,
      versionName: json['versionName'] as String?,
      url: json['url'] as String?,
      apkSha256: json['apkSha256'] as String?,
      // A number on the wire, like versionCode and for the same reason: it
      // is compared, and "9" > "10" as text.
      sizeBytes: (json['sizeBytes'] as num?)?.toInt(),
      minimumCode: (json['minimumCode'] as num?)?.toInt() ?? 0,
      noteBn: note is Map ? note['bn'] as String? : null,
      noteEn: note is Map ? note['en'] as String? : null,
    );
  }
}

/// What this build should do about what it was told.
enum UpdateVerdict {
  /// Up to date, or the server could not be asked. Both mean: carry on.
  fine,

  /// Newer build exists. A notice that can be put aside; work continues.
  available,

  /// Below `minimumCode`. This build must not be used.
  blocked,
}

/// The decision, kept apart from both the network call and the screen so it
/// can be tested as what it is — three comparisons, one of which is the most
/// consequential line in this file.
class UpdateStatus {
  const UpdateStatus({required this.verdict, this.release});

  final UpdateVerdict verdict;
  final AppRelease? release;

  static const UpdateStatus fine = UpdateStatus(verdict: UpdateVerdict.fine);

  /// ⛔ **A failed check changes nothing — no notice, no wall.**
  ///
  /// <p>This is the rule that matters most. Unreachable means the phone does
  /// not *know* whether it is current, and treating not-knowing as "out of
  /// date" would lock every handset that lost signal — in an app whose entire
  /// purpose is working without one (docs/Contract §০, the owner's first
  /// decision). A version wall that rises when the network drops does not
  /// protect the app; it is the outage.
  static Future<UpdateStatus> check({
    Future<AppRelease> Function()? fetch,
    int current = AppConfig.appVersionCode,
  }) async {
    final AppRelease release;
    try {
      release = await (fetch ?? AppVersionApi.latest)();
    } catch (_) {
      return fine;
    }
    return of(release, current: current);
  }

  static UpdateStatus of(AppRelease release, {required int current}) {
    if (current < release.minimumCode) {
      return UpdateStatus(verdict: UpdateVerdict.blocked, release: release);
    }
    if (current < release.versionCode) {
      return UpdateStatus(verdict: UpdateVerdict.available, release: release);
    }
    return fine;
  }
}
