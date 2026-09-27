import 'dart:io';

import 'package:dio/dio.dart';

import 'apk_installer.dart';
import 'app_version_check.dart';
import 'storage_check.dart';

/// Where the flow has got to, for the line under the button.
enum UpdateStep { checking, downloading, verifying }

/// How an attempt ended. Every one of these has its own sentence on screen —
/// see `UpdateDownloadButton` — because "it did not work" is not something a
/// person in a shop can act on, and each of these is put right differently.
enum UpdateOutcome {
  /// Android's own install prompt is open. The rest is between the person
  /// and the system.
  handedToInstaller,

  /// "Install unknown apps" is off for this app.
  needsPermission,

  /// Not enough free room for the download and the installer's scratch space.
  notEnoughSpace,

  /// The file is not the size the server said. Deleted.
  incomplete,

  /// ⛔ The file's SHA-256 is not the one the server said. Deleted, and not
  /// installed.
  mismatch,

  /// No connection, or it dropped part way.
  offline,

  /// The release cannot be verified at all: no https address, no hash or no
  /// size. Nothing was downloaded.
  notInstallable,

  /// Anything else.
  failed,
}

class UpdateResult {
  const UpdateResult(this.outcome, {this.megabytesNeeded});

  final UpdateOutcome outcome;

  /// Only with [UpdateOutcome.notEnoughSpace].
  final int? megabytesNeeded;
}

/// Everything the flow touches outside itself, so the flow can be run in a
/// test with no phone, no network and no installer.
class UpdateActions {
  const UpdateActions({
    required this.canInstall,
    required this.cached,
    required this.freeBytes,
    required this.download,
    required this.lengthOf,
    required this.sha256Of,
    required this.install,
    required this.delete,
  });

  const UpdateActions.real()
      : canInstall = ApkInstaller.canInstall,
        cached = ApkInstaller.cached,
        freeBytes = StorageCheck.freeBytes,
        download = _download,
        lengthOf = _lengthOf,
        sha256Of = ApkInstaller.sha256Of,
        install = ApkInstaller.install,
        delete = _delete;

  final Future<bool> Function() canInstall;
  final Future<File?> Function() cached;
  final Future<int?> Function() freeBytes;
  final Future<File> Function(
      String url, void Function(int received, int total) onProgress) download;
  final Future<int> Function(File file) lengthOf;
  final Future<String> Function(File file) sha256Of;
  final Future<void> Function(File file) install;
  final Future<void> Function(File file) delete;

  static Future<File> _download(
          String url, void Function(int received, int total) onProgress) =>
      ApkInstaller.download(url, onProgress: onProgress);

  static Future<int> _lengthOf(File file) => file.length();

  static Future<void> _delete(File file) async {
    try {
      if (await file.exists()) await file.delete();
    } catch (_) {
      // The cache directory is Android's to clear; a file that will not
      // delete now is overwritten by the next attempt.
    }
  }
}

/// Download, check, hand over — docs/Contract §৬ rule খ, in the order the
/// contract gives it:
///
/// 1. the install permission, <b>before</b> a single byte moves;
/// 2. a file a previous attempt left behind, used only if it verifies;
/// 3. free space, measured against the size the server named;
/// 4. the download, with progress;
/// 5. the size (cheap), then the SHA-256;
/// 6. Android's own install prompt.
///
/// <p>⛔ A file that fails step 5 is deleted and never reaches step 6.
class UpdateInstallFlow {
  const UpdateInstallFlow._();

  static Future<UpdateResult> run(
    AppRelease release, {
    UpdateActions actions = const UpdateActions.real(),
    void Function(UpdateStep step, double? progress)? onStep,
  }) async {
    if (!release.installable) {
      return const UpdateResult(UpdateOutcome.notInstallable);
    }
    final url = release.url!;
    final size = release.sizeBytes!;
    final hash = release.apkSha256!;

    onStep?.call(UpdateStep.checking, null);

    if (!await actions.canInstall()) {
      return const UpdateResult(UpdateOutcome.needsPermission);
    }

    // What a previous attempt pulled down. This is what makes granting the
    // permission cost nothing the second time — and it is only ever used
    // when it is provably the file the server is offering now, so it cannot
    // install yesterday's build or half of one.
    final leftover = await actions.cached();
    if (leftover != null) {
      if (await _verifies(leftover, size, hash, actions, onStep)) {
        return _handOver(leftover, actions);
      }
      await actions.delete(leftover);
    }

    if (!hasEnoughSpace(await actions.freeBytes(), size)) {
      return UpdateResult(
        UpdateOutcome.notEnoughSpace,
        megabytesNeeded: megabytesNeeded(size),
      );
    }

    onStep?.call(UpdateStep.downloading, 0);
    final File file;
    try {
      file = await actions.download(url, (received, total) {
        // The server's Content-Length when it sends one, the contract's
        // size when it does not: either way the bar has a denominator.
        final whole = total > 0 ? total : size;
        onStep?.call(UpdateStep.downloading,
            (received / whole).clamp(0.0, 1.0).toDouble());
      });
    } on InsecureUpdateUrl {
      return const UpdateResult(UpdateOutcome.notInstallable);
    } on DioException {
      return const UpdateResult(UpdateOutcome.offline);
    } on SocketException {
      return const UpdateResult(UpdateOutcome.offline);
    } catch (_) {
      return const UpdateResult(UpdateOutcome.failed);
    }

    onStep?.call(UpdateStep.verifying, null);

    // The cheap check first.
    if (!sizeMatches(await actions.lengthOf(file), size)) {
      await actions.delete(file);
      return const UpdateResult(UpdateOutcome.incomplete);
    }
    if (!sha256Matches(await actions.sha256Of(file), hash)) {
      await actions.delete(file);
      return const UpdateResult(UpdateOutcome.mismatch);
    }

    return _handOver(file, actions);
  }

  static Future<bool> _verifies(
    File file,
    int size,
    String hash,
    UpdateActions actions,
    void Function(UpdateStep step, double? progress)? onStep,
  ) async {
    try {
      if (!sizeMatches(await actions.lengthOf(file), size)) return false;
      onStep?.call(UpdateStep.verifying, null);
      return sha256Matches(await actions.sha256Of(file), hash);
    } catch (_) {
      return false;
    }
  }

  static Future<UpdateResult> _handOver(
      File file, UpdateActions actions) async {
    try {
      await actions.install(file);
      return const UpdateResult(UpdateOutcome.handedToInstaller);
    } catch (_) {
      return const UpdateResult(UpdateOutcome.failed);
    }
  }
}
