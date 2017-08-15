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

use ApacheSolrForTypo3\Solr\IndexQueue\Initializer\Record;

class IgnorePageIdInitializer extends Record
{
    /**
     * Disable pid/uid constraints (e.g. for indexing sys_file table).
     *
     * @return string
     */
    protected function buildPagesClause()
    {
        return '1=1';
    }
}
