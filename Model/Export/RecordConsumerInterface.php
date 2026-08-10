<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model\Export;

use Magenx\ProductFeed\Model\Delivery\DeliveryContext;
use Magenx\ProductFeed\Model\Feed;

/**
 * A destination fed live with records as the export runs.
 *
 * This is what a push catalog API implements. It exists so a push destination is
 * fed from the same record stream the file writer sees, rather than by reading a
 * generated file back and parsing it apart - which would mean the push path
 * inherits every quoting and encoding decision of a file format it does not use.
 *
 * Buffering must not span cron ticks. A run can pause at any batch boundary and
 * resume minutes later in a different process, so flush() is called at the end of
 * every tick, not only at the end of the run. Anything still buffered when a tick
 * ends is lost.
 */
interface RecordConsumerInterface
{
    /**
     * Hand over the destination's decrypted settings before the run starts.
     *
     * The consume() path receives records, not contexts, so a consumer has no
     * other way to learn where it is sending. DeliveryManager calls this once per
     * feed; a consumer that is never prepared must refuse to send rather than
     * quietly drop records.
     */
    public function prepare(DeliveryContext $context): void;

    /**
     * @param array<int, array<string, mixed>> $records One batch, in export order
     */
    public function consume(Feed $feed, array $records): void;

    /**
     * Send whatever is buffered. Called at the end of every tick.
     */
    public function flush(Feed $feed): void;

    /**
     * Called once when the whole run completes successfully, for destinations that
     * need to signal "that was the full catalog".
     */
    public function finish(Feed $feed): void;
}
