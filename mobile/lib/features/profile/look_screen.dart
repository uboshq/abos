import 'package:flutter/material.dart';

import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/theme/app_look.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/theme/glass.dart';
import '../../core/theme/glass_wallpaper.dart';

/// ⭐ প্রোফাইল → "চেহারা" (0.4.58; মালিক, ২০ নভেম্বর ২০২৬ "ok", নমুনা দুটো): তিন রূপ — ABOS (এখনকার, একটুও বদলায় না) ·
/// Liquid Glass · Material 3; Liquid Glass-এ "কাচের ঝাপসা" দাগ 0–100 আর পাঁচ ধাপ (স্বচ্ছ · হালকা · মাঝারি · ঘন · ঘষা কাচ),
/// আর "পেছনের রং নড়বে"। সব বাছাই এই ফোনে থাকে ([AppLooks]); চাপ বা দাগ টানলেই পুরো অ্যাপ বদলায়।
class LookScreen extends StatelessWidget {
  const LookScreen({super.key, this.looks});

  /// পরীক্ষার পথ — না দিলে অ্যাপের নিজের ([AppLooks.instance])
  final AppLooks? looks;

  static Future<void> open(BuildContext context) =>
      Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const LookScreen()));

  @override
  Widget build(BuildContext context) {
    final looks = this.looks ?? AppLooks.instance;
    final l = context.l10n;
    return ListenableBuilder(
      listenable: Listenable.merge([looks.look, looks.blur, looks.motion, looks.wallpaper]),
      builder: (context, _) {
        final look = looks.look.value;
        final level = looks.blur.value;
        final muted = context.palette.onSurfaceMuted;
        return Scaffold(
          key: const ValueKey('look-screen'),
          appBar: AbosScreenBar(title: l.lookTitle),
          body: ListView(
            padding: const EdgeInsets.all(AppSpacing.md),
            children: [
              Text(l.lookChoose, style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: AppSpacing.sm),
              for (final (value, name, hint) in [
                // ⭐ ক্রম: ম্যাটেরিয়াল · লিকুইড গ্লাস · ক্লাসিক (মালিক, ২০ নভেম্বর ২০২৬ "ok"; "ABOS" আর রূপের নাম নয়)
                (AppLook.expressive, l.lookExpressive, l.lookExpressiveHint),
                (AppLook.glass, l.lookGlass, l.lookGlassHint),
                (AppLook.abos, l.lookAbos, l.lookAbosHint),
              ])
                Card(
                  key: ValueKey('look-${value.name}'),
                  child: ListTile(
                    minTileHeight: 64,
                    leading: Icon(value == look ? Icons.radio_button_checked : Icons.radio_button_unchecked),
                    title: Text(name, style: const TextStyle(fontWeight: FontWeight.w600)),
                    subtitle: Text(hint),
                    selected: value == look,
                    onTap: () => looks.chooseLook(value),
                  ),
                ),
              // ⓘ দাগটা কেবল Liquid Glass-এ — ABOS আর Material 3-এ এর কাজ নেই (নমুনার নিয়ম)
              if (look == AppLook.glass) ...[
                const SizedBox(height: AppSpacing.md),
                // ⭐ পটভূমি — নয়টা (প্রথমে বেগুনি ঢেউ), সবই ABOS-এর নিজের আঁকা (মালিক, ২০ নভেম্বর ২০২৬); কাচের ঝাপসার দাগের ঠিক ওপরে
                Text(l.lookWallpaper, style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: AppSpacing.sm),
                Wrap(spacing: 10, runSpacing: 10, children: [
                  for (final paper in GlassWallpaper.values)
                    _WallpaperSwatch(
                      paper: paper,
                      name: wallpaperName(l, paper),
                      selected: looks.wallpaper.value == paper,
                      onTap: () => looks.chooseWallpaper(paper),
                    ),
                ]),
                const SizedBox(height: AppSpacing.md),
                Row(children: [
                  Expanded(child: Text(l.lookBlur, style: const TextStyle(fontWeight: FontWeight.w700))),
                  Text('$level', key: const ValueKey('look-blur-value'), style: const TextStyle(fontWeight: FontWeight.w800)),
                ]),
                Slider(
                  key: const ValueKey('look-blur'),
                  value: level.toDouble(),
                  min: 0,
                  max: 100,
                  divisions: 100,
                  label: '$level',
                  onChanged: (v) => looks.chooseBlur(v.round()),
                ),
                Row(children: [
                  Expanded(child: Text(l.lookBlurClearEnd, style: TextStyle(fontSize: 12, color: muted))),
                  Text(l.lookBlurFrosted, style: TextStyle(fontSize: 12, color: muted)),
                ]),
                const SizedBox(height: AppSpacing.sm),
                Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
                  for (final (value, name) in [
                    (AppLooks.presets[0], l.lookBlurClear),
                    (AppLooks.presets[1], l.lookBlurLight),
                    (AppLooks.presets[2], l.lookBlurMedium),
                    (AppLooks.presets[3], l.lookBlurDense),
                    (AppLooks.presets[4], l.lookBlurFrosted),
                  ])
                    ChoiceChip(
                      key: ValueKey('look-preset-$value'),
                      label: Text(name),
                      selected: level == value,
                      onSelected: (_) => looks.chooseBlur(value),
                    ),
                ]),
                const SizedBox(height: AppSpacing.md),
                // ⭐ নমুনার "নিচের লেখা — স্বচ্ছ কাচে পড়া যায়": দাগ টানলে এই কাচও সাথে সাথে বদলায়
                GlassPanel(
                  key: const ValueKey('look-glass-preview'),
                  padding: const EdgeInsets.all(16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(l.lookTodaySales),
                    const Text('৳ 2,84,500', style: TextStyle(fontSize: 28, fontWeight: FontWeight.w700)), // l10n-ok: a sample figure on the glass preview
                    Text(l.lookBlurHint, style: const TextStyle(fontSize: 12.5)),
                  ]),
                ),
                const SizedBox(height: AppSpacing.sm),
                SwitchListTile(
                  key: const ValueKey('look-motion'),
                  contentPadding: EdgeInsets.zero,
                  title: Text(l.lookMotion),
                  subtitle: Text(l.lookMotionHint),
                  value: looks.motion.value,
                  onChanged: looks.chooseMotion,
                ),
              ],
            ],
          ),
        );
      },
    );
  }
}

