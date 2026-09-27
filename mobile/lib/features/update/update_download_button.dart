import 'package:flutter/material.dart';

import '../../core/theme/app_colors.dart';
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
    this.label = 'নতুন সংস্করণ নামান',
    this.dense = false,
    this.actions = const UpdateActions.real(),
    this.openInstallSettings,
  });

  final AppRelease release;
  final String label;

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
      return const _Message(
        'এই আপডেট অ্যাপের ভেতর থেকে বসানো যাচ্ছে না। অফিসে জানান।',
        colour: AppColors.warning,
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
          _Message(_stepText(_step!, _progress), colour: AppColors.pending),
        ] else if (result?.outcome == UpdateOutcome.needsPermission) ...[
          const _Message(
            'এই অ্যাপ থেকে আপডেট বসানোর অনুমতি দেওয়া নেই। সেটিংসে গিয়ে '
            '"এই উৎস থেকে অনুমতি দিন" চালু করুন, তারপর ফিরে এসে আবার চাপুন।',
            colour: AppColors.warning,
          ),
          const SizedBox(height: AppSpacing.xs),
          _button(
            'সেটিংস খুলুন',
            widget.openInstallSettings ?? ApkInstaller.openInstallSettings,
            icon: Icons.settings_outlined,
          ),
          TextButton(onPressed: _start, child: const Text('আবার চেষ্টা করুন')),
        ] else ...[
          _button(widget.label, _start, icon: Icons.download_outlined),
          if (result != null) ...[
            const SizedBox(height: AppSpacing.xs),
            _Message(_failureText(result), colour: AppColors.danger),
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
        UpdateStep.checking => 'দেখা হচ্ছে…',
        UpdateStep.downloading => progress == null
            ? 'নামানো হচ্ছে…'
            : 'নামানো হচ্ছে… ${(progress * 100).floor()}%',
        UpdateStep.verifying => 'ফাইলটা মিলিয়ে দেখা হচ্ছে…',
      };

  static String _failureText(UpdateResult result) => switch (result.outcome) {
        UpdateOutcome.notEnoughSpace =>
          'ফোনে যথেষ্ট জায়গা নেই। অন্তত ${result.megabytesNeeded}MB ফাঁকা করে '
              'আবার চেষ্টা করুন।',
        UpdateOutcome.incomplete =>
          'নামানো ফাইলটা অসম্পূর্ণ, তাই বসানো হয়নি। আবার চেষ্টা করুন।',
        UpdateOutcome.mismatch =>
          'নামানো ফাইলটা অফিসের দেওয়া ফাইলের সাথে মেলেনি, তাই বসানো হয়নি। '
              'ফাইলটা মুছে ফেলা হয়েছে। আবার চেষ্টা করুন; আবারও না মিললে অফিসে '
              'জানান।',
        UpdateOutcome.offline =>
          'নামানো গেল না। ইন্টারনেট সংযোগ দেখে আবার চেষ্টা করুন।',
        UpdateOutcome.notInstallable =>
          'এই আপডেট অ্যাপের ভেতর থেকে বসানো যাচ্ছে না। অফিসে জানান।',
        _ => 'আপডেট বসানো গেল না। আবার চেষ্টা করুন।',
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
