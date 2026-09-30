'use strict';

/**
 * Pushes connection state to Laravel (POST /internal/whatsapp/state, docs/MASTER_PROMPT.md
 * §32.5) whenever it changes, and again every `heartbeatMs` as a heartbeat so
 * WhatsAppSessionManager::workerRunning() knows the worker is alive.
 */
class StateReporter {
  constructor({ laravelUrl, workerToken, heartbeatMs = 15000, logger = console }) {
    this.laravelUrl = laravelUrl.replace(/\/+$/, '');
    this.workerToken = workerToken;
    this.heartbeatMs = heartbeatMs;
    this.logger = logger;
    this.lastReported = null;
    this.timer = null;
  }

  /** `getState` may be async: the worker re-reads the page on each beat. */
  start(getState) {
    this.getState = getState;
    this.timer = setInterval(async () => {
      try {
        await this.report(await this.getState(), 'heartbeat');
      } catch (error) {
        this.logger.warn(`[state-reporter] heartbeat failed: ${error.message}`);
      }
    }, this.heartbeatMs);
    this.timer.unref?.();
  }

  stop() {
    if (this.timer) clearInterval(this.timer);
  }

  async report(state, reason = null) {
    try {
      const res = await fetch(`${this.laravelUrl}/internal/whatsapp/state`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Worker-Token': this.workerToken,
        },
        body: JSON.stringify({ state, reason }),
        signal: AbortSignal.timeout(5000),
      });

      if (!res.ok) {
        this.logger.warn(`[state-reporter] Laravel responded ${res.status} for state ${state}`);
      }

      this.lastReported = state;
    } catch (error) {
      // Laravel being briefly unreachable is not fatal: the worker keeps running and
      // will report again on the next change or heartbeat.
      this.logger.warn(`[state-reporter] could not reach Laravel: ${error.message}`);
    }
  }
}

module.exports = { StateReporter };
