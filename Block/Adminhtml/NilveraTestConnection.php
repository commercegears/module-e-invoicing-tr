<?php
/**
 * @copyright Copyright (c) CommerceGears (https://www.commercegearscommerce.co.uk/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Block\Adminhtml;

use CommerceGears\EInvoicingCore\Block\Adminhtml\System\Config\TestConnection;

class NilveraTestConnection extends TestConnection
{
    protected string $channel = 'tr_nilvera';
}
