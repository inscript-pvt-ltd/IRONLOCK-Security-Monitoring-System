import 'package:flutter/material.dart';

/// Staggered list animation for entry effects on children
class StaggeredListAnimation extends StatelessWidget {
  const StaggeredListAnimation({
    super.key,
    required this.children,
    this.direction = Axis.vertical,
  });

  final List<Widget> children;
  final Axis direction;

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween<double>(begin: 0, end: 1),
      duration: Duration(
        milliseconds: 300 + (children.length * 50),
      ),
      builder: (context, value, child) {
        return Stack(
          children: List.generate(
            children.length,
            (index) {
              final delay = index * 50 / 1000;
              final animationValue = (value - delay).clamp(0.0, 1.0);

              return _AnimatedChild(
                opacity: animationValue,
                offset: Offset(
                  direction == Axis.horizontal ? (1 - animationValue) * 20 : 0,
                  direction == Axis.vertical ? (1 - animationValue) * 20 : 0,
                ),
                child: children[index],
              );
            },
          ),
        );
      },
    );
  }
}

class _AnimatedChild extends StatelessWidget {
  const _AnimatedChild({
    required this.opacity,
    required this.offset,
    required this.child,
  });

  final double opacity;
  final Offset offset;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Opacity(
      opacity: opacity,
      child: Transform.translate(
        offset: offset,
        child: child,
      ),
    );
  }
}

/// Page transition with fade and slide
class PageTransitionBuilder extends PageTransitionsBuilder {
  @override
  Widget buildTransitions<T>(
    PageRoute<T> route,
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) {
    return SlideTransition(
      position: Tween<Offset>(
        begin: const Offset(0, 0.05),
        end: Offset.zero,
      ).animate(
        CurvedAnimation(parent: animation, curve: Curves.easeOut),
      ),
      child: FadeTransition(
        opacity: animation,
        child: child,
      ),
    );
  }
}
