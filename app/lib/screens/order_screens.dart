import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart' as intl;
import 'package:url_launcher/url_launcher.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../router.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/permission_prompts.dart';

String _date(DateTime? d) => d == null
    ? ''
    : intl.DateFormat(AppLang.isAr ? 'd MMM، h:mm a' : 'd MMM, h:mm a', AppLang.code).format(d.toLocal());

class OrdersScreen extends ConsumerWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider);
    final orders = ref.watch(ordersProvider);
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('طلباتي', 'My orders'))),
      body: user == null
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(32),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Text(context.tr('سجّل دخولك عشان تشوف طلباتك', 'Log in to see your orders'), style: const TextStyle(color: C.muted)),
                  const SizedBox(height: 16),
                  FilledButton(
                    style: FilledButton.styleFrom(minimumSize: const Size(200, 52)),
                    onPressed: () => context.push('/login'),
                    child: Text(context.tr('تسجيل الدخول', 'Log in')),
                  ),
                ]),
              ),
            )
          : RefreshIndicator(
              color: C.primary,
              onRefresh: () => ref.refresh(ordersProvider.future),
              child: orders.when(
                loading: () => const LoadingBox(),
                error: (e, _) => ListView(children: [ErrorBox(e, onRetry: () => ref.invalidate(ordersProvider))]),
                data: (list) => list.isEmpty
                    ? ListView(children: [
                        const SizedBox(height: 120),
                        Center(
                          child: Text(context.tr('ما عندك طلبات للحين', 'No orders yet'),
                              style: const TextStyle(color: C.muted)),
                        ),
                      ])
                    : ListView.separated(
                        padding: const EdgeInsets.fromLTRB(20, 4, 20, kTabBarClearance),
                        itemCount: list.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 10),
                        itemBuilder: (_, i) => _OrderRow(order: list[i]),
                      ),
              ),
            ),
    );
  }
}

class _OrderRow extends StatelessWidget {
  const _OrderRow({required this.order});
  final OrderSummary order;

  @override
  Widget build(BuildContext context) {
    final active = !{'completed', 'cancelled', 'refunded', 'failed'}.contains(order.status);
    return Material(
      color: C.surface,
      borderRadius: BorderRadius.circular(R.card),
      child: InkWell(
        borderRadius: BorderRadius.circular(R.card),
        onTap: () => context.push('/order/${order.id}'),
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              SizedBox(width: 60, height: 60, child: NetImage(order.image, tint: active ? C.peach : C.mint)),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(context.tr('طلب #${order.number}', 'Order #${order.number}'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    Text('${_date(order.date)} · ${context.tr('${order.itemCount} منتج', '${order.itemCount} items')}', style: const TextStyle(fontSize: 12, color: C.muted)),
                    const SizedBox(height: 4),
                    Text(order.statusLabel,
                        style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: active ? C.accentText : C.primary)),
                  ],
                ),
              ),
              Text('${order.total} ${currencyLabel(context.isAr)}', style: display(14, weight: FontWeight.w700)),
            ],
          ),
        ),
      ),
    );
  }
}

class OrderScreen extends ConsumerStatefulWidget {
  const OrderScreen({super.key, required this.id, this.justPlaced = false});
  final int id;
  final bool justPlaced;

  @override
  ConsumerState<OrderScreen> createState() => _OrderScreenState();
}

