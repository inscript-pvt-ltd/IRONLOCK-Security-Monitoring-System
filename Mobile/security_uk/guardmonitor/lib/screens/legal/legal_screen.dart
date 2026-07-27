import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show rootBundle;

import '../../theme/app_colors.dart';
import '../../theme/app_spacing.dart';
import '../../theme/app_typography.dart';
import '../../theme/responsive.dart';

/// The two bundled legal documents (source of truth: `assets/legal/*.md`).
enum LegalDoc {
  privacy('Privacy Policy', 'assets/legal/privacy_policy.md'),
  terms('Terms', 'assets/legal/terms.md');

  const LegalDoc(this.title, this.asset);
  final String title;
  final String asset;
}

/// Full-text viewer for the Privacy Policy + Terms & Conditions. Reachable from
/// the first-run privacy notice and the login footer. Renders the bundled
/// markdown with a tiny in-house block renderer (no extra dependency).
class LegalScreen extends StatelessWidget {
  const LegalScreen({super.key, this.initial = LegalDoc.privacy});

  /// Which tab to open on. `Navigator.push(... LegalScreen(initial: ...))`.
  final LegalDoc initial;

  static Future<void> open(BuildContext context,
          {LegalDoc initial = LegalDoc.privacy}) =>
      Navigator.of(context).push(
        MaterialPageRoute<void>(builder: (_) => LegalScreen(initial: initial)),
      );

  @override
  Widget build(BuildContext context) {
    return DefaultTabController(
      length: LegalDoc.values.length,
      initialIndex: initial.index,
      child: Scaffold(
        backgroundColor: AppColors.bg,
        appBar: AppBar(
          backgroundColor: AppColors.surface,
          foregroundColor: AppColors.text,
          elevation: 0,
          title: Text('Legal', style: AppType.h2.copyWith(fontSize: context.sp(20))),
          bottom: TabBar(
            indicatorColor: AppColors.gold,
            labelColor: AppColors.gold,
            unselectedLabelColor: AppColors.muted,
            labelStyle: AppType.label.copyWith(fontSize: context.sp(14)),
            tabs: [for (final d in LegalDoc.values) Tab(text: d.title)],
          ),
        ),
        body: TabBarView(
          children: [for (final d in LegalDoc.values) _DocView(doc: d)],
        ),
      ),
    );
  }
}

class _DocView extends StatelessWidget {
  const _DocView({required this.doc});
  final LegalDoc doc;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<String>(
      future: rootBundle.loadString(doc.asset),
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Center(child: CircularProgressIndicator());
        }
        if (!snap.hasData || snap.data!.isEmpty) {
          return Center(
            child: Text('Unable to load ${doc.title}.',
                style: AppType.body.copyWith(color: AppColors.muted)),
          );
        }
        return SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(
            context.s(AppSpacing.xl),
            context.s(AppSpacing.lg),
            context.s(AppSpacing.xl),
            context.s(AppSpacing.xxl),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: _renderMarkdown(context, snap.data!),
          ),
        );
      },
    );
  }
}

/// Minimal block-level markdown → widgets. Handles `#`/`##`/`###` headings,
/// `- ` bullets, blank-line spacing, and plain paragraphs. No inline styling —
/// legal text doesn't need it, and this keeps the app dependency-free.
List<Widget> _renderMarkdown(BuildContext context, String source) {
  final out = <Widget>[];
  for (final raw in source.split('\n')) {
    final line = raw.trimRight();
    if (line.isEmpty) {
      out.add(SizedBox(height: context.s(10)));
    } else if (line.startsWith('### ')) {
      out.add(Padding(
        padding: EdgeInsets.only(top: context.s(12), bottom: context.s(4)),
        child: Text(line.substring(4),
            style: AppType.bodySemi.copyWith(fontSize: context.sp(15))),
      ));
    } else if (line.startsWith('## ')) {
      out.add(Padding(
        padding: EdgeInsets.only(top: context.s(18), bottom: context.s(6)),
        child: Text(line.substring(3),
            style: AppType.h2
                .copyWith(fontSize: context.sp(18), color: AppColors.gold)),
      ));
    } else if (line.startsWith('# ')) {
      out.add(Padding(
        padding: EdgeInsets.only(bottom: context.s(8)),
        child: Text(line.substring(2),
            style: AppType.h1.copyWith(fontSize: context.sp(24))),
      ));
    } else if (line.startsWith('- ')) {
      out.add(Padding(
        padding: EdgeInsets.only(bottom: context.s(6), left: context.s(4)),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('•  ',
                style: AppType.body.copyWith(
                    fontSize: context.sp(15), color: AppColors.gold)),
            Expanded(
              child: Text(line.substring(2),
                  style: AppType.body.copyWith(
                      fontSize: context.sp(15),
                      color: AppColors.muted,
                      height: 1.45)),
            ),
          ],
        ),
      ));
    } else {
      out.add(Padding(
        padding: EdgeInsets.only(bottom: context.s(6)),
        child: Text(line,
            style: AppType.body.copyWith(
                fontSize: context.sp(15),
                color: AppColors.muted,
                height: 1.5)),
      ));
    }
  }
  return out;
}
