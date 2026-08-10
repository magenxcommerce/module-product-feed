<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\ProductFeed\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Decides whether a feed is due.
 *
 * Per-feed schedules cannot be expressed as a cron <config_path>, so the cron
 * jobs are a dispatcher that ticks and asks this class about each active feed.
 *
 * TIMES ARE THE STORE'S, NOT THE SERVER'S. A merchant setting "03:00" means three
 * in the morning where they sell, and a server in another zone would otherwise
 * generate at what is - to them - an arbitrary hour, most visibly around a DST
 * change.
 *
 * A DUE FEED IS ONE WHOSE SLOT HAS PASSED AND WHICH HAS NOT RUN SINCE. It is
 * deliberately not "the tick that lands exactly on the slot": the dispatcher can
 * be late, cron can be wedged for an hour, and a feed that silently skips a day
 * because nothing ran at 03:00 sharp is the single most common complaint about
 * scheduled exports. This means a feed whose slot passed while cron was down runs
 * as soon as cron recovers, which is the desired behaviour.
 */
class Schedule
{
    /**
     * A slot is only considered missed for this long. Beyond it the feed waits for
     * its next slot rather than firing at, say, 23:50 for an 03:00 slot that was
     * missed twenty hours earlier.
     */
    private const CATCH_UP_WINDOW_SECONDS = 6 * 3600;

    public function __construct(
        private readonly TimezoneInterface $localeDate
    ) {
    }

    /**
     * @param int|null $now Unix timestamp, for tests
     */
    public function isDue(Feed $feed, ?int $now = null): bool
    {
        if (!$feed->isActive()) {
            return false;
        }

        // A run that ran out of time budget is always continued, regardless of
        // schedule: leaving a half-written .part file until the next slot would
        // mean a feed that never completes on a store whose catalog needs more
        // than one window.
        if ($feed->getData('cursor_position') !== null) {
            return true;
        }

        $times = $feed->getScheduleTimes();
        if ($times === []) {
            return false;
        }

        $storeId = $feed->getStoreId();
        $timezone = new \DateTimeZone($this->localeDate->getConfigTimezone(null, (string) $storeId));

        $nowUtc = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone('UTC'));
        $nowLocal = $nowUtc->setTimezone($timezone);

        $days = $feed->getScheduleDays();
        if ($days !== [] && !in_array((int) $nowLocal->format('N'), $days, true)) {
            return false;
        }

        $lastRun = $this->lastRunTimestamp($feed);

        foreach ($times as $time) {
            $slot = $this->slotToday($nowLocal, $timezone, $time);
            if ($slot === null) {
                continue;
            }

            $slotTs = $slot->getTimestamp();
            $nowTs = $nowUtc->getTimestamp();

            if ($slotTs > $nowTs) {
                continue;
            }

            if ($nowTs - $slotTs > self::CATCH_UP_WINDOW_SECONDS) {
                continue;
            }

            if ($lastRun === null || $lastRun < $slotTs) {
                return true;
            }
        }

        return false;
    }

    private function slotToday(
        \DateTimeImmutable $nowLocal,
        \DateTimeZone $timezone,
        string $time
    ): ?\DateTimeImmutable {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $slot = $nowLocal->setTime($hour, $minute, 0);

        // setTime() on a DST spring-forward gap yields a moment that does not exist
        // locally; PHP normalises it forward, which is the behaviour we want (the
        // feed runs an hour "late" once a year rather than not at all).
        return $slot->setTimezone(new \DateTimeZone('UTC'));
    }

    private function lastRunTimestamp(Feed $feed): ?int
    {
        $lastRun = (string) $feed->getData('last_generated_at');
        if (trim($lastRun) === '') {
            return null;
        }

        try {
            // Stored in UTC by the runner.
            return (new \DateTimeImmutable($lastRun, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
