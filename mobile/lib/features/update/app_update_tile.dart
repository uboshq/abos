import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/update/app_version_check.dart';
import 'update_download_button.dart';

/// ⭐ "আরও" পাতার "অ্যাপ হালনাগাদ" — মালিক, ৭ অক্টোবর ২০২৬: "অ্যাপে আপডেট বোতাম দেওয়ার কথা ছিল"।
///
/// <p>চলতি সংস্করণ দেখায়; চাপলে এখনই `/app/version` জিজ্ঞেস করে। নতুন থাকলে হোমের একই পথ
/// ([[UpdateDownloadButton]] — নামানো, sha মেলানো, বসানো); না থাকলে "সর্বশেষ সংস্করণ চলছে"। ⓘ হোমের
/// [[UpdateGate]] ব্যর্থ যাচাইয়ে চুপ থাকে (নেট গেলে দেয়াল ওঠা চলবে না); এখানে মানুষ নিজে চেয়েছেন, তাই নেট না থাকলে
/// সেটা পরিষ্কার বলা হয়।
class AppUpdateTile extends StatefulWidget {
  const AppUpdateTile({
    super.key,
    this.fetch,
    this.current = AppConfig.appVersionCode,
    this.currentName = AppConfig.appVersion,
  });

  /// পরীক্ষার পথ — আসলটা সার্ভার চায়
  final Future<AppRelease> Function()? fetch;
  final int current;
  final String currentName;

  @override
  State<AppUpdateTile> createState() => _AppUpdateTileState();
}

enum _Asked { never, asking, latest, newer, offline }

class _AppUpdateTileState extends State<AppUpdateTile> {
  _Asked _state = _Asked.never;
  AppRelease? _release;

  Future<void> _ask() async {
    if (_state == _Asked.asking) return;
    setState(() => _state = _Asked.asking);
    try {
      final release = await (widget.fetch ?? AppVersionApi.latest)();
      final verdict = UpdateStatus.of(release, current: widget.current).verdict;
      if (!mounted) return;
      setState(() {
        _release = release;
        _state = verdict == UpdateVerdict.fine ? _Asked.latest : _Asked.newer;
      });
    } catch (_) {
      if (mounted) setState(() => _state = _Asked.offline);
    }
  }

  @override
  Widget build(BuildContext context) {
    final release = _release;
    return Card(
      child: Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ListTile(
              key: const ValueKey('app-update-check'),
              leading: const Icon(Icons.system_update_outlined),
              title: const Text('অ্যাপ হালনাগাদ'),
              subtitle: Text('চলছে ${widget.currentName}'),
              trailing: _state == _Asked.asking
                  ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.refresh),
              onTap: _ask,
            ),
            if (_state == _Asked.latest)
              const _Line('সর্বশেষ সংস্করণ চলছে।', AppColors.success, key: ValueKey('app-update-latest')),
            if (_state == _Asked.offline)
              const _Line('সার্ভারে পৌঁছানো গেল না। ইন্টারনেট সংযোগ দেখে আবার চাপুন।', AppColors.danger,
                  key: ValueKey('app-update-offline')),
            if (_state == _Asked.newer && release != null) ...[
              _Line('নতুন সংস্করণ এসেছে${release.versionName == null ? '' : ' (${release.versionName})'}।',
                  AppColors.pending,
                  key: const ValueKey('app-update-newer')),
              for (final line in release.whatsNew) _Line('• $line', AppColors.pending),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: AppSpacing.xs),
                child: UpdateDownloadButton(release: release, label: 'এখনই আপডেট করুন'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line(this.text, this.colour, {super.key});

  final String text;
  final Color colour;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.xs),
        child: Text(text, style: TextStyle(fontSize: 13, color: colour)),
      );
}
