import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config/app_channel.dart';
import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/update/app_version_check.dart';
import 'update_download_button.dart';

/// ⭐ নতুন সংস্করণের বোতাম — বিল্ড দেখে (২০ নভেম্বর ২০২৬)।
///
/// side বিল্ডে আগের মতোই [UpdateDownloadButton] (নামানো, sha মেলানো, বসানো)। play বিল্ডে [StoreUpdateButton] —
/// ⛔ Google Play অ্যাপকে নিজের APK নামাতে বা বসাতে দেয় না, তাই সেখানে কেবল Play Store-এর পাতা খোলে।
/// হোমের নোটিশ, দেয়াল আর "আরও" পাতার সারি — তিনটাই এটা দিয়ে আঁকে, তাই play বিল্ডে নামানোর পথ কোথাও নেই।
Widget updateButtonFor(AppRelease release, {String? label, bool dense = false}) {
  // ⓘ play-এ বোতামের নাম সবসময় "Play Store-এ আপডেট" — "এখনই আপডেট" বললে মানুষ ভাবতেন অ্যাপ নিজেই বসাবে
  if (AppChannel.isPlay) return StoreUpdateButton(dense: dense);
  return UpdateDownloadButton(release: release, label: label, dense: dense);
}

/// play বিল্ডের আপডেট — Play Store-এ এই অ্যাপের পাতা খোলে; বসানো Play নিজে করে।
class StoreUpdateButton extends StatefulWidget {
  const StoreUpdateButton({super.key, this.label, this.dense = false, this.open});

  final String? label;
  final bool dense;

  /// পরীক্ষার পথ — আসলটা url_launcher দিয়ে বাইরের অ্যাপে (Play Store) খোলে
  final Future<bool> Function(Uri url)? open;

  @override
  State<StoreUpdateButton> createState() => _StoreUpdateButtonState();
}

class _StoreUpdateButtonState extends State<StoreUpdateButton> {
  bool _failed = false;

  Future<void> _open() async {
    final url = Uri.parse(AppChannel.playStoreUrl);
    bool opened;
    try {
      opened = await (widget.open ?? (u) => launchUrl(u, mode: LaunchMode.externalApplication))(url);
    } catch (_) {
      opened = false;
    }
    if (mounted) setState(() => _failed = !opened);
  }

  @override
  Widget build(BuildContext context) {
    final label = widget.label ?? context.l10n.updateOpenStore;
    final button = widget.dense
        ? Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              key: const ValueKey('update-open-store'),
              onPressed: _open,
              icon: const Icon(Icons.shop_outlined, size: 18),
              label: Text(label),
            ),
          )
        : FilledButton.icon(
            key: const ValueKey('update-open-store'),
            onPressed: _open,
            icon: const Icon(Icons.shop_outlined),
            label: Padding(padding: const EdgeInsets.symmetric(vertical: 10), child: Text(label)),
          );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        button,
        if (_failed)
          Text(context.l10n.updateStoreFailed,
              key: const ValueKey('update-store-failed'),
              style: TextStyle(fontSize: 12.5, color: context.palette.danger)),
      ],
    );
  }
}
