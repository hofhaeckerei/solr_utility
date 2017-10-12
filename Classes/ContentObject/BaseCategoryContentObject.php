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

use TYPO3\CMS\Core\Database\DatabaseConnection;
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
        return $this->fetchCategories([
            'parent=' . (int)$this->configuration['baseId'],
        ]);
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
                // excludeIds takes precedence over filterIds
                // -> excludeIds [1,2,3] and filterIds [3,4,5] will
                //    only return [4,5] since [1,2,3] are excluded
                return !in_array($categoryId, $excludeIds, true)
                    && (
                        in_array($categoryId, $filterIds, true)
                        || empty($filterIds)
                    );
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

        return $this->fetchCategories([
            'uid IN (' . implode(',', $categoryIds) . ')',
        ]);
    }

    /**
     * @param array $categories
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

        $parentCategories = $this->fetchCategories([
            'uid IN (' . implode(',', $parentCategoryIds) . ')',
        ]);

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
     * @param array $values
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
     * @param array $predicates
     * @return array
     */
    private function fetchCategories(array $predicates)
    {
        $defaultPredicates = GeneralUtility::trimExplode(
            ' AND ',
            $this->getPageRepository()->enableFields('sys_category'),
            true
        );
        $predicates = array_merge($defaultPredicates, $predicates);

        $result = $this->getDatabaseConnection()->exec_SELECTgetRows(
            'uid,pid,parent,title,sys_language_uid,l10n_parent',
            'sys_category',
            implode(' AND ', $predicates),
            '',
            '',
            '',
            'uid'
        );

        if (empty($result)) {
            return [];
        }
        return $result;
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
     * @return DatabaseConnection
     */
    private function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }
}
