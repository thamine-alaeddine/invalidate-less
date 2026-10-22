import { Injectable, OnModuleDestroy } from '@nestjs/common';
import { DatabaseSync } from 'node:sqlite';

// The site's SQLite database; its schema is owned by the Symfony app (app:seed).
@Injectable()
export class Database extends DatabaseSync implements OnModuleDestroy {
  constructor() {
    super(process.env.DATABASE_PATH ?? '/data/data.db');
    // Waits for the site's readers instead of failing with SQLITE_BUSY.
    this.exec('PRAGMA busy_timeout = 5000; PRAGMA foreign_keys = ON;');
  }

  onModuleDestroy(): void {
    this.close();
  }
}
