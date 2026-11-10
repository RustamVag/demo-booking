<?php

namespace App\Rules;

use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\ValidationRule;

class Iso8601Date implements ValidationRule
{
    /** @var array<string, string> */
    protected array $patterns = [];

    public function __construct(
        protected bool $allowDateOnly = false,
        protected bool $allowDateTime = true,
    ) {
        if ($this->allowDateOnly) {
            $this->patterns['/^\d{4}-\d{2}-\d{2}$/'] = 'Y-m-d';
        }

        if ($this->allowDateTime) {
            $this->patterns = array_merge($this->patterns, [
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/'                    => 'Y-m-d\TH:i:s',
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+$/'                => 'Y-m-d\TH:i:s.u',
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/'                    => 'Y-m-d\TH:i:s\Z',
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/'               => 'Y-m-d\TH:i:s.u\Z',
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/'      => 'Y-m-d\TH:i:sP',
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+[+-]\d{2}:\d{2}$/' => 'Y-m-d\TH:i:s.uP',
            ]);
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !$this->isValid($value)) {
            $fail('Поле :attribute должно быть корректной датой в формате ISO 8601.');
        }
    }

    protected function isValid(string $value): bool
    {
        foreach ($this->patterns as $pattern => $format) {
            if (!preg_match($pattern, $value)) {
                continue;
            }

            $dt = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            // createFromFormat может «перекатить» несуществующие даты
            // поэтому дополнительно смотрим на warnings/errors.
            if ($dt === false) {
                return false;
            }

            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return false;
            }

            return true;
        }

        return false;
    }
}
