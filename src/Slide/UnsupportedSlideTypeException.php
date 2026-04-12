<?php

namespace App\Slide;

/**
 * Thrown by SlideBuilder when a slide carries a type that is not in the supported whitelist.
 *
 * T156 — blocks and signals any attempt to render a slide with an unrecognised type.
 */
final class UnsupportedSlideTypeException extends \RuntimeException
{
    public function __construct(string $type)
    {
        parent::__construct(sprintf('Slide type "%s" is not supported.', $type));
    }
}
