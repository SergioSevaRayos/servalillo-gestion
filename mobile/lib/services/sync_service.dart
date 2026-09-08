import '../data/position_queue.dart';
import '../data/secure_store.dart';
import '../models/api_exceptions.dart';
import 'tracker_api.dart';

enum FlushOutcome {
  idle,
  sent,
  stopRevoked,
  stopDeactivated,
  retryLater,
  notEnrolled,
}

class FlushResult {
  const FlushResult(this.outcome, {this.sent = 0, this.retryAfter});
  final FlushOutcome outcome;
  final int sent;
  final Duration? retryAfter;
}

/// Vacía la cola contra `POST /api/gps/batch` en lotes de ≤500.
class SyncService {
  SyncService({
    required PositionQueue queue,
    required SecureStore store,
    required TrackerApi api,
  }) : _queue = queue,
       _store = store,
       _api = api;

  final PositionQueue _queue;
  final SecureStore _store;
  final TrackerApi _api;

  Future<FlushResult> flush({int maxBatches = 20}) async {
    final String? token = await _store.token;
    if (token == null || token.isEmpty) {
      return const FlushResult(FlushOutcome.notEnrolled);
    }

    int totalSent = 0;

    for (int i = 0; i < maxBatches; i++) {
      final batch = await _queue.peekBatch(limit: 500);
      if (batch.isEmpty) {
        return totalSent > 0
            ? FlushResult(FlushOutcome.sent, sent: totalSent)
            : const FlushResult(FlushOutcome.idle);
      }

      final int lastId = batch.last.id!;

      try {
        await _api.sendBatch(
          token: token,
          positions: batch.map((p) => p.toJson()).toList(),
        );
        await _queue.deleteUpTo(lastId);
        await _store.setLastSentAt(DateTime.now());
        totalSent += batch.length;
      } on BatchRejected {
        // Lote inválido: descartarlo y seguir con el siguiente.
        await _queue.deleteUpTo(lastId);
      } on TokenRevoked {
        await _store.setState(EnrolState.revoked);
        return FlushResult(FlushOutcome.stopRevoked, sent: totalSent);
      } on DeviceDeactivated {
        await _store.setState(EnrolState.deactivated);
        return FlushResult(FlushOutcome.stopDeactivated, sent: totalSent);
      } on TransientNetworkError catch (e) {
        return FlushResult(
          FlushOutcome.retryLater,
          sent: totalSent,
          retryAfter: e.retryAfter,
        );
      }
    }

    return FlushResult(FlushOutcome.sent, sent: totalSent);
  }
}
