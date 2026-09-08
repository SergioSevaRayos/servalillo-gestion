import 'dart:math';

import 'package:flutter_test/flutter_test.dart';
import 'package:servalillo_tracker/core/backoff.dart';

void main() {
  test('crece exponencialmente sin jitter', () {
    final b = ExponentialBackoff(
      base: const Duration(seconds: 1),
      factor: 2,
      jitter: 0,
      max: const Duration(minutes: 10),
    );
    expect(b.next(), const Duration(seconds: 1));
    expect(b.next(), const Duration(seconds: 2));
    expect(b.next(), const Duration(seconds: 4));
    expect(b.next(), const Duration(seconds: 8));
    expect(b.attempts, 4);
  });

  test('respeta el tope', () {
    final b = ExponentialBackoff(
      base: const Duration(seconds: 1),
      factor: 10,
      jitter: 0,
      max: const Duration(seconds: 5),
    );
    b.next();
    expect(b.next(), const Duration(seconds: 5));
    expect(b.next(), const Duration(seconds: 5));
  });

  test('reset vuelve al principio', () {
    final b = ExponentialBackoff(base: const Duration(seconds: 1), jitter: 0);
    b.next();
    b.next();
    b.reset();
    expect(b.attempts, 0);
    expect(b.next(), const Duration(seconds: 1));
  });

  test('con jitter se mantiene dentro de +-20% y nunca negativo', () {
    final b = ExponentialBackoff(
      base: const Duration(seconds: 10),
      factor: 1,
      jitter: 0.2,
      max: const Duration(minutes: 5),
      random: Random(1),
    );
    for (int i = 0; i < 50; i++) {
      final ms = b.next().inMilliseconds;
      expect(ms, greaterThanOrEqualTo(8000));
      expect(ms, lessThanOrEqualTo(12000));
    }
  });
}
