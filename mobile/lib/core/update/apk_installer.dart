import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter/services.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../api_client/api_client.dart';

/// Downloads an update inside the app and hands it to the system package
/// installer — docs/Contract §৬ rule খ, the owner's decision of 27 September
/// 2026: nobody should have to follow a link and find a file in a downloads
/// folder again.
///
/// <p>⛔ <b>Nothing here installs.</b> [install] opens Android's own prompt
/// and stops; the person says yes or no there, every time. An app that could
/// silently replace itself is an app that could be made to replace itself
/// with something else, and that confirmation is not this file's to remove.
///
/// <p>⛔ <b>And nothing reaches [install] unverified.</b> The caller checks
/// the byte count and then the SHA-256 against what the server said, and
/// deletes the file when either disagrees — see [UpdateDownloadButton].
class ApkInstaller {
  const ApkInstaller._();

  static const _install = MethodChannel('com.abos.abos_mobile/install');

  static const _fileName = 'abos-update.apk';

  /// Whether this app may hand a package to the installer at all.
  ///
  /// <p>From Android 8, REQUEST_INSTALL_PACKAGES in the manifest only buys
  /// the right to ask. Until the person switches it on for this app the
  /// installer refuses, with a dialog of its own, in English, and only
  /// <i>after</i> the download has finished — on a rep's mobile data. So it is
  /// asked first.
  ///
  /// <p>True when the check itself fails, on purpose. A phone that cannot
  /// answer must not be stopped from updating; the installer will still
  /// refuse if it has to.
  static Future<bool> canInstall() async {
    try {
      return await _install.invokeMethod<bool>('canInstallApks') ?? true;
    } catch (_) {
      return true;
    }
  }

  /// Opens this app's own "install unknown apps" switch, not the top of
  /// Settings.
  static Future<void> openInstallSettings() async {
    try {
      await _install.invokeMethod<void>('openInstallSettings');
    } catch (_) {
      // Nothing useful to say if Settings will not open; the message on
      // screen already names what has to be switched on.
    }
  }

  static Future<File> _target() async {
    final dir = await getTemporaryDirectory();
    return File('${dir.path}/$_fileName');
  }

  /// The file a previous attempt left behind, if there is one.
  ///
  /// <p>Exists so that granting the install permission does not cost a second
  /// download. The caller uses it only after checking its length and its
  /// SHA-256: an unverified leftover is not an update, it is a file.
  static Future<File?> cached() async {
    try {
      final file = await _target();
      return await file.exists() ? file : null;
    } catch (_) {
      return null;
    }
  }

  /// Downloads into the app's own cache directory — somewhere the app can
  /// always write, that no other app can, and that Android clears on its
  /// own.
  ///
  /// <p>⛔ https only. The server refuses to publish anything else, and this
  /// is the second lock on the same door: an APK fetched over plain http can
  /// be swapped on the way by anybody on the same network.
  static Future<File> download(
    String url, {
    required void Function(int received, int total) onProgress,
    CancelToken? cancelToken,
  }) async {
    if (!isHttps(url)) {
      throw const InsecureUpdateUrl();
    }

    final file = await _target();
    if (await file.exists()) await file.delete();

    await ApiClient.dio.download(
      url,
      file.path,
      onReceiveProgress: onProgress,
      cancelToken: cancelToken,
      options: Options(
        extra: ApiClient.skipAuthRefresh,
        // The API's own timeout is sized for JSON. An APK on mobile data is
        // tens of megabytes and must be allowed the minutes it takes.
        receiveTimeout: const Duration(minutes: 20),
      ),
    );
    return file;
  }

  /// SHA-256 of the file, as lowercase hex. Read as a stream: the whole APK
  /// held in memory at once is the kind of allocation a small phone refuses.
  static Future<String> sha256Of(File file) async {
    final digest = await sha256.bind(file.openRead()).first;
    return digest.toString();
  }

  /// Hands the file to the system package installer, which asks the person.
  static Future<void> install(File file) async {
    await OpenFilex.open(
      file.path,
      type: 'application/vnd.android.package-archive',
    );
  }
}

/// The server named an address that is not https.
class InsecureUpdateUrl implements Exception {
  const InsecureUpdateUrl();
}

bool isHttps(String url) => Uri.tryParse(url)?.scheme == 'https';

/// Whether a computed hash is the one the server said to expect.
///
/// <p>Case-insensitive and trimmed: a hash is the same hash in upper or lower
/// hex, and a pasted one is a real way a trailing space gets in.
///
/// <p>⛔ An empty expectation matches nothing. "Nothing to compare against"
/// must never be read as "it matched".
bool sha256Matches(String actual, String? expected) {
  final want = expected?.trim().toLowerCase() ?? '';
  if (want.isEmpty) return false;
  return actual.trim().toLowerCase() == want;
}

/// The cheap check before the expensive one: a truncated download shows in
/// its byte count long before the whole file has been hashed. Not a
/// replacement for the hash — two files of one size are not thereby one file.
bool sizeMatches(int actual, int? expected) =>
    expected != null && expected > 0 && actual == expected;
