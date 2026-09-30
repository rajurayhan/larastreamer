<?php

declare(strict_types=1);

namespace Raju\Streamer\Playlist;

use DOMAttr;
use DOMDocument;
use DOMElement;
use Raju\Streamer\Exceptions\VideoNotFound;

final class DashManifestRewriter
{
    /**
     * @param  callable(string): string  $toUrl
     */
    public function rewrite(string $contents, string $playlistPath, callable $toUrl): string
    {
        $contents = $this->stripBom($contents);
        $trimmed = ltrim($contents);

        if ($trimmed === '' || stripos($trimmed, '<MPD') === false) {
            throw new VideoNotFound;
        }

        $document = new DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $root = $document->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || strcasecmp($this->localName($root), 'MPD') !== 0) {
            throw new VideoNotFound;
        }

        $this->rewriteElement($root, $playlistPath, $toUrl);
        $rewritten = $document->saveXML();

        if (! is_string($rewritten) || $rewritten === '') {
            throw new VideoNotFound;
        }

        return $rewritten;
    }

    /**
     * @param  callable(string): string  $toUrl
     */
    private function rewriteElement(DOMElement $element, string $basePath, callable $toUrl): void
    {
        [$basePath, $isLocal] = $this->basePath($element, $basePath);

        if (! $isLocal) {
            return;
        }

        $attributes = [];

        for ($index = 0; $index < $element->attributes->length; $index++) {
            $attribute = $element->attributes->item($index);

            if ($attribute instanceof DOMAttr) {
                $attributes[] = $attribute;
            }
        }

        foreach ($attributes as $attribute) {
            if (! in_array(strtolower($this->localName($attribute)), ['media', 'initialization', 'href', 'sourceurl'], true)) {
                continue;
            }

            $uri = html_entity_decode($attribute->value, ENT_QUOTES | ENT_XML1);

            if ($uri === '' || PlaylistUri::isAbsolute($uri)) {
                continue;
            }

            $url = $toUrl(PlaylistUri::resolve($basePath, $uri));

            if ($attribute->namespaceURI !== null) {
                $element->setAttributeNS($attribute->namespaceURI, $attribute->name, $url);
            } else {
                $element->setAttribute($attribute->name, $url);
            }
        }

        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && strcasecmp($this->localName($child), 'BaseURL') !== 0) {
                $children[] = $child;
            }
        }

        foreach ($children as $child) {
            $this->rewriteElement($child, $basePath, $toUrl);
        }
    }

    /**
     * @return array{string, bool}
     */
    private function basePath(DOMElement $element, string $basePath): array
    {
        $relative = [];

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement || strcasecmp($this->localName($child), 'BaseURL') !== 0) {
                continue;
            }

            $uri = trim($child->textContent);

            if ($uri === '') {
                continue;
            }

            if (PlaylistUri::isAbsolute($uri)) {
                return [$basePath, false];
            }

            $relative[] = $child;
        }

        if ($relative === []) {
            return [$basePath, true];
        }

        $uri = trim($relative[0]->textContent);
        $resolvedBase = str_ends_with($uri, '/')
            ? PlaylistUri::resolve($basePath, $uri.'__larastreamer_base__')
            : PlaylistUri::resolve($basePath, $uri);

        foreach ($relative as $baseUrl) {
            $element->removeChild($baseUrl);
        }

        return [$resolvedBase, true];
    }

    private function localName(DOMAttr|DOMElement $node): string
    {
        return $node->localName ?? $node->nodeName;
    }

    private function stripBom(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }

        return $contents;
    }
}
