<?php
namespace H4ck3r31\SolrUtility\IndexQueue;

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

use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\IndexQueue\PageIndexer;
use TYPO3\CMS\Core\Database\DatabaseConnection;

class PreventNonTranslatedPageIndexer extends PageIndexer
{
    /**
     * Denies access if page does not have a real page translation.
     *
     * @param Item $item
     * @param int $language
     * @return array
     */
    public function getAccessGroupsFromContent(Item $item, $language = 0)
    {
        $language = (int)$language;
        $pageId = (int)$item->getRecordUid();

        if ($language === 0 || $this->hasPageTranslation($pageId, $language)) {
            return parent::getAccessGroupsFromContent($item, $language);
        }

        return [];
    }

    /**
     * @param int $pageId
     * @param int $language
     * @return bool
     */
    private function hasPageTranslation($pageId, $language)
    {
        $predicates = [
            'deleted=0',
            'hidden=0',
            'sys_language_uid=' . $language,
            'pid=' . $pageId,
        ];

        $translation = $this->getDatabaseConnection()->exec_SELECTgetSingleRow(
            'uid',
            'pages_language_overlay',
            implode(' AND ', $predicates)
        );

        return !empty($translation);
    }

    /**
     * @return DatabaseConnection
     */
    private function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
