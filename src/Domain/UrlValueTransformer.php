<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Domain;

use WpDbSafeMerge\Infrastructure\SqlSyntax;
use WpDbSafeMerge\Infrastructure\SqlWriter;

final class UrlValueTransformer
{
    /** @var list<string> */
    private array $sourceHosts;
    /** @var list<string> */
    private array $sourceEmailHosts;
    private string $targetOrigin;
    private string $targetHost;
    private bool $replaceUrlAndHosts = true;
    private bool $replaceEmailDomains = true;
    /** @var array<string,true>|null */
    private ?array $allowedEmails = null;

    public function __construct(string $baseUrl, string ...$incomingUrls)
    {
        $base = $this->urlParts($baseUrl);
        if ($base === null || $incomingUrls === []) {
            throw new \InvalidArgumentException('URLの形式が正しくありません。');
        }

        $this->targetOrigin = $base['origin'];
        $this->targetHost = $base['host'];
        $incomingHosts = [];
        $incomingEmailHosts = [];
        foreach ($incomingUrls as $incomingUrl) {
            $incoming = $this->urlParts($incomingUrl);
            if ($incoming === null) { throw new \InvalidArgumentException('URLの形式が正しくありません。'); }
            $incomingEmailHosts[] = $incoming['host'];
            $incomingHosts[] = $incoming['host'];
            $incomingHosts[] = str_starts_with($incoming['host'], 'www.')
                ? substr($incoming['host'], 4)
                : 'www.' . $incoming['host'];
        }
        $this->sourceHosts = array_values(array_unique(array_merge($incomingHosts, [$base['host']])));
        $this->sourceEmailHosts = array_values(array_unique($incomingEmailHosts));
    }

    public function targetOrigin(): string
    {
        return $this->targetOrigin;
    }

    public function withEmailDomains(bool $enabled): self
    {
        $transformer = clone $this;
        $transformer->replaceEmailDomains = $enabled;
        return $transformer;
    }

    public function withUrlAndHosts(bool $enabled): self
    {
        $transformer = clone $this;
        $transformer->replaceUrlAndHosts = $enabled;
        return $transformer;
    }

    /** @param list<string>|null $emails */
    public function withAllowedEmails(?array $emails): self
    {
        $transformer = clone $this;
        $transformer->allowedEmails = $emails === null ? null : array_fill_keys(array_map('strtolower', $emails), true);
        return $transformer;
    }

    /** @return array{value:mixed,replacements:int,kinds:array{url:int,host:int,email:int},emails:array<string,array{source:string,target:string,count:int}>} */
    public function transform(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return ['value' => $value, 'replacements' => 0, 'kinds' => $this->emptyKinds(), 'emails' => []];
        }
        $trimmed = trim($value);
        if ($this->isSerialized($trimmed)) {
            $data = @unserialize($trimmed, ['allowed_classes' => false]);
            if ($data !== false || $trimmed === 'b:0;') {
                $count = 0;
                $kinds = $this->emptyKinds();
                $emails = [];
                $data = $this->walk($data, $count, $kinds, $emails);
                return ['value' => serialize($data), 'replacements' => $count, 'kinds' => $kinds, 'emails' => $emails];
            }
        }

