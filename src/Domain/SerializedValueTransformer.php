<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

final class SerializedValueTransformer
{
    /** @param array<int,int> $idMap */
    public function transform(mixed $value, array $idMap, bool $allowPlainNumeric = false): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (!$this->isSerialized($value)) {
            return $allowPlainNumeric && isset($idMap[(int) $value]) && ctype_digit($value) ? (string) $idMap[(int) $value] : $value;
        }

        $data = @unserialize($value, ['allowed_classes' => false]);
        if ($data === false && $value !== 'b:0;') {
            return $value;
        }
        return serialize($this->walk($data, $idMap));
    }

    private function isSerialized(string $value): bool
    {
        $value = trim($value);
        return preg_match('/^(?:N;|[aObisCd]:)/', $value) === 1;
    }

    /** @param array<int,int> $idMap */
    private function walk(mixed $value, array $idMap): mixed
    {
        if (is_int($value) && isset($idMap[$value])) {
            return $idMap[$value];
        }
        if (is_string($value) && ctype_digit($value) && isset($idMap[(int) $value])) {
            return (string) $idMap[(int) $value];
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->walk($item, $idMap);
            }
        }
        return $value;
    }
}
