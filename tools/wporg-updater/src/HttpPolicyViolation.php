<?php

declare(strict_types=1);

namespace WpOrgPluginUpdater;

/** A rejected trust policy must never be retried as a transient transport error. */
final class HttpPolicyViolation extends \RuntimeException
{
}
