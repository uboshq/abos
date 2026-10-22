import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/auth/auth_exceptions.dart';
import '../../core/auth/auth_state.dart';
import '../../core/auth/remembered_login.dart';
import '../../core/config/app_config.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_top_bar.dart' show bnDigits;

/// Identifier + password, then a six-digit code if the account has MFA on —
/// see docs/Contract, §১ for the 409 branch this screen's [_mfaStage] answers.
///
/// Routing on success is not done here: app_router.dart watches
/// [authStateProvider] and redirects once it flips to signedIn, the same
/// separation the sibling Nexus app's own login screen uses.
///
/// <p>⭐ The look is the owner's choice of 1 October 2026, "প্রিমিয়াম ৫ ·
/// মুক্তা সাদা": a pearl-white page with soft teal circles and one frosted
/// card. In the dark theme (0.4.39) the same page in deep teal ([_Palette.dark]):
/// before 22 Oct 2026 it stayed pearl-white in dark mode too, and its dark golden
/// was a light picture (fe's golden audit).
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key, this.now, this.readRemembered, this.saveRemembered});

  /// Seams — a test can ask for evening, and must not touch the keystore.
  final DateTime Function()? now;
  final Future<String?> Function()? readRemembered;
  final Future<void> Function(String? identifier)? saveRemembered;

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

/// The greeting by **Dhaka** time, whatever the phone's own zone says — the
/// business is in Bangladesh, and a phone set to another zone must not say
/// good evening at ten in the morning.
String loginGreeting(DateTime instant, {required bool bangla}) {
  final hour = instant.toUtc().add(const Duration(hours: 6)).hour;
  final words = lookupAppL10n(bangla ? AppLocale.locale : AppLocale.english);
  if (hour < 12) return words.authLoginGreetingMorning;
  if (hour < 17) return words.authLoginGreetingAfternoon;
  return words.authLoginGreetingEvening;
}

/// Every sentence on this page, from the ARB ([AppL10n]) in the page's own language. The footer's "বাংলা /
/// English" switches between them for this page; it starts in the app's language.
class _Words {
  _Words(bool bn)
      : _l = lookupAppL10n(bn ? AppLocale.locale : AppLocale.english),
        _locale = bn ? AppLocale.locale : AppLocale.english;

  final AppL10n _l;
  final Locale _locale;

  String get subtitle => _l.authLoginSubtitle;
  String get identifier => _l.authLoginIdentifier;
  String get identifierMissing => _l.authLoginIdentifierMissing;
  String get password => _l.authLoginPassword;
  String get passwordMissing => _l.authLoginPasswordMissing;
  String get signIn => _l.authLoginSignIn;
  String get verify => _l.authLoginVerify;
  String get back => _l.authLoginBack;
  String get remember => _l.authLoginRemember;
  String get forgot => _l.authLoginForgot;
  // ⭐ মালিক, ২ অক্টোবর ২০২৬: "forget passworads kaj korena app e" — আগে কেবল এই বাক্য ছিল, কোনো পথ নয়।
  String get forgotBody => _l.authLoginForgotBody;
  String get forgotOpen => _l.authLoginForgotOpen;
  String get ok => _l.authLoginOk;
  String get mfaPrompt => _l.authLoginMfaPrompt(bnDigits(6, _locale));
  String get mfaWrong => _l.authLoginMfaWrong;
  String get mfaLabel => _l.authLoginMfaLabel;
  String get mfaMissing => _l.authLoginMfaMissing(bnDigits(6, _locale));
  String get version => _l.authLoginVersion;
  String get hidePassword => _l.authLoginHidePassword;
  String get showPassword => _l.authLoginShowPassword;
}

/// ⭐ মালিকের "মুক্তা সাদা" — পট আর কার্ড হুবহু; কেবল তিনটা লেখার রং সেই রঙেরই একটু গাঢ় সুর, যাতে পটে অন্তত ৪.৫:১
/// (WCAG 2.1 AA; ec-র প্রবেশযোগ্যতার টেস্ট ধরল, সমন্বয়ক, ২০ অক্টোবর ২০২৬)। আগে: মলিন ৪.১, লিংক ৩.৪, নিচের লেখা ২.৪।
/// ⭐ অন্ধকারে একই পাতা গাঢ় সবুজাভ-নীলে ([dark]) — আগে অন্ধকার মোডেও সাদা পাতা খুলত (ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬);
/// প্রতিটা লেখা তার পটে অন্তত ৪.৫:১ (main_screens_in_dark_test)
class _Palette {
  const _Palette({
    required this.page,
    required this.card,
    required this.field,
    required this.ink,
    required this.muted,
    required this.border,
    required this.cardBorder,
    required this.link,
    required this.divider,
    required this.footer,
    required this.danger,
    required this.dangerSurface,
    required this.button,
    required this.onButton,
    required this.shadow,
    required this.glow,
  });

