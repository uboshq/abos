import 'package:flutter/material.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/locale/l10n.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ টাকা নেওয়া-দেওয়ার পর্দার একই টুকরো — আদায় ([CollectScreen]), পরিশোধ আর খরচের ভাউচার ([MoneyVoucherScreen]) একই চিপ, একই
/// তিন অঙ্ক, একই নিচের সারি আর একই ঘর পরে (মালিক, ২১ অক্টোবর ২০২৬: "ওয়েব আর অ্যাপ দুটোতেই এই গড়ন"; a4, ২৪ অক্টোবর ২০২৬:
/// "receipt, payment, expense — একই চেহারা")। ⓘ আগে এগুলো আদায়ের পর্দার নিজের ছিল; নকল নয়, এক জায়গায় তুলে আনা।

/// ⭐ বাছা চিপের রং — বাছা আর না-বাছা স্পষ্ট আলাদা (fe, ২২ অক্টোবর ২০২৬; WCAG 2.2: সীমানা ৩:১, লেখা ৪.৫:১): বাছা চিপ থিমের
/// primary-তে ভরা, লেখা onPrimary, চারপাশে ২dp primary; না-বাছা চিপ কার্ডের রঙের উপর। অ্যাপের নীলচে-সবুজ (teal) থাকে।
class CollectChipColors {
  const CollectChipColors({required this.selectedFill, required this.selectedBorder, required this.selectedLabel, required this.unselectedSurface});

  final Color selectedFill;
  final Color selectedBorder;
  final Color selectedLabel;

  /// না-বাছা চিপ যার উপর বসে — কার্ডের রং
  final Color unselectedSurface;

  static CollectChipColors of(ThemeData theme) => CollectChipColors(
        selectedFill: theme.colorScheme.primary,
        selectedBorder: theme.colorScheme.primary,
        selectedLabel: theme.colorScheme.onPrimary,
        unselectedSurface: theme.cardTheme.color ?? theme.colorScheme.surfaceContainerLow,
      );
}

/// বেছে নেওয়ার চিপ — পক্ষের ধরন, পদ্ধতি, ছাড়/রাউন্ডিং; বাছা অবস্থা [CollectChipColors]-এ
class MoneySegment extends StatelessWidget {
  const MoneySegment({super.key, required this.label, required this.selected, required this.onSelected});

  final Widget label;
  final bool selected;
  final ValueChanged<bool>? onSelected;

  @override
  Widget build(BuildContext context) {
    final c = CollectChipColors.of(Theme.of(context));
    return ChoiceChip(
      label: label,
      selected: selected,
      onSelected: onSelected,
      selectedColor: c.selectedFill,
      checkmarkColor: c.selectedLabel,
      labelStyle: selected ? TextStyle(color: c.selectedLabel, fontWeight: FontWeight.w600) : null,
      side: selected ? BorderSide(color: c.selectedBorder, width: 2) : null,
    );
  }
}

/// নকশার হালকা-ভরা চিপ — "পুরো বকেয়া", "নির্বাচিত বিল", রসিদের চার পথ
class MoneyTonalChip extends StatelessWidget {
  const MoneyTonalChip({super.key, required this.label, required this.onPressed});

  final String label;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) => ActionChip(
        label: Text(label),
        onPressed: onPressed,
        side: BorderSide.none,
        backgroundColor: context.palette.primarySoft,
        labelStyle: TextStyle(color: Theme.of(context).colorScheme.primary),
      );
}

/// ⭐ নিচের স্থির বোতাম-সারি — Scaffold-এর `bottomNavigationBar`, তাই পাতার শেষ ঘরও এর উপরে থামে, কিছু ঢাকে না
class MoneyBottomBar extends StatelessWidget {
  const MoneyBottomBar({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Material(
        elevation: 3,
        color: Theme.of(context).colorScheme.surface,
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.sm),
            child: Row(children: children),
          ),
        ),
      );
}

class MoneyLabel extends StatelessWidget {
  const MoneyLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: Text(text, style: TextStyle(color: context.palette.onSurfaceMuted, fontSize: 12.5)),
      );
}

/// ⭐ তিন অঙ্ক — বকেয়া/পাওনা (লাল) · এই আদায়/পরিশোধ (সবুজ) · বাকি থাকবে, এক সারিতে (ঋণাত্মক হলে [creditLabel]); জায়গা না
/// থাকলে একটার নিচে একটা — অঙ্ক কখনো কাটা নয়
class MoneyFigures extends StatelessWidget {
  const MoneyFigures({
    super.key,
    required this.due,
    required this.received,
    required this.settled,
    required this.dueLabel,
    required this.thisLabel,
    required this.leftLabel,
    required this.creditLabel,
    this.keyPrefix = 'collect',
  });

  /// পয়সায়; না জানা গেলে null
  final int? due;
  final int received;

  /// ছাড় আর উৎসে কাটা — বকেয়া থেকে এরাও কমে
  final int settled;
  final String dueLabel;
  final String thisLabel;
  final String leftLabel;
  final String creditLabel;

