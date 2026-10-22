// Same tag names as the site's src/Cache/CacheTags.php, which tags the responses these purge.
export const CacheTags = {
  article: (id: number): string => `article-${id}`,
  latestList: (): string => 'list-latest',
  categoryList: (categoryId: number): string => `list-category-${categoryId}`,
  authorList: (authorId: number): string => `list-author-${authorId}`,
};
