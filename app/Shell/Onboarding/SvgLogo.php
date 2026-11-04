<?php

namespace App\Shell\Onboarding;

use DOMDocument;
use DOMElement;
use DOMNode;

final class SvgLogo
{
    private const ELEMENTS = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'title', 'desc'];

    private const ATTRIBUTES = ['xmlns', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'opacity', 'fill-opacity', 'stroke-opacity', 'transform', 'id', 'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'clip-path', 'font-size', 'font-family', 'font-weight', 'text-anchor'];

    public static function sanitize(string $bytes): ?string
    {
        $bytes = preg_replace('/^(?:\xEF\xBB\xBF)?\s*<\?xml\s+[^?]*\?>\s*/i', '', $bytes) ?? $bytes;
        if (strlen($bytes) > 1024 * 1024 || preg_match('/<!|<\?|\x00/i', $bytes)) {
            return null;
        }
        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        if (! @$document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS) || $document->doctype !== null) {
            return null;
        }
        $root = $document->documentElement;
        if (! $root || $root->localName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            return null;
        }
        if (! self::safeNode($root)) {
            return null;
        }

        return $document->saveXML($root) ?: null;
    }

    private static function safeNode(DOMNode $node): bool
    {
        if ($node instanceof DOMElement) {
            if (! in_array($node->localName, self::ELEMENTS, true) || $node->namespaceURI !== 'http://www.w3.org/2000/svg') {
                return false;
            }
            foreach ($node->attributes as $attribute) {
                if (! in_array($attribute->name, self::ATTRIBUTES, true) || ($attribute->namespaceURI && $attribute->name !== 'xmlns') || preg_match('/url\s*\(|(?:javascript|data):/i', $attribute->value)) {
                    return false;
                }
                if ($attribute->name === 'xmlns' && $attribute->value !== 'http://www.w3.org/2000/svg') {
                    return false;
                }
            }
        } elseif (! in_array($node->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
            return false;
        }
        foreach ($node->childNodes as $child) {
            if (! self::safeNode($child)) {
                return false;
            }
        }

        return true;
    }
}
