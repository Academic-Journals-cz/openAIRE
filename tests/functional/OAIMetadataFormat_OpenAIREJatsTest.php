<?php

/**
 * @file OAIMetadataFormat_OpenAIREJatsTest.php
 *
 * Copyright (c) 2013-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Unit tests for the OpenAIRE-specific augmentation layered onto a
 *  jatsTemplate-generated document.
 */

namespace APP\plugins\generic\openAIRE\tests\functional;

use APP\issue\Issue;
use APP\journal\Journal;
use APP\plugins\generic\openAIRE\OAIMetadataFormat_OpenAIREJats;
use APP\plugins\generic\openAIRE\OpenAIREPlugin;
use APP\publication\Publication;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;
use ReflectionMethod;

#[CoversClass(OAIMetadataFormat_OpenAIREJats::class)]
class OAIMetadataFormat_OpenAIREJatsTest extends PKPTestCase
{
	/**
	 * Build a minimal DOMDocument that mimics the shape jatsTemplate's
	 * Article::convertOAIToXml() produces: an <article> with a populated
	 * <front>/<article-meta> (always including a trailing <self-uri>, which
	 * jatsTemplate always emits) and an optional <body>.
	 */
	private function createDocument(string $extraArticleMetaXml = '', bool $withBody = false): DOMDocument
	{
		$body = $withBody ? '<body><p>Full text.</p></body>' : '';
		$xml = '<article xmlns:xlink="http://www.w3.org/1999/xlink" dtd-version="1.2">'
			. '<front><article-meta>'
			. '<article-id pub-id-type="publisher-id">1</article-id>'
			. '<title-group><article-title>Test Article</article-title></title-group>'
			. $extraArticleMetaXml
			. '<self-uri xlink:href="https://example.org/article/1"/>'
			. '</article-meta></front>'
			. $body
			. '</article>';
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		return $doc;
	}

	/**
	 * Invoke the protected augmentOpenAireMetadata() method under test.
	 */
	private function invokeAugment(
		DOMDocument $doc,
		?Issue $issue,
		Publication $publication,
		OpenAIREPlugin $plugin,
		?string $accessRights,
		string $resourceType = 'http://purl.org/coar/resource_type/c_6501',
		bool $allowedPrePublicationAccess = true
	): void {
		$format = new OAIMetadataFormat_OpenAIREJats('oai_openaire_jats', '', '');
		$method = new ReflectionMethod($format, 'augmentOpenAireMetadata');
		$method->invoke($format, $doc, $issue, $publication, $plugin, $accessRights, $resourceType, $allowedPrePublicationAccess);
	}

	private function createPublication(array $data = []): Publication
	{
		/** @var Publication $publication */
		$publication = $this->getMockBuilder(Publication::class)->onlyMethods([])->getMock();
		foreach ($data as $key => $value) {
			$publication->setData($key, $value);
		}
		return $publication;
	}

	private function createIssue(array $data = []): Issue
	{
		/** @var Issue $issue */
		$issue = $this->getMockBuilder(Issue::class)->onlyMethods([])->getMock();
		foreach ($data as $key => $value) {
			$issue->setData($key, $value);
		}
		return $issue;
	}

	private function createJournal(array $data = []): Journal
	{
		/** @var Journal $journal */
		$journal = $this->getMockBuilder(Journal::class)->onlyMethods([])->getMock();
		foreach ($data as $key => $value) {
			$journal->setData($key, $value);
		}
		return $journal;
	}

