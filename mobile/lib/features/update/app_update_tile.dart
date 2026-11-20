import 'package:flutter/material.dart';

import '../../core/config/app_config.dart';
import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/update/app_version_check.dart';
import 'store_update_button.dart';

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
              title: Text(context.l10n.updateTileTitle),
              subtitle: Text(context.l10n.updateRunningVersion(widget.currentName)),
              trailing: _state == _Asked.asking
                  ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.refresh),
              onTap: _ask,
            ),
            if (_state == _Asked.latest)
              _Line(context.l10n.updateLatest, context.palette.success, key: const ValueKey('app-update-latest')),
            if (_state == _Asked.offline)
              _Line(context.l10n.updateOffline, context.palette.danger,
                  key: const ValueKey('app-update-offline')),
            if (_state == _Asked.newer && release != null) ...[
              _Line(release.versionName == null ? context.l10n.updateNewVersion : context.l10n.updateNewVersionNamed(release.versionName!),
                  context.palette.pending,
                  key: const ValueKey('app-update-newer')),
              for (final line in release.whatsNew) _Line('• $line', context.palette.pending),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: AppSpacing.md, vertical: AppSpacing.xs),
                child: updateButtonFor(release, label: context.l10n.updateNow),
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
