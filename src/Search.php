<?php

namespace XLR8;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use NumberFormatter;
use XLR8\Exception\XLR8Exception;

/**
 *
 */
class Search
{
    const ITEM_PARAM_HOTEL = 0;
    const ITEM_PARAM_LAT = 1;
    const ITEM_PARAM_LOG = 2;
    const ITEM_PARAM_PRICE = 3;
    const PARAM_ORDER_BY_PROXIMITY = "proximity";
    const PARAM_ORDER_BY_PRICE_NIGHT = "price night";
    const VALID_ORDER_BY = [
        self::PARAM_ORDER_BY_PROXIMITY,
        self::PARAM_ORDER_BY_PRICE_NIGHT
    ];
    const ERROR_IS_REQUIRED = "%s is required";
    const ERROR_FORMATTER = "Formatter error";
    private static $endpoints = [
        'source_1' => 'https://xlr8-interview-files.s3.eu-west-2.amazonaws.com/source_1.json',
        'source_2' => 'https://xlr8-interview-files.s3.eu-west-2.amazonaws.com/source_2.json'
    ];
    private static $selected_endpoint = "source_1";

    /**
     * @param string|null $latitude
     * @param string|null $longitude
     * @param string|null $orderby
     * @param int|null $page
     * @param int|null $limit
     * @param bool|null $responseJson
     * @param string|null $select_source
     * @param array|null $add_sources
     * @return void
     * @throws \XLR8\Exception\XLR8Exception
     */
    public static function getNearbyHotels(
        ?string $latitude,
        ?string $longitude,
        ?string $orderby = "proximity",
        ?int    $page = 0,
        ?int    $limit = 15,
        ?bool   $responseJson = false,
        ?string $select_source = null,
        ?array  $add_sources = null
    )
    {
        $order = in_array($orderby, self::VALID_ORDER_BY) ? $orderby : self::PARAM_ORDER_BY_PROXIMITY;
        self::verifyIsNull("Latitude", $latitude);
        self::verifyIsNull("Longitude", $longitude);

        if ($add_sources)
            self::addSources($add_sources);

        if ($select_source)
            self::selectEndPoint($select_source);

        $data = self::getDataFromSource($order);

        $dataFormated = self::addMileage($latitude, $longitude, $data);

        $dataOrderned = self::ordering($orderby, $dataFormated);

        if ($responseJson) {
            $response = ['orderby' => $orderby];
            $response = array_merge($response, self::pagination($page, $limit, $dataOrderned));
            self::response($response);
        } else {
            self::responseList($dataOrderned);
        }
    }

