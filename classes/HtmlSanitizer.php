<?php

namespace Chatbot;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

class HtmlSanitizer
{
    private $allowedCssProperties = [
        'aspect-ratio',
        'background',
        'border-radius',
        'color',
        'font-size',
        'height',
        'margin-bottom',
        'margin-top',
        'max-width',
        'object-fit',
        'padding'
    ];

    private $allowedTags = [
        'a' => ['href', 'target', 'rel', 'class'],
        'br' => [],
        'div' => ['class', 'style'],
        'h4' => ['class', 'style'],
        'img' => ['src', 'alt', 'class', 'style'],
        'li' => ['class', 'style'],
        'ol' => ['class', 'style'],
        'p' => ['class', 'style'],
        'small' => ['class', 'style'],
        'strong' => ['class', 'style'],
        'ul' => ['class', 'style']
    ];

    public function sanitize($html)
    {
        if (!is_string($html) || trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $wrappedHtml = '<div>' . $html . '</div>';
        $document->loadHTML(mb_convert_encoding($wrappedHtml, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $this->sanitizeNode($document->documentElement);

        $output = '';
        foreach ($document->documentElement->childNodes as $childNode) {
            $output .= $document->saveHTML($childNode);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $output;
    }

    private function sanitizeNode(DOMNode $node)
    {
        for ($index = $node->childNodes->length - 1; $index >= 0; $index--) {
            $child = $node->childNodes->item($index);
            if ($child instanceof DOMElement) {
                $tagName = strtolower($child->tagName);
                if (!isset($this->allowedTags[$tagName])) {
                    $this->replaceElementWithText($child);
                    continue;
                }

                $this->sanitizeAttributes($child, $this->allowedTags[$tagName]);
                $this->sanitizeNode($child);
            }
        }
    }

    private function replaceElementWithText(DOMElement $element)
    {
        $textNode = $element->ownerDocument->createTextNode($element->textContent);
        $element->parentNode->replaceChild($textNode, $element);
    }

    private function sanitizeAttributes(DOMElement $element, array $allowedAttributes)
    {
        for ($index = $element->attributes->length - 1; $index >= 0; $index--) {
            $attribute = $element->attributes->item($index);
            if (!$attribute instanceof DOMAttr) {
                continue;
            }

            $attributeName = strtolower($attribute->name);

            if (strpos($attributeName, 'on') === 0 || !in_array($attributeName, $allowedAttributes, true)) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            $sanitizedValue = $this->sanitizeAttributeValue($attributeName, $attribute->value);
            if ($sanitizedValue === null) {
                $element->removeAttributeNode($attribute);
                continue;
            }

            $element->setAttribute($attributeName, $sanitizedValue);
        }

        if (strtolower($element->tagName) === 'a' && strtolower($element->getAttribute('target')) === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function sanitizeAttributeValue($attributeName, $value)
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if ($attributeName === 'href' || $attributeName === 'src') {
            return $this->sanitizeUrl($value);
        }

        if ($attributeName === 'style') {
            return $this->sanitizeInlineStyle($value);
        }

        if ($attributeName === 'target') {
            return $value === '_blank' ? '_blank' : null;
        }

        return htmlspecialchars_decode($value, ENT_QUOTES);
    }

    private function sanitizeUrl($value)
    {
        $decodedValue = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        if (preg_match('/^https?:\/\//i', $decodedValue)) {
            return $decodedValue;
        }

        return null;
    }

    private function sanitizeInlineStyle($value)
    {
        if (preg_match('/expression|javascript:|url\s*\(|behavior\s*:/i', $value)) {
            return null;
        }

        $safeRules = [];
        $rules = explode(';', $value);

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if ($rule === '' || strpos($rule, ':') === false) {
                continue;
            }

            list($property, $propertyValue) = explode(':', $rule, 2);
            $property = strtolower(trim($property));
            $propertyValue = trim($propertyValue);

            if (!in_array($property, $this->allowedCssProperties, true)) {
                continue;
            }

            if (!$this->isSafeCssValue($propertyValue)) {
                continue;
            }

            $safeRules[] = $property . ':' . $propertyValue;
        }

        if (empty($safeRules)) {
            return null;
        }

        return implode('; ', $safeRules);
    }

    private function isSafeCssValue($value)
    {
        return preg_match('/^[a-zA-Z0-9\s\-\.#,%\(\)"\']+$/', $value) === 1;
    }
}
