<?php

namespace App\Enums;

enum VerificationMethod: string
{
    case DnsTxt = 'dns_txt';
    case HtmlFile = 'html_file';
    case MetaTag = 'meta_tag';

    public function label(): string
    {
        return match ($this) {
            self::DnsTxt => 'DNS TXT Record',
            self::HtmlFile => 'HTML Verification File',
            self::MetaTag => 'HTML Meta Tag',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}

