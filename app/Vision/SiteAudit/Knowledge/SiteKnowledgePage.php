<?php

namespace App\Vision\SiteAudit\Knowledge;

use App\Ai\CanonicalJson;
use App\Vision\SiteAudit\HiddenContent;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

/** Text and navigation are extracted without executing any page content. */
final class SiteKnowledgePage
{
    public function url(string $base, string $reference = ''): string
    {
        $uri = UriResolver::resolve(new Uri($base), new Uri($reference))->withFragment('');
        $host = strtolower($uri->getHost());
        if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getUserInfo() !== ''
            || $host === '' || ! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || ($uri->getPort() !== null && ! in_array($uri->getPort(), [80, 443], true))) {
            throw new InvalidArgumentException('Нужен публичный HTTP(S)-адрес без учётных данных и нестандартного порта.');
        }

        return (string) $uri->withHost($host)->withPath($uri->getPath() ?: '/');
    }

    /** Deduplicate index aliases without changing an authored request path. */
    public function canonicalUrl(string $url): string
    {
        $uri = new Uri($this->url($url));

        return (string) $uri->withPath(preg_replace('~/index\.html?$~i', '/', $uri->getPath()));
    }

    public function origin(string $url): string
    {
        $uri = new Uri($url);

        return $uri->getScheme().'://'.$uri->getAuthority();
    }

    public function kind(string $heading): string
    {
        $parts = explode(' > ', mb_strtolower($heading));
        foreach (array_reverse($parts) as $part) {
            if (preg_match('/ограничени|не обещаем|гаранти|данны|конфиденц|оговорк/u', $part)) {
                return 'policy';
            }
            if (preg_match('/тариф|цен|стоимост|уровн|что входит|состав|где платно/u', $part)) {
                return 'catalog';
            }
            if (preg_match('/как |шаг|подключ|запуск|пилот/u', $part)) {
                return 'process';
            }
            if (preg_match('/контакт|о компани|кто мы|для кого/u', $part)) {
                return 'identity';
            }
        }

        return 'knowledge';
    }

    /** @return array{title:string,text:string,links:list<string>,fingerprint:string,price_caveats:list<string>} */
    public function extract(string $html, string $url): array
    {
        if (strlen($html) > 600000 || ! mb_check_encoding($html, 'UTF-8')) {
            throw new InvalidArgumentException('Страница превышает лимит или содержит некорректный UTF-8.');
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        HiddenContent::remove($dom);
        $xpath = new DOMXPath($dom);
        $links = [];
        foreach ($xpath->query('//a[@href]') as $anchor) {
            try {
                $link = $this->url($url, $anchor->getAttribute('href'));
                $path = parse_url($link, PHP_URL_PATH) ?: '/';
                $pagePath = ! preg_match('~\.[a-z0-9]{1,8}$~i', $path) || preg_match('~\.html?$~i', $path);
                if ($this->origin($link) === $this->origin($url) && parse_url($link, PHP_URL_QUERY) === null && $pagePath) {
                    $links[] = $link;
                }
            } catch (InvalidArgumentException) {
                continue;
            }
        }
        foreach (iterator_to_array($xpath->query('//script|//style|//noscript|//svg|//nav|//template|//iframe|//input|//textarea|//select|//button|//*[@hidden]|//*[@aria-hidden="true"]')) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $heading = $xpath->query('//h1')->item(0);
        $title = $heading ? preg_replace('/^#\s*/u', '', $this->plain($this->render($heading)))
            : $this->plain($xpath->query('//title')->item(0)?->textContent ?: $url);
        $text = $this->render($xpath->query('//body')->item(0) ?? $dom);
        $text = trim(preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/u', "\n\n", $text));
        if (mb_strlen($text) < 10 || mb_strlen($text) > 500000) {
            throw new InvalidArgumentException('На странице недостаточно текста или превышен лимит документа.');
        }

        $caveats = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/(?:цен[\p{L}]*|стоимост[\p{L}]*)[^\n]*(?:ориентир|утверждени|уточня)/ui', $line)) {
                $caveats[] = trim($line);
            }
        }

        return ['title' => mb_substr($title, 0, 180), 'text' => $text, 'links' => array_values(array_unique($links)),
            'price_caveats' => array_values(array_unique($caveats)),
            'fingerprint' => CanonicalJson::hash(['title' => $title, 'text' => $text])];
    }

    private function render(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/u', ' ', $node->textContent);
        }
        if (! $node instanceof DOMElement && ! $node instanceof DOMDocument) {
            return '';
        }
        $tag = strtolower($node->nodeName);
        if ($tag === 'br') {
            return "\n";
        }
        if ($tag === 'pre') {
            // Markdown inside a code sample is quoted content, not page headings.
            return "\n\nФрагмент кода (пример): «".$this->plain($node->textContent)."»\n\n";
        }
        if ($tag === 'table') {
            $rows = [];
            foreach ((new DOMXPath($node->ownerDocument))->query('.//tr', $node) as $row) {
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell instanceof DOMElement && in_array($cell->tagName, ['th', 'td'], true)) {
                        $cells[] = str_replace('|', ' / ', $this->plain($this->render($cell)));
                    }
                }
                if ($cells !== []) {
                    $rows[] = implode(' | ', $cells);
                }
            }

            return "\n\n".implode("\n", $rows)."\n\n";
        }
        $body = '';
        foreach ($node->childNodes as $child) {
            $body .= $this->render($child);
        }
        if (in_array($tag, ['a', 'article', 'section', 'div', 'header', 'li'], true)) {
            $body = $this->sectionCaption($body);
        }
        if (preg_match('/^h([1-6])$/', $tag, $match)) {
            return "\n\n".str_repeat('#', (int) $match[1]).' '.$this->plain($body)."\n\n";
        }
        if ($tag === 'summary') {
            return "\n\nВопрос: ".$this->plain($body)."\n";
        }
        if ($tag === 'li') {
            return preg_match('/^\s*#{1,6}\s/mu', $body)
                ? "\n".trim($body)."\n"
                : "\n- ".trim($body)."\n";
        }
        if ($tag === 'a') {
            $href = $node->getAttribute('href');
            if (preg_match('~^(?:mailto:|tel:|https?://(?:t\.me|telegram\.me)/)~i', $href)) {
                $body .= ' ('.preg_replace('/^(?:mailto:|tel:)/i', '', $href).')';
            }
        }
        if (in_array($tag, ['p', 'div', 'section', 'article', 'header', 'footer', 'main', 'ul', 'ol', 'details', 'dl', 'dt', 'dd', 'blockquote'], true)) {
            return "\n".trim($body)."\n";
        }

        return $body;
    }

    /** Keep a card's leading label with its heading instead of the preceding card. */
    private function sectionCaption(string $body): string
    {
        if (preg_match_all('/^\s*#{1,6}\s/mu', $body) !== 1
            || ! preg_match('/\A\s*(.*?)\n\s*(#{1,6} [^\n]+)\n(.*)\z/su', $body, $match)) {
            return $body;
        }
        $caption = $this->plain($match[1]);
        if ($caption === '' || mb_strlen($caption) > 180) {
            return $body;
        }

        $label = preg_match('/^\d+[.)]?$/u', $caption) ? $caption : 'Контекст раздела: '.$caption;

        return "\n\n".$match[2]."\n\n".$label."\n".$match[3];
    }

    private function plain(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
