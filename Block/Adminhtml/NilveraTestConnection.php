<?php
/**
 * @copyright Copyright (c) Softaware Commerce (https://www.softawarecommerce.co.uk/)
 */
declare(strict_types=1);

namespace Softaware\EInvoicingTr\Block\Adminhtml;

use Softaware\EInvoicingCore\Block\Adminhtml\System\Config\TestConnection;

class NilveraTestConnection extends TestConnection
{
    protected string $channel = 'tr_nilvera';
}
