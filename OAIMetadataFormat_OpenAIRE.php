<?php

/**
 * @defgroup oai_format_openaire
 */
/**
 * @file OAIMetadataFormat_OpenAIRE.php
 *
 * Copyright (c) 2013-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OAIMetadataFormat_OpenAIRE
 * @ingroup oai_format
 * @see OAI
 *
 * @brief OAI metadata format class -- OpenAIRE (COAR/DataCite)
 */

namespace APP\plugins\generic\openAIRE;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\funding\classes\Funder;
use APP\plugins\generic\funding\classes\FunderAward;
use APP\plugins\generic\funding\classes\FunderAwardDAO;
use APP\plugins\generic\funding\classes\FunderDAO;
use PKP\core\PKPString;
use PKP\db\DAOResultFactory;
use PKP\facades\Locale;
use PKP\oai\OAIMetadataFormat;
use PKP\plugins\PluginRegistry;
use PKP\db\DAORegistry;
use PKP\submission\Genre;
use PKP\submission\GenreDAO;
use PKP\submissionFile\SubmissionFile;
use PKP\i18n\LocaleConversion;
use PKP\core\PKPApplication;

class OAIMetadataFormat_OpenAIRE extends OAIMetadataFormat {

    /**
     * @see OAIMetadataFormat::toXml()
     */
    public function toXml($record, $format = null): string
    {
        $request = Application::get()->getRequest();
        $article = $record->getData('article');
        $journal = $record->getData('journal');
        $section = $record->getData('section');
        $issue = $record->getData('issue');

        $publication = $article->getCurrentPublication();

        $galleys = $publication->getData('galleys');
        $printIssn = $journal->getData('printIssn');
        $onlineIssn = $journal->getData('onlineIssn');
        $publicationLocale = $publication->getData('locale');
        $publisherInstitution = $journal->getData('publisherInstitution');
        $datePublished = $publication->getData('datePublished');
        $publicationDoi = $publication->getDoi();
        /** @var OpenAIREPlugin $parentPlugin */
        $parentPlugin = PluginRegistry::getPlugin('generic', 'openaireplugin');
        $accessRights = $parentPlugin->getAccessRights($journal, $issue, $publication);
        $resourceType = ($section->getData('resourceType') ? $section->getData('resourceType') : 'http://purl.org/coar/resource_type/c_6501'); # COAR resource type URI, defaults to "journal article"
        $audience = $section->getData('audience');
        if (!$datePublished) {
            $datePublished = $issue->getData('datePublished');
        }
        if ($datePublished) {
            $datePublished = strtotime($datePublished);
        }

        //resource - defining schemas and namespaces
        $response = "<resource xmlns=\"http://namespace.openaire.eu/schema/oaire/\" xmlns:rdf=\"http://www.w3.org/TR/rdf-concepts/\" xmlns:doc=\"http://www.lyncode.com/xoai\" xmlns:dc=\"http://purl.org/dc/elements/1.1/\" xmlns:dcterms=\"http://purl.org/dc/terms/\" xmlns:oaire=\"http://namespace.openaire.eu/schema/oaire/\" xmlns:datacite=\"http://datacite.org/schema/kernel-4\" xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:vc=\"http://www.w3.org/2007/XMLSchema-versioning\"  xsi:schemaLocation=\"http://namespace.openaire.eu/schema/oaire/ https://www.openaire.eu/schema/repo-lit/4.0/openaire.xsd\"> \n";

        //1. Title (M) - Translated article titles
        $response .= "<datacite:titles>\n"
                . "<datacite:title xml:lang=\"" . htmlspecialchars(LocaleConversion::toBcp47($publicationLocale)) . "\">" . htmlspecialchars(strip_tags($publication->getData('title', $publicationLocale))) . "</datacite:title>\n";

        if (!empty($subtitle = $publication->getData('subtitle', $publicationLocale))) {
            $response .= "<datacite:title xml:lang=\"" . htmlspecialchars(LocaleConversion::toBcp47($publicationLocale)) . "\" titleType=\"subtitle\">" . htmlspecialchars(strip_tags($subtitle)) . "</datacite:title>\n";
        }
        foreach ((array) $publication->getData('title') as $locale => $title) {
            if ($title != '' && $locale != $publicationLocale) {
                $response .= "<datacite:title xml:lang=\"" . htmlspecialchars(LocaleConversion::toBcp47($locale)) . "\">" . htmlspecialchars(strip_tags($title)) . "</datacite:title>\n";
                if (!empty($subtitle = $publication->getData('subtitle', $locale))) {
                    $response .= "<datacite:title xml:lang=\"" . htmlspecialchars(LocaleConversion::toBcp47($locale)) . "\" titleType=\"Subtitle\">" . htmlspecialchars(strip_tags($subtitle)) . "</datacite:title>\n";
                }
            }
        }
        $response .= "</datacite:titles>\n";

        //2. Creator (M) - Authors
        $response .= "<datacite:creators>\n";
        foreach ($publication->getData('authors') as $author) {
            $affiliation = $author->getLocalizedAffiliationNamesAsString($publicationLocale);
            $response .= "<datacite:creator>\n" .
                    "<datacite:creatorName nameType=\"Personal\">" . htmlspecialchars($author->getFamilyName($publicationLocale) . ", " . $author->getGivenName($publicationLocale)) . "</datacite:creatorName>\n" .
                    "<datacite:givenName>" . htmlspecialchars($author->getGivenName($publicationLocale)) . "</datacite:givenName>\n" .
                    "<datacite:familyName>" . htmlspecialchars($author->getFamilyName($publicationLocale)) . "</datacite:familyName>\n" .
                    ($author->getOrcid() ? "<datacite:nameIdentifier nameIdentifierScheme=\"ORCID\" schemeURI=\"http://orcid.org\">" . htmlspecialchars($author->getOrcid()) . "</datacite:nameIdentifier>\n" : '') .
                    ($affiliation ? "<datacite:affiliation>" . htmlspecialchars($affiliation) . "</datacite:affiliation>\n" : '') .
                    "</datacite:creator>\n";
        }
        $response .= "</datacite:creators>\n";

        //4. Funding Reference (MA) - from the Funding plugin, if installed and enabled
        $fundingReferences = $this->getFundingReferences($article->getId());
        if ($fundingReferences) {
            $response .= $fundingReferences;
        }

        //5. Alternate Identifier (R)
        if (!empty($publicationDoi)) {
            $response .= "<datacite:alternateIdentifiers>\n"
                    . "<datacite:alternateIdentifier alternateIdentifierType=\"DOI\">" . htmlspecialchars($publicationDoi) . "</datacite:alternateIdentifier>\n"
                    . "</datacite:alternateIdentifiers>\n";
        }

        //8. Languages (MA) - taken from galley locales
        $galleyLocales = [];
        $mainGalleysList = [];
        foreach ($galleys as $galley) {
            $galleyFile = Repo::submissionFile()->get((int) $galley->getData('submissionFileId'));
            $galleyLocale = $galley->getLocale();
            if($galleyFile && $this->isMainDocumentFile($galleyFile)) {
                $mainGalleysList[] = ['galley' => $galley, 'file' => $galleyFile];
                if (!in_array($galleyLocale, $galleyLocales)) {
                    $response .= "<dc:language>" . htmlspecialchars(LocaleConversion::toBcp47($galleyLocale)) . "</dc:language>\n";
                    $galleyLocales[] = $galleyLocale;
                }
            }
        }

        //ISSN + eISSN
        if ($printIssn) {
            $response .= "<dc:source>ISSN: " . htmlspecialchars($printIssn) . "</dc:source>";
        }
        if ($onlineIssn) {
            $response .= "<dc:source>eISSN: " . htmlspecialchars($onlineIssn) . "</dc:source>";
        }

        //9. Publisher (MA)
        $response .= ($publisherInstitution != '' ? "<dc:publisher>" . htmlspecialchars($publisherInstitution) . "</dc:publisher>\n" : '');

        // 10. Publication date (M)
        if ($datePublished) {
            $response .= "<datacite:dates>\n" .
                    "<datacite:date dateType=\"Issued\">" . date('Y-m-d', $datePublished) . "</datacite:date>\n" .
                    "</datacite:dates>\n";
        }

        //11. Resource Type (M)
        $coarResourceLabel = $parentPlugin->getCoarResourceType($resourceType);
        if ($coarResourceLabel) {
            $response .= "<oaire:resourceType uri=\"" . $resourceType . "\" resourceTypeGeneral=\"literature\">" . $coarResourceLabel . "</oaire:resourceType>\n";
        }

        //12. description - abstract + translated abstracts
        $abstracts = $publication->getData('abstract') ?: [];
        foreach ($abstracts as $locale => $abstract) {
            if ($abstract != '')
                $abstract = PKPString::html2text($abstract);
            $response .= "<dc:description xml:lang=\"" . htmlspecialchars(LocaleConversion::toBcp47($locale)) . "\">" . htmlspecialchars($abstract) . "</dc:description>\n";
        }

        //13. Format (R)
        foreach ($mainGalleysList as $mainGalley) {
            $response .= "<dc:format>" . htmlspecialchars($mainGalley['file']->getData('mimetype')) . "</dc:format>\n";
        }

        //14. Resource Identifier (M) - landing page link
        $response .= "<datacite:identifier identifierType=\"URL\">" . htmlspecialchars($request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, null, 'article', 'view', [$article->getBestId()], urlLocaleForPage: '')) . "</datacite:identifier>\n";

