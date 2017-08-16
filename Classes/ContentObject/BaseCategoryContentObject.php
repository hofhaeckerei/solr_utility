<?php
namespace H4ck3r31\SolrUtility\ContentObject;

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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\AbstractContentObject;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageRepository;

class BaseCategoryContentObject extends AbstractContentObject
{
    const CONTENT_OBJECT_NAME = 'SOLR_BASECATEGORY';

    /**
     * @var array
     */
    private $configuration = [
        'removeEmptyValues' => true,
        'singleValueGlue' => ', ',
        'multiValue' => true,
    ];

    /**
     * Base category records having `baseId`
     * matching in `parent` database field
     *
     * @var array
     */
    private $baseCategories = [];

    /**
     * Resolved category records assigned to the current
     * subject record (e.g. "categories of the current page")
     *
     * @var array
     */
    private $resolvedCategories = [];

    /**
     * @param array $configuration
     * @return string
     */
    public function render($configuration = [])
    {
        $this->configuration = array_merge($this->configuration, $configuration);
        $this->baseCategories = $this->getBaseCategories();
        $this->resolvedCategories = $this->getAssignedCategories();
        $this->resolveParentCategories($this->resolvedCategories);

        $values = $this->resolveIntersectingCategoryTitles();
        return $this->prepareResult($values);
    }

    /**
     * @return array
     */
    private function getBaseCategories()
    {
        $queryBuilder = $this->createCategoryQueryBuilder();
        $queryBuilder->where(
            $queryBuilder->expr()->eq(
                'parent',
                $queryBuilder->createNamedParameter(
                    (int)$this->configuration['baseId'],
                    Connection::PARAM_INT
                )
            )
        );

        return $this->fetchCategories($queryBuilder);
    }

    /**
     * Filters resolved base categories by either `filterIds` or
     * excluding `excludeIds` configuration (if defined).
     *
     * @return array Filtered base categories
     */
    private function filterBaseCategories()
    {
        $filterIds = [];
        $excludeIds = [];

        if (!empty($this->configuration['filterIds'])) {
            $filterIds = GeneralUtility::intExplode(
                ',',
                $this->configuration['filterIds'],
                true
            );
        }
        if (!empty($this->configuration['excludeIds'])) {
            $filterIds = GeneralUtility::intExplode(
                ',',
                $this->configuration['excludeIds'],
                true
            );
        }

        if (empty($filterIds) && empty($excludeIds)) {
            return $this->baseCategories;
        }

        return array_filter(
            $this->baseCategories,
            function (array $category) use ($filterIds, $excludeIds) {
                $categoryId = (int)$category['uid'];
                // filterIds takes precedence over excludeIds
                return in_array($categoryId, $filterIds, true)
                    || !in_array($categoryId, $excludeIds, true);
            }
        );
    }

    /**
     * @return array
     */
    private function getAssignedCategories()
    {
        list ($tableName, $id) = explode(':', $this->cObj->currentRecord);
        $configuration = $GLOBALS['TCA'][$tableName]['columns']['categories']['config'];

        $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
        $relationHandler->start(
            '',
            $configuration['foreign_table'],
            $configuration['MM'],
            $id,
            $tableName,
            $configuration
        );

        $categoryIds = [];
        foreach ($relationHandler->itemArray as $item) {
            $categoryIds[] = (int)$item['id'];
        }

        if (empty($categoryIds)) {
            return [];
        }

        $queryBuilder = $this->createCategoryQueryBuilder();
        $queryBuilder->where(
            $queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter(
                    $categoryIds,
                    Connection::PARAM_INT_ARRAY
                )
            )
        );

        return $this->fetchCategories($queryBuilder);
    }

    /**
     * @param array $categories Category records
     */
    private function resolveParentCategories(array $categories)
    {
        $parentCategoryIds = [];
        foreach ($categories as $category) {
            $parentCategoryId = (int)$category['parent'];
            if (!in_array($parentCategoryId, $parentCategoryIds)) {
                $parentCategoryIds[] = $parentCategoryId;
            }
        }

        if (empty($parentCategoryIds)) {
            return;
        }

        $queryBuilder = $this->createCategoryQueryBuilder();
        $queryBuilder->where(
            $queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter(
                    $parentCategoryIds,
                    Connection::PARAM_INT_ARRAY
                )
            )
        );

        $parentCategories = $this->fetchCategories($queryBuilder);

        if (empty($parentCategories)) {
            return;
        }

        $this->resolvedCategories += $parentCategories;
        $this->resolveParentCategories($parentCategories);
    }

    /**
     * @return array
     */
    private function resolveIntersectingCategoryTitles()
    {
        $validBaseCategories = $this->filterBaseCategories();

        $intersectingCategories = array_intersect_key(
            $validBaseCategories,
            $this->resolvedCategories
        );

        $categoryTitles = array_map(
            function($intersectingCategory) {
                $intersectingCategory = $this->getPageRepository()
                    ->getRecordOverlay(
                        'sys_category',
                        $intersectingCategory,
                        $this->getFrontendController()->sys_language_uid
                    );
                return trim($intersectingCategory['title']);
            },
            $intersectingCategories
        );

        if (!empty($this->configuration['removeEmptyValues'])) {
            $categoryTitles = array_filter(
                $categoryTitles,
                function($categoryTitle) {
                    return !empty($categoryTitle);
                }
            );
        }

        return $categoryTitles;
    }

    /**
     * @param string[] $values
     * @return string
     */
    private function prepareResult(array $values)
    {
        if (empty($this->configuration['multiValue'])) {
            if (empty($this->configuration['singleValueGlue'])) {
                $this->configuration['singleValueGlue'] = ', ';
            }
            $result = implode($this->configuration['singleValueGlue'], $values);
        } else {
            $result = serialize($values);
        }

        return $result;
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @return array
     */
    private function fetchCategories(QueryBuilder $queryBuilder)
    {
        $records = $queryBuilder->execute()->fetchAll();

        return array_combine(
            array_column($records, 'uid'),
            array_values($records)
        );
    }

    /**
     * @return PageRepository
     */
    private function getPageRepository()
    {
        return $this->getFrontendController()->sys_page;
    }

    /**
     * @return TypoScriptFrontendController
     */
    private function getFrontendController()
    {
        return $GLOBALS['TSFE'];
    }

    /**
     * @return QueryBuilder
     */
    private function createCategoryQueryBuilder()
    {
        $queryBuilder = $this->getConnectionPool()
            ->getQueryBuilderForTable('sys_category')
            ->select('uid', 'pid', 'parent', 'title', 'sys_language_uid', 'l10n_parent')
            ->from('sys_category');
        return $queryBuilder;
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
