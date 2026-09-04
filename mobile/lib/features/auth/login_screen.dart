import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/auth_exceptions.dart';
import '../../core/auth/auth_state.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';

/// Identifier + password, then a six-digit code if the account has MFA on —
/// see docs/Contract, §১ for the 409 branch this screen's [_mfaStage] answers.
///
/// Routing on success is not done here: app_router.dart watches
/// [authStateProvider] and redirects once it flips to signedIn, the same
/// separation the sibling Nexus app's own login screen uses.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
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
  String? _error;

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

    try {
      await ref.read(authStateProvider.notifier).login(
            identifier: _identifierController.text.trim(),
            password: _passwordController.text,
            code: _mfaStage ? _codeController.text.trim() : null,
          );
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.primary,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SizedBox(height: AppSpacing.xxl),
                const Text(
                  'ABOS',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: AppColors.onPrimary,
                    fontSize: 32,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 1.2,
                  ),
                ),
                const SizedBox(height: AppSpacing.xs),
                const Text(
                  'A Business Operating System',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.white70, fontSize: 13),
                ),
                const SizedBox(height: AppSpacing.xxl),
                Card(
                  color: AppColors.surface,
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.lg),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (_error != null) ...[
                          Container(
                            padding: const EdgeInsets.all(AppSpacing.sm),
                            decoration: BoxDecoration(
                              color: AppColors.dangerSurface,
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Text(
                              _error!,
                              style: const TextStyle(
                                  color: AppColors.danger, fontSize: 13),
                            ),
                          ),
                          const SizedBox(height: AppSpacing.md),
                        ],
                        if (!_mfaStage) ...[
                          TextFormField(
                            controller: _identifierController,
                            enabled: !_submitting,
                            textInputAction: TextInputAction.next,
                            decoration: const InputDecoration(
                              labelText: 'নাম বা ইমেইল',
                              prefixIcon: Icon(Icons.person_outline),
                            ),
                            validator: (value) =>
                                (value == null || value.trim().isEmpty)
                                    ? 'নাম বা ইমেইল দিন'
                                    : null,
                          ),
                          const SizedBox(height: AppSpacing.md),
                          TextFormField(
                            controller: _passwordController,
                            enabled: !_submitting,
                            obscureText: !_passwordVisible,
                            onFieldSubmitted: (_) => _submit(),
                            decoration: InputDecoration(
                              labelText: 'পাসওয়ার্ড',
                              prefixIcon: const Icon(Icons.lock_outline),
                              suffixIcon: IconButton(
                                icon: Icon(_passwordVisible
                                    ? Icons.visibility_off_outlined
                                    : Icons.visibility_outlined),
                                onPressed: _submitting
                                    ? null
                                    : () => setState(
                                        () => _passwordVisible = !_passwordVisible),
                              ),
                            ),
                            validator: (value) =>
                                (value == null || value.isEmpty)
                                    ? 'পাসওয়ার্ড দিন'
                                    : null,
                          ),
                        ] else ...[
                          const Text(
                            'আপনার অথেনটিকেটর অ্যাপ থেকে ৬-সংখ্যার কোডটি দিন।',
                          ),
                          if (_mfaCodeWasWrong) ...[
                            const SizedBox(height: AppSpacing.xs),
                            const Text(
                              'কোডটি ভুল ছিল — আবার চেষ্টা করুন।',
                              style: TextStyle(
                                  color: AppColors.danger, fontSize: 13),
                            ),
                          ],
                          const SizedBox(height: AppSpacing.md),
                          TextFormField(
                            controller: _codeController,
                            enabled: !_submitting,
                            keyboardType: TextInputType.number,
                            onFieldSubmitted: (_) => _submit(),
                            decoration: const InputDecoration(
                              labelText: 'এমএফএ কোড',
                              prefixIcon: Icon(Icons.pin_outlined),
                            ),
                            validator: (value) =>
                                (value == null || value.trim().length != 6)
                                    ? '৬-সংখ্যার কোডটি দিন'
                                    : null,
                          ),
                        ],
                        const SizedBox(height: AppSpacing.lg),
                        ElevatedButton(
                          onPressed: _submitting ? null : _submit,
                          child: _submitting
                              ? const SizedBox(
                                  height: 20,
                                  width: 20,
                                  child: CircularProgressIndicator(
                                      strokeWidth: 2, color: Colors.white),
                                )
                              : Text(_mfaStage ? 'যাচাই করুন' : 'ঢুকুন'),
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
                            child: const Text('ফিরে যান'),
                          ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
