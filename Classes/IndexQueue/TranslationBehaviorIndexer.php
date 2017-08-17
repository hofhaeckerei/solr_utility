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

use ApacheSolrForTypo3\Solr\IndexQueue\Indexer;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\SolrService;
use H4ck3r31\SolrUtility\Utility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * @internal
 * @experimental
 */
class TranslationBehaviorIndexer extends Indexer
{
    /**
     * Gets the Solr connections applicable for an item.
     *
     * The connections include the default connection and connections to be used
     * for translations of an item.
     *
     * @param Item $item An index queue item
     * @return array An array of ApacheSolrForTypo3\Solr\SolrService connections, the array's keys are the sys_language_uid of the language of the connection
     */
    protected function getSolrConnectionsByItem(Item $item)
    {
        $languageFieldName = $this->getLocalizationLanguageFieldName(
            $item->getType()
        );
        $parentFieldName = $this->getLocalizationParentFieldName(
            $item->getType()
        );

        // let default Solr Indexer handle this, if table is not translatable
        if ($languageFieldName === null) {
            return parent::getSolrConnectionsByItem($item);
        }

        $record = $item->getRecord();
        $recordLanguage = (int)$record[$languageFieldName];

        $site = $item->getSite();
        $solrConfigurationsBySite = $this->connectionManager->getConfigurationsBySite($site);

        $solrLanguages = array_map(
            function (array $solrConfiguration) {
                return (int)$solrConfiguration['language'];
            },
            $solrConfigurationsBySite
        );

        // use all Solr configurations for "all language" items
        if ($recordLanguage === -1) {
            return $this->getSolrConnectionsFor($item, $solrLanguages);
        }
        // let default Solr Indexer handle this, in case localizations cannot
        // be resolved due to the missing localization parent pointer field
        if ($parentFieldName === null) {
            return parent::getSolrConnectionsByItem($item);
        }
        // let default Solr Indexer handle this, if localized item is already
        // in indexing queue (regular case is to have -1/0 languages only)
        if ($recordLanguage > 0) {
            return parent::getSolrConnectionsByItem($item);
        }

        // resolve all localizations pointing to the current item
        // (which is in the default language with UID 0)
        $queryBuilder = $this->getConnectionPool()
            ->getQueryBuilderForTable($item->getType());
        $statement = $queryBuilder
            ->select($languageFieldName)
            ->from($item->getType())
            ->where(
                $queryBuilder->expr()->andX(
                    $queryBuilder->expr()->eq(
                        $parentFieldName,
                        $queryBuilder->createNamedParameter(
                            (int)$item->getRecordUid(),
                            Connection::PARAM_INT
                        )
                    ),
                    $queryBuilder->expr()->gt(
                        $languageFieldName,
                        $queryBuilder->createNamedParameter(
                            0,
                            Connection::PARAM_INT
                        )
                    )
                )
            )
            ->execute();

        $languages = [0];
        foreach ($statement as $localization) {
            $languages[] = (int)$localization[$languageFieldName];
        }

        return $this->getSolrConnectionsFor(
            $item,
            array_intersect($solrLanguages, $languages)
        );
    }

    /**
     * Gets the full item record.
     *
     * This general record indexer simply gets the record from the item. Other
     * more specialized indexers may provide more data for their specific item
     * types.
     *
     * @param Item $item The item to be indexed
     * @param int $language Language Id (sys_language.uid)
     * @return array|NULL The full record with fields of data to be used for indexing or NULL to prevent an item from being indexed
     */
    protected function getFullItemRecord(Item $item, $language = 0)
    {
        if (empty($this->options['additionalTypoScript.'])) {
            return parent::getFullItemRecord($item, $language);
        }

        // @todo Refactor ext:solr to retrieve custom frontend controller
        Utility::initializeTsfe(
            $item->getRootPageUid(),
            $language,
            true,
            $this->options['additionalTypoScript.']
        );

        /**
         * copied form ext:Solr after this point
         */

        $systemLanguageContentOverlay = $GLOBALS['TSFE']->sys_language_contentOL;
        $itemRecord = $this->getItemRecordOverlayed($item, $language, $systemLanguageContentOverlay);

        /*
         * Skip disabled records. This happens if the default language record
         * is hidden but a certain translation isn't. Then the default language
         * document appears here but must not be indexed.
         */
        if (!empty($GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['disabled'])
            && $itemRecord[$GLOBALS['TCA'][$item->getType()]['ctrl']['enablecolumns']['disabled']]
        ) {
            $itemRecord = null;
        }

        /*
         * Skip translation mismatching records. Sometimes the requested language
         * doesn't fit the returned language. This might happen with content fallback
         * and is perfectly fine in general.
         * But if the requested language doesn't match the returned language and
         * the given record has no translation parent, the indexqueue_item most
         * probably pointed to a non-translated language record that is dedicated
         * to a very specific language. Now we have to avoid indexing this record
         * into all language cores.
         */
        $translationOriginalPointerField = 'l10n_parent';
        if (!empty($GLOBALS['TCA'][$item->getType()]['ctrl']['transOrigPointerField'])) {
            $translationOriginalPointerField = $GLOBALS['TCA'][$item->getType()]['ctrl']['transOrigPointerField'];
        }

        $languageField = $GLOBALS['TCA'][$item->getType()]['ctrl']['languageField'];
        if ($itemRecord[$translationOriginalPointerField] == 0
            && $systemLanguageContentOverlay != 1
            && !empty($languageField)
            && $itemRecord[$languageField] != $language
            && $itemRecord[$languageField] != '-1'
        ) {
            $itemRecord = null;
        }

        if (!is_null($itemRecord)) {
            $itemRecord['__solr_index_language'] = $language;
        }

        return $itemRecord;
    }

    /**
     * @param Item $item
     * @param int[] $languages
     * @return SolrService[]
     */
    private function getSolrConnectionsFor(Item $item, array $languages)
    {
        $pageId = $item->getRootPageUid();
        return array_map(
            function ($language) use ($pageId) {
                return $this->connectionManager->getConnectionByRootPageId(
                    $pageId,
                    $language
                );
            },
            $languages
        );
    }

    /**
     * @param string $tableName
     * @return null|string
     */
    private function getLocalizationLanguageFieldName($tableName)
    {
        if (empty($GLOBALS['TCA'][$tableName]['ctrl']['languageField'])) {
            return null;
        }
        return $GLOBALS['TCA'][$tableName]['ctrl']['languageField'];
    }

    /**
     * @param string $tableName
     * @return null|string
     */
    private function getLocalizationParentFieldName($tableName)
    {
        if (empty($GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'])) {
            return null;
        }
        return $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'];
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
