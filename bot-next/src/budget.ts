import type { DatabaseSync } from 'node:sqlite';

export type ChatId = string | number;
export type Reservation = { operationId: string; chatId: string; reservedMicros: number; actualMicros: number | null; state: 'reserved' | 'settled' | 'released' };
export type BudgetBalance = { limitMicros: number; spentMicros: number; reservedMicros: number; availableMicros: number };

export function integer(value: number, name: string, minimum = 0): number {
  if (!Number.isSafeInteger(value) || value < minimum) throw new Error(`Invalid ${name}`);
  return value;
}

let savepointSequence = 0;
/** Synchronous transactions cannot be interleaved by another JS task. */
export function atomic<T>(db: DatabaseSync, body: () => T): T {
  if (db.isTransaction) {
    const savepoint = `budget_${++savepointSequence}`;
    db.exec(`SAVEPOINT ${savepoint}`);
    try { const result = body(); db.exec(`RELEASE ${savepoint}`); return result; }
    catch (error) { db.exec(`ROLLBACK TO ${savepoint}; RELEASE ${savepoint}`); throw error; }
  }
  db.exec('BEGIN IMMEDIATE');
  try { const result = body(); db.exec('COMMIT'); return result; }
  catch (error) { db.exec('ROLLBACK'); throw error; }
}

/** All amounts are USD microdollars; Stars never change this ledger. */
export class Budget {
  readonly db: DatabaseSync;
  constructor(db: DatabaseSync, initialLimitMicros: number) {
    this.db = db;
    integer(initialLimitMicros, 'budget limit');
    db.exec(`CREATE TABLE IF NOT EXISTS budget_settings (id INTEGER PRIMARY KEY CHECK(id=1), limit_micros INTEGER NOT NULL CHECK(limit_micros>=0));
      CREATE TABLE IF NOT EXISTS budget_operations (
        operation_id TEXT PRIMARY KEY, chat_id TEXT NOT NULL, reserved_micros INTEGER NOT NULL CHECK(reserved_micros>=0),
        actual_micros INTEGER, state TEXT NOT NULL CHECK(state IN ('reserved','settled','released'))
      );`);
    db.prepare('INSERT OR IGNORE INTO budget_settings VALUES (1, ?)').run(initialLimitMicros);
  }

  balance(): BudgetBalance {
    const row = this.db.prepare(`SELECT limit_micros AS limitMicros,
      COALESCE((SELECT SUM(actual_micros) FROM budget_operations WHERE state='settled'),0) AS spentMicros,
      COALESCE((SELECT SUM(reserved_micros) FROM budget_operations WHERE state='reserved'),0) AS reservedMicros
      FROM budget_settings WHERE id=1`).get() as { limitMicros: number; spentMicros: number; reservedMicros: number };
    return { ...row, availableMicros: row.limitMicros - row.spentMicros - row.reservedMicros };
  }

  get(operationId: string): Reservation | undefined {
    return this.db.prepare(`SELECT operation_id AS operationId, chat_id AS chatId, reserved_micros AS reservedMicros,
      actual_micros AS actualMicros, state FROM budget_operations WHERE operation_id=?`).get(operationId) as Reservation | undefined;
  }

  reserve(operationId: string, chatId: ChatId, maximumMicros: number): Reservation {
    integer(maximumMicros, 'reservation');
    if (!operationId || !String(chatId)) throw new Error('Operation and chat are required');
    return atomic(this.db, () => {
      const existing = this.get(operationId);
      if (existing) {
        if (existing.chatId !== String(chatId) || existing.reservedMicros !== maximumMicros) throw new Error('Reservation conflict');
        return existing;
      }
      if (this.balance().availableMicros < maximumMicros) throw new Error('Budget exhausted');
      this.db.prepare("INSERT INTO budget_operations VALUES (?, ?, ?, NULL, 'reserved')").run(operationId, String(chatId), maximumMicros);
      return this.get(operationId)!;
    });
  }

  settle(operationId: string, actualMicros: number): Reservation {
    integer(actualMicros, 'actual cost');
    return atomic(this.db, () => {
      const row = this.get(operationId);
      if (!row) throw new Error('Unknown reservation');
      if (row.state === 'settled' && row.actualMicros === actualMicros) return row;
      if (row.state !== 'reserved') throw new Error('Reservation is already closed');
      if (actualMicros > row.reservedMicros) throw new Error('Cost exceeds reservation');
      this.db.prepare("UPDATE budget_operations SET state='settled', actual_micros=? WHERE operation_id=?").run(actualMicros, operationId);
      return this.get(operationId)!;
    });
  }

  release(operationId: string): Reservation {
    return atomic(this.db, () => {
      const row = this.get(operationId);
      if (!row) throw new Error('Unknown reservation');
      if (row.state === 'released') return row;
      if (row.state !== 'reserved') throw new Error('Cannot release settled spend');
      this.db.prepare("UPDATE budget_operations SET state='released', actual_micros=0 WHERE operation_id=?").run(operationId);
      return this.get(operationId)!;
    });
  }

  /** Host-only funding operation: do not expose this method as an agent tool. */
  increaseLimit(deltaMicros: number): BudgetBalance {
    integer(deltaMicros, 'funding', 1);
    return atomic(this.db, () => {
      const next = integer(this.balance().limitMicros + deltaMicros, 'budget limit');
      this.db.prepare('UPDATE budget_settings SET limit_micros=? WHERE id=1').run(next);
      return this.balance();
    });
  }
}
