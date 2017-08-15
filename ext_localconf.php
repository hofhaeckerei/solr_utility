<?php
defined('TYPO3_MODE') || die();

call_user_func(function() {
    $baseCategory = \H4ck3r31\SolrUtility\ContentObject\BaseCategoryContentObject::CONTENT_OBJECT_NAME;

    // Registers SOLR_BASECATEGORY as content object to be used for indexing
    $GLOBALS['TYPO3_CONF_VARS']['FE']['ContentObjects'][$baseCategory]
        = \H4ck3r31\SolrUtility\ContentObject\BaseCategoryContentObject::class;
    // Registers serialization hook to be used by SOLR_BASECATEGORY content object
    $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['solr']['detectSerializedValue'][]
        = \H4ck3r31\SolrUtility\IndexQueue\SerializedValueHook::class;
});
