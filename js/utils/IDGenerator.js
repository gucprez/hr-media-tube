const counters = new Map();

export function nextId(prefix) {
  const current = counters.get(prefix) ?? 0;
  const next = current + 1;
  counters.set(prefix, next);
  return `${prefix}_${String(next).padStart(4, '0')}`;
}

export function registerExistingId(id) {
  const match = /^(.+)_(\d+)$/.exec(id ?? '');
  if (!match) return;
  const [, prefix, numStr] = match;
  const num = parseInt(numStr, 10);
  const current = counters.get(prefix) ?? 0;
  if (num > current) counters.set(prefix, num);
}

export function resetIdCounters() {
  counters.clear();
}
