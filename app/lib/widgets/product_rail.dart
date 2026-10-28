import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../theme.dart';
import 'common.dart';

/// A titled, horizontally scrolling row of product cards.
class ProductRail extends StatelessWidget {
  const ProductRail({
    super.key,
    required this.title,
    required this.products,
    this.action,
    this.onAction,
    this.icon,
    this.hideWhenEmpty = true,
    this.onRetry,
  });

  final String title;
  final AsyncValue<List<Product>> products;
  final String? action;
  final VoidCallback? onAction;
  final IconData? icon;
  final bool hideWhenEmpty;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final list = products.valueOrNull;
    if (hideWhenEmpty && products.hasValue && (list == null || list.isEmpty)) return const SizedBox.shrink();
    if (hideWhenEmpty && products.hasError) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Row(
              children: [
                if (icon != null) ...[Icon(icon, size: 20, color: C.accentText), const SizedBox(width: 6)],
                Expanded(child: Text(title, style: display(17))),
                if (action != null)
                  TextButton(
                    onPressed: onAction,
                    style: TextButton.styleFrom(foregroundColor: C.primary, minimumSize: const Size(44, 44)),
                    child: Text(action!, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 4),
          products.when(
            loading: () => const LoadingBox(height: 250),
            error: (e, _) => ErrorBox(e, onRetry: onRetry),
            data: (items) => items.isEmpty
                ? Padding(
                    padding: const EdgeInsets.all(20),
                    child: Text(context.tr('ما فيه منتجات هنا للحين', 'Nothing here yet'),
                        style: const TextStyle(color: C.muted)),
                  )
                : SizedBox(
                    height: 262,
                    child: ListView.separated(
                      scrollDirection: Axis.horizontal,
                      padding: const EdgeInsets.symmetric(horizontal: 20),
                      itemCount: items.length,
                      separatorBuilder: (_, __) => const SizedBox(width: 12),
                      itemBuilder: (_, i) => ProductCard(items[i], index: i),
                    ),
                  ),
          ),
        ],
      ),
    );
  }
}
