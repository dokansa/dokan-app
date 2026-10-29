import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../state/providers.dart';
import '../theme.dart';

void showMessage(BuildContext context, Object message, {bool error = false}) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      content: Text(message.toString()),
      backgroundColor: error ? const Color(0xFF8C2F0A) : C.ink,
    ));
}

/// Network image with a soft tinted placeholder.
class NetImage extends StatelessWidget {
  const NetImage(this.url, {super.key, this.tint = C.peach, this.radius = 14, this.fit = BoxFit.contain, this.padding = 8});
  final String? url;
  final Color tint;
  final double radius;
  final BoxFit fit;
  final double padding;

  @override
  Widget build(BuildContext context) {
    final placeholder = Center(child: Icon(Icons.pets_rounded, color: C.ink.withValues(alpha: 0.25), size: 32));
    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: ColoredBox(
        // Product photos sit on plain white (no colored card behind them); the tint is only
        // for the empty placeholder.
        color: url == null || url!.isEmpty ? tint : C.surface,
        child: url == null || url!.isEmpty
            ? placeholder
            : Padding(
                padding: EdgeInsets.all(padding),
                child: CachedNetworkImage(
                  imageUrl: url!,
                  fit: fit,
                  placeholder: (_, __) => placeholder,
                  errorWidget: (_, __, ___) => placeholder,
                ),
              ),
      ),
    );
  }
}

class SectionHeader extends StatelessWidget {
  const SectionHeader(this.title, {super.key, this.action, this.onAction});
  final String title;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: Row(
        children: [
          Expanded(child: Text(title, style: display(17))),
          if (action != null)
            TextButton(
              onPressed: onAction,
              style: TextButton.styleFrom(foregroundColor: C.primary, minimumSize: const Size(44, 44)),
              child: Text(action!, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
            ),
        ],
      ),
    );
  }
}

class PetAvatar extends StatelessWidget {
  const PetAvatar({super.key, required this.pet, this.size = 58, this.selected = false, this.tint});
  final Pet pet;
  final double size;
  final bool selected;
  final Color? tint;

  @override
  Widget build(BuildContext context) {
    final color = tint ?? C.tints[pet.name.hashCode.abs() % C.tints.length];
    return Container(
      width: size,
      height: size,
      padding: const EdgeInsets.all(3),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: selected ? C.accentStrong : Colors.transparent, width: 2.5),
      ),
      child: CircleAvatar(
        backgroundColor: color,
        backgroundImage: pet.photo != null && pet.photo!.isNotEmpty ? CachedNetworkImageProvider(pet.photo!) : null,
        child: pet.photo != null && pet.photo!.isNotEmpty
            ? null
            : Text(pet.name.isEmpty ? '?' : pet.name.characters.first, style: display(size * 0.34, weight: FontWeight.w700)),
      ),
    );
  }
}

class PriceText extends StatelessWidget {
  const PriceText(this.product, {super.key, this.size = 14});
  final Product product;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        if (product.onSale && product.regularPrice.minor > product.price.minor)
          Text(product.regularPrice.label,
              style: TextStyle(fontSize: size * 0.8, color: C.muted, decoration: TextDecoration.lineThrough)),
        Text(product.price.label, style: display(size, weight: FontWeight.w700)),
      ],
    );
  }
}

class ProductCard extends ConsumerStatefulWidget {
  const ProductCard(this.product, {super.key, this.index = 0, this.width = 160});
  final Product product;
  final int index;
  final double? width;

  @override
  ConsumerState<ProductCard> createState() => _ProductCardState();
}

class _ProductCardState extends ConsumerState<ProductCard> {
  bool _busy = false;

  Future<void> _quickAdd() async {
    final p = widget.product;
    if (p.isVariable) {
      context.push('/product/${p.id}');
      return;
    }
    setState(() => _busy = true);
    try {
      await ref.read(cartProvider.notifier).add(p.id, 1);
      if (mounted) showMessage(context, context.tr('انضاف للسلة', 'Added to cart'));
    } catch (e) {
      if (mounted) showMessage(context, e, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = widget.product;
    final tint = C.tints[widget.index % C.tints.length];
    return SizedBox(
      width: widget.width,
      child: Material(
        color: C.surface,
        borderRadius: BorderRadius.circular(R.card),
        child: InkWell(
          borderRadius: BorderRadius.circular(R.card),
          onTap: () => context.push('/product/${p.id}'),
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                AspectRatio(
                  aspectRatio: 4 / 3,
                  child: Stack(
                    fit: StackFit.expand,
                    children: [
                      NetImage(p.image, tint: tint),
                      if (p.onSale)
                        PositionedDirectional(
                          top: 8,
                          start: 8,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(color: C.accentText, borderRadius: BorderRadius.circular(R.chip)),
                            child: Text(context.tr('خصم', 'Sale'),
                                style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 8),
                Text(p.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600, height: 1.35)),
                const SizedBox(height: 6),
                Row(
                  children: [
                    Expanded(child: PriceText(p)),
                    Semantics(
                      button: true,
                      label: context.tr('أضف للسلة', 'Add to cart'),
                      child: InkResponse(
                        onTap: _busy ? null : _quickAdd,
                        radius: 24,
                        child: Container(
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(color: C.primary, borderRadius: BorderRadius.circular(11)),
                          child: _busy
                              ? const Padding(
                                  padding: EdgeInsets.all(9),
                                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                                )
                              : const Icon(Icons.add_rounded, color: Colors.white, size: 20),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// White rounded row with an icon tile, title, subtitle and a switch.
class ToggleRow extends StatelessWidget {
  const ToggleRow({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.value,
    required this.onChanged,
    this.tint = C.mint,
    this.iconColor = C.primary,
  });
  final IconData icon;
  final String title;
  final String subtitle;
  final bool value;
  final ValueChanged<bool> onChanged;
  final Color tint;
  final Color iconColor;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: C.surface,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: () => onChanged(!value),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: tint, borderRadius: BorderRadius.circular(12)),
                child: Icon(icon, color: iconColor, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w700)),
                    Text(subtitle, style: const TextStyle(fontSize: 12, color: C.muted)),
                  ],
                ),
              ),
              Switch(value: value, onChanged: onChanged, activeTrackColor: C.primary),
            ],
          ),
        ),
      ),
    );
  }
}

class RoundIconButton extends StatelessWidget {
  const RoundIconButton({super.key, required this.icon, required this.label, this.onTap, this.color = C.surface});
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: label,
      child: Material(
        color: color,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap ?? () => context.pop(),
          child: SizedBox(width: 46, height: 46, child: Icon(icon, color: C.ink, size: 22)),
        ),
      ),
    );
  }
}

class LoadingBox extends StatelessWidget {
  const LoadingBox({super.key, this.height = 160});
  final double height;
  @override
  Widget build(BuildContext context) =>
      SizedBox(height: height, child: const Center(child: CircularProgressIndicator(color: C.primary)));
}

class ErrorBox extends StatelessWidget {
  const ErrorBox(this.error, {super.key, this.onRetry});
  final Object error;
  final VoidCallback? onRetry;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(error.toString(), textAlign: TextAlign.center, style: const TextStyle(color: C.muted)),
            if (onRetry != null) TextButton(onPressed: onRetry, child: Text(context.tr('حاول مرة ثانية', 'Try again'))),
          ],
        ),
      );
}

/// Asks the customer to log in, then runs [then] if they did.
Future<bool> requireLogin(BuildContext context, WidgetRef ref) async {
  if (ref.read(sessionProvider) != null) return true;
  final ok = await context.push<bool>('/login');
  return ok == true;
}
