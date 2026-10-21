(function (root) {
  'use strict';
  const copy = value => JSON.parse(JSON.stringify(value));
  const key = () => {
    if (root.crypto.randomUUID) return root.crypto.randomUUID();
    const bytes=root.crypto.getRandomValues(new Uint8Array(16));bytes[6]=(bytes[6]&15)|64;bytes[8]=(bytes[8]&63)|128;
    const hex=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
    return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
  };

  // Each editor owns its backup. A second tab must never overwrite the first tab's unsent work.
  function recovery(scope, options = {}) {
    const user = options.user || root.document?.querySelector('meta[name="save-user"]')?.content;
    if (!user) throw new Error('작성자 정보를 확인하지 못했습니다. 다시 로그인하세요.');
    const storage = options.storage || root.localStorage;
    const prefix = 'claim-recovery-v1:' + user + ':' + scope + ':';
    const own = prefix + key();
    let restored = null, restoredRaw = null;
    return {
      latest() {
        const found = [];
        for (let i = 0; i < storage.length; i++) {
          const name = storage.key(i);
          if (!name.startsWith(prefix)) continue;
          try { const raw = storage.getItem(name), entry = JSON.parse(raw); found.push({name, raw, entry}); } catch (_) { /* Keep an unreadable backup for recovery. */ }
        }
        found.sort((a, b) => b.entry.at - a.entry.at);
        if (!found.length) return null;
        restored = found[0].name; restoredRaw = found[0].raw;
        return copy(found[0].entry.value);
      },
      write(value) { storage.setItem(own, JSON.stringify({at: Date.now(), value})); },
      clear() {
        storage.removeItem(own);
        // Do not delete a newer backup produced by another still-open editor.
        if (restored && storage.getItem(restored) === restoredRaw) storage.removeItem(restored);
        restored = restoredRaw = null;
      }
    };
  }

  // A retry repeats the exact uncertain request. New edits wait until its acknowledgement.
  class Writer {
    constructor(send) { this.send = send; this.pending = null; this.queue = Promise.resolve(); }
    restore(pending) { this.pending = pending ? copy(pending) : null; }
    run(request) {
      const execute = async () => {
        if (!this.pending) this.pending = Object.assign(copy(request()), {requestId: key()});
        const response = await this.send(copy(this.pending));
        const sent = this.pending; this.pending = null;
        return {response, sent};
      };
      this.queue = this.queue.catch(() => {}).then(execute);
      return this.queue;
    }
  }
  root.ClaimSaveSafety = {recovery, Writer, key};
  if (typeof module !== 'undefined') module.exports = root.ClaimSaveSafety;
})(typeof window === 'undefined' ? globalThis : window);
