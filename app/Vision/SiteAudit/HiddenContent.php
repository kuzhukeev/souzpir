<?php

namespace App\Vision\SiteAudit;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** Removes explicit HTML/inline visibility markers; this is not a CSS or JS renderer. */
final class HiddenContent
{
    public static function remove(DOMDocument $document): void
    {
        $nodes = iterator_to_array((new DOMXPath($document))->query('//*[@hidden or @aria-hidden or @style]'));
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement || ! $node->parentNode) {
                continue;
            }
            if ($node->hasAttribute('hidden') || strtolower(trim($node->getAttribute('aria-hidden'))) === 'true' || self::inlineHidden($node->getAttribute('style'))) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /** Parse declaration boundaries without treating strings or function arguments as CSS properties. */
    private static function inlineHidden(string $style): bool
    {
        $declarations = [];
        $current = '';
        $quote = null;
        $depth = 0;
        $length = strlen($style);
        for ($i = 0; $i < $length; $i++) {
            $char = $style[$i];
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $style[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '/' && ($style[$i + 1] ?? '') === '*') {
                $end = strpos($style, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $current .= ' ';
                $i = $end + 1;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
            } elseif ($char === '(') {
                $depth++;
                $current .= $char;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
                $current .= $char;
            } elseif ($char === ';' && $depth === 0) {
                $declarations[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }
        $declarations[] = $current;
        foreach ($declarations as $declaration) {
            if (preg_match('/^\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)\s*(?:!\s*important\s*)?$/iD', $declaration)) {
                return true;
            }
        }

        return false;
    }
}
