<?php

namespace Goose\Cleaners;

use Goose\Utils\Debug;

class DocumentCleaner {
    private $equalsNodes = [
        'side', 'links', 'inset', 'print', 'fn'
    ];

    private $endsWithNodes = [
        'meta'
    ];

    private $searchNodes = [
        'combx', 'retweet', 'mediaarticlerelated', 'menucontainer', 'navbar',
        'storytopbar-bucket', 'utility-bar', 'inline-share-tools', 'comment',
        'PopularQuestions', 'contact', 'foot', 'footer', 'Footer', 'footnote',
        'cnn_strycaptiontxt', 'cnn_html_slideshow', 'cnn_strylftcntnt',
        'shoutbox', 'sponsor', 'tags', 'socialnetworking', 'socialNetworking',
        'cnnStryHghLght', 'cnn_stryspcvbx', 'pagetools', 'post-attributes',
        'welcome_form', 'contentTools2', 'the_answers', 'communitypromo',
        'runaroundLeft', 'subscribe', 'vcard', 'articleheadings', 'date',
        'popup', 'author-dropdown', 'tools', 'socialtools', 'byline',
        'konafilter', 'KonaFilter', 'breadcrumbs', 'wp-caption-text',
        'legende', 'ajoutVideo', 'timestamp', 'js_replies',
    ];

    private $logPrefix = 'Cleaner: ';

    private $config;

    public function __construct($config) {
        $this->config = $config;
    }

    public function clean($article) {
        $docToClean = $article->getDoc();

        $docToClean = $this->removeComments($docToClean);
        $docToClean = $this->cleanEmTags($docToClean);
        $docToClean = $this->removeDropCaps($docToClean);
        $docToClean = $this->removeScriptsAndStyles($docToClean);
        $docToClean = $this->cleanBadTags($docToClean);
        $docToClean = $this->removeNodesViaFilter($docToClean, "[%s='caption']");
        $docToClean = $this->removeNodesViaFilter($docToClean, "[%s*=' google ']");
        $docToClean = $this->removeNodesViaFilter($docToClean, "[%s*='more']:not([%s^=entry-])", 2);
        $docToClean = $this->removeNodesViaFilter($docToClean, "[%s*='facebook']:not([%s*='-facebook'])", 2);
        $docToClean = $this->removeNodesViaFilter($docToClean, "[%s*='facebook-broadcasting']");
        $docToClean = $this->removeNodesViaFilter($docToClean, "[%s*='twitter']:not([%s*='-twitter'])", 2);
        $docToClean = $this->cleanUpSpanTagsInParagraphs($docToClean);
        $docToClean = $this->convertWantedTagsToParagraphs($docToClean, ['div', 'span', 'article']);
        $docToClean = $this->convertDivsToParagraphs($docToClean, 'div');
        $docToClean = $this->convertDivsToParagraphs($docToClean, 'span');

        return $docToClean;
    }

    private function removeComments($doc) {
        $comments = $doc->filterXpath('//comment()');

        foreach ($comments as $comment) {
            $comment->parentNode->removeChild($comment);
        }

        return $doc;
    }

    /**
     * replaces <em> tags with textnodes
     */
    private function cleanEmTags($doc) {
        $ems = $doc->filter('em');

        foreach ($ems as $node) {
            $images = $node->filter('img');

            if ($images->length == 0) {
                $node->parentNode->replaceChild(new \DOMText($node->textContent), $node);
            }
        }

        return $doc;
    }

    /**
     * takes care of the situation where you have a span tag nested in a paragraph tag
     * e.g. businessweek2.txt
     */
    private function cleanUpSpanTagsInParagraphs($doc) {
        $spans = $doc->filter('span');

        foreach ($spans as $item) {
            if ($item->parentNode->nodeName == 'p') {
                $item->parentNode->replaceChild(new \DOMText($item->textContent), $item);
            }
        }

        return $doc;
    }

    /**
     * remove those css drop caps where they put the first letter in big text in the 1st paragraph
     */
    private function removeDropCaps($doc) {
        $items = $doc->filter('span[class~=dropcap], span[class~=drop_cap]');

        foreach ($items as $item) {
            $item->parentNode->replaceChild(new \DOMText($item->textContent), $item);
        }

        return $doc;
    }

    private function removeScriptsAndStyles($doc) {
        $scripts = $doc->filter('script');

        foreach ($scripts as $item) {
            $item->parentNode->removeChild($item);
        }

        $styles = $doc->filter('style');

        foreach ($styles as $style) {
            $style->parentNode->removeChild($style);
        }

        return $doc;
    }

