<?php

declare(strict_types=1);

namespace WpDbSafeMerge\Support;

final class View
{
    public function __construct(private readonly string $templates) {}

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $contentTemplate = $this->templates . '/' . $template . '.php';
        require $this->templates . '/layout.php';
    }
}
