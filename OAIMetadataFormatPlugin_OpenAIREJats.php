<?php

/**
 * @file OAIMetadataFormatPlugin_OpenAIREJats.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OAIMetadataFormatPlugin_OpenAIREJats
 * @ingroup oai_format_openaire
 * @see OAI
 *
 * @brief OAI JATS XML format plugin for OpenAIRE.
 */

namespace APP\plugins\generic\openAIRE;

use PKP\plugins\OAIMetadataFormatPlugin;

class OAIMetadataFormatPlugin_OpenAIREJats extends OAIMetadataFormatPlugin {
	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 */
	public function getName(): string
	{
		return 'OAIMetadataFormatPlugin_OpenAIREJats';
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName(): string
	{
		return __('plugins.oaiMetadata.openAIRE.jats.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription(): string
	{
		return __('plugins.oaiMetadata.openAIRE.jats.description');
	}

	public function getFormatClass(): string
	{
		return '\APP\plugins\generic\openAIRE\OAIMetadataFormat_OpenAIREJats';
	}

	public static function getMetadataPrefix(): string
	{
		return 'oai_openaire_jats';
	}

	public static function getSchema(): string
	{
		return 'https://jats.nlm.nih.gov/publishing/1.2/xsd/JATS-journalpublishing1.xsd';
	}

	public static function getNamespace(): string
	{
		return 'https://jats.nlm.nih.gov/publishing/1.2/';
	}
}
