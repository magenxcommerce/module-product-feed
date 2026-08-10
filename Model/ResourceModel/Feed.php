<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\ResourceModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Math\Random;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Rule\Model\ResourceModel\AbstractResource;

/**
 * Feed resource model.
 *
 * NOTE on _beforeSave's visibility: Magento\Rule\Model\ResourceModel\AbstractResource
 * WIDENS _beforeSave() to public, so an override cannot narrow it back to
 * protected - PHP rejects that at class-load time with a fatal that `php -l`
 * cannot see. The leading underscore does not imply protected in Magento.
 * _beforeDelete() stays protected.
 */
class Feed extends AbstractResource
{
    /** Length of the random public-URL path segment. */
    private const SECRET_LENGTH = 24;

    public function __construct(
        Context $context,
        private readonly Random $random,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    protected function _construct(): void
    {
        $this->_init('magenx_feed', 'feed_id');
    }

    /**
     * @throws LocalizedException
     */
    public function _beforeSave(AbstractModel $object): self
    {
        parent::_beforeSave($object);

        if (!$object->getData('code')) {
            $object->setData('code', $this->generateCode((string) $object->getData('name')));
        }

        // A feed is a complete machine-readable catalog dump and may carry cost or
        // supplier data, so its public URL must not be guessable. Assigned once and
        // never rotated on save - rotating it would silently break every consumer
        // already fetching the old URL.
        if (!$object->getData('url_secret')) {
            $object->setData('url_secret', $this->random->getRandomString(self::SECRET_LENGTH));
        }

        return $this;
    }

    /**
     * Resolve a feed id from its stable code, for the CLI.
     */
    public function getIdByCode(string $code): ?int
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), 'feed_id')
            ->where('code = ?', $code)
            ->limit(1);

        $id = $connection->fetchOne($select);

        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Persist run state without touching the rest of the row.
     *
     * A generation run updates progress from inside a long loop, where a full
     * model save would re-serialize the condition tree and the template on every
     * batch. It would also race an admin editing the same feed: the model in
     * memory was loaded before the edit, so saving it whole would write stale
     * template text back over the admin's change.
     *
     * @param array<string, mixed> $state
     */
    public function updateRunState(int $feedId, array $state): void
    {
        if ($state === []) {
            return;
        }

        $this->getConnection()->update(
            $this->getMainTable(),
            $state,
            ['feed_id = ?' => $feedId]
        );
    }

    /**
     * Derive a unique code from the feed name, suffixing on collision.
     */
    private function generateCode(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
        if ($base === '') {
            $base = 'feed';
        }
        $base = substr($base, 0, 56);

        $code = $base;
        $suffix = 1;
        while ($this->getIdByCode($code) !== null) {
            $code = $base . '_' . (++$suffix);
        }

        return $code;
    }
}
