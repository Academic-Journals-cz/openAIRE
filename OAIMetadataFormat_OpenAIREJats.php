<?php

/**
 * @defgroup oai_format_jats
 */

/**
 * @file OAIMetadataFormat_OpenAIREJats.php
 *
 * Copyright (c) 2013-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OAIMetadataFormat_OpenAIREJats
 * @ingroup oai_format
 * @see OAI
 *
 * @brief OAI metadata format class -- OpenAIRE JATS
 *
 * Reuses the jatsTemplate plugin's generated JATS DOMDocument (the same way
 * plugins/oaiMetadataFormats/oaiJats does) and layers OpenAIRE/COAR-specific
 * metadata on top, instead of hand-building the whole document.
 *
 * Uses whatever JATS version jatsTemplate generates (currently 1.2) rather
 * than pinning to 1.1 like the original hand-rolled OpenAIRE plugin and
 * oaiJats used to. No confirmed requirement from OpenAIRE that 1.1 is
 * needed - revisit before the OJS 3.6 release if that gets confirmed
 * either way.
 */

namespace APP\plugins\generic\openAIRE;

use APP\core\Application;
use APP\issue\Issue;
use APP\issue\IssueAction;
use APP\oai\ojs\OAIDAO;
use APP\publication\Publication;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PKP\db\DAORegistry;
use PKP\i18n\LocaleConversion;
use PKP\oai\OAIMetadataFormat;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;

class OAIMetadataFormat_OpenAIREJats extends OAIMetadataFormat {

	/**
	 * @see OAIMetadataFormat::toXml()
	 */
	function toXml($record, $format = null): string
	{
		$article = $record->getData('article');
		$journal = $record->getData('journal');
		$section = $record->getData('section');
		$issue = $record->getData('issue');
		$publication = $article->getCurrentPublication();
		/** @var OpenAIREPlugin $parentPlugin */
		$parentPlugin = PluginRegistry::getPlugin('generic', 'openaireplugin');
		$accessRights = $parentPlugin->getAccessRights($journal, $issue, $publication);
		$resourceType = ($section->getData('resourceType') ? $section->getData('resourceType') : 'http://purl.org/coar/resource_type/c_6501'); # COAR resource type URI, defaults to "journal article"

		// Whether the requester is privileged enough to see pre-publication content,
		// same check oaiJats uses.
		$allowedPrePublicationAccess = $issue
			? (new IssueAction())->allowedIssuePrePublicationAccess($journal, Application::get()->getRequest()->getUser())
			: true;

		// Get the jatsTemplate-generated document, the same way oaiJats does.
		$candidateFiles = [];
		$templateDoc = null;
		Hook::call('OAIMetadataFormat_JATS::findJats', [&$this, &$record, &$candidateFiles, &$templateDoc]);
		if (!$templateDoc) {
			/** @var OAIDAO $oaiDao */
			$oaiDao = DAORegistry::getDAO('OAIDAO');
			$oaiDao->oai->error('cannotDisseminateFormat', 'Cannot disseminate format (JATS XML not available)');
			exit();
		}

		$this->augmentOpenAireMetadata($templateDoc, $issue, $publication, $parentPlugin, $accessRights, $resourceType, $allowedPrePublicationAccess);

		return $templateDoc->saveXml($templateDoc->getElementsByTagName('article')->item(0));
	}