        //15. Access Rights (M) - OpenAIRE COAR Access Rights
        $coarAccessRights = OpenAIREPlugin::COAR_ACCESS_RIGHTS;

        if ($accessRights) {
            $response .= "<datacite:rights rightsURI=\"" . $coarAccessRights[$accessRights]['url'] . "\">" . $coarAccessRights[$accessRights]['label'] . "</datacite:rights>\n";
        }

        //17. Subject (MA) - subjects + keywords
        $subjectsOutput = "";
        if ($subjects = $publication->getData('subjects')) {
            foreach ($subjects as $locale => $localeSubjects) {
                foreach ($localeSubjects as $subject) {
                    $subjectsOutput .= "<datacite:subject xml:lang=\"" . htmlspecialchars(LocaleConversion::toBcp47($locale)) . "\">" . htmlspecialchars(trim($subject['name'])) . "</datacite:subject>\n";
                }
            }
        }

        $keywordsOutput = "";
        if ($keywords = $publication->getData('keywords')) {
            foreach ($keywords as $locale => $localeKeywords) {
                foreach ($localeKeywords as $keyword) {
                    $keywordsOutput .= "<datacite:subject xml:lang=\"" . htmlspecialchars(LocaleConversion::toBcp47($locale)) . "\">" . htmlspecialchars($keyword['name']) . "</datacite:subject>\n";
                }
            }
        }

