<?php

namespace App\Filament\Tables\Columns;

use Closure;
use Filament\Tables\Columns\TextInputColumn;

class CustomTextInputColumn extends TextInputColumn
{
    protected string $view = 'filament.tables.columns.custom-text-input-column';

    protected bool | Closure $isDeferred = false;

    protected bool | Closure $isNavigable = false;

    public function deferred(bool | Closure $condition = true): static
    {
        $this->isDeferred = $condition;

        return $this;
    }

    public function navigable(bool | Closure $condition = true): static
    {
        $this->isNavigable = $condition;

        return $this;
    }

    public function isDeferred(): bool
    {
        return (bool) $this->evaluate($this->isDeferred);
    }

    public function isNavigable(): bool
    {
        return (bool) $this->evaluate($this->isNavigable);
    }
}
