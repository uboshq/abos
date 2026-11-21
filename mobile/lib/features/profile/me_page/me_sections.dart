part of '../me_page.dart';

/// একটা অংশ (নমুনার `.sec`): শিরোনাম, ডানে ছোট লেখা। ক্লাসিকে সাদা কার্ড, গ্লাসে কাচের প্যানেল, স্মার্টে হালকা বেগুনি বাক্স —
/// ⭐ স্মার্টে সারি আর সংখ্যার অংশ ([plain]) বাক্স ছাড়া: শিরোনাম বাইরে, প্রতিটা সারি নিজের গোল কার্ড
class _Sec extends StatelessWidget {
  const _Sec({super.key, required this.tok, required this.title, required this.child, this.trailing, this.icon, this.plain = false});

  final _Tok tok;
  final String title;
  final Widget child;
  final Widget? trailing;
  final IconData? icon;
  final bool plain;

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final flat = t.smart && plain;
    final head = Padding(
      padding: flat ? const EdgeInsets.fromLTRB(4, 20, 4, 8) : const EdgeInsets.only(bottom: 10),
      child: Row(children: [
        if (icon != null) ...[Icon(icon, size: 18, color: t.ink), const SizedBox(width: 6)],
        Expanded(
          child: Semantics(
            header: true,
            child: Text(title,
                style: TextStyle(
                  fontSize: flat ? 17 : (t.smart ? 16 : 15),
                  fontWeight: t.smart ? FontWeight.w800 : FontWeight.w700,
                  letterSpacing: t.smart ? -0.1 : null,
                  color: t.ink,
                )),
          ),
        ),
        if (trailing != null)
          DefaultTextStyle.merge(
            style: TextStyle(
              fontSize: 12,
              fontWeight: t.smart ? FontWeight.w600 : FontWeight.w500,
              color: t.smart ? Expressive.primary : t.mut,
            ),
            child: trailing!,
          ),
      ]),
    );
    final inner = Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [head, child]);
    if (flat) return Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 0), child: inner);
    if (t.smart) {
      return Container(
        margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(color: Expressive.surfaceBox, borderRadius: BorderRadius.circular(24)),
        child: inner,
      );
    }
    if (t.glass) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
        child: GlassPanel(radius: t.secRadius, padding: const EdgeInsets.all(14), child: inner),
      );
    }
    return Container(
      margin: const EdgeInsets.fromLTRB(12, 12, 12, 0),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: t.card,
        border: Border.all(color: t.cardB),
        borderRadius: BorderRadius.circular(t.secRadius),
      ),
      child: inner,
    );
  }
}

/// এক সারি — [label] থাকলে ছোট নাম উপরে, মান নিচে (পরিচয়); না থাকলে মান উপরে, [hint] নিচে (ফোন, নিরাপত্তা)
class _RowData {
  const _RowData({this.key, required this.icon, required this.value, this.label, this.hint, this.tag, this.end, this.onTap});

  final Key? key;
  final IconData icon;
  final String value;
  final String? label;
  final String? hint;

  /// মানের পাশে সবুজ ছোট চিহ্ন ("এই ফোন")
  final String? tag;
  final Widget? end;
  final VoidCallback? onTap;
}

/// নমুনার `.rows` — ক্লাসিক আর গ্লাসে মাঝে সরু রেখা; স্মার্টে প্রতিটা সারি আলাদা গোল কার্ড, মাঝে 4
class _Rows extends StatelessWidget {
  const _Rows({required this.tok, required this.rows});

