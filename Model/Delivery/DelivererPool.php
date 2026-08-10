<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magenx\ProductFeed\Api\DelivererInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Delivery type code -> deliverer, populated from di.xml.
 */
class DelivererPool
{
    /**
     * @param array<string, DelivererInterface> $deliverers
     * @throws LocalizedException
     */
    public function __construct(
        private readonly array $deliverers = []
    ) {
        foreach ($this->deliverers as $code => $deliverer) {
            if (!$deliverer instanceof DelivererInterface) {
                throw new LocalizedException(
                    __('Feed deliverer "%1" must implement %2.', $code, DelivererInterface::class)
                );
            }
        }
    }

    /**
     * @throws LocalizedException
     */
    public function get(string $type): DelivererInterface
    {
        if (!isset($this->deliverers[$type])) {
            throw new LocalizedException(__('No feed deliverer is registered for type "%1".', $type));
        }

        return $this->deliverers[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->deliverers[$type]);
    }

    /**
     * @return array<string, string> code => label, for the admin dropdown
     */
    public function getOptions(): array
    {
        $out = [];
        foreach ($this->deliverers as $code => $deliverer) {
            $out[$code] = $deliverer->getLabel();
        }

        return $out;
    }
}
