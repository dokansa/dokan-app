import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../l10n/lang.dart';
import '../models/models.dart';
import '../state/providers.dart';
import '../theme.dart';
import '../widgets/common.dart';

IconData _iconFor(String key) {
  switch (key) {
    case 'privacy':
      return Icons.privacy_tip_outlined;
    case 'returns':
      return Icons.assignment_return_outlined;
    case 'shipping':
      return Icons.local_shipping_outlined;
    case 'terms':
      return Icons.gavel_rounded;
    case 'complaints':
      return Icons.report_gmailerrorred_rounded;
  }
  return Icons.description_outlined;
}

class PoliciesScreen extends ConsumerWidget {
  const PoliciesScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(configProvider);
    return Scaffold(
      appBar: AppBar(
        title: Text(context.tr('السياسات والشروط', 'Policies & terms')),
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')),
        ),
      ),
      body: config.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(configProvider)),
        data: (c) {
          final list = c.policies.where((p) => p.pageId != null || (p.contentHtml ?? '').isNotEmpty).toList();
          if (list.isEmpty) {
            return Center(child: Text(context.tr('ما فيه سياسات منشورة للحين', 'No policies published yet'),
                style: const TextStyle(color: C.muted)));
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
            itemCount: list.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (_, i) {
              final p = list[i];
              return Material(
                color: C.surface,
                borderRadius: BorderRadius.circular(R.card),
                child: ListTile(
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                  leading: CircleAvatar(backgroundColor: C.mint, child: Icon(_iconFor(p.key), color: C.primary)),
                  title: Text(p.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                  trailing: const Icon(Icons.chevron_right_rounded),
                  onTap: () => context.push('/policy/${p.key}'),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

class PolicyScreen extends ConsumerWidget {
  const PolicyScreen({super.key, required this.policyKey});
  final String policyKey;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(configProvider).valueOrNull;
    final policy = config?.policies.where((p) => p.key == policyKey).firstOrNull;
    final page = policy?.pageId == null ? null : ref.watch(pageProvider(policy!.pageId!));

    Widget body;
    if (policy == null) {
      body = const LoadingBox();
    } else if ((policy.contentHtml ?? '').isNotEmpty && page == null) {
      body = _Html(html: policy.contentHtml!);
    } else if (page != null) {
      body = page.when(
        loading: () => const LoadingBox(),
        error: (e, _) => ErrorBox(e, onRetry: () => ref.invalidate(pageProvider(policy.pageId!))),
        data: (p) => _Html(html: p.html),
      );
    } else {
      body = const SizedBox.shrink();
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(policy?.title ?? ''),
        leading: Padding(
          padding: const EdgeInsets.all(5),
          child: RoundIconButton(icon: Icons.arrow_back_rounded, label: context.tr('رجوع', 'Back')),
        ),
      ),
      body: body,
    );
  }
}

class _Html extends StatelessWidget {
  const _Html({required this.html});
  final String html;

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(20, 8, 20, 40),
      child: Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(color: C.surface, borderRadius: BorderRadius.circular(R.card)),
        child: HtmlWidget(
          html,
          textStyle: const TextStyle(fontSize: 15, height: 1.7, color: C.ink),
          onTapUrl: (url) => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
        ),
      ),
    );
  }
}

/// One line under the checkout button linking to the terms and policies.
class PolicyConsentLine extends StatelessWidget {
  const PolicyConsentLine({super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: InkWell(
        onTap: () => context.push('/policies'),
        child: Text(
          context.tr('بتأكيد الطلب أنت توافق على الشروط وسياسة الاسترجاع والخصوصية',
              'By placing the order you agree to the terms, returns and privacy policies'),
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 11, color: C.muted, decoration: TextDecoration.underline),
        ),
      ),
    );
  }
}