  final Color page;
  final Color card;
  final Color field;
  final Color ink;
  final Color muted;
  final Color border;
  final Color cardBorder;
  final Color link;
  final Color divider;
  final Color footer;
  final Color danger;
  final Color dangerSurface;

  /// "সাইন ইন" বোতাম আর বাছা টিকের পট, আর তার উপরের লেখা
  final Color button;
  final Color onButton;
  final Color shadow;

  /// পেছনের নরম বৃত্তগুলো কতটা দেখা যায় (১ = নমুনার মতো)
  final double glow;

  static const light = _Palette(
    page: Color(0xFFEEF5F7),
    card: Color.from(alpha: 0.85, red: 1, green: 1, blue: 1), // dark-ok: মুক্তা সাদার কার্ড, ৮৫%
    field: Color(0xFFFFFFFF), // dark-ok
    ink: Color(0xFF0B2A31),
    muted: Color(0xFF56747B),
    border: Color(0xFFD6E4E8),
    cardBorder: Color(0xFFE3EDF0),
    link: Color(0xFF087993),
    divider: Color(0xFFBFE3EA),
    footer: Color(0xFF59747A),
    danger: Color(0xFFB42318),
    dangerSurface: Color(0xFFFEF3F2),
    button: Color(0xFF0B2A31),
    onButton: Color(0xFFFFFFFF), // dark-ok
    shadow: Color(0x240B3C48),
    glow: 1,
  );

  static const dark = _Palette(
    page: Color(0xFF0B181B),
    card: Color(0xE6132327),
    field: Color(0xFF0F1F23),
    ink: Color(0xFFE3F1F4),
    muted: Color(0xFFA3BDC3),
    border: Color(0xFF344D53),
    cardBorder: Color(0xFF243A40),
    link: Color(0xFF7AD3E4),
    divider: Color(0xFF2F5A64),
    footer: Color(0xFF9DB6BC),
    danger: Color(0xFFFFB4AB),
    dangerSurface: Color(0xFF3B1714),
    button: Color(0xFF9EDCE8),
    onButton: Color(0xFF0B2A31),
    shadow: Color(0x66000000),
    glow: .16,
  );

  static _Palette of(BuildContext context) => Theme.of(context).brightness == Brightness.dark ? dark : light;
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _identifierController = TextEditingController();
  final _passwordController = TextEditingController();
  final _codeController = TextEditingController();

  bool _passwordVisible = false;
  bool _mfaStage = false;
  bool _mfaCodeWasWrong = false;
  bool _submitting = false;
  bool _remember = false;
  /// The footer's choice; until one is made, the app's own language ([L10nContext.l10n]: the tree's, or
  /// [AppLocale.current] where the tree has none)
  bool? _banglaChosen;

  bool get _bangla => _banglaChosen ?? context.l10n.localeName != AppLocale.english.languageCode;
  String? _error;

  _Words get _w => _Words(_bangla);

  _Palette get _p => _Palette.of(context);

  @override
  void initState() {
    super.initState();
    _restoreRemembered();
  }

  Future<void> _restoreRemembered() async {
    final saved = await (widget.readRemembered ?? RememberedLogin.instance.read)();
    if (!mounted || saved == null) return;
    setState(() {
      _remember = true;
      if (_identifierController.text.isEmpty) _identifierController.text = saved;
    });
  }

