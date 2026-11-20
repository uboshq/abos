import 'package:flutter/material.dart';

import '../../core/api_client/retired_build.dart';
import '../../core/locale/l10n.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/update/app_version_check.dart';
import 'store_update_button.dart';

/// Stands between the person and the app when this build is too old to be
/// trusted, and says so quietly when it is merely behind — docs/Contract §৬.
///
/// <p><b>Two different answers to two different questions.</b> A newer build
/// existing means "this would be better"; a build below `minimumCode` means
/// "what you are looking at is wrong". The second is not a stronger version
/// of the first, and the twelfth of September is why: six screens read keys
/// the server had never sent, drew a number for every one of them, and no
/// guard went red. A build in that state must not be left running because
/// its owner has not got around to updating.
///
/// <p>⛔ <b>A failed check does nothing at all.</b> See [UpdateStatus.check]:
/// a wall that rises when the network drops would lock every phone that lost
/// signal, in an app built to work without one.
class UpdateGate extends StatefulWidget {
  const UpdateGate({super.key, required this.child, this.check, this.fetch});

  final Widget child;

  /// Seam for tests — the real check needs a server.
  final Future<UpdateStatus> Function()? check;

  /// Seam for tests — where the newest build is, once the server refused this one.
  final Future<AppRelease> Function()? fetch;

  @override
  State<UpdateGate> createState() => _UpdateGateState();
}

class _UpdateGateState extends State<UpdateGate> {
  UpdateStatus _status = UpdateStatus.fine;
  bool _noticeDismissed = false;

  @override
  void initState() {
    super.initState();
    RetiredBuild.refusals.addListener(_refused);
    _check();
  }

  @override
  void dispose() {
    RetiredBuild.refusals.removeListener(_refused);
    super.dispose();
  }

  /// ⛔ সার্ভার ৪২৬ দিল — নিজের হিসাব যা-ই বলুক, দেয়াল (পূর্ণ নিরীক্ষা §৯ ⚠️৯)। ⓘ নতুনটা কোথায় জানা না গেলে কিছুই নয়, পরের
  /// ৪২৬-এ আবার চেষ্টা — দেয়ালে নামানোর বোতাম লাগে।
  Future<void> _refused() async {
    if (_status.verdict == UpdateVerdict.blocked) return;
    final AppRelease release;
    try {
      release = await (widget.fetch ?? AppVersionApi.latest)();
    } catch (_) {
      return;
    }
    if (mounted) setState(() => _status = UpdateStatus(verdict: UpdateVerdict.blocked, release: release));
  }

  Future<void> _check() async {
    final status = await (widget.check ?? UpdateStatus.check)();
    if (mounted) setState(() => _status = status);
  }

  @override
  Widget build(BuildContext context) {
    if (_status.verdict == UpdateVerdict.blocked) {
      return _UpdateWall(release: _status.release!);
    }

    return Column(
      children: [
        if (_status.verdict == UpdateVerdict.available && !_noticeDismissed)
          _UpdateNotice(
            release: _status.release!,
            onDismiss: () => setState(() => _noticeDismissed = true),
          ),
        Expanded(child: widget.child),
      ],
    );
  }
}

/// A line across the top. It can be put aside, because the work underneath it
/// is still correct — that is the whole difference from the wall.
class _UpdateNotice extends StatelessWidget {
  const _UpdateNotice({required this.release, required this.onDismiss});

  final AppRelease release;
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: context.palette.pendingSurface,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
            AppSpacing.md, AppSpacing.sm, AppSpacing.xs, AppSpacing.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                Icon(Icons.system_update_outlined,
                    size: 18, color: context.palette.pending),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(
                    release.versionName == null ? context.l10n.updateNewVersion : context.l10n.updateNewVersionNamed(release.versionName!),
                    style: TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        color: context.palette.pending),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close, size: 18),
                  tooltip: context.l10n.updateDismiss,
                  onPressed: onDismiss,
                ),
              ],
            ),
            // ⭐ কী নতুন — মালিক, ২ অক্টোবর ২০২৬: "ki ki update holo ta dekhabe"।
            // এক লাইনে এক জিনিস ([[AppRelease.whatsNew]])।
            for (final line in release.whatsNew)
              Padding(
                padding: const EdgeInsets.only(left: 26, right: AppSpacing.sm, top: 2),
                child: Text('• $line',
                    style: TextStyle(fontSize: 12.5, color: context.palette.pending)),
              ),
            // docs/Contract section 6, rule kha: the app downloads, checks
            // and hands over its own update. No link, no browser.
            Padding(
              padding: const EdgeInsets.only(right: AppSpacing.sm),
              // ⓘ play বিল্ডে নামানো নয় — Play Store-এর পাতা ([[updateButtonFor]])
              child: updateButtonFor(release, label: context.l10n.updateNow, dense: true),
            ),
          ],
        ),
      ),
    );
  }
}

/// No way past. Shown only below `minimumCode`.
class _UpdateWall extends StatelessWidget {
  const _UpdateWall({required this.release});

  final AppRelease release;

  @override
  Widget build(BuildContext context) {
    // docs/Contract §৬ rule গ. Somebody facing a wall will uninstall and
    // reinstall, and orders that never synced live inside the app's own
    // storage — they go with it. So the count is said before anybody reaches
    // for the uninstall button, not after.
    final pending = SyncEngine.instance.pendingCount;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(AppSpacing.lg),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.system_update,
                    size: 56, color: context.palette.warning),
                const SizedBox(height: AppSpacing.md),
                Text(
                  context.l10n.updateWallTitle,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  release.note ?? context.l10n.updateWallBody,
                  textAlign: TextAlign.center,
                  style: TextStyle(color: context.palette.onSurfaceMuted),
                ),
                if (pending > 0) ...[
                  const SizedBox(height: AppSpacing.md),
                  Container(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    decoration: BoxDecoration(
                      color: context.palette.dangerSurface,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      children: [
                        Text(
                          context.l10n.updateWallPending(pending),
                          style: TextStyle(
                              fontWeight: FontWeight.w700,
                              color: context.palette.danger),
                          textAlign: TextAlign.center,
                        ),
                        const SizedBox(height: AppSpacing.xs),
                        Text(
                          context.l10n.updateWallKeepOrders,
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontSize: 12.5),
                        ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: AppSpacing.lg),
                // ⓘ play বিল্ডে দেয়াল থেকেও Play Store-এ — নিজে নামানো Play-র নিয়মে নিষেধ
                updateButtonFor(release),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
