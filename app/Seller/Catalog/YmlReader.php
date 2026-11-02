<?php

namespace App\Seller\Catalog;

use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

final class YmlReader
{
    public function parse(#[\SensitiveParameter] string $body): array
    {
        if (strlen($body) > 5000000 || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            throw ValidationException::withMessages(['url' => 'Некорректный или слишком большой YML.']);
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $xml || ! isset($xml->shop->offers)) {
            throw ValidationException::withMessages(['url' => 'Не найдены товары YML.']);
        }
        $categories = [];
        foreach ($xml->shop->categories->category ?? [] as $category) {
            $categories[(string) $category['id']] = trim((string) $category);
        }
        $items = [];
        foreach ($xml->shop->offers->offer as $offer) {
            if (count($items) >= 20000) {
                throw ValidationException::withMessages(['url' => 'В YML больше 20 000 товаров.']);
            }
            $name = trim((string) $offer->name);
            if ($name === '') {
                $name = trim((string) $offer->vendor.' '.(string) $offer->model);
            }
            $sku = trim((string) ($offer->vendorCode ?: $offer['id']));
            $category = $categories[(string) $offer->categoryId] ?? null;
            $raw = trim((string) $offer->price);
            $price = $raw === '' ? null : str_replace(',', '.', $raw);
            $currency = strtoupper(trim((string) $offer->currencyId));
            $stockRaw = trim((string) $offer->count);
            $stock = $stockRaw === '' ? (strtolower((string) $offer['available']) === 'false' ? '0' : null) : str_replace(',', '.', $stockRaw);
            if ($name === '' || mb_strlen($name) > 500 || mb_strlen($sku) > 255 || ($category && mb_strlen($category) > 255)
                || ($currency !== '' && ! in_array($currency, ['RUB', 'RUR'], true))
                || ($price !== null && (! is_numeric($price) || (float) $price < 0 || (float) $price >= 100000000000))
                || ($stock !== null && (! is_numeric($stock) || (float) $stock < 0 || (float) $stock >= 100000000000))) {
                throw ValidationException::withMessages(['url' => 'Проверьте название, валюту, цену и остаток в YML.']);
            }
            $items[] = ['name' => $name, 'sku' => $sku ?: null, 'category' => $category, 'price' => $price, 'wholesale_price' => null, 'stock' => $stock];
        }
        if (! $items) {
            throw ValidationException::withMessages(['url' => 'В YML нет товаров.']);
        }

        return $items;
    }
}
