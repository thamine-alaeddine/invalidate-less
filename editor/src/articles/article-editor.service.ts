import { Inject, Injectable } from '@nestjs/common';
import { TextGenerator } from '../content/text-generator';
import { ARTICLE_EVENTS, ArticleEvents } from '../events/article-events';
import { Article } from './article';
import { ArticleRepository } from './article.repository';

// Every action queues an article event; the invalidator purges caches asynchronously.
@Injectable()
export class ArticleEditor {
  constructor(
    private readonly articles: ArticleRepository,
    @Inject(ARTICLE_EVENTS) private readonly events: ArticleEvents,
  ) {}

  async update(id: number): Promise<Article | undefined> {
    if (!this.articles.find(id)) {
      return undefined;
    }

    this.articles.edit(id, now());
    const article = this.articles.find(id)!;
    await this.events.updated(article);

    return article;
  }

  // Category and author default to a round-robin on the article number, so runs stay reproducible.
  async publish(categoryId?: number, authorId?: number): Promise<Article | undefined> {
    const number = this.articles.count() + 1;
    const category = pick(this.articles.categoryIds(), categoryId, number);
    const author = pick(this.articles.authorIds(), authorId, number);
    if (category === undefined || author === undefined) {
      return undefined;
    }

    const text = new TextGenerator(number);
    const title = text.title();
    const id = this.articles.insert({
      slug: `${TextGenerator.slugify(title)}-${number}`,
      title,
      body: text.body(),
      publishedAt: now(),
      categoryId: category,
      authorId: author,
    });
    const article = this.articles.find(id)!;
    await this.events.published(article);

    return article;
  }
}

// The requested id if it exists, else the round-robin one.
function pick(ids: number[], requested: number | undefined, number: number): number | undefined {
  if (requested !== undefined) {
    return ids.includes(requested) ? requested : undefined;
  }

  return ids.length ? ids[number % ids.length] : undefined;
}

// Doctrine's SQLite datetime format, in UTC like the Symfony app.
function now(): string {
  return new Date().toISOString().slice(0, 19).replace('T', ' ');
}