    /**
     * @param int|null $page
     * @param int|null $limit
     * @param array $data
     * @return array
     */
    private static function pagination(?int $page, ?int $limit, array $data): array
    {
        $page++;
        $total = count($data);
        $limit = $limit ?? 20;
        $totalPages = ceil($total / $limit);
        $page = max($page, 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $limit;
        if ($offset < 0) $offset = 0;
        return [
            'page' => $page,
            'pages' => $totalPages,
            'data' => array_slice($data, $offset, $limit)
        ];
    }

    /**
     * @param string $orderby
     * @param array $data
     * @return array
     */
    private static function ordering(string $orderby, array $data): array
    {
        if ($orderby === self::PARAM_ORDER_BY_PROXIMITY) {
            $cmp = function ($a, $b) {
                return strcmp($a["km"], $b["km"]);
            };
        } else {
            $cmp = function ($a, $b) {
                return strcmp($a["price"], $b["price"]);
            };
        }
        usort($data, $cmp);

        return $data;
    }

    /**
     * @param string $latitude
     * @param string $longitude
     * @param array $data
     * @return array
     */
    private static function addMileage(string $latitude, string $longitude, array $data): array
    {
        $new_data = [];
        foreach ($data as $item) {
            $km = self::getDistanceBetweenPointsNew(
                abs($latitude),
                abs($longitude),
                abs($item[self::ITEM_PARAM_LAT]),
                abs($item[self::ITEM_PARAM_LOG]),
                'kilometers'
            );
            $new_data[] = [
                'hotel' => $item[self::ITEM_PARAM_HOTEL],
                'km' => $km,
                'price' => floatval($item[self::ITEM_PARAM_PRICE])
            ];
        }
        return $new_data;
    }

    /**
     * @param string $latitude1
     * @param string $longitude1
     * @param string $latitude2
     * @param string $longitude2
     * @param string $unit
     * @return float
     */
    private static function getDistanceBetweenPointsNew(string $latitude1, string $longitude1, string $latitude2, string $longitude2, string $unit = 'miles'): float
    {
        $theta = $longitude1 - $longitude2;
        $distance = (sin(deg2rad($latitude1)) * sin(deg2rad($latitude2))) + (cos(deg2rad($latitude1)) * cos(deg2rad($latitude2)) * cos(deg2rad($theta)));
        $distance = acos($distance);
        $distance = rad2deg($distance);
        $distance = $distance * 60 * 1.1515;
        if ($unit == 'kilometers')
            $distance = $distance * 1.609344;
        return round($distance, 2);
    }

    /**
     * @param string|null $order
     * @return mixed
     * @throws \XLR8\Exception\XLR8Exception
     */
    private static function getDataFromSource(?string $order)
    {
        self::verifyIsNull("Order", $order);

        $result = self::request('GET', self::getEndPoint());
        if (!in_array('success', $result) || !$result['success']) {
            throw new XLR8Exception("No data found");
        } else {
            return $result['message'];
        }
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array $params
     * @return mixed
     * @throws \XLR8\Exception\XLR8Exception
     */
    private static function request(string $method, string $uri, array $params = [])
    {
        try {
            $client = new Client();
            $result = $client->request($method, $uri, $params);
            $json = $result->getBody()->getContents();
            return json_decode($json, true);
        } catch (GuzzleException $e) {
            throw new XLR8Exception($e->getMessage());
        }
    }

    /**
     * @return string
     */
    private static function getEndPoint(): string
    {
        return self::$endpoints[self::$selected_endpoint];
    }

    /**
     * @param array|null $add_sources
     * @return void
     * @throws \XLR8\Exception\XLR8Exception
     */
    private static function addSources(?array $add_sources = null)
    {
        self::verifyIsNull("Sources list", $add_sources);
        $valid_sources = [];
        foreach ($add_sources as $key => $value) {
            if (is_numeric($key) || empty($value) || !filter_var($value, FILTER_VALIDATE_URL))
                continue;
            $valid_sources[$key] = $value;
        }
        self::$endpoints = array_merge(self::$endpoints, $valid_sources);
    }

    /**
     * @param string $name_endpoint
     * @return void
     */
    private static function selectEndPoint(string $name_endpoint)
    {
        if (in_array($name_endpoint, self::$endpoints))
            self::$selected_endpoint = self::$endpoints[$name_endpoint];
    }

    /**
     * @param string $label
     * @param string|null $value
     * @return void
     * @throws \XLR8\Exception\XLR8Exception
     */
    private static function verifyIsNull(string $label, string $value = null)
    {
        if (is_null($value) || empty($label))
            throw new XLR8Exception(sprintf(self::ERROR_IS_REQUIRED, $label));
    }

    /**
     * @param float $amount
     * @param string $locale
     * @param string $currency
     * @param bool $showSymbol
     * @return string
     * @throws \XLR8\Exception\XLR8Exception
     */
    private static function currencyConvert(float $amount, string $locale = 'pt', string $currency = 'EUR', bool $showSymbol = false): string
    {
        $fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        if (!$showSymbol)
            $fmt->setSymbol(NumberFormatter::CURRENCY_SYMBOL, '');

        $fmt_amount = $fmt->formatCurrency($amount, $currency);
        if (intl_is_failure($fmt->getErrorCode())) {
            throw new XLR8Exception(self::ERROR_FORMATTER);
        }
        return $fmt_amount . (!$showSymbol ? " {$currency}" : null);
    }

    /**
     * @return void
     * @throws \XLR8\Exception\XLR8Exception
     */
    private static function responseList(array $data, string $separator = " &bull; ")
    {
        $formatedData = [];
        foreach ($data as $item) {
            $formatedData[] = sprintf("%s, %s, %s", $item['hotel'], $item['km'] . " KM", self::currencyConvert($item['price']));
        }
        echo $separator . implode($separator, $formatedData);
    }

    /**
     * @return void
     */
    private static function response($data)
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
    }
}