	/**
	 * Layer OpenAIRE-specific metadata onto the jatsTemplate-generated
	 * document. Only adds/adjusts what OpenAIRE needs.
	 */
	protected function augmentOpenAireMetadata(
		DOMDocument $doc,
		?Issue $issue,
		Publication $publication,
		OpenAIREPlugin $parentPlugin,
		?string $accessRights,
		string $resourceType,
		bool $allowedPrePublicationAccess = false
	): void
	{
		$xpath = new DOMXPath($doc);
		$articleNode = $xpath->query('//article')->item(0);
		$articleMetaNode = $xpath->query('//article/front/article-meta')->item(0);

		// Remove author emails for requesters not allowed pre-publication access.
		if (!$allowedPrePublicationAccess) {
			$authorEmailNodes = $xpath->query(
				'//article/front/article-meta/contrib-group/contrib/email'
				. ' | //article/front/article-meta/author-notes/corresp/email'
			);
			foreach ($authorEmailNodes as $node) {
				$node->parentNode->removeChild($node);
			}
		}

		$articleType = $this->mapCoarResourceTypeToJatsArticleType($resourceType);
		if ($articleType) {
			$articleNode->setAttribute('article-type', $articleType);
		}

		// OpenAIRE has always linked to full text via self-uri, never embedded it.
		$bodyNode = $xpath->query('//article/body')->item(0);
		if ($bodyNode) {
			$bodyNode->parentNode->removeChild($bodyNode);
		}

		// Access rights: permissions/ali:free_to_read + COAR custom-meta.
		$openAccessDate = null;
		if ($accessRights === 'embargoedAccess' && $issue?->getOpenAccessDate()) {
			$openAccessDate = date('Y-m-d', strtotime($issue->getOpenAccessDate()));
		}
		if ($accessRights === 'openAccess' || $openAccessDate) {
			$articleNode->setAttribute('xmlns:ali', 'http://www.niso.org/schemas/ali/1.0');

			$permissionsNode = $xpath->query('//article/front/article-meta/permissions')->item(0);
			if (!$permissionsNode) {
				$permissionsNode = $doc->createElement('permissions');
				// JATS puts permissions before self-uri, which jatsTemplate always generates.
				$selfUriNode = $xpath->query('//article/front/article-meta/self-uri')->item(0);
				$articleMetaNode->insertBefore($permissionsNode, $selfUriNode);
			}

			$freeToReadNode = $doc->createElement('ali:free_to_read');
			if ($openAccessDate) {
				$freeToReadNode->setAttribute('start_date', $openAccessDate);
			}
			$permissionsNode->appendChild($freeToReadNode);
		}

		$coarResourceLabel = $parentPlugin->getCoarResourceType($resourceType);
		if ($accessRights || $coarResourceLabel) {
			$customMetaGroupNode = $xpath->query('//article/front/article-meta/custom-meta-group')->item(0);
			if (!$customMetaGroupNode) {
				// custom-meta-group is last in the article-meta content model, so
				// appending it is always correct - no anchor needed.
				$customMetaGroupNode = $doc->createElement('custom-meta-group');
				$articleMetaNode->appendChild($customMetaGroupNode);
			}

			if ($accessRights) {
				$coarAccessRights = OpenAIREPlugin::COAR_ACCESS_RIGHTS;
				$customMetaGroupNode->appendChild($this->createCustomMeta(
					$doc,
					'access-right',
					$coarAccessRights[$accessRights]['label'],
					$coarAccessRights[$accessRights]['url']
				));
			}
			if ($coarResourceLabel) {
				$customMetaGroupNode->appendChild($this->createCustomMeta(
					$doc,
					'resource-type',
					$coarResourceLabel,
					$resourceType
				));
			}
		}

		// Subjects (distinct from keywords, which jatsTemplate already emits).
		// kwd-group must precede funding-group/counts/custom-meta-group per the
		// JATS content model; insert before the first of those if any exist,
		// else append (also correctly stacks multiple locales in order, since
		// each new group lands right before the same anchor as the last one).
		if ($allSubjects = $publication->getData('subjects')) {
			$kwdGroupAnchor = $xpath->query(
				'//article/front/article-meta/funding-group'
				. ' | //article/front/article-meta/counts'
				. ' | //article/front/article-meta/custom-meta-group'
			)->item(0);
			foreach ($allSubjects as $locale => $subjects) {
				if (empty($subjects)) {
					continue;
				}
				$kwdGroupNode = $doc->createElement('kwd-group');
				$kwdGroupNode->setAttribute('xml:lang', LocaleConversion::toBcp47($locale));
				foreach ($subjects as $subject) {
					$kwdGroupNode->appendChild($doc->createElement('kwd', htmlspecialchars(trim($subject['name']))));
				}
				if ($kwdGroupAnchor) {
					$articleMetaNode->insertBefore($kwdGroupNode, $kwdGroupAnchor);
				} else {
					$articleMetaNode->appendChild($kwdGroupNode);
				}
			}
		}
	}

	/**
	 * Build a <custom-meta specific-use="..."> element with a meta-name/meta-value pair.
	 */
	protected function createCustomMeta(DOMDocument $doc, string $specificUse, string $metaName, string $metaValue): DOMElement
	{
		$customMetaNode = $doc->createElement('custom-meta');
		$customMetaNode->setAttribute('specific-use', $specificUse);
		$customMetaNode->appendChild($doc->createElement('meta-name', htmlspecialchars($metaName)));
		$customMetaNode->appendChild($doc->createElement('meta-value', htmlspecialchars($metaValue)));
		return $customMetaNode;
	}

	/**
	 * Get a JATS article-type string based on COAR Resource Type URI.
	 * https://jats.nlm.nih.gov/archiving/tag-library/1.1/attribute/article-type.html
	 */
	public function mapCoarResourceTypeToJatsArticleType(string $uri): ?string
	{
		$resourceTypes = [
			'http://purl.org/coar/resource_type/c_6501' => 'research-article',
			'http://purl.org/coar/resource_type/c_2df8fbb1' => 'research-article',
			'http://purl.org/coar/resource_type/c_dcae04bc' => 'review-article',
			'http://purl.org/coar/resource_type/c_beb9' => 'research-article',
			'http://purl.org/coar/resource_type/c_7bab' => 'research-article',
			'http://purl.org/coar/resource_type/c_b239' => 'editorial',
			'http://purl.org/coar/resource_type/c_545b' => 'letter',
			'http://purl.org/coar/resource_type/c_93fc' => 'case-report',
			'http://purl.org/coar/resource_type/c_efa0' => 'product-review',
			'http://purl.org/coar/resource_type/c_ba08' => 'book-review',
			'http://purl.org/coar/resource_type/c_26e4' => 'other', // interview -- no exact JATS article-type equivalent
			'http://purl.org/coar/resource_type/c_8544' => 'other', // lecture -- no exact JATS article-type equivalent
			'http://purl.org/coar/resource_type/c_5794' => 'meeting-report',
			'http://purl.org/coar/resource_type/c_46ec' => 'dissertation',
			'http://purl.org/coar/resource_type/c_8042' => 'research-article',
			'http://purl.org/coar/resource_type/c_816b' => 'research-article',
			'http://purl.org/coar/resource_type/c_1843' => 'other',
		];
		return $resourceTypes[$uri] ?? null;
	}

}
