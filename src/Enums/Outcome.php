<?php

namespace Hojabbr\Social\Enums;

/**
 * What a publish attempt actually did. Three states, not two, because the caller
 * has to treat them differently around a durable post claim:
 *
 * - Sent     — confirmed. Record the returned id.
 * - Rejected — the network answered and DEFINITIVELY refused. Nothing exists on
 *              the other side, so a claim may be released and a corrected retry
 *              is allowed.
 * - Unknown  — the outcome was never observed (a dropped connection, a timeout,
 *              an ambiguous 5xx). Something MAY be live. The claim is KEPT and a
 *              human decides. Never blind-retry.
 *
 * Collapsing Unknown into Rejected is the mistake this enum exists to prevent:
 * it turns "we cannot tell" into "it is safe to post again", and the duplicate
 * lands on a public account.
 */
enum Outcome: string
{
    case Sent = 'sent';
    case Rejected = 'rejected';
    case Unknown = 'unknown';
}
