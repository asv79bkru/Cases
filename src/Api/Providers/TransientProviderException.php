<?php

namespace CasesBot\Api\Providers;

use RuntimeException;

// Временная ошибка провайдера (модель перегружена/недоступна, сетевой сбой) —
// в отличие от обычного RuntimeException, такие ошибки стоит повторить один раз.
final class TransientProviderException extends RuntimeException
{
}