        $count = 0;
        $kinds = $this->emptyKinds();
        $emails = [];
        return ['value' => $this->replacePlain($value, $count, $kinds, $emails), 'replacements' => $count, 'kinds' => $kinds, 'emails' => $emails];
    }

    /** @return array{sql:string,replacements:int,kinds:array{url:int,host:int,email:int},emails:array<string,array{source:string,target:string,count:int}>} */
    public function transformSql(string $sql): array
    {
        $candidate = false;
        foreach ($this->sourceHosts as $host) {
            if (stripos($sql, $host) !== false) { $candidate = true; break; }
        }
        if (!$candidate) { return ['sql' => $sql, 'replacements' => 0, 'kinds' => $this->emptyKinds(), 'emails' => []]; }

        $output = '';
        $count = 0;
        $kinds = $this->emptyKinds();
        $emails = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            if ($sql[$i] !== "'") {
                $output .= $sql[$i];
                continue;
            }
            $start = $i;
            for ($i++; $i < $length; $i++) {
                if ($sql[$i] === '\\') {
                    $i++;
                    continue;
                }
                if ($sql[$i] !== "'") { continue; }
                if ($i + 1 < $length && $sql[$i + 1] === "'") {
                    $i++;
                    continue;
                }
                break;
            }
            if ($i >= $length) {
                $output .= substr($sql, $start);
                break;
            }
            $literal = substr($sql, $start, $i - $start + 1);
            $result = $this->transform(SqlSyntax::decodeValue($literal));
            if ($result['replacements'] > 0) {
                $output .= SqlWriter::value($result['value']);
                $count += $result['replacements'];
                foreach ($result['kinds'] as $kind => $kindCount) { $kinds[$kind] += $kindCount; }
                $this->mergeEmails($emails, $result['emails']);
            } else {
                $output .= $literal;
            }
        }
        return ['sql' => $output, 'replacements' => $count, 'kinds' => $kinds, 'emails' => $emails];
    }

    /** @return array{origin:string,host:string}|null */
    private function urlParts(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        if (isset($parts['port'])) { $host .= ':' . (int) $parts['port']; }
        return ['origin' => $scheme . '://' . $host, 'host' => $host];
    }

    private function isSerialized(string $value): bool
    {
        return preg_match('/^(?:N;|[aObisCd]:)/', $value) === 1;
    }

    /** @param array{url:int,host:int,email:int} $kinds */
    private function walk(mixed $value, int &$count, array &$kinds, array &$emails): mixed
    {
        if (is_string($value)) {
            $result = $this->transform($value);
            $count += $result['replacements'];
            foreach ($result['kinds'] as $kind => $kindCount) { $kinds[$kind] += $kindCount; }
            $this->mergeEmails($emails, $result['emails']);
            return $result['value'];
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) { $value[$key] = $this->walk($item, $count, $kinds, $emails); }
        }
        return $value;
    }

    /** @param array{url:int,host:int,email:int} $kinds */
    private function replacePlain(string $value, int &$count, array &$kinds, array &$emails): string
    {
        foreach ($this->sourceHosts as $host) {
            $quotedHost = preg_quote($host, '/');
            if ($this->replaceUrlAndHosts) {
                $value = preg_replace_callback(
                    '/(?:https?:)?\/\/' . $quotedHost . '(?![A-Za-z0-9.:-])/i',
                    function (array $match) use (&$count, &$kinds): string {
                        if (strcasecmp($match[0], $this->targetOrigin) !== 0) { $count++; $kinds['url']++; }
                        return $this->targetOrigin;
                    },
                    $value
                ) ?? $value;

                $escapedOrigin = str_replace('/', '\\/', $this->targetOrigin);
                $value = preg_replace_callback(
                    '/(?:https?:)?\\\\\/\\\\\/' . preg_quote(str_replace('/', '\\/', $host), '/') . '(?![A-Za-z0-9.:-])/i',
                    static function (array $match) use (&$count, &$kinds, $escapedOrigin): string {
                        if (strcasecmp($match[0], $escapedOrigin) !== 0) { $count++; $kinds['url']++; }
                        return $escapedOrigin;
                    },
                    $value
                ) ?? $value;
            }

            if ($this->replaceEmailDomains && in_array($host, $this->sourceEmailHosts, true)) {
                $value = preg_replace_callback(
                    '/(?<![A-Za-z0-9._+\\-])([A-Za-z0-9_+\\-]+(?:\\.[A-Za-z0-9_+\\-]+)*)@' . $quotedHost . '(?![A-Za-z0-9.:-])/i',
                    function (array $match) use (&$count, &$kinds, &$emails): string {
                        if ($this->allowedEmails !== null && !isset($this->allowedEmails[strtolower($match[0])])) { return $match[0]; }
                        $replacement = $match[1] . '@' . $this->targetHost;
                        if (strcasecmp($match[0], $replacement) !== 0) {
                            $count++;
                            $kinds['email']++;
                            $key = strtolower($match[0]);
                            $emails[$key] ??= ['source' => $match[0], 'target' => $replacement, 'count' => 0];
                            $emails[$key]['count']++;
                        }
                        return $replacement;
                    },
                    $value
                ) ?? $value;
            }

            if ($this->replaceUrlAndHosts) {
                $value = preg_replace_callback(
                    '/(?<![A-Za-z0-9.@_-])' . $quotedHost . '(?![A-Za-z0-9.:-])/i',
                    function (array $match) use (&$count, &$kinds): string {
                        if (strcasecmp($match[0], $this->targetHost) !== 0) { $count++; $kinds['host']++; }
                        return $this->targetHost;
                    },
                    $value
                ) ?? $value;
            }
        }
        return $value;
    }

    /** @return array{url:int,host:int,email:int} */
    private function emptyKinds(): array
    {
        return ['url' => 0, 'host' => 0, 'email' => 0];
    }

    /** @param array<string,array{source:string,target:string,count:int}> $target @param array<string,array{source:string,target:string,count:int}> $source */
    private function mergeEmails(array &$target, array $source): void
    {
        foreach ($source as $key => $email) {
            $target[$key] ??= ['source' => $email['source'], 'target' => $email['target'], 'count' => 0];
            $target[$key]['count'] += $email['count'];
        }
    }
}
