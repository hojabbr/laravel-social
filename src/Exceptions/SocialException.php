<?php

namespace Hojabbr\Social\Exceptions;

use RuntimeException;

/**
 * Base for the few things this package DOES throw. Publishing is not among them:
 * a network refusal is a PublishResult, not an exception. What throws here are
 * configuration and wiring mistakes — a network that does not exist, a driver
 * whose SDK is not installed, an account with no credentials — because those are
 * bugs a developer fixes, not outcomes a queue worker records.
 */
class SocialException extends RuntimeException {}