        if (!empty($subjectsOutput) OR !empty($keywordsOutput)) {
            $response .= "<datacite:subjects>\n";
            $response .= $subjectsOutput;
            $response .= $keywordsOutput;
            $response .= "</datacite:subjects>\n";
        }

        //18. Licence Condition (R)
        $licenseUrl = $publication->getData('licenseUrl');

        $openAccessDate = null;
        if ($accessRights == 'embargoedAccess') {
            $openAccessDate = strtotime($issue->getOpenAccessDate());
        } else {
            $openAccessDate = $datePublished;
        }
        if ($licenseUrl) {
            $ccLabel = $this->getCCLicenseLabel($licenseUrl, $publicationLocale);
            $response .= "<oaire:licenseCondition startDate=\"" . date('Y-m-d', $openAccessDate) . "\" uri=\"" . htmlspecialchars($licenseUrl) . "\">" . strip_tags($ccLabel ?? $licenseUrl) . "</oaire:licenseCondition>\n";
        }

        //19. Coverage (R)
        if ($coverages = $publication->getLocalizedData('coverage', $publicationLocale)) {
            if (trim($coverages) != '') {
                $response .= "<dc:coverage>" . htmlspecialchars(trim($coverages)) . "</dc:coverage>\n";
            }
        }

        //20. Size (O) - page count only; galley file sizes aren't reported here since
        // OJS doesn't store them and computing them would require a filesystem stat
        // per file on every OAI request, for little added value.
        $pageInfo = $parentPlugin->getPageInfo($publication);
        if ($pageInfo) {
            $response .= "<datacite:sizes>\n"
                    . "<datacite:size>" . (int) $pageInfo['pagecount'] . " Pages</datacite:size>\n"
                    . "</datacite:sizes>\n";
        }

