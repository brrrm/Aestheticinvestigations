<?php

/**
 * @file plugins/generic/announcementFeed/AnnouncementFeedGatewayPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AnnouncementFeedGatewayPlugin
 * @ingroup plugins_generic_announcementFeed
 *
 * @brief Gateway component of announcement feed plugin
 *
 */

use PKP\plugins\GatewayPlugin;
use APP\facades\Repo;
use APP\template\TemplateManager;

class AnnouncementFeedGatewayPlugin extends GatewayPlugin
{
    protected $_parentPlugin;

    /**
     * Constructor
     *
     * @param $parentPlugin AnnouncementFeedPlugin
     */
    public function __construct($parentPlugin)
    {
        $this->_parentPlugin = $parentPlugin;
        parent::__construct();
    }

    /**
     * Get the name of this plugin. The name must be unique within
     * its category.
     *
     * @return String name of plugin
     */
    public function getName()
    {
        return 'AnnouncementFeedGatewayPlugin';
    }

    /**
     * Hide this plugin from the management interface (it's subsidiary)
     */
    public function getHideManagement()
    {
        return true;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.announcementfeed.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.announcementfeed.description');
    }

    /**
     * Override the builtin to get the correct plugin path.
     */
    public function getPluginPath()
    {
        return $this->_parentPlugin->getPluginPath();
    }

    /**
     * Get whether or not this plugin is enabled. (Should always return true, as the
     * parent plugin will take care of loading this one when needed)
     *
     * @return boolean
     */
    public function getEnabled()
    {
        return $this->_parentPlugin->getEnabled();
    }

    /**
     * Handle fetch requests for this plugin.
     *
     * @param $args array
     * @param $request PKPRequest
     */
    public function fetch($args, $request)
    {
        // Make sure we're within a Journal context
        $journal = $request->getJournal();
        if (!$journal) {
            return false;
        }

        // Make sure announcements and plugin are enabled
        $announcementsEnabled = $journal->getData('enableAnnouncements');
        if (!$announcementsEnabled || !$this->_parentPlugin->getEnabled()) {
            return false;
        }

        // Make sure the feed type is specified and valid
        $type = array_shift($args);
        $typeMap = [
            'rss' => 'rss.tpl',
            'rss2' => 'rss2.tpl',
            'atom' => 'atom.tpl'
        ];
        $mimeTypeMap = [
            'rss' => 'application/rdf+xml',
            'rss2' => 'application/rss+xml',
            'atom' => 'application/atom+xml'
        ];
        if (!isset($typeMap[$type])) {
            return false;
        }

        // Get limit setting, if any
        $collector = Repo::announcement()->getCollector()->filterByContextIds([$journal->getId()]);
        $recentItems = (int) $this->_parentPlugin->getSetting($journal->getId(), 'recentItems');
        if ($recentItems > 0) {
            $collector->limit($recentItems);
        }
        $announcements = Repo::announcement()->getMany($collector);

        // Get date of most recent announcement
        $lastDateUpdated = $this->_parentPlugin->getSetting($journal->getId(), 'dateUpdated');
        if (empty($announcements)) {
            if (empty($lastDateUpdated)) {
                $dateUpdated = Core::getCurrentDate();
                $this->_parentPlugin->updateSetting($journal->getId(), 'dateUpdated', $dateUpdated, 'string');
            } else {
                $dateUpdated = $lastDateUpdated;
            }
        } else {
            $dateUpdated = $announcements->first()->getDatetimePosted();
            if (empty($lastDateUpdated) || (strtotime($dateUpdated) > strtotime($lastDateUpdated))) {
                $this->_parentPlugin->updateSetting($journal->getId(), 'dateUpdated', $dateUpdated, 'string');
            }
        }

        $versionDao = DAORegistry::getDAO('VersionDAO'); /* @var $versionDao VersionDAO */
        $version = $versionDao->getCurrentVersion();

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'ojsVersion' => $version->getVersionString(),
            'selfUrl' => $request->getCompleteUrl(),
            'dateUpdated' => $dateUpdated,
            'announcements' => $announcements,
            'journal' => $journal,
        ]);

        $templateMgr->display($this->_parentPlugin->getTemplateResource($typeMap[$type]), $mimeTypeMap[$type]);

        return true;
    }
}
