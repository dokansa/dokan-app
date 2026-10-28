import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../api/client.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Login with the phone number, same account as the website.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();
  bool _sent = false;
  bool _needName = false;
  bool _busy = false;
  int _wait = 0;
  Timer? _timer;

  @override
  void dispose() {
    _timer?.cancel();
    _phone.dispose();
    _code.dispose();
    _name.dispose();
    super.dispose();
  }

  String get _digits => _phone.text.replaceAll(RegExp(r'[^0-9]'), '');

  bool get _validPhone {
    final d = _digits;
    return RegExp(r'^(05\d{8}|5\d{8}|9665\d{8}|009665\d{8})$').hasMatch(d);
  }

  void _startTimer() {
    _timer?.cancel();
    setState(() => _wait = 60);
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted || _wait <= 1) {
        t.cancel();
        if (mounted) setState(() => _wait = 0);
      } else {
        setState(() => _wait--);
      }
    });
  }

  Future<void> _send() async {
    if (!_validPhone) return showMessage(context, 'اكتب رقم جوال سعودي صحيح مثل 05xxxxxxxx', error: true);
    setState(() => _busy = true);
    try {
      await ref.read(sessionProvider.notifier).requestOtp(_digits);
      if (!mounted) return;
      setState(() => _sent = true);
      _startTimer();
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    if (_code.text.trim().length < 4) return;
    if (_needName && _name.text.trim().isEmpty) return showMessage(context, 'اكتب اسمك', error: true);
    setState(() => _busy = true);
    try {
      await ref.read(sessionProvider.notifier).verifyOtp(_digits, _code.text.trim(), name: _name.text.trim());
      if (mounted) context.pop(true);
    } on ApiError catch (e) {
      final wantsName = (e.code ?? '').contains('name');
      if (wantsName && !_needName && mounted) {
        setState(() => _needName = true);
        showMessage(context, 'أهلاً فيك! اكتب اسمك عشان ننشئ حسابك');
      } else if (mounted) {
        showMessage(context, e.message, error: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(icon: Icons.close_rounded, label: 'إغلاق', onTap: () => context.pop(false)),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 40),
        children: [
          const CircleAvatar(radius: 34, backgroundColor: C.peach, child: Icon(Icons.pets_rounded, color: C.accentText, size: 32)),
          const SizedBox(height: 18),
          Text(_sent ? 'اكتب الرمز' : 'أهلاً في دكان', style: display(26, weight: FontWeight.w700)),
          const SizedBox(height: 6),
          Text(
            _sent ? 'أرسلنا رمز التحقق إلى ${_phone.text}' : 'سجّل برقم جوالك، نفس حسابك في موقع دكان',
            style: const TextStyle(color: C.muted, fontSize: 15),
          ),
          const SizedBox(height: 24),
          if (!_sent) ...[
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              textDirection: TextDirection.ltr,
              autofocus: true,
              autofillHints: const [AutofillHints.telephoneNumber],
              inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9+ ]')), LengthLimitingTextInputFormatter(16)],
              style: const TextStyle(fontSize: 18, letterSpacing: 1),
              decoration: const InputDecoration(hintText: '05xxxxxxxx', prefixIcon: Icon(Icons.phone_iphone_rounded)),
              onSubmitted: (_) => _send(),
            ),
            const SizedBox(height: 16),
            FilledButton(onPressed: _busy ? null : _send, child: _busy ? const _Spinner() : const Text('أرسل الرمز')),
          ] else ...[
            TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.center,
              autofocus: true,
              autofillHints: const [AutofillHints.oneTimeCode],
              inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(6)],
              style: display(26, weight: FontWeight.w700).copyWith(letterSpacing: 10),
              decoration: const InputDecoration(hintText: '••••••'),
              onChanged: (v) {
                if (v.length == 6 && !_needName) _verify();
              },
            ),
            if (_needName) ...[
              const SizedBox(height: 10),
              TextField(controller: _name, decoration: const InputDecoration(labelText: 'اسمك')),
            ],
            const SizedBox(height: 16),
            FilledButton(onPressed: _busy ? null : _verify, child: _busy ? const _Spinner() : const Text('دخول')),
            const SizedBox(height: 8),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                TextButton(
                  onPressed: () => setState(() {
                    _sent = false;
                    _code.clear();
                  }),
                  child: const Text('غيّر الرقم'),
                ),
                TextButton(
                  onPressed: _wait > 0 || _busy ? null : _send,
                  child: Text(_wait > 0 ? 'إعادة الإرسال بعد $_wait ث' : 'أعد إرسال الرمز'),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class _Spinner extends StatelessWidget {
  const _Spinner();
  @override
  Widget build(BuildContext context) =>
      const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white));
}
