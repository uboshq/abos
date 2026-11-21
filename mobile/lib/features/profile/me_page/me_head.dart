part of '../me_page.dart';

/// মলাট, তার অর্ধেকের ওপরে বড় গোল ছবি, নাম, পদবি, "কোম্পানি · শাখা" (নমুনার `.cover` আর `.pid`)
class _Head extends StatelessWidget {
  const _Head({required this.tok, required this.profile, this.photo, this.cover, this.onCover, this.onPhoto});

  final _Tok tok;
  final MeProfile profile;
  final ImageProvider? photo;
  final ImageProvider? cover;

  /// null = "মলাট বদলান" নেই (সার্ভারের `cover_photo` false)
  final VoidCallback? onCover;

  /// null = ছবির ক্যামেরা নেই (`profile_photo` false)
  final VoidCallback? onPhoto;

  static const double coverHeight = 150;

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final avatar = t.smart ? 112.0 : 110.0;
    final overlap = t.smart ? 58.0 : 56.0;
    final coverTop = t.smart ? 4.0 : 0.0;
    final where = [profile.company, profile.branch].where((s) => s.trim().isNotEmpty).join(' · ');
    final designation = profile.designation;
    final align = t.smart ? CrossAxisAlignment.center : CrossAxisAlignment.start;
    final textAlign = t.smart ? TextAlign.center : TextAlign.start;

    return Stack(clipBehavior: Clip.none, children: [
      Padding(
        padding: EdgeInsets.fromLTRB(t.smart ? 16 : 0, coverTop, t.smart ? 16 : 0, 0),
        child: _Cover(tok: t, image: cover, colour: _hex(profile.companyColour), onChange: onCover),
      ),
      Padding(
        padding: EdgeInsets.fromLTRB(16, coverTop + coverHeight - overlap, 16, 0),
        child: Column(crossAxisAlignment: align, children: [
          _Avatar(tok: t, size: avatar, image: photo, name: profile.name, initial: profile.initial, onCamera: onPhoto),
          SizedBox(height: t.smart ? 10 : 8),
          Text(profile.name,
              key: const ValueKey('me-name'),
              textAlign: textAlign,
              style: TextStyle(
                  fontSize: t.smart ? 21 : 23, height: 1.2, fontWeight: t.smart ? FontWeight.w800 : FontWeight.w700, color: t.ink)),
          if (designation != null && designation.trim().isNotEmpty)
            Text(designation.trim(),
                key: const ValueKey('me-designation'),
                textAlign: textAlign,
                style: TextStyle(fontWeight: FontWeight.w600, color: t.mut, fontSize: t.smart ? 13 : 14)),
          if (where.isNotEmpty)
            Row(
              key: const ValueKey('me-where'),
              mainAxisAlignment: t.smart ? MainAxisAlignment.center : MainAxisAlignment.start,
              children: [
                Icon(Symbols.apartment, size: 15, color: t.mut),
                const SizedBox(width: 5),
                Flexible(child: Text(where, style: TextStyle(color: t.mut, fontSize: 13), overflow: TextOverflow.ellipsis)),
              ],
            ),
        ]),
      ),
    ]);
  }
}

class _Cover extends StatelessWidget {
  const _Cover({required this.tok, this.image, this.colour, this.onChange});

