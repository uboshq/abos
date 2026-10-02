import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/auth/auth_exceptions.dart';
import '../../core/auth/auth_state.dart';
import '../../core/auth/remembered_login.dart';
import '../../core/config/app_config.dart';

/// Identifier + password, then a six-digit code if the account has MFA on —
/// see docs/Contract, §১ for the 409 branch this screen's [_mfaStage] answers.
///
/// Routing on success is not done here: app_router.dart watches
/// [authStateProvider] and redirects once it flips to signedIn, the same
/// separation the sibling Nexus app's own login screen uses.
///
/// <p>⭐ The look is the owner's choice of 1 October 2026, "প্রিমিয়াম ৫ ·
/// মুক্তা সাদা": a pearl-white page with soft teal circles and one frosted
/// card. The app has no dark theme, so this page has none either — it is
/// always the light pearl look.
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
  if (hour < 12) return bangla ? 'শুভ সকাল' : 'Good morning';
  if (hour < 17) return bangla ? 'শুভ দুপুর' : 'Good afternoon';
  return bangla ? 'শুভ সন্ধ্যা' : 'Good evening';
}

/// Every sentence on this page in both languages. The footer's "বাংলা /
/// English" switches between them for this page only — the rest of the app
/// speaks Bangla.
class _Words {
  const _Words(this.bn);

  final bool bn;

  String get subtitle => bn ? 'আজকের হিসাব আপনার অপেক্ষায়' : "Today's books are waiting for you";
  String get identifier => bn ? 'লগইন আইডি (নাম বা ইমেইল)' : 'Login ID (name or email)';
  String get identifierMissing => bn ? 'নাম বা ইমেইল দিন' : 'Enter your name or email';
  String get password => bn ? 'পাসওয়ার্ড' : 'Password';
  String get passwordMissing => bn ? 'পাসওয়ার্ড দিন' : 'Enter your password';
  String get signIn => bn ? 'প্রবেশ করুন' : 'Sign in';
  String get verify => bn ? 'যাচাই করুন' : 'Verify';
  String get back => bn ? 'ফিরে যান' : 'Go back';
  String get remember => bn ? 'মনে রাখুন' : 'Remember me';
  String get forgot => bn ? 'পাসওয়ার্ড ভুলে গেছেন?' : 'Forgot password?';
  // ⭐ মালিক, ২ অক্টোবর ২০২৬: "forget passworads kaj korena app e" — আগে কেবল এই বাক্য ছিল, কোনো পথ নয়।
  String get forgotBody => bn
      ? 'নিচের বোতাম চাপলে ব্রাউজারে পাসওয়ার্ড বদলের পাতা খুলবে — ইমেইল দিলে লিংক আসবে। ইমেইল না থাকলে কোম্পানির প্রশাসককে বলুন।'
      : "Tap below to open the reset page in your browser — enter your email and a link will arrive. No email? Ask your company's administrator.";
  String get forgotOpen => bn ? 'ইমেইলে লিংক নিন' : 'Get a link by email';
  String get ok => bn ? 'ঠিক আছে' : 'OK';
  String get mfaPrompt => bn
      ? 'আপনার অথেনটিকেটর অ্যাপ থেকে ৬-সংখ্যার কোডটি দিন।'
      : 'Enter the 6-digit code from your authenticator app.';
  String get mfaWrong => bn ? 'কোডটি ভুল ছিল — আবার চেষ্টা করুন।' : 'That code was wrong — try again.';
  String get mfaLabel => bn ? 'এমএফএ কোড' : 'MFA code';
  String get mfaMissing => bn ? '৬-সংখ্যার কোডটি দিন' : 'Enter the 6-digit code';
  String get version => bn ? 'সংস্করণ' : 'Version';
}

