<?php

namespace App\Support;

/**
 * Amounts in words, as invoices and acts write them: "нэг сая хоёр зуун тавин мянган
 * төгрөг 00 мөнгө", or "one million two hundred fifty thousand tugrik 00/100".
 *
 * In Mongolian a number word takes its joined form (гурав → гурван, хорь → хорин,
 * зуу → зуун) when another word follows it, and the last word too when the currency
 * follows. Мянга joins only then (мянган төгрөг); before more digits it stays мянга.
 */
class NumberWords
{
    /** [alone, joined] */
    private const MN_UNITS = [1 => ['нэг', 'нэг'], ['хоёр', 'хоёр'], ['гурав', 'гурван'], ['дөрөв', 'дөрвөн'], ['тав', 'таван'], ['зургаа', 'зургаан'], ['долоо', 'долоон'], ['найм', 'найман'], ['ес', 'есөн']];

    private const MN_TENS = [1 => ['арав', 'арван'], ['хорь', 'хорин'], ['гуч', 'гучин'], ['дөч', 'дөчин'], ['тавь', 'тавин'], ['жар', 'жаран'], ['дал', 'далан'], ['ная', 'наян'], ['ер', 'ерэн']];

    private const MN_HUNDRED = ['зуу', 'зуун'];

    /** Thousand, million, billion, trillion. */
    private const MN_SCALES = [1 => ['мянга', 'мянган'], ['сая', 'сая'], ['тэрбум', 'тэрбум'], ['их наяд', 'их наяд']];

    private const EN_ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const EN_TENS = [2 => 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    private const EN_SCALES = [1 => 'thousand', 'million', 'billion', 'trillion'];

    /**
     * An amount in words with its currency and the cents as digits. Without a currency word,
     * only the whole part in words.
     */
    public static function amount(float|int|string $value, string $language = 'mn', ?string $currency = null, ?string $subunit = null): string
    {
        $value = round((float) $value, 2);
        $whole = (int) floor(abs($value));
        $cents = (int) round((abs($value) - $whole) * 100);
        $words = $language === 'en' ? self::english($whole) : self::mongolian($whole, $currency !== null && $currency !== '');
        $text = ($value < 0 ? ($language === 'en' ? 'minus ' : 'хасах ') : '').$words;
        if ($currency) {
            $text .= ' '.$currency;
            $text .= $language === 'en' ? sprintf(' %02d/100', $cents) : sprintf(' %02d', $cents).($subunit ? ' '.$subunit : '');
        }

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** A whole number in Mongolian; $followed: a noun comes after it, so the last word joins too. */
    public static function mongolian(int $n, bool $followed = false): string
    {
        if ($n === 0) {
            return 'тэг';
        }
        // Each word as [alone, joined], biggest first.
        $words = [];
        $groups = array_reverse(str_split(str_pad((string) $n, (int) ceil(strlen((string) $n) / 3) * 3, '0', STR_PAD_LEFT), 3));
        for ($scale = count($groups) - 1; $scale >= 0; $scale--) {
            $group = (int) $groups[$scale];
            if ($group === 0) {
                continue;
            }
            $hundreds = intdiv($group, 100);
            $tens = intdiv($group % 100, 10);
            $units = $group % 10;
            if ($hundreds) {
                $words[] = self::MN_UNITS[$hundreds];
                $words[] = self::MN_HUNDRED;
            }
            if ($tens) {
                $words[] = self::MN_TENS[$tens];
            }
            if ($units) {
                $words[] = self::MN_UNITS[$units];
            }
            if ($scale > 0) {
                $words[] = self::MN_SCALES[$scale];
            }
        }
        $last = count($words) - 1;
        // Мянга only joins at the end, before the noun: "хоёр мянга таван зуу", "хоёр мянган төгрөг".
        $joined = fn ($w, $i) => $i === $last ? $followed : $w !== self::MN_SCALES[1];

        return implode(' ', array_map(fn ($w, $i) => $w[$joined($w, $i) ? 1 : 0], $words, array_keys($words)));
    }

    public static function english(int $n): string
    {
        if ($n === 0) {
            return 'zero';
        }
        $parts = [];
        $groups = array_reverse(str_split(str_pad((string) $n, (int) ceil(strlen((string) $n) / 3) * 3, '0', STR_PAD_LEFT), 3));
        for ($scale = count($groups) - 1; $scale >= 0; $scale--) {
            $group = (int) $groups[$scale];
            if ($group === 0) {
                continue;
            }
            $words = [];
            if ($group >= 100) {
                $words[] = self::EN_ONES[intdiv($group, 100)].' hundred';
            }
            $rest = $group % 100;
            if ($rest >= 20) {
                $words[] = self::EN_TENS[intdiv($rest, 10)].($rest % 10 ? '-'.self::EN_ONES[$rest % 10] : '');
            } elseif ($rest > 0) {
                $words[] = self::EN_ONES[$rest];
            }
            if ($scale > 0) {
                $words[] = self::EN_SCALES[$scale];
            }
            $parts[] = implode(' ', $words);
        }

        return implode(' ', $parts);
    }
}
