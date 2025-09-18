<?php
/**
 * @file plugins/importexport/copernicus/classes/CopernicusXmlBuilder.php
 * @brief Standalone XML builder & XSD validator for Index Copernicus (ICI) export, OJS 3.5.0-1.
 *
 * Architecture
 * - DOAJ-like separation: all XML generation/validation logic is encapsulated here,
 * while the plugin entry remains thin.
 *
 * Features
 * - <issue> elements are direct children of <ici-import>; <journal> is an empty
 * element carrying the ISSN as an attribute only.
 * - Multi-language <languageVersion> blocks for available locales.
 * - Robust author handling (given/surname, optional middle name, ORCID, role).
 * - DOAJ-style affiliation resolution via getLocalizedAffiliationNames() with fallbacks.
 * - DOI normalization, CC license type mapping, optional XSD validation.
 * - Schema path (default): plugins/importexport/copernicus/schema/journal_import_ici.xsd
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * @license GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Upgraded for OJS 3.5 by:
 * Saddam Al-Slfi <saddamalsalfi@qau.edu.ye>
 * Queen Arwa University
 *
 * Copyright (c) 2025
 * Queen Arwa University
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.
 * See the LICENSE.txt file for details.
 */

namespace APP\plugins\importexport\copernicus\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\Journal;
use APP\issue\Issue;
use APP\submission\Submission;

class CopernicusXmlBuilder
{
    /** @var \DOMDocument */
    private $doc;

    /** @var Journal */
    private $journal;

    /** @var Issue[] */
    private $issues;

    /** @var array */
    private $opts;

    public function __construct(Journal $journal, array $issues, array $opts = [])
    {
        $this->journal = $journal;
        $this->issues  = $issues;
        $this->opts = array_merge([
            'validateSchema'         => true,
            'schemaPath'             => null,
            'forceAffiliationFallback' => false,
            'affiliationFallbackText'  => 'No data',
        ], $opts);

        $this->doc = new \DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
        $this->doc->preserveWhiteSpace = false;
    }

    /** Build DOMDocument for the selected issues */
    public function buildDocument(): \DOMDocument
    {
        $root = $this->doc->createElement('ici-import');

        // <journal> must be empty (no child elements); carry ISSN as attribute
        $issn = $this->pickFirstString(
            $this->journal->getData('onlineIssn') ?: $this->journal->getData('printIssn') ?: ''
        );
        $journalElem = $this->doc->createElement('journal');
        if ($issn !== '') {
            $journalElem->setAttribute('issn', $issn);
        }
        $root->appendChild($journalElem);

        // <issue> nodes are direct children of <ici-import>
        foreach ($this->issues as $issue) {
            $root->appendChild($this->buildIssue($issue));
        }

        $this->doc->appendChild($root);

        // Optional schema validation
        if (!empty($this->opts['validateSchema'])
            && !empty($this->opts['schemaPath'])
            && file_exists($this->opts['schemaPath'])) {
            $this->schemaValidate($this->doc, $this->opts['schemaPath']);
        }

        return $this->doc;
    }

