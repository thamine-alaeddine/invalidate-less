import { Controller, HttpCode, NotFoundException, Param, ParseIntPipe, Post, Query } from '@nestjs/common';
import { ArticleEditor } from './article-editor.service';

const optionalInt = new ParseIntPipe({ optional: true });

@Controller('articles')
export class ArticlesController {
  constructor(private readonly editor: ArticleEditor) {}

  @Post(':id')
  @HttpCode(200)
  async update(@Param('id', ParseIntPipe) id: number): Promise<{ id: number; revision: number }> {
    const article = await this.editor.update(id);
    if (!article) {
      throw new NotFoundException(`Article ${id} not found.`);
    }

    return { id: article.id, revision: article.revision };
  }

  @Post()
  async publish(
    @Query('category', optionalInt) category?: number,
    @Query('author', optionalInt) author?: number,
  ): Promise<{ id: number; slug: string }> {
    const article = await this.editor.publish(category, author);
    if (!article) {
      throw new NotFoundException('Category or author not found.');
    }

    return { id: article.id, slug: article.slug };
  }
}
