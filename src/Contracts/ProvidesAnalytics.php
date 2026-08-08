<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Values\Account;
use Hojabbr\Social\Values\Metrics;

/**
 * A network that will report numbers back.
 *
 * Every method returns Metrics rather than throwing, including on failure
 * (`Metrics::unavailable()`): analytics are read to be displayed, and a page that
 * cannot show one panel should show the others rather than 500.
 */
interface ProvidesAnalytics
{
    /**
     * Performance of one published object.
     */
    public function mediaMetrics(Account $account, int|string $externalId): Metrics;

    /**
     * Account-level figures (followers, reach, views).
     */
    public function accountMetrics(Account $account): Metrics;

    /**
     * What the network's publishing budget looks like right now — Instagram's
     * 25-posts-per-24h quota, YouTube's daily upload allowance. Read BEFORE a
     * burst, so a backfill can stop instead of collecting rejections.
     */
    public function publishingLimit(Account $account): Metrics;
}
