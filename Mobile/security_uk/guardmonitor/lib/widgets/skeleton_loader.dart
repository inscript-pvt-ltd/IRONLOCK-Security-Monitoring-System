import 'package:flutter/material.dart';

class SkeletonLoader extends StatefulWidget {
  const SkeletonLoader({
    super.key,
    required this.child,
    this.visible = true,
  });

  final Widget child;
  final bool visible;

  @override
  State<SkeletonLoader> createState() => _SkeletonLoaderState();
}

class _SkeletonLoaderState extends State<SkeletonLoader>
    with SingleTickerProviderStateMixin {
  late AnimationController _shimmerCtrl;

  @override
  void initState() {
    super.initState();
    _shimmerCtrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1300),
    );
    if (widget.visible) {
      _shimmerCtrl.repeat();
    }
  }

  @override
  void didUpdateWidget(SkeletonLoader oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.visible && !oldWidget.visible) {
      _shimmerCtrl.repeat();
    } else if (!widget.visible && oldWidget.visible) {
      _shimmerCtrl.stop();
    }
  }

  @override
  void dispose() {
    _shimmerCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.visible) {
      return widget.child;
    }

    return Stack(
      children: [
        widget.child,
        AnimatedBuilder(
          animation: _shimmerCtrl,
          builder: (context, _) {
            return Container(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment(-1.0 - _shimmerCtrl.value * 2, 0),
                  end: Alignment(1.0 - _shimmerCtrl.value * 2, 0),
                  colors: const [
                    Color(0xFF0F172A),
                    Color(0xFF182840),
                    Color(0xFF0F172A),
                  ],
                  stops: const [0.0, 0.5, 1.0],
                ),
              ),
            );
          },
        ),
      ],
    );
  }
}