  final _Tok tok;
  final List<_RowData> rows;

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final small = TextStyle(color: t.mut, fontSize: t.smart ? 12 : 11.5, height: 1.3);
    final main = TextStyle(fontSize: t.smart ? 14.5 : 13.5, fontWeight: t.smart ? FontWeight.w600 : FontWeight.w400, color: t.ink, height: 1.3);
    final children = <Widget>[];
    for (final (i, r) in rows.indexed) {
      final line = Row(children: [
        Icon(r.icon, size: t.smart ? 22 : 18, color: t.smart ? const Color(0xFF49454F) : t.mut),
        SizedBox(width: t.smart ? 14 : 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            if (r.label != null) Text(r.label!, style: small),
            if (r.tag == null)
              Text(r.value, style: main)
            else
              Wrap(crossAxisAlignment: WrapCrossAlignment.center, spacing: 4, children: [
                Text(r.value, style: main),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6),
                  decoration: BoxDecoration(color: const Color(0xFFDCFCE7), borderRadius: BorderRadius.circular(t.smart ? 8 : 999)),
                  child: Text(r.tag!,
                      key: const ValueKey('me-this-phone'),
                      style: const TextStyle(fontSize: 10, height: 1.6, fontWeight: FontWeight.w700, color: Color(0xFF166534))),
                ),
              ]),
            if (r.hint != null) Text(r.hint!, style: small),
          ]),
        ),
        if (r.end != null) ...[const SizedBox(width: 8), r.end!],
      ]);
      if (t.smart) {
        children.add(Padding(
          key: r.key,
          padding: EdgeInsets.only(top: i == 0 ? 0 : 4),
          child: Material(
            color: Expressive.surfaceBox,
            borderRadius: BorderRadius.circular(24),
            child: InkWell(
              onTap: r.onTap,
              borderRadius: BorderRadius.circular(24),
              child: Padding(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 13), child: line),
            ),
          ),
        ));
      } else {
        children.add(Container(
          key: r.key,
          decoration: i == 0 ? null : BoxDecoration(border: Border(top: BorderSide(color: t.cardB))),
          child: InkWell(
            onTap: r.onTap,
            child: Padding(padding: EdgeInsets.only(top: i == 0 ? 0 : 8, bottom: i == rows.length - 1 ? 0 : 8), child: line),
          ),
        ));
      }
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: children);
  }
}

/// "এই মাসে আমার কাজ" — দুই কলামে, বিজোড় শেষটা পুরো চওড়া (নমুনার `.stats`); স্মার্টে অ্যাপের চার আকারের ঘর
class _Stats extends StatelessWidget {
  const _Stats({required this.tok, required this.stats});

  final _Tok tok;
  final List<MeStat> stats;

  @override
  Widget build(BuildContext context) {
    final list = stats.take(3).toList();
    return LayoutBuilder(builder: (context, box) {
      final half = (box.maxWidth - 8) / 2;
      return Wrap(spacing: 8, runSpacing: 8, children: [
        for (final (i, s) in list.indexed)
          SizedBox(
            key: ValueKey('me-stat-${s.key}'),
            width: i == list.length - 1 && list.length.isOdd ? box.maxWidth : half,
            child: _stat(i, s),
          ),
      ]);
    });
  }

  Widget _stat(int i, MeStat s) {
    final t = tok;
    final tone = _statTone(s);
    if (t.smart) {
      final (bg, ink, shape) = Expressive.tiles[i % 4];
      final capsule = i % 4 == 2;
      return Container(
        constraints: const BoxConstraints(minHeight: 92),
        padding: EdgeInsets.symmetric(horizontal: capsule ? 24 : 16, vertical: 14),
        decoration: BoxDecoration(color: bg, borderRadius: shape),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 28,
            height: 28,
            decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.6), borderRadius: BorderRadius.circular(12)), // dark-ok: white on a coloured tile / switch thumb
            child: Center(child: _glyph(_statIcon(s), color: ink)),
          ),
          const SizedBox(height: 6),
          Text(s.label, style: TextStyle(fontSize: 12, color: ink, height: 1.3)),
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: AlignmentDirectional.centerStart,
            child: Text(s.value, style: Expressive.bigNumber.copyWith(fontSize: 19, letterSpacing: -0.4, color: ink)),
          ),
        ]),
      );
    }
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 9),
      decoration: BoxDecoration(color: t.soft, borderRadius: BorderRadius.circular(t.statRadius)),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Container(
          width: 28,
          height: 28,
          decoration: BoxDecoration(color: tone, borderRadius: BorderRadius.circular(9)),
          child: Center(child: _glyph(_statIcon(s))),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(s.label, style: TextStyle(fontSize: 11.5, height: 1.3, color: t.softInk.withValues(alpha: 0.8))),
            const SizedBox(height: 2),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: AlignmentDirectional.centerStart,
              child: Text(s.value, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: t.softInk)),
            ),
          ]),
        ),
      ]),
    );
  }
}

