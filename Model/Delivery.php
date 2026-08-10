<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model;

use Magenx\ProductFeed\Model\ResourceModel\Delivery as DeliveryResource;
use Magento\Framework\Model\AbstractModel;

/**
 * One configured destination for one feed.
 */
class Delivery extends AbstractModel
{
    protected $_eventPrefix = 'magenx_product_feed_delivery';

    protected $_eventObject = 'delivery';

    protected function _construct(): void
    {
        $this->_init(DeliveryResource::class);
        $this->setIdFieldName('delivery_id');
    }

    public function getType(): string
    {
        return (string) $this->getData('type');
    }

    public function isActive(): bool
    {
        return (bool) $this->getData('is_active');
    }

    /**
     * Raw settings, still encrypted where they are secret.
     *
     * Decryption happens in DeliveryManager, never here - see DeliveryContext.
     *
     * @return array<string, mixed>
     */
    public function getConfigData(): array
    {
        $raw = (string) $this->getData('config');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfigData(array $config): self
    {
        $this->setData('config', json_encode($config, JSON_UNESCAPED_SLASHES));

        return $this;
    }
}
