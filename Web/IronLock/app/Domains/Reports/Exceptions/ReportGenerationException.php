<?php

namespace App\Domains\Reports\Exceptions;

/**
 * A user-facing failure while preparing a report (e.g. the target shift does
 * not exist, or a required filter is missing). The controller catches this and
 * surfaces getMessage() to the admin as a 422 — unlike an unexpected \Throwable,
 * which becomes a logged 500.
 */
class ReportGenerationException extends \RuntimeException
{
}