/// "আমার সাম্প্রতিক কাজ" — রঙিন গোল চিহ্ন, তাদের মাঝে খাড়া রেখা (নমুনার `.tl`)
class _Timeline extends StatelessWidget {
  const _Timeline({required this.tok, required this.items, required this.now, this.onOpen});

  final _Tok tok;
  final List<MeRecent> items;
  final DateTime now;
  final void Function(MeRecent item)? onOpen;

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final l = context.l10n;
    final list = items.take(4).toList();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final (i, r) in list.indexed)
        _item(context, l, t, r, last: i == list.length - 1),
    ]);
  }

  Widget _item(BuildContext context, AppL10n l, _Tok t, MeRecent r, {required bool last}) {
    final (glyph, colour) = _recentLook(r);
    final open = onOpen != null && r.url != null ? () => onOpen!(r) : null;
    final at = r.at;
    return InkWell(
      key: ValueKey('me-recent-${r.no}'),
      onTap: open,
      child: IntrinsicHeight(
        child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          SizedBox(
            width: 34,
            child: Column(children: [
              Container(
                width: 34,
                height: 34,
                decoration: BoxDecoration(color: colour, shape: BoxShape.circle),
                child: Center(child: _glyph(glyph, size: 17)),
              ),
              if (!last)
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(bottom: 2),
                    child: Container(width: 2, color: t.smart ? const Color(0xFFE7E0EC) : t.cardB),
                  ),
                ),
            ]),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: last ? 0 : 14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text.rich(
                  TextSpan(children: [
                    TextSpan(text: r.no, style: const TextStyle(fontWeight: FontWeight.w700)),
                    TextSpan(text: ' ${r.action}'),
                  ]),
                  style: TextStyle(color: t.ink, height: 1.35, fontSize: 13.5),
                ),
                if (r.summary.isNotEmpty) Text(r.summary, style: TextStyle(color: t.mut, fontSize: 12, height: 1.35)),
                if (at != null) Text(_ago(l, at, now), style: TextStyle(color: t.mut, fontSize: 11.5, height: 1.35)),
              ]),
            ),
          ),
        ]),
      ),
    );
  }
}

/// "বাদ দিন" — লাল কিনারের ছোট বড়ি (নমুনার `.rm`)
class _RemoveButton extends StatelessWidget {
  const _RemoveButton({super.key, required this.tok, required this.busy, required this.semantics, this.onPressed});

  final _Tok tok;
  final bool busy;
  final String semantics;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final ink = t.smart ? const Color(0xFFB3261E) : (t.glass ? const Color(0xFFFFB3C1) : const Color(0xFFE11D48));
    final edge = t.smart ? const Color(0xFF79747E) : (t.glass ? const Color(0x99FF96AA) : const Color(0xFFF3B4C1));
    return OutlinedButton(
      onPressed: onPressed,
      style: OutlinedButton.styleFrom(
        foregroundColor: ink,
        side: BorderSide(color: edge),
        shape: const StadiumBorder(),
        padding: t.smart ? const EdgeInsets.symmetric(horizontal: 12, vertical: 4) : const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
        minimumSize: const Size(0, 28),
        tapTargetSize: MaterialTapTargetSize.padded,
        // ⓘ থিমের বোতামের অক্ষর থেকে (বাংলা অক্ষরসহ) — খালি TextStyle দিলে থিমের ফন্ট হারিয়ে যেত
        textStyle: Theme.of(context).textTheme.labelLarge?.copyWith(fontSize: 12, fontWeight: FontWeight.w600) ??
            const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
      ),
      child: busy
          ? SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2, color: ink))
          : Text(context.l10n.mePageRemovePhone, semanticsLabel: semantics),
    );
  }
}