class _OrderScreenState extends ConsumerState<OrderScreen> {
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    if (widget.justPlaced) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) askNotifications(context);
      });
    }
  }

  List<(String, String)> _stages(BuildContext context) => [
        (context.tr('تم استلام الطلب', 'Order received'), context.tr('وصلنا طلبك وجهزنا الفاتورة', 'We got your order and made the invoice')),
        (context.tr('قيد التنفيذ', 'Processing'), context.tr('نجهّز طلبك في المستودع', 'Packing your order at the warehouse')),
        (context.tr('تم الشحن', 'Shipped'), context.tr('طلع مع شركة الشحن ومعه رقم تتبع', 'With the courier, tracking number included')),
        (context.tr('في الطريق إليك', 'On its way'), context.tr('المندوب بيتواصل معك', 'The driver will contact you')),
        (context.tr('تم التوصيل', 'Delivered'), context.tr('بالعافية على حيوانك', 'Hope your pet enjoys it')),
      ];

  List<String> _heads(BuildContext context) => [
        context.tr('استلمنا طلبك', 'We got your order'),
        context.tr('نجهّز طلبك', 'Packing your order'),
        context.tr('طلبك انشحن', 'Your order has shipped'),
        context.tr('طلبك في الطريق', 'Your order is on its way'),
        context.tr('وصل طلبك', 'Your order arrived'),
      ];

  Future<void> _reorder(OrderDetail o) async {
    setState(() => _busy = true);
    try {
      final r = await ref.read(appApiProvider).reorder(o.summary.id);
      final cart = ref.read(cartProvider.notifier);
      if (r.items.isNotEmpty) {
        for (final (id, qty) in r.items) {
          await cart.add(id, qty);
        }
      } else {
        await cart.refresh();
      }
      if (mounted) context.go('/cart');
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final order = ref.watch(orderProvider(widget.id));
    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr(
          'طلب #${order.valueOrNull?.summary.number ?? widget.id}',
          'Order #${order.valueOrNull?.summary.number ?? widget.id}',
        )),
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(
            icon: Icons.arrow_back_rounded,
            label: context.tr('رجوع', 'Back'),
            onTap: () => context.canPop() ? context.pop() : context.go('/orders'),
          ),
        ),
      ),
      body: order.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(orderProvider(widget.id))),
        data: (o) => RefreshIndicator(
          color: C.primary,
          onRefresh: () => ref.refresh(orderProvider(widget.id).future),
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 40),
            children: [
              const NotifyPromptCard(),
              if (widget.justPlaced)
                Container(
                  margin: const EdgeInsets.only(bottom: 12),
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: C.mint, borderRadius: BorderRadius.circular(18)),
                  child: Row(children: [
                    const Icon(Icons.check_circle_rounded, color: C.success),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        context.tr('تم الطلب، شكراً لك! بنرسل لك إشعار مع كل تحديث.', "Order placed, thank you! We'll notify you at every step."),
                        style: const TextStyle(fontWeight: FontWeight.w700),
                      ),
                    ),
                  ]),
                ),
              _StatusCard(order: o, heads: _heads(context)),
              const SizedBox(height: 16),
              if (o.stage > 0) _Timeline(order: o, stages: _stages(context)),
              const SizedBox(height: 16),
              Row(children: [
                if (o.tracking?.url != null && o.tracking!.url!.isNotEmpty) ...[
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () => launchUrl(Uri.parse(o.tracking!.url!), mode: LaunchMode.externalApplication),
                      icon: const Icon(Icons.local_shipping_outlined, size: 18),
                      label: Text(context.tr('تتبع الشحنة', 'Track shipment'), style: const TextStyle(fontSize: 14)),
                    ),
                  ),
                  const SizedBox(width: 10),
                ],
                Expanded(
                  child: FilledButton.icon(
                    style: FilledButton.styleFrom(backgroundColor: C.surface, foregroundColor: C.ink),
                    onPressed: () => context.push('/complaint-new?order=${o.summary.id}'),
                    icon: const Icon(Icons.chat_bubble_outline_rounded, size: 18),
                    label: Text(context.tr('عندي مشكلة', 'I have a problem'), style: const TextStyle(fontSize: 14)),
                  ),
                ),
              ]),
              const SizedBox(height: 10),
              Text(
                o.canCancel
                    ? context.tr('تقدر تطلب إلغاء الطلب ما دام قيد التنفيذ.', 'You can request a cancellation while the order is processing.')
                    : context.tr('بعد الشحن ما يمكن إلغاء الطلب — لو فيه مشكلة كلمنا.', "Orders can't be cancelled once shipped — if something's wrong, talk to us."),
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 12, color: C.muted),
              ),
              const SizedBox(height: 20),
              Text(context.tr('المنتجات', 'Items'), style: display(16)),
              const SizedBox(height: 8),
              for (final l in o.items)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(children: [
                    SizedBox(width: 52, height: 52, child: NetImage(l.image)),
                    const SizedBox(width: 10),
                    Expanded(child: Text('${l.name} × ${l.quantity}', style: const TextStyle(fontSize: 13))),
                    Text('${l.total} ${currencyLabel(context.isAr)}', style: const TextStyle(fontWeight: FontWeight.w700)),
                  ]),
                ),
              const Divider(color: C.line),
              _line(context.tr('الشحن', 'Shipping'), '${o.shippingTotal} ${currencyLabel(context.isAr)}'),
              for (final (name, total) in o.fees) _line(name, '$total ${currencyLabel(context.isAr)}'),
              _line(context.tr('طريقة الدفع', 'Payment method'), o.paymentTitle),
              _line(context.tr('الإجمالي', 'Total'), '${o.summary.total} ${currencyLabel(context.isAr)}', bold: true),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _busy ? null : () => _reorder(o),
                icon: const Icon(Icons.autorenew_rounded),
                label: Text(context.tr('اطلبه مرة ثانية', 'Order again')),
              ),
              if (o.canCancel) ...[
                const SizedBox(height: 8),
                TextButton(
                  onPressed: _busy
                      ? null
                      : () async {
                          await context.push('/complaint-new?order=${o.summary.id}&cat=cancel');
                          ref.invalidate(orderProvider(o.summary.id));
                        },
                  style: TextButton.styleFrom(foregroundColor: C.accentText, minimumSize: const Size.fromHeight(48)),
                  child: Text(context.tr('طلب إلغاء الطلب', 'Request cancellation')),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  Widget _line(String a, String b, {bool bold = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [
          Expanded(child: Text(a, style: TextStyle(color: bold ? C.ink : C.muted, fontWeight: bold ? FontWeight.w700 : FontWeight.w400))),
          Text(b, style: TextStyle(fontWeight: bold ? FontWeight.w700 : FontWeight.w500)),
        ]),
      );
}

class _StatusCard extends StatelessWidget {
  const _StatusCard({required this.order, required this.heads});
  final OrderDetail order;
  final List<String> heads;

  @override
  Widget build(BuildContext context) {
    final stage = order.stage;
    final head = stage >= 1 && stage <= 5 ? heads[stage - 1] : order.summary.statusLabel;
    return Container(
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(color: C.ink, borderRadius: BorderRadius.circular(26)),
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(context.tr('الحالة الآن', 'Current status'), style: const TextStyle(fontSize: 13, color: Color(0xFFC9D3D0))),
          const SizedBox(height: 6),
          Text(head, style: display(26, weight: FontWeight.w700, color: Colors.white)),
          if (order.tracking?.number != null && order.tracking!.number!.isNotEmpty) ...[
            const SizedBox(height: 4),
            Text('${order.tracking!.carrier ?? context.tr('رقم التتبع', 'Tracking number')}: ${order.tracking!.number}',
                style: const TextStyle(fontSize: 13, color: Color(0xFFFFC8A1))),
          ],
          const SizedBox(height: 14),
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: LinearProgressIndicator(
              value: stage <= 0 ? 0 : stage / 5,
              minHeight: 8,
              color: C.accent,
              backgroundColor: const Color(0xFF2C3D3B),
            ),
          ),
        ],
      ),
    );
  }
}

