<?php

namespace App\Watch;

use RuntimeException;

/**
 * A source table changed while it was being copied, so the copy cannot be trusted and nothing was dropped.
 */
final class SourceChangedDuringMove extends RuntimeException {}
