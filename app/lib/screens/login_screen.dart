import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:smart_auth/smart_auth.dart';

import '../api/client.dart';
import '../l10n/lang.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/permission_prompts.dart';

const _whatsappGreen = Color(0xFF1FA855);

/// Login with the phone number (code by WhatsApp or SMS) — same account as the website.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> with WidgetsBindingObserver {
  final _smartAuth = SmartAuth.instance;
  bool _listening = false;
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();
  bool _sent = false;
  bool _needName = false;
  bool _busy = false;
  String _channel = '';
  int _wait = 0;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  /// Fill the code when it arrives (SMS consent) or when the customer copied it (WhatsApp).
  void _autoFill(String raw) {
    final m = RegExp(r'(?<!\d)(\d{6})(?!\d)').firstMatch(raw);
    if (m == null || !_sent || !mounted || _busy) return;
    if (_code.text == m.group(1)) return;
    setState(() => _code.text = m.group(1)!);
    if (!_needName) _verify();
  }

  Future<void> _listenSms() async {
    if (_listening) return;
    _listening = true;
    try {
      final res = await _smartAuth.getSmsWithUserConsentApi();
      final text = '${res.data?.code ?? ''} ${res.data?.sms ?? ''}';
      _autoFill(text);
    } catch (_) {
      // Not available on this phone: the keyboard suggestion still works.
    } finally {
      _listening = false;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed && _sent && _code.text.length < 6) {
      Clipboard.getData(Clipboard.kTextPlain).then((d) => _autoFill(d?.text ?? '')).catchError((_) {});
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    try {
      _smartAuth.removeUserConsentApiListener();
    } catch (_) {}
    _timer?.cancel();
    _phone.dispose();
    _code.dispose();
    _name.dispose();
    super.dispose();
  }

  String get _digits {
    // Accept Arabic-Indic digits too.
    const ar = '٠١٢٣٤٥٦٧٨٩';
    var s = _phone.text;
    for (var i = 0; i < 10; i++) {
      s = s.replaceAll(ar[i], '$i');
    }
    return s.replaceAll(RegExp(r'[^0-9]'), '');
  }

  bool get _validPhone => RegExp(r'^(05\d{8}|5\d{8}|9665\d{8}|009665\d{8})$').hasMatch(_digits);

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

  Future<void> _send(String? channel) async {
    if (!_validPhone) {
      return showMessage(context, context.tr('اكتب رقم جوال سعودي صحيح مثل 05xxxxxxxx', 'Enter a valid Saudi mobile number, e.g. 05xxxxxxxx'),
          error: true);
    }
    setState(() => _busy = true);
    // Start listening before the SMS is sent (Android shows "allow Dokan to read this message").
    if (channel != 'whatsapp') _listenSms();
    try {
      final used = await ref.read(sessionProvider.notifier).requestOtp(_digits, channel: channel);
      if (!mounted) return;
      setState(() {
        _sent = true;
        _channel = used.isNotEmpty ? used : (channel ?? '');
      });
      _startTimer();
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    if (_code.text.trim().length < 4) return;
    if (_needName && _name.text.trim().isEmpty) {
      return showMessage(context, context.tr('اكتب اسمك', 'Enter your name'), error: true);
    }
    setState(() => _busy = true);
    try {
      final linked = await ref.read(sessionProvider.notifier).verifyOtp(_digits, _code.text.trim(), name: _name.text.trim());
      if (!mounted) return;
      if (linked > 0) {
        showMessage(
          context,
          context.tr('ربطنا $linked طلب سابق برقمك بحسابك', 'We linked $linked earlier order(s) placed with your number to your account'),
        );
      }
      // Ask for notifications right after login so the customer can follow their orders.
      await askNotifications(context);
      if (mounted) context.pop(true);
    } on ApiError catch (e) {
      final wantsName = (e.code ?? '').contains('name');
      if (wantsName && !_needName && mounted) {
        setState(() => _needName = true);
        showMessage(context, context.tr('أهلاً فيك! اكتب اسمك عشان ننشئ حسابك', 'Welcome! Enter your name to create your account'));
      } else if (mounted) {
        showMessage(context, e.message, error: true);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final channels = ref.watch(configProvider).valueOrNull?.otpChannels ?? const <String>[];
    final sentVia = _channel == 'whatsapp'
        ? context.tr('على واتساب', 'on WhatsApp')
        : _channel == 'sms'
            ? context.tr('برسالة نصية', 'by SMS')
            : '';
    final other = _channel == 'whatsapp' ? 'sms' : 'whatsapp';

    return Scaffold(
      appBar: AppBar(
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(icon: Icons.close_rounded, label: context.tr('إغلاق', 'Close'), onTap: () => context.pop(false)),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(24, 8, 24, 40),
        children: [
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: Image.asset('assets/brand/logo_circle.png', width: 84, height: 84, filterQuality: FilterQuality.high),
          ),
          const SizedBox(height: 18),
          Text(_sent ? context.tr('اكتب الرمز', 'Enter the code') : context.tr('أهلاً في دكان', 'Welcome to Dokan'),
              style: display(26, weight: FontWeight.w700)),
          const SizedBox(height: 6),
          Text(
            _sent
                ? context.tr('أرسلنا رمز التحقق $sentVia إلى ${_phone.text}', 'We sent a code $sentVia to ${_phone.text}')
                : context.tr('سجّل برقم جوالك، نفس حسابك في موقع دكان', 'Sign in with your mobile number — same account as dokansa.com'),
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
              inputFormatters: [
                FilteringTextInputFormatter.allow(RegExp(r'[0-9٠-٩+ ]')),
                LengthLimitingTextInputFormatter(16),
              ],
              style: const TextStyle(fontSize: 18, letterSpacing: 1),
              decoration: const InputDecoration(hintText: '05xxxxxxxx', prefixIcon: Icon(Icons.phone_iphone_rounded)),
              onSubmitted: (_) => _send(channels.isEmpty ? null : channels.first),
            ),
            const SizedBox(height: 16),
            if (channels.isEmpty)
              FilledButton(
                onPressed: _busy ? null : () => _send(null),
                child: _busy ? const _Spinner() : Text(context.tr('أرسل الرمز', 'Send code')),
              ),
            for (final c in channels) ...[
              FilledButton.icon(
                style: c == 'whatsapp' ? FilledButton.styleFrom(backgroundColor: _whatsappGreen) : null,
                onPressed: _busy ? null : () => _send(c),
                icon: Icon(c == 'whatsapp' ? Icons.chat_rounded : Icons.sms_outlined),
                label: Text(c == 'whatsapp'
                    ? context.tr('أرسل الرمز على واتساب', 'Send code on WhatsApp')
                    : context.tr('أرسل الرمز برسالة SMS', 'Send code by SMS')),
              ),
              const SizedBox(height: 10),
            ],
            const SizedBox(height: 6),
            InkWell(
              onTap: () => context.push('/policies'),
              child: Text(
                context.tr('بالمتابعة أنت توافق على الشروط وسياسة الخصوصية', 'By continuing you agree to the terms and privacy policy'),
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 12, color: C.muted, decoration: TextDecoration.underline),
              ),
            ),
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
              TextField(controller: _name, decoration: InputDecoration(labelText: context.tr('اسمك', 'Your name'))),
            ],
            const SizedBox(height: 16),
            FilledButton(
              onPressed: _busy ? null : _verify,
              child: _busy ? const _Spinner() : Text(context.tr('دخول', 'Sign in')),
            ),
            const SizedBox(height: 8),
            Wrap(
              alignment: WrapAlignment.center,
              children: [
                TextButton(
                  onPressed: () => setState(() {
                    _sent = false;
                    _code.clear();
                  }),
                  child: Text(context.tr('غيّر الرقم', 'Change number')),
                ),
                TextButton(
                  onPressed: _wait > 0 || _busy ? null : () => _send(_channel.isEmpty ? null : _channel),
                  child: Text(_wait > 0
                      ? context.tr('إعادة الإرسال بعد $_wait ث', 'Resend in ${_wait}s')
                      : context.tr('أعد إرسال الرمز', 'Resend code')),
                ),
                if (channels.contains(other) && _channel.isNotEmpty)
                  TextButton(
                    onPressed: _wait > 0 || _busy ? null : () => _send(other),
                    child: Text(other == 'sms'
                        ? context.tr('أرسله برسالة SMS بدلاً', 'Send by SMS instead')
                        : context.tr('أرسله على واتساب بدلاً', 'Send on WhatsApp instead')),
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
