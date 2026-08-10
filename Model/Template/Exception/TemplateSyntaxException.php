<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Template\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * A template the compiler cannot make sense of.
 *
 * Thrown at compile time - once per generation run, never per product - so the
 * admin sees the problem before any file is written rather than after a partial
 * export.
 */
class TemplateSyntaxException extends LocalizedException
{
    public function __construct(string $message, ?\Throwable $cause = null)
    {
        parent::__construct(__($message), $cause);
    }
}
