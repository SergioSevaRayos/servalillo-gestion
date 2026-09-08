import 'dart:math';

/// Backoff exponencial con tope y jitter. Se reinicia tras un éxito.
class ExponentialBackoff {
  ExponentialBackoff({
    this.base = const Duration(seconds: 2),
    this.factor = 2,
    this.max = const Duration(minutes: 5),
    this.jitter = 0.2,
    Random? random,
  }) : _random = random ?? Random();

  final Duration base;
  final num factor;
  final Duration max;
  final double jitter;
  final Random _random;

  int _attempt = 0;

  void reset() => _attempt = 0;

  int get attempts => _attempt;

  Duration next() {
    final double rawMs = base.inMilliseconds * pow(factor, _attempt).toDouble();
    final double cappedMs = min(rawMs, max.inMilliseconds.toDouble());
    _attempt++;

    final double delta = cappedMs * jitter;
    final double jittered = cappedMs + (_random.nextDouble() * 2 - 1) * delta;

    return Duration(
      milliseconds: jittered.clamp(0, max.inMilliseconds).round(),
    );
  }
}
