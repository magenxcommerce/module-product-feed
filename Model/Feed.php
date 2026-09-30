<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model;

use Magenx\ProductFeed\Model\Export\Writer\ValueTyper;
use Magenx\ProductFeed\Model\ResourceModel\Feed as FeedResource;
use Magento\CatalogWidget\Model\Rule\Condition\CombineFactory;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Rule\Model\AbstractModel;

/**
 * A feed definition.
 *
 * Extends Magento\Rule\Model\AbstractModel purely to inherit the product filter:
 * the condition tree, its serialization into `conditions_serialized`, and the
 * admin widget that edits it. That is a large amount of machinery for two method
 * bodies, and it is the same trade Magenx_AutoProductLinks makes.
 *
 * Consequences of that parent, both real traps:
 *
 *  1. `conditions_serialized` is a FIXED column name. AbstractModel writes
 *     getConditions() there and nowhere else.
 *  2. AbstractModel::loadPost() converts `from_date` and `to_date` - and only
 *     those two keys - into \DateTime objects before setting them on the model.
 *     Any code that then does (string) $feed->getData('from_date') fatals with
 *     "Object of class DateTime could not be converted to string". This class
 *     declares neither field, and it should stay that way; if a validity window
 *     is ever added, compare timestamps rather than strings (the admin date
 *     input posts a locale format, which does not sort chronologically either).
 *
 * getActions() is required by the parent but unused here - a feed has one tree,
 * not two - so it returns an empty combine.
 */
class Feed extends AbstractModel
{
    public const STATUS_NOT_GENERATED = 'not_generated';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';
    public const STATUS_DISABLED = 'disabled';

    public const FORMAT_XML = 'xml';
    public const FORMAT_CSV = 'csv';
    public const FORMAT_TSV = 'tsv';
    public const FORMAT_JSONL = 'jsonl';

    public const COMPRESSION_NONE = 'none';
    public const COMPRESSION_GZIP = 'gzip';

    /**
     * Product types a cart accepts by sku alone, with no option selections.
     * Used by the purchasable-only filter.
     */
    public const PURCHASABLE_TYPES = ['simple', 'virtual', 'downloadable'];

    /**
     * Formats whose output is one record per line, and which can therefore feed a
     * push catalog API directly. A free-form XML template has arbitrary nesting
     * and no record equivalent, so it can only ever be delivered as a file.
     */
    private const RECORD_FORMATS = [self::FORMAT_CSV, self::FORMAT_TSV, self::FORMAT_JSONL];

    protected $_eventPrefix = 'magenx_product_feed';

    protected $_eventObject = 'feed';

    /** @var array<string, string>|null */
    private ?array $columnTypes = null;

    private string $columnTypesSource = '';

    public function __construct(
        private readonly CombineFactory $combineFactory,
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        TimezoneInterface $localeDate,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?ExtensionAttributesFactory $extensionFactory = null,
        ?AttributeValueFactory $customAttributeFactory = null,
        ?Json $serializer = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $formFactory,
            $localeDate,
            $resource,
            $resourceCollection,
            $data,
            $extensionFactory,
            $customAttributeFactory,
            $serializer
        );
    }

    protected function _construct(): void
    {
        parent::_construct();
        $this->_init(FeedResource::class);
        $this->setIdFieldName('feed_id');
    }

    /**
     * The product filter's root combine.
     *
     * Magento\CatalogWidget\Model\Rule\Condition\Combine, NOT the CatalogRule one.
     * The admin widget, attribute picker and serialized format are identical, but
     * only the CatalogWidget flavour implements getMappedSqlField(), which is what
     * lets Magento\Rule\Model\Condition\Sql\Builder push the whole tree down into
     * one SQL WHERE clause. The CatalogRule flavour resolves by instantiating and
     * validating every product in PHP - minutes per feed on a real catalog.
     *
     * Known cost of the pushdown, inherited from the builder: an attribute it
     * cannot map is SKIPPED. Inside an all/AND group that only over-selects, which
     * is harmless here; inside an any/OR group it silently UNDER-selects.
     */
    public function getConditionsInstance(): \Magento\Rule\Model\Condition\Combine
    {
        return $this->combineFactory->create();
    }

    /**
     * Unused. A feed has a single condition tree; the parent simply requires this
     * method to exist.
     */
    public function getActionsInstance(): \Magento\Rule\Model\Condition\Combine
    {
        return $this->combineFactory->create();
    }

    public function getFeedId(): ?int
    {
        $id = $this->getData('feed_id');

        return $id === null ? null : (int) $id;
    }

    public function getCode(): string
    {
        return (string) $this->getData('code');
    }

    public function getStoreId(): int
    {
        return (int) $this->getData('store_id');
    }

    public function getFormat(): string
    {
        return (string) ($this->getData('format') ?: self::FORMAT_XML);
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    /**
     * Whether this feed's output is a stream of records, and so can be pushed to a
     * catalog API rather than only published as a file.
     */
    public function producesRecords(): bool
    {
        return in_array($this->getFormat(), self::RECORD_FORMATS, true);
    }

    /**
     * @return array<int, array{column: string, value: string}>
     */
    public function getFieldMap(): array
    {
        $raw = (string) $this->getData('field_map');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Declared JSON type per column, from the optional `type` on each field map
     * row. Columns without one are strings.
     *
     * @return array<string, string> column => ValueTyper::TYPE_*
     */
    public function getColumnTypes(): array
    {
        // Read once per record by the writer, so memoised against the raw map:
        // decoding the field map JSON per product would be pure waste.
        $raw = (string) $this->getData('field_map');
        if ($this->columnTypes !== null && $this->columnTypesSource === $raw) {
            return $this->columnTypes;
        }

        $types = [];
        foreach ($this->getFieldMap() as $row) {
            $column = trim((string) ($row['column'] ?? ''));
            if ($column !== '') {
                $types[$column] = ValueTyper::normalizeType($row['type'] ?? null);
            }
        }

        $this->columnTypesSource = $raw;

        return $this->columnTypes = $types;
    }

    /**
     * Leave an empty column out of a JSON record instead of writing "".
     *
     * Delimited formats cannot do this - every row must carry every column - so
     * it only affects JSONL.
     */
    public function shouldOmitEmpty(): bool
    {
        return (bool) $this->getData('omit_empty');
    }

    public function isPurchasableOnly(): bool
    {
        return (bool) $this->getData('purchasable_only');
    }

    public function getCompression(): string
    {
        return $this->getData('compression') === self::COMPRESSION_GZIP
            ? self::COMPRESSION_GZIP
            : self::COMPRESSION_NONE;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getValidationRules(): array
    {
        $raw = (string) $this->getData('validation_rules');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * ISO day numbers (1 = Monday .. 7 = Sunday) this feed generates on.
     *
     * @return int[]
     */
    public function getScheduleDays(): array
    {
        return $this->splitList((string) $this->getData('schedule_days'), true);
    }

    /**
     * HH:MM times of day, evaluated in the feed store's timezone.
     *
     * @return string[]
     */
    public function getScheduleTimes(): array
    {
        return $this->splitList((string) $this->getData('schedule_times'), false);
    }

    /**
     * @return ($asInt is true ? int[] : string[])
     */
    private function splitList(string $raw, bool $asInt): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '');

        return array_values($asInt ? array_map('intval', $parts) : $parts);
    }
}