class _Timeline extends StatelessWidget {
  const _Timeline({required this.order, required this.stages});
  final OrderDetail order;
  final List<(String, String)> stages;

  @override
  Widget build(BuildContext context) {
    final cur = order.stage;
    DateTime? dateFor(int s) {
      for (final t in order.timeline) {
        if (t.stage == s) return t.date;
      }
      return null;
    }

    return Container(
      padding: const EdgeInsets.fromLTRB(18, 18, 18, 6),
      decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(22)),
      child: Column(
        children: [
          for (var i = 0; i < stages.length; i++)
            IntrinsicHeight(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  SizedBox(
                    width: 24,
                    child: Column(children: [
                      Container(
                        width: 24,
                        height: 24,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          color: i + 1 <= cur ? (i + 1 == cur ? C.accentStrong : C.primary) : C.surface,
                          border: i + 1 <= cur ? null : Border.all(color: const Color(0xFFC9CCC6), width: 2),
                        ),
                        child: i + 1 <= cur ? const Icon(Icons.check_rounded, size: 14, color: Colors.white) : null,
                      ),
                      if (i < stages.length - 1)
                        Expanded(
                          child: Container(
                            width: 2,
                            margin: const EdgeInsets.symmetric(vertical: 4),
                            color: i + 1 < cur ? C.primary : const Color(0xFFE2E1DA),
                          ),
                        ),
                    ]),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Padding(
                      padding: const EdgeInsets.only(bottom: 18),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(stages[i].$1,
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: i + 1 == cur ? FontWeight.w700 : FontWeight.w600,
                                color: i + 1 <= cur ? C.ink : C.muted,
                              )),
                          Text(dateFor(i + 1) != null ? _date(dateFor(i + 1)) : stages[i].$2,
                              style: const TextStyle(fontSize: 12, color: C.muted)),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
