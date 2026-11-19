<?php

declare(strict_types=1);

namespace Teme\Directory\Signing;

use InvalidArgumentException;

/**
 * A signing key that is not base64 of an Ed25519 seed or secret key. Its
 * message never repeats the key.
 */
final class InvalidKey extends InvalidArgumentException {}
