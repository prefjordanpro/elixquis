<?php
declare(strict_types=1);
namespace App\Service;

use Symfony\Component\HtmlSanitizer\{HtmlSanitizer, HtmlSanitizerConfig};

final class LegalMarkup
{
    private HtmlSanitizer $sanitizer;
    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())->withMaxInputLength(200000)->allowLinkSchemes(['http', 'https', 'mailto']);
        foreach (['h2', 'h3', 'p', 'br', 'strong', 'em', 'ul', 'ol', 'li'] as $tag) $config = $config->allowElement($tag, []);
        foreach (['span', 's', 'del', 'u', 'code', 'blockquote'] as $tag) $config = $config->blockElement($tag);
        $config = $config->allowElement('a', ['href', 'title'])->forceAttribute('a', 'rel', 'noopener noreferrer');
        $this->sanitizer = new HtmlSanitizer($config);
    }
    public function clean(string $html): string
    {
        if (strlen($html) > 200000) throw new \DomainException('Le document est trop long.');
        $html = preg_replace('/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/s', '', $html);
        $html = preg_replace('~<(/?)(div|h1|b|i)(?:\s[^>]*)?>~i', '<$1$2>', $html);
        $html = str_ireplace(['<div>', '</div>', '<h1>', '</h1>', '<b>', '</b>', '<i>', '</i>'], ['<p>', '</p>', '<h2>', '</h2>', '<strong>', '</strong>', '<em>', '</em>'], $html);
        return $this->sanitizer->sanitize($html);
    }
    public function isComplete(string $html): bool
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match('/[^\s\p{Z}\p{Cf}]/u', $text) === 1
            && !preg_match('/\[(?:À\s+(?:COMPLÉTER|INSÉRER)|NOM\]|ADRESSE\]|SITE INTERNET\])/ui', $text);
    }
    public function isPublishable(string $html, string $type): bool
    {
        if ($type === 'cgv') {
            $html = str_replace([
                '[À COMPLÉTER — MÉDIATEUR DE LA CONSOMMATION]',
                '[À INSÉRER — ENCADRÉ RÉGLEMENTAIRE OFFICIEL DES GARANTIES LÉGALES]',
                '[NOM]', '[ADRESSE]', '[SITE INTERNET]',
            ], '', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return $this->isComplete($html);
    }
    public function renderPublished(string $html): string
    {
        // Keep the source in the immutable snapshot; do not expose unfilled editorial fields.
        return preg_replace_callback('~<p>.*?</p>~su', fn ($match) => $this->isComplete($match[0]) ? $match[0] : '', $this->render($html));
    }
    public function render(string $html): string
    {
        $safe = $this->clean($html);
        // Stable navigation point for the supplied withdrawal form, without a submission workflow.
        return preg_replace('~<h2>FORMULAIRE TYPE DE RÉTRACTATION</h2>~u', '<h2 id="formulaire-retractation">FORMULAIRE TYPE DE RÉTRACTATION</h2>', $safe);
    }
}
