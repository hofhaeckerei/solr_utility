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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
        $queryBuilder = $this->getConnectionPool()
            ->getQueryBuilderForTable('pages_language_overlay');
        $statement = $queryBuilder
            ->select('uid')
            ->from('pages_language_overlay')
            ->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter(
                            $language,
                            Connection::PARAM_INT
                        )
                    ),
                    $queryBuilder->expr()->eq(
                        'pid',
                        $queryBuilder->createNamedParameter(
                            $pageId,
                            Connection::PARAM_INT
                        )
                    )
                )
            )
            ->setMaxResults(1)
            ->execute();

        $translation = $statement->fetch();
        return !empty($translation['uid']);
    }

    /**
     * @return ConnectionPool
     */
    private function getConnectionPool()
    {
        /** @var ConnectionPool $connectionPool */
        $connectionPool = GeneralUtility::makeInstance(
            ConnectionPool::class
        );
        return $connectionPool;
    }
}