    /** <issue> with its <article> children */
    private function buildIssue(Issue $issue): \DOMElement
    {
        $issueElem = $this->doc->createElement('issue');
        $issueElem->setAttribute('number', (string)($issue->getNumber() ?: ''));
        $issueElem->setAttribute('volume', (string)($issue->getVolume() ?: ''));
        $issueElem->setAttribute('year',   (string)($issue->getYear()   ?: ''));

        $pubDate = $issue->getDatePublished();
        if (!empty($pubDate)) {
            $issueElem->setAttribute('publicationDate', date('Y-m-d', strtotime($pubDate)));
        }

        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$this->journal->getId()])
            ->filterByIssueIds([$issue->getId()])
            ->getMany();

        $numArticles = 0;
        foreach ($submissions as $submission) {
            $pub = $this->publicationForIssue($submission, $issue);
            if (!$pub) { continue; }

            $articleElem = $this->doc->createElement('article');
            $articleElem->appendChild($this->doc->createElement('type', 'ORIGINAL_ARTICLE'));

            // languageVersion blocks: build from the union of locales across title/abstract/keywords
            $locales = $this->collectLocales($pub);
            foreach ($locales as $locale) {
                $lv = $this->buildLanguageVersion($submission, $pub, $issue, $locale);
                // Only append if at least one child (title/abstract/keywords/etc.) exists
                if ($lv->hasChildNodes()) {
                    $articleElem->appendChild($lv);
                }
            }

            // Authors (>= 1 required)
            $authorsElem = $this->buildAuthors($pub);
            if ($authorsElem->hasChildNodes()) {
                $articleElem->appendChild($authorsElem);
            } else {
                // Skip the article if no authors to avoid XSD/UI errors
                continue;
            }

            // References (optional; keep XSD order)
            $refs = $this->buildReferences($pub);
            if ($refs) $articleElem->appendChild($refs);

            $issueElem->appendChild($articleElem);
            $numArticles++;
        }

        $issueElem->setAttribute('numberOfArticles', (string)$numArticles);
        return $issueElem;
    }

    /** Pick publication bound to this issue (robust for OJS 3.5) */
    private function publicationForIssue(Submission $submission, Issue $issue)
    {
        $cur = $submission->getCurrentPublication();
        if ($cur && (int)$cur->getData('issueId') === (int)$issue->getId()) {
            return $cur;
        }
        $pubs = Repo::publication()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();
        foreach ($pubs as $p) {
            if ((int)$p->getData('issueId') === (int)$issue->getId()) return $p;
        }
        return null;
    }

    /** One <languageVersion> per locale */
    private function buildLanguageVersion(Submission $submission, $publication, Issue $issue, string $locale): \DOMElement
    {
        $lv = $this->doc->createElement('languageVersion');
        $lv->setAttribute('language', $this->localeToLangCode($locale));

        // title (optional in practice; add when available)
        $title = $this->getFromBag($publication->getData('title'), $locale);
        if ($title !== '') {
            // MODIFIED: Replaced cdata() with textNode() for standard XML practice.
            $e = $this->doc->createElement('title'); $e->appendChild($this->textNode($title));
            $lv->appendChild($e);
        }

        // abstract (optional)
        $abstract = $this->getFromBag($publication->getData('abstract'), $locale);
        if ($abstract !== '') {
            // MODIFIED: Replaced cdata() with textNode() for standard XML practice.
            $e = $this->doc->createElement('abstract'); $e->appendChild($this->textNode(strip_tags((string)$abstract)));
            $lv->appendChild($e);
        }

        // keywords (optional)
        $kwBag = $publication->getData('keywords');
        if (is_array($kwBag)) {
            $kwForLocale = $kwBag[$locale] ?? null;
            if (is_array($kwForLocale) && !empty($kwForLocale)) {
                $ks = $this->doc->createElement('keywords');
                foreach ($kwForLocale as $kw) {
                    $kwStr = $this->pickFirstString($kw);
                    if ($kwStr === '') continue;
                    // MODIFIED: Replaced cdata() with textNode() for standard XML practice.
                    $k = $this->doc->createElement('keyword'); $k->appendChild($this->textNode($kwStr));
                    $ks->appendChild($k);
                }
                if ($ks->hasChildNodes()) $lv->appendChild($ks);
            }
        }

        // pdfFileUrl (prefer direct download)
        $galleys = $publication->getData('galleys');
        if (!empty($galleys)) {
            foreach ($galleys as $galley) {
                if (is_object($galley) && method_exists($galley, 'isPdfGalley') && $galley->isPdfGalley()) {
                    $req = Application::get()->getRequest();
                    $pdfUrl = $req->url(null, 'article', 'download', [$submission->getId(), $galley->getId()]);
                    $lv->appendChild($this->doc->createElement('pdfFileUrl', $pdfUrl));
                    break;
                }
            }
        }

        // articleUrl (HTML view)
        $req = Application::get()->getRequest();
        $articleUrl = $req->url(null, 'article', 'view', [$submission->getId()]);
        $lv->appendChild($this->doc->createElement('articleUrl', $articleUrl));

        // publicationDate
        $pubDate = $publication->getData('datePublished') ?: $issue->getDatePublished();
        if (!empty($pubDate)) {
            $lv->appendChild($this->doc->createElement('publicationDate', date('Y-m-d', strtotime($pubDate))));
        }

        // pages
        $pages = (string)($publication->getData('pages') ?? '');
        if ($pages !== '') {
            if (preg_match('/([0-9]+)\s*-\s*([0-9]+)/', $pages, $m)) {
                $lv->appendChild($this->doc->createElement('pageFrom', $m[1]));
                $lv->appendChild($this->doc->createElement('pageTo',   $m[2]));
            } elseif (preg_match('/(e[0-9]+)/i', $pages, $m) || preg_match('/^\s*([0-9]+)\s*$/', $pages, $m)) {
                $lv->appendChild($this->doc->createElement('pageFrom', $m[1]));
            }
        }

        // doi
        $doi = $this->extractDoi($publication, $submission);
        if ($doi !== '') {
            $lv->appendChild($this->doc->createElement('doi', $doi));
        }

        // license (map to enum type)
        $licenseUrl = $publication->getData('licenseUrl') ?: $this->journal->getData('licenseUrl') ?: '';
        if ($licenseUrl !== '') {
            $licenseType = $this->mapCcShortName($licenseUrl);
            $lic = $this->doc->createElement('license', $licenseUrl);
            if ($licenseType !== '') { $lic->setAttribute('type', $licenseType); }
            $lv->appendChild($lic);
        }

        return $lv;
    }

