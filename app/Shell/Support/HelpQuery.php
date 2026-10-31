<?php

namespace App\Shell\Support;

final class HelpQuery
{
    public function read(): array
    {
        $sections = [];
        foreach (__('Shell/Support/messages.sections') as $id => $section) {
            $sections[] = ['id' => $id, ...$section, 'url' => $this->url(config('features.E1-12.defaults.section_urls.'.$id))];
        }
        $articles = [];
        foreach (config('features.E1-12.defaults.articles', []) as $article) {
            if (is_array($article) && is_string($article['id'] ?? null) && is_string($article['title'] ?? null)
                && in_array($article['section_id'] ?? null, array_column($sections, 'id'), true) && ($url = $this->url($article['url'] ?? null))) {
                $articles[] = ['id' => $article['id'], 'title' => $article['title'], 'url' => $url, 'section_id' => $article['section_id']];
            }
        }
        $base = $this->url(config('features.E1-12.defaults.knowledge_base_url'));

        return ['sections' => $sections, 'articles' => $articles, 'knowledge_base_url' => $base,
            'configured' => $base !== null || $articles !== [] || count(array_filter(array_column($sections, 'url'))) > 0];
    }

    private function url(mixed $url): ?string
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) ? $url : null;
    }
}
