<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'code',
        'name',
        'subject',
        'body_html',
        'available_placeholders',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'available_placeholders' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function renderSubject(array $variables = []): string
    {
        return $this->interpolate($this->subject, $variables);
    }

    public function renderBody(array $variables = []): string
    {
        return $this->interpolate($this->body_html, $variables);
    }

    protected function interpolate(string $template, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $placeholder = '{' . trim((string) $key, '{} ') . '}';
            $template = str_replace($placeholder, (string) $value, $template);
        }

        return $template;
    }
}