	public function testArticleTypeIsSet(): void
	{
		$doc = $this->createDocument();
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), null);

		$article = $doc->getElementsByTagName('article')->item(0);
		$this->assertEquals('research-article', $article->getAttribute('article-type'));
	}

	public function testJatsVersionIsNotOverridden(): void
	{
		// Uses whatever version jatsTemplate generated (currently 1.2), rather than
		// pinning to 1.1 like the original hand-rolled OpenAIRE plugin and oaiJats do.
		$doc = $this->createDocument();
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), null);

		$article = $doc->getElementsByTagName('article')->item(0);
		$this->assertEquals('1.2', $article->getAttribute('dtd-version'));
		$this->assertSame('', $article->getAttribute('xmlns'));
	}

	public function testBodyIsStripped(): void
	{
		$doc = $this->createDocument(withBody: true);
		$this->assertCount(1, $doc->getElementsByTagName('body'));

		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), null);

		$this->assertCount(0, $doc->getElementsByTagName('body'));
	}

	public function testDocumentWithoutBodyIsUnaffected(): void
	{
		$doc = $this->createDocument(withBody: false);
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), null);
		$this->assertCount(0, $doc->getElementsByTagName('body'));
	}

	public function testAuthorEmailsStrippedWhenPrePublicationAccessNotAllowed(): void
	{
		$doc = $this->createDocument(
			'<contrib-group><contrib><email>author@example.org</email></contrib></contrib-group>'
			. '<author-notes><corresp id="corresp-1"><email>corresp@example.org</email></corresp></author-notes>'
		);

		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), null, allowedPrePublicationAccess: false);

		$this->assertCount(0, $doc->getElementsByTagName('email'));
		// The surrounding structure (contrib, corresp) is left intact - only the email leaf is redacted.
		$this->assertCount(1, $doc->getElementsByTagName('contrib'));
		$this->assertCount(1, $doc->getElementsByTagName('corresp'));
	}

	public function testAuthorEmailsKeptWhenPrePublicationAccessAllowed(): void
	{
		$doc = $this->createDocument(
			'<contrib-group><contrib><email>author@example.org</email></contrib></contrib-group>'
			. '<author-notes><corresp id="corresp-1"><email>corresp@example.org</email></corresp></author-notes>'
		);

		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), null, allowedPrePublicationAccess: true);

		$this->assertCount(2, $doc->getElementsByTagName('email'));
	}

	public function testCreatesPermissionsAndFreeToReadWhenOpenAccessAndNoneExists(): void
	{
		$doc = $this->createDocument();
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), 'openAccess');

		// setAttribute('xmlns:ali', ...) is treated by libxml as a namespace
		// declaration rather than a plain attribute, so it isn't retrievable
		// via getAttribute()/hasAttribute() - but it IS correctly serialized,
		// which is what actually matters for the OAI consumer. Assert on the
		// serialized output instead.
		$this->assertStringContainsString('xmlns:ali="http://www.niso.org/schemas/ali/1.0"', $doc->saveXML());

		$permissions = $doc->getElementsByTagName('permissions');
		$this->assertCount(1, $permissions);

		$freeToRead = $doc->getElementsByTagName('ali:free_to_read');
		$this->assertCount(1, $freeToRead);
		$this->assertSame('', $freeToRead->item(0)->getAttribute('start_date'));

		// permissions must land before self-uri per JATS DTD order.
		$xpath = new DOMXPath($doc);
		$permissionsAndSelfUri = $xpath->query('//article/front/article-meta/*[self::permissions or self::self-uri]');
		$this->assertEquals('permissions', $permissionsAndSelfUri->item(0)->nodeName);
		$this->assertEquals('self-uri', $permissionsAndSelfUri->item(1)->nodeName);
	}

	public function testAppendsFreeToReadToExistingPermissions(): void
	{
		$doc = $this->createDocument('<permissions><copyright-statement>All rights reserved</copyright-statement></permissions>');
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), 'openAccess');

		$this->assertCount(1, $doc->getElementsByTagName('permissions'));
		$this->assertCount(1, $doc->getElementsByTagName('copyright-statement'));
		$this->assertCount(1, $doc->getElementsByTagName('ali:free_to_read'));
	}

	public function testEmbargoedAccessSetsFreeToReadStartDate(): void
	{
		$doc = $this->createDocument();
		$issue = $this->createIssue(['accessStatus' => 2]);
		// getOpenAccessDate() reads the 'openAccessDate' data field.
		$issue->setData('openAccessDate', '2030-06-15 00:00:00');

		$this->invokeAugment($doc, $issue, $this->createPublication(), new OpenAIREPlugin(), 'embargoedAccess');

		$freeToRead = $doc->getElementsByTagName('ali:free_to_read');
		$this->assertCount(1, $freeToRead);
		$this->assertEquals('2030-06-15', $freeToRead->item(0)->getAttribute('start_date'));
	}

	public function testNoFreeToReadWhenNotOpenAccessOrEmbargoed(): void
	{
		$doc = $this->createDocument();
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), 'restrictedAccess');

		$this->assertCount(0, $doc->getElementsByTagName('permissions'));
		$this->assertCount(0, $doc->getElementsByTagName('ali:free_to_read'));
	}

	public function testCreatesCustomMetaGroupWithAccessRightAndResourceType(): void
	{
		$doc = $this->createDocument();
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), 'openAccess');

		$groups = $doc->getElementsByTagName('custom-meta-group');
		$this->assertCount(1, $groups);
		$entries = $groups->item(0)->getElementsByTagName('custom-meta');
		$this->assertCount(2, $entries);

		$specificUses = [];
		foreach ($entries as $entry) {
			$specificUses[] = $entry->getAttribute('specific-use');
		}
		$this->assertEqualsCanonicalizing(['access-right', 'resource-type'], $specificUses);
	}

	public function testAppendsToExistingCustomMetaGroup(): void
	{
		$doc = $this->createDocument(
			'<custom-meta-group><custom-meta specific-use="issue-cover"><meta-name>issue-cover</meta-name></custom-meta></custom-meta-group>'
		);
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), 'openAccess');

		$groups = $doc->getElementsByTagName('custom-meta-group');
		$this->assertCount(1, $groups);
		$this->assertCount(3, $groups->item(0)->getElementsByTagName('custom-meta'));
	}

	public function testNoCustomMetaGroupWhenNoAccessRightsOrResourceType(): void
	{
		$doc = $this->createDocument();
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), null, '');

		$this->assertCount(0, $doc->getElementsByTagName('custom-meta-group'));
	}

	public function testAddsSubjectsAsSeparateKwdGroupsFromKeywords(): void
	{
		$doc = $this->createDocument(
			'<kwd-group xml:lang="en"><kwd>existing-keyword</kwd></kwd-group>'
		);
		$publication = $this->createPublication([
			'subjects' => [
				'en' => [['name' => 'Subject One'], ['name' => 'Subject Two']],
			],
		]);

		$this->invokeAugment($doc, null, $publication, new OpenAIREPlugin(), null);

		$kwdGroups = $doc->getElementsByTagName('kwd-group');
		$this->assertCount(2, $kwdGroups);

		// The pre-existing keyword group is untouched.
		$this->assertCount(1, $kwdGroups->item(0)->getElementsByTagName('kwd'));
		$this->assertEquals('existing-keyword', $kwdGroups->item(0)->getElementsByTagName('kwd')->item(0)->textContent);

		// The new subjects group has both subject entries.
		$subjectKwds = $kwdGroups->item(1)->getElementsByTagName('kwd');
		$this->assertCount(2, $subjectKwds);
		$this->assertEquals('Subject One', $subjectKwds->item(0)->textContent);
		$this->assertEquals('Subject Two', $subjectKwds->item(1)->textContent);
	}

	public function testNoSubjectsMeansNoAdditionalKwdGroup(): void
	{
		$doc = $this->createDocument();
		$this->invokeAugment($doc, null, $this->createPublication(), new OpenAIREPlugin(), null);
		$this->assertCount(0, $doc->getElementsByTagName('kwd-group'));
	}

	public function testSubjectsAppendedAtEndWhenNoKeywordsOrOtherAnchors(): void
	{
		// No existing kwd-group, and no accessRights/resourceType means no
		// custom-meta-group gets created either - nothing for subjects to be
		// inserted before, so they land at the very end of article-meta.
		$doc = $this->createDocument();
		$publication = $this->createPublication([
			'subjects' => ['en' => [['name' => 'Subject One']]],
		]);

		$this->invokeAugment($doc, null, $publication, new OpenAIREPlugin(), null, '');

		$articleMeta = $doc->getElementsByTagName('article-meta')->item(0);
		$this->assertEquals('kwd-group', $articleMeta->lastChild->nodeName);
	}

	public function testSubjectsInsertedBeforeCustomMetaGroupWhenNoKeywords(): void
	{
		// No existing kwd-group, but accessRights forces a new custom-meta-group
		// to be created - subjects must land before it, not after.
		$doc = $this->createDocument();
		$publication = $this->createPublication([
			'subjects' => ['en' => [['name' => 'Subject One']]],
		]);

		$this->invokeAugment($doc, null, $publication, new OpenAIREPlugin(), 'openAccess');

		$articleMeta = $doc->getElementsByTagName('article-meta')->item(0);
		$this->assertEquals('custom-meta-group', $articleMeta->lastChild->nodeName);
		$this->assertEquals('kwd-group', $articleMeta->lastChild->previousSibling->nodeName);
	}

	public function testSubjectsInsertedBeforeFundingGroupWhenNoKeywords(): void
	{
		// funding-group (from jatsTemplate) and counts both already exist; subjects
		// must land before funding-group specifically, since it's first in document
		// order - not before whichever of the two the xpath alternatives lists first.
		$doc = $this->createDocument(
			'<funding-group><award-group><funding-source/></award-group></funding-group>'
			. '<counts><page-count count="10"/></counts>'
		);
		$publication = $this->createPublication([
			'subjects' => ['en' => [['name' => 'Subject One']]],
		]);

		$this->invokeAugment($doc, null, $publication, new OpenAIREPlugin(), null);

		$articleMeta = $doc->getElementsByTagName('article-meta')->item(0);
		$nodeNames = array_map(
			fn ($node) => $node->nodeName,
			iterator_to_array($articleMeta->childNodes)
		);
		$kwdGroupIndex = array_search('kwd-group', $nodeNames, true);
		$fundingGroupIndex = array_search('funding-group', $nodeNames, true);
		$countsIndex = array_search('counts', $nodeNames, true);

		$this->assertNotFalse($kwdGroupIndex);
		$this->assertLessThan($fundingGroupIndex, $kwdGroupIndex);
		$this->assertLessThan($countsIndex, $fundingGroupIndex);
	}

	public function testGetAccessRightsSubscriptionModeWithNoIssueIsOpenAccess(): void
	{
		// A publication can now exist with no issue at all (continuous-publication
		// journals). Subscription-based access control is issue-level, so without
		// an issue there's no subscription gate to enforce.
		$journal = $this->createJournal(['publishingMode' => Journal::PUBLISHING_MODE_SUBSCRIPTION]);
		$plugin = new OpenAIREPlugin();

		$this->assertEquals('openAccess', $plugin->getAccessRights($journal, null, $this->createPublication()));
	}

	public function testGetAccessRightsSubscriptionModeWithIssueStillGated(): void
	{
		// Regression guard: a subscription-gated issue with no per-article
		// override and no open-access date is still metadata-only.
		$journal = $this->createJournal(['publishingMode' => Journal::PUBLISHING_MODE_SUBSCRIPTION]);
		$issue = $this->createIssue(['accessStatus' => Issue::ISSUE_ACCESS_SUBSCRIPTION]);
		$plugin = new OpenAIREPlugin();

		$this->assertEquals('metadataOnlyAccess', $plugin->getAccessRights($journal, $issue, $this->createPublication()));
	}

	public function testMapCoarResourceTypeToJatsArticleType(): void
	{
		$format = new OAIMetadataFormat_OpenAIREJats('oai_openaire_jats', '', '');
		$this->assertEquals('research-article', $format->mapCoarResourceTypeToJatsArticleType('http://purl.org/coar/resource_type/c_6501'));
		$this->assertEquals('review-article', $format->mapCoarResourceTypeToJatsArticleType('http://purl.org/coar/resource_type/c_dcae04bc'));
		$this->assertNull($format->mapCoarResourceTypeToJatsArticleType('http://purl.org/coar/resource_type/does-not-exist'));
	}
}