  final _Tok tok;
  final ImageProvider? image;
  final Color? colour;
  final VoidCallback? onChange;

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final plain = _plain();
    final body = Stack(fit: StackFit.expand, children: [
      if (image == null)
        plain
      else
        Image(key: const ValueKey('me-cover-image'), image: image!, fit: BoxFit.cover, errorBuilder: (_, __, ___) => plain),
      if (onChange != null)
        Positioned(
          right: t.smart ? 12 : 10,
          bottom: t.smart ? 12 : 10,
          child: Material(
            color: t.smart ? Expressive.secondaryBox : const Color(0x80000000),
            shape: const StadiumBorder(),
            child: InkWell(
              key: const ValueKey('me-cover-change'),
              customBorder: const StadiumBorder(),
              onTap: onChange,
              child: Padding(
                padding: t.smart ? const EdgeInsets.symmetric(horizontal: 12, vertical: 6) : const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Symbols.photo_camera, size: 15, color: t.smart ? const Color(0xFF1D192B) : Colors.white), // dark-ok: on the coloured cover/avatar
                  const SizedBox(width: 5),
                  Text(context.l10n.mePageCoverChange,
                      style: TextStyle(
                          fontSize: 12, fontWeight: FontWeight.w600, color: t.smart ? const Color(0xFF1D192B) : Colors.white)), // dark-ok: on the coloured cover/avatar
                ]),
              ),
            ),
          ),
        ),
    ]);
    return SizedBox(
      key: const ValueKey('me-cover'),
      height: _Head.coverHeight,
      width: double.infinity,
      child: t.smart ? ClipRRect(borderRadius: Expressive.heroShape, child: body) : ClipRect(child: body),
    );
  }

  /// মলাট নেই — কোম্পানির রং (না থাকলে ব্র্যান্ডের নীল); স্মার্টে এক রঙের মূল কার্ড আর ফুলের ছাপ, বাকি দুইয়ে ঢাল আর পাহাড়
  Widget _plain() {
    final c = colour;
    if (tok.smart) {
      return ColoredBox(
        key: const ValueKey('me-cover-colour'),
        color: c ?? Expressive.primary,
        child: Stack(children: [
          Positioned(right: -22, top: -20, width: 110, height: 110, child: CustomPaint(painter: _FlowerPainter())),
        ]),
      );
    }
    final colours = c == null
        ? const [Color(0xFF0B3B8F), Color(0xFF2563EB), Color(0xFF22D3EE)]
        : [Color.lerp(c, Colors.black, 0.35)!, c, Color.lerp(c, Colors.white, 0.45)!]; // dark-ok: on the coloured cover/avatar
    return DecoratedBox(
      key: const ValueKey('me-cover-colour'),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          // ⓘ CSS-এর 120deg — বাঁ-উপর থেকে ডান-নিচ
          begin: const Alignment(-0.87, -0.5),
          end: const Alignment(0.87, 0.5),
          stops: const [0, 0.48, 1],
          colors: colours,
        ),
      ),
      child: CustomPaint(painter: _LandPainter()),
    );
  }
}

/// নমুনার `#land` — সূর্য, দুই সারি পাহাড়, দুই পাখি (viewBox 400×160, slice)
class _LandPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final s = math.max(size.width / 400, size.height / 160);
    final dx = (size.width - 400 * s) / 2, dy = (size.height - 160 * s) / 2;
    canvas.save();
    canvas.translate(dx, dy);
    canvas.scale(s);
    canvas.drawCircle(const Offset(305, 50), 22, Paint()..color = Colors.white.withValues(alpha: 0.5)); // dark-ok: on the coloured cover/avatar
    final back = Path()
      ..moveTo(0, 112)
      ..cubicTo(60, 74, 112, 86, 160, 104)
      ..cubicTo(208, 122, 262, 64, 322, 90)
      ..cubicTo(382, 116, 380, 98, 400, 86)
      ..lineTo(400, 160)
      ..lineTo(0, 160)
      ..close();
    canvas.drawPath(back, Paint()..color = Colors.white.withValues(alpha: 0.16)); // dark-ok: on the coloured cover/avatar
    final front = Path()
      ..moveTo(0, 138)
      ..cubicTo(80, 108, 140, 122, 200, 132)
      ..cubicTo(260, 142, 320, 106, 400, 124)
      ..lineTo(400, 160)
      ..lineTo(0, 160)
      ..close();
    canvas.drawPath(front, Paint()..color = Colors.white.withValues(alpha: 0.26)); // dark-ok: on the coloured cover/avatar
    final bird = Paint()
      ..color = Colors.white.withValues(alpha: 0.5) // dark-ok: on the coloured cover/avatar
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round;
    canvas.drawPath(Path()..moveTo(60, 40)..quadraticBezierTo(72, 34, 84, 40), bird);
    canvas.drawPath(Path()..moveTo(90, 30)..quadraticBezierTo(99, 25.5, 108, 30), bird);
    canvas.restore();
  }

  @override
  bool shouldRepaint(_LandPainter old) => false;
}

/// স্মার্টের মলাটে ফুলের ছাপ — মাঝে বড় গোল, চারপাশে আটটা (সাদা 22%)
class _FlowerPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final k = size.width / 100;
    final p = Paint()..color = Colors.white.withValues(alpha: 0.22); // dark-ok: on the coloured cover/avatar
    canvas.drawCircle(Offset(50 * k, 50 * k), 34 * k, p);
    for (final (x, y) in const [(50.0, 16.0), (72.0, 24.0), (84.0, 50.0), (72.0, 76.0), (50.0, 84.0), (28.0, 76.0), (16.0, 50.0), (28.0, 24.0)]) {
      canvas.drawCircle(Offset(x * k, y * k), 13 * k, p);
    }
  }

  @override
  bool shouldRepaint(_FlowerPainter old) => false;
}

