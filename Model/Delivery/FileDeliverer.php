<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Delivery;

use Magenx\ProductFeed\Api\DelivererInterface;

/**
 * "Delivery" by publishing the file at its public URL.
 *
 * The runner has already published atomically by the time this runs, so there is
 * nothing to transfer - this deliverer exists so that "fetched from our URL" is a
 * destination a merchant explicitly enables and can see the state of, rather than
 * an implicit side effect. Every fetch-based consumer (the long tail of European
 * comparison engines, and Google's own data-source fetch) points here.
 */
class FileDeliverer implements DelivererInterface
{
    public function deliver(DeliveryContext $context): DeliveryResult
    {
        if ($context->publicUrl === '') {
            return DeliveryResult::error('The feed has no public URL.');
        }

        return DeliveryResult::success(
            __('Published at %1', $context->publicUrl)->render(),
            ['url' => $context->publicUrl]
        );
    }

    public function isAvailable(DeliveryContext $context): DeliveryResult
    {
        return DeliveryResult::success('Available.');
    }

    public function testConnection(DeliveryContext $context): DeliveryResult
    {
        return DeliveryResult::success(
            __('The feed will be published at %1', $context->publicUrl)->render()
        );
    }

    public function getLabel(): string
    {
        return (string) __('Public URL (consumer fetches the file)');
    }
}
