<?php

declare(strict_types=1);

namespace Nubit\Platform\Exception;

enum DomainErrorCode: string
{
    case SaleCashSessionRequired = 'SALE_CASH_SESSION_REQUIRED';
    case SaleItemDiscountPercentInvalid = 'SALE_ITEM_DISCOUNT_PERCENT_INVALID';
}
