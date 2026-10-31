import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../l10n/lang.dart';
import '../services/push_service.dart';
import '../theme.dart';

/// First launch only: welcome, then the system dialogs for notifications and location.
class Onboarding {
  static const _k = 'onboarded_v1';
  static bool done = true;

  static Future<void> load(SharedPreferences prefs) async => done = prefs.getBool(_k) ?? false;

  static Future<void> finish() async {
    done = true;
    try {
      (await SharedPreferences.getInstance()).setBool(_k, true);
    } catch (_) {}
  }
}

class WelcomeScreen extends ConsumerStatefulWidget {
  const WelcomeScreen({super.key});

  @override
  ConsumerState<WelcomeScreen> createState() => _WelcomeScreenState();
}

class _WelcomeScreenState extends ConsumerState<WelcomeScreen> {
  int _step = 0; // 0 welcome, 1 notifications, 2 location
  bool _busy = false;

  Future<void> _next() async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      if (_step == 1) {
        await PushService.requestAndRegister(ProviderScope.containerOf(context, listen: false));
      } else if (_step == 2) {
        try {
          if (await Geolocator.isLocationServiceEnabled()) {
            final p = await Geolocator.checkPermission();
            if (p == LocationPermission.denied) await Geolocator.requestPermission();
          }
        } catch (_) {}
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
    _advance();
  }

  Future<void> _advance() async {
    if (_step < 2) {
      setState(() => _step++);
      return;
    }
    await Onboarding.finish();
    if (mounted) context.go('/');
  }

  @override
  Widget build(BuildContext context) {
    final pages = [
      (
        image: true,
        icon: Icons.pets_rounded,
        title: context.tr('أهلاً بك في دكان 👋', 'Welcome to Dokan 👋'),
        body: context.tr(
          'كل اللي يحتاجه حيوانك الأليف في مكان واحد: أكل، رمل، ألعاب وعناية — بأسعار دكان وتوصيل لباب بيتك.',
          'Everything your pet needs in one place: food, litter, toys and care — Dokan prices, delivered to your door.',
        ),
        cta: context.tr('يلا نبدأ', "Let's start"),
        skip: '',
      ),
      (
        image: false,
        icon: Icons.notifications_active_rounded,
        title: context.tr('خلّك متابع طلبك', 'Follow your order'),
        body: context.tr(
          'فعّل الإشعارات ونبلغك لما يتجهز طلبك وينشحن ويوصل، ونذكّرك قبل ما يخلص أكل حيوانك. بدون إزعاج.',
          "Turn on notifications and we'll tell you when your order is packed, shipped and delivered — and remind you before your pet's food runs out. No spam.",
        ),
        cta: context.tr('فعّل الإشعارات', 'Turn on notifications'),
        skip: context.tr('لاحقاً', 'Later'),
      ),
      (
        image: false,
        icon: Icons.location_on_rounded,
        title: context.tr('نوصل لبابك بالضبط', 'Right to your door'),
        body: context.tr(
          'اسمح بالموقع عشان نعبّي عنوانك تلقائياً ويوصل المندوب بدون اتصالات. نستخدمه وقت الطلب بس.',
          "Allow location so we fill in your address automatically and the driver finds you without calling. We only use it when you order.",
        ),
        cta: context.tr('اسمح بالموقع', 'Allow location'),
        skip: context.tr('لاحقاً', 'Later'),
      ),
    ];
    final p = pages[_step];
    return Scaffold(
      backgroundColor: C.ground,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(28, 16, 28, 24),
          child: Column(
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  for (var i = 0; i < 3; i++)
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 250),
                      margin: const EdgeInsets.symmetric(horizontal: 3),
                      width: i == _step ? 22 : 8,
                      height: 8,
                      decoration: BoxDecoration(
                        color: i == _step ? C.primary : C.line,
                        borderRadius: BorderRadius.circular(8),
                      ),
                    ),
                ],
              ),
              const Spacer(),
              AnimatedSwitcher(
                duration: const Duration(milliseconds: 300),
                child: p.image
                    ? Image.asset('assets/brand/logo_circle.png', key: const ValueKey('logo'), width: 190, height: 190)
                    : Container(
                        key: ValueKey(_step),
                        width: 150,
                        height: 150,
                        decoration: BoxDecoration(color: _step == 1 ? C.peach : C.mint, shape: BoxShape.circle),
                        child: Icon(p.icon, size: 70, color: _step == 1 ? C.accentText : C.primary),
                      ),
              ),
              const SizedBox(height: 34),
              Text(p.title, textAlign: TextAlign.center, style: display(26, weight: FontWeight.w700)),
              const SizedBox(height: 12),
              Text(p.body, textAlign: TextAlign.center, style: const TextStyle(fontSize: 15.5, height: 1.7, color: C.muted)),
              const Spacer(),
              FilledButton(
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56)),
                onPressed: _busy ? null : (_step == 0 ? _advance : _next),
                child: _busy
                    ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                    : Text(p.cta, style: const TextStyle(fontSize: 16)),
              ),
              SizedBox(
                height: 48,
                child: p.skip.isEmpty
                    ? null
                    : TextButton(onPressed: _busy ? null : _advance, child: Text(p.skip, style: const TextStyle(color: C.muted))),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
