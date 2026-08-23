<?php

namespace App\Domain\Media\Services;

class SafeAreaDetector
{
    /** @return array{x: float, y: float, width: float, height: float, confidence: string} */
    public function detect(string $imageBytes): array
    {
        $image = new \Imagick;
        $image->readImageBlob($imageBytes);
        $image->setImageColorspace(\Imagick::COLORSPACE_GRAY);
        $image->thumbnailImage(640, 640, true);
        $imageWidth = $image->getImageWidth();
        $imageHeight = $image->getImageHeight();
        $boxWidth = max(1, (int) round($imageWidth * .56));
        $boxHeight = max(1, (int) round($imageHeight * .24));
        $best = null;

        foreach ([.08, .22, .36, .50, .64, .78] as $centerY) {
            foreach ([.30, .50, .70] as $centerX) {
                $x = max(0, min($imageWidth - $boxWidth, (int) round(($centerX * $imageWidth) - ($boxWidth / 2))));
                $y = max(0, min($imageHeight - $boxHeight, (int) round(($centerY * $imageHeight) - ($boxHeight / 2))));
                $sample = clone $image;
                $sample->cropImage($boxWidth, $boxHeight, $x, $y);
                $statistics = $sample->getImageChannelMean(\Imagick::CHANNEL_GRAY);
                $edgePenalty = abs($centerX - .5) * .04;
                $score = ((float) ($statistics['standardDeviation'] ?? 0) / \Imagick::getQuantum()) + $edgePenalty;
                if ($best === null || $score < $best['score']) {
                    $best = compact('x', 'y', 'score');
                }
                $sample->clear();
            }
        }

        $image->clear();
        $score = (float) ($best['score'] ?? 1);

        return [
            'x' => round((($best['x'] ?? 0) / $imageWidth) * 100, 2),
            'y' => round((($best['y'] ?? 0) / $imageHeight) * 100, 2),
            'width' => 56,
            'height' => 24,
            'confidence' => $score < .08 ? 'high' : ($score < .16 ? 'medium' : 'low'),
        ];
    }
}
