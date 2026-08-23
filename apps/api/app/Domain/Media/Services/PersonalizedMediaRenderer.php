<?php

namespace App\Domain\Media\Services;

use App\Domain\Media\Models\GeneratedMedia;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PersonalizedMediaRenderer
{
    public const FONTS = [
        'Avenir Next', 'Arial', 'Helvetica', 'Futura', 'Optima',
        'Georgia', 'Times New Roman', 'Baskerville', 'Didot',
        'American Typewriter', 'Copperplate', 'Marker Felt',
        'Brush Script MT', 'Snell Roundhand',
        'Poppins', 'Montserrat', 'Playfair Display', 'Pacifico', 'Great Vibes',
        'Lobster', 'Sacramento', 'Allura',
    ];

    public function render(GeneratedMedia $generated): GeneratedMedia
    {
        $generated->loadMissing(['customer', 'mediaTemplate']);
        $template = $generated->mediaTemplate;
        $configuration = $template->text_configuration;
        $image = new \Imagick;
        $image->readImageBlob(Storage::disk($template->disk)->get($template->background_image_path));
        $image->setIteratorIndex(0);
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(88);

        $text = str_replace(
            ['{{customer_first_name}}', '{{customer_name}}'],
            [$generated->customer->first_name, trim($generated->customer->first_name.' '.$generated->customer->last_name)],
            (string) ($configuration['text'] ?? '{{customer_first_name}}'),
        );
        $points = $this->points($configuration, $image->getImageWidth(), $image->getImageHeight());
        $boxWidth = max(40, (int) round(($this->distance($points[0], $points[1]) + $this->distance($points[3], $points[2])) / 2));
        $boxHeight = max(24, (int) round(($this->distance($points[0], $points[3]) + $this->distance($points[1], $points[2])) / 2));
        $draw = new \ImagickDraw;
        $draw->setFillColor((string) ($configuration['color'] ?? '#17201b'));
        $draw->setTextAlignment(match ($configuration['align'] ?? 'center') {
            'left' => \Imagick::ALIGN_LEFT,
            'right' => \Imagick::ALIGN_RIGHT,
            default => \Imagick::ALIGN_CENTER,
        });
        $font = (string) ($configuration['font_family'] ?? self::FONTS[0]);
        if (! in_array($font, self::FONTS, true)) {
            throw new RuntimeException('Unsupported media font.');
        }
        $fontPath = $this->fontPath($font);
        if ($fontPath) {
            $draw->setFont($fontPath);
        }

        $maxFont = (int) ($configuration['font_size'] ?? 72);
        $minFont = (int) ($configuration['min_font_size'] ?? 20);
        $fontSize = $maxFont;
        $maxLines = (int) ($configuration['max_lines'] ?? 2);
        $lines = [$text];
        while ($fontSize >= $minFont) {
            $draw->setFontSize($fontSize);
            $lines = $this->wrap($image, $draw, $text, $boxWidth, $maxLines);
            $metrics = $image->queryFontMetrics($draw, 'Ag');
            $lineHeight = (float) ($metrics['textHeight'] ?? $fontSize) * 1.08;
            $widest = max(array_map(fn (string $line): float => (float) ($image->queryFontMetrics($draw, $line)['textWidth'] ?? PHP_FLOAT_MAX), $lines));
            if (count($lines) <= $maxLines && $widest <= $boxWidth && ($lineHeight * count($lines)) <= $boxHeight) {
                break;
            }
            $fontSize -= 2;
        }
        $draw->setFontSize(max($minFont, $fontSize));
        $textLayer = new \Imagick;
        $textLayer->newImage($image->getImageWidth(), $image->getImageHeight(), new \ImagickPixel('transparent'), 'png');
        $metrics = $textLayer->queryFontMetrics($draw, 'Ag');
        $lineHeight = (float) ($metrics['textHeight'] ?? $fontSize) * 1.08;
        $startY = max(0, (($boxHeight - ($lineHeight * count($lines))) / 2) + (float) ($metrics['ascender'] ?? $fontSize));
        $anchorX = match ($configuration['align'] ?? 'center') {
            'left' => 0,
            'right' => $boxWidth,
            default => $boxWidth / 2,
        };
        foreach ($lines as $index => $line) {
            $textLayer->annotateImage($draw, $anchorX, $startY + ($index * $lineHeight), 0, $line);
        }
        $textLayer->setImageVirtualPixelMethod(\Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
        $textLayer->setImageArtifact('distort:viewport', $image->getImageWidth().'x'.$image->getImageHeight().'+0+0');
        $textLayer->distortImage(\Imagick::DISTORTION_PERSPECTIVE, [
            0, 0, $points[0][0], $points[0][1],
            $boxWidth, 0, $points[1][0], $points[1][1],
            $boxWidth, $boxHeight, $points[2][0], $points[2][1],
            0, $boxHeight, $points[3][0], $points[3][1],
        ], false);
        $image->compositeImage($textLayer, \Imagick::COMPOSITE_OVER, 0, 0);

        $path = 'businesses/'.$generated->business_id.'/generated/'.$generated->id.'.jpg';
        Storage::disk($generated->disk)->put($path, $image->getImagesBlob());
        $generated->update(['path' => $path, 'mime_type' => 'image/jpeg', 'status' => 'ready', 'failure_message' => null]);
        $draw->clear();
        $textLayer->clear();
        $image->clear();

        return $generated->fresh();
    }

    private function fontPath(string $font): ?string
    {
        $paths = match ($font) {
            'Avenir Next' => ['/System/Library/Fonts/Avenir Next.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
            'Arial' => ['/System/Library/Fonts/Supplemental/Arial.ttf', '/usr/share/fonts/truetype/msttcorefonts/Arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
            'Georgia' => ['/System/Library/Fonts/Supplemental/Georgia.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'],
            'Helvetica' => ['/System/Library/Fonts/Helvetica.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
            'Times New Roman' => ['/System/Library/Fonts/Supplemental/Times New Roman.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'],
            'Baskerville' => ['/System/Library/Fonts/Supplemental/Baskerville.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'],
            'Didot' => ['/System/Library/Fonts/Supplemental/Didot.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'],
            'American Typewriter' => ['/System/Library/Fonts/Supplemental/AmericanTypewriter.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'],
            'Copperplate' => ['/System/Library/Fonts/Supplemental/Copperplate.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf'],
            'Marker Felt' => ['/System/Library/Fonts/MarkerFelt.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
            'Brush Script MT' => ['/System/Library/Fonts/Supplemental/Brush Script.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Oblique.ttf'],
            'Snell Roundhand' => ['/System/Library/Fonts/Supplemental/SnellRoundhand.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Italic.ttf'],
            'Optima' => ['/System/Library/Fonts/Optima.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
            'Futura' => ['/System/Library/Fonts/Supplemental/Futura.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
            'Poppins' => [resource_path('fonts/Poppins-Regular.ttf')],
            'Montserrat' => [resource_path('fonts/Montserrat.ttf')],
            'Playfair Display' => [resource_path('fonts/PlayfairDisplay.ttf')],
            'Pacifico' => [resource_path('fonts/Pacifico-Regular.ttf')],
            'Great Vibes' => [resource_path('fonts/GreatVibes-Regular.ttf')],
            'Lobster' => [resource_path('fonts/Lobster-Regular.ttf')],
            'Sacramento' => [resource_path('fonts/Sacramento-Regular.ttf')],
            'Allura' => [resource_path('fonts/Allura-Regular.ttf')],
            default => [],
        };

        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function points(array $configuration, int $width, int $height): array
    {
        $x = (float) ($configuration['x'] ?? 22);
        $y = (float) ($configuration['y'] ?? 38);
        $right = $x + (float) ($configuration['width'] ?? 56);
        $bottom = $y + (float) ($configuration['height'] ?? 24);
        $percentages = [
            [(float) ($configuration['top_left_x'] ?? $x), (float) ($configuration['top_left_y'] ?? $y)],
            [(float) ($configuration['top_right_x'] ?? $right), (float) ($configuration['top_right_y'] ?? $y)],
            [(float) ($configuration['bottom_right_x'] ?? $right), (float) ($configuration['bottom_right_y'] ?? $bottom)],
            [(float) ($configuration['bottom_left_x'] ?? $x), (float) ($configuration['bottom_left_y'] ?? $bottom)],
        ];

        return array_map(fn (array $point): array => [($point[0] / 100) * $width, ($point[1] / 100) * $height], $percentages);
    }

    /** @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b */
    private function distance(array $a, array $b): float
    {
        return hypot($b[0] - $a[0], $b[1] - $a[1]);
    }

    /** @return list<string> */
    private function wrap(\Imagick $image, \ImagickDraw $draw, string $text, float $width, int $maxLines): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [$text];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $wordWidth = (float) ($image->queryFontMetrics($draw, $word)['textWidth'] ?? PHP_FLOAT_MAX);
            if ($wordWidth > $width) {
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $chunk = '';
                foreach (mb_str_split($word) as $character) {
                    $candidateChunk = $chunk.$character;
                    if ($chunk !== '' && (float) ($image->queryFontMetrics($draw, $candidateChunk)['textWidth'] ?? PHP_FLOAT_MAX) > $width) {
                        $lines[] = $chunk;
                        $chunk = $character;
                    } else {
                        $chunk = $candidateChunk;
                    }
                }
                $current = $chunk;

                continue;
            }
            $candidate = trim($current.' '.$word);
            $candidateWidth = (float) ($image->queryFontMetrics($draw, $candidate)['textWidth'] ?? PHP_FLOAT_MAX);
            if ($current !== '' && $candidateWidth > $width) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return count($lines) > $maxLines ? [$text] : $lines;
    }
}