  /// চাবির আগের অংশ — `<prefix>-figure-due` …
  final String keyPrefix;

  @override
  Widget build(BuildContext context) {
    final left = due == null ? null : due! - received - settled;
    final figures = [
      MoneyFigure(key: ValueKey('$keyPrefix-figure-due'), label: dueLabel, value: due, colour: context.palette.danger),
      MoneyFigure(key: ValueKey('$keyPrefix-figure-this'), label: thisLabel, value: received, colour: context.palette.success, align: 1),
      MoneyFigure(
        key: ValueKey('$keyPrefix-figure-left'),
        label: left != null && left < 0 ? creditLabel : leftLabel,
        value: left?.abs(),
        colour: Theme.of(context).colorScheme.onSurface,
        align: 2,
      ),
    ];
    return LayoutBuilder(builder: (context, c) {
      final scaler = MediaQuery.textScalerOf(context);
      final scale = scaler.scale(1);
      // ⛔ এক সারিতে কেবল যখন প্রতিটা অঙ্ক তার তৃতীয়াংশে পুরো ধরে — "৳1,20,000.00" ৩৬০dp-তে 104dp-এর ঘরে দুই লাইনে ভাঙত
      // (a4, ২৪ অক্টোবর ২০২৬; 0.4.48-এর নিয়ম: অঙ্ক কখনো ভাঙা নয়)
      final room = (c.maxWidth - 2 * AppSpacing.sm) / 3 - 2 * AppSpacing.xs;
      final fits = figures.every((f) {
        final painter = TextPainter(
          text: TextSpan(text: f.shown, style: DefaultTextStyle.of(context).style.merge(MoneyFigure.valueStyle)),
          textDirection: Directionality.of(context),
          textScaler: scaler,
          maxLines: 1,
        )..layout();
        final w = painter.width;
        painter.dispose();
        return w <= room;
      });
      final wide = c.maxWidth / 3 >= 104 * scale && fits;
      return Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpacing.sm, vertical: AppSpacing.xs),
          child: wide
              ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [for (final f in figures) Expanded(child: f)])
              : Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: figures),
        ),
      );
    });
  }
}

class MoneyFigure extends StatelessWidget {
  const MoneyFigure({super.key, required this.label, required this.value, required this.colour, this.align = 0});

  final String label;
  final int? value;
  final Color colour;

  /// 0 বাঁয়ে, 1 মাঝে, 2 ডানে — নকশার এক সারি
  final int align;

  /// অঙ্কের লেখা আর তার রূপ — [MoneyFigures] এক সারিতে ধরে কি না মাপে
  String get shown => value == null ? '—' : Money.taka(value! / 100);
  static const valueStyle = TextStyle(fontSize: 16, fontWeight: FontWeight.w700);

  @override
  Widget build(BuildContext context) {
    final text = shown;
    final cross = switch (align) { 1 => CrossAxisAlignment.center, 2 => CrossAxisAlignment.end, _ => CrossAxisAlignment.start };
    return Semantics(
      container: true,
      label: '$label, ${value == null ? '—' : Money.speakable(text)}',
      excludeSemantics: true,
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.xs),
        child: Column(crossAxisAlignment: cross, children: [
          Text(label, style: TextStyle(fontSize: 12.5, color: context.palette.onSurfaceMuted)),
          Text(text, style: valueStyle.copyWith(color: colour)),
        ]),
      ),
    );
  }
}

class MoneySection extends StatelessWidget {
  const MoneySection({super.key, required this.title, required this.children, this.trailing});

  final String? title;
  final Widget? trailing;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(top: AppSpacing.sm),
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.md),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (title != null)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.xs),
                // ⓘ Wrap — বড় লেখায় পাশের বোতাম নিচের লাইনে নামে, ডানে উপচে পড়ে না
                child: Wrap(
                  alignment: WrapAlignment.spaceBetween,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Semantics(
                      header: true,
                      child: Text(title!, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
                    ),
                    if (trailing != null) trailing!,
                  ],
                ),
              ),
            ...children,
          ]),
        ),
      );
}

/// চাপলে বাছাই খোলে — লেবেলসহ ঘর (গ্রাহক, তারিখ)
///
/// <p>⛔ ফাঁকা ঘরে কেবল লেবেল (Material 3-এর ঘর): লেবেল তখন ঘরের ভেতরে বসে, তাই সেখানে আর কোনো লেখা আঁকা নয় — 0.4.45-এ
/// "বাছুন" লেখাটা লেবেলের উপরেই আঁকা হত, মালিকের ফোনে "Customer *" আর "Choose a customer" একটার উপর আরেকটা (fe, ২২ অক্টোবর
/// ২০২৬)। বাছার পরে লেবেল উপরে ওঠে, নাম নিচে। [placeholder] পর্দা-পাঠকের ইঙ্গিত হয়ে থাকে, আঁকা হয় না।
class MoneyTappable extends StatelessWidget {
  const MoneyTappable({super.key, required this.label, this.value, this.placeholder, required this.icon, this.onTap});

