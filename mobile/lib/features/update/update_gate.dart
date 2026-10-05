import 'package:flutter/material.dart';

import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/update/app_version_check.dart';
import 'update_download_button.dart';

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
  const UpdateGate({super.key, required this.child, this.check});

  final Widget child;

  /// Seam for tests — the real check needs a server.
  final Future<UpdateStatus> Function()? check;

  @override
  State<UpdateGate> createState() => _UpdateGateState();
}

class _UpdateGateState extends State<UpdateGate> {
  UpdateStatus _status = UpdateStatus.fine;
  bool _noticeDismissed = false;

  @override
  void initState() {
    super.initState();
    _check();
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
      color: AppColors.pendingSurface,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(
            AppSpacing.md, AppSpacing.sm, AppSpacing.xs, AppSpacing.sm),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              children: [
                const Icon(Icons.system_update_outlined,
                    size: 18, color: AppColors.pending),
                const SizedBox(width: AppSpacing.sm),
                Expanded(
                  child: Text(
                    'নতুন সংস্করণ এসেছে${release.versionName == null ? '' : ' (${release.versionName})'}।',
                    style: const TextStyle(
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                        color: AppColors.pending),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close, size: 18),
                  tooltip: 'সরিয়ে রাখুন',
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
                    style: const TextStyle(fontSize: 12.5, color: AppColors.pending)),
              ),
            // docs/Contract section 6, rule kha: the app downloads, checks
            // and hands over its own update. No link, no browser.
            Padding(
              padding: const EdgeInsets.only(right: AppSpacing.sm),
              child: UpdateDownloadButton(
                release: release,
                label: 'এখনই আপডেট করুন',
                dense: true,
              ),
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
                const Icon(Icons.system_update,
                    size: 56, color: AppColors.warning),
                const SizedBox(height: AppSpacing.md),
                const Text(
                  'এই সংস্করণটি আর ব্যবহার করা যাবে না',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: AppSpacing.sm),
                Text(
                  release.note ??
                      'অ্যাপের নতুন সংস্করণ নামিয়ে বসান। পুরনো সংস্করণ ভুল তথ্য দেখাতে পারে।',
                  textAlign: TextAlign.center,
                  style: const TextStyle(color: AppColors.onSurfaceMuted),
                ),
                if (pending > 0) ...[
                  const SizedBox(height: AppSpacing.md),
                  Container(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    decoration: BoxDecoration(
                      color: AppColors.dangerSurface,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Column(
                      children: [
                        Text(
                          'এই ফোনে $pending টি অর্ডার এখনো পাঠানো হয়নি',
                          style: const TextStyle(
                              fontWeight: FontWeight.w700,
                              color: AppColors.danger),
                          textAlign: TextAlign.center,
                        ),
                        const SizedBox(height: AppSpacing.xs),
                        const Text(
                          'অ্যাপ মুছে ফেললে ওগুলো চলে যাবে। নতুন সংস্করণ '
                          'বসানোর সময় পুরনোটা মুছবেন না — উপরে বসিয়ে দিন, '
                          'অর্ডারগুলো থেকে যাবে।',
                          textAlign: TextAlign.center,
                          style: TextStyle(fontSize: 12.5),
                        ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: AppSpacing.lg),
                UpdateDownloadButton(release: release),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