class _Palette {
  static const page = Color(0xFFEEF5F7);
  static const ink = Color(0xFF0B2A31);
  static const muted = Color(0xFF5B7B83);
  static const border = Color(0xFFD6E4E8);
  static const cardBorder = Color(0xFFE3EDF0);
  static const link = Color(0xFF0A8FAE);
  static const divider = Color(0xFFBFE3EA);
  static const footer = Color(0xFF8AA4AA);
  static const danger = Color(0xFFB42318);
  static const dangerSurface = Color(0xFFFEF3F2);
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
  bool _bangla = true;
  String? _error;

  _Words get _w => _Words(_bangla);

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
      labelStyle: const TextStyle(color: _Palette.muted, fontSize: 15),
      prefixIcon: Icon(icon, color: _Palette.muted),
      suffixIcon: suffix,
      filled: true,
      fillColor: Colors.white,
      constraints: const BoxConstraints(minHeight: 54),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      enabledBorder: edge(_Palette.border),
      disabledBorder: edge(_Palette.border),
      focusedBorder: edge(_Palette.link, 1.5),
      errorBorder: edge(_Palette.danger),
      focusedErrorBorder: edge(_Palette.danger, 1.5),
    );
  }

  @override
  Widget build(BuildContext context) {
    final greeting = loginGreeting((widget.now ?? DateTime.now)(), bangla: _bangla);

    return Scaffold(
      backgroundColor: _Palette.page,
      // The keyboard pushes the card up; the scroll view keeps the button
      // reachable on a small phone.
      resizeToAvoidBottomInset: true,
      body: Stack(
        children: [
          const Positioned.fill(child: _PearlBackdrop()),
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
        color: Colors.white.withValues(alpha: 0.85),
        borderRadius: BorderRadius.circular(28),
        border: Border.all(color: _Palette.cardBorder),
        boxShadow: const [
          BoxShadow(color: Color(0x240B3C48), blurRadius: 70, offset: Offset(0, 30)),
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
                errorBuilder: (_, __, ___) => const Text('ABOS',
                    style: TextStyle(
                        fontSize: 28, fontWeight: FontWeight.w800, color: _Palette.ink)),
              ),
            ),
            gap,
            Text(greeting,
                key: const ValueKey('login-greeting'),
                textAlign: TextAlign.center,
                style: const TextStyle(
                    fontSize: 30, fontWeight: FontWeight.w700, color: _Palette.ink)),
            Text(w.subtitle,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 15, color: _Palette.muted)),
            gap,
            if (_error != null) ...[
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: _Palette.dangerSurface,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(_error!,
                    style: const TextStyle(color: _Palette.danger, fontSize: 13)),
              ),
              gap,
            ],
            if (!_mfaStage) ...[
              TextFormField(
                key: const ValueKey('login-identifier'),
                controller: _identifierController,
                enabled: !_submitting,
                textInputAction: TextInputAction.next,
                style: const TextStyle(fontSize: 17, color: _Palette.ink),
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
                style: const TextStyle(fontSize: 17, color: _Palette.ink),
                onFieldSubmitted: (_) => _submit(),
                decoration: _field(
                  w.password,
                  Icons.lock_outline,
                  suffix: IconButton(
                    key: const ValueKey('login-password-eye'),
                    icon: Icon(
                      _passwordVisible
                          ? Icons.visibility_off_outlined
                          : Icons.visibility_outlined,
                      color: _Palette.muted,
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
              Text(w.mfaPrompt, style: const TextStyle(color: _Palette.ink)),
              if (_mfaCodeWasWrong)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(w.mfaWrong,
                      style: const TextStyle(color: _Palette.danger, fontSize: 13)),
                ),
              gap,
              TextFormField(
                key: const ValueKey('login-code'),
                controller: _codeController,
                enabled: !_submitting,
                keyboardType: TextInputType.number,
                style: const TextStyle(fontSize: 17, color: _Palette.ink),
                onFieldSubmitted: (_) => _submit(),
                decoration: _field(w.mfaLabel, Icons.pin_outlined),
                validator: (value) =>
                    (value == null || value.trim().length != 6) ? w.mfaMissing : null,
              ),
            ],
            gap,
            SizedBox(
              height: 56,
              child: FilledButton(
                key: const ValueKey('login-submit'),
                style: FilledButton.styleFrom(
                  backgroundColor: _Palette.ink,
                  foregroundColor: Colors.white,
                  disabledBackgroundColor: _Palette.ink.withValues(alpha: 0.6),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                  textStyle: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                ),
                onPressed: _submitting ? null : _submit,
                child: _submitting
                    ? const SizedBox(
                        height: 22,
                        width: 22,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : Text(_mfaStage ? w.verify : w.signIn),
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
                child: Text(w.back, style: const TextStyle(color: _Palette.link)),
              )
            else
              Wrap(
                alignment: WrapAlignment.spaceBetween,
                crossAxisAlignment: WrapCrossAlignment.center,
                runSpacing: 4,
                children: [
                  InkWell(
                    key: const ValueKey('login-remember'),
                    borderRadius: BorderRadius.circular(8),
                    onTap: _submitting ? null : () => setState(() => _remember = !_remember),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Checkbox(
                          value: _remember,
                          activeColor: _Palette.ink,
                          visualDensity: VisualDensity.compact,
                          onChanged: _submitting
                              ? null
                              : (v) => setState(() => _remember = v ?? false),
                        ),
                        Text(w.remember,
                            style: const TextStyle(color: _Palette.ink, fontSize: 14)),
                      ],
                    ),
                  ),
                  TextButton(
                    key: const ValueKey('login-forgot'),
                    onPressed: _showForgot,
                    child: Text(w.forgot,
                        style: const TextStyle(color: _Palette.link, fontSize: 14)),
                  ),
                ],
              ),
            const SizedBox(height: 4),
            Container(
              height: 1,
              decoration: const BoxDecoration(
                gradient: LinearGradient(colors: [
                  Color(0x00BFE3EA),
                  _Palette.divider,
                  Color(0x00BFE3EA),
                ]),
              ),
            ),
            gap,
            Wrap(
              alignment: WrapAlignment.center,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Text('${w.version} ${AppConfig.appVersion} · ',
                    style: const TextStyle(fontSize: 12, color: _Palette.footer)),
                _LanguageWord(
                  label: 'বাংলা',
                  selected: _bangla,
                  onTap: () => setState(() => _bangla = true),
                ),
                const Text(' / ', style: TextStyle(fontSize: 12, color: _Palette.footer)),
                _LanguageWord(
                  label: 'English',
                  selected: !_bangla,
                  onTap: () => setState(() => _bangla = false),
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
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
          child: Text(label,
              style: TextStyle(
                fontSize: 12,
                color: selected ? _Palette.ink : _Palette.footer,
                fontWeight: selected ? FontWeight.w700 : FontWeight.w400,
              )),
        ),
      );
}

/// The decorative layer — circles and the faint "A". Never interactive,
/// never read aloud.
class _PearlBackdrop extends StatelessWidget {
  const _PearlBackdrop();

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: ExcludeSemantics(
        child: ClipRect(
          child: Stack(
            children: [
              const Positioned(
                left: -150,
                top: -150,
                child: _Circle(
                  size: 360,
                  opacity: .55,
                  gradient: RadialGradient(
                    colors: [Color(0xFFBFF0F8), Color(0xFF8EDCEA), Color(0xFF6CCBDD)],
                    stops: [0, .6, 1],
                  ),
                ),
              ),
              const Positioned(
                right: -120,
                bottom: -110,
                child: _Circle(
                  size: 300,
                  opacity: .7,
                  gradient: RadialGradient(colors: [Color(0xFFD3F4EC), Color(0xFFA7E3D6)]),
                ),
              ),
              const Positioned(
                right: 30,
                top: 110,
                child: _Circle(size: 110, opacity: .8, color: Color(0xFFCFEFF5)),
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
