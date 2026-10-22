import { Injectable } from '@nestjs/common';
import { Database } from '../database/database';
import { Article } from './article';

const COLUMNS = 'id, slug, revision, category_id AS categoryId, author_id AS authorId';

@Injectable()
export class ArticleRepository {
  constructor(private readonly db: Database) {}

  find(id: number): Article | undefined {
    return this.db.prepare(`SELECT ${COLUMNS} FROM article WHERE id = ?`).get(id) as Article | undefined;
  }

  count(): number {
    return (this.db.prepare('SELECT COUNT(*) AS n FROM article').get() as { n: number }).n;
  }

  // Bumps the revision so stale pages show an older "rev N".
  edit(id: number, now: string): void {
    this.db.prepare('UPDATE article SET revision = revision + 1, updated_at = ? WHERE id = ?').run(now, id);
  }

  insert(article: { slug: string; title: string; body: string; publishedAt: string; categoryId: number; authorId: number }): number {
    const result = this.db
      .prepare(
        'INSERT INTO article (slug, title, body, published_at, updated_at, revision, category_id, author_id) VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
      )
      .run(article.slug, article.title, article.body, article.publishedAt, article.publishedAt, article.categoryId, article.authorId);

    return Number(result.lastInsertRowid);
  }

  categoryIds(): number[] {
    return this.ids('category');
  }

  authorIds(): number[] {
    return this.ids('author');
  }

  private ids(table: 'category' | 'author'): number[] {
    return (this.db.prepare(`SELECT id FROM ${table} ORDER BY id`).all() as { id: number }[]).map((row) => row.id);
  }
}
