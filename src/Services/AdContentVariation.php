<?php

namespace App\Services;

/**
 * Небольшая смена контента при переопубликации, чтобы новое объявление
 * не совпадало со снятым: порядок слов в заголовке, первое предложение
 * описания (или первые два абзаца HTML) и обложка.
 */
class AdContentVariation
{
    /**
     * @param list<string> $images
     * @return array{title: string, description: string, images: list<string>}
     */
    public function vary(string $title, string $description, array $images): array
    {
        return [
            'title' => $this->varyTitle($title),
            'description' => $this->varyDescription($description),
            'images' => $this->varyImages($images),
        ];
    }

    private function varyTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            return $title;
        }

        $parts = preg_split('/\s+/u', $title) ?: [];
        if (count($parts) >= 2) {
            $first = $parts[0];
            $parts[0] = $parts[1];
            $parts[1] = $first;

            return implode(' ', $parts);
        }

        if (!str_ends_with(mb_strtolower($title), 'в наличии')) {
            return $title . ' в наличии';
        }

        return $title;
    }

    private function varyDescription(string $description): string
    {
        $description = trim($description);
        if ($description === '') {
            return $description;
        }

        if (preg_match('/<[a-z][^>]*>/i', $description) === 1) {
            return $this->varyHtmlParagraphs($description);
        }

        if (preg_match('/^(.+?[.!?])(\s+)(.+)$/us', $description, $matches) === 1) {
            $first = trim($matches[1]);
            $rest = trim($matches[3]);
            if ($rest !== '' && $rest !== $first) {
                return $rest . ' ' . $first;
            }
        }

        return $description;
    }

    /**
     * Меняет местами первые два абзаца, не разрезая теги.
     */
    private function varyHtmlParagraphs(string $description): string
    {
        $count = preg_match_all('/<p\b[^>]*>.*?<\/p>/is', $description, $matches, PREG_OFFSET_CAPTURE);
        if ($count < 2) {
            return $description;
        }

        $first = $matches[0][0][0];
        $second = $matches[0][1][0];
        if (trim(strip_tags($first)) === '' || trim(strip_tags($first)) === trim(strip_tags($second))) {
            return $description;
        }

        $pos1 = $matches[0][0][1];
        $pos2 = $matches[0][1][1];
        $between = substr($description, $pos1 + strlen($first), $pos2 - ($pos1 + strlen($first)));
        $after = substr($description, $pos2 + strlen($second));

        return substr($description, 0, $pos1) . $second . $between . $first . $after;
    }

    /**
     * @param list<string> $images
     * @return list<string>
     */
    private function varyImages(array $images): array
    {
        $urls = array_values(array_filter($images, static fn(string $url): bool => $url !== ''));
        if (count($urls) < 2) {
            return $urls;
        }

        $first = array_shift($urls);
        $urls[] = $first;

        return $urls;
    }
}