    private function cleanBadTags($doc) {
        $lists = [
            "[%s*='%s']" => $this->searchNodes,
            "[%s$='%s']" => $this->endsWithNodes,
            "[%s^='%s']" => $this->equalsNodes,
        ];

        $attrs = [
            'id',
            'class',
            'name',
        ];

        foreach ($lists as $expr => $list) {
            foreach ($list as $value) {
                foreach ($attrs as $attr) {
                    $selector = sprintf($expr, $attr, $value);

                    foreach ($doc->filter($selector) as $node) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }

        return $doc;
    }

    private function removeNodesViaFilter($doc, $pattern, $length = 1) {
        $attrs = [
            'id',
            'class',
        ];

        foreach ($attrs as $attr) {
            $args = [
                $pattern
            ];

            for ($i = 0; $i < $length; $i++) {
                $args[] = $attr;
            }

            $selector = call_user_func_array('sprintf', $args);

            foreach ($doc->filter($selector) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        return $doc;
    }

    public function replaceElementsWithPara($doc, $div) {
        $el = $doc->createElement('p');

        foreach ($div->childNodes as $child) {
            $child = $child->cloneNode(true);
            $el->appendChild($child);
        }

        foreach ($div->attributes as $attr) {
            $el->setAttribute($attr->localName, $attr->nodeValue);
        }

        $div->parentNode->replaceChild($el, $div);
    }

    public function convertWantedTagsToParagraphs($doc, $wantedTags) {
        $tags = ['a', 'blockquote', 'dl', 'div', 'img', 'ol', 'p', 'pre', 'table', 'ul'];

        $selected = $doc->filter(implode(', ', $wantedTags));

        foreach ($selected as $elem) {
            $items = $elem->filter(implode(', ', $tags));

            if (!$items->length) {
                $this->replaceElementsWithPara($doc, $elem);
            } else {
                $replacements = $this->getReplacementNodes($doc, $elem);

                foreach ($elem->childNodes as $child) {
                    $elem->removeChild($child);
                }

                foreach ($replacements as $replace) {
                    $elem->appendChild($replace);
                }
            }
        }

        return $doc;
    }

    public function convertDivsToParagraphs($doc, $domType) {
        $divs = $doc->filter($domType);

        foreach ($divs as $div) {
            if (!preg_match('@<(a|blockquote|dl|div|img|ol|p|pre|table|ul)@', $doc->saveXML($div))) {
                $this->replaceElementsWithPara($doc, $div);
            } else {
                $replacements = $this->getReplacementNodes($doc, $div);

                foreach ($div->childNodes as $child) {
                    $div->removeChild($child);
                }

                foreach ($replacements as $replace) {
                    $div->appendChild($replace);
                }
            }
        }

        return $doc;
    }

    private function getFlushedBuffer($replacementText, $doc) {
        $fragment = $doc->createDocumentFragment();
        $fragment->appendXML(implode('', $replacementText));

        $el = $doc->createElement('p');
        $el->appendChild($fragment);

        return $el;
    }

    public function getReplacementNodes($doc, $div) {
        $replacementText = [];
        $nodesToReturn = [];
        $nodesToRemove = [];

        foreach ($div->childNodes as $kid) {
            if ($kid->nodeName == 'p' && count($replacementText) > 0) {
                $newNode = $this->getFlushedBuffer($replacementText, $doc);
                $nodesToReturn[] = $newNode;
                $replacementText = [];
                $nodesToReturn[] = $kid;
            } else if ($kid->nodeType == XML_TEXT_NODE) {
                $replaceText = preg_replace('@[\n\r\s\t]+@', "\n", $kid->textContent);
                
                if (mb_strlen(trim($replaceText)) > 0) {
                    $prevSibNode = $kid->previousSibling;

                    while (!empty($prevSibNode) && $prevSibNode->nodeName == 'a' && $prevSibNode->getAttribute('grv-usedalready') != 'yes') {
                        $replacementText[] = $doc->saveXml($prevSibNode);
                        $nodesToRemove[] = $prevSibNode;
                        $prevSibNode->setAttribute('grv-usedalready', 'yes');

                        $prevSibNode = $prevSibNode->previousSibling;
                    }

                    $replacementText[] = $text;

                    $nextSibNode = $kid->nextSibling;

                    while (!empty($nextSibNode) && $nextSibNode->nodeName == 'a' && $nextSibNode->getAttribute('grv-usedalready') != 'yes') {
                        $replacementText[] = $doc->saveXml($nextSibNode);
                        $nodesToRemove[] = $nextSibNode;
                        $nextSibNode->setAttribute('grv-usedalready', 'yes');

                        $nextSibNode = $nextSibNode->nextSibling;
                    }
                }
                
                $nodesToRemove[] = $kid;
            } else {
                $nodesToReturn[] = $kid;
            }
        }

        if (!empty($replacementText)) {
            $newNode = $this->getFlushedBuffer($replacementText, $doc);
            $nodesToReturn[] = $newNode;
            $replacementText = [];
        }

        foreach ($nodesToRemove as $el) {
            if (is_object($el->parentNode)) {
                $el->parentNode->removeChild($el);
            }
        }

        return $nodesToReturn;
    }
}
