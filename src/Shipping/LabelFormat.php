<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Shipping;

enum LabelFormat: string
{
    case A4 = 'PDF_A4';
    case Label10x15 = 'PDF_10x15';
}
