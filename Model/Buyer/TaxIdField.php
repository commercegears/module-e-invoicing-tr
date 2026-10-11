<?php
/**
 * @copyright Copyright (c) CommerceGears (https://www.commercegearscommerce.co.uk/)
 */
declare(strict_types=1);

namespace CommerceGears\EInvoicingTr\Model\Buyer;

use CommerceGears\EInvoicingCore\Model\Buyer\Field\TextField;

/**
 * Turkish buyers: VKN (companies, 10 digits) or T.C. identity number (persons, 11 digits), with checksum.
 * Shown for all Turkish buyers (e-Arşiv invoices need it too).
 */
class TaxIdField extends TextField
{
    public function __construct()
    {
        parent::__construct('tr_tax_id', 'Tax number (VKN) or T.C. identity number (TCKN)', ['TR'], 'tax_id', false, 11,
            '', '', '', '', [], [], 40, false, true);
    }

    public function validate(string $value): array
    {
        [$value, $error] = parent::validate($value);
        if ($error === null && $value !== '' && !self::valid($value)) {
            $error = (string)__('Please enter a valid tax number (VKN, 10 digits) or T.C. identity number (TCKN, 11 digits).');
        }
        return [$value, $error];
    }

    /** VKN (10 digits, checksum) or TCKN (11 digits, checksum). */
    public static function valid(string $id): bool
    {
        if (preg_match('/^\d{10}$/', $id)) {
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $tmp = ((int)$id[$i] + 10 - ($i + 1)) % 10;
                $v = ($tmp * (2 ** (10 - ($i + 1)))) % 9;
                if ($tmp !== 0 && $v === 0) {
                    $v = 9;
                }
                $sum += $v;
            }
            return (10 - ($sum % 10)) % 10 === (int)$id[9];
        }
        if (preg_match('/^[1-9]\d{10}$/', $id)) {
            $d = array_map('intval', str_split($id));
            $c10 = (($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7 - ($d[1] + $d[3] + $d[5] + $d[7])) % 10;
            if ($c10 < 0) {
                $c10 += 10;
            }
            return $c10 === $d[9] && (array_sum(array_slice($d, 0, 10)) % 10) === $d[10];
        }
        return false;
    }
}