  @override
  void dispose() {
    _identifierController.dispose();
    _passwordController.dispose();
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    final identifier = _identifierController.text.trim();
    try {
      await ref.read(authStateProvider.notifier).login(
            identifier: identifier,
            password: _passwordController.text,
            code: _mfaStage ? _codeController.text.trim() : null,
          );
      // Only after a sign-in that worked — a mistyped ID is not worth keeping.
      await (widget.saveRemembered ?? RememberedLogin.instance.write)(
          _remember ? identifier : null);
      // On success app_router.dart redirects to /home — nothing to navigate
      // to from here.
    } on MfaRequiredException catch (failure) {
      if (!mounted) return;
      setState(() {
        _mfaStage = true;
        _mfaCodeWasWrong = failure.codeWasWrong;
      });
    } on AuthFailure catch (failure) {
      if (!mounted) return;
      setState(() => _error = failure.message);
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  void _showForgot() {
    final w = _w;
    showDialog<void>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(w.forgot),
        content: Text(w.forgotBody),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: Text(w.ok)),
          FilledButton(
            key: const ValueKey('login-forgot-open'),
            onPressed: () {
              Navigator.of(context).pop();
              launchUrl(forgotPasswordUrl(AppConfig.apiBaseUrl), mode: LaunchMode.externalApplication);
            },
            child: Text(w.forgotOpen),
          ),
        ],
      ),
    );
  }

  InputDecoration _field(String label, IconData icon, {Widget? suffix}) {
    OutlineInputBorder edge(Color color, [double width = 1]) => OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: color, width: width),
        );
    return InputDecoration(
      labelText: label,
      labelStyle: TextStyle(color: _p.muted, fontSize: 15),
      prefixIcon: Icon(icon, color: _p.muted),
      suffixIcon: suffix,
      filled: true,
      fillColor: _p.field,
      constraints: const BoxConstraints(minHeight: 54),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      enabledBorder: edge(_p.border),
      disabledBorder: edge(_p.border),
      focusedBorder: edge(_p.link, 1.5),
      errorBorder: edge(_p.danger),
      focusedErrorBorder: edge(_p.danger, 1.5),
    );
  }

  @override
  Widget build(BuildContext context) {
    final greeting = loginGreeting((widget.now ?? DateTime.now)(), bangla: _bangla);

    return Scaffold(
      backgroundColor: _p.page,
      // The keyboard pushes the card up; the scroll view keeps the button
      // reachable on a small phone.
      resizeToAvoidBottomInset: true,
      body: Stack(
        children: [
          Positioned.fill(child: _PearlBackdrop(glow: _p.glow)),
          SafeArea(
            child: LayoutBuilder(
              builder: (context, constraints) => SingleChildScrollView(
                keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
                child: ConstrainedBox(
                  constraints:
                      BoxConstraints(minHeight: math.max(0, constraints.maxHeight - 32)),
                  child: Center(
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 440),
                      child: _card(greeting),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _card(String greeting) {
    final w = _w;
    const gap = SizedBox(height: 14);

    return Container(
      key: const ValueKey('login-card'),
      padding: const EdgeInsets.fromLTRB(24, 34, 24, 26),
      decoration: BoxDecoration(
        color: _p.card,
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: _p.cardBorder),
        boxShadow: [
          BoxShadow(color: _p.shadow, blurRadius: 70, offset: const Offset(0, 30)),
        ],
      ),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Image.asset(
                'assets/branding/abos-logo-lockup-transparent.png',
                width: 124,
                semanticLabel: 'ABOS',
                errorBuilder: (_, __, ___) => Text('ABOS',
                    style: TextStyle(
                        fontSize: 28, fontWeight: FontWeight.w800, color: _p.ink)),
              ),
            ),
            gap,
            Text(greeting,
                key: const ValueKey('login-greeting'),
                textAlign: TextAlign.center,
                style: TextStyle(
                    fontSize: 30, fontWeight: FontWeight.w700, color: _p.ink)),
            Text(w.subtitle,
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 15, color: _p.muted)),
            gap,
            if (_error != null) ...[
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: _p.dangerSurface,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(_error!,
                    style: TextStyle(color: _p.danger, fontSize: 13)),
              ),
              gap,
            ],
            if (!_mfaStage) ...[
              TextFormField(
                key: const ValueKey('login-identifier'),
                controller: _identifierController,
                enabled: !_submitting,
                textInputAction: TextInputAction.next,
                style: TextStyle(fontSize: 17, color: _p.ink),
                decoration: _field(w.identifier, Icons.person_outline),
                validator: (value) =>
                    (value == null || value.trim().isEmpty) ? w.identifierMissing : null,
              ),
              gap,
              TextFormField(
                key: const ValueKey('login-password'),
                controller: _passwordController,
                enabled: !_submitting,
                obscureText: !_passwordVisible,
                style: TextStyle(fontSize: 17, color: _p.ink),
                onFieldSubmitted: (_) => _submit(),
                decoration: _field(
                  w.password,
                  Icons.lock_outline,
                  suffix: IconButton(
                    key: const ValueKey('login-password-eye'),
                    tooltip: _passwordVisible ? w.hidePassword : w.showPassword,
                    icon: Icon(
                      _passwordVisible
                          ? Icons.visibility_off_outlined
                          : Icons.visibility_outlined,
                      color: _p.muted,
                    ),
                    onPressed: _submitting
                        ? null
                        : () => setState(() => _passwordVisible = !_passwordVisible),
                  ),
                ),
                validator: (value) =>
                    (value == null || value.isEmpty) ? w.passwordMissing : null,
              ),
            ] else ...[
              Text(w.mfaPrompt, style: TextStyle(color: _p.ink)),
              if (_mfaCodeWasWrong)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(w.mfaWrong,
                      style: TextStyle(color: _p.danger, fontSize: 13)),
                ),
              gap,
              TextFormField(
                key: const ValueKey('login-code'),
                controller: _codeController,
                enabled: !_submitting,
                keyboardType: TextInputType.number,
                style: TextStyle(fontSize: 17, color: _p.ink),
                onFieldSubmitted: (_) => _submit(),
                decoration: _field(w.mfaLabel, Icons.pin_outlined),
                validator: (value) =>
                    (value == null || value.trim().length != 6) ? w.mfaMissing : null,
              ),
            ],
            gap,
            // ⓘ অন্তত ৫৬, বড় লেখায় বোতাম নিজে বাড়ে — লেখা কাটে না (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)।
            // ⛔ ২০০%-এ লেখা দুই লাইনে ভাঙলে উপরের লাইন বোতামের কিনারায় লাগত: Material 3-এর FilledButton-এর ভেতরে উপর-নিচে
            // কোনো ফাঁক নেই। এখন উপর-নিচে ১২ — বোতাম লেখার সাথে বাড়ে, লেখা কিনারা ছোঁয় না; পাশে ১২ (Material ২০০%-এ যা নামায়), বোতাম পুরো চওড়া, তাই ১৩০%-এ লেখা এক লাইনেই (ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬)
            ConstrainedBox(
              constraints: const BoxConstraints(minHeight: 56),
              child: FilledButton(
                key: const ValueKey('login-submit'),
                style: FilledButton.styleFrom(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                  backgroundColor: _p.button,
                  foregroundColor: _p.onButton,
                  disabledBackgroundColor: _p.button.withValues(alpha: 0.6),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
                onPressed: _submitting ? null : _submit,
                child: _submitting
                    ? SizedBox(
                        height: 22,
                        width: 22,
                        child: CircularProgressIndicator(strokeWidth: 2, color: _p.onButton),
                      )
                    : Text(_mfaStage ? w.verify : w.signIn, key: const ValueKey('login-submit-label'), textAlign: TextAlign.center),
              ),
            ),
            if (_mfaStage)
              TextButton(
                onPressed: _submitting
                    ? null
                    : () => setState(() {
                          _mfaStage = false;
                          _mfaCodeWasWrong = false;
                          _codeController.clear();
                          _error = null;
                        }),
                child: Text(w.back, style: TextStyle(color: _p.link)),
              )
            else
              Wrap(
                alignment: WrapAlignment.spaceBetween,
                crossAxisAlignment: WrapCrossAlignment.center,
                runSpacing: 4,
                children: [
                  // ⓘ বাক্স আর লেখা একটাই জিনিস — TalkBack বলে "মনে রাখুন, চেকবক্স", আর আঙুলের জায়গা অন্তত ৪৮ dp
                  // (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)। ⛔ আগে বাক্সটা ছিল নামহীন ৪০×৪০।
                  MergeSemantics(
                    child: InkWell(
                      key: const ValueKey('login-remember'),
                      borderRadius: BorderRadius.circular(8),
                      onTap: _submitting ? null : () => setState(() => _remember = !_remember),
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(minHeight: 48),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Checkbox(
                              value: _remember,
                              activeColor: _p.button,
                              checkColor: _p.onButton,
                              visualDensity: VisualDensity.compact,
                              onChanged: _submitting
                                  ? null
                                  : (v) => setState(() => _remember = v ?? false),
                            ),
                            Flexible(
                              child: Text(w.remember,
                                  style: TextStyle(color: _p.ink, fontSize: 14)),
                            ),
                            const SizedBox(width: 8),
                          ],
                        ),
                      ),
                    ),
                  ),
                  TextButton(
                    key: const ValueKey('login-forgot'),
                    onPressed: _showForgot,
                    child: Text(w.forgot,
                        style: TextStyle(color: _p.link, fontSize: 14)),
                  ),
                ],
              ),
            const SizedBox(height: 4),
            Container(
              height: 1,
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [
                  _p.divider.withValues(alpha: 0),
                  _p.divider,
                  _p.divider.withValues(alpha: 0),
                ]),
              ),
            ),
            gap,
            Wrap(
              alignment: WrapAlignment.center,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text('${w.version} ${AppConfig.appVersion} · ',
                    style: TextStyle(fontSize: 12, color: _p.footer)),
                _LanguageWord(
                  label: 'বাংলা', // l10n-ok: the language's own name
                  selected: _bangla,
                  onTap: () => setState(() => _banglaChosen = true),
                ),
                Text(' / ', style: TextStyle(fontSize: 12, color: _p.footer)),
                _LanguageWord(
                  label: 'English',
                  selected: !_bangla,
                  onTap: () => setState(() => _banglaChosen = false),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _LanguageWord extends StatelessWidget {
  const _LanguageWord({required this.label, required this.selected, required this.onTap});

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  // ⓘ ছোট লেখা, কিন্তু আঙুলের জায়গা ৪৮×৪৮ dp, আর TalkBack বলে কোনটা বাছা (ec, ১৯ অক্টোবর ২০২৬)
  Widget build(BuildContext context) {
    final p = _Palette.of(context);
    return Semantics(
        selected: selected,
        child: InkWell(
          onTap: onTap,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minWidth: 48, minHeight: 48),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
              child: Center(
                widthFactor: 1,
                child: Text(label,
                    style: TextStyle(
                      fontSize: 12,
                      color: selected ? p.ink : p.footer,
                      fontWeight: selected ? FontWeight.w700 : FontWeight.w400,
                    )),
              ),
            ),
          ),
        ),
      );
  }
}

