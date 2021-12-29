<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Elasticsearch\Model\ResourceModel\Fulltext\Collection;

use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection\SearchResultApplierInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Collection;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Resolve specific attributes for search criteria.
 */
class SearchResultApplier implements SearchResultApplierInterface
{
    /**
     * @var Collection|\Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection
     */
    private $collection;

    /**
     * @var SearchResultInterface
     */
    private $searchResult;

    /**
     * @var int
     */
    private $size;

    /**
     * @var int
     */
    private $currentPage;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param Collection $collection
     * @param SearchResultInterface $searchResult
     * @param int $size
     * @param int $currentPage
     * @param ScopeConfigInterface|null $scopeConfig
     */
    public function __construct(
        Collection $collection,
        SearchResultInterface $searchResult,
        int $size,
        int $currentPage,
        ?ScopeConfigInterface $scopeConfig = null
    ) {
        $this->collection = $collection;
        $this->searchResult = $searchResult;
        $this->size = $size;
        $this->currentPage = $currentPage;
        $this->scopeConfig = $scopeConfig ?? ObjectManager::getInstance()->get(ScopeConfigInterface::class);
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        if (empty($this->searchResult->getItems())) {
            $this->collection->getSelect()->where('NULL');

            return;
        }

        $ids = $this->getProductIdsBySaleability();

        if (count($ids) == 0) {
            $items = $this->sliceItems($this->searchResult->getItems(), $this->size, $this->currentPage);
            foreach ($items as $item) {
                $ids[] = (int)$item->getId();
            }
        }
        $orderList = implode(',', $ids);
        $this->collection->getSelect()
            ->where('e.entity_id IN (?)', $ids)
            ->reset(\Magento\Framework\DB\Select::ORDER)
            ->order(new \Zend_Db_Expr("FIELD(e.entity_id,$orderList)"));
    }

    /**
     * Slice current items
     *
     * @param array $items
     * @param int $size
     * @param int $currentPage
     * @return array
     */
    private function sliceItems(array $items, int $size, int $currentPage): array
    {
        if ($size !== 0) {
            // Check that current page is in a range of allowed page numbers, based on items count and items per page,
            // than calculate offset for slicing items array.
            $itemsCount = count($items);
            $maxAllowedPageNumber = ceil($itemsCount/$size);
            if ($currentPage < 1) {
                $currentPage = 1;
            }
            if ($currentPage > $maxAllowedPageNumber) {
                $currentPage = $maxAllowedPageNumber;
            }

            $offset = $this->getOffset($currentPage, $size);
            $items = array_slice($items, $offset, $size);
        }

        return $items;
    }

    /**
     * Get offset for given page.
     *
     * @param int $pageNumber
     * @param int $pageSize
     * @return int
     */
    private function getOffset(int $pageNumber, int $pageSize): int
    {
        return ($pageNumber - 1) * $pageSize;
    }

    /**
     * Fetch filtered product ids sorted by the salability and other applied sort orders
     *
     * @return array
     */
    private function getProductIdsBySaleability(): array
    {
        $ids = [];

        if (!$this->hasShowOutOfStockStatus()) {
            return $ids;
        }

        if ($this->collection->getFlag('has_stock_status_filter')) {
            $categoryId = null;
            $searchCriteria = $this->searchResult->getSearchCriteria();
            foreach ($searchCriteria->getFilterGroups() as $filterGroup) {
                foreach ($filterGroup->getFilters() as $filter) {
                    if ($filter->getField() === 'category_ids') {
                        $categoryId = $filter->getValue();
                        break 2;
                    }
                }
            }

            if ($categoryId) {
                $storeId = $this->collection->getStoreId();
                $searchOrders = $searchCriteria->getSortOrders();
                $searchOrders = array_merge(['is_salable' => \Magento\Framework\DB\Select::SQL_DESC], $searchOrders);
                $defaultColumnsFilter = ['is_salable', 'position', 'name', 'price', 'entity_id'];

                $connection = $this->collection->getConnection();
                $query = clone $connection->select()
                    ->reset(\Magento\Framework\DB\Select::ORDER)
                    ->reset(\Magento\Framework\DB\Select::LIMIT_COUNT)
                    ->reset(\Magento\Framework\DB\Select::LIMIT_OFFSET)
                    ->reset(\Magento\Framework\DB\Select::COLUMNS);

                $selectColumns = [
                    'e.entity_id',
                    'cat_index.position AS cat_index_position',
                    'stock_status_index.stock_status AS is_salable'
                ];
                $query->join(
                    ['stock_status_index' => $this->collection->getTable('cataloginventory_stock_status')],
                    'stock_status_index.product_id = e.entity_id',
                    []
                )->join(
                    ['cat_index' => $this->collection->getTable('catalog_category_product_index_store' . $storeId)],
                    'cat_index.product_id = e.entity_id'
                    . ' AND cat_index.category_id = ' . $categoryId
                    . ' AND cat_index.store_id = ' . $storeId,
                    []
                );
                foreach ($searchOrders as $field => $dir) {
                    if ($field === 'name') {
                        $selectColumns[] = 'product.value AS name';
                        $query->join(
                            ['product' => $this->collection->getTable('catalog_product_entity_varchar')],
                            'product.row_id = e.entity_id ' .
                            'AND product.attribute_id = (' .
                            'SELECT attribute_id FROM eav_attribute WHERE entity_type_id=4 AND attribute_code="name")',
                            []
                        );
                    } else if ($field === 'price') {
                        $selectColumns[] = 'price_index.max_price AS price';
                        $query->join(
                            ['price_index' => $this->collection->getTable('catalog_product_index_price')],
                            'price_index.entity_id = e.entity_id'
                            . ' AND price_index.customer_group_id = 0'
                            . ' AND price_index.website_id = (Select website_id FROM store WHERE store_id = '
                            . $storeId . ')',
                            []
                        );
                    }
                    if (in_array($field, $defaultColumnsFilter, true)) {
                        $query->order(new \Zend_Db_Expr("{$field} {$dir}"));
                    }
                }

                $query->from(
                    ['e' => $this->collection->getTable('catalog_product_entity')],
                    $selectColumns
                );

                $query->limit(
                    $searchCriteria->getPageSize(),
                    $searchCriteria->getCurrentPage() * $searchCriteria->getPageSize()
                );
                $resultSet = $this->collection->getConnection()->fetchAssoc($query);

                foreach ($resultSet as $item) {
                    $ids[] = (int)$item['entity_id'];
                }
            }
        }

        return $ids;
    }

    /**
     * Returns if display out of stock status set or not in catalog inventory
     *
     * @return bool
     */
    private function hasShowOutOfStockStatus(): bool
    {
        return (bool) $this->scopeConfig->getValue('cataloginventory/options/show_out_of_stock');
    }
}