        //23. File Location (MA) - full text links
        foreach ($mainGalleysList as $mainGalley) {
            $galley = $mainGalley['galley'];
            $galleyFile = $mainGalley['file'];
            if ($galleyFile->getData('fileId')) {
                $response .= "<oaire:file accessRightsURI=\"" . $coarAccessRights[$accessRights]['url'] . "\" mimeType=\"" . htmlspecialchars($galleyFile->getData('mimetype')) . "\" objectType=\"fulltext\">" . htmlspecialchars($request->url($journal->getPath(), 'article', 'download', [$article->getBestId(), $galley->getBestGalleyId()], null, null, true)) . "</oaire:file>\n";
            }
        }

        //24. Citation Title (R)
        $response .= "<oaire:citationTitle>" . htmlspecialchars($journal->getName($journal->getPrimaryLocale())) . "</oaire:citationTitle>\n";

        //25. Citation Volume (R)
        if ($issue->getVolume() && $issue->getShowVolume()) {
            $response .= "<oaire:citationVolume>" . htmlspecialchars($issue->getVolume()) . "</oaire:citationVolume>\n";
        }

        //26. Citation Issue (R)
        if ($issue->getNumber() && $issue->getShowNumber()) {
            $response .= "<oaire:citationIssue>" . htmlspecialchars($issue->getNumber()) . "</oaire:citationIssue>\n";
        }

        //27. Citation Start Page (R) + 28. Citation End Page (R) - Page info, if available and parseable.
        if ($pageInfo) {
            $response .= "<oaire:citationStartPage>" . $pageInfo['fpage'] . "</oaire:citationStartPage>\n"
                    . "<oaire:citationEndPage>" . $pageInfo['lpage'] . "</oaire:citationEndPage>\n";
        }

        //32. Audience (O)
        if ($audience) {
            $response .= "<dcterms:audience>" . htmlspecialchars($audience) . "</dcterms:audience>\n";
        }

        $response .= "</resource>\n";

