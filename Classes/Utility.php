<?php
namespace H4ck3r31\SolrUtility;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use ApacheSolrForTypo3\Solr\Util;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\TimeTracker\NullTimeTracker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * @internal
 * @experimental
 */
class Utility extends Util
{
    /**
     * @param int $pageId
     * @param int $language
     * @param bool $useCache
     * @param array|null $additionalTypoScript
     */
    public static function initializeTsfe(
        $pageId,
        $language = 0,
        $useCache = true,
        array $additionalTypoScript = null
    ) {
        static $tsfeCache = [];

        // resetting, a TSFE instance with data from a different page Id could be set already
        unset($GLOBALS['TSFE']);

        $cacheId = $pageId . '|' . $language;

        if (!is_object($GLOBALS['TT'])) {
            $GLOBALS['TT'] = GeneralUtility::makeInstance(NullTimeTracker::class);
        }

        if (!isset($tsfeCache[$cacheId]) || !$useCache) {
            GeneralUtility::_GETset($language, 'L');

            /** @var TypoScriptFrontendController $frontend */
            $GLOBALS['TSFE'] = $frontend = GeneralUtility::makeInstance(
                TypoScriptFrontendController::class,
                $GLOBALS['TYPO3_CONF_VARS'],
                $pageId,
                0
            );

            // for certain situations we need to trick TSFE into granting us
            // access to the page in any case to make getPageAndRootline() work
            // see http://forge.typo3.org/issues/42122
            $pageRecord = BackendUtility::getRecord('pages', $pageId, 'fe_group');
            $groupListBackup = $frontend->gr_list;
            $frontend->gr_list = $pageRecord['fe_group'];

            $frontend->sys_page = GeneralUtility::makeInstance(PageRepository::class);
            $frontend->getPageAndRootline();

            // restore gr_list
            $frontend->gr_list = $groupListBackup;

            $frontend->initTemplate();
            $frontend->forceTemplateParsing = true;
            $frontend->initFEuser();
            $frontend->initUserGroups();
            //  $frontend->getCompressedTCarray(); // seems to cause conflicts sometimes

            $frontend->no_cache = true;
            $frontend->tmpl->start($frontend->rootLine);
            $frontend->no_cache = false;
            $frontend->getConfigArray();

            // @todo Refactor ext:solr to inject custom TypoScript
            if (!empty($additionalTypoScript['config.'])) {
                $frontend->config['config'] = array_merge(
                    $frontend->config['config'],
                    $additionalTypoScript['config.']
                );
            }
            $frontend->settingLanguage();
            if (!$useCache) {
                $frontend->settingLocale();
            }

            $frontend->newCObj();
            $frontend->absRefPrefix = self::getAbsRefPrefixFromTSFE($frontend);
            $frontend->calculateLinkVars();

            if ($useCache) {
                $tsfeCache[$cacheId] = $frontend;
            }
        }

        if ($useCache) {
            $GLOBALS['TSFE'] = $frontend = $tsfeCache[$cacheId];
            $frontend->settingLocale();
        }
    }

}
