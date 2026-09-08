import '../core/clock.dart';
import '../data/position_queue.dart';
import '../data/secure_store.dart';
import 'location_sampler.dart';
import 'pause_window.dart';
import 'sync_service.dart';

/// Resultado de un `tick`, para que el TaskHandler actualice la notificación / pare el servicio.
enum TickAction {
  tracking,
  paused,
  stopRevoked,
  stopDeactivated,
  retryLater,
  notEnrolled,
}

class TickResult {
  const TickResult(
    this.action, {
    this.message = '',
    this.pending = 0,
    this.skipTicks = 0,
  });
  final TickAction action;
  final String message;
  final int pending;

  /// Nº de ticks a saltar (por un `Retry-After` del servidor).
  final int skipTicks;
}

/// Un "tick" del servicio: pausa? → muestrear → encolar → vaciar cola.
class TrackerController {
  TrackerController({
    required PositionQueue queue,
    required SecureStore store,
    required LocationSampler sampler,
    required SyncService sync,
    Clock clock = const SystemClock(),
  }) : _queue = queue,
       _store = store,
       _sampler = sampler,
       _sync = sync,
       _clock = clock;

  final PositionQueue _queue;
  final SecureStore _store;
  final LocationSampler _sampler;
  final SyncService _sync;
  final Clock _clock;

  Future<TickResult> tick() async {
    final config = await _store.config;

    if (isWithinPause(_clock.now(), config.pauseStart, config.pauseEnd)) {
      return TickResult(
        TickAction.paused,
        message: 'En pausa hasta las ${config.pauseEnd}',
        pending: await _queue.count(),
      );
    }

    final position = await _sampler.sample();
    if (position != null) {
      await _queue.enqueue(position);
    }

    final FlushResult flush = await _sync.flush();
    final int pending = await _queue.count();

    switch (flush.outcome) {
      case FlushOutcome.stopRevoked:
        return TickResult(
          TickAction.stopRevoked,
          message: 'Acceso revocado. Re-enrola.',
          pending: pending,
        );
      case FlushOutcome.stopDeactivated:
        return TickResult(
          TickAction.stopDeactivated,
          message: 'Dispositivo desactivado.',
          pending: pending,
        );
      case FlushOutcome.notEnrolled:
        return TickResult(
          TickAction.notEnrolled,
          message: 'Sin enrolar.',
          pending: pending,
        );
      case FlushOutcome.retryLater:
        final int skip = flush.retryAfter == null
            ? 0
            : (flush.retryAfter!.inSeconds / config.pingIntervalSeconds).ceil();
        return TickResult(
          TickAction.retryLater,
          message: pending > 0
              ? '$pending posiciones en cola (sin red)'
              : 'Sin red',
          pending: pending,
          skipTicks: skip,
        );
      case FlushOutcome.idle:
      case FlushOutcome.sent:
        final DateTime? last = await _store.lastSentAt;
        return TickResult(
          TickAction.tracking,
          message: pending > 0
              ? '$pending en cola'
              : (last != null
                    ? 'Enviando ubicación'
                    : 'Compartiendo ubicación'),
          pending: pending,
        );
    }
  }
}
