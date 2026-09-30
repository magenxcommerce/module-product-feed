<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export\Loader;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * The option values that make a simple product a variant of its configurable.
 *
 * A variant row in a marketplace feed needs three things its own attributes do
 * not give it: the id of the group it belongs to, the NAMES of the options that
 * distinguish the group (the parent's super attributes - "Color", "Size"), and
 * the value this child selects for each. Google calls the last two its variant
 * attributes; the agentic-commerce feed wants them as one `variant_dict` object.
 *
 * Exposed on the product record as:
 *   variant_group_id         the configurable parent's sku ('' for a non-variant)
 *   variant_dict             {"Color": "Black", "Size": "10"}
 *   configurable_attributes  [{code, label, value}, ...] in the parent's order
 *
 * Only CONFIGURABLE parents count. catalog_product_relation (which `parent`
 * uses) also links bundle and grouped children, and a bundle is not a variant
 * group - so this reads catalog_product_super_link instead. A child under two
 * configurables resolves to the lowest parent id, the same rule RelationLoader
 * applies, so group ids do not flip between runs.
 *
 * Four queries per batch whatever its size, and none at all unless the template
 * references one of the fields above. Link-field aware: on Adobe Commerce the
 * EAV and super-link tables key on row_id, not entity_id.
 */
class ConfigurableAttributeLoader
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * @return array<int, array<string, mixed>> childProductId => variant fields
     */
    public function load(LoadScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        $parents = $this->loadConfigurableParents($scope->productIds, $linkField);
        if ($parents === []) {
            return [];
        }

        $superAttributes = $this->loadSuperAttributes(
            array_values(array_unique(array_column($parents, 'parent_id'))),
            $linkField,
            $scope->storeId
        );

        $attributeIds = [];
        foreach ($superAttributes as $attributes) {
            foreach ($attributes as $attribute) {
                $attributeIds[$attribute['attribute_id']] = true;
            }
        }

        if ($attributeIds === []) {
            return [];
        }

        $values = $this->loadChildValues(
            array_keys($parents),
            array_keys($attributeIds),
            $linkField,
            $scope->storeId
        );
        $labels = $this->loadOptionLabels($values, $scope->storeId);

        $out = [];
        foreach ($parents as $childId => $parent) {
            $options = [];
            $dict = [];

            foreach ($superAttributes[$parent['parent_id']] ?? [] as $attribute) {
                $optionId = $values[$childId][$attribute['attribute_id']] ?? null;
                $value = $optionId === null ? '' : ($labels[$optionId] ?? '');
                if ($value === '') {
                    continue;
                }

                $options[] = [
                    'code' => $attribute['code'],
                    'label' => $attribute['label'],
                    'value' => $value,
                ];
                $dict[$attribute['label']] = $value;
            }

            $out[$childId] = [
                'variant_group_id' => $parent['parent_sku'],
                'variant_dict' => $dict,
                'configurable_attributes' => $options,
            ];
        }

        return $out;
    }

    /**
     * @param int[] $childIds
     * @return array<int, array{parent_id: int, parent_sku: string}> childId => parent
     */
    private function loadConfigurableParents(array $childIds, string $linkField): array
    {
        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from(['sl' => $this->resource->getTableName('catalog_product_super_link')], ['product_id'])
            ->join(
                ['pe' => $this->resource->getTableName('catalog_product_entity')],
                'pe.' . $linkField . ' = sl.parent_id',
                ['parent_id' => 'entity_id', 'parent_sku' => 'sku']
            )
            ->where('sl.product_id IN (?)', $childIds)
            ->order('pe.entity_id ASC');

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $childId = (int) $row['product_id'];
            if (!isset($out[$childId])) {
                $out[$childId] = [
                    'parent_id' => (int) $row['parent_id'],
                    'parent_sku' => (string) $row['parent_sku'],
                ];
            }
        }

        return $out;
    }

    /**
     * The parents' super attributes, in the parent's configured order, with the
     * store-view label a shopper sees on the product page.
     *
     * @param int[] $parentIds
     * @return array<int, array<int, array{attribute_id: int, code: string, label: string}>>
     */
    private function loadSuperAttributes(array $parentIds, string $linkField, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $labelTable = $this->resource->getTableName('catalog_product_super_attribute_label');

        $select = $connection->select()
            ->from(
                ['sa' => $this->resource->getTableName('catalog_product_super_attribute')],
                ['product_super_attribute_id', 'attribute_id', 'position']
            )
            ->join(
                ['pe' => $this->resource->getTableName('catalog_product_entity')],
                'pe.' . $linkField . ' = sa.product_id',
                ['parent_id' => 'entity_id']
            )
            ->join(
                ['ea' => $this->resource->getTableName('eav_attribute')],
                'ea.attribute_id = sa.attribute_id',
                ['attribute_code', 'frontend_label']
            )
            ->joinLeft(
                ['sls' => $labelTable],
                $connection->quoteInto(
                    'sls.product_super_attribute_id = sa.product_super_attribute_id AND sls.use_default = 0'
                    . ' AND sls.store_id = ?',
                    $storeId
                ),
                ['store_super_label' => 'value']
            )
            ->joinLeft(
                ['sld' => $labelTable],
                'sld.product_super_attribute_id = sa.product_super_attribute_id AND sld.store_id = 0',
                ['default_super_label' => 'value']
            )
            ->joinLeft(
                ['eal' => $this->resource->getTableName('eav_attribute_label')],
                $connection->quoteInto('eal.attribute_id = sa.attribute_id AND eal.store_id = ?', $storeId),
                ['store_label' => 'value']
            )
            ->where('pe.entity_id IN (?)', $parentIds)
            ->order(['pe.entity_id ASC', 'sa.position ASC', 'sa.product_super_attribute_id ASC']);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $label = '';
            foreach (['store_super_label', 'store_label', 'default_super_label', 'frontend_label'] as $key) {
                $candidate = trim((string) ($row[$key] ?? ''));
                if ($candidate !== '') {
                    $label = $candidate;
                    break;
                }
            }

            $out[(int) $row['parent_id']][] = [
                'attribute_id' => (int) $row['attribute_id'],
                'code' => (string) $row['attribute_code'],
                'label' => $label !== '' ? $label : (string) $row['attribute_code'],
            ];
        }

        return $out;
    }

    /**
     * Each child's selected option id per super attribute, store value first.
     *
     * Super attributes are global-scope select attributes, so they always live in
     * the int table; the store row is still honoured in case one was written.
     *
     * @param int[] $childIds
     * @param int[] $attributeIds
     * @return array<int, array<int, int>> childId => attributeId => optionId
     */
    private function loadChildValues(array $childIds, array $attributeIds, string $linkField, int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $intTable = $this->resource->getTableName('catalog_product_entity_int');

        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
            ->join(
                ['vd' => $intTable],
                'vd.' . $linkField . ' = e.' . $linkField . ' AND vd.store_id = 0',
                ['attribute_id']
            )
            ->joinLeft(
                ['vs' => $intTable],
                $connection->quoteInto(
                    'vs.' . $linkField . ' = e.' . $linkField . ' AND vs.attribute_id = vd.attribute_id'
                    . ' AND vs.store_id = ?',
                    $storeId
                ),
                []
            )
            ->columns(['value' => new \Zend_Db_Expr('IFNULL(vs.value, vd.value)')])
            ->where('e.entity_id IN (?)', $childIds)
            ->where('vd.attribute_id IN (?)', $attributeIds);

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            if ($row['value'] !== null) {
                $out[(int) $row['entity_id']][(int) $row['attribute_id']] = (int) $row['value'];
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<int, int>> $values
     * @return array<int, string> optionId => store-view label
     */
    private function loadOptionLabels(array $values, int $storeId): array
    {
        $optionIds = [];
        foreach ($values as $byAttribute) {
            foreach ($byAttribute as $optionId) {
                $optionIds[$optionId] = true;
            }
        }

        if ($optionIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('eav_attribute_option_value');

        $select = $connection->select()
            ->from(['d' => $table], ['option_id'])
            ->joinLeft(
                ['s' => $table],
                $connection->quoteInto('s.option_id = d.option_id AND s.store_id = ?', $storeId),
                []
            )
            ->columns(['label' => new \Zend_Db_Expr('IFNULL(s.value, d.value)')])
            ->where('d.store_id = 0')
            ->where('d.option_id IN (?)', array_keys($optionIds));

        $out = [];
        foreach ($connection->fetchAll($select) as $row) {
            $out[(int) $row['option_id']] = trim((string) $row['label']);
        }

        return $out;
    }
}