class _Avatar extends StatelessWidget {
  const _Avatar({required this.tok, required this.size, required this.name, required this.initial, this.image, this.onCamera});

  final _Tok tok;
  final double size;
  final String name;
  final String initial;
  final ImageProvider? image;
  final VoidCallback? onCamera;

  @override
  Widget build(BuildContext context) {
    final t = tok;
    final letter = initial.trim().isNotEmpty ? initial.trim() : (name.trim().isEmpty ? '?' : name.trim().characters.first);
    final letterView = Center(
      child: Text(letter,
          key: const ValueKey('me-initial'),
          textScaler: TextScaler.noScaling,
          style: TextStyle(fontSize: t.smart ? 42 : 40, fontWeight: FontWeight.w700, color: t.smart ? Expressive.primaryDeep : Colors.white)), // dark-ok: on the coloured cover/avatar
    );
    final circle = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: t.ring, width: 4),
        color: t.smart ? Expressive.secondaryBox : null,
        gradient: t.smart ? null : const LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Color(0xFFF59E0B), Color(0xFFEA580C)]),
        boxShadow: t.smart ? const [BoxShadow(color: Color(0xFFCAC4D0), spreadRadius: 1)] : null,
      ),
      child: ClipOval(
        child: image == null
            ? letterView
            : Image(key: const ValueKey('me-photo'), image: image!, fit: BoxFit.cover, errorBuilder: (_, __, ___) => letterView),
      ),
    );
    final camSize = t.smart ? 34.0 : 32.0;
    final camBg = t.smart ? Expressive.create : (t.glass ? const Color(0xFF3A0C4F) : t.soft);
    final camInk = t.smart ? Expressive.onCreate : t.softInk;
    final camShape = t.smart
        ? RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: t.ring, width: 2))
        : CircleBorder(side: BorderSide(color: t.ring, width: 2));
    return Semantics(
      image: true,
      label: image == null ? context.l10n.profileFaceNone : context.l10n.profileFace,
      child: SizedBox(
        width: size,
        height: size,
        child: Stack(clipBehavior: Clip.none, children: [
          ExcludeSemantics(child: circle),
          if (onCamera != null)
            Positioned(
              right: -2,
              bottom: 4,
              child: Material(
                color: camBg,
                shape: camShape,
                child: InkWell(
                  key: const ValueKey('me-photo-change'),
                  customBorder: camShape,
                  onTap: onCamera,
                  child: Tooltip(
                    message: context.l10n.mePagePhotoChange,
                    child: SizedBox(width: camSize, height: camSize, child: Icon(Symbols.photo_camera, size: 17, color: camInk)),
                  ),
                ),
              ),
            ),
        ]),
      ),
    );
  }
}

/// "প্রোফাইল ঠিক করুন" (পুরো চওড়া), তার নিচে "বার্তা" আর "কল" (নমুনার `.btns`) — ⭐ বার্তা ও কল (২১ নভেম্বর ২০২৬): "বার্তা" কেবল
/// [showMessage] (`features.messaging`), "কল" কেবল [showCall] (`features.calls`) হলে; একটা থাকলে সে পুরো সারি, কোনোটাই না থাকলে
/// সারিই নেই। শেল [onMessage]/[onCall] দিলে সেগুলো খোলে; না দিলে "শীঘ্রই"
class _Buttons extends StatelessWidget {
  const _Buttons(
      {required this.tok, required this.onEdit, required this.onSoon, this.showMessage = false, this.showCall = false, this.onMessage, this.onCall});

  final _Tok tok;
  final VoidCallback onEdit;
  final VoidCallback onSoon;
  final bool showMessage;
  final bool showCall;
  final VoidCallback? onMessage;
  final VoidCallback? onCall;