/**
     * <authors> (>=1). For each <author>:
     * Required: <name> (given), <surname> (family), <order>
     * Optional: <name2>, <email>, <instituteAffiliation>, <country>, <role>, <ORCID>
     *
     * Affiliation uses DOAJ-like approach:
     * - Prefer $author->getLocalizedAffiliationNames($publication->getData('locale')) if available
     * - Else fallback to localized string/bag
     * - Join multiple affiliations with " ; " to one string field Copernicus expects
     */
    private function buildAuthors($publication): \DOMElement
    {
        $authorsElem = $this->doc->createElement('authors');
        $authors = $publication->getData('authors') ?: [];
        $primaryContactId = $publication->getData('primaryContactId');
        
        // NEW: Get the publication's primary locale to give it priority when picking names.
        $publicationLocale = $publication->getData('locale') ?: $this->journal->getPrimaryLocale();

        $index = 1;

        foreach ($authors as $author) {
            $isObj = is_object($author);

            $givenBag  = $isObj ? ($author->getData('givenName') ?? null)  : ($author['givenName'] ?? null);
            $familyBag = $isObj ? ($author->getData('familyName') ?? null) : ($author['familyName'] ?? null);
            $publicBag = $isObj ? ($author->getData('preferredPublicName') ?? null) : ($author['preferredPublicName'] ?? null);
            
            // NEW: Create a dynamic list of preferred locales, starting with the publication's own language.
            $preferredLocales = array_unique(array_merge([$publicationLocale], $this->preferredLatinLocales()));

            // MODIFIED: Use the new dynamic list of locales to pick names.
            $firstName = trim($this->pickByLocales($givenBag,  $preferredLocales));
            $lastName  = trim($this->pickByLocales($familyBag, $preferredLocales));
            $public    = trim($this->pickByLocales($publicBag, $preferredLocales));

            // If familyName missing but public has multiple tokens, split last token as surname
            if ($lastName === '' && $public !== '') {
                $tokens = preg_split('/\s+/u', trim($public));
                if (count($tokens) > 1) {
                    $lastName  = array_pop($tokens);
                    $firstName = $firstName !== '' ? $firstName : trim(implode(' ', $tokens));
                }
            }

            if ($firstName === '' && $public !== '') { $firstName = $public; }

            // Avoid duplication: strip surname off the end of given name if present
            if ($firstName !== '' && $lastName !== '') {
                $firstName = preg_replace('/\s*' . preg_quote($lastName, '/') . '\s*$/ui', '', $firstName);
                $firstName = trim($firstName);
            }

            // Conservative middle name from public name
            $middleName = '';
            if ($public !== '' && $firstName !== '') {
                $p = $public;
                if ($lastName !== '') $p = preg_replace('/\b' . preg_quote($lastName, '/') . '\b/ui', '', $p);
                $p = preg_replace('/\b' . preg_quote($firstName, '/') . '\b/ui', '', $p);
                $p = trim(preg_replace('/\s+/', ' ', $p));
                if ($p !== '' && $p !== $firstName && $p !== $lastName) $middleName = $p;
            }

            $email   = $isObj ? (string)($author->getEmail() ?? '') : (string)($author['email'] ?? '');
            $orcid   = $isObj ? (string)($author->getData('orcid') ?? '') : (string)($author['orcid'] ?? '');
            $country = strtoupper(trim($isObj ? (string)($author->getData('country') ?? '') : (string)($author['country'] ?? '')));

            // >>> DOAJ-like affiliation resolution <<<
            $affiliation = $this->resolveAffiliation($author, $publication);
            if ($affiliation === '' && !empty($this->opts['forceAffiliationFallback'])) {
                $affiliation = (string)$this->opts['affiliationFallbackText'];
            }

            // Compose author node (ensure required fields exist)
            if ($firstName === '' || $lastName === '') {
                // Skip incomplete author to avoid schema/UI errors
                continue;
            }

            $a = $this->doc->createElement('author');
            
            $e = $this->doc->createElement('name'); $e->appendChild($this->textNode($firstName)); $a->appendChild($e);
            if ($middleName !== '') {
                $e = $this->doc->createElement('name2'); $e->appendChild($this->textNode($middleName)); $a->appendChild($e);
            }
            $e = $this->doc->createElement('surname'); $e->appendChild($this->textNode($lastName)); $a->appendChild($e);

            if ($email !== '') {
                $a->appendChild($this->doc->createElement('email', $email));
            }

            // order (required)
            $a->appendChild($this->doc->createElement('order', (string)$index));

            // instituteAffiliation (often required by Copernicus UI)
            if ($affiliation !== '') {
                $affEl = $this->doc->createElement('instituteAffiliation');
                $affEl->appendChild($this->textNode($affiliation));
                $a->appendChild($affEl);
            } elseif (!empty($this->opts['forceAffiliationFallback'])) {
                $affEl = $this->doc->createElement('instituteAffiliation');
                $affEl->appendChild($this->textNode($this->opts['affiliationFallbackText']));
                $a->appendChild($affEl);
            }

            if ($country !== '') {
                $a->appendChild($this->doc->createElement('country', $country));
            }
            
            $isPrimaryContact = $isObj && ($author->getId() == $primaryContactId);
            $role = $isPrimaryContact ? 'LEAD_AUTHOR' : 'AUTHOR';
            $a->appendChild($this->doc->createElement('role', $role));

            if ($orcid !== '' && $isObj && method_exists($author, 'hasVerifiedOrcid') && $author->hasVerifiedOrcid()) {
                $a->appendChild($this->doc->createElement('ORCID', $orcid));
            }

            $authorsElem->appendChild($a);
            $index++;
        }

        return $authorsElem;
    }

    
    /** <references> (optional) — XSD sequence: unparsedContent -> order -> (doi) */
    private function buildReferences($publication): ?\DOMElement
    {
        $citationsRaw = $publication->getData('citationsRaw');
        if (empty($citationsRaw)) return null;

        $refs = $this->doc->createElement('references');
        $lines = preg_split("/\r\n|\n|\r/", (string)$citationsRaw);
        $index = 1;

        foreach ($lines as $line) {
            $citation = trim((string)$line);
            // sanity per schema (referenceLength ≥ ~25 chars)
            if (mb_strlen($citation) < 25) continue;

            $ref = $this->doc->createElement('reference');

            // 1) unparsedContent (REQUIRED, must come first)
            $content = $this->doc->createElement('unparsedContent');
            // MODIFIED: Replaced cdata() with textNode() for standard XML practice.
            $content->appendChild($this->textNode($citation));
            $ref->appendChild($content);

            // 2) order (REQUIRED)
            $ref->appendChild($this->doc->createElement('order', (string)$index));

            // 3) doi (optional — only add if valid DOI can be extracted)
            $doi = $this->extractDoiFromReferenceText($citation);
            if ($doi !== '') {
                $ref->appendChild($this->doc->createElement('doi', $doi));
            }

            $refs->appendChild($ref);
            $index++;
        }

        return $refs->hasChildNodes() ? $refs : null;
    }

    /** Try to extract a DOI from free-text reference; returns normalized "10.xxxx/..." or "" */
    private function extractDoiFromReferenceText(string $text): string
    {
        if (preg_match('~\b(10\.\d{4,9}/[^\s"<>]+)~i', $text, $m)) {
            $doi = rtrim($m[1], '.,;:()[]{}');
            return $this->normalizeDoi($doi);
        }
        return '';
    }

    /* =========================
     * Helpers
     * ========================= */

    private function schemaValidate(\DOMDocument $doc, string $schemaPath): void
    {
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->schemaValidate($schemaPath);
        if (!$ok) {
            $errs = libxml_get_errors();
            foreach ($errs as $e) {
                error_log("Copernicus XSD error [{$e->level}] line {$e->line}: " . trim($e->message));
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
    }

    /** Build the set of locales present across title/abstract/keywords */
    private function collectLocales($publication): array
    {
        $locales = [];
        foreach (['title', 'abstract', 'keywords'] as $key) {
            $bag = (array) $publication->getData($key);
            foreach (array_keys($bag) as $loc) {
                if (is_string($loc) && $loc !== '') {
                    $locales[$loc] = true;
                }
            }
        }
        // Fallback to the publication's own locale if nothing was found
        if (empty($locales)) {
            $def = (string) $publication->getData('locale');
            if ($def !== '') $locales[$def] = true;
        }
        return array_keys($locales);
    }

    /** Get a string value for a concrete locale from a localized bag (no cross-locale fallback) */
    private function getFromBag($bag, string $locale): string
    {
        if (is_string($bag)) {
            return trim($bag);
        }
        if (is_array($bag) && isset($bag[$locale]) && is_string($bag[$locale])) {
            return trim($bag[$locale]);
        }
        return '';
    }

    /** First non-empty string from scalar or localized array */
    private function pickFirstString($value): string
    {
        if (is_string($value)) return $value;
        if (is_array($value)) {
            foreach ($value as $v) if (is_string($v) && $v !== '') return $v;
        }
        return '';
    }

    private function isNonEmptyString($v): bool { return is_string($v) && $v !== ''; }

    // NEW: Replaced cdata() with textNode() for standard XML creation.
    // This allows the DOMDocument library to handle special character escaping automatically.
    private function textNode($value): \DOMText
    {
        return $this->doc->createTextNode($this->pickFirstString($value));
    }

    /** Prefer Latin locales first (EN*), then others (Arabic last) */
    private function preferredLatinLocales(): array
    {
        return [
            'en_US','en_GB','en-GB','en_CA','en_AU','en_IE','en_NZ','en',
            'fr_FR','fr','de_DE','de','es_ES','es','tr_TR','tr',
            'ar_YE','ar_SA','ar_EG','ar_IQ','ar',
        ];
    }

    /** From a localized bag, pick by preferred locales; then any non-empty string */
    private function pickByLocales($value, array $preferredLocales): string
    {
        if (is_string($value)) return $value;
        if (is_array($value)) {
            foreach ($preferredLocales as $loc) {
                if (array_key_exists($loc, $value) && is_string($value[$loc]) && $value[$loc] !== '') return $value[$loc];
                if ($loc === 'en') {
                    foreach ($value as $k => $v) {
                        if (!is_string($v) || $v === '') continue;
                        if (stripos($k, 'en_') === 0 || stripos($k, 'en-') === 0) return $v;
                    }
                }
            }
            foreach ($value as $v) if (is_string($v) && $v !== '') return $v;
        }
        return '';
    }

    /** Normalize DOI to bare form (10.xxxx/...) while satisfying schema pattern */
    private function normalizeDoi(string $doi): string
    {
        $doi = trim($doi);
        if ($doi === '') return '';
        $doi = preg_replace('~^(https?://(dx\.)?doi\.org/|doi:\s*)~i', '', $doi);
        $doi = preg_replace('~\s+~', '', $doi);
        if (!preg_match('~^10\.[0-9]{4,9}/.{1,200}$~', $doi)) {
            if (!preg_match('~^10\..+~', $doi)) return '';
        }
        return $doi;
    }

    /** Extract DOI across OJS 3.3–3.5 variants */
    private function extractDoi($publication, $submission): string
    {
        if (is_object($publication) && method_exists($publication, 'getDoi')) {
            $v = (string)($publication->getDoi() ?? ''); if ($v !== '') return $this->normalizeDoi($v);
        }
        if (is_object($publication)) {
            $v = (string)($publication->getData('pub-id::doi') ?? ''); if ($v !== '') return $this->normalizeDoi($v);
        }
        if (is_object($submission) && method_exists($submission, 'getDoi')) {
            $v = (string)($submission->getDoi() ?? ''); if ($v !== '') return $this->normalizeDoi($v);
        }
        $v = (string)($submission->getData('pub-id::doi') ?? '');
        return $this->normalizeDoi($v);
    }

    /** Convert 'en_US' -> 'en' */
    private function localeToLangCode(string $locale): string
    {
        $parts = preg_split('/[_-]/', $locale);
        $lang = strtolower($parts[0] ?? 'en');
        return $lang !== '' ? $lang : 'en';
    }

    /** Map CC URL to XSD enum licenceType */
    private function mapCcShortName(string $url): string
    {
        $u = strtolower($url);
        if (strpos($u, 'creativecommons.org') === false) return 'OTHER';
        if (strpos($u, '/by-nc-nd/') !== false) return 'CC BY-NC-ND';
        if (strpos($u, '/by-nc-sa/') !== false) return 'CC BY-NC-SA';
        if (strpos($u, '/by-nc/')    !== false) return 'CC BY-NC';
        if (strpos($u, '/by-nd/')    !== false) return 'CC BY-ND';
        if (strpos($u, '/by-sa/')    !== false) return 'CC BY-SA';
        if (strpos($u, '/by/')       !== false) return 'CC BY';
        return 'OTHER';
    }

    /**
     * Resolve author's affiliation (DOAJ-like):
     * 1) If available, use Author::getLocalizedAffiliationNames($publication->getData('locale')) → array of strings.
     * Join unique non-empty entries with " ; " (Copernicus expects a single text field).
     * 2) Else, try getLocalizedAffiliation($locale) / getLocalizedData('affiliation').
     * 3) Else, use raw data bag 'affiliation' (string or localized array) with Latin-first picking.
     */
    private function resolveAffiliation($author, $publication): string
    {
        $pubLocale = is_object($publication) ? (string)$publication->getData('locale') : '';
        $pubLocale = $pubLocale ?: ($this->journal->getPrimaryLocale() ?: 'en_US');

        // DOAJ-style API first
        if (is_object($author) && method_exists($author, 'getLocalizedAffiliationNames')) {
            $list = (array)$author->getLocalizedAffiliationNames($pubLocale);
            $clean = [];
            foreach ($list as $a) {
                $a = trim((string)$a);
                if ($a !== '' && !in_array($a, $clean, true)) {
                    $clean[] = $a;
                }
            }
            if (!empty($clean)) {
                return implode(' ; ', $clean);
            }
        }

        // Localized single-string helpers
        if (is_object($author) && method_exists($author, 'getLocalizedAffiliation')) {
            $v = (string)($author->getLocalizedAffiliation($pubLocale) ?? '');
            if (trim($v) !== '') return trim($v);
        }
        if (is_object($author) && method_exists($author, 'getLocalizedData')) {
            $v = (string)($author->getLocalizedData('affiliation', $pubLocale) ?? '');
            if (trim($v) !== '') return trim($v);
        }

        // Raw bag (string or localized array)
        $bag = is_object($author) ? $author->getData('affiliation') : ($author['affiliation'] ?? null);
        if (is_string($bag)) {
            return trim($bag);
        }
        if (is_array($bag)) {
            return trim($this->pickByLocales($bag, $this->preferredLatinLocales()));
        }

        return '';
    }
}
