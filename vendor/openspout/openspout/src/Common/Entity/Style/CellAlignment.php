<?php

declare(strict_types=1);

namespace OpenSpout\Common\Entity\Style;

enum CellAlignment: string
{
    case LEFT = 'left';
    case RIGHT = 'right';
    case CENTER = 'center';
    case JUSTIFY = 'justify';
}
