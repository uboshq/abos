import 'package:flutter/material.dart';

import '../theme/app_spacing.dart';

/// ⭐ তিন অবস্থার দুটো — লোড হচ্ছে আর ভুল; খালি অবস্থা [EmptyState] (মোবাইলের নকশা ৩, পাতা ৮ আর ১০; মালিকের "হ্যাঁ",
/// ১৯ অক্টোবর ২০২৬)। রং কেবল `colorScheme` থেকে — আলো আর অন্ধকার দুই মোডেই একই widget।

/// লোড হচ্ছে — তালিকার আকারের ধূসর খসড়া, ঘোরানো চাকা নয় (Material 3: Progress indicators — "skeleton" দেখায় কী আসছে)।
///
/// <p>⛔ নড়ে না: চলতে থাকা অ্যানিমেশন টেস্টের `pumpAndSettle`-কে কখনো থামতে দেয় না।
class LoadingSkeleton extends StatelessWidget {
  const LoadingSkeleton({super.key, this.rows = 6});

  final int rows;

  @override
  Widget build(BuildContext context) {
    final bar = Theme.of(context).colorScheme.surfaceContainerHighest;
    Widget block(double w, double h) => Container(
          width: w,
          height: h,
          decoration: BoxDecoration(color: bar, borderRadius: BorderRadius.circular(6)),
        );
    return Semantics(
      key: const ValueKey('loading-skeleton'),
      label: 'লোড হচ্ছে',
      liveRegion: true,
      child: ExcludeSemantics(
        child: Column(children: [
          for (var i = 0; i < rows; i++)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: SizedBox(
                height: 56,
                child: Row(children: [
                  Container(width: 40, height: 40, decoration: BoxDecoration(color: bar, shape: BoxShape.circle)),
                  const SizedBox(width: AppSpacing.md),
                  Expanded(
                    child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                      block(140, 14),
                      const SizedBox(height: AppSpacing.sm),
                      block(90, 10),
                    ]),
                  ),
                  block(72, 14),
                ]),
              ),
            ),
        ]),
      ),
    );
  }
}

/// ভুল — বাংলায় কী হলো আর "আবার চেষ্টা করুন" (৪৮dp)। [onRetry] না দিলে বোতাম নেই। [title] দিলে উপরে মোটা শিরোনাম
/// ("তথ্য আনা গেল না"), [message] নিচে বিস্তারিত (নকশা ৩, পাতা ৮; a5-এর অনুরোধ)।
class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.message, this.title, this.onRetry});

  final String message;
  final String? title;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Semantics(
      liveRegion: true,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpacing.lg),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Icon(Icons.cloud_off_outlined, size: 48, color: cs.error),
          const SizedBox(height: AppSpacing.md),
          if (title != null) ...[
            Text(title!, textAlign: TextAlign.center, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: cs.onSurface)),
            const SizedBox(height: AppSpacing.xs),
          ],
          Text(message,
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: title == null ? 16 : 14, color: title == null ? cs.onSurface : cs.onSurfaceVariant)),
          if (onRetry != null) ...[
            const SizedBox(height: AppSpacing.md),
            FilledButton.tonalIcon(
              key: const ValueKey('error-retry'),
              style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
              onPressed: onRetry,
              icon: const Icon(Icons.refresh),
              label: const Text('আবার চেষ্টা করুন'),
            ),
          ],
        ]),
      ),
    );
  }
}
