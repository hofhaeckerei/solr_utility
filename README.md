# TYPO3 Extension solr_utility

## Content Objects

### SOLR_BASECATEGORY

Fetches the next level cateogry titles of a given base category.

**Properties**

* `baseId` *(int, required)*: Defines the base ID of `sys_category`
  to retrieve the next level children from
* `removeEmptyValues` *(bool, default: true)*: Whether to remove items that
  don't have a category title
* `multiValue` *(bool, default: true)*: Whether to return a multivalued array
* `singleValueGlue` *(string, default: ",")*: Which character to use to
  concatenate multiple category titles in case `multiValue` is disabled
* `filterIds` *(int-list)*: Defines which category IDs to keep in the final
  result of resolved base categories - takes precedence over `excludeIds`
* `excludeIds` *(int-list)*: Defines which category IDs to exclude from the
  final result of resolved base categories

**Example**

Given the following category tree, the `SOLR_BASECATEGORY` content object
invoked for `baseId=1` would fetch the category titles of the next level
below the submitted base category - in this example the result would be
`["News", "Products", "Events", "Press"]` only.

* 1: Base Categories
  * 11: News
  * 12: Products
    * 121: New Products
    * 122: Products on Sale
  * 13: Events
  * 14: Press
* 2: Product Categories
  * 21: Software
  * 23: Books

```
plugin.tx_solr.index.queue {
    pages {
        fields {
            baseCategory_stringM = SOLR_BASECATEGORY
            baseCategory_stringM {
                baseId = 1
                localField = categories
                multiValue = 1
                # both filterIds and excludeIds
                # result in "Press" being ignored
                filterIds = 11,12,13
                excludeIds = 14
            }
```
*Example of fetching next level category titles of base category with `uid=1`*

## Indexing

### Ignore page ID constraints

Ignore page ID constraints on indexing entities that are not accessible per
default, like e.g. indexing `sys_file` items on root-page (`pid=0`).

```
plugin.tx_solr.index.queue {
    document {
        table = sys_file
        additionalWhereClause = storage=1 AND identifier LIKE '/public-downloads/%'
        initialization = GMK\BezirkOberfranken\Alternatives\Solr\DocumentInitializer
```
*Example of indexing files of storage `1` below folder `/public-downloads/`*

### Prevent non-translated pages being indexes

Prevents pages being indexed multiple times if no page translation is available.
This prevents rendering the content fall-back mode for `sys_language_uid=0` with
having a real language in the request.

```
plugin.tx_solr.index.queue {
    pages {
        indexer = H4ck3r31\SolrUtility\IndexQueue\PreventNonTranslatedPageIndexer
```
*Example of preventing non-translated pages get indexed in content fall-back mode*
