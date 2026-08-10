<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Api;

use Magenx\ProductFeed\Model\Delivery\DeliveryContext;
use Magenx\ProductFeed\Model\Delivery\DeliveryResult;

/**
 * A place a generated feed is sent.
 *
 * The extension point of this module: a new destination is a class implementing
 * this interface plus one <item> in di.xml's DelivererPool. Never special-case a
 * destination inside the runner or the delivery manager.
 *
 * Two shapes exist behind this one interface:
 *
 *   FILE-BASED (file, ftp, sftp, google_datasource) act on the published file
 *   after a run completes. deliver() does the work.
 *
 *   PUSH-BASED (a catalog API) additionally implement RecordConsumerInterface and
 *   are fed records live during the run. Their deliver() is then a no-op or a
 *   final confirmation, because the data has already gone.
 */
interface DelivererInterface
{
    /**
     * Send the feed. Called once per delivery row, after the file is published.
     */
    public function deliver(DeliveryContext $context): DeliveryResult;

    /**
     * Whether this deliverer can run at all right now - an optional SDK present,
     * credentials configured.
     *
     * Reported rather than thrown, so an unavailable destination shows an
     * actionable message in the feed's history and on the form instead of turning
     * every run into a failure.
     */
    public function isAvailable(DeliveryContext $context): DeliveryResult;

    /**
     * Verify the configuration without sending the feed.
     *
     * MUST NOT write anything to the destination. A test that leaves a file behind
     * gets ingested as a bogus feed by whatever consumer is watching that folder,
     * and a test file copied from the module's own directory publishes source code
     * to a third party.
     */
    public function testConnection(DeliveryContext $context): DeliveryResult;

    /**
     * Human-readable name for the admin.
     */
    public function getLabel(): string;
}