        return $response;
    }

    /**
     * Get OpenAIRE oaire:fundingReferences XML for a submission's funders,
     * if the Funding plugin (plugins/generic/funding) is installed and enabled.
     */
    protected function getFundingReferences(int $submissionId): ?string
    {
        if (!PluginRegistry::getPlugin('generic', 'FundingPlugin')) {
            return null;
        }
        /** @var FunderDAO $funderDao */
        $funderDao = DAORegistry::getDAO('FunderDAO');
        /** @var FunderAwardDAO $funderAwardDao */
        $funderAwardDao = DAORegistry::getDAO('FunderAwardDAO');
        /** @var DAOResultFactory<Funder> $funders */
        $funders = $funderDao->getBySubmissionId($submissionId);
        $fundingReferences = '';
        while ($funder = $funders->next()) { /** @var Funder $funder */
            $funderXml = "<oaire:funderName>" . htmlspecialchars($funder->getFunderName()) . "</oaire:funderName>\n";
            if ($funder->getFunderIdentification()) {
                $funderXml .= "<oaire:funderIdentifier funderIdentifierType=\"Crossref Funder ID\">" . htmlspecialchars($funder->getFunderIdentification()) . "</oaire:funderIdentifier>\n";
            }
            $funderAwards = $funderAwardDao->getByFunderId($funder->getId());
            $hasAward = false;
            while ($funderAward = $funderAwards->next()) { /** @var FunderAward $funderAward */
                $hasAward = true;
                $fundingReferences .= "<oaire:fundingReference>\n" . $funderXml
                        . "<oaire:awardNumber>" . htmlspecialchars($funderAward->getFunderAwardNumber()) . "</oaire:awardNumber>\n"
                        . "</oaire:fundingReference>\n";
            }
            if (!$hasAward) {
                $fundingReferences .= "<oaire:fundingReference>\n" . $funderXml . "</oaire:fundingReference>\n";
            }
        }
        if (!$fundingReferences) {
            return null;
        }
        return "<oaire:fundingReferences>\n" . $fundingReferences . "</oaire:fundingReferences>\n";
    }

    /**
     * Get the Creative Commons license labels associated with a given
     * license URL.
     * @param $locale string Optional locale to return badge in
     * @return string HTML code for CC license
     */
    protected function getCCLicenseLabel(string $ccLicenseUrl, ?string $locale = null): ?string
    {
        $licenseKeyMap = [
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-nc-nd/4.0[/]?|' => 'submission.license.cc.by-nc-nd4',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-nc/4.0[/]?|' => 'submission.license.cc.by-nc4',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-nc-sa/4.0[/]?|' => 'submission.license.cc.by-nc-sa4',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-nd/4.0[/]?|' => 'submission.license.cc.by-nd4',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by/4.0[/]?|' => 'submission.license.cc.by4',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-sa/4.0[/]?|' => 'submission.license.cc.by-sa4',
            // Core has no short-label translation for CC 3.0 licenses (only
            // the full HTML badge under the ".footer" key) -- use that
            // instead, since getting real text is better than the
            // otherwise-missing-key "##key##" placeholder. strip_tags()
            // at the call site reduces the badge HTML to its inner sentence.
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-nc-nd/3.0[/]?|' => 'submission.license.cc.by-nc-nd3.footer',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-nc/3.0[/]?|' => 'submission.license.cc.by-nc3.footer',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-nc-sa/3.0[/]?|' => 'submission.license.cc.by-nc-sa3.footer',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-nd/3.0[/]?|' => 'submission.license.cc.by-nd3.footer',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by/3.0[/]?|' => 'submission.license.cc.by3.footer',
            '|http[s]?://(www\.)?creativecommons.org/licenses/by-sa/3.0[/]?|' => 'submission.license.cc.by-sa3.footer',
        ];
        if (is_null($locale)) {
            $locale = Locale::getLocale();
        }

        foreach ($licenseKeyMap as $pattern => $key) {
            if (preg_match($pattern, $ccLicenseUrl)) {
                return __($key, [], $locale);
            }
        }
        return null;
    }
    public function isMainDocumentFile(SubmissionFile $submissionFile): bool
    {
        static $genres = [];
        /** @var GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genreId = $submissionFile->getData('genreId');
        if (!isset($genres[$genreId])) {
            $genres[$genreId] = $genreDao->getById($genreId);
        }
        assert($genres[$genreId]);
        $genre = $genres[$genreId];

        // The genre doesn't look like a main submission document.
        if ($genre->getCategory() != Genre::GENRE_CATEGORY_DOCUMENT) {
            return false;
        }
        return true;
    }
}