/// রূপের রঙে সুইচ — স্মার্টে Material 3-এর পুরো মাপ, বাকি দুইয়ে নমুনার ছোট `.tg`
class _LookSwitch extends StatelessWidget {
  const _LookSwitch({required this.tok, required this.value, required this.onChanged, required this.label});

  final _Tok tok;
  final bool value;
  final ValueChanged<bool> onChanged;
  final String label;

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final sw = Switch(
      value: value,
      onChanged: onChanged,
      thumbColor: const WidgetStatePropertyAll(Colors.white), // dark-ok: white on a coloured tile / switch thumb
      trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? t.acc : t.cardB),
      trackOutlineColor: const WidgetStatePropertyAll(Colors.transparent),
      materialTapTargetSize: MaterialTapTargetSize.padded,
    );
    return Semantics(
      label: label,
      child: t.smart ? sw : Transform.scale(scale: 0.78, child: sw),
    );
  }
}

/// "চেহারা" — স্মার্ট / গ্লাস / ক্লাসিক; চাপলেই পুরো অ্যাপের রূপ বদলায় ([AppLooks.chooseLook])
class _LookChoice extends StatelessWidget {
  const _LookChoice({required this.tok});

  final _Tok tok;

  static const List<AppLook> order = [AppLook.expressive, AppLook.glass, AppLook.abos];

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final l = context.l10n;
    final labels = [l.lookExpressive, l.lookGlass, l.lookAbos];
    final selected = order.indexOf(t.look);
    void pick(int i) => AppLooks.instance.chooseLook(order[i]);
    if (t.smart) {
      return ExpressiveButtonGroup(key: const ValueKey('me-look-choice'), labels: labels, selected: selected, onSelect: pick);
    }
    final radius = t.classic ? 7.0 : 999.0;
    // ⓘ দেখতে নমুনার সরু পিল (৩৩); চাপার জায়গা ৪৮ (Android-এর নিয়ম) — পিলের উপরে-নিচে অদৃশ্য কিনারা, প্রতিটা ঘর নিজের কলামে।
    // পিলের ভেতরে চাপলে পিলের নিজের ঢেউ; কিনারায় চাপলেও একই বাছাই। TalkBack পড়ে ৪৮-এর ঘর, নাম আর "বাছা" অবস্থাসহ
    final pill = ExcludeSemantics(child: _pill(t, labels, selected, radius, pick));
    return ConstrainedBox(
      constraints: const BoxConstraints(minHeight: 48),
      child: Stack(alignment: Alignment.center, children: [
        Positioned.fill(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 3),
            child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              for (final (i, label) in labels.indexed) ...[
                if (i > 0) const SizedBox(width: 4),
                Expanded(
                  child: Semantics(
                    container: true,
                    button: true,
                    selected: i == selected,
                    label: label,
                    onTap: () => pick(i),
                    child: GestureDetector(
                      behavior: HitTestBehavior.opaque,
                      excludeFromSemantics: true,
                      onTap: () => pick(i),
                    ),
                  ),
                ),
              ],
            ]),
          ),
        ),
        pill,
      ]),
    );
  }

  Widget _pill(_Tok t, List<String> labels, int selected, double radius, void Function(int) pick) {
    return Container(
      key: const ValueKey('me-look-choice'),
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(color: t.soft, borderRadius: BorderRadius.circular(radius)),
      child: Row(children: [
        for (final (i, label) in labels.indexed) ...[
          if (i > 0) const SizedBox(width: 4),
          Expanded(
            child: Semantics(
              button: true,
              selected: i == selected,
              child: Material(
                color: i == selected ? t.acc : Colors.transparent,
                borderRadius: BorderRadius.circular(radius),
                child: InkWell(
                  key: ValueKey('me-look-${order[i].name}'),
                  borderRadius: BorderRadius.circular(radius),
                  onTap: () => pick(i),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Text(label,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                            fontSize: 13, fontWeight: FontWeight.w600, color: i == selected ? t.accInk : t.softInk)),
                  ),
                ),
              ),
            ),
          ),
        ],
      ]),
    );
  }
}
