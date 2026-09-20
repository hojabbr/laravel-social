<?php

namespace Hojabbr\Social\Contracts;

use Hojabbr\Social\Enums\Placement;
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
     *
     * `$placement` is what the object was published AS, when the caller knows.
     * A network whose metric vocabulary differs per placement needs it: asking
     * a carousel for a reel's watch time is not a missing field, it is a 400
     * that loses every other metric in the same call. Null keeps the caller's
     * old behaviour, so this stays additive.
     */
    public function mediaMetrics(Account $account, int|string $externalId, ?Placement $placement = null): Metrics;

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