/// পটভূমির নাম — বাংলা আর ইংরেজি
String wallpaperName(AppL10n l, GlassWallpaper paper) => switch (paper) {
      GlassWallpaper.purpleWave => l.lookPaperPurpleWave,
      GlassWallpaper.balls => l.lookPaperBalls,
      GlassWallpaper.purple => l.lookPaperPurple,
      GlassWallpaper.blueWave => l.lookPaperBlueWave,
      GlassWallpaper.sunset => l.lookPaperSunset,
      GlassWallpaper.aurora => l.lookPaperAurora,
      GlassWallpaper.ocean => l.lookPaperOcean,
      GlassWallpaper.graphite => l.lookPaperGraphite,
      GlassWallpaper.silver => l.lookPaperSilver,
    };

/// একটা পটভূমির ছোট ছবি — বাছা থাকলে নীল দাগ আর টিক; নাম নিচে
class _WallpaperSwatch extends StatelessWidget {
  const _WallpaperSwatch({required this.paper, required this.name, required this.selected, required this.onTap});

  final GlassWallpaper paper;
  final String name;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final blue = Theme.of(context).colorScheme.primary;
    return Semantics(
      button: true,
      selected: selected,
      label: name,
      excludeSemantics: true,
      child: InkWell(
        key: ValueKey('look-wallpaper-${paper.name}'),
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: SizedBox(
          width: 64,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 56,
              height: 84,
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: selected ? blue : context.palette.border, width: selected ? 2.5 : 1),
              ),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: Stack(fit: StackFit.expand, children: [
                  CustomPaint(painter: paper.painter),
                  if (selected)
                    const Center(child: Icon(Icons.check_circle, color: Colors.white, size: 22)), // dark-ok: রঙিন ছবির ওপর
                ]),
              ),
            ),
            const SizedBox(height: 4),
            Text(name, textAlign: TextAlign.center, maxLines: 2, style: const TextStyle(fontSize: 11.5, height: 1.2)),
          ]),
        ),
      ),
    );
  }
}
