<?php

namespace App\Domain\Templates\Services;

use Illuminate\Validation\ValidationException;

class TemplateRenderer
{
    public const VARIABLES = ['customer_first_name', 'customer_last_name', 'business_name', 'location_name', 'review_link', 'employee_name', 'visit_date'];

    public function validate(string $body): void
    {
        preg_match_all('/{{\s*([a-z_]+)\s*}}/', $body, $matches);
        $unknown = array_diff(array_unique($matches[1]), self::VARIABLES);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => ['Unknown variables: '.implode(', ', $unknown)]]);
        }

        if (! in_array('review_link', $matches[1], true)) {
            throw ValidationException::withMessages(['body' => ['The template must contain {{review_link}}.']]);
        }
    }

    public function render(string $body, array $values): string
    {
        $this->validate($body);

        return preg_replace_callback('/{{\s*([a-z_]+)\s*}}/', fn (array $match): string => (string) ($values[$match[1]] ?? ''), $body) ?? $body;
    }

    public function estimate(string $message): array
    {
        $gsm = preg_match('/[^\x{000A}\x{000D}\x{0020}-\x{007E}]/u', $message) !== 1;
        $length = mb_strlen($message);
        $singleLimit = $gsm ? 160 : 70;
        $multipartLimit = $gsm ? 153 : 67;
        $segments = $length <= $singleLimit ? 1 : (int) ceil($length / $multipartLimit);

        return ['characters' => $length, 'encoding' => $gsm ? 'GSM-7' : 'UCS-2', 'segments' => $segments];
    }
}
