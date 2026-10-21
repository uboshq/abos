import 'package:flutter/material.dart';

import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/update/apk_installer.dart';
import '../../core/update/app_version_check.dart';
import '../../core/update/update_install_flow.dart';

/// The owner's instruction in one widget: tap, and the app downloads its own
/// update, checks it, and hands it to the installer. No browser, no downloads
/// folder, no finding the file again.
///
/// <p>Two taps, never fewer: this button, then Android's own prompt. There is
/// no way round the second outside the Play Store, and this file does not
/// look for one.
///
/// <p>Every way it can fail has its own sentence, in Bangla, saying what
/// happened and what to do. The flow itself is [UpdateInstallFlow]; this only
/// draws where it has got to.
class UpdateDownloadButton extends StatefulWidget {
  const UpdateDownloadButton({
    super.key,
    required this.release,
    this.label,
    this.dense = false,
    this.actions = const UpdateActions.real(),
    this.openInstallSettings,
  });

  final AppRelease release;
  final String? label;

  /// The compact form for the notice strip: a text button rather than the
  /// full-width filled one the wall uses.
  final bool dense;

  /// Seams for tests.
  final UpdateActions actions;
  final Future<void> Function()? openInstallSettings;

  @override
  State<UpdateDownloadButton> createState() => _UpdateDownloadButtonState();
}

class _UpdateDownloadButtonState extends State<UpdateDownloadButton> {
  UpdateStep? _step;
  double? _progress;
  UpdateResult? _result;

  bool get _busy => _step != null;

  Future<void> _start() async {
    if (_busy) return;
    setState(() {
      _step = UpdateStep.checking;
      _progress = null;
      _result = null;
    });

    final result = await UpdateInstallFlow.run(
      widget.release,
      actions: widget.actions,
      onStep: (step, progress) {
        if (!mounted) return;
        setState(() {
          _step = step;
          _progress = progress;
        });
      },
    );

    if (!mounted) return;
    setState(() {
      _step = null;
      _progress = null;
      // Nothing to say once the installer has it: Android's prompt is on
      // screen, and a line of ours under it would be talking over it.
      _result =
          result.outcome == UpdateOutcome.handedToInstaller ? null : result;
    });
  }

  @override
  Widget build(BuildContext context) {
    // A release that cannot be verified offers no button. A download that
    // cannot be checked must not be started.
    if (!widget.release.installable) {
      return _Message(
        context.l10n.updateNotInstallable,
        colour: context.palette.warning,
      );
    }

    final result = _result;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (_busy) ...[
          LinearProgressIndicator(
            value: _step == UpdateStep.downloading ? _progress : null,
            minHeight: 4,
          ),
          const SizedBox(height: AppSpacing.xs),
          _Message(_stepText(_step!, _progress), colour: context.palette.pending),
        ] else if (result?.outcome == UpdateOutcome.needsPermission) ...[
          _Message(
            context.l10n.updateNeedsPermission,
            colour: context.palette.warning,
          ),
          const SizedBox(height: AppSpacing.xs),
          _button(
            context.l10n.updateOpenSettings,
            widget.openInstallSettings ?? ApkInstaller.openInstallSettings,
            icon: Icons.settings_outlined,
          ),
          TextButton(onPressed: _start, child: Text(context.l10n.commonRetry)),
        ] else ...[
          _button(widget.label ?? context.l10n.updateDownloadNew, _start, icon: Icons.download_outlined),
          if (result != null) ...[
            const SizedBox(height: AppSpacing.xs),
            _Message(_failureText(result), colour: context.palette.danger),
          ],
        ],
      ],
    );
  }

  Widget _button(String label, VoidCallback onPressed,
      {required IconData icon}) {
    if (widget.dense) {
      return Align(
        alignment: Alignment.centerLeft,
        child: TextButton.icon(
          onPressed: onPressed,
          icon: Icon(icon, size: 18),
          label: Text(label),
        ),
      );
    }
    return FilledButton.icon(
      onPressed: onPressed,
      icon: Icon(icon),
      label: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Text(label),
      ),
    );
  }

  static String _stepText(UpdateStep step, double? progress) => switch (step) {
        UpdateStep.checking => AppLocale.l10n.updateChecking,
        UpdateStep.downloading => progress == null
            ? AppLocale.l10n.updateDownloading
            : AppLocale.l10n.updateDownloadingPercent('${(progress * 100).floor()}'),
        UpdateStep.verifying => AppLocale.l10n.updateVerifying,
      };

  static String _failureText(UpdateResult result) => switch (result.outcome) {
        UpdateOutcome.notEnoughSpace => AppLocale.l10n.updateNotEnoughSpace('${result.megabytesNeeded}'),
        UpdateOutcome.incomplete => AppLocale.l10n.updateIncomplete,
        UpdateOutcome.mismatch => AppLocale.l10n.updateMismatch,
        // ⛔ ফাইলটা অফিসের দেওয়া ঠিকই, কিন্তু অন্য অ্যাপ বা অন্য চাবিতে সই — ফোন এমনিও নিত না
        UpdateOutcome.foreignPackage => AppLocale.l10n.updateForeignPackage,
        // ⛔ পুরনো সংস্করণে ফেরা নয়
        UpdateOutcome.notNewer => AppLocale.l10n.updateNotNewer,
        UpdateOutcome.offline => AppLocale.l10n.updateDownloadOffline,
        UpdateOutcome.notInstallable => AppLocale.l10n.updateNotInstallable,
        // ⓘ ফাইল ঠিক আছে, কিন্তু ফোন বসানোর পর্দা খুলতে দিল না — কারণটা ছোট করে, অফিসের জন্য
        UpdateOutcome.installerRefused => AppLocale.l10n.updateInstallerRefused(result.detail ?? '—'),
        _ => AppLocale.l10n.updateFailed,
      };
}

class _Message extends StatelessWidget {
  const _Message(this.text, {required this.colour});

  final String text;
  final Color colour;

  @override
  Widget build(BuildContext context) {
    return Text(text, style: TextStyle(fontSize: 12.5, color: colour));
  }
}