/// The decorative layer — circles and the faint "A". Never interactive,
/// never read aloud.
class _PearlBackdrop extends StatelessWidget {
  const _PearlBackdrop({this.glow = 1});

  /// বৃত্তগুলো কতটা দেখা যায় — অন্ধকারে নরম আভা মাত্র
  final double glow;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: ExcludeSemantics(
        child: ClipRect(
          child: Stack(
            children: [
              Positioned(
                left: -150,
                top: -150,
                child: _Circle(
                  size: 360,
                  opacity: .55 * glow,
                  gradient: const RadialGradient(
                    colors: [Color(0xFFBFF0F8), Color(0xFF8EDCEA), Color(0xFF6CCBDD)],
                    stops: [0, .6, 1],
                  ),
                ),
              ),
              Positioned(
                right: -120,
                bottom: -110,
                child: _Circle(
                  size: 300,
                  opacity: .7 * glow,
                  gradient: const RadialGradient(colors: [Color(0xFFD3F4EC), Color(0xFFA7E3D6)]),
                ),
              ),
              Positioned(
                right: 30,
                top: 110,
                child: _Circle(size: 110, opacity: .8 * glow, color: const Color(0xFFCFEFF5)),
              ),
              Positioned(
                left: -40,
                bottom: -60,
                child: Opacity(
                  opacity: .07,
                  child: Transform.rotate(
                    angle: -12 * math.pi / 180,
                    child: Image.asset(
                      'assets/branding/abos-icon-transparent.png',
                      width: 380,
                      errorBuilder: (_, __, ___) => const SizedBox(width: 380),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Circle extends StatelessWidget {
  const _Circle({required this.size, required this.opacity, this.gradient, this.color});

  final double size;
  final double opacity;
  final Gradient? gradient;
  final Color? color;

  @override
  Widget build(BuildContext context) => Opacity(
        opacity: opacity,
        child: Container(
          width: size,
          height: size,
          decoration: BoxDecoration(shape: BoxShape.circle, gradient: gradient, color: color),
        ),
      );
}

/// ওয়েবের পাসওয়ার্ড-রিসেটের পাতা — API-র ঠিকানা থেকে (`…/api/v1` বাদ দিয়ে `…/forgot-password`)।
/// ⓘ অ্যাপ নিজে রিসেট করে না: ইমেইল-লিংক, throttle আর সব পাহারা ওয়েবের একটাই পথে ([[PasswordResetController]])।
Uri forgotPasswordUrl(String apiBaseUrl) {
  final api = Uri.parse(apiBaseUrl);
  final root = api.path.replaceFirst(RegExp(r'/api/v\d+/?$'), '');
  return api.replace(path: '$root/forgot-password', query: null);
}
