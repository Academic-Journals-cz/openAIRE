<?php

/**
 * @file plugins/generic/openAIRE/OpenAIREPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OpenAIREPlugin
 * @ingroup plugins_generic_openAIRE
 *
 * @brief OpenAIRE plugin class
 */

namespace APP\plugins\generic\openAIRE;

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\journal\Journal;
use APP\publication\Publication;
use APP\submission\Submission;
use stdClass;
use Illuminate\Database\Migrations\Migration;
use PKP\db\DAORegistry;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\site\VersionDAO;
use APP\plugins\generic\openAIRE\OAIMetadataFormatPlugin_OpenAIRE;
use APP\plugins\generic\openAIRE\OAIMetadataFormatPlugin_OpenAIREJats;
use APP\plugins\generic\openAIRE\OpenAIREGatewayPlugin;
use APP\plugins\generic\openAIRE\OpenAIREJatsGatewayPlugin;
use APP\plugins\generic\openAIRE\OpenAIREstandardCleanupMigration;

class OpenAIREPlugin extends GenericPlugin {

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }

        if (Application::isUnderMaintenance()) {
            return true;
        }

        if ($this->getEnabled($mainContextId)) {
            PluginRegistry::register('oaiMetadataFormats', new OAIMetadataFormatPlugin_OpenAIRE(), $this->getPluginPath());
            PluginRegistry::register('oaiMetadataFormats', new OAIMetadataFormatPlugin_OpenAIREJats(), $this->getPluginPath());
            PluginRegistry::register('gateways', new OpenAIREGatewayPlugin($this), $this->getPluginPath());
            PluginRegistry::register('gateways', new OpenAIREJatsGatewayPlugin($this), $this->getPluginPath());

            # Handle COAR resource types in section forms
            Hook::add('Schema::get::section', [$this, 'addToSchema']);
            Hook::add('Templates::Manager::Sections::SectionForm::AdditionalMetadata', [$this, 'addSectionFormFields']);
            Hook::add('sectionform::initdata', [$this, 'initDataSectionFormFields']);
            Hook::add('sectionform::readuservars', [$this, 'readSectionFormFields']);
            Hook::add('sectionform::execute', [$this, 'executeSectionFormFields']);

            $this->cleanupOpenAIREstandardIfNeeded();
        }
        return $success;
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     *
     * Covers manual installs done via tools/installPluginVersion.php, which
     * calls this directly (not via a hook), so it isn't affected by the
     * install.xml/hook timing issue noted below.
     */
    public function getInstallMigration(): ?Migration
    {
        return new OpenAIREstandardCleanupMigration();
    }

    /**
     * Fallback cleanup of leftover openAIREstandard data, for anything
     * install.xml and getInstallMigration() miss (e.g. a plugin folder
     * that only ever got auto-registered via the admin Plugins page).
     * Runs on every enabled page load; self-limiting since it finds
     * nothing once the leftover row is disabled.
     */
    protected function cleanupOpenAIREstandardIfNeeded(): void
    {
        /** @var VersionDAO $versionDao */
        $versionDao = DAORegistry::getDAO('VersionDAO');
        if ($versionDao->getCurrentVersion('plugins.generic', 'openAIREstandard')) {
            (new OpenAIREstandardCleanupMigration())->up();
        }
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.openAIRE.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.openAIRE.description');
    }

    /**
     * Extend the section entity's schema with an resourceType property
     */
    public function addToSchema(string $hookName, array $args): bool
    {
        /** @var stdClass $schema */
        $schema = $args[0];
        $schema->properties->resourceType = (object) [
                    'type' => 'string',
                    'apiSummary' => true,
                    'multilingual' => false,
                    'validation' => ['nullable']
        ];
        $schema->properties->audience = (object) [
                    'type' => 'string',
                    'apiSummary' => true,
                    'multilingual' => false,
                    'validation' => ['nullable']
        ];
        return false;
    }

    public function addSectionFormFields(string $hookName, array $args): bool
    {
        $smarty = & $args[1];
        $output = & $args[2];
        $smarty->assign('resourceTypeOptions', $this->getResourceTypeOptions());
        $smarty->assign('audienceOptions', $this->getAudienceOptions());
        $output .= $smarty->fetch($this->getTemplateResource('controllers/grids/settings/section/form/sectionFormAdditionalFields.tpl'));
        return false;
    }

    /**
     * Initialize data when form is first loaded
     *
     * @param $hookName string `sectionform::initData`
     * @parram $args array [
     * 		@option SectionForm
     * ]
     */
    public function initDataSectionFormFields(string $hookName, array $args): void
    {
        $sectionForm = $args[0];
        $sectionId = $sectionForm->getSectionId();
        if (!$sectionId) {
            // New section being added; nothing to pre-populate yet.
            return;
        }
        $context = Application::get()->getRequest()->getContext();
        $section = Repo::section()->get($sectionId, $context?->getId());
        if ($section) {
            $sectionForm->setData('resourceType', $section->getData('resourceType'));
            $sectionForm->setData('audience', $section->getData('audience'));
        }
    }

    /**
     * Read user input from additional fields in the section editing form
     *
     * @param $hookName string `sectionform::readUserVars`
     * @parram $args array [
     * 		@option SectionForm
     * 		@option array User vars
     * ]
     */
    public function readSectionFormFields(string $hookName, array $args): void
    {
        $sectionForm = & $args[0];
        $request = Application::get()->getRequest();
        $sectionForm->setData('resourceType', $request->getUserVar('resourceType'));
        $sectionForm->setData('audience', $request->getUserVar('audience'));
    }

    /**
     * Save additional fields in the section editing form
     *
     * @param $hookName string `sectionform::execute`
     */
    public function executeSectionFormFields(string $hookName, array $args): void
    {
        $sectionForm = $args[0];
        $resourceType = $sectionForm->getData('resourceType') ? $sectionForm->getData('resourceType') : '';
        $audience = $sectionForm->getData('audience') ? $sectionForm->getData('audience') : '';

        // Only accept values from the known option lists; the form only
        // ever offers these via a <select>, but that's a UI restriction,
        // not a server-side guarantee, so re-validate here.
        if ($resourceType !== '' && !array_key_exists($resourceType, $this->getCoarResourceTypes())) {
            $resourceType = '';
        }
        if ($audience !== '' && !array_key_exists($audience, $this->getAudienceOptions())) {
            $audience = '';
        }

        if (!empty($resourceType) || !empty($audience)) {
            $context = Application::get()->getRequest()->getContext();
            $section = Repo::section()->get($sectionForm->getSectionId(), $context?->getId());
            if ($section) {
                $section->setData('resourceType', $resourceType);
                $section->setData('audience', $audience);
                Repo::section()->edit($section, []);
            }
        }
    }

    /**
     * Get a COAR Resource Type label by URI.
     */
    public function getCoarResourceType(string $uri): ?string
    {
        return $this->getCoarResourceTypes()[$uri] ?? null;
    }

    /**
     * Get all COAR Resource Type Genres, keyed by URI.
     */
    protected function getCoarResourceTypes(): array
    {
        return [
            'http://purl.org/coar/resource_type/c_6501' => 'journal article',
            'http://purl.org/coar/resource_type/c_2df8fbb1' => 'research article',
            'http://purl.org/coar/resource_type/c_dcae04bc' => 'review article',
            'http://purl.org/coar/resource_type/c_beb9' => 'data paper',
            'http://purl.org/coar/resource_type/c_7bab' => 'software paper',
            'http://purl.org/coar/resource_type/c_b239' => 'editorial',
            'http://purl.org/coar/resource_type/c_545b' => 'letter to the editor',
            'http://purl.org/coar/resource_type/c_93fc' => 'report',
            'http://purl.org/coar/resource_type/c_efa0' => 'review',
            'http://purl.org/coar/resource_type/c_ba08' => 'book review',
            'http://purl.org/coar/resource_type/c_26e4' => 'interview',
            'http://purl.org/coar/resource_type/c_8544' => 'lecture',
            'http://purl.org/coar/resource_type/c_5794' => 'conference paper',
            'http://purl.org/coar/resource_type/c_46ec' => 'thesis',
            'http://purl.org/coar/resource_type/c_8042' => 'working paper',
            'http://purl.org/coar/resource_type/c_816b' => 'preprint',
            'http://purl.org/coar/resource_type/c_1843' => 'other',
        ];
    }

    public const COAR_ACCESS_RIGHTS = [
        'openAccess' => ['label' => 'open access', 'url' => 'http://purl.org/coar/access_right/c_abf2'],
        'embargoedAccess' => ['label' => 'embargoed access', 'url' => 'http://purl.org/coar/access_right/c_abf2'],
        'restrictedAccess' => ['label' => 'restricted access', 'url' => 'http://purl.org/coar/access_right/c_abf2'],
        'metadataOnlyAccess' => ['label' => 'metadata only access', 'url' => 'http://purl.org/coar/access_right/c_abf2'],
    ];

    /**
     * Get article access rights. Shared by both OAI metadata formats
     * (COAR/DataCite and JATS), which need identical access-rights logic.
     *
     * A publication may have no issue at all (e.g. a continuous-publication
     * journal that doesn't use issues). Subscription-based access control is
     * an issue-level concept, so without an issue there's no subscription
     * gate to check - same as IssueAction::subscriptionRequired()'s guard and
     * oaiJats's own no-issue handling, which both treat "no issue" as
     * unrestricted access.
     */
    public function getAccessRights(Journal $journal, ?Issue $issue, Publication $publication): ?string
    {
        $accessRights = null;
        if ($journal->getData('publishingMode') == Journal::PUBLISHING_MODE_OPEN) {
            $accessRights = 'openAccess';
        } else if ($journal->getData('publishingMode') == Journal::PUBLISHING_MODE_SUBSCRIPTION) {
            if (!$issue || $issue->getAccessStatus() == 0 || $issue->getAccessStatus() == Issue::ISSUE_ACCESS_OPEN) {
                $accessRights = 'openAccess';
            } else if ($issue->getAccessStatus() == Issue::ISSUE_ACCESS_SUBSCRIPTION) {
                if ($publication->getData('accessStatus') == Submission::ARTICLE_ACCESS_OPEN) {
                    $accessRights = 'openAccess';
                } else if ($issue->getOpenAccessDate() != null) {
                    $accessRights = 'embargoedAccess';
                } else {
                    $accessRights = 'metadataOnlyAccess';
                }
            }
        }
        if ($journal->getData('restrictSiteAccess') == 1 || $journal->getData('restrictArticleAccess') == 1) {
            $accessRights = 'restrictedAccess';
        }
        return $accessRights;
    }

    /**
     * Get an associative array containing page info, or null if the
     * publication's pages aren't expressible as a single numeric range.
     * Shared by both OAI metadata formats, which both need to reject
     * non-numeric/multi-range pagination rather than guess (see
     * citationStartPage/citationEndPage and fpage/lpage/page-count usage
     * in each format's toXml()).
     */
    public function getPageInfo(Publication $publication): ?array
    {
        $ranges = $publication->getPageArray();
        if (count($ranges) !== 1) {
            return null;
        }
        $fpage = $ranges[0][0] ?? null;
        $lpage = $ranges[0][1] ?? $fpage;
        if (!is_numeric($fpage) || !is_numeric($lpage) || $lpage < $fpage) {
            return null;
        }
        return ['fpage' => htmlspecialchars($fpage), 'lpage' => htmlspecialchars($lpage), 'pagecount' => $lpage - $fpage + 1];
    }

    /**
     * Get an associative array of all COAR Resource Type Genres for select element
     * (Includes default '' => "Choose One" string.)
     * @return array resourceTypeUri => resourceTypeLabel
     */
    protected function getResourceTypeOptions(): array
    {
        $resourceTypeOptions = $this->getCoarResourceTypes();
        $chooseOne = __('common.chooseOne');
        $chooseOneOption = ['' => $chooseOne];
        $resourceTypeOptions = $chooseOneOption + $resourceTypeOptions;
        return $resourceTypeOptions;
    }

    /**
     * Get an array of Audience tapes for select element
     * (Includes default '' => "Choose One" string.)
     * @return array resourceTypeUri => resourceTypeLabel
     */
    protected function getAudienceOptions(): array
    {
        $audience = [
            '' => __('common.chooseOne'),
            'Administrators' => 'Administrators',
            'Community Groups' => 'Community Groups',
            'Counsellors' => 'Counsellors',
            'Federal Funds Recipients and Applicants' => 'Federal Funds Recipients and Applicants',
            'Librarians' => 'Librarians',
            'News Media' => 'News Media',
            'Other' => 'Other',
            'Parents and Families' => 'Parents and Families',
            'Policymakers' => 'Policymakers',
            'Researchers' => 'Researchers',
            'School Support Staff' => 'School Support Staff',
            'Student Financial Aid Providers' => 'Student Financial Aid Providers',
            'Students' => 'Students',
            'Teachers' => 'Teachers',
        ];
        return $audience;
    }
}