  final String label;
  final Widget? value;
  final String? placeholder;
  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        hint: value == null ? placeholder : null,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(4),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 56),
            child: InputDecorator(
              decoration: InputDecoration(labelText: label, suffixIcon: Icon(icon), enabled: onTap != null),
              isEmpty: value == null,
              child: value ?? const SizedBox.shrink(),
            ),
          ),
        ),
      );
}

class MoneyAmountRow extends StatelessWidget {
  const MoneyAmountRow({super.key, required this.label, required this.paisa, this.strong = false});

  final String label;
  final int paisa;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    final style = TextStyle(fontWeight: strong ? FontWeight.w700 : FontWeight.w400);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Wrap(alignment: WrapAlignment.spaceBetween, spacing: AppSpacing.sm, children: [
        Text(label, style: style),
        Text(Money.taka(paisa / 100), style: style),
      ]),
    );
  }
}

enum MoneyTone { info, warn, stop }

class MoneyNote extends StatelessWidget {
  const MoneyNote({super.key, required this.text, required this.tone});

  final String text;
  final MoneyTone tone;

  @override
  Widget build(BuildContext context) {
    final p = context.palette;
    final (fg, bg, icon) = switch (tone) {
      MoneyTone.info => (p.pending, p.pendingSurface, Symbols.info),
      MoneyTone.warn => (p.warning, p.warningSurface, Symbols.warning),
      MoneyTone.stop => (p.danger, p.dangerSurface, Symbols.block),
    };
    return Container(
      margin: const EdgeInsets.only(top: AppSpacing.xs),
      padding: const EdgeInsets.all(AppSpacing.sm),
      decoration: BoxDecoration(
          color: bg, borderRadius: BorderRadius.circular(6), border: BorderDirectional(start: BorderSide(color: fg, width: 3))),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        ExcludeSemantics(child: Icon(icon, size: 18, color: fg)),
        const SizedBox(width: AppSpacing.sm),
        Expanded(child: Text(text, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: p.onSurface))),
      ]),
    );
  }
}

class MoneyErrorCard extends StatelessWidget {
  const MoneyErrorCard(this.text, {super.key, this.textKey = const ValueKey('collect-error')});

  final String text;

  /// লেখার চাবি — আদায়ে `collect-error`
  final Key textKey;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(top: AppSpacing.sm),
        color: context.palette.dangerSurface,
        child: Semantics(
          liveRegion: true,
          child: Padding(
            padding: const EdgeInsets.all(AppSpacing.md),
            child: Text(text, key: textKey, style: TextStyle(color: context.palette.danger)),
          ),
        ),
      );
}

/// ⛔ বোতামের ভেতরে উপর-নিচে ১২, পাশে ১২ — Material 3-এর বোতামে উপর-নিচে কোনো ফাঁক নেই, তাই ১৩০%/২০০% লেখায় লেবেল দুই লাইনে
/// ভাঙলে শেষ অক্ষর বোতামের নিচের কিনারা ছুঁত (collection_130/200, ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬)। উচ্চতা বাঁধা নয়: অন্তত ৪৮,
/// লেখার সাথে বাড়ে
const moneyButtonPadding = EdgeInsets.symmetric(horizontal: 12, vertical: 12);

/// ⛔ টাকার ঘরের নিচের লেখা ("চিহ্ন বাদ দেওয়া হয়েছে …", ব্যাংক চার্জের কথা) চার লাইন পর্যন্ত — দুই লাইনে ২০০% লেখায়
/// বিলের সারির সরু ঘরে (বাঁয়ে ৪৮dp সরানো) ইংরেজি লেখাটা "…"-এ কাটা পড়ত (collect_sign_item_412_light_en_200, ছবি-নিরীক্ষা,
/// ২৩ অক্টোবর ২০২৬)। সব টাকার ঘরে একই সীমা
const moneyNoteLines = 4;

/// ⭐ নিচের বোতামের লেখা — Material 3-এর labelLarge: 14, w600 (fe, ২২ অক্টোবর ২০২৬; থিমের মাপ নিচে নামলেও 14)
TextStyle moneyButtonText(BuildContext context) =>
    (Theme.of(context).textTheme.labelLarge ?? const TextStyle()).copyWith(fontSize: 14, fontWeight: FontWeight.w600);

String moneyDayText(DateTime d) => '${d.day.toString().padLeft(2, '0')}/${d.month.toString().padLeft(2, '0')}/${d.year}';

/// পদ্ধতির নাম — ওয়েবের `money.method.*`-এর একই পাঁচটা
String moneyMethodLabel(BuildContext context, String method) {
  final w = context.l10n;
  return switch (method) {
    'cash' => w.collectMethodCash,
    'mfs' => 'MFS',
    'transfer' => w.collectMethodTransfer,
    'deposit' => w.collectMethodDeposit,
    _ => w.collectMethodCheque,
  };
}
