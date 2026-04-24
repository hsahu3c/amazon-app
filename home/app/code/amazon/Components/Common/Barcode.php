<?php


namespace App\Amazon\Components\Common;

use App\Core\Components\Base as Base;
class Barcode extends Base
{
    public const TYPE_GTIN = 'GTIN';
     // 14 digits
    public const TYPE_EAN = 'EAN';
     // 13 digits
    public const TYPE_UPC = 'UPC';
     // 12 digits
    public const TYPE_ISBN_10 = 'ISBN';
     // 10 digits excluding dashes
    public const TYPE_ISBN_13 = 'ISBN';
     // 13 digits excluding dashes
    public const TYPE_EAN_8 = 'EAN-8';

    public const TYPE_ASIN = 'ASIN';

    public const TYPE_UPC_COUPON_CODE = 'UPC Coupon Code';

    public $barcode;

    public $type;

    public $gtin;

    public $valid;

    public $allowedIdentifiers = [self::TYPE_GTIN, self::TYPE_EAN, self::TYPE_EAN_8, self::TYPE_UPC, self::TYPE_ASIN, self::TYPE_ISBN_10, self::TYPE_ISBN_13];


    public function setBarcode($barcode)
    {
        $type = false;
        $barcode = (string)$barcode;
        // Trims parsed string to remove unwanted whitespace or characters
        $barcode = trim($barcode);
        if (preg_match('/[^0-9]/', $barcode)) {
            $isbn = $this->isIsbn($barcode);
            if ($isbn == false) {
                $asin = $this->isAsin($barcode);
                if ($asin) {
                    $type = self::TYPE_ASIN;
                }
            } else {
                $type = $isbn;
            }
        } else {
            $gtin = $barcode;
            $length = strlen($gtin);
            if (($length > 11 && $length <= 14) || $length == 8) {
                $zeros = 18 - $length;
                $length = null;
                $fill = '';
                for ($i = 0; $i < $zeros; $i++) {
                    $fill .= '0';
                }

                $gtin = $fill . $gtin;
                $fill = null;
                $valid = true;
                if (!$this->checkDigitValid($gtin)) {
                    $valid = false;
                } elseif (substr($gtin, 5, 1) > 2) {
                    // EAN / JAN / EAN-13 code
                    $type = self::TYPE_EAN;
                } elseif (substr($gtin, 6, 1) == 0 && substr($gtin, 0, 10) == 0) {
                    // EAN-8 / GTIN-8 code
                    $type = self::TYPE_EAN_8;
                } elseif (substr($gtin, 5, 1) <= 0) {
                    // UPC / UCC-12 GTIN-12 code
                    if (substr($gtin, 6, 1) == 5) {
                        $type = self::TYPE_UPC_COUPON_CODE;
                    } else {
                        $type = self::TYPE_UPC;
                    }
                } elseif (substr($gtin, 0, 6) == 0) {
                    // GTIN-14 code
                    $type = self::TYPE_GTIN;
                } else {
                    // EAN code
                    $type = self::TYPE_EAN;
                }
            }
        }

        return $this->barcodeValidator($barcode , $type);
    }

    //barcode matching as per new schema
    public function barcodeValidator($barcode , $type)
    {
        if (preg_match('/^[0-9]{12}$/', (string) $barcode)) {
            return self::TYPE_UPC;
        }
        // if (preg_match('/^(97[89])?\d{9}(\d|X)$/', (string) $barcode)) {
        //     return self::TYPE_ISBN_10;
        // }
        if (preg_match('/^[0-9]{13}$/', (string) $barcode)) {
            return self::TYPE_EAN;
        }
        if (preg_match('/^[0-9]{8}$/', (string) $barcode)) {
            return self::TYPE_EAN_8;
        }
        if (preg_match('/^(?i)(B0|BT)[0-9A-Z]{8}$/', (string) $barcode)) {
            return self::TYPE_ASIN;
        }
        if (preg_match('/^\d{8,14}$/', (string) $barcode)) {
            return self::TYPE_GTIN;
        }
        else {
            return $type;
        }

        return true;
    }


      public function getBarcodeType($data)
    {
        $barcode = $data['barcode'] ?? null;
        if(!is_null($barcode)){
            $type = $this->setBarcode($barcode);
            return ['success'=>true ,'response' => $type];
        }
        return ['success'=>false ,'msg' => 'Barcode is a required field'];

    }

    public function isIsbn($barcode)
    {
        $regex = '/\b(?:ISBN(?:: ?| ))?((?:97[89])?\d{9}[\dx])\b/i';
        if (preg_match($regex, str_replace('-', '', $barcode), $matches)) {
            if (strlen($matches[1]) === 10) {
                $valid = true;
                $type = self::TYPE_ISBN_10;
            } else {
                $valid = true;
                $type = self::TYPE_ISBN_13;
            }

            return $type;
        }

        return false; // No valid ISBN found
    }

    public function isAsin($barcode)
    {
        $ptn = "/B[0-9]{2}[0-9A-Z]{7}|[0-9]{9}(X|0-9])/";
        if (preg_match($ptn, (string) $barcode, $matches)) {
            $valid = true;
            $type = self::TYPE_ASIN;
            return true;
        }

        return false;
    }

    public function checkDigitValid($gtin)
    {
        $calculation = 0;
        for ($i = 0; $i < (strlen((string) $gtin) - 1); $i++) {
            $calculation += $i % 2 ? $gtin[$i] * 1 : $gtin[$i] * 3;
        }

        if (substr(10 - (substr($calculation, -1)), -1) != substr((string) $gtin, -1)) {
            return false;
        }
        return true;
    }


    public function getGTIN14($gtin)
    {
        return substr((string) $gtin, -14);
    }

}