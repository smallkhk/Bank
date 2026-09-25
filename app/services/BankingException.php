<?php
declare(strict_types=1);

namespace App\Services;

/** A business-rule failure whose message is safe to show to the user. */
final class BankingException extends \RuntimeException
{
}
