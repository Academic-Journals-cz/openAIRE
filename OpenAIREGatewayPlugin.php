<?php

/**
 * @file OpenAIREGatewayPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OpenAIREGatewayPlugin
 * @ingroup plugins_gateways_OpenAIREGateway
 *
 * @brief OpenAIRE (COAR/DataCite) gateway plugin
 */

namespace APP\plugins\generic\openAIRE;

use APP\core\Application;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\template\TemplateManager;
use PKP\db\DAORegistry;
use PKP\plugins\GatewayPlugin;

class OpenAIREGatewayPlugin extends GatewayPlugin {
	protected OpenAIREPlugin $_parentPlugin;

	/**
	 * Constructor
	 */
	public function __construct(OpenAIREPlugin $parentPlugin)
	{
		$this->_parentPlugin = $parentPlugin;
		parent::__construct();
	}

	/**
	 * Intentionally kept as the pre-rename class name string for URL
	 * backward-compat (.../gateway/plugin/OpenAIREstandardGatewayPlugin/objects).
	 */
	public function getName(): string
	{
		return 'OpenAIREstandardGatewayPlugin';
	}

	public function getDisplayName(): string
	{
		return __('plugins.generic.openAIRE.gateway.displayName');
	}

	public function getDescription(): string
	{
		return __('plugins.generic.openAIRE.gateway.description');
	}

	public function getPluginPath(): string
	{
		return $this->_parentPlugin->getPluginPath();
	}

	public function getHideManagement(): bool
	{
		return true;
	}

	public function getEnabled(): bool
	{
		return $this->_parentPlugin->getEnabled();
	}

	/**
	 * Handle fetch requests for this plugin.
	 */
	public function fetch($args, $request): bool
	{
		if (!$this->getEnabled()) {
			return false;
		}

		$scheme = array_shift($args);
		switch ($scheme) {
			case 'objects':
				$this->showObjects();
				break;
		}

		// Failure.
		header('HTTP/1.0 404 Not Found');
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('message', 'plugins.generic.openAIRE.gateway.errorMessage');
		$templateMgr->display('frontend/pages/message.tpl');
		exit;
	}

	protected function showObjects(): void
	{
		/** @var JournalDAO $journalDao	*/
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$journals = $journalDao->getAll(true);
		$request = $this->getRequest();
		$dispatcher = $request->getDispatcher();
		header('content-type: text/plain');
		header('content-disposition: attachment; filename=objects-' . date("Y-m-d") . '.txt');
		$journalData = [];
		while ($journal = $journals->next()) { /** @var Journal $journal */
			if ( ($journal->getData('onlineIssn') || $journal->getData('printIssn') ) && $journal->getEnabled() && $journal->getData('publishingMode') != Journal::PUBLISHING_MODE_NONE) {
					$journalData[$journal->getId()]['url'] = $dispatcher->url($request, Application::ROUTE_PAGE, $journal->getPath());
					$journalData[$journal->getId()]['issn'] = $journal->getData('printIssn');
					$journalData[$journal->getId()]['eissn'] = $journal->getData('onlineIssn');
					$journalData[$journal->getId()]['primaryLanguage'] = $journal->getPrimaryLocale();
					$journalData[$journal->getId()]['name'] = $journal->getName(null);
			}
		}
		echo json_encode($journalData);
		exit;
	}
}
