import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../l10n/lang.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

class PaymentArgs {
  PaymentArgs({required this.url, required this.orderId});
  final String url;
  final int orderId;
}

/// Opens the gateway's own page (MyFatoorah / Tabby / Tamara) in the phone's secure browser
/// (Chrome Custom Tab / Safari), not inside the app. That browser supports Google Pay,
/// Apple Pay, mada 3-D Secure and bank apps, which an in-app WebView blocks — the reason many
/// customers could not finish paying. The app follows the order on the store and closes this
/// screen by itself once the store marks it paid.
class PaymentScreen extends ConsumerStatefulWidget {
  const PaymentScreen({super.key, required this.args});
  final PaymentArgs args;

  @override
  ConsumerState<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends ConsumerState<PaymentScreen> with WidgetsBindingObserver {
  static const _paid = {'processing', 'completed', 'on-hold', 'shipped', 'delivered', 'out-for-delivery', 'ready'};
  static const _dead = {'cancelled', 'failed', 'refunded', 'trash'};

  Timer? _timer;
  bool _done = false;
  bool _checking = false;
  bool _opened = false;
  String _status = 'pending';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _open());
    _timer = Timer.periodic(const Duration(seconds: 4), (_) => _check());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Back from the payment browser: check straight away.
    if (state == AppLifecycleState.resumed) _check();
  }

  Future<void> _open() async {
    final uri = Uri.parse(widget.args.url);
    var ok = false;
    try {
      ok = await launchUrl(uri, mode: LaunchMode.inAppBrowserView);
    } catch (_) {}
    if (!ok) {
      try {
        ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
      } catch (_) {}
    }
    if (mounted) setState(() => _opened = ok);
    if (!ok && mounted) {
      showMessage(context, context.tr('تعذّر فتح صفحة الدفع', 'Could not open the payment page'), error: true);
    }
  }

  Future<void> _check({bool manual = false}) async {
    if (_done || _checking) return;
    _checking = true;
    if (manual && mounted) setState(() {});
    try {
      final o = await ref.read(appApiProvider).order(widget.args.orderId);
      final st = o.summary.status.replaceFirst('wc-', '');
      if (!mounted) return;
      setState(() => _status = st);
      if (_paid.contains(st)) {
        _finish(true);
      } else if (_dead.contains(st)) {
        _finish(false);
      } else if (manual) {
        showMessage(
          context,
          context.tr('لسا ما وصلنا الدفع. إذا دفعت، انتظر ثواني وحاول مرة ثانية.',
              "We haven't received the payment yet. If you paid, wait a few seconds and try again."),
        );
      }
    } catch (_) {
      // Network hiccup: the next tick tries again.
    } finally {
      _checking = false;
      if (manual && mounted) setState(() {});
    }
  }

  void _finish(bool ok) {
    if (_done || !mounted) return;
    _done = true;
    _timer?.cancel();
    try {
      closeInAppWebView();
    } catch (_) {}
    Navigator.of(context).pop(ok);
  }

  Future<void> _confirmClose() async {
    final leave = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.tr('تلغي الدفع؟', 'Cancel payment?')),
        content: Text(ctx.tr(
          'طلبك محفوظ، وتقدر تكمل الدفع بعدين من طلباتي.',
          'Your order is saved. You can finish paying later from My orders.',
        )),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(ctx.tr('أكمل الدفع', 'Continue paying'))),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: Text(ctx.tr('اطلع', 'Leave'))),
        ],
      ),
    );
    if (leave == true) {
      await _check();
      _finish(false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _confirmClose();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.tr('الدفع · طلب #${widget.args.orderId}', 'Payment · Order #${widget.args.orderId}')),
          leading: Padding(
            padding: const EdgeInsets.all(5),
            child: RoundIconButton(icon: Icons.close_rounded, label: context.tr('إغلاق', 'Close'), onTap: _confirmClose),
          ),
        ),
        body: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(28, 24, 28, 24),
            child: Column(
              children: [
                const Spacer(),
                Container(
                  width: 120,
                  height: 120,
                  decoration: const BoxDecoration(color: C.mint, shape: BoxShape.circle),
                  child: const Icon(Icons.lock_outline_rounded, size: 56, color: C.primary),
                ),
                const SizedBox(height: 26),
                Text(
                  context.tr('أكمل الدفع في صفحة الدفع الآمنة', 'Finish paying on the secure payment page'),
                  textAlign: TextAlign.center,
                  style: display(21, weight: FontWeight.w700),
                ),
                const SizedBox(height: 10),
                Text(
                  context.tr(
                    'فتحنا لك صفحة الدفع من مزوّد الدفع. بعد ما تدفع ارجع للتطبيق، ونأكد طلبك تلقائياً.',
                    'We opened the payment provider\'s page. After you pay, come back to the app and we\'ll confirm your order automatically.',
                  ),
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 14.5, height: 1.7, color: C.muted),
                ),
                const SizedBox(height: 18),
                Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
                  const SizedBox(width: 10),
                  Text(
                    context.tr('ننتظر تأكيد الدفع…', 'Waiting for payment confirmation…'),
                    style: const TextStyle(color: C.primary, fontWeight: FontWeight.w600),
                  ),
                ]),
                const Spacer(),
                FilledButton.icon(
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54)),
                  onPressed: _checking ? null : () => _check(manual: true),
                  icon: const Icon(Icons.check_circle_rounded),
                  label: Text(context.tr('دفعت — تأكد من طلبي', "I've paid — check my order")),
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(50)),
                  onPressed: _open,
                  icon: const Icon(Icons.refresh_rounded),
                  label: Text(_opened
                      ? context.tr('افتح صفحة الدفع مرة ثانية', 'Open the payment page again')
                      : context.tr('افتح صفحة الدفع', 'Open the payment page')),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