  @override
  Widget build(BuildContext context) {
    final l = context.l10n;
    // ⓘ স্মার্টে বোতাম নিজেই ৪৮ — ফাঁক যেমন ছিল। ক্লাসিক আর গ্লাসে বোতাম দেখতে ৩৮, চাপার জায়গা ৪৮ (Android-এর নিয়ম): বাড়তি
    // ১০ আগের ফাঁক থেকেই নেওয়া — "ঠিক করুন"-এর উপরে ১০ (উপরের ১২ থেকে), "বার্তা"/"কল"-এর উপরে ৮ (মাঝের ফাঁক) আর নিচে ২
    // (নিচের ফাঁক)। ⛔ তাই আঁকা হুবহু আগের জায়গায়, পাতার মাপও একই
    final rowReach = tok.smart ? EdgeInsets.zero : const EdgeInsets.only(top: 8, bottom: 2);
    final pair = <Widget>[
      if (showMessage)
        _Btn(
            key: const ValueKey('me-message'),
            tok: tok,
            icon: Symbols.chat,
            label: l.mePageMessage,
            soon: onMessage == null,
            reach: rowReach,
            onTap: onMessage ?? onSoon),
      if (showCall)
        _Btn(
            key: const ValueKey('me-call'),
            tok: tok,
            icon: Symbols.call,
            label: l.mePageCall,
            soon: onCall == null,
            reach: rowReach,
            onTap: onCall ?? onSoon),
    ];
    final row = pair.isEmpty
        ? null
        : Row(children: [
            for (final (i, b) in pair.indexed) ...[
              if (i > 0) const SizedBox(width: 8),
              Expanded(child: b),
            ],
          ]);
    if (tok.smart) {
      return Padding(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 0),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          _Btn(key: const ValueKey('me-edit'), tok: tok, primary: true, icon: Symbols.edit, label: l.mePageEditProfile, onTap: onEdit),
          if (row != null) ...[const SizedBox(height: 8), row],
        ]),
      );
    }
    const editReach = EdgeInsets.only(top: 10);
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 2, 16, 0),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        _Btn(
            key: const ValueKey('me-edit'),
            tok: tok,
            primary: true,
            icon: Symbols.edit,
            label: l.mePageEditProfile,
            reach: editReach,
            onTap: onEdit),
        if (row != null) row,
      ]),
    );
  }
}

class _Btn extends StatelessWidget {
  const _Btn(
      {super.key,
      required this.tok,
      required this.icon,
      required this.label,
      required this.onTap,
      this.primary = false,
      this.soon = false,
      this.reach = EdgeInsets.zero});

  final _Tok tok;
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool primary;
  final bool soon;

  /// আঁকা বোতামের বাইরে অদৃশ্য বাড়তি চাপার জায়গা — দেখতে ৩৮-এর বোতাম, চাপতে ৪৮ ([_Buttons])
  final EdgeInsets reach;

  @override
  Widget build(BuildContext context) {
    final button = _button(context);
    if (reach == EdgeInsets.zero) return button;
    // ⓘ ভেতরের বোতাম চাপলে তার নিজের ঢেউ; বাড়তি কিনারায় চাপলেও একই কাজ। TalkBack একটাই বোতাম পড়ে — পুরো ৪৮-এর ঘর
    return Semantics(
      container: true,
      button: true,
      label: soon ? '$label\n${l10nSoon(context)}' : label,
      onTap: onTap,
      excludeSemantics: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        excludeFromSemantics: true,
        onTap: onTap,
        child: Padding(padding: reach, child: button),
      ),
    );
  }

  Widget _button(BuildContext context) {
    final t = tok;
    final radius = t.smart ? 24.0 : (t.classic ? 8.0 : 12.0);
    final bg = primary ? t.acc : (t.glass ? const Color(0x1FFFFFFF) : t.soft);
    final ink = primary ? t.accInk : t.softInk;
    final side = primary || t.smart ? BorderSide.none : BorderSide(color: t.glass ? const Color(0x4DFFFFFF) : t.cardB);
    final shape = RoundedRectangleBorder(borderRadius: BorderRadius.circular(radius), side: side);
    return Material(
      color: bg,
      shape: shape,
      child: InkWell(
        customBorder: shape,
        onTap: onTap,
        child: SizedBox(
          height: t.smart ? 48 : 38,
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              Icon(icon, size: 17, color: ink),
              const SizedBox(width: 6),
              Flexible(
                child: Text(label,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                        color: ink, fontSize: 13.5, fontWeight: primary && t.smart ? FontWeight.w700 : FontWeight.w600)),
              ),
              if (soon) ...[
                const SizedBox(width: 6),
                // ⓘ Flexible + scaleDown — খুব বড় লেখায় (২০০%) সরু বোতামে "শীঘ্রই" একটু ছোট হয়ে ধরে, সারি ডানে উপচায় না;
                // সাধারণ মাপে যেমন ছিল
                Flexible(
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6),
                      decoration: BoxDecoration(color: const Color(0xFFFEF3C7), borderRadius: BorderRadius.circular(999)),
                      child: Text(l10nSoon(context),
                          style: const TextStyle(fontSize: 10, height: 1.6, fontWeight: FontWeight.w700, color: Color(0xFF92400E))),
                    ),
                  ),
                ),
              ],
            ]),
          ),
        ),
      ),
    );
  }

  static String l10nSoon(BuildContext context) => context.l10n.mePageSoon;
}